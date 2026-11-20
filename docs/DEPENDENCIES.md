# Registro de dependencias y decisiones
Se autoriza incorporar librerías PHP/JavaScript/CSS que aporten velocidad, calidad de interfaz o fiabilidad bajo los criterios de AGENTS.md.

## Registro obligatorio por incorporación
| Campo | Contenido requerido |
| --- | --- |
| Fecha y tarea | Hito e ID |
| Paquete / upstream | Nombre y enlace oficial |
| Versión / licencia | Versión estable fijada, compatibilidad y licencia |
| Beneficio | Problema concreto y alternativa existente considerada |
| Mantenimiento | Última release, actividad reciente, comunidad, respuesta a incidencias |
| Seguridad | Avisos conocidos, dependencias transitivas y riesgo |
| Impacto | Tamaño frontend, recursos backend, coste y accesibilidad |
| Validación | Tests/build/auditoría ejecutados y limitaciones |
| Retirada | Cómo exportar datos o sustituirlo |

No hay nuevas librerías instaladas por el hito documental H00. Los paquetes del starter se inventariarán en H01, con sus versiones reales.

## Decisión vigente D008 — Portal de servicios (2026-11-08)
Sustituye D004–D007 del plan inicial: Laravel/Livewire solo índice y navegación, sin catálogo/reproductor/lector/notas propios ni sincronización entre motores. Cada servicio gestiona cuentas y datos.
Navidrome se incorpora como contenedor autónomo, no paquete PHP/JS: navidrome/navidrome:0.64.2, upstream https://github.com/navidrome/navidrome/releases/tag/v0.64.2 (consultado 2026-11-08). Revisar licencia GPL-3.0 y obligaciones al redistribuir/modificar. No se redistribuye su código en este cambio.
Versión fijada, sin latest; mantenimiento oficial activo. Coste: RAM/CPU/disco del servicio y sus actualizaciones. Validación runtime pendiente por falta de Docker. Salida: música en directorio independiente y backup de data.
Conservar política de librerías PHP/JS, pero instalarlas solo para el portal cuando aporte valor. Historia del enfoque anterior: commit 614ab0f.


## D009 — Puente de sesión de Navidrome (2026-11-10)
Se reutiliza nginx:1.27-alpine, ya fijado en el portal, para servir el puente y reenviar Navidrome sin modificar su imagen. No se añaden paquetes PHP, JS o CSS. El gateway se ejecuta como UID 101, sin capabilities, con raíz read-only y tmpfs; no monta bibliotecas ni socket Docker.
Contrato revisado en upstream v0.64.2: ui/src/authProvider.js (claves de autenticación) y ui/src/store/persistState.js (state con cola/biblioteca). Fuentes: https://github.com/navidrome/navidrome/blob/v0.64.2/ui/src/authProvider.js y https://github.com/navidrome/navidrome/blob/v0.64.2/ui/src/store/persistState.js.
Se usa Crypt de Laravel para pases autenticados efímeros, no para entregar credenciales musicales. Actualizar Navidrome requiere revisar este contrato y ejecutar las pruebas. Retirada: desactivar puente en portal y volver al Compose musical autónomo conservando data/music. La actualización general de la versión Nginx heredada y revisión de imágenes corresponde al endurecimiento de producción; no se declara readiness productiva.

## D010 — Tablas, interactividad y animaciones sin librerías nuevas (2026-11-14, D02)
Necesidad: listas paginadas y filtrables sin recargar, guardado en el sitio, avisos y animaciones con preferencia propia.
Se usan capacidades ya instaladas: Livewire 4.4.8 (`WithPagination`, `#[Url]`, `#[Computed]`, `wire:model.live`, `wire:navigate`, `wire:confirm`, atributo `data-loading`) y Alpine (incluido en Livewire) para avisos y atributos de `<html>`. Componentes de tabla y paginación propios del tema (`x-ui::table`, `x-ui::pagination`).
Alternativas evaluadas y descartadas por ahora:
- rappasoft/laravel-livewire-tables v3.8: fija `livewire/livewire ^3.0`, incompatible con Livewire 4 (notas de la release v3.8.0, consultadas 2026-11-14: https://newreleases.io/project/github/rappasoft/laravel-livewire-tables/release/v3.8.0).
- power-components/livewire-powergrid: activo y compatible con Livewire 4/Tailwind 4 (Packagist, actualizado 2026-10-31: https://packagist.org/packages/power-components/livewire-powergrid), pero trae tema y vistas propias que romperían la capa de tema sustituible (docs/DESIGN.md) y su tamaño excede el volumen de datos de una suite familiar.
Además, el entorno del agente no alcanza Packagist, así que no puede generar lockfiles verificables. Revisar PowerGrid si una lista supera miles de filas o necesita exportación/edición en línea.
Fuente tipográfica: se retira Instrument Sans (bunny, heredada de la plantilla y sin uso en el tema Nocturne, que usa la pila del sistema); desaparece la descarga de ~50 KB por página y el aviso de `fontaine`.


## D011 — restic para la copia de seguridad del NAS (2026-11-14, S05.1)
Necesidad: copia cifrada, incremental y verificable de la DB del portal, los archivos privados y los datos de Navidrome a un disco externo o un destino remoto, con retención y restauración ensayada.
Se adopta **restic** como contenedor efímero (`restic/restic:0.19.1`, fijado en `scripts/nas-backup.sh` y `backup.env.example`), no como paquete PHP/JS ni instalación en el host. Lo ejecuta el operador con `make nas-backup`; el portal no lo invoca ni recibe el socket Docker.
- Versión: 0.19.0 publicada el 2026-06-09 (https://restic.net/blog/2026-06-09/restic-0.19.0-released/) y 0.19.1 de corrección en julio de 2026 (https://github.com/restic/restic/releases/tag/v0.19.1; Debian la aceptó el 2026-07-06: https://tracker.debian.org/restic). Consultado 2026-11-14.
- Licencia BSD de 2 cláusulas (https://github.com/instrumentisto/restic-docker-image, sección License, consultado 2026-11-14). Binarios reproducibles y proyecto con actividad continua en 2026.
- Motivos: cifrado obligatorio en el cliente, deduplicación, `forget --prune` para la retención, `check --read-data-subset` para detectar corrupción y destinos locales, SFTP, REST y S3/B2 con la misma herramienta.
- Alternativas: BorgBackup (equivalente en funciones, pero sin destinos de objetos nativos y con servidor Borg en el remoto); `rsync`/`tar` (sin cifrado, deduplicación ni verificación); Backrest o similares (interfaz web y servicio permanente que el portal no necesita).
- Coste: una imagen más que actualizar a mano (cambiar la etiqueta y repetir `make nas-backup-verify`) y la custodia de la contraseña del repositorio fuera del servidor.
- Pendiente de validar: el entorno del agente no tiene Docker ni red hacia el registro, así que la etiqueta `0.19.1` de la imagen y una copia real no se han ejecutado; se comprueban con `make nas-backup` y `make nas-backup-verify` en el servidor. Retirada: borrar `backup.env` y la tarea programada; el repositorio sigue siendo legible con cualquier restic.

## D012 — Visor de imágenes, vídeo y optimización sin pérdida (2026-11-14, S03.5)
Decisión del usuario: visor con zoom y reproductor en Mis archivos; al subir, solo optimización sin pérdida real; vídeo reproducido por el navegador, sin conversión en el servidor.
- **jpegtran** (paquete Debian `libjpeg-turbo-progs` 1:2.1.5-2 de bookworm, la base de la imagen PHP; https://packages.debian.org/bookworm/libjpeg-turbo-progs, consultado 2026-11-14). Es el compañero de `libjpeg62-turbo`, que la imagen ya instala para GD: mismo proyecto y mismas actualizaciones de seguridad de Debian. Reordena la codificación del JPEG sin tocar los píxeles y conserva los metadatos (`-copy all`).
- Sin optimizador de PNG: el `optipng` de bookworm es una versión antigua para procesar archivos no confiables y recomprimir con GD no es sin pérdida (pierde perfiles de color y profundidad). Revisar `oxipng` cuando la imagen base pase a una Debian que lo incluya.
- Sin librerías JavaScript: el visor (`resources/js/viewer.js`, ~200 líneas) y el reproductor nativo `<video controls>` cubren zoom, arrastre, pellizco, teclado, pantalla completa, velocidad e imagen en imagen. El entorno del agente no alcanza el registro de npm, así que no puede generar un lockfile verificable; evaluar PhotoSwipe o Plyr solo si hace falta algo que el navegador no dé (subtítulos externos, listas).
- Sin ffmpeg: no se recodifica ni se transcodifica. Coste: los formatos que el navegador no entiende (p. ej. vídeos HEVC en navegadores sin soporte) solo se descargan.
- Requiere reconstruir la imagen (`make rebuild`); sin `jpegtran` el archivo se guarda tal como llega.

## D013 — Cifrado en reposo con libsodium (2026-11-15, S03.7)
Sin dependencias nuevas: la extensión `sodium` viene incluida en PHP 8.4 (imagen oficial `php:8.4-fpm-bookworm`) y aporta XChaCha20-Poly1305; HKDF es `hash_hkdf` del núcleo. Se descartan un paquete de cifrado de archivos (añadiría código de terceros para ~200 líneas propias probadas) y `Crypt`/`encrypt()` de Laravel (cifra cadenas completas en memoria, sin acceso por rangos y ligado a `APP_KEY`). Los ZIP se generan con un escritor propio en flujo (`Services/Files/ZipStreamWriter`, almacenado y ZIP64) en lugar de `ZipArchive`, que solo admite archivos de origen en claro; validado con libzip (`CHECKCONS`), `unzip -t` y `zipfile` de Python.

## D014 — Cropper.js 2 para encuadrar la foto de perfil (2026-11-15, U03)
- **cropperjs 2.3.1** + 11 paquetes `@cropper/*` de la misma versión (todos MIT, sin otras dependencias transitivas; lockfile generado con `make npm CMD="install cropperjs@2"` en el entorno del usuario). Proyecto de referencia para recortar imágenes en el navegador: unas 800 000 descargas semanales, versiones estables 2.x publicadas regularmente durante 2025-2026, componentes web (`cropper-canvas`, `cropper-image`, `cropper-selection`) que funcionan con Alpine sin envoltorio. Fuentes: https://www.npmjs.com/package/cropperjs y https://fengyuanchen.github.io/cropperjs/v2/api/ (consultadas 2026-11-15).
- Uso (`resources/js/avatar-editor.js`): selección cuadrada fija mostrada como círculo, imagen que se mueve y amplía debajo y que nunca deja hueco (cálculo propio sobre la matriz del evento `transform`: la receta con clon de la documentación 2.0 falla en 2.3, comprobado en el navegador). Cropper.js debe iniciarse con el recuadro ya visible: si mide 0 × 0, la selección queda vacía, recorte con `$toCanvas` a 512 px. Sustituye ~250 líneas propias. La rueda sola sigue desplazando la página (Ctrl/⌘ + rueda amplía), como en el visor.
- En el servidor no cambia nada: GD (con WebP) vuelve a decodificar, recortar y guardar un WebP de 256 px. Las imágenes PHP se compilan con `--with-webp` (`libwebp-dev` de bookworm).

## D015 — ExifTool para quitar metadatos de las fotos (2026-11-15, S03.8)
- **ExifTool** (paquete Debian `libimage-exiftool-perl` 12.57 de bookworm, https://packages.debian.org/bookworm/libimage-exiftool-perl, consultado 2026-11-15; proyecto https://exiftool.org, publicaciones cada pocas semanas desde 2003). Es la herramienta de referencia para leer y escribir metadatos: cubre JPEG, PNG, GIF y WebP (y vídeo, para el siguiente paso), reescribe solo los metadatos sin tocar los píxeles y permite conservar orientación y perfil de color. Licencia libre (la de Perl: Artistic/GPL). Actualizaciones de seguridad a través de Debian. Coste: Perl en la imagen (unos 20 MB).
- Descartadas: recodificar con GD o Imagick (pierde calidad, contra la regla de no recodificar originales), `jpegtran -copy none` (solo JPEG y además pierde orientación y perfil de color) y librerías PHP de EXIF como PEL (solo JPEG/TIFF, sin escritura de PNG/WebP).
- Se invoca con `Process` y argumentos en lista (sin shell) sobre una copia con nombre controlado; ver `Services/Storage/ImageMetadata`.
- De paso, la imagen de producción instala también `libjpeg-turbo-progs`: faltaba, así que `jpegtran` no optimizaba en producción.
- Requiere reconstruir las imágenes (`make rebuild`).

## D016 — Kavita 0.9.1 para Lectura (2026-11-15, P04.1)
- **Kavita** (`jvmilazz0/kavita:0.9.1`, publicación v0.9.1.4 de 2026-09-01; GPL-3.0; https://github.com/Kareadita/Kavita y https://www.kavitareader.com, consultados 2026-11-15). Servidor de lectura con lectores propios para EPUB, PDF y cómics/manga (CBZ, CBR, CB7, imágenes), OPDS, multiusuario y permisos por biblioteca. Unos 364 commits en los últimos doce meses con actividad todos los meses. Se ejecuta como servicio aparte (contenedor), así que su licencia GPL no afecta al portal.
- Descartado Komga (MIT, excelente en cómics pero con lectura de EPUB/PDF más limitada) porque se quieren novelas y cómics por igual; Calibre-Web (centrado en libros, cómics básicos) y Audiobookshelf (orientado a audiolibros).
- Kavita+ (de pago: metadatos externos, scrobbling) no se usa.
- La etiqueta `0.9.1` sigue las correcciones de esa versión; fijar por digest al preparar producción (P05). Adaptador probado en CI contra la imagen real.

## Política: librería antes que código propio (decisión del usuario, 2026-11-15)
Para cada función nueva se busca primero una librería o herramienta segura, mantenida y usada por la comunidad (criterios de AGENTS.md); el código propio queda para cuando no exista una adecuada. Que el entorno del agente no alcance un registro (npm, Packagist) no es motivo para reimplementar: se pide acceso o se genera el lockfile donde haya red.

## Komga 1.30.1 · 2026-11-17

Imagen `gotson/komga:1.30.1`, licencia MIT, lector mantenido de EPUB/PDF/CBZ/CBR. Release estable del 10 de noviembre de 2026. Se adopta a petición del usuario para una prueba administrativa local. JVM limitada inicialmente a un heap de 512 MiB; consumo total y tiempos de análisis pendientes de medir. Sin dependencias nuevas de PHP/JavaScript. La prueba real se ejecuta en el job existente de infraestructura.

Fuentes: https://github.com/gotson/komga/releases/tag/1.30.1 · https://github.com/gotson/komga/blob/1.30.1/LICENSE · https://komga.org/docs/installation/docker/ · https://komga.org/docs/openapi/komga-api/

## D017 — Asistente y MCP (2026-11-20, MCP-00/MCP-01)
`laravel/mcp` v1.2.1 y `laravel/ai` v1.4.1 **instalados** (MCP-01 y MCP-02, PR #44; composer.json y lock). `laravel/ai` añade `symfony/yaml` v8.1.11 (MIT, de Symfony, ya en su ecosistema). `laravel/passport` se instalará en MCP-05. Su migración de conversaciones se copió a `database/migrations` y `config/ai.php` deja la lista de proveedores vacía (D2).
| Paquete | Versión / licencia | Uso | Mantenimiento |
| --- | --- | --- | --- |
| `laravel/mcp` | v1.2.1 (17-nov-2026), MIT; PHP ^8.2, Illuminate ^12.41.1\|^13.0 | Servidor MCP con herramientas, recursos y prompts; revisión 2026-07-28 con respuesta a clientes 2025-11-25/2025-06-18; pruebas con `Server::actingAs` | Primera parte de Laravel; 1.0 estable el 15-sep-2026 y cinco versiones desde octubre |
| `laravel/ai` | v1.4.1 (13-nov-2026), MIT; PHP ^8.3, Illuminate ^12\|^13 | Chat: proveedores externos (Anthropic, OpenAI, Gemini…; D3), conmutación por error, conversaciones, streaming, aprobación de herramientas, *fakes* para pruebas | Primera parte; 1.0 estable el 23-sep-2026 y versiones frecuentes |
| `laravel/passport` | v13.12.0 (12-nov-2026), MIT; `league/oauth2-server` ^9.2 | OAuth 2.1 para clientes MCP externos (MCP-05, tras P03) | Primera parte, versiones mensuales |

Descartados: `mcp/sdk` (oficial pero anterior a 1.0 y duplicaría `laravel/mcp`), paquetes MCP de terceros con poca adopción (`opgginc/laravel-mcp-server`, `php-mcp/laravel`, solo hasta la revisión 2025-03-26) y un adaptador LLM propio. Sanctum no se añade: el chat usa la sesión web y los clientes externos, OAuth.
`laravel/mcp` no añade dependencias transitivas nuevas (solo `illuminate/console`, `illuminate/support` e `illuminate/json-schema`, ya incluidos en el framework). `composer audit` no se pudo ejecutar en el entorno del agente (API de avisos bloqueada): pendiente de ejecutar con `make composer CMD="audit --locked"`. Comprobado en el código de `laravel/ai` v1.4.1 que usa las herramientas de `laravel/mcp` en el mismo proceso (`Tools/McpServerTool`).

Fuentes (consultadas 2026-11-20): https://repo.packagist.org/p2/laravel/mcp.json · https://repo.packagist.org/p2/laravel/ai.json · https://repo.packagist.org/p2/laravel/passport.json · https://laravel-news.com/laravel-mcp-1-0 · https://laravel.com/blog/introducing-laravel-ai-sdk-v1 · https://laravel.com/docs/13.x/mcp · https://laravel.com/docs/13.x/ai-sdk

## D018 — poppler-utils (`pdftotext`) para que el asistente lea PDF (2026-11-20, MCP-03)
Paquete de Debian `poppler-utils` en la imagen PHP (`docker/php/Dockerfile`) y en CI. Poppler es la biblioteca PDF de referencia en Linux (GPL-2.0, mantenida por freedesktop.org, versiones mensuales); se usa como programa externo, no se enlaza con el portal. Elegido frente a un parser PDF en PHP porque convierte en un proceso aparte con tiempo máximo: un PDF malicioso o enorme no consume la memoria de PHP-FPM. Sin dependencias PHP/JS nuevas. Sin `pdftotext`, los PDF dan un error claro y el resto del asistente sigue funcionando. Retirada: quitar el paquete y `FileText::pdfText`.
Fuentes (2026-11-20): https://poppler.freedesktop.org/ · https://packages.debian.org/bookworm/poppler-utils
