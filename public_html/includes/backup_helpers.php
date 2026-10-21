<?php
/**
 * backup_helpers.php
 * Copia de seguridad completa de la base de datos en un archivo .sql (o .sql.gz), generada con PHP puro (no usa
 * mysqldump ni exec, que los hostings gratuitos suelen bloquear). El archivo se puede restaurar desde phpMyAdmin
 * (pestaña Importar). Incluye la estructura y todos los datos de todas las tablas. NO incluye los archivos de la
 * carpeta uploads/ (imágenes, audio, video).
 * Exclusivo del súper administrador.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

const BACKUP_BATCH_ROWS = 200;
const BACKUP_BATCH_BYTES = 400000;

/** Tablas (no vistas) de la base de datos actual. */
function backup_tables(PDO $pdo): array
{
    $tables = [];
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = $row[0];
    }
    return $tables;
}

function backup_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

/** Tablas con su número de filas aproximado y el tamaño total de la base de datos (para mostrar en pantalla). */
function backup_overview(PDO $pdo): array
{
    $info = [];
    foreach (backup_tables($pdo) as $t) {
        $info[] = ['name' => $t, 'rows' => (int) $pdo->query('SELECT COUNT(*) FROM ' . backup_ident($t))->fetchColumn()];
    }
    $bytes = 0;
    try {
        $bytes = (int) $pdo->query('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    } catch (Throwable $e) {
        // sin permiso para information_schema: solo no se muestra el tamaño
    }
    return ['tables' => $info, 'bytes' => $bytes];
}

/**
 * Escribe la copia completa llamando a $out(string) por cada fragmento. Devuelve [tablas, filas].
 * Las claves foráneas se desactivan durante la restauración, así el orden de las tablas no importa.
 */
function backup_write(PDO $pdo, callable $out): array
{
    $tables = backup_tables($pdo);
    $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $totalRows = 0;

    $out("-- Dynamic SGA — copia de seguridad\n-- Base de datos: {$dbName}\n-- Generada: " . now_datetime() . "\n-- Para restaurar: phpMyAdmin > tu base de datos > Importar.\n\n");
    $out("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n");

    foreach ($tables as $table) {
        $q = backup_ident($table);
        $create = $pdo->query("SHOW CREATE TABLE {$q}")->fetch(PDO::FETCH_NUM);
        $out("-- ----------------------------\n-- Tabla {$table}\n-- ----------------------------\nDROP TABLE IF EXISTS {$q};\n" . $create[1] . ";\n\n");

        // Tipos de columna: números sin comillas, binarios en hexadecimal.
        $cols = $pdo->query("SHOW COLUMNS FROM {$q}")->fetchAll();
        $names = [];
        $numeric = [];
        $binary = [];
        $select = [];
        foreach ($cols as $c) {
            $n = $c['Field'];
            $names[] = $n;
            $type = strtolower($c['Type']);
            $numeric[$n] = (bool) preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|year)\b/', $type);
            $binary[$n] = (bool) preg_match('/blob|binary|varbinary|bit\b/', $type);
            $select[] = $binary[$n] ? 'HEX(' . backup_ident($n) . ') AS ' . backup_ident($n) : backup_ident($n);
        }
        $colList = implode(', ', array_map('backup_ident', $names));

        // Lectura fila a fila sin cargar toda la tabla en memoria
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $stmt = $pdo->query('SELECT ' . implode(', ', $select) . " FROM {$q}");
            $batch = [];
            $batchBytes = 0;
            $flush = function () use (&$batch, &$batchBytes, $out, $q, $colList) {
                if ($batch) {
                    $out("INSERT INTO {$q} ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                    $batchBytes = 0;
                }
            };
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $vals = [];
                foreach ($names as $n) {
                    $v = $row[$n];
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif ($binary[$n]) {
                        $vals[] = $v === '' ? "''" : '0x' . $v;
                    } elseif ($numeric[$n] && is_numeric($v)) {
                        $vals[] = (string) $v;
                    } else {
                        $vals[] = $pdo->quote((string) $v);
                    }
                }
                $line = '(' . implode(', ', $vals) . ')';
                $batch[] = $line;
                $batchBytes += strlen($line);
                $totalRows++;
                if (count($batch) >= BACKUP_BATCH_ROWS || $batchBytes >= BACKUP_BATCH_BYTES) {
                    $flush();
                }
            }
            $stmt->closeCursor();
            $flush();
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        $out("\n");
    }

    $out("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- Fin de la copia: " . count($tables) . " tablas, {$totalRows} filas.\n");
    return [count($tables), $totalRows];
}

/** ¿El servidor puede comprimir en streaming? (extensión zlib de PHP 7+) */
function backup_can_gzip(): bool
{
    return function_exists('deflate_init') && function_exists('deflate_add');
}

/** Descarga la copia como archivo (gzip si el servidor lo permite y se pide). No retorna: termina el script. */
function backup_download(PDO $pdo, bool $gzip): void
{
    @set_time_limit(0);
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $stamp = date('Ymd_His');
    $gzip = $gzip && backup_can_gzip();
    header('Content-Type: ' . ($gzip ? 'application/gzip' : 'application/sql; charset=utf-8'));
    header('Content-Disposition: attachment; filename="dynamic_sga_backup_' . $stamp . ($gzip ? '.sql.gz' : '.sql') . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    if ($gzip) {
        // Compresión en streaming (no se guarda el archivo completo en memoria ni en disco)
        $ctx = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        backup_write($pdo, function (string $s) use ($ctx) {
            $chunk = deflate_add($ctx, $s, ZLIB_NO_FLUSH);
            if ($chunk !== '' && $chunk !== false) {
                echo $chunk;
            }
        });
        echo deflate_add($ctx, '', ZLIB_FINISH);
    } else {
        backup_write($pdo, function (string $s) {
            echo $s;
        });
    }
    exit;
}

/** Fecha de la última copia descargada (según el registro de actividad) o null. */
function backup_last_time(PDO $pdo): ?string
{
    $v = $pdo->query("SELECT MAX(created_at) FROM audit_log WHERE action = 'admin_backup'")->fetchColumn();
    return $v ?: null;
}
