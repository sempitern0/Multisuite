# Estado de Multisuite

Instantánea a 2026-11-13. Historial detallado: git log y PRs #1–#11. Este archivo se **reescribe** (no se acumula) al cerrar cada incremento.

## Integrado en `main` (3d98ca0)
| Área | Qué hay | Validación |
| --- | --- | --- |
| Portal | Starter Livewire, portada de servicios, `/services/{id}` con destinos administrados | CI verde; QA visual de portada pendiente |
| Acceso | Registro libre retirado; invitaciones firmadas de un uso; roles owner/admin/member; alta manual con contraseña temporal; username o correo; 2FA/passkeys | CI + confirmación del usuario |
| Cuentas de servicio | Aprovisionamiento Navidrome 0.64.2 (alta, cambio/reset, renombrado, baja) con reintentos cifrados efímeros | CI con Navidrome real desechable |
| Sesión de Música | Gateway Nginx + pase cifrado de 60 s; no es SSO | CI (proxy/Range); QA navegador pendiente |
| Administración | CRUD de usuarios, auditoría administrativa protegida | CI; QA visual del CRUD pendiente |
| Música | `compose.music.yaml` autónomo, ciclo integrado con `make up` | Usuario confirma que funciona; checklist P01 sin evidencia |
| Aislamiento | Laboratorio S01 con 30 comprobaciones reales | Workflow *Library isolation* verde |
| Almacenamiento | Inventario de discos, reservas/cuotas transaccionales, recepción privada HTTP | CI + concurrencia PostgreSQL |

## Lista para merge: S02.3 — PR #12, rama `feat/s02-storage-admin-gui`
- Página **Almacenamiento** para owner/admin: estado y barra de cada disco, límite con opciones predefinidas o unidades humanas, reparto/sobreasignación, cuota por defecto y cuota por persona. Owner gestiona discos, defecto y a cualquiera; admin solo cuotas de miembros.
- Cuota por defecto (`storage_settings`, migración incremental) aplicada de forma perezosa en la primera reserva; 0 conserva el comportamiento anterior.
- Auditoría `storage.*`, «Tu almacenamiento» en portada, enlace desde la ficha de usuario.
- Reservas caducadas visibles por disco, separando limpieza automática y revisión manual (solo lectura). `portal:storage-quota` acepta unidades (`50GB`, `1,5TB`).
- Página en `/admin/storage` (Nginx reserva `/storage/` para el disco público; test de regresión). CI de eeb6fec verde: 120 tests / 580 aserciones, Pint, PHPStan nivel 7, build, PostgreSQL concurrente, Navidrome real e infraestructura Linux/Windows. Guardado validado por el usuario en su entorno HTTPS local.
- Documentación reorganizada: obsoletos eliminados, Foundry en `docs/foundry/`, almacenamiento consolidado en STORAGE.md, guía para agentes en AGENTS.md y ARCHITECTURE.md.

## En revisión: S03.2 Mis archivos — PR #13, rama `feat/s03-my-files` (depende de PR #12)
- Decisión del usuario 2026-11-13: gestor propio en el portal con carpetas; copias = exportar, papelera/versiones y backup del NAS (hitos S03.3, S03.4, S05.1). AGENTS.md recoge la excepción acotada.
- Carpetas privadas, subida web con progreso (reserva + PUT existente), listado, renombrado, descarga y vista previa de imágenes, PDF, Markdown saneado y texto. Tipos ampliados (ofimática, EPUB, imágenes). Nginx admite 110 MiB por petición.
- Tests: `PersonalFilesTest` (flujo completo, aislamiento entre personas, carpetas, nombres, Markdown/XSS, PDF, cuota). CI verde: 128 tests, Pint, PHPStan, build e infraestructura. Un `.md` con `<script>`/`<html>` se detecta como HTML y se rechaza (documentado).

## En revisión: automatización del entorno — rama `feat/dev-automation` (apilada sobre #13)
- `make up` migra y prepara un NAS de desarrollo (volumen `nas_private`, marca y permisos por `nas-init`, límites iniciales con `portal:dev-bootstrap`) sin pasos manuales; `NAS_DEV=0` lo omite.
- AGENTS.md revisado: entorno local desechable, migraciones editables dentro de una PR abierta, PRs apiladas, automatización por defecto y verificación manual solo para lo que CI no cubre. Se mantienen las protecciones de host, producción, datos reales y secretos.
- Validado aquí: `tests/music-lifecycle.py` (Make con Docker simulado) y `docker compose config` de los overlays. App: `DevBootstrapTest` en CI.

## Siguiente acción ejecutable
1. Merge manual de la PR #12 y después de la #13 (GitHub la reapunta a `main`).
2. Tras fusionar, `make build && make up` y probar Mis archivos (ya no requiere preparar la ubicación a mano): varios tipos, móvil y escritorio, otra persona no ve nada.
3. S03.3 Papelera y versiones.

## Límites conocidos
- Producción no desplegada; VPN/TLS, backups y piloto pendientes (P03/P05).
- La biblioteca habitual de Navidrome es común: no hay contenido privado por usuario en el despliegue actual.
- Las cuotas controlan lo que entra por el portal; archivos añadidos por fuera reducen el espacio físico pero no se imputan a nadie.
- Inventario real de discos, capacidad y host del usuario: desconocido; pedirlo al preparar el despliegue.
