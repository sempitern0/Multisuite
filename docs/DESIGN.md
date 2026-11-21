# Diseño de la interfaz (capa de tema)

Todas las pantallas usan el tema Nocturne; de Flux solo queda el campo de código de un solo uso (`flux:otp`). El prototipo visual original quedó en el historial de git (`docs/design/nocturne-v2`, retirado tras la migración).

## Objetivo
El diseño está separado de la lógica para poder sustituirlo sin tocar controladores, rutas, policies ni la estructura de las vistas de dominio.

| Capa | Dónde | Qué contiene |
|---|---|---|
| Lógica | Controladores, `app/Support/*` (`ServiceTiles`, `Navigation`), policies/gates, `User::roleLabel()` | Qué ve cada persona, estados, permisos, textos de rol. Nunca clases CSS. |
| Vistas de dominio | `resources/views/{dashboard,files,users,…}` y `pages/` | Estructura y textos usando **solo** componentes `x-ui::` y `x-layouts::app|auth`: sin clases `ui-*` ni `style`. |
| Tema | `resources/views/themes/{tema}/components/` + `resources/css/themes/{tema}.css` | Aspecto: marcado de cada componente, tokens, clases `ui-*`, layouts, responsive. |

`config/ui.php` (`UI_THEME`, por defecto `nocturne`) registra el namespace `x-ui::` hacia `themes/{tema}/components` (AppServiceProvider). `layouts/app.blade.php` y `layouts/auth.blade.php` delegan en `x-ui::layouts.app` / `x-ui::layouts.auth` y les pasan los datos calculados fuera del tema (`Navigation::for()`, URL de inicio), así que Livewire (layout por defecto `layouts::app`) y Fortify usan el tema sin saberlo.

## Cambiar de tema
1. Copiar `resources/views/themes/nocturne` a `themes/<nuevo>` y `resources/css/themes/nocturne.css` a `<nuevo>.css`.
2. Mantener los **contratos** de la tabla siguiente (nombres de componente y props); el marcado interno es libre.
3. Cambiar el `@import` de `resources/css/app.css` y `UI_THEME=<nuevo>`; `make npm CMD="run build"`.
Las vistas de dominio no cambian. Si un tema necesita un componente nuevo, se añade con el mismo nombre en todos los temas.

## Contratos de componentes (`x-ui::`)
| Componente | Props | Notas |
|---|---|---|
| `layouts.app` | `title`, `navigation` | Dibuja `Navigation::for()` (grupos ya filtrados por gates y bloque de cuenta), barra superior, enlace «Saltar al contenido», toasts Flux. Sin lógica de permisos ni rutas. |
| `layouts.auth` | `title`, `eyebrow`, `heading`, `description`, `home-href`, slot `links` | Panel visual + tarjeta; el slot contiene avisos y formularios con ritmo vertical; `links` va bajo la tarjeta. |
| `button` | `variant` primary/secondary/ghost/danger, `size` sm, `href`, `icon`, `icon-end`, `block`, `type`, `label` | Con `href` se dibuja como enlace. Con `icon` + `label` y sin contenido es un botón de solo icono: `label` es el tooltip y el nombre accesible (un `aria-label` explícito con el nombre del elemento tiene prioridad). Las acciones de fila de las tablas usan iconos. |
| `field` | `name`, `label`, `type`, `value`, `help`, `id` | Restaura `old()` (salvo contraseñas) y muestra el error de validación de `name` junto al control. |
| `password` | `name`, `label`, `help` | Botón mostrar/ocultar; nunca rellena el valor. |
| `checkbox` | `name`, `label`, `checked`, `value` | |
| `select` | `name`, `label`, `options`, `placeholder`, `help` | Lista desplegable con error junto al control. |
| `choice-group` | `name`, `label`, `options`, `help` (+ `wire:model`) | Elección única entre pocas opciones (segmentada). |
| `toolbar` | slot, slot `end` | Filtros sobre una lista (`role="search"`). |
| `table` | `label`, `paginator`, slot `head` | Tabla con pie de resultados y paginación Livewire; en móvil se apila en tarjetas. |
| `toolbar` / `table` | `surface`; `quiet` | `surface`: barra de filtros sobre su propio panel, separada del contenido. `quiet`: las acciones de fila aparecen al pasar el ratón o con el foco (siempre en pantallas táctiles). |
| `td` | `label`, `primary`, `sub`, `align`, `nowrap` | Celda; `label` da el nombre de columna en móvil. `align="end"` es la celda de acciones: sus botones forman una fila alineada y la columna ocupa lo justo. `nowrap` para valores cortos (tamaños, fechas). |
| `table-empty` | `colspan` | Fila de lista vacía. |
| `pagination` | `paginator` | Anterior/Siguiente sin recargar (`WithPagination`). |
| `subnav` | `items`, `label` | Navegación secundaria (ajustes) con `wire:navigate`. |
| `container` | `width` form/text | Limita el ancho de formularios y textos. |
| `sr` | slot | Texto solo para lectores de pantalla. |
| `toasts` | `flash` | Avisos breves; escucha `ui-toast` y muestra el aviso `session('toast')` que llega tras una redirección. |
| `confirm` | `title`, slots `trigger`, `action` | Diálogo de confirmación (`<dialog>` nativo: foco atrapado y Escape). |
| `size-picker` | `id`, `presets`, `units`, `selected`, `extra`, `label`, `submit-label`, `error` | Tamaño por opciones u «otra cantidad»; emite `size-chosen` con `{preset, amount, unit}`. |
| `stats` | `items` (etiqueta => valor) | Pocos datos resumidos. |
| `segment-bar` | `segments`, `label` | Partes de un total con leyenda (portal/other/safety/free). |
| `rows`, `row`, `list` | slot | Panel con filas separadas, línea con dos extremos y lista explicativa. |
| `dialog` | `name`, `title`, `description` | Diálogo modal con contenido propio; se abre y cierra por nombre con los eventos `ui-dialog-open` / `ui-dialog-close` (también desde Livewire) y sobrevive a las actualizaciones. |
| `actions` | slot | Fila final de botones (diálogos, formularios). |
| `separator` | slot | Línea divisoria con texto opcional (sin texto, línea continua). |
| `copy-field` | `value`, `label` | Valor de solo lectura con botón de copiar. |
| `code-list` | `items`, `label` | Lista corta de códigos para copiar a mano. |
| `qr` | `label`, slot (SVG) | Código QR sobre fondo claro para que la cámara lo lea en tema oscuro. |
| `breadcrumbs` | `items` ({label, href}) | Ubicación; el último elemento es la página actual. |
| `uploader` | `store-url`, `upload-url`, `max-bytes`, `folder`, `label`, `hint` | Botón de subir, zona para soltar archivos sobre la página y bandeja flotante de progreso (resumen con barra total, lista desplegable con los errores primero, cierre al terminar). `files.js` clona `<template data-tray-row>` y solo cambia `data-state` (queued, uploading, ok, bad), textos y anchos. |
| `size-picker` (compacto) | `compact`, `visible-label` | Fila de una línea para celdas de tabla y barras de herramientas; las etiquetas quedan solo para lectores de pantalla salvo `visible-label`. |
| `segmented` | `label` | Grupo de botones excluyentes (p. ej. cambiar de vista); cada botón lleva `aria-pressed`. |
| `file-grid` | `view` gallery/list, `label` | Archivos y carpetas en mosaico: galería con miniaturas o lista compacta en columnas. |
| `file-card` | `href`, `name`, `icon`, `meta`, `thumb` image/video, `src`, `spa`, `fresh`; slots `select`, `actions` | Elemento de `file-grid`. La miniatura solo se carga en galería y de forma diferida. |
| `tile-art` | `name` | Fondo vectorial decorativo de una tarjeta de servicio según su icono (music, folder, video, book, image; otro nombre: patrón neutro). Lo dibuja `service-tile`; sin texto ni significado propio. |
| `avatar` | `src`, `initials`, `size` sm/md/lg/xl, `label`, `live` | Foto de la persona o sus iniciales. `live` (la propia persona, p. ej. en el menú) se actualiza con el evento `avatar-changed` sin recargar. |
| `avatar-editor` | `model`, `save`, `src`, `initials`; ranura para acciones extra | Elegir foto y encuadrarla en un círculo con Cropper.js (arrastrar, botones de zoom, Ctrl/⌘ + rueda, pellizco, flechas y + / −). El navegador la recorta a 512 px y la sube a la propiedad `model`; luego llama a `save`. El servidor la vuelve a procesar. |
| `person` | `name`, `sub`, `src`, `initials`, `size` sm/lg | Avatar con nombre y una línea secundaria (listas de personas y cabecera de su ficha). |
| `command-stack` | `selecting` (expresión Alpine) | Coloca la barra de filtros y la de selección en el mismo hueco: al seleccionar se intercambian sin desplazar la lista (nada se mueve bajo el puntero); la barra oculta queda inerte y el conjunto se fija arriba mientras hay selección. |
| `selection-bar` | `show` (expresión Alpine), `label` | Barra contextual con el número de elementos seleccionados y las acciones en bloque; aparece solo con selección y queda fija arriba al desplazarse. |
| `entry` | `href`, `icon`, `spa` | Nombre de archivo o carpeta con icono, como enlace. En tablas se recorta a dos líneas; el nombre completo queda en el tooltip. |
| `uploader` | `store-url`, `upload-url`, `max-bytes`, `folder`, `hint` | Selector de archivos con lista de progreso; lo maneja `resources/js/files.js` (ganchos `data-uploader*`, estado en `data-state`) y emite `files-uploaded`. |
| `preview` | `kind` image/video/pdf/text/rich/none, `src`, `title`, `note` | Superficie de vista previa de solo lectura. `image` es un visor con zoom (Ctrl/⌘ + rueda o pellizco; la rueda sola desplaza la página o mueve la imagen ampliada), `video` y `audio` usan el reproductor del navegador; su comportamiento vive en `resources/js/viewer.js` (`data-viewer`, `data-player`). |
| `prose` | slot | Contenido largo ya convertido a HTML seguro (Markdown saneado). |
| `page-header` (atrás) | `back`, `back-label` | Flecha para volver a la pantalla padre antes del título (Mis archivos, Papelera, Descargas ZIP, detalle de archivo, alta y edición de personas). |
| `form` | `action`, `method`, `inline` | Añade CSRF (y `@method` si no es POST) y el espaciado entre campos. `inline`: un campo y su botón en la misma línea, alineados por abajo. |
| `stack` | `gap` sm/md/lg | Ritmo vertical entre bloques. |
| `text` | `tone` sub/muted, `as` | Texto secundario. |
| `heading` | `level` 2/3 | Títulos de sección bajo el `h1`. |
| `link` | `href` | Acción en texto; sin `href` es un botón. |
| `meter` | `value` 0–100, `label`, slot (pie) | Barra de progreso accesible; ≥ 90 % cambia de tono. |
| `notice` | `tone` ok/warn/bad/info | Resultados confirmados por el servidor o errores. |
| `status` | `tone` ok/warn/bad/muted | Punto + texto corto. |
| `tag` | `tone` grey/red/blue | |
| `panel` | `pad`, `tone` danger/dashed | Superficie; `danger` marca acciones irreversibles. |
| `page-header` | `title`, `eyebrow`, `description`, slot `actions` | Un `h1` por página. |
| `service-grid` | `label` | Rejilla de tarjetas (3 / 2 columnas). |
| `service-tile` | `name`, `icon`, `status` ready/pending/soon/offline, `status-label`, `href`, `note`, `detail` | Sin `href` es informativa (no se puede abrir). El título queda alineado entre tarjetas. |
| `empty-state` | `icon`, `title` | |
| `textarea` | `name`, `label`, `help`, `rows` | Campo de varias líneas con su error. |
| `chat-layout` | slot `aside`, slot | Lista de conversaciones junto a la conversación; se apila en pantallas estrechas. |
| `dock` | `open`, `title`, `toggle`, `close`, slots `tools`, `composer` | Botón flotante abajo a la derecha con panel no modal; Escape cierra. |
| `chat-message` | `role` user/assistant, `author` | Un turno de conversación; el slot ya es HTML seguro (texto escapado o Markdown saneado). |
| `nav-item` | `href`, `icon`, `active` | |
| `icon` | `name` | Iconos SVG propios (sin peticiones externas); ver lista en el componente. |

## Interactividad y movimiento
- Navegación SPA con `wire:navigate` en menú, subnavegación, carpetas y enlaces internos. La subida de archivos funciona por delegación de eventos (`files.js` va en `app.js`). Excepción: la vista previa de un archivo carga completa por su cabecera CSP.
- Los componentes Livewire avisan con `NotifiesInterface::toast()` (evento `ui-toast`) solo tras confirmar el servidor; las preferencias cambian `<html>` al momento con el evento `ui-preferences`.
- Preferencias (`Support/UserPreferences`) aplicadas como `data-motion` (system/on/off), `data-density` (comfortable/compact) y `data-sidebar` (full/compact). «Según el sistema» respeta `prefers-reduced-motion`; «Activadas» permite animaciones aunque el sistema pida reducirlas; «Desactivadas» las quita siempre.
- Movimiento con intención: una entrada de página por navegación, aparición de avisos, indicador en el botón que espera respuesta (`data-loading`) y atenuado de la tabla mientras filtra. Nada de animaciones decorativas en cada tarjeta.

## Reglas
- Vistas nuevas o modificadas: solo componentes `x-ui::`; sin clases `ui-*`, `style`, colores, sombras ni radios. Si falta algo, se añade un componente al tema (en todos los temas) en vez de estilar la vista.
- Las pruebas comprueban `data-layout="app|auth"`, no clases del tema.
- No quedan vistas Flux. `nocturne.css` conserva la reasignación de `zinc`, `teal` y el acento solo para `flux:otp`.
- Nombres de servicio de una palabra (Música, Archivos…); no mostrar nombres técnicos (Navidrome) en la portada.
- Ocultar un enlace no es autorización: cada ruta mantiene middleware/policies. Los miembros no ven estadísticas globales.
- Avisos de éxito solo tras confirmación del servidor; sin barras de vista previa en la app.
- Tema solo oscuro: no hay selector claro/oscuro (`@fluxAppearance` retirado y página Apariencia fuera del menú).
- Contraste AA comprobado (texto ≥ 4,5:1, contornos de controles ≥ 3:1). Desviaciones mínimas respecto a la guía para cumplirlo: texto atenuado `#818e81` (guía `#778477`), placeholder `#758575` y borde de campos `#5a675b`.
- Accesibilidad: foco visible amarillo, `aria-current` en navegación, errores con `role="alert"` y `aria-describedby`, `prefers-reduced-motion`, responsive (≤1050 px barra de iconos, ≤700 px menú desplegable, rejilla de 2 columnas).

