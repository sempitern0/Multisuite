# Rescate de private-nas y cambio de arquitectura
Revisión: 2026-11-08. Multisuite pasa a ser portal de acceso a servicios existentes; cada servicio mantiene su interfaz, cuentas y datos.

## Evidencia inspeccionada
- private-nas/main: f4a7c533178e7a03991a1f2937910b6038a6b18b. Registro nas.modules y ModuleRegistry; todos los módulos marcados planned/disabled. No tiene Navidrome en su Compose principal.
- Rama feat/music-library-player: a97d48ee8872dfcd36ada9b6be364428bc4a90ff. compose.music.yaml y compose.music.production.yaml con deluan/navidrome:0.55.2, puerto loopback y datos propios.
- docs/NAVIDROME.md declara Navidrome opcional; NavidromeCatalog consulta carátulas y canciones para complementar catálogo Laravel.
- docs/MUSIC.md y árbol app/ muestran reproductor Svelte, modelos, playlists, favoritos, escáner, parsers de etiquetas y sincronización propios.
- compose.agent.yaml conecta un agente de host mediante socket. No es necesario para un índice de servicios.
Esto es revisión estática de archivos, no una auditoría completa ni certificación de funcionamiento.

## Decisiones
| Elemento | Decisión | Motivo |
| --- | --- | --- |
| Compose Navidrome | Adaptar | Recupera prueba local sin reconstruir música |
| Música read-only y data persistente | Conservar | Separa originales de DB/cache |
| Perfil/servicio opcional | Mantener independencia mediante stack autónomo | Puede ejecutarse sin app Laravel ni DB |
| Ruta dentro de app/storage | Sustituir por carpeta configurable | La música no pertenece al portal |
| Imagen 0.55.2 | No copiar como default nuevo | Elegir release estable actual, probar y fijar |
| Registro de módulos | Reutilizar la idea, no copiar Svelte | Catálogo de enlaces controlado por administrador |
| Catálogo/streaming/playlists propios | No portar | Navidrome ya es responsable |
| Parsers y sincronización de etiquetas | No portar | Evitar dos fuentes de verdad |
| Agente Go y control de host | No portar | Excede el alcance del portal |
| Notas/lectores/descargador propios del plan anterior | Retirar del MVP | Elegir servicios completos con UI |

## Alcance propio permitido
Página principal Laravel/Livewire, tarjetas por categoría, orden/favoritos de accesos opcionales, URLs por entorno y estados desactivado/desconocido/disponible solo con evidencia. Autenticación del portal si se requiere.
No copiar cuentas de servicios, almacenar contraseñas administrativas ni crear catálogo multimedia transversal. Las cuentas se gestionan dentro de cada servicio.
Healthchecks futuros solo sobre destinos configurados por operador, con timeouts/caché; nunca bloquear toda la portada si un servicio falla.
Un enlace es distinto de una subruta proxy y de SSO. Elegir explícitamente uno por servicio.

## Ruta de continuidad
Ver ROADMAP.md vigente (hitos Pxx). Los Hxx anteriores quedan sustituidos; su contenido permanece en historial Git, commit 614ab0f7cf77255d93e41305cf040b031a86af6b. No retomarlos automáticamente.
private-nas no se modifica. No se copian medios, secretos ni bases de datos.
