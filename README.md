# AulaInteractiva

Plataforma educativa web independiente: actividades interactivas (tipo Kahoot/Educaplay) + creación de trabajos académicos (mapas mentales, infografías, presentaciones, resúmenes, tablas comparativas, etc.), con asignación, entrega y calificación.

Tecnologías: PHP 8.x + MySQL/MariaDB + JavaScript + HTML5/CSS3. Sin frameworks pesados. Compatible con hosting gratuito PHP/MySQL (InfinityFree).

## Estado actual: FASE 3 completada

**Fase 1:** estructura, autenticación, roles, cursos y asignaturas.
**Fase 2:** actividades interactivas manuales y partidas en vivo con polling.

**Fase 3 — Generación de actividades con Google Gemini:**
- Botón "Generar con Gemini" en el panel del profesor, con formulario (asignatura, tema, descripción, objetivo, cantidad de preguntas, dificultad, tipo, tiempo por pregunta, instrucciones adicionales).
- `services/GeminiService.php`: único punto de contacto con la API de Gemini, aislado para poder cambiar de proveedor de IA en el futuro sin tocar el resto del sistema.
- La API Key de Gemini vive solo en `config/env.php` (servidor), nunca llega al navegador.
- Arquitectura: Navegador → PHP (`api/gemini/generate.php`) → Gemini API → PHP → Navegador.
- Salida estructurada en JSON mediante `responseSchema` de Gemini (no texto libre que haya que interpretar).
- Validación estricta del JSON antes de mostrarlo o guardarlo: título obligatorio, al menos una pregunta válida, exactamente una opción correcta por pregunta (autocorregido si el modelo se equivoca), tiempo y puntos acotados a rangos razonables.
- El profesor **siempre** revisa la vista previa en pantalla antes de guardar nada.
- Al guardar, la actividad se crea como **borrador** (`status = 'draft'`, `source = 'gemini'`) — Gemini nunca publica ni controla partidas.
- Si Gemini falla (sin API key, error de red, JSON inválido, etc.), el usuario ve siempre el mismo mensaje genérico: "No fue posible generar la actividad. Intenta nuevamente." — nunca se exponen errores internos ni la API Key.
- Todo el flujo (validación de estructura JSON con casos límite, y guardado de una actividad ya generada) fue probado antes de la entrega. La llamada real a la API de Gemini debe probarse con tu propia API Key, ya que no fue posible desde el entorno de desarrollo.

## Requisitos

- PHP 8.0 o superior con extensión PDO MySQL.
- MySQL o MariaDB.
- Servidor local recomendado: XAMPP, MAMP o Laragon (para pruebas antes de subir a InfinityFree).

## Instalación en local

1. Copia la carpeta `public_html` dentro de tu servidor local (por ejemplo `htdocs/aulainteractiva`).
2. Crea una base de datos vacía en MySQL, por ejemplo `aulainteractiva`.
3. Importa `database/schema_fase1.sql` y luego `database/schema_fase2.sql` (en ese orden) con phpMyAdmin, o:
   `mysql -u root -p aulainteractiva < database/schema_fase1.sql`
   `mysql -u root -p aulainteractiva < database/schema_fase2.sql`
4. Copia `public_html/config/env.example.php` como `public_html/config/env.php` y completa tus credenciales locales.
5. Para probar la Fase 3, obtén una API Key gratuita en [Google AI Studio](https://aistudio.google.com/apikey) y colócala en `GEMINI_API_KEY` dentro de `env.php`. Sin esto, todo lo demás funciona igual; solo el botón "Generar con Gemini" mostrará el mensaje de error genérico.
6. Abre `http://localhost/aulainteractiva/index.php` en el navegador.
7. Regístrate como profesor y como estudiante (dos cuentas distintas) para probar ambos flujos.

## Despliegue en InfinityFree

1. Crea tu cuenta y hosting en InfinityFree, y una base de datos MySQL desde el panel (vhost/cPanel).
2. Sube el contenido de `public_html/` (no la carpeta en sí, sino su contenido) a la carpeta `htdocs` de tu hosting, vía Administrador de Archivos o FTP.
3. En `config/env.php` (créalo en el servidor a partir de `env.example.php`) coloca las credenciales MySQL que InfinityFree te asigna (host, nombre de base de datos, usuario, contraseña), y tu `GEMINI_API_KEY` si quieres usar la generación con IA.
4. Importa `database/schema_fase1.sql` y luego `database/schema_fase2.sql` desde phpMyAdmin de InfinityFree.
5. Ajusta `APP_URL` y `APP_ENV=production` en `env.php`.
6. Visita tu dominio y prueba registro/login.

## Estructura de carpetas

```
public_html/
  assets/          CSS, JS, imágenes, iconos
  config/          config.php, database.php, env.php (no versionado)
  includes/        auth.php, security.php, functions.php, header.php, footer.php, game_helpers.php
  services/        GeminiService.php (único punto de contacto con la IA)
  api/
    games/         state.php, host_action.php, join.php, answer.php (partidas en vivo)
    gemini/        generate.php (generación de actividades con IA)
  teacher/         panel y páginas del profesor (cursos, actividades, partidas, generación con IA)
  student/         panel y páginas del estudiante
  game/            unirse y jugar una partida (estudiante)
  projector/       pantalla de proyección para el salón
  editor/          reservado para fases futuras (creación académica)
  uploads/         archivos subidos por usuarios (protegido contra ejecución de scripts)
  index.php login.php register.php logout.php
database/
  schema_fase1.sql
  schema_fase2.sql
```

## Seguridad implementada en Fase 1

- Contraseñas con `password_hash()` / `password_verify()`.
- Consultas exclusivamente con PDO y prepared statements.
- Tokens CSRF en todos los formularios POST.
- Sesiones con `httponly`, `samesite=Lax` y `secure` automático bajo HTTPS.
- Escape de salida (`htmlspecialchars`) en toda variable impresa en HTML.
- `.htaccess` que bloquea el acceso directo a `/config`, `/includes` y ejecución de scripts en `/uploads`.
- Ninguna credencial ni API key se guarda en el repositorio (`env.php` está en `.gitignore`).

## Próximo paso recomendado

FASE 4: asignaciones, entregas y calificaciones (el profesor asigna actividades — interactivas o de creación académica — a sus estudiantes con fecha de entrega; el estudiante ve "actividades pendientes" y entrega; el profesor califica).
