# AulaInteractiva

Plataforma educativa web independiente: actividades interactivas (tipo Kahoot/Educaplay) + creación de trabajos académicos (mapas mentales, infografías, presentaciones, resúmenes, tablas comparativas, etc.), con asignación, entrega y calificación.

Tecnologías: PHP 8.x + MySQL/MariaDB + JavaScript + HTML5/CSS3. Sin frameworks pesados. Compatible con hosting gratuito PHP/MySQL (InfinityFree).

## Estado actual: FASE 7 completada

**Fase 1:** estructura, autenticación, roles, cursos y asignaturas.
**Fase 2:** actividades interactivas manuales y partidas en vivo con polling.
**Fase 3:** generación de actividades con Google Gemini.
**Fase 4:** asignaciones, entregas y calificaciones (actividades interactivas).

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

**Fase 5 — Creación académica del estudiante (resúmenes y tablas comparativas):**
- Dos nuevas herramientas 100% asíncronas: el estudiante trabaja a su ritmo, sin depender de una sesión en vivo.
- **Resúmenes**: editor de texto enriquecido (Quill.js vía CDN — negritas, cursivas, títulos, listas, citas, enlaces).
- **Tablas comparativas**: editor propio con filas/columnas dinámicas (agregar, quitar, editar celdas).
- El profesor, al crear una asignación, ahora elige entre **"Actividad interactiva"** (Fase 2/4, en vivo) o **"Trabajo de creación"** (Fase 5, asíncrono) — mismo flujo de asignación automática a estudiantes inscritos.
- El trabajo se guarda como **estructura JSON editable** (no solo el resultado final visual), así el estudiante puede cerrar sesión y continuar después exactamente donde lo dejó.
- Autoguardado cada 20 segundos + botón manual "Guardar borrador".
- Botón "Entregar actividad" con advertencia "una vez enviada, no podrás modificarla" — y el servidor la hace cumplir de verdad (una vez entregado, el trabajo queda bloqueado para edición).
- **Sin IA**: estas herramientas nunca usan Gemini ni ningún otro modelo para generar el contenido del estudiante — el requisito 35 de la especificación se respeta estrictamente.
- El profesor revisa el trabajo entregado en una vista de solo lectura y lo califica manualmente con retroalimentación (no hay autocalificación posible para contenido abierto).
- **Seguridad importante**: el HTML del editor de resúmenes se sanea en el servidor antes de guardarlo (lista blanca estricta de etiquetas/atributos, elimina `<script>`, manejadores `onXXX` y enlaces `javascript:`), para evitar que un estudiante inyecte código malicioso que se ejecute en la pantalla de otro estudiante o del profesor. Esto se probó activamente con un intento de XSS real antes de la entrega.
- Todo el flujo (crear asignación de creación → autosave con intento de XSS neutralizado → entrega → bloqueo de edición → vista del profesor limpia → calificación manual → tabla comparativa completa) fue probado de extremo a extremo con base de datos real antes de la entrega.

## Requisitos

- PHP 8.0 o superior con extensión PDO MySQL.
- MySQL o MariaDB.
- Servidor local recomendado: XAMPP, MAMP o Laragon (para pruebas antes de subir a InfinityFree).

## Instalación en local

1. Copia la carpeta `public_html` dentro de tu servidor local (por ejemplo `htdocs/aulainteractiva`).
2. Crea una base de datos vacía en MySQL, por ejemplo `aulainteractiva`.
3. Importa en orden: `schema_fase1.sql`, `schema_fase2.sql`, `schema_fase4.sql`, `schema_fase5.sql` y `schema_fase6.sql` con phpMyAdmin, o:
   ```
   mysql -u root -p aulainteractiva < database/schema_fase1.sql
   mysql -u root -p aulainteractiva < database/schema_fase2.sql
   mysql -u root -p aulainteractiva < database/schema_fase4.sql
   mysql -u root -p aulainteractiva < database/schema_fase5.sql
   mysql -u root -p aulainteractiva < database/schema_fase6.sql
   ```
4. Copia `public_html/config/env.example.php` como `public_html/config/env.php` y completa tus credenciales locales.
5. Para probar la Fase 3, obtén una API Key gratuita en [Google AI Studio](https://aistudio.google.com/apikey) y colócala en `GEMINI_API_KEY` dentro de `env.php`. Sin esto, todo lo demás funciona igual; solo el botón "Generar con Gemini" mostrará el mensaje de error genérico.
6. Abre `http://localhost/aulainteractiva/index.php` en el navegador.
7. Regístrate como profesor y como estudiante (dos cuentas distintas) para probar ambos flujos.

## Despliegue en InfinityFree

1. Crea tu cuenta y hosting en InfinityFree, y una base de datos MySQL desde el panel (vhost/cPanel).
2. Sube el contenido de `public_html/` (no la carpeta en sí, sino su contenido) a la carpeta `htdocs` de tu hosting, vía Administrador de Archivos o FTP.
3. En `config/env.php` (créalo en el servidor a partir de `env.example.php`) coloca las credenciales MySQL que InfinityFree te asigna (host, nombre de base de datos, usuario, contraseña), y tu `GEMINI_API_KEY` si quieres usar la generación con IA.
4. Importa `schema_fase1.sql`, `schema_fase2.sql`, `schema_fase4.sql`, `schema_fase5.sql` y `schema_fase6.sql` (en ese orden) desde phpMyAdmin de InfinityFree.
5. Ajusta `APP_URL` y `APP_ENV=production` en `env.php`.
6. Visita tu dominio y prueba registro/login.

## Estructura de carpetas

```
public_html/
  assets/          CSS, JS, imágenes, iconos
  config/          config.php, database.php, env.php (no versionado)
  includes/        auth.php, security.php, functions.php, header.php, footer.php, game_helpers.php, assignment_helpers.php, project_helpers.php
  services/        GeminiService.php (único punto de contacto con la IA)
  api/
    games/         state.php, host_action.php, join.php, answer.php (partidas en vivo)
    gemini/        generate.php (generación de actividades con IA)
  teacher/         panel y páginas del profesor (cursos, actividades, partidas, generación con IA, asignaciones, revisión de trabajos)
  student/         panel y páginas del estudiante (asignaturas, actividades pendientes, calificaciones)
  game/            unirse y jugar una partida (estudiante)
  projector/       pantalla de proyección para el salón
  editor/          resumen.php, tabla.php y canvas.php (infografías/mapas mentales) — creación académica del estudiante
  uploads/         archivos subidos por usuarios (protegido contra ejecución de scripts)
  index.php login.php register.php logout.php
database/
  schema_fase1.sql
  schema_fase2.sql
  schema_fase4.sql
  schema_fase5.sql
  schema_fase6.sql
```

## Seguridad implementada en Fase 1

- Contraseñas con `password_hash()` / `password_verify()`.
- Consultas exclusivamente con PDO y prepared statements.
- Tokens CSRF en todos los formularios POST.
- Sesiones con `httponly`, `samesite=Lax` y `secure` automático bajo HTTPS.
- Escape de salida (`htmlspecialchars`) en toda variable impresa en HTML.
- `.htaccess` que bloquea el acceso directo a `/config`, `/includes` y ejecución de scripts en `/uploads`.
- Ninguna credencial ni API key se guarda en el repositorio (`env.php` está en `.gitignore`).

## Fase 6 — Editor gráfico (infografías y mapas mentales)

- Editor tipo Canvas basado en **Fabric.js** (vía CDN), reutilizando el mismo modelo `student_projects` de la Fase 5 (dos nuevos valores de `type`: `infografia` y `mapa_mental`), sin necesidad de tablas nuevas para eso.
- Herramientas: texto, rectángulo, círculo, línea, subir imagen, color de relleno, color de fondo, negrita/cursiva/subrayado, duplicar, eliminar, traer al frente/enviar atrás, deshacer/rehacer.
- **Mapas mentales**: botón "+ Nodo" (crea el nodo central automáticamente si el lienzo está vacío, luego nodos secundarios), y "Conectar nodos seleccionados" (selecciona 2 con Shift+clic) dibuja una línea entre ellos que se mantiene anclada cuando mueves los nodos.
- El trabajo se guarda como la propia estructura `canvas.toJSON()` de Fabric.js (ancho, alto, color de fondo, y la lista de objetos), tal como se planteó desde la arquitectura inicial — así el estudiante puede cerrar sesión y retomar exactamente donde lo dejó.
- **Subida de imágenes** (`api/projects/upload_image.php`): valida extensión contra lista blanca, tamaño máximo, y que el contenido sea realmente una imagen (no solo la extensión) usando `getimagesize()`; genera un nombre aleatorio en disco (nunca el nombre original) y registra cada subida en la nueva tabla `uploads`.
- **Saneo adicional en el guardado**: el ancho/alto del lienzo se acota a un rango razonable, la cantidad de objetos tiene un tope, y cualquier imagen referenciada que **no** provenga de nuestro propio endpoint de subida se elimina automáticamente (evita que un estudiante inserte referencias a recursos externos arbitrarios).
- El profesor revisa el trabajo entregado en la misma vista Fabric.js, pero en modo solo lectura (sin edición).
- Todo esto (autocreación del proyecto, subida válida, subida con extensión falsa, subida con contenido falso disfrazado de imagen, saneo de imagen externa, acotado de ancho/alto, bloqueo de edición y de subida tras la entrega, vista de solo lectura del profesor) fue probado de extremo a extremo con base de datos real antes de la entrega.
- **Limitación conocida**: no pude probar visualmente el editor en un navegador real desde este entorno de desarrollo (Fabric.js corre en el cliente). Probé exhaustivamente todo el backend (guardado, saneo, seguridad, subida de archivos), pero te recomiendo que tú mismo lo abras y dibujes un poco para confirmar que la experiencia visual es la esperada, por si aparece algún detalle de UI que solo se nota interactuando de verdad.

## Fase 7 — Multimedia, exportación y estadísticas (cierre de las fases planeadas)

- **Imágenes en preguntas**: se llenó un vacío que quedó desde la Fase 2 — la columna `activity_questions.image_path` existía en la base de datos pero nunca había interfaz para usarla. Ahora el profesor puede subir (o quitar) una imagen por pregunta desde `activity_question.php`, con las mismas validaciones estrictas que el resto del sistema (extensión, tamaño, contenido real de imagen vía `getimagesize()`). La imagen se propaga automáticamente a la pantalla del profesor, la del proyector y la del estudiante durante la partida en vivo.
- **Audio y video en resúmenes**: se extendió el saneador de HTML (`sanitize_rich_html()`) para permitir `<audio>`/`<video>` de forma segura — el atributo `src` solo se conserva si apunta a un archivo que el propio estudiante subió a través de nuestro endpoint (`api/projects/upload_media.php`); cualquier otro origen se elimina automáticamente. Botones "+ Audio" / "+ Video" en el editor de resúmenes.
- **Exportación**:
  - Infografías y mapas mentales: botón "Descargar como PNG" (usa `canvas.toDataURL()` de Fabric.js, sin necesidad de servidor), disponible tanto para el estudiante como para el profesor al revisar.
  - Resúmenes y tablas comparativas: botón "Imprimir / Guardar como PDF", que usa el diálogo de impresión nativo del navegador con una hoja de estilos de impresión dedicada (oculta menú, pie de página y botones de acción).
- **Estadísticas** (`teacher/statistics.php`): totales generales (cursos, asignaturas, estudiantes, actividades, partidas realizadas, asignaciones), tasa de entregas completadas, y promedio de calificación por asignatura con barras de progreso simples en CSS (sin librerías de gráficos externas).
- Todo lo anterior (subida y propagación de imagen de pregunta con control de acceso por rol, audio embebido con saneo de origen externo, y carga correcta de la página de estadísticas con conteos exactos) fue probado de extremo a extremo con base de datos real antes de la entrega.
- **Limitación conocida**: igual que con el editor gráfico de la Fase 6, no pude probar visualmente la exportación a PNG/PDF ni la reproducción de audio/video en un navegador real desde este entorno — la lógica de datos y seguridad está verificada, pero te recomiendo probar tú mismo la experiencia final.

## Alcance completo

Con esto se cierran las 7 fases planeadas originalmente. La plataforma cubre: autenticación y roles, estructura académica (cursos/asignaturas), actividades interactivas en vivo con partidas por código, generación de actividades con IA (siempre revisada por el profesor), asignaciones con calificación automática y manual, y tres herramientas de creación académica para el estudiante (resúmenes, tablas comparativas, infografías y mapas mentales), con multimedia, exportación y estadísticas.

Posibles ampliaciones futuras (no forman parte del alcance original en detalle, o quedaron como mejoras menores): presentaciones, fichas de estudio, más tipos de pregunta (relacionar, ordenar, completar espacios), modo por equipos, integración con Google Classroom/Moodle, y una versión verdaderamente asíncrona de las actividades interactivas (sin depender de una sesión en vivo).
