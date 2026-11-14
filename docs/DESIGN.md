# Diseño de la interfaz (capa de tema)

Estado: **D01 y D02a implementados** (fundación, shell, acceso, portada, listas de administración, ajustes con preferencias). Mis archivos, Almacenamiento y edición de personas en D02b.
Referencia visual: [design/nocturne-v2/GUIA.md](design/nocturne-v2/GUIA.md) y el prototipo estático `design/nocturne-v2/prototipo-28-pantallas.html` (abrir en el navegador; no forma parte de la app).

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
| `button` | `variant` primary/secondary/ghost/danger, `size` sm, `href`, `icon`, `icon-end`, `block`, `type` | Con `href` se dibuja como enlace. |
| `field` | `name`, `label`, `type`, `value`, `help`, `id` | Restaura `old()` (salvo contraseñas) y muestra el error de validación de `name` junto al control. |
| `password` | `name`, `label`, `help` | Botón mostrar/ocultar; nunca rellena el valor. |
| `checkbox` | `name`, `label`, `checked`, `value` | |
| `select` | `name`, `label`, `options`, `placeholder`, `help` | Lista desplegable con error junto al control. |
| `choice-group` | `name`, `label`, `options`, `help` (+ `wire:model`) | Elección única entre pocas opciones (segmentada). |
| `toolbar` | slot, slot `end` | Filtros sobre una lista (`role="search"`). |
| `table` | `label`, `paginator`, slot `head` | Tabla con pie de resultados y paginación Livewire; en móvil se apila en tarjetas. |
| `td` | `label`, `primary`, `sub`, `align` | Celda; `label` da el nombre de columna en móvil. |
| `table-empty` | `colspan` | Fila de lista vacía. |
| `pagination` | `paginator` | Anterior/Siguiente sin recargar (`WithPagination`). |
| `subnav` | `items`, `label` | Navegación secundaria (ajustes) con `wire:navigate`. |
| `container` | `width` form/text | Limita el ancho de formularios y textos. |
| `sr` | slot | Texto solo para lectores de pantalla. |
| `toasts` | — | Avisos breves; escucha el evento `ui-toast`. |
| `form` | `action`, `method` | Añade CSRF (y `@method` si no es POST) y el espaciado entre campos. |
| `stack` | `gap` sm/md/lg | Ritmo vertical entre bloques. |
| `text` | `tone` sub/muted, `as` | Texto secundario. |
| `heading` | `level` 2/3 | Títulos de sección bajo el `h1`. |
| `link` | `href` | Acción en texto; sin `href` es un botón. |
| `meter` | `value` 0–100, `label`, slot (pie) | Barra de progreso accesible; ≥ 90 % cambia de tono. |
| `notice` | `tone` ok/warn/bad/info | Resultados confirmados por el servidor o errores. |
| `status` | `tone` ok/warn/bad/muted | Punto + texto corto. |
| `tag` | `tone` grey/red/blue | |
| `panel` | `pad` | Superficie. |
| `page-header` | `title`, `eyebrow`, `description`, slot `actions` | Un `h1` por página. |
| `service-grid` | `label` | Rejilla de tarjetas (3 / 2 columnas). |
| `service-tile` | `name`, `icon`, `status` ready/pending/soon/offline, `status-label`, `href`, `note`, `detail` | Sin `href` es informativa (no se puede abrir). El título queda alineado entre tarjetas. |
| `empty-state` | `icon`, `title` | |
| `nav-item` | `href`, `icon`, `active` | |
| `icon` | `name` | Iconos SVG propios (sin peticiones externas); ver lista en el componente. |

## Interactividad y movimiento
- Navegación SPA con `wire:navigate` en menú, subnavegación y enlaces internos; Mis archivos carga normal porque tiene scripts de subida propios.
- Los componentes Livewire avisan con `NotifiesInterface::toast()` (evento `ui-toast`) solo tras confirmar el servidor; las preferencias cambian `<html>` al momento con el evento `ui-preferences`.
- Preferencias (`Support/UserPreferences`) aplicadas como `data-motion` (system/on/off), `data-density` (comfortable/compact) y `data-sidebar` (full/compact). «Según el sistema» respeta `prefers-reduced-motion`; «Activadas» permite animaciones aunque el sistema pida reducirlas; «Desactivadas» las quita siempre.
- Movimiento con intención: una entrada de página por navegación, aparición de avisos, indicador en el botón que espera respuesta (`data-loading`) y atenuado de la tabla mientras filtra. Nada de animaciones decorativas en cada tarjeta.

## Reglas
- Vistas nuevas o modificadas: solo componentes `x-ui::`; sin clases `ui-*`, `style`, colores, sombras ni radios. Si falta algo, se añade un componente al tema (en todos los temas) en vez de estilar la vista.
- Las pruebas comprueban `data-layout="app|auth"`, no clases del tema.
- Las vistas aún no migradas (Flux + Tailwind) heredan la paleta del tema porque `nocturne.css` reasigna `zinc`, `teal` y el acento de Flux; se migran en D02.
- Nombres de servicio de una palabra (Música, Archivos…); no mostrar nombres técnicos (Navidrome) en la portada.
- Ocultar un enlace no es autorización: cada ruta mantiene middleware/policies. Los miembros no ven estadísticas globales.
- Avisos de éxito solo tras confirmación del servidor; sin barras de vista previa en la app.
- Tema solo oscuro: no hay selector claro/oscuro (`@fluxAppearance` retirado y página Apariencia fuera del menú).
- Contraste AA comprobado (texto ≥ 4,5:1, contornos de controles ≥ 3:1). Desviaciones mínimas respecto a la guía para cumplirlo: texto atenuado `#818e81` (guía `#778477`), placeholder `#758575` y borde de campos `#5a675b`.
- Accesibilidad: foco visible amarillo, `aria-current` en navegación, errores con `role="alert"` y `aria-describedby`, `prefers-reduced-motion`, responsive (≤1050 px barra de iconos, ≤700 px menú desplegable, rejilla de 2 columnas).

## Estado por pantalla
| Pantalla | Estado |
|---|---|
| Acceso, recuperar/guardar contraseña, confirmar, 2FA, verificar correo, invitación, contraseña inicial | Migradas (D01). Sin página de bienvenida: `/` lleva a entrar o a la página de inicio elegida |
| Shell y portada de servicios | Migradas (D01) |
| Mis archivos, papelera, exportaciones, personas, invitaciones, almacenamiento, actividad, ajustes | Pendiente (D02); ya usan la paleta Nocturne |
| Resumen de administración con métricas reales | Pendiente (D02, solo admin) |
