# Arquitectura y mapa del código

Guía rápida para personas y agentes de IA. Si algo aquí contradice al código, manda el código: corrige este archivo en la misma PR.

## Vista general
```
Navegador ──► Caddy/Nginx ──► Laravel 13 + Livewire 4 (app/, PHP ≥ 8.3)
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
| `compose.reading.yaml`, `reading.env.example` | Kavita (Lectura) independiente |
| `deploy/*.yaml` | Overlays que aplica Make: cuentas/red compartida con Música y Lectura (`compose.*-accounts`, `compose.portal-reading`) y NAS de desarrollo (`compose.nas-dev.yaml`). En el servidor, `compose.nas.yaml` (local, generado por `make nas-apply`) lo sustituye |
| `docker/` | Imágenes PHP/Nginx, Caddy, gateway musical (`docker/music-proxy`) |
| `scripts/` | Setup/preflight/backup en Bash y PowerShell (paridad obligatoria); `nas-backup.sh` (restic, solo servidor Linux) con `backup.env` |
| `tests/` | Pruebas de infraestructura (Python/Bash/PowerShell/Node) y laboratorio `tests/isolation/` |
| `docs/` | Documentación del producto; `docs/foundry/` = guías heredadas de la plantilla |
| `Makefile` | Punto de entrada: `make up`, `make test`, `make artisan CMD=…`, `make npm CMD=…`, `music-*`, `reading-*` |

## Aplicación (`app/`)
| Dominio | Código clave | Doc |
| --- | --- | --- |
| Portada y servicios | `config/portal.php`, `DashboardController` + `Support/ServiceTiles` (qué ve cada persona), `ServiceController`, `resources/views/dashboard.blade.php` | NAVIDROME.md |
| Administración (páginas) | Livewire en `resources/views/pages/admin/{users,user-create,user-edit,invitations,activity,storage}`: única entrada a estas operaciones; autorizan en cada petición y delegan en Actions (`Actions/Users/{CreateUser,UpdateUser,ChangeUsername,SetServiceAccess,SetSuspension,DeleteUser,SetUsername,UpdateProfile,UpdateAvatar,RemoveAvatar}`, `Actions/Invitations/*`, `Actions/Storage/UpdateStorageLimits`). Apoyos: `Support/LockedUsers` (bloqueo ordenado actor/objetivo), `Support/SizeInput`, `User::assignableRoles()`, `Invitation::status()`/`scopeInStatus()`, `User::scopeSearch()`, `UserStorageQuota::summaryFor()`, `Livewire/Concerns/ThrottlesActions`. Pruebas con `tests/Concerns/UsesAdminPages` | USER_MANAGEMENT.md, INVITATIONS.md, STORAGE.md |
| Preferencias | `Support/UserPreferences` (columna `users.preferences`), página `pages::settings.preferences`, `Http/Responses/LoginResponse` (página de inicio) | DESIGN.md |
| Diseño (tema) | `config/ui.php`, `resources/views/themes/nocturne/components` (`x-ui::`, layouts), `resources/css/themes/nocturne.css`, delegadores `resources/views/layouts/{app,auth}.blade.php` | DESIGN.md |
| Roles y permisos | `User::canInviteRole`, `Policies/UserPolicy`, gates en `Providers/AppServiceProvider` | USER_MANAGEMENT.md |
| Invitaciones | `InvitationController`, `Models/Invitation`, `Notifications/PortalInvitation` | INVITATIONS.md |
| Alta manual y primer acceso | `Actions/Users/CreateUser`, `InitialPasswordController`, `Middleware/EnsureInitialPasswordChanged` | USER_MANAGEMENT.md |
| Suspensión y fotos de perfil | `Actions/Users/{SetSuspension,UpdateAvatar,RemoveAvatar}`, `Middleware/EnsureAccountIsActive`, `Services/Users/Avatars` (recorte, WebP 256 px, disco privado), `AvatarController` (`/avatars/{user}/{hash}`), `UserPolicy::{changeAvatar,removeAvatar,viewAvatar}`, `resources/js/avatar-editor.js` | USER_MANAGEMENT.md |
| Cuentas de servicio | `Services/ServiceAccounts` (estado local, elegibilidad, bloqueo y reactivación), puerto `Services/Provisioning/ServiceProvisioner` con registro `ServiceProvisioners`, adaptadores `Services/NavidromeAccounts` y `Services/KavitaAccounts` (Lectura; `portal:reading-setup` prepara Kavita), `Provisioning/ServiceUrl`, `config/provisioning.php`, `portal:sync-accounts`, `portal:accounts-status` | SERVICE_ACCOUNTS.md · KAVITA.md |
| Sesión musical | `MusicSessionController`, `Services/MusicSessionBridge`, `docker/music-proxy/` | MUSIC_SESSION_BRIDGE.md |
| Asistente y MCP | `Mcp/Servers/MultisuiteServer` (servidor `laravel/mcp`, sin ruta todavía), `Mcp/Tools/AssistantTool` (base: persona de la sesión, acceso, interruptor y presupuesto en cada llamada) y herramientas `IdentityMe`, `ServicesList`, `StorageMyUsage`, `FilesList`, `FilesSearch`, `FilesReadExcerpt` (contenido, con `Services/Files/FileText` y `pdftotext`), `FilesTrashList`, `MusicSearch` y `ReadingSearch` (`NavidromeAccounts`/`KomgaAccounts::searchFor`, solo bibliotecas de la persona), `SemanticSearch` (`Services/Assistant/SemanticIndex`, `portal:assistant-index`) y las de cambio sobre `Mcp/Tools/ChangeTool` (`FolderCreate`, `FilesRename`, `FilesTrash`, `FilesRestore`; solo dentro de `Services/Assistant/ApprovedChange`); `Services/Assistant/AssistantAccess`, `Actions/Assistant/{UpdateAssistantSettings,SetAssistantMember,SaveAssistantProvider,RemoveAssistantProvider}`, gates `manage-assistant` (solo owner) y `use-assistant`, página `pages::admin.assistant`, `PersonalFiles::page` (listado paginado). Chat (`laravel/ai`): agente `Ai/Agents/MultisuiteAssistant` (usa las herramientas MCP en el mismo proceso; las de cambio con `Ai/Tools/ApprovalRequiredTool`), `Services/Assistant/ChangeDescriber` (tarjetas de aprobación), `Services/Assistant/{AssistantChat,AssistantProviders}`, `Models/AssistantProvider` (clave cifrada), página `pages::assistant` (`/assistant`, CSP sin imágenes remotas) y botón flotante `pages::assistant-dock` en `layouts/app` (lógica común en `Livewire/Concerns/ChatsWithAssistant`), `config/ai.php` sin proveedores, `portal:assistant-prune`. Clientes externos: `routes/ai.php` (`Mcp::oauthRoutes`, `POST /mcp`), Passport (guard `api`, `User` con `HasApiTokens`), `Http/Middleware/EnsureExternalAssistant`, `pages/oauth-authorize`, `config/mcp.php`. Pruebas en `tests/Feature/Mcp` | MCP.md |
| Auditoría | `Services/AdminAudit`, `Enums/ServiceAuditAction`, página Livewire `pages::admin.activity` | ADMIN_AUDIT.md |
| Mis archivos | Páginas Livewire `resources/views/pages/files/{index,show,trash,exports}` (+ componente local `entry-actions`) (única entrada a las operaciones; búsquedas acotadas al propietario en cada petición) sobre `Services/Files/PersonalFiles` (carpetas, explorador, papelera, versiones, retención), `Services/Storage/{FileCipher,EncryptedWriter,StoredBlob}` (cifrado en reposo; se lee siempre a través de `StoredBlob`), `Http/Responses/BlobResponse` (descarga con rangos), `Services/Files/ZipStreamWriter`, `portal:files-key`, `Enums/FileKind` (único dueño de la clasificación por tipo: filtro SQL, etiquetas e iconos), `Services/Files/FileExports` y `Services/Files/FilePreview` (vista previa de solo lectura). `FileController` y `FileExportController` conservan solo lo que no es página: registro de subida (JSON para `resources/js/files.js`), contenido binario y descarga de ZIP. `Livewire/Concerns/ManagesOwnFiles` (papelera, restaurar, versiones y borrado compartidos por las páginas), `Middleware/RestrictPreviewImages` (CSP de la vista previa), `Support/DomainErrors`, `Policies/{UserFilePolicy,FolderPolicy}`, `portal:files-purge`, `portal:exports-run`. Pruebas con `tests/Concerns/UsesFilePages` | STORAGE.md |
| Mis finanzas | Página Livewire `resources/views/pages/finances` (solo valida y pinta; gate `use-finances`, `PORTAL_FINANCES`) sobre `Models/{PersonalTransaction,MonthlyBudget}` (alcance `ownedBy` en toda lectura), `Policies/PersonalTransactionPolicy`, `Actions/Finance/{RecordTransaction,DeleteTransaction,SetMonthlyBudget}` y `Services/Finance/FinanceSummary`; `Support/Finance/{MoneyFormat,Categories}` (moneda, formato y categorías; preferencias `finance_currency`/`finance_format`). Facturas desde Mis archivos: `Jobs/ReadReceipt` (cola, resultado en caché por persona y archivo) con `Support/Finance/ReadsReceipts` → `ReceiptReader` (pdftotext, pdftoppm, tesseract). Pruebas en `tests/Feature/PersonalFinanceTest` | PERSONAL_FINANCES.md |
| Carpetas conectadas | `Services/Files/ConnectedFolders` (carpetas fijas por servicio), `Services/Files/MediaLibrary` (reflejo por enlaces duros en `.multisuite-media` y sincronización idempotente), puerto `Services/Provisioning/PersonalLibraries` (implementado por `NavidromeAccounts`, `KavitaAccounts`, `KomgaAccounts` y `JellyfinAccounts`), `portal:media-sync`, `deploy/compose.{music,reading,video}-media.dev.yaml` | STORAGE.md |
| Publicación para todos | `Services/Files/Publications` (propuestas, decisiones, fondo común), `Services/Files/CommonLibrary` (biblioteca común por enlaces duros), `Services/Files/LinkMirror` (aplicar/podar espejos), puerto `Services/Provisioning/CommonLibraries` (`NavidromeAccounts`, `KomgaAccounts`, `JellyfinAccounts`), `Services/Files/MediaScans` (pide un nuevo escaneo solo si cambia el espejo; puerto `ScansMediaChanges`), página `admin.publications` | STORAGE.md § Publicación para todos |
| Almacenamiento | `Services/Storage/{VolumeProbe,StorageInventory,StorageReservations,StorageOverview,PrivateUploads,LosslessOptimizer,HardwareSnapshot,BackupStatus}`, `StorageController`, `PrivateUploadController`, `Support/Bytes`, `config/nas.php`, `portal:storage-status`, `portal:storage-quota`, `portal:uploads-cleanup`, `portal:dev-bootstrap`, `Services/Storage/HardwareSnapshot`, `scripts/nas-discover.*`, `scripts/nas-apply.*` | STORAGE.md |

Gates: `manage-invitations`, `view-admin-audit`, `view-storage` (owner/admin); `manage-storage-pools`, `manage-assistant` (owner); `manage-storage-quota` (owner → todos; admin → miembros).

## Modelo de datos
Motor: PostgreSQL 17 en desarrollo y producción; SQLite en los tests (CI ejecuta además PostgreSQL para concurrencia y planes de consulta). Revisión del esquema: 2026-11-14.

| Área | Tablas | Relaciones clave |
| --- | --- | --- |
| Identidad | `users`, `invitations`, `passkeys`, `sessions` | `invitations.invited_by` → users (null al borrar); `users.created_by_admin_id` → users |
| Cuentas de servicio | `service_accounts` | 1 por (usuario, servicio); `(service, username)` único |
| Auditoría | `admin_audit_events` | sin FK a propósito: el historial sobrevive al borrado |
| Asistente | `assistant_settings`, `assistant_members`, `assistant_tool_calls`, `assistant_providers`, `assistant_usage`, `assistant_consents`, `assistant_indexed_files`, `assistant_chunks`, `oauth_*` (Passport), `agent_conversations`, `agent_conversation_messages` | ajustes en una fila (id 1); miembros por persona; consultas sin argumentos ni resultados (90 días); proveedores con clave cifrada; tokens por persona y día; quién acepta que se lea su contenido; índice por significado con texto cifrado; clientes y tokens OAuth; conversaciones de `laravel/ai` por persona (30 días) |
| Almacenamiento | `storage_pools`, `user_storage_quotas`, `storage_settings` (fila única), `storage_reservations` | reserva → pool (restrict), → usuario (null al borrar) |
| Contenido | `private_uploads`, `file_exports` (subtipos 1:1 de la reserva, misma UUID), `folders` (árbol por `parent_id`), `user_files` (`folder_id`, `original_folder_id`, `version_of_id`) | tamaño y MIME se leen de la reserva/subida, no se duplican en `user_files` |
| Finanzas | `personal_transactions`, `monthly_budgets` | → users (cascade); `personal_transactions.receipt_id` → user_files (null al purgar); únicos `(user_id, entry_key)` y `(user_id, receipt_id)`; presupuesto único por `(user_id, month, currency)` |

**Desnormalizaciones deliberadas** (no "corregir"):
- Contadores `reserved_bytes`/`used_bytes` en pools y cuotas: permiten reservar con un bloqueo de fila en vez de sumar reservas; la reconciliación está en ROADMAP (Mis archivos y almacenamiento).
- `actor_name`/`subject_name` en auditoría: instantánea para que el historial no dependa de usuarios borrados.
- `file_exports.items`: instantánea del contenido en el momento de pedir el ZIP.
- `users.service_access` e `invitations.services` en JSON: conjunto pequeño de IDs validados contra `config/portal.php`, solo se lee por persona (nunca se busca "quién tiene X"). `null` significa cuenta anterior a las invitaciones con servicios (sin restricción gestionada). Pasar a tabla si algún día hay que consultar entre personas.
- `users.preferences` en JSON: solo interfaz, con valores por defecto en `Support/UserPreferences`.

**Índices**: PostgreSQL no indexa las claves foráneas; todas las columnas FK o de búsqueda tienen índice propio (migración `2026_11_18_000000_index_foreign_keys`) y `tests/Integration/QueryPlanTest.php` comprueba en CI que cada consulta usa el suyo.

**Pendiente** (ROADMAP, deuda técnica): enums PHP + `CHECK` para roles y estados escritos como texto. Si aparece un tercer subtipo de reserva, mover `location_id`/`root_hash` (hoy repetidos en `private_uploads` y `file_exports`) a la reserva común.

## Invariantes que no se deben romper
- Una contraseña escrita en una página Livewire nunca vuelve al navegador: las acciones que la reciben se envuelven en `ForgetsSecrets::forgetting()`.
- Las acciones Livewire que modifican datos llaman a `ThrottlesActions::throttle()` con el limitador con nombre de su ruta: `/livewire/update` no pasa por `throttle:` y así ambos caminos comparten contador y definición.
- Ediciones de personas por capas: la página Livewire (adaptador) valida con `rules()` y llama a una Action; la Action bloquea actor y objetivo, autoriza con la Policy, aplica el cambio primero en los servicios a través del puerto `ServiceProvisioner` y después en Multisuite, y audita. Ninguna vista modifica modelos directamente.
- Los componentes Livewire de un solo archivo (`resources/views/pages`) no los analiza PHPStan: solo orquestan. Consultas en scopes de modelo (`User::search`, `Invitation::inStatus`) y mutaciones en Actions.
- Autorización en servidor en cada lectura/escritura; revalidar roles **bajo bloqueo** en operaciones administrativas (`Support/LockedUsers::pair`).
- Una operación de dominio vive en una Action y tiene una sola entrada (su página Livewire). No mantener endpoints paralelos sin uso ni duplicar reglas en componentes.
- Orden de bloqueo del almacenamiento: usuario → grupo → cuota → reserva.
- Nunca aceptar rutas, URLs de redirección ni IDs de servicio no registrados desde la petición. Nombres de archivo/carpeta son etiquetas, nunca rutas.
- Ninguna ruta de Laravel bajo `/storage/`: Nginx la sirve como disco público.
- Límites de peticiones con nombre (`RateLimiter::for` en `AppServiceProvider`: files, uploads, file-exports, admin…). Nunca `throttle:N,1` sin nombre en rutas web: comparten un contador por usuario entre todas las rutas y las anidadas cuentan doble.
- Auditoría con lista blanca de campos: sin payloads, rutas, contraseñas ni respuestas remotas.
- Migraciones solo incrementales; no editar las ya aplicadas.
- La vista previa de un archivo (`files.show`) se abre siempre con carga completa (sin `wire:navigate`): su cabecera CSP `img-src 'self' data:` impide que un Markdown propio cargue imágenes remotas.
- Fallar cerrado: capacidad desconocida, marca ausente o conflicto bloquean escrituras.

## Pruebas
- `make test` (PHPUnit; `CMD="--filter=…"`), `make lint`/Pint, PHPStan nivel 7 (`app/phpstan.neon`), build con `make npm CMD="run build"`.
- CI: `.github/workflows/application.yml` (app, Navidrome real, PostgreSQL), `ci.yml` (infraestructura Linux/Windows), `library-isolation.yml` (S01).
- El entorno de un agente puede no tener PHP/Docker: indicarlo y delegar la ejecución en CI, sin inventar resultados.

## Vídeo con Jellyfin

Tres piezas con una responsabilidad cada una: `JellyfinClient` (transporte: cabecera MediaBrowser, clave API cifrada en `ServiceConnection`, traducción de errores HTTP), `JellyfinServer` (conexión del propietario —asistente inicial o administrador existente—, aviso de acceso, aceleración entre las que ofrece el fabricante detectado `VIDEO_HARDWARE` y retirada de sesiones de navegador inactivas, `portal:video-sessions-prune`) y `JellyfinAccounts` (adaptador de cuentas: implementa los puertos `ServiceProvisioner`, `PersonalLibraries`, `CommonLibraries`, `NeedsConnection` y `ScansMediaChanges`; política sin administrador, solo sus bibliotecas y límite parental de `users.video_max_rating` vía `Actions/Users/SetVideoRating`, comprobada tras guardarla). `VideoSession` abre la sesión del navegador sin segundo inicio de sesión. Páginas: `pages::admin.video-connection` y `pages::video-confirm`. `compose.video.yaml` es autónomo; `video-gateway` (`docker/video-proxy`) es la única entrada y añade aspecto y vuelta al portal; los overrides `deploy/compose.video-{intel,amd,nvidia}.yaml` añaden dispositivos solo a Jellyfin. Ver [JELLYFIN.md](JELLYFIN.md).

## Descargas (P04.3)

`compose.downloads.yaml` opcional (con `downloads.env`): MeTube sin puertos ni red compartida y `downloads-gateway`, que pregunta al portal en cada petición (`routes/api.php` `downloads/*`, `App\Services\Downloads\DownloadsAccess`). Lo descargado espera en el volumen `multisuite-downloads`, montado en el portal por `deploy/compose.portal-downloads.example.yaml`; `StagedDownloads` lo lista por identificador opaco y lo guarda en Mis archivos con `PersonalFiles::begin` + `PrivateUploads::receive`. Página `pages::admin.downloads`, gate `manage-downloads`. La entrada desde la portada a Lectura, Vídeo y Descargas pasa por `App\Support\ServiceLaunch` (origen, regla HTTPS y vista común `service-launch`). Ver [DOWNLOADS.md](DOWNLOADS.md).

## Red privada (P03)

`compose.vpn.yaml` es autónomo y opcional (solo `make vpn-*` con `vpn.env`): `headscale` (coordinación, única pieza pública en el servidor), `vpn-node` (el NAS como dispositivo, modo usuario; publica los nombres privados en el archivo DNS de Headscale, `docker/vpn-node/node.sh`) y `vpn-entry` (Traefik en el espacio de red de `vpn-node`: nombre privado → portal o pasarela por `multisuite-accounts`, `docker/vpn-entry/routes.yml`). Política en `docker/headscale/policy.hujson`. Operación con `scripts/vpn.{sh,ps1}`. El portal solo confía en `X-Forwarded-*` de `TRUSTED_PROXIES` (`config/app.php`, `AppServiceProvider`). Ver [VPN.md](VPN.md).

## Lectura con Komga

`KomgaAccounts` implementa aprovisionamiento y bibliotecas privadas. `ServiceConnection` guarda una clave API cifrada, autorizada por el propietario en `pages::admin.komga-connection`. `KomgaSession` entrega la cookie nativa mediante pase breve; `pages::komga-confirm` renueva sesiones. El espejo reutiliza el espacio `reading` mediante `ServiceAccount.media_id`. Ver [KOMGA.md](KOMGA.md).

## Roles y estados
`app/Enums` (`Role`, `ReservationState`, `UploadState`, `ExportState`, `ServiceAccountStatus`, `PublicationState`) define los valores válidos; las columnas siguen siendo texto y PostgreSQL los impone con `CHECK` (migraciones `2026_11_27_000000_add_state_checks` y `…000001_create_publications`, `tests/Integration/StateChecksTest`). En el código se compara con `->value`; no hay cast a enum porque cambiaría el significado de cada `===` existente y PHPStan no analiza las vistas Blade.
