# Estado de Multisuite

Instantánea a 2026-11-14. Historial detallado: git log y PRs #1–#24. Este archivo se **reescribe** (no se acumula) al cerrar cada incremento.

## Integrado en `main` (PRs #1–#24)
| Área | Qué hay | Validación |
| --- | --- | --- |
| Portal | Portada «Servicios» (`DashboardController` + `ServiceTiles`), `/services/{id}` con destinos administrados | CI; QA visual del usuario pendiente |
| Modelo de datos | Índices de claves foráneas, consultas agregadas y `QueryPlanTest` en PostgreSQL; `/` redirige a entrar o a la página de inicio | CI |
| Perfil y Seguridad (D02b.2b) | Perfil, contraseña, 2FA y llaves de acceso en el tema; fin de la migración de diseño | CI; QA con dispositivos reales pendiente |
| Mis archivos (D02b.2a) | Explorador, vista previa, papelera y Descargas ZIP como páginas Livewire con el tema, sin recargas | CI; subida real en navegador pendiente del usuario |
| Administración (D02b.1) | Personas (crear/editar/eliminar) y Almacenamiento como páginas Livewire sobre Actions de entrada única; diálogo de confirmación del tema | CI; QA del usuario pendiente |
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

## En revisión: S05.1 Copia de seguridad del NAS — rama `feat/s05-nas-backup`
- `make nas-backup` (servidor Linux): volcado de la DB, archivos privados, carpetas extra y datos de Navidrome a un repositorio restic cifrado en disco externo o remoto; retención, comprobación y `local/backup-status.json`. Se niega si el destino comparte disco con los datos.
- `make nas-backup-verify` ensaya la restauración en un volumen y un PostgreSQL temporales; `nas-backup-restore` solo escribe en carpetas vacías; `nas-backup-snapshots` lista copias.
- **Almacenamiento → Copia de seguridad** muestra si la última copia es reciente, antigua, fallida o no existe (`Services/Storage/BackupStatus`, solo lectura).
- restic 0.19.1 como contenedor efímero (DEPENDENCIES D011). Sin migraciones ni paquetes PHP/JS.
- Validado: CI (pruebas con Docker simulado y `BackupStatusTest`). **No validado**: ninguna copia real, la etiqueta de la imagen ni el ensayo de restauración; requieren el servidor.
- De paso: `nas-discover` reescribe `nas-hardware.json` en el sitio para que el portal vea la actualización sin recrear contenedores.

## Siguiente acción ejecutable
1. Usuario: revisar la PR S05.1. En el servidor (o en desarrollo con `BACKUP_ALLOW_SAME_DISK=1` y una carpeta de destino): `cp backup.env.example backup.env`, `make nas-backup`, `make nas-backup-verify`.
2. Usuario: QA pendiente de D02 (Seguridad con autenticador y llave reales, subida real en Mis archivos).
3. En el servidor Linux: `make nas-discover` y `make nas-apply TARGETS="…"`.
4. Siguiente hito: S04 (publicación administrativa) necesita tres decisiones del usuario (ROADMAP); si no, P03.

## Límites conocidos
- Producción no desplegada; VPN/TLS, copia de seguridad real programada y piloto pendientes (P03/P05).
- La biblioteca habitual de Navidrome es común: no hay contenido privado por usuario en el despliegue actual.
- Las cuotas controlan lo que entra por el portal; archivos añadidos por fuera reducen el espacio físico pero no se imputan a nadie.
- Inventario real de discos, capacidad y host del usuario: desconocido; pedirlo al preparar el despliegue.
