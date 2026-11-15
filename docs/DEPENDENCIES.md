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
- Uso (`resources/js/avatar-editor.js`): selección cuadrada fija mostrada como círculo, imagen que se mueve y amplía debajo y que nunca deja hueco (receta documentada del evento `transform`), recorte con `$toCanvas` a 512 px. Sustituye ~250 líneas propias. La rueda sola sigue desplazando la página (Ctrl/⌘ + rueda amplía), como en el visor.
- En el servidor no cambia nada: GD (con WebP) vuelve a decodificar, recortar y guardar un WebP de 256 px. Las imágenes PHP se compilan con `--with-webp` (`libwebp-dev` de bookworm).

## Política: librería antes que código propio (decisión del usuario, 2026-11-15)
Para cada función nueva se busca primero una librería o herramienta segura, mantenida y usada por la comunidad (criterios de AGENTS.md); el código propio queda para cuando no exista una adecuada. Que el entorno del agente no alcance un registro (npm, Packagist) no es motivo para reimplementar: se pide acceso o se genera el lockfile donde haya red.
