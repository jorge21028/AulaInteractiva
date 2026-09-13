# AulaInteractiva

Plataforma educativa web independiente: actividades interactivas (tipo Kahoot/Educaplay) + creación de trabajos académicos (mapas mentales, infografías, presentaciones, resúmenes, tablas comparativas, etc.), con asignación, entrega y calificación.

Tecnologías: PHP 8.x + MySQL/MariaDB + JavaScript + HTML5/CSS3. Sin frameworks pesados. Compatible con hosting gratuito PHP/MySQL (InfinityFree).

## Estado actual: FASE 4 completada

**Fase 1:** estructura, autenticación, roles, cursos y asignaturas.
**Fase 2:** actividades interactivas manuales y partidas en vivo con polling.
**Fase 3:** generación de actividades con Google Gemini.

**Fase 4 — Asignaciones, entregas y calificaciones:**
- El profesor asigna una actividad **publicada** a una asignatura, con título, descripción, fecha de inicio/entrega y puntuación.
- Al crear la asignación, se reparte automáticamente entre todos los estudiantes inscritos en esa asignatura (tabla `assignment_students`), con una entrega en estado `pending` para cada uno.
- **Importante sobre cómo se conecta con las partidas en vivo (Fase 2):** las actividades interactivas siguen siendo partidas en vivo con código, no hay todavía un modo "jugar cuando quieras". Por eso, una asignación de este tipo funciona así: el profesor la crea con fecha de entrega y puntaje, y cuando esté listo, inicia la partida en vivo de esa misma actividad (desde la propia página de la asignación hay un botón directo). En cuanto un estudiante juega esa partida, su entrega se autocalifica.
- Autocalificación: al finalizar la partida, cada estudiante que participó recibe una calificación = (aciertos ÷ total de preguntas) × puntos de la asignación. Por ejemplo, 3 de 4 correctas sobre una asignación de 100 pts = 75.00.
- El estudiante ve un botón "¡La partida está activa! Unirme ahora" en el detalle de su asignación pendiente, que aparece automáticamente en cuanto el profesor inicia la partida (sin que el estudiante necesite el código).
- El profesor puede revisar el listado de entregas de cada asignación y **ajustar manualmente** la calificación o dejar retroalimentación; una vez ajustada a mano, la sincronización automática nunca la vuelve a sobrescribir.
- Respeta la configuración "permitir repetir" de la actividad: si está activada, una repetición solo mejora la nota (nunca la baja); si no, se conserva el primer intento.
- El panel del estudiante ahora muestra "Actividades pendientes" (con fecha de entrega) y un "Historial de calificaciones".
- Todo el flujo (crear asignación → reparto automático → partida en vivo → autocalificación exacta → ajuste manual del profesor → reflejo en ambos paneles) fue probado de extremo a extremo con una base de datos real antes de la entrega.

## Requisitos

- PHP 8.0 o superior con extensión PDO MySQL.
- MySQL o MariaDB.
- Servidor local recomendado: XAMPP, MAMP o Laragon (para pruebas antes de subir a InfinityFree).

## Instalación en local

1. Copia la carpeta `public_html` dentro de tu servidor local (por ejemplo `htdocs/aulainteractiva`).
2. Crea una base de datos vacía en MySQL, por ejemplo `aulainteractiva`.
3. Importa en orden: `database/schema_fase1.sql`, `schema_fase2.sql` y `schema_fase4.sql` (en ese orden) con phpMyAdmin, o:
   `mysql -u root -p aulainteractiva < database/schema_fase1.sql`
   `mysql -u root -p aulainteractiva < database/schema_fase2.sql`
   `mysql -u root -p aulainteractiva < database/schema_fase4.sql`
4. Copia `public_html/config/env.example.php` como `public_html/config/env.php` y completa tus credenciales locales.
5. Para probar la Fase 3, obtén una API Key gratuita en [Google AI Studio](https://aistudio.google.com/apikey) y colócala en `GEMINI_API_KEY` dentro de `env.php`. Sin esto, todo lo demás funciona igual; solo el botón "Generar con Gemini" mostrará el mensaje de error genérico.
6. Abre `http://localhost/aulainteractiva/index.php` en el navegador.
7. Regístrate como profesor y como estudiante (dos cuentas distintas) para probar ambos flujos.

## Despliegue en InfinityFree

1. Crea tu cuenta y hosting en InfinityFree, y una base de datos MySQL desde el panel (vhost/cPanel).
2. Sube el contenido de `public_html/` (no la carpeta en sí, sino su contenido) a la carpeta `htdocs` de tu hosting, vía Administrador de Archivos o FTP.
3. En `config/env.php` (créalo en el servidor a partir de `env.example.php`) coloca las credenciales MySQL que InfinityFree te asigna (host, nombre de base de datos, usuario, contraseña), y tu `GEMINI_API_KEY` si quieres usar la generación con IA.
4. Importa `database/schema_fase1.sql`, `schema_fase2.sql` y `schema_fase4.sql` (en ese orden) desde phpMyAdmin de InfinityFree.
5. Ajusta `APP_URL` y `APP_ENV=production` en `env.php`.
6. Visita tu dominio y prueba registro/login.

## Estructura de carpetas

```
public_html/
  assets/          CSS, JS, imágenes, iconos
  config/          config.php, database.php, env.php (no versionado)
  includes/        auth.php, security.php, functions.php, header.php, footer.php, game_helpers.php, assignment_helpers.php
  services/        GeminiService.php (único punto de contacto con la IA)
  api/
    games/         state.php, host_action.php, join.php, answer.php (partidas en vivo)
    gemini/        generate.php (generación de actividades con IA)
  teacher/         panel y páginas del profesor (cursos, actividades, partidas, generación con IA, asignaciones)
  student/         panel y páginas del estudiante (asignaturas, actividades pendientes, calificaciones)
  game/            unirse y jugar una partida (estudiante)
  projector/       pantalla de proyección para el salón
  editor/          reservado para fases futuras (creación académica)
  uploads/         archivos subidos por usuarios (protegido contra ejecución de scripts)
  index.php login.php register.php logout.php
database/
  schema_fase1.sql
  schema_fase2.sql
  schema_fase4.sql
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

FASE 5: herramientas de creación académica para el estudiante — empezando por resúmenes (editor de texto enriquecido) y tablas comparativas, guardando el trabajo como estructura JSON editable (no solo el resultado final) para que puedan retomarlo después. Estas herramientas se conectarán al mismo sistema de asignaciones y entregas de esta fase, pero de forma asíncrona (sin depender de una sesión en vivo).
