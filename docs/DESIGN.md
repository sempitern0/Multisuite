# Diseño de la interfaz (capa de tema)

Estado: **D01 implementado** (fundación, shell, acceso y portada de servicios). Resto de pantallas en D02.
Referencia visual: [design/nocturne-v2/GUIA.md](design/nocturne-v2/GUIA.md) y el prototipo estático `design/nocturne-v2/prototipo-28-pantallas.html` (abrir en el navegador; no forma parte de la app).

## Objetivo
El diseño está separado de la lógica para poder sustituirlo sin tocar controladores, rutas, policies ni la estructura de las vistas de dominio.

| Capa | Dónde | Qué contiene |
|---|---|---|
| Lógica | Controladores, `app/Support/*` (p. ej. `ServiceTiles`), policies/gates | Qué ve cada persona, estados, permisos. Nunca clases CSS. |
| Vistas de dominio | `resources/views/{dashboard,files,users,…}` y `pages/` | Estructura y textos usando **solo** componentes `x-ui::` y `x-layouts::app|auth`. |
| Tema | `resources/views/themes/{tema}/components/` + `resources/css/themes/{tema}.css` | Aspecto: marcado de cada componente, tokens, clases `ui-*`, layouts, responsive. |

`config/ui.php` (`UI_THEME`, por defecto `nocturne`) registra el namespace `x-ui::` hacia `themes/{tema}/components` (AppServiceProvider). `layouts/app.blade.php` y `layouts/auth.blade.php` solo delegan en `x-ui::layouts.app` / `x-ui::layouts.auth`, así que Livewire (layout por defecto `layouts::app`) y Fortify usan el tema sin saberlo.

## Cambiar de tema
1. Copiar `resources/views/themes/nocturne` a `themes/<nuevo>` y `resources/css/themes/nocturne.css` a `<nuevo>.css`.
2. Mantener los **contratos** de la tabla siguiente (nombres de componente y props); el marcado interno es libre.
3. Cambiar el `@import` de `resources/css/app.css` y `UI_THEME=<nuevo>`; `make npm CMD="run build"`.
Las vistas de dominio no cambian. Si un tema necesita un componente nuevo, se añade con el mismo nombre en todos los temas.

## Contratos de componentes (`x-ui::`)
| Componente | Props | Notas |
|---|---|---|
| `layouts.app` | `title` | Shell: barra lateral (Servicios, Mis archivos; grupo Administración según gates; cuenta abajo), barra superior, toasts Flux. |
| `layouts.auth` | `title`, `eyebrow`, `heading`, `description` | Panel visual + tarjeta; el slot contiene el formulario y avisos. |
| `button` | `variant` primary/secondary/ghost/danger, `size` sm, `href`, `icon`, `icon-end`, `block`, `type` | Con `href` se dibuja como enlace. |
| `field` | `name`, `label`, `type`, `value`, `help`, `id` | Restaura `old()` (salvo contraseñas) y muestra el error de validación de `name` junto al control. |
| `password` | `name`, `label`, `help` | Botón mostrar/ocultar; nunca rellena el valor. |
| `checkbox` | `name`, `label`, `checked`, `value` | |
| `notice` | `tone` ok/warn/bad/info | Resultados confirmados por el servidor o errores. |
| `status` | `tone` ok/warn/bad/muted | Punto + texto corto. |
| `tag` | `tone` grey/red/blue | |
| `panel` | `pad` | Superficie. |
| `page-header` | `title`, `eyebrow`, `description`, slot `actions` | Un `h1` por página. |
| `service-tile` | `name`, `icon`, `status` ready/pending/soon/offline, `status-label`, `href` | Sin `href` es informativa (no se puede abrir). |
| `empty-state` | `icon`, `title` | |
| `nav-item` | `href`, `icon`, `active` | |
| `icon` | `name` | Iconos SVG propios (sin peticiones externas); ver lista en el componente. |

## Reglas
- Vistas nuevas o modificadas: componentes `x-ui::` y tokens; sin colores, sombras ni radios escritos a mano. Estilos de ajuste puntual (márgenes) permitidos hasta que exista utilidad del tema.
- Las vistas aún no migradas (Flux + Tailwind) heredan la paleta del tema porque `nocturne.css` reasigna `zinc`, `teal` y el acento de Flux; se migran en D02.
- Nombres de servicio de una palabra (Música, Archivos…); no mostrar nombres técnicos (Navidrome) en la portada.
- Ocultar un enlace no es autorización: cada ruta mantiene middleware/policies. Los miembros no ven estadísticas globales.
- Avisos de éxito solo tras confirmación del servidor; sin barras de vista previa en la app.
- Tema solo oscuro: no hay selector claro/oscuro (`@fluxAppearance` retirado y página Apariencia fuera del menú).
- Accesibilidad: foco visible amarillo, `aria-current` en navegación, errores con `role="alert"` y `aria-describedby`, `prefers-reduced-motion`, responsive (≤1050 px barra de iconos, ≤700 px menú desplegable, rejilla de 2 columnas).

## Estado por pantalla
| Pantalla | Estado |
|---|---|
| Acceso, recuperar/guardar contraseña, confirmar, 2FA, verificar correo, invitación, contraseña inicial, bienvenida | Migradas (D01) |
| Shell y portada de servicios | Migradas (D01) |
| Mis archivos, papelera, exportaciones, personas, invitaciones, almacenamiento, actividad, ajustes | Pendiente (D02); ya usan la paleta Nocturne |
| Resumen de administración con métricas reales | Pendiente (D02, solo admin) |
