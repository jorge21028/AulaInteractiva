<?php
/**
 * image_helpers.php
 * Reducción de imágenes subidas (estudiantes y profesor) para no llenar el espacio del hosting, y
 * direcciones públicas locales (sin dominio) para que siempre se muestren.
 *
 * Qué hace image_optimize():
 *   - Reduce el lado mayor a IMAGE_MAX_SIDE píxeles (nunca agranda).
 *   - Corrige la orientación de las fotos de celular (EXIF) y elimina los metadatos (incluida la ubicación GPS).
 *   - JPEG: recomprime a calidad IMAGE_JPEG_QUALITY.
 *   - PNG sin transparencia (capturas de pantalla, fotos): se convierte a JPEG, que pesa mucho menos.
 *   - PNG con transparencia: se conserva como PNG (reducido).
 *   - WebP: se reduce y recomprime. GIF: se deja igual (podría ser animado).
 *   - Si el resultado no queda más liviano que el original, se conserva el original.
 *   - Si el servidor no tiene la extensión GD o no hay memoria suficiente, no hace nada (no rompe la subida).
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const IMAGE_MAX_SIDE = 1280;       // píxeles del lado mayor
const IMAGE_JPEG_QUALITY = 80;     // 0-100
const IMAGE_MAX_PIXELS = 25000000; // por encima de esto no se intenta procesar (memoria del servidor)

/** ¿Tiene transparencia real una imagen PNG (GD)? Muestrea la imagen; no recorre todos los píxeles. */
function image_has_alpha($im): bool
{
    $w = imagesx($im);
    $h = imagesy($im);
    $stepX = max(1, (int) floor($w / 60));
    $stepY = max(1, (int) floor($h / 60));
    for ($y = 0; $y < $h; $y += $stepY) {
        for ($x = 0; $x < $w; $x += $stepX) {
            if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 0) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Optimiza el archivo $path (se reescribe en el mismo lugar o junto a él con otra extensión).
 * Devuelve ['path' => ruta final, 'mime' => mime final, 'ext' => extensión final, 'size' => bytes,
 *           'original_size' => bytes, 'optimized' => bool].
 * $allowFormatChange = false mantiene el mismo formato (para optimizar archivos ya referenciados).
 */
function image_optimize(string $path, string $mime, bool $allowFormatChange = true): array
{
    $extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $originalSize = (int) @filesize($path);
    $result = [
        'path' => $path, 'mime' => $mime, 'ext' => $extByMime[$mime] ?? '',
        'size' => $originalSize, 'original_size' => $originalSize, 'optimized' => false,
    ];

    if (!function_exists('imagecreatetruecolor') || $mime === 'image/gif' || !isset($extByMime[$mime])) {
        return $result;
    }

    $info = @getimagesize($path);
    if (!$info || ($info[0] * $info[1]) > IMAGE_MAX_PIXELS) {
        return $result;
    }
    [$w, $h] = $info;

    // Memoria aproximada que necesita GD (4 bytes por píxel, origen + destino): no arriesgarse a un error fatal
    $limit = ini_get('memory_limit');
    $limitBytes = $limit === '-1' ? PHP_INT_MAX : (int) $limit * (stripos($limit, 'G') !== false ? 1073741824 : (stripos($limit, 'K') !== false ? 1024 : 1048576));
    $scale = min(1, IMAGE_MAX_SIDE / max($w, $h));
    $needed = ($w * $h + (int) ($w * $scale) * (int) ($h * $scale)) * 5;
    if ($limitBytes > 0 && $needed > $limitBytes * 0.7) {
        return $result;
    }

    $src = null;
    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $src = @imagecreatefromjpeg($path);
    } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        $src = @imagecreatefrompng($path);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp($path);
    }
    if (!$src) {
        return $result;
    }

    // Orientación EXIF de las fotos de celular
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0 && function_exists('imagerotate')) {
            $rotated = imagerotate($src, $angle, 0);
            if ($rotated) {
                imagedestroy($src);
                $src = $rotated;
                [$w, $h] = [imagesx($src), imagesy($src)];
            }
        }
    }

    $newW = max(1, (int) round($w * $scale));
    $newH = max(1, (int) round($h * $scale));

    $hasAlpha = $mime !== 'image/jpeg' && image_has_alpha($src);
    $dst = imagecreatetruecolor($newW, $newH);
    if ($hasAlpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    } else {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
    imagedestroy($src);

    // Formato de salida
    $outMime = $mime;
    if ($mime === 'image/png' && !$hasAlpha && $allowFormatChange) {
        $outMime = 'image/jpeg';
    }

    $tmp = $path . '.opt';
    $ok = false;
    if ($outMime === 'image/jpeg') {
        $ok = imagejpeg($dst, $tmp, IMAGE_JPEG_QUALITY);
    } elseif ($outMime === 'image/png') {
        $ok = imagepng($dst, $tmp, 9);
    } elseif ($outMime === 'image/webp' && function_exists('imagewebp')) {
        $ok = imagewebp($dst, $tmp, IMAGE_JPEG_QUALITY);
    }

    // Dibujos/capturas de colores planos: a veces el PNG reducido pesa menos que el JPEG. Se prueba y se elige el menor.
    if ($ok && $outMime === 'image/jpeg' && $mime === 'image/png') {
        $tmpPng = $path . '.optpng';
        if (imagepng($dst, $tmpPng, 9) && is_file($tmpPng) && filesize($tmpPng) < filesize($tmp)) {
            @unlink($tmp);
            rename($tmpPng, $tmp);
            $outMime = 'image/png';
        } else {
            @unlink($tmpPng);
        }
    }
    imagedestroy($dst);

    if (!$ok || !is_file($tmp)) {
        @unlink($tmp);
        return $result;
    }

    $newSize = (int) filesize($tmp);
    // Solo se reemplaza si realmente quedó más liviana (o si se redujeron las dimensiones de forma importante)
    if ($newSize >= $originalSize) {
        @unlink($tmp);
        return $result;
    }

    $finalExt = $extByMime[$outMime];
    $finalPath = $outMime === $mime ? $path : preg_replace('/\.[A-Za-z0-9]+$/', '', $path) . '.' . $finalExt;
    if (!@rename($tmp, $finalPath)) {
        @unlink($tmp);
        return $result;
    }
    if ($finalPath !== $path) {
        @unlink($path);
    }

    return [
        'path' => $finalPath, 'mime' => $outMime, 'ext' => $finalExt,
        'size' => $newSize, 'original_size' => $originalSize, 'optimized' => true,
    ];
}

/**
 * Dirección pública LOCAL (sin dominio) de un archivo subido, p. ej. "/uploads/images/abc.jpg".
 * Funciona aunque el sitio se abra con una dirección distinta a APP_URL (http/https, con o sin www).
 */
function uploads_public_url(string $relativePath): string
{
    $base = rtrim((string) (parse_url(APP_URL, PHP_URL_PATH) ?? ''), '/');
    return $base . '/' . ltrim($relativePath, '/');
}
