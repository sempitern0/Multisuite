# Arquitectura y mapa del código

Guía rápida para personas y agentes de IA. Si algo aquí contradice al código, manda el código: corrige este archivo en la misma PR.

## Vista general
```
Navegador ──► Caddy/Nginx ──► Laravel 13 + Livewire 4 + Flux 2 (app/, PHP ≥ 8.3)
                                   │  DB: SQLite (dev/tests) · PostgreSQL (concurrencia real)
                                   │  scheduler: portal:sync-accounts (1 min), portal:exports-run (1 min), portal:uploads-cleanup (10 min), portal:files-purge (diario)
                                   ├──► API nativa de Navidrome (aprovisionamiento de cuentas)
                                   └──► Carpetas NAS montadas en el contenedor (solo las declaradas)
Navegador ──► music-gateway (Nginx, mismo origen) ──► Navidrome 0.64.2 (compose.music.yaml, autónomo)
```
El portal **no** controla Docker, no monta discos ni hace de proxy PHP. Cada servicio mantiene su propia autenticación.

## Raíz del repositorio
| Ruta | Contenido |
| --- | --- |
| `app/` | Aplicación Laravel (se versiona entera con lockfiles) |
| `compose.yaml`, `compose.production.yaml` | Stack del portal heredado de Laravel Foundry |
| `compose.music.yaml`, `music.env.example` | Navidrome independiente |
| `deploy/*.yaml` | Overlays que aplica Make: cuentas/red compartida con Música y NAS de desarrollo (`compose.nas-dev.yaml`). En el servidor, `compose.nas.yaml` (local, generado por `make nas-apply`) lo sustituye |
| `docker/` | Imágenes PHP/Nginx, Caddy, gateway musical (`docker/music-proxy`) |
| `scripts/` | Setup/preflight/backup en Bash y PowerShell (paridad obligatoria) |
| `tests/` | Pruebas de infraestructura (Python/Bash/PowerShell/Node) y laboratorio `tests/isolation/` |
| `docs/` | Documentación del producto; `docs/foundry/` = guías heredadas de la plantilla |
| `Makefile` | Punto de entrada: `make up`, `make test`, `make artisan CMD=…`, `make npm CMD=…`, `music-*` |

## Aplicación (`app/`)
| Dominio | Código clave | Doc |
| --- | --- | --- |
| Portada y servicios | `config/portal.php`, `DashboardController` + `Support/ServiceTiles` (qué ve cada persona), `ServiceController`, `resources/views/dashboard.blade.php` | NAVIDROME.md |
| Listas administrativas | Páginas Livewire `resources/views/pages/admin/{users,invitations,activity}` (autorizan en `boot()`, en cada petición), `Actions/Invitations/{IssueInvitation,RevokeInvitation}` compartidas con `InvitationController`; estado de invitación en `Invitation::status()`/`scopeInStatus()`; búsqueda en `User::scopeSearch()`; límite en `Livewire/Concerns/ThrottlesActions` | USER_MANAGEMENT.md, INVITATIONS.md |
| Preferencias | `Support/UserPreferences` (columna `users.preferences`), página `pages::settings.preferences`, `Http/Responses/LoginResponse` (página de inicio) | DESIGN.md |
| Diseño (tema) | `config/ui.php`, `resources/views/themes/nocturne/components` (`x-ui::`, layouts), `resources/css/themes/nocturne.css`, delegadores `resources/views/layouts/{app,auth}.blade.php` | DESIGN.md |
| Roles y permisos | `User::canInviteRole`, `Policies/UserPolicy`, gates en `Providers/AppServiceProvider` | USER_MANAGEMENT.md |
| Invitaciones | `InvitationController`, `Models/Invitation`, `Notifications/PortalInvitation` | INVITATIONS.md |
| Alta manual y primer acceso | `ManualUserController`, `InitialPasswordController`, `Middleware/EnsureInitialPasswordChanged` | USER_MANAGEMENT.md |
| Cuentas de servicio | `Services/ServiceAccounts`, `Services/NavidromeAccounts`, `config/provisioning.php`, `portal:sync-accounts`, `portal:accounts-status` | SERVICE_ACCOUNTS.md |
| Sesión musical | `MusicSessionController`, `Services/MusicSessionBridge`, `docker/music-proxy/` | MUSIC_SESSION_BRIDGE.md |
| Auditoría | `Services/AdminAudit`, `Enums/ServiceAuditAction`, página Livewire `pages::admin.activity` | ADMIN_AUDIT.md |
| Mis archivos | `Services/Files/PersonalFiles` (carpetas, papelera, versiones, retención), `Services/Files/FileExports` + `FileExportController` + `portal:exports-run` (ZIP), `Events/PrivateUploadReady` (listener en `AppServiceProvider`), `portal:files-purge`, `FileController`, `Models/{Folder,UserFile}`, `Policies/{FolderPolicy,UserFilePolicy}`, `resources/js/files.js`, `resources/views/files/` | STORAGE.md §5b |
| Almacenamiento | `Services/Storage/{VolumeProbe,StorageInventory,StorageReservations,StorageOverview,PrivateUploads}`, `StorageController`, `PrivateUploadController`, `Support/Bytes`, `config/nas.php`, `portal:storage-status`, `portal:storage-quota`, `portal:uploads-cleanup`, `portal:dev-bootstrap`, `Services/Storage/HardwareSnapshot`, `scripts/nas-discover.*`, `scripts/nas-apply.*` | STORAGE.md |

Gates: `manage-invitations`, `view-admin-audit`, `view-storage` (owner/admin); `manage-storage-pools` (owner); `manage-storage-quota` (owner → todos; admin → miembros).

Tablas propias: `invitations`, `service_accounts`, `admin_audit_events`, `storage_pools`, `user_storage_quotas`, `storage_reservations`, `private_uploads`, `storage_settings`, `folders`, `user_files`, `file_exports`.

## Invariantes que no se deben romper
- Las acciones Livewire que modifican datos llaman a `ThrottlesActions::throttle()` con el limitador con nombre de su ruta: `/livewire/update` no pasa por `throttle:` y así ambos caminos comparten contador y definición.
- Los componentes Livewire de un solo archivo (`resources/views/pages`) no los analiza PHPStan: solo orquestan. Consultas en scopes de modelo (`User::search`, `Invitation::inStatus`) y mutaciones en Actions.
- Autorización en servidor en cada lectura/escritura; revalidar roles **bajo bloqueo** en operaciones administrativas (patrón `lockedUsers`).
- Orden de bloqueo del almacenamiento: usuario → grupo → cuota → reserva.
- Nunca aceptar rutas, URLs de redirección ni IDs de servicio no registrados desde la petición. Nombres de archivo/carpeta son etiquetas, nunca rutas.
- Ninguna ruta de Laravel bajo `/storage/`: Nginx la sirve como disco público.
- Límites de peticiones con nombre (`RateLimiter::for` en `AppServiceProvider`: files, uploads, file-exports, admin…). Nunca `throttle:N,1` sin nombre en rutas web: comparten un contador por usuario entre todas las rutas y las anidadas cuentan doble.
- Auditoría con lista blanca de campos: sin payloads, rutas, contraseñas ni respuestas remotas.
- Migraciones solo incrementales; no editar las ya aplicadas.
- Fallar cerrado: capacidad desconocida, marca ausente o conflicto bloquean escrituras.

## Pruebas
- `make test` (PHPUnit; `CMD="--filter=…"`), `make lint`/Pint, PHPStan nivel 7 (`app/phpstan.neon`), build con `make npm CMD="run build"`.
- CI: `.github/workflows/application.yml` (app, Navidrome real, PostgreSQL), `ci.yml` (infraestructura Linux/Windows), `library-isolation.yml` (S01).
- El entorno de un agente puede no tener PHP/Docker: indicarlo y delegar la ejecución en CI, sin inventar resultados.
