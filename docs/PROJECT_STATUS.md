# Estado de Multisuite

Instantánea a 2026-11-14. Historial detallado: git log y PRs #1–#19. Este archivo se **reescribe** (no se acumula) al cerrar cada incremento.

## Integrado en `main` (PRs #1–#19)
| Área | Qué hay | Validación |
| --- | --- | --- |
| Portal | Portada «Servicios» (`DashboardController` + `ServiceTiles`), `/services/{id}` con destinos administrados | CI; QA visual del usuario pendiente |
| Diseño (D01) | Capa de tema Nocturne sustituible: `x-ui::` + `themes/nocturne` + `nocturne.css`; navegación calculada en `Support/Navigation`; acceso, 2FA, contraseñas, invitación y portada migrados; contraste AA y foco comprobados | CI (`DesignSystemTest`) + capturas estáticas 1440/768/390 px; pendiente revisión del usuario en la app real |
| Acceso | Invitaciones firmadas, roles owner/admin/member, alta manual, username o correo, 2FA/passkeys | CI + usuario |
| Cuentas de servicio | Aprovisionamiento Navidrome 0.64.2 con reintentos cifrados efímeros | CI con Navidrome real |
| Sesión de Música | Gateway Nginx + pase cifrado de 60 s; no es SSO | CI; QA navegador pendiente |
| Administración | CRUD de usuarios, auditoría administrativa (incluye `storage.*`) | CI |
| Música | `compose.music.yaml` autónomo, ciclo integrado con `make up` | Usuario |
| Aislamiento | Laboratorio S01 (30 comprobaciones reales) | CI *Library isolation* |
| Almacenamiento (S02) | Inventario, reservas/cuotas transaccionales, página **Almacenamiento** en `/admin/storage` y `make nas-discover`/`nas-apply` | CI + usuario (GUI); scripts de discos pendientes en servidor real |
| Mis archivos (S03.1–S03.4) | Carpetas con elementos y tamaño exacto, subida, descarga, vista previa de solo lectura, papelera y versiones, descargas ZIP (selección, carpeta, todo) con reserva temporal y `portal:exports-run`; limitadores de peticiones con nombre por área | CI (`PersonalFilesTest`, `FileExportsTest`); QA del usuario pendiente |
| Entorno de desarrollo | `make up` prepara NAS de desarrollo y ejecuta `portal:dev-bootstrap` | CI + arranque real del usuario |

Decisiones vigentes: Mis archivos es del portal (excepción acotada en AGENTS.md); entorno local desechable mientras no haya producción; el diseño va por delante para que lo nuevo nazca con el tema; sin librerías de tablas por ahora (D010 en DEPENDENCIES.md).

## En revisión: D02a interfaz interactiva — rama `feat/d02-interactive-ui`
- Personas, Invitaciones y Actividad son páginas Livewire con tabla, búsqueda/filtros en vivo (estado en la URL) y paginación sin recargar; invitar y revocar ocurren en el sitio con aviso. Autorización en `boot()` en cada petición; acciones con límite propio porque `/livewire/update` no pasa por el throttle de la ruta. `IssueInvitation`/`RevokeInvitation` compartidas con el controlador.
- Navegación SPA (`wire:navigate`), avisos del tema (`ui-toast`) y entrada de página animada; indicador en botones que esperan y atenuado de tablas al filtrar.
- Ajustes con subnavegación y nueva página **Preferencias** (guardado al cambiar): animaciones (sistema/activadas/desactivadas, también por encima de `prefers-reduced-motion`), densidad, menú lateral, página de inicio tras entrar y elementos por página. Columna `users.preferences` (migración nueva).
- Fuente Instrument Sans retirada (sin uso). Sin dependencias nuevas.
- Tests: `AdminListsTest`, `PreferencesTest` y ajustes en auditoría y verificación de correo. Capturas estáticas de tabla, móvil, preferencias y modo compacto revisadas.

## Siguiente acción ejecutable
1. Usuario: revisar la PR D02a; tras fusionar, `make up` (migra) + `make npm CMD="run build"` y probar Personas, Invitaciones, Actividad y Preferencias.
2. D02b: Mis archivos, Almacenamiento, crear/editar persona y contenido de Perfil/Seguridad.
3. En el servidor Linux: `make nas-discover` y `make nas-apply TARGETS="…"`.

## Límites conocidos
- Producción no desplegada; VPN/TLS, backups y piloto pendientes (P03/P05).
- La biblioteca habitual de Navidrome es común: no hay contenido privado por usuario en el despliegue actual.
- Las cuotas controlan lo que entra por el portal; archivos añadidos por fuera reducen el espacio físico pero no se imputan a nadie.
- Inventario real de discos, capacidad y host del usuario: desconocido; pedirlo al preparar el despliegue.
