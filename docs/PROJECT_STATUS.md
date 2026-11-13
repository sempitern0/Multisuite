# Estado de Multisuite

Instantánea a 2026-11-13. Historial detallado: git log y PRs #1–#14. Este archivo se **reescribe** (no se acumula) al cerrar cada incremento.

## Integrado en `main` (PRs #1–#14)
| Área | Qué hay | Validación |
| --- | --- | --- |
| Portal | Starter Livewire, portada de servicios, `/services/{id}` con destinos administrados | CI; QA visual de portada pendiente |
| Acceso | Invitaciones firmadas, roles owner/admin/member, alta manual, username o correo, 2FA/passkeys | CI + usuario |
| Cuentas de servicio | Aprovisionamiento Navidrome 0.64.2 con reintentos cifrados efímeros | CI con Navidrome real |
| Sesión de Música | Gateway Nginx + pase cifrado de 60 s; no es SSO | CI; QA navegador pendiente |
| Administración | CRUD de usuarios, auditoría administrativa (incluye `storage.*`) | CI |
| Música | `compose.music.yaml` autónomo, ciclo integrado con `make up` | Usuario |
| Aislamiento | Laboratorio S01 (30 comprobaciones reales) | CI *Library isolation* |
| Almacenamiento (S02) | Inventario, reservas/cuotas transaccionales, página **Almacenamiento** en `/admin/storage` (discos, reparto, cuota por defecto y por persona) | CI + guardado validado por el usuario |
| Mis archivos (S03.1–S03.2) | Recepción privada, carpetas, subida web con progreso, descarga y vista previa de solo lectura (imágenes, PDF, Markdown saneado, texto) | CI (`PersonalFilesTest`) + usuario |
| Entorno de desarrollo | `make up` prepara NAS de desarrollo (`deploy/compose.nas-dev.yaml`, `NAS_DEV`) y ejecuta `portal:dev-bootstrap` (migraciones + límites sin configurar, `DEV_BOOTSTRAP`) | CI (`DevBootstrapTest`, Make con Docker simulado) + arranque real del usuario en Docker Desktop |

Decisiones vigentes: Mis archivos es del portal (excepción acotada en AGENTS.md); entorno local desechable mientras no haya producción.

## En revisión: rama `feat/s03-folder-info`
- Mis archivos muestra en cada carpeta sus elementos y su tamaño exacto acumulado.
- Almacenamiento y `portal:dev-bootstrap` avisan cuando la capacidad procede del disco virtual de Docker Desktop.

## En revisión: S02.4 descubrimiento de discos — rama `feat/s02-disk-discovery` (apilada sobre #15)
- `make nas-discover` (solo lectura) y `make nas-apply TARGETS=…` preparan discos ya montados en el servidor Linux: marca, carpeta privada 0700 y `compose.nas.yaml` local, que Make prefiere al NAS de desarrollo. Nunca formatea, particiona ni monta.
- Panel **Discos del servidor** en Almacenamiento a partir del inventario `lsblk` (solo lectura).
- Validado: `tests/nas-disk-discovery.py` con `lsblk`/`findmnt` simulados; `HardwareSnapshotTest` en CI. Pendiente: prueba en el servidor real.

## En revisión: S03.3 papelera y versiones — rama `feat/s03-trash-versions` (apilada sobre #16)
- Papelera con restauración; borrado definitivo verificado que devuelve la cuota; versiones al subir un nombre repetido (se aplican al verificar los bytes); retención diaria (30 días, 5 versiones).
- Tests en `PersonalFilesTest`: versiones, papelera y cuota, aislamiento, fallo cerrado sin volumen, retención.

## Siguiente acción ejecutable
1. Usuario confirmó el 2026-11-13 que `main` funciona (Almacenamiento y Mis archivos). Revisar la PR de información de carpetas.
2. S03.3 Papelera y versiones (rama nueva desde `main`).

## Límites conocidos
- Producción no desplegada; VPN/TLS, backups y piloto pendientes (P03/P05).
- La biblioteca habitual de Navidrome es común: no hay contenido privado por usuario en el despliegue actual.
- Las cuotas controlan lo que entra por el portal; archivos añadidos por fuera reducen el espacio físico pero no se imputan a nadie.
- Inventario real de discos, capacidad y host del usuario: desconocido; pedirlo al preparar el despliegue.
