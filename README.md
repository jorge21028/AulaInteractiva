# Dynamic SGA

Plataforma educativa web independiente: actividades interactivas (tipo Kahoot/Educaplay) + creación de trabajos académicos (mapas mentales, infografías, presentaciones, resúmenes, tablas comparativas, etc.), con asignación, entrega y calificación.

Tecnologías: PHP 8.x + MySQL/MariaDB + JavaScript + HTML5/CSS3. Sin frameworks pesados. Compatible con hosting gratuito PHP/MySQL (InfinityFree).

## Estado actual: FASE 10 completada (más rebranding a Dynamic SGA)

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
3. Importa en orden: `schema_fase1.sql`, `schema_fase2.sql`, `schema_fase4.sql`, `schema_fase5.sql`, `schema_fase6.sql`, `schema_fase9.sql` y `schema_fase10.sql` con phpMyAdmin, o:
   ```
   mysql -u root -p aulainteractiva < database/schema_fase1.sql
   mysql -u root -p aulainteractiva < database/schema_fase2.sql
   mysql -u root -p aulainteractiva < database/schema_fase4.sql
   mysql -u root -p aulainteractiva < database/schema_fase5.sql
   mysql -u root -p aulainteractiva < database/schema_fase6.sql
   mysql -u root -p aulainteractiva < database/schema_fase9.sql
   mysql -u root -p aulainteractiva < database/schema_fase10.sql
   ```
4. Copia `public_html/config/env.example.php` como `public_html/config/env.php` y completa tus credenciales locales.
5. Para probar la Fase 3, obtén una API Key gratuita en [Google AI Studio](https://aistudio.google.com/apikey) y colócala en `GEMINI_API_KEY` dentro de `env.php`. Sin esto, todo lo demás funciona igual; solo el botón "Generar con Gemini" mostrará el mensaje de error genérico.
6. Abre `http://localhost/aulainteractiva/index.php` en el navegador.
7. Regístrate como profesor y como estudiante (dos cuentas distintas) para probar ambos flujos.

## Despliegue en InfinityFree

1. Crea tu cuenta y hosting en InfinityFree, y una base de datos MySQL desde el panel (vhost/cPanel).
2. Sube el contenido de `public_html/` (no la carpeta en sí, sino su contenido) a la carpeta `htdocs` de tu hosting, vía Administrador de Archivos o FTP.
3. En `config/env.php` (créalo en el servidor a partir de `env.example.php`) coloca las credenciales MySQL que InfinityFree te asigna (host, nombre de base de datos, usuario, contraseña), y tu `GEMINI_API_KEY` si quieres usar la generación con IA.
4. Importa `schema_fase1.sql`, `schema_fase2.sql`, `schema_fase4.sql`, `schema_fase5.sql`, `schema_fase6.sql`, `schema_fase9.sql` y `schema_fase10.sql` (en ese orden) desde phpMyAdmin de InfinityFree.
5. Ajusta `APP_URL` y `APP_ENV=production` en `env.php`.
6. Visita tu dominio y prueba registro/login.

## Estructura de carpetas

```
public_html/
  assets/          CSS, JS, imágenes, iconos (assets/icons/ = favicons + pack de iconos; assets/img/ = logo)
  config/          config.php, database.php, env.php (no versionado)
  includes/        auth.php, security.php, functions.php, header.php, footer.php, game_helpers.php, assignment_helpers.php, project_helpers.php, course_helpers.php
  services/        GeminiService.php (único punto de contacto con la IA)
  api/
    games/         state.php, host_action.php, join.php, answer.php (partidas en vivo)
    gemini/        generate.php (generación de actividades con IA)
  teacher/         panel y páginas del profesor (cursos, actividades, partidas, generación con IA, asignaciones, revisión de trabajos)
  student/         panel y páginas del estudiante (asignaturas, actividades pendientes, calificaciones, unirse con código)
  favicon.ico, site.webmanifest    en la raíz de public_html/ (identidad de marca)
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
  schema_fase9.sql
  schema_fase10.sql
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

## Fase 8 — Presentaciones (estilo PowerPoint) con modo de presentar

- Nuevo tipo de trabajo de creación académica: **Presentación** (`presentacion`), quinto tipo sobre el mismo modelo `student_projects` — sin tablas nuevas.
- Editor de varias diapositivas (`editor/presentacion.php`), reutilizando las mismas herramientas de Canvas/Fabric.js del editor gráfico (texto, formas, imágenes, colores, deshacer/rehacer): lista de diapositivas a la izquierda, lienzo de la diapositiva activa a la derecha (relación 16:9).
- Gestión de diapositivas: **+ Diapositiva**, **Duplicar**, **Eliminar** (mínimo 1), reordenar con **↑ / ↓**. Al cambiar de diapositiva, el contenido de la anterior se guarda automáticamente en memoria antes de mostrar la siguiente.
- **▶ Presentar**: modo de pantalla completa integrado en la misma página (sin recargar ni depender de otro archivo), con navegación por botones, flechas del teclado y barra espaciadora, y tecla Escape para salir. Disponible tanto mientras se edita como después de entregar, y también para el profesor al revisar el trabajo.
- Mismas protecciones que el resto del editor gráfico: ancho/alto de cada diapositiva acotado, máximo de diapositivas (60) y de objetos por diapositiva (300), e imágenes restringidas solo a las que el propio estudiante subió (cualquier referencia externa se elimina al guardar).
- Todo el flujo (autocreación del proyecto con una diapositiva en blanco, guardado de varias diapositivas con saneo activo de imagen externa y de dimensiones fuera de rango, entrega, bloqueo posterior de edición, y vista de solo lectura del profesor con su propio botón de presentar) fue probado de extremo a extremo con base de datos real antes de la entrega.
- **Limitación conocida**: no pude probar visualmente el editor ni el modo de presentación en un navegador real (igual que con el resto de los editores basados en Fabric.js) — te recomiendo abrirlo y probar la experiencia tú mismo. Tampoco hay todavía transiciones animadas entre diapositivas ni plantillas prediseñadas.

## Fase 9 — Actividades estilo Educaplay (ordenar, relacionar, completar espacios)

Amplía las actividades interactivas más allá del formato Kahoot (preguntas de opción), agregando tres tipos nuevos al mismo sistema de partidas en vivo, tanto en creación manual como generados con Gemini.

- **Ordenar elementos**: el profesor define un conjunto de elementos en el orden correcto (ej. planetas del sistema solar, pasos de un proceso, eventos históricos). Al estudiante se le muestran mezclados y debe reordenarlos con botones ↑/↓.
- **Relacionar parejas**: el profesor define parejas correctas (ej. país ↔ capital). Al estudiante se le muestra la columna izquierda fija y la derecha mezclada; empareja con clic-clic (sin necesitar arrastrar).
- **Completar espacios**: el profesor escribe un enunciado con un espacio en blanco y la respuesta correcta; el estudiante escribe la respuesta en un campo de texto (comparación insensible a mayúsculas y espacios).
- **Calificación con crédito parcial**: a diferencia de selección múltiple (todo o nada), ordenar y relacionar califican por porcentaje de acierto — por ejemplo, 2 de 4 elementos en su posición correcta = 50% de los puntos de esa pregunta (más la bonificación por rapidez, también escalada proporcionalmente). Completar es todo-o-nada, como corresponde a una respuesta exacta.
- **Seguridad de las preguntas nuevas**: igual que con selección múltiple, el estudiante nunca recibe la respuesta correcta (el orden correcto, las parejas reales o el texto de la respuesta) hasta que termina la fase de resultados. El "desorden" que ve cada estudiante es estable durante todo el polling (no cambia cada 1-2 segundos) gracias a una mezcla con semilla determinística por partida+pregunta.
- **Generación con Gemini**: el formulario de "Generar con Gemini" ahora permite pedir cualquiera de estos tipos por separado, o una actividad "Mixta" que combina los cinco tipos disponibles. Gemini nunca controla la partida ni publica directamente — el profesor revisa la vista previa (ahora adaptada a cada tipo) antes de guardar, igual que con selección múltiple.
- **Bug real encontrado y corregido durante las pruebas**: mi primer guion de prueba en bash usó sintaxis de arreglos que no es compatible con el intérprete `sh` de este entorno — no fue un problema del código de la aplicación, sino de mi propio script de verificación; lo corregí y repetí la prueba completa antes de continuar.
- Todo el flujo fue probado de extremo a extremo con base de datos real: creación manual de los 3 tipos nuevos, una partida jugada completa con puntajes parciales verificados matemáticamente (ordenar: 2/4 → 75 pts; relacionar: 2/3 → 100 pts; completar: coincidencia exacta insensible a mayúsculas → 150 pts), autocalificación de asignaciones con estos tipos, y guardado de una actividad "generada" con los 5 tipos mezclados simulando la respuesta de Gemini.
- **Limitación conocida**: la interfaz de arrastrar-y-soltar no existe (se usa clic-clic para relacionar y botones ↑/↓ para ordenar, que funcionan en cualquier dispositivo incluyendo móvil, pero son menos vistosos que arrastrar). Tampoco hay todavía preguntas con audio/video como estímulo (solo imagen), ni "preguntas aleatorias" ni "modo por equipos" mencionados en la especificación original.

## Alcance completo

Con esto se cierran las 7 fases planeadas originalmente, más las Fases 8, 9 y 10 agregadas a pedido. La plataforma cubre: autenticación y roles, estructura académica (cursos/asignaturas) con auto-matrícula por código, actividades interactivas en vivo con partidas por código (selección múltiple, verdadero/falso, ordenar, relacionar y completar espacios), generación de actividades con IA para los 5 tipos (siempre revisada por el profesor), asignaciones con calificación automática (incluyendo crédito parcial) y manual, y cinco herramientas de creación académica para el estudiante (resúmenes, tablas comparativas, infografías, mapas mentales y presentaciones), con multimedia, exportación y estadísticas — todo con la identidad visual de Dynamic SGA.

## Identidad de marca — Dynamic SGA

El proyecto se renombró de "AulaInteractiva" a **Dynamic SGA**, aplicando la línea gráfica provista por el cliente (no se inventó una paleta nueva):

- **Colores**: gradiente de marca cian → azul → violeta (`#09C9FF → #0878F9 → #7B2CFF`), azul marino (`#071A3D`) para texto y encabezados. Definidos como variables CSS en `assets/css/style.css`, así que cambian en **todas** las páginas a la vez.
- **Tipografía**: Montserrat (Google Fonts), pesos 400 a 800.
- **Logo**: se generó una versión "solo isotipo" (`assets/img/logo-icon.svg`) a partir del SVG editable original, para usarla en el menú superior y el pie de página sin saturar espacios pequeños.
- **Favicons**: pack completo instalado (`favicon.ico` en la raíz, más los tamaños 16 a 512px y `site.webmanifest` en `assets/icons/`), enlazados desde `includes/header.php` con las etiquetas `<link rel="icon">` estándar para que se vea bien en pestañas del navegador, marcadores y accesos directos en el celular.
- **Pack de iconos**: el sprite de 40 iconos que enviaste se recortó automáticamente (con Python/Pillow) en imágenes individuales transparentes, guardadas en `assets/icons/`. Se usan ya en: el panel del profesor (tarjetas de Cursos/Asignaturas/Estudiantes), la portada (tarjetas "Para profesores"/"Para estudiantes"/"Sin instalaciones") y la página de unirse a una asignatura. Quedan los 40 disponibles en esa carpeta para usarlos en más pantallas cuando quieras — el resto del sitio se actualizó automáticamente por los nuevos estilos CSS aunque no lleve un ícono específico todavía.
- Se revisó y reemplazó **cada mención de "AulaInteractiva"** en el código (título de las páginas, portada, textos de ayuda) — confirmado con una búsqueda exhaustiva antes de la entrega.

## Fase 10 — Auto-matrícula por código de curso

- Cada curso ahora tiene un **código de 6 caracteres** (sin 0/O/1/I/L, para que no se confunda al leerlo en voz alta), que se genera automáticamente la primera vez que el profesor visita la página del curso.
- El profesor lo ve destacado en `teacher/course.php`, con un botón para **regenerarlo** (invalida el anterior) si necesita controlarlo — por ejemplo, si ya no quiere que se sigan uniendo con el código viejo.
- El estudiante lo ingresa desde su panel → **"Unirme a una asignatura"** (`student/join_course.php`) y queda inscrito al instante, sin que el profesor tenga que escribir su correo manualmente.
- **Mejora adicional que aproveché de agregar**: si el estudiante se une a un curso que ya tenía asignaciones creadas, el sistema le crea automáticamente las entregas pendientes de esas asignaciones (antes, un estudiante inscrito tarde se las perdía por completo, tanto por este método nuevo como por la inscripción manual — ahora ambos caminos comparten la misma función y quedan arreglados a la vez).
- Sigue existiendo la inscripción manual por correo (útil si el profesor prefiere controlar la lista él mismo), ahora marcada como opcional.
- Todo esto (generación automática del código, auto-matrícula, relleno automático de asignaciones previas, bloqueo de inscripción duplicada, código inválido rechazado, y regeneración invalidando el código anterior) fue probado de extremo a extremo con base de datos real antes de la entrega.

Posibles ampliaciones futuras: fichas de estudio, preguntas con audio/video como estímulo, modo por equipos, arrastrar-y-soltar real para relacionar, integración con Google Classroom/Moodle, y una versión verdaderamente asíncrona de las actividades interactivas (sin depender de una sesión en vivo).

## Fase 12 — Disponibilidad por fecha/hora, edición, cursos inactivos e importación

- **Inicio y entrega con hora**: `assignments.start_date` / `due_date` ya eran DATETIME; ahora el formulario pide fecha **y hora**. Antes de la hora de inicio la asignación no existe para el estudiante (panel, página directa, cuestionarios, juegos de palabras, editores, partidas en vivo). Pasada la hora de entrega se muestra como **Vencida** y se bloquea guardar, subir archivos y entregar (API y editores en solo lectura). Una entrega que el profesor devuelve para corregir se considera reabierta. Helpers en `includes/availability_helpers.php`.
- **Editar asignaciones**: `teacher/assignment_edit.php` (título, instrucciones, inicio, entrega, puntaje).
- **Cursos inactivos**: columna `courses.is_active` (`database/schema_fase12.sql`). Los estudiantes no ven el curso, sus materiales ni pueden matricularse con su código. El profesor los ve en `teacher/inactive_courses.php` y los reactiva desde allí o desde *Ajustes* del curso.
- **Importar contenido**: `teacher/import_content.php` + `includes/import_helpers.php`. Copia actividades interactivas y cuestionarios (con preguntas, opciones e imágenes) de cualquier curso del profesor, activo o inactivo, a una asignatura de un curso activo: todo el curso o solo lo marcado.
- **Zona horaria opcional**: define `APP_TIMEZONE` en `config/env.php` (ej. `'America/Santo_Domingo'`) si la hora del servidor no coincide con la tuya.

## Fase 13 — Imágenes: reducción de tamaño y visualización

- `includes/image_helpers.php`: `image_optimize()` (GD) reduce el lado mayor a 1280 px, corrige la orientación de fotos de celular, quita metadatos/GPS y recomprime; los PNG sin transparencia pasan a JPEG (o se queda el PNG si pesa menos). `uploads_public_url()` devuelve rutas locales sin dominio.
- Se acepta la foto original de hasta 10 MB (`UPLOAD_MAX_IMAGE_RAW_BYTES`) y se guarda ya reducida (máx. 3 MB). Aplica a las imágenes de los estudiantes y a las de las preguntas del profesor.
- El navegador también reduce la foto antes de subirla (`assets/js/image_upload.js`).
- Las imágenes de preguntas (partida en vivo, proyector, editor) usan rutas locales en vez de `APP_URL`, así se ven aunque el sitio se abra con otro dominio.
- `teacher/optimize_images.php`: reduce por lotes las imágenes que ya estaban subidas (mismo nombre y formato).

## Fase 14 — Descripciones en HTML y juegos nuevos (Sopa de letras, Emparejar conceptos)

- **Descripción en HTML** (asignaciones y cuestionarios): `assignments.description_format` / `quizzes.description_format` (`text` | `html`, ver `database/schema_fase14.sql`). El profesor elige *Texto sencillo* o *HTML*; con HTML tiene barra de ayuda (título, lista, enlace, imagen, video, tabla) y vista previa. `includes/description_helpers.php` limpia el HTML con lista blanca al guardar y al mostrar (sin scripts/formularios/eventos; `<iframe>` solo de YouTube, Vimeo, Google Drive/Docs y otros sitios de `DESC_IFRAME_HOSTS`; estilos CSS filtrados). `includes/description_field.php` es el campo reutilizable; `api/teacher/preview_description.php` la vista previa.
- **Sopa de letras** (`game_mode = 'sopa_letras'`): palabras de 2 a 15 letras escondidas en una cuadrícula (→ ↓ ↘ ↗) generada con una semilla guardada en el intento (`wordsearch_build_layout`). El estudiante marca la línea y el servidor comprueba (`api/wordgames/wordsearch.php`); las posiciones nunca salen al navegador. Nota = palabras encontradas ÷ total.
- **Emparejar conceptos** (`game_mode = 'emparejar'`): cada fila del banco es concepto (opción) ↔ definición (enunciado). Dos columnas barajadas con códigos opacos en la derecha (`api/wordgames/matching.php`). Nota = parejas unidas bien a la primera ÷ total.
- Ambos reutilizan el banco de palabras, el banco aleatorio por estudiante, importación Aiken, repetir (mejor nota), fechas y reportes de ahorcado/crucigrama. UI del estudiante: `assets/js/wg_wordsearch.js`, `assets/js/wg_matching.js`.

## Fase 15 — Eliminar asignaciones, actividades y cuestionarios; borrar imágenes de trabajos

- `includes/delete_helpers.php` + `teacher/delete_item.php`: eliminación con pantalla de confirmación (resumen de lo que se borra, casilla de confirmación, CSRF, solo el dueño). Asignación → entregas, trabajos de estudiantes (y sus archivos), intentos de cuestionario/juegos. Actividad → preguntas e imágenes, partidas en vivo y todas las asignaciones que la usan. Cuestionario → preguntas, intentos y todas las asignaciones que lo usan (los cuestionarios no tienen claves foráneas en la BD, así que se borra por código, en una transacción).
- Los archivos del disco se borran después de confirmar la BD y solo si ningún otro trabajo o pregunta los sigue usando.
- `teacher/delete_images.php`: borra todas las imágenes subidas por estudiantes en las asignaciones elegidas (quita las imágenes del contenido del trabajo —galería, lienzos, `<img>` del texto— y elimina los archivos); el resto del trabajo se conserva.
- Sin cambios en la base de datos.

## Fase 16 — Imágenes que no se visualizan (icono de imagen rota)

- Causa probable: `uploads/.htaccess` usaba `Options -ExecCGI` y `AddHandler`; en hostings con `AllowOverride` restringido Apache devuelve **error 500 en todos los archivos** de `uploads/` (incluidas las imágenes). Se reproduce con Apache 2.4 y `AllowOverride FileInfo AuthConfig Limit Indexes`. El `.htaccess` corregido solo usa `<FilesMatch>` + `Require all denied` (se mantiene bloqueada la ejecución de scripts). Este archivo vive solo en el hosting (no está en el repositorio).
- Los archivos subidos ahora se guardan con permisos `0644` (`move_uploaded_file` los deja en `0600`, ilegibles para el servidor web en algunos hostings).
- `teacher/diagnose_images.php`: prueba en el navegador la imagen por la dirección normal y por PHP (`api/media/image.php`), muestra el veredicto y ofrece reparar (`.htaccess` corregido, permisos, quitar `.htaccess`).

## Fase 17 — Súper administrador

- Cuenta `admin@dynamicsga.com` (rol `admin`) creada por `database/schema_fase17.sql` con contraseña **temporal**: se obliga a cambiarla en el primer inicio de sesión (`users.must_change_password`, `change_password.php`).
- **Entrar como profesor** (`admin/impersonate.php`, `stop_impersonation.php`, helpers en `includes/auth.php`): la sesión actúa como el profesor y el administrador conserva su identidad en `$_SESSION['impersonator']`; así todas las pantallas del profesor funcionan sin cambios y el administrador ve y edita todo lo de todos los profesores. Un aviso fijo permite volver; cada acción en `audit_log` lleva la marca `[admin #id]`.
- Panel (`admin/`): estadísticas globales, profesores (entrar como, restablecer contraseña, activar/desactivar), todos los cursos (abrir como su profesor, activar/inactivar), estudiantes (buscar, restablecer contraseña, activar/desactivar), registro de actividad y crear usuarios (profesor o administrador).
- Seguridad: solo el rol `admin` accede a `admin/`; suplantar exige CSRF y solo a profesores; contraseñas temporales con cambio obligatorio; las cuentas desactivadas pierden la sesión abierta en ≤ 5 min; límite de 5 intentos fallidos de inicio de sesión por correo cada 15 min.

## Fase 18 — Herramientas de imágenes solo para el súper administrador

- `admin/images.php` (menú **🖼️ Imágenes**) agrupa: `admin/optimize_images.php`, `admin/delete_images.php` (ahora con alcance **global**: asignaciones de todos los profesores, mostrando el profesor) y `admin/diagnose_images.php`. Todas exigen el rol `admin`.
- `teacher/optimize_images.php`, `teacher/delete_images.php` y `teacher/diagnose_images.php` quedan como archivos que solo redirigen al panel (así ninguna copia o enlace antiguo da acceso a un profesor). Se quitaron sus botones del panel del profesor y de la pantalla de la asignación.
- `delete_images_overview()` y `delete_assignment_images()` aceptan `$teacherId = null` (alcance de administrador).

## Fase 19 — Prórroga por estudiante, calendario, progreso del estudiante y año escolar

- **Prórroga individual**: tabla `assignment_extensions` (`database/schema_fase19.sql`). La fecha efectiva de un estudiante es `COALESCE(ax.due_date, a.due_date)` en todas las consultas del lado estudiante (panel, asignación, cuestionarios, juegos de palabras, bloqueo de editores/API de proyectos: `assignment_lock_reason`). Pantalla del profesor: `teacher/assignment_extensions.php` (dar/quitar a varios a la vez, con motivo). Funciones en `includes/availability_helpers.php`.
- **Calendario**: `includes/calendar_helpers.php`, `student/calendar.php` (mes + línea de tiempo; respeta asignaciones ocultas, cursos inactivos y la prórroga) y `teacher/calendar.php` (entregas con avance y publicaciones programadas).
- **Mi progreso**: `includes/progress_helpers.php` + `student/progress.php` (promedio ponderado por puntos, entregas a tiempo, pendientes/vencidas, gráfico SVG de evolución, por asignatura y últimas notas).
- **Año escolar**: columna `courses.school_year` (el año empieza en agosto). `includes/schoolyear_helpers.php`, `teacher/school_year.php` (cerrar/reabrir todos los cursos de un año), edición en Ajustes del curso y cierre/reapertura global en `admin/courses.php`.

## Fase 20 — Avisos, notificaciones, imágenes huérfanas y copia de seguridad

- **Avisos del profesor** (`announcements`, `teacher/announcements.php`, `includes/announcement_helpers.php`): avisos a un curso con texto o HTML (reutiliza el sanitizador de descripciones), fijar, programar publicación y ocultar en una fecha. El estudiante los ve en su panel y en `student/announcements.php`; solo si el curso está activo y está inscrito.
- **Campanita de notificaciones** (`notifications`, `includes/notification_helpers.php`, `api/notifications/*`, `assets/js/notifications.js`, `student/notifications.php`): nueva asignación (solo cuando ya es visible), prórroga, calificación (y corrección de nota), trabajo devuelto y aviso. Se generan al cargar las páginas del estudiante (como máximo 1 vez por minuto) con `INSERT … ON DUPLICATE KEY UPDATE` sobre la clave única (usuario, tipo, elemento): no hace falta enganchar cada lugar donde se califica y se respetan las asignaciones programadas.
- **Imágenes huérfanas** (`admin/orphan_images.php`, `includes/orphan_helpers.php`): archivos de `uploads/images` que no referencia ningún trabajo, pregunta, descripción ni aviso y tienen más de 24 h. Se recalcula al borrar y si no se puede leer una tabla no se borra nada. Solo administrador.
- **Copia de seguridad** (`admin/backup.php`, `includes/backup_helpers.php`): volcado completo de la base de datos en PHP puro (sin mysqldump), en `.sql` o `.sql.gz` (compresión en streaming con `deflate_add`). Se restauró en una base nueva y se comprobó fila por fila (checksum) y claves foráneas. Aviso en el panel de administrador si pasan más de 7 días sin copia. Solo administrador.

## Fase 21 — Entrega de archivos (PDF, Word, Excel...) y protección del trabajo del estudiante

- **Nuevo tipo de trabajo "Entrega de archivos"** (`project_type = 'archivos'`): el estudiante sube hasta 5 archivos (PDF, Word, Excel, PowerPoint, OpenDocument, texto, CSV, ZIP o imágenes; máx. 10 MB cada uno, ajustable en `includes/project_files_helpers.php`) desde `editor/archivos.php`, con barra de progreso y un comentario opcional para el profesor. Cada archivo se guarda en el servidor apenas termina de subirse (no depende de "Guardar borrador").
- **Seguridad:** lista blanca de extensiones, comprobación de la firma real del archivo (un `.php` renombrado a `.pdf` se rechaza), nombre aleatorio en disco, carpeta `uploads/submissions/` sin acceso directo (`.htaccess`) y descarga solo por `api/submissions/download.php`, que verifica que sea el estudiante dueño, el profesor de esa asignación o el súper administrador.
- **Profesor:** en `teacher/assignment_detail.php`, junto a "Calificación" y "Retroalimentación", aparece el botón **🗑️ Borrar archivos (N · tamaño)** de cada estudiante (y uno para borrar todos los de la asignación). También hay descargar/ver/borrar archivo por archivo en `teacher/view_project.php`. Borrar libera el espacio del hosting y conserva la entrega y la nota; el estudiante ve "archivo eliminado".
- **Súper administrador:** nueva pestaña **📎 Archivos** (`admin/submission_files.php`): espacio ocupado por asignación, filtro por antigüedad y borrado por asignación o archivo por archivo.
- **Limpieza automática:** al borrar una asignación, borrar una entrega o quitar a un estudiante de una asignación, sus archivos también se eliminan del disco.
- **Protección del trabajo (todos los editores):** nuevo `assets/js/draft_guard.js`. Guarda unos segundos después del último cambio (antes solo cada 20 s), mantiene una copia local en el navegador, guarda al ocultar o cerrar la pestaña, avisa si quedan cambios sin guardar y recupera automáticamente el trabajo si el servidor no alcanzó a recibirlo. "Entregar" ya no continúa si el guardado falló. `api/projects/save.php` devuelve ahora la `version` guardada.
- **Base de datos:** ejecutar `database/schema_fase21_archivos.sql` (tabla `project_files`) antes de usar el nuevo tipo.
- **Nota:** la copia de seguridad del administrador cubre la base de datos, no los archivos de `uploads/`.
