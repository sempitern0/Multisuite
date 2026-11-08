# Estado de Multisuite
2026-11-08. Decisión vigente: portal Laravel/Livewire de enlaces a servicios existentes. Sustituye Hxx; ver [ROADMAP.md](ROADMAP.md).

| Hito | Estado | Evidencia |
| --- | --- | --- |
| P00 | Completado | private-nas revisado; cambio de alcance documentado |
| P01 | En curso | Compose autónomo, ejemplo env y guía añadidos; runtime Docker pendiente |
| P02 | En curso | Starter existente; portada y rutas implementadas; pruebas runtime/QA pendientes |
| P03 | Pendiente | Proxy/VPN y acceso desde segundo dispositivo |
| P04 | Pendiente | Selección de servicios adicionales |
| P05 | Pendiente | Producción/restore/piloto |

## Rescate realizado
Origen: private-nas rama feat/music-library-player, a97d48ee8872dfcd36ada9b6be364428bc4a90ff.
Rescatado patrón Docker Navidrome, música read-only, persistencia y loopback; separados del storage Laravel.
No portados: catálogo, parsers, sincronización, Svelte player ni agente de host. private-nas no modificado.
Imagen elegida navidrome/navidrome:0.64.2 según release oficial; pull/arranque pendientes.

## Próxima acción
P01-03: en equipo con Docker, copiar music.env.example a music.env, crear directorios y ajustar UID/GID; ejecutar comandos de docs/NAVIDROME.md. Completar reproducción/persistencia/restore antes de marcar P01 terminado.
El starter ya está versionado; no repetir bootstrap. Continuar comprobaciones P02-06 y flujo de invitaciones P02-05.

## Validación y límites
Validación estática YAML y documentación. No Docker disponible en entorno del agente: no se ha arrancado ni probado audio, permisos o Compose con su CLI. No hay despliegue ni migración de datos.
Presupuesto, dispositivos y host pendientes; no bloquean preparar portal y prueba local.

## Relevo por iteración
Registrar fecha/agente, rama/commit, IDs de tareas, archivos cambiados, comandos y resultados, pruebas no ejecutadas, bloqueos, cambios sin commit y siguiente paso. Nunca guardar secretos. Completado requiere evidencia de aceptación.

## Iteración de portada
Starter encontrado en main d60f641. Añadidos portal.php, portada y redirección autenticada con URL controlada. Activar PORTAL_MUSIC_ENABLED=true en app/.env; limpiar config. Tests de acceso añadidos, pendientes de ejecución: entorno sin PHP/Docker. Continuar P02-05 (invitaciones/registro) y P02-06 (QA y tests). No repetir bootstrap.
