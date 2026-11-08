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
