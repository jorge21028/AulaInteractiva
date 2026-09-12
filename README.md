# AulaInteractiva

Plataforma educativa web independiente: actividades interactivas (tipo Kahoot/Educaplay) + creación de trabajos académicos (mapas mentales, infografías, presentaciones, resúmenes, tablas comparativas, etc.), con asignación, entrega y calificación.

Tecnologías: PHP 8.x + MySQL/MariaDB + JavaScript + HTML5/CSS3. Sin frameworks pesados. Compatible con hosting gratuito PHP/MySQL (InfinityFree).

## Estado actual: FASE 2 completada

**Fase 1:**
- Estructura del proyecto.
- Base de datos (usuarios, roles, cursos, asignaturas, inscripciones, auditoría).
- Autenticación: registro, login, logout, sesiones seguras, CSRF.
- Roles: profesor, estudiante (admin reservado para fases futuras).
- Panel del profesor: crear cursos, crear asignaturas, inscribir estudiantes por correo.
- Panel del estudiante: ver sus cursos y asignaturas.

**Fase 2 — Actividades interactivas y partidas en vivo:**
- Creación manual de actividades (selección múltiple y verdadero/falso), con preguntas, opciones, tiempo y puntos configurables.
- Publicación de actividades (borrador → publicada).
- Partidas en vivo con código de acceso de 6 dígitos.
- Sincronización en tiempo real mediante polling (fetch cada 1.5–2 segundos), sin WebSockets — compatible con InfinityFree.
- Pantalla del profesor (control de la partida: iniciar, siguiente pregunta, ver resultados, finalizar).
- Pantalla de proyección pública (código, pregunta, temporizador, resultados, ranking).
- Pantalla del estudiante (unirse con código, responder, ver retroalimentación).
- Sistema de puntuación: puntos base + bonificación por rapidez, configurable por actividad.
- Avance automático de pregunta cuando se agota el tiempo, incluso si el profesor no interactúa.
- Todo el flujo fue probado de extremo a extremo (registro → curso → actividad → partida → respuestas → ranking final) en un entorno local con MySQL real antes de la entrega.

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
5. Abre `http://localhost/aulainteractiva/index.php` en el navegador.
6. Regístrate como profesor y como estudiante (dos cuentas distintas) para probar ambos flujos.

## Despliegue en InfinityFree

1. Crea tu cuenta y hosting en InfinityFree, y una base de datos MySQL desde el panel (vhost/cPanel).
2. Sube el contenido de `public_html/` (no la carpeta en sí, sino su contenido) a la carpeta `htdocs` de tu hosting, vía Administrador de Archivos o FTP.
3. En `config/env.php` (créalo en el servidor a partir de `env.example.php`) coloca las credenciales MySQL que InfinityFree te asigna (host, nombre de base de datos, usuario, contraseña).
4. Importa `database/schema_fase1.sql` y luego `database/schema_fase2.sql` desde phpMyAdmin de InfinityFree.
5. Ajusta `APP_URL` y `APP_ENV=production` en `env.php`.
6. Visita tu dominio y prueba registro/login.

## Estructura de carpetas

```
public_html/
  assets/          CSS, JS, imágenes, iconos
  config/          config.php, database.php, env.php (no versionado)
  includes/        auth.php, security.php, functions.php, header.php, footer.php
  api/             endpoints REST internos (se irán llenando por fase)
  teacher/         panel y páginas del profesor
  student/         panel y páginas del estudiante
  game/ editor/ projector/   reservados para fases futuras
  uploads/         archivos subidos por usuarios (protegido contra ejecución de scripts)
  index.php login.php register.php logout.php
database/
  schema_fase1.sql
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

FASE 3: integración con Google Gemini para generar preguntas de actividades interactivas (el profesor sigue revisando y publicando manualmente; Gemini nunca publica ni controla partidas).
