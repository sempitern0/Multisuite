# Estado de Multisuite

Instantánea a 2026-11-14. Historial detallado: git log y PRs #1–#21. Este archivo se **reescribe** (no se acumula) al cerrar cada incremento.

## Integrado en `main` (PRs #1–#21)
| Área | Qué hay | Validación |
| --- | --- | --- |
| Portal | Portada «Servicios» (`DashboardController` + `ServiceTiles`), `/services/{id}` con destinos administrados | CI; QA visual del usuario pendiente |
| Modelo de datos | Índices de claves foráneas, consultas agregadas y `QueryPlanTest` en PostgreSQL; `/` redirige a entrar o a la página de inicio | CI |
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

## En revisión: D02b.1 administración completa en el tema — rama `feat/d02b-admin-forms`
- Crear, editar y eliminar personas y **Almacenamiento** son páginas Livewire con componentes del tema: se guarda en el sitio con aviso, errores junto al control y sin recargas.
- Operaciones extraídas a Actions (`Actions/Users/*`, `Actions/Storage/UpdateStorageLimits`) con una sola entrada, su página: se retiran `UserManagementController`, `ManualUserController`, `StorageController` y las rutas de formulario de invitaciones, que ya no usaba ninguna pantalla; `Support/LockedUsers` unifica el bloqueo ordenado y `Support/SizeInput` la lectura de tamaños.
- Tema: diálogo de confirmación (`x-ui::confirm`, sustituye a `wire:confirm`), selector de tamaño, barra por segmentos, datos resumidos y aviso tras redirección (`session('toast')`).
- `User::assignableRoles()` y `UserStorageQuota::summaryFor()` sustituyen reglas repetidas en varias pantallas.
- Tests: los casos anteriores de personas, invitaciones y almacenamiento se ejecutan ahora contra las páginas (`tests/Concerns/UsesAdminPages`), más `AdminFormsTest`. Sin dependencias ni migraciones nuevas.

## Siguiente acción ejecutable
1. Usuario: revisar la PR D02b.1; tras fusionar, `make up` + `make npm CMD="run build"` y probar Personas (crear/editar/eliminar) y Almacenamiento.
2. D02b.2: Mis archivos y contenido de Perfil/Seguridad.
3. En el servidor Linux: `make nas-discover` y `make nas-apply TARGETS="…"`.

## Límites conocidos
- Producción no desplegada; VPN/TLS, backups y piloto pendientes (P03/P05).
- La biblioteca habitual de Navidrome es común: no hay contenido privado por usuario en el despliegue actual.
- Las cuotas controlan lo que entra por el portal; archivos añadidos por fuera reducen el espacio físico pero no se imputan a nadie.
- Inventario real de discos, capacidad y host del usuario: desconocido; pedirlo al preparar el despliegue.
