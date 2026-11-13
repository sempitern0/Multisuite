# Multisuite

NAS doméstico autoalojado con un portal privado (Laravel 13 + Livewire 4 + Flux) para centralizar los servicios del día a día y el ocio, e invitar a familiares y amistades. Cada aplicación de terceros conserva su interfaz y sus datos; el portal gestiona el acceso, las cuentas, el espacio de almacenamiento y la auditoría.

## Qué funciona hoy
- **Portada** con tarjetas de servicios y acceso controlado (`/services/{id}`).
- **Acceso privado**: sin registro libre; invitaciones firmadas de un uso, alta manual con contraseña temporal, roles propietario/administrador/miembro, 2FA y passkeys.
- **Música** con [Navidrome](docs/NAVIDROME.md): stack independiente, cuentas aprovisionadas desde el portal y puente de sesión para dispositivos compartidos.
- **Administración**: usuarios, invitaciones y registro de actividad.
- **Almacenamiento**: inventario de discos, cuotas por persona con reservas atómicas, recepción privada de archivos y página **Almacenamiento** para repartir el espacio sin conocimientos técnicos ([STORAGE.md](docs/STORAGE.md)).

Aún no desplegado en producción (VPN/TLS, backups y piloto pendientes). Estado exacto en [PROJECT_STATUS.md](docs/PROJECT_STATUS.md); trabajo pendiente en [ROADMAP.md](docs/ROADMAP.md).

## Arranque rápido (desarrollo)
Requisitos: Docker con Compose v2 y Make (Windows sin Make: `powershell -NoProfile -File scripts/setup.ps1`).

```sh
make build                                # primera vez o tras cambios de imágenes
make up                                   # arranca todo, migra y prepara el almacenamiento
make artisan CMD="portal:owner tu@correo --create"   # único paso manual: primer propietario
make test                                 # tests de la aplicación
```

`make up` aplica migraciones y prepara un **NAS de desarrollo**: volumen Docker privado con su marca y permisos, límite del disco y 10 GB por persona si nadie los ha elegido aún. Los cambios que hagas después en **Almacenamiento** se respetan. Usa `NAS_DEV=0` si `app/.env` apunta a discos reales (el arranque muestra qué ubicaciones están activas) y `DEV_BOOTSTRAP=0` para no migrar automáticamente. El entorno local es desechable: `make reset-project` + `make build` lo recrea desde cero (borra la DB y los archivos de desarrollo).

Solo música, sin Laravel: copiar `music.env.example` a `music.env`, crear carpetas y `docker compose --env-file music.env -f compose.music.yaml up -d` ([guía](docs/NAVIDROME.md)). `make up MUSIC_AUTO=0` arranca solo el portal.

No ejecutar `make setup`/`reset-project` sobre la app existente: el starter ya está versionado en `app/`.

## Documentación
| Tema | Documento |
| --- | --- |
| Instrucciones para agentes de IA y colaboradores | [AGENTS.md](AGENTS.md) · [CLAUDE.md](CLAUDE.md) |
| Arquitectura y mapa del código | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Estado y pendientes | [docs/PROJECT_STATUS.md](docs/PROJECT_STATUS.md) · [docs/ROADMAP.md](docs/ROADMAP.md) |
| Almacenamiento y cuotas | [docs/STORAGE.md](docs/STORAGE.md) |
| Usuarios, invitaciones, auditoría | [USER_MANAGEMENT](docs/USER_MANAGEMENT.md) · [INVITATIONS](docs/INVITATIONS.md) · [ADMIN_AUDIT](docs/ADMIN_AUDIT.md) |
| Servicios | [NAVIDROME](docs/NAVIDROME.md) · [SERVICE_ACCOUNTS](docs/SERVICE_ACCOUNTS.md) · [MUSIC_SESSION_BRIDGE](docs/MUSIC_SESSION_BRIDGE.md) |
| Entornos y dependencias | [ENVIRONMENTS](docs/ENVIRONMENTS.md) · [DEPENDENCIES](docs/DEPENDENCIES.md) |
| Plantilla Laravel Foundry (despliegue, hosting, entornos de desarrollo) | [docs/foundry/](docs/foundry/DEPLOYMENT.md) |

## Licencia
[MIT](LICENSE) para este repositorio. Cada servicio integrado conserva su licencia.
