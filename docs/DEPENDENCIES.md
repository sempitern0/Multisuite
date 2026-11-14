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

