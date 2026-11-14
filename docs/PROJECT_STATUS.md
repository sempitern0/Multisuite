# Estado de Multisuite

Instantánea a 2026-11-14. Historial detallado: git log y PRs #1–#20. Este archivo se **reescribe** (no se acumula) al cerrar cada incremento.

## Integrado en `main` (PRs #1–#20)
| Área | Qué hay | Validación |
| --- | --- | --- |
| Portal | Portada «Servicios» (`DashboardController` + `ServiceTiles`), `/services/{id}` con destinos administrados | CI; QA visual del usuario pendiente |
| Interfaz (D02a) | Listas Livewire (Personas, Invitaciones, Actividad), navegación SPA, avisos y Preferencias | CI; QA del usuario pendiente |
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

## En revisión: revisión del modelo de datos — rama `feat/db-review`
- Esquema analizado (ARCHITECTURE § Modelo de datos): normalizado salvo desnormalizaciones deliberadas y documentadas; sin cambios de estructura.
- Índices nuevos para las claves foráneas que PostgreSQL no indexa solo (`folders.parent_id`, `user_files.folder_id`, `user_files.original_folder_id`, `user_files(version_of_id, superseded_at)`, `invitations.invited_by`, `users.created_by_admin_id`, `storage_reservations.pool_id`).
- Consultas: reservas caducadas de Almacenamiento agregadas en SQL (antes cargaba todos los IDs pendientes en PHP); `portal:uploads-cleanup` filtra con subconsulta (antes una consulta por reserva).
- `QueryPlanTest` en el paso PostgreSQL de CI exige el índice esperado en cada consulta.
- Incluido aparte (petición del usuario): se retira la página de bienvenida; `/` redirige a entrar o, con sesión, a la página de inicio elegida en Preferencias.

## Siguiente acción ejecutable
1. Usuario: probar Personas, Invitaciones, Actividad y Preferencias (D02a, ya en `main`); tras fusionar esta revisión, `make up` aplica los índices.
2. D02b: Mis archivos, Almacenamiento, crear/editar persona y contenido de Perfil/Seguridad.
3. En el servidor Linux: `make nas-discover` y `make nas-apply TARGETS="…"`.

## Límites conocidos
- Producción no desplegada; VPN/TLS, backups y piloto pendientes (P03/P05).
- La biblioteca habitual de Navidrome es común: no hay contenido privado por usuario en el despliegue actual.
- Las cuotas controlan lo que entra por el portal; archivos añadidos por fuera reducen el espacio físico pero no se imputan a nadie.
- Inventario real de discos, capacidad y host del usuario: desconocido; pedirlo al preparar el despliegue.
