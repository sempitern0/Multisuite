# Estado de Multisuite

Instantánea a 2026-11-14. Historial detallado: git log y PRs #1–#17. Este archivo se **reescribe** (no se acumula) al cerrar cada incremento.

## Integrado en `main` (PRs #1–#17)
| Área | Qué hay | Validación |
| --- | --- | --- |
| Portal | Starter Livewire, portada de servicios, `/services/{id}` con destinos administrados | CI; QA visual de portada pendiente |
| Acceso | Invitaciones firmadas, roles owner/admin/member, alta manual, username o correo, 2FA/passkeys | CI + usuario |
| Cuentas de servicio | Aprovisionamiento Navidrome 0.64.2 con reintentos cifrados efímeros | CI con Navidrome real |
| Sesión de Música | Gateway Nginx + pase cifrado de 60 s; no es SSO | CI; QA navegador pendiente |
| Administración | CRUD de usuarios, auditoría administrativa (incluye `storage.*`) | CI |
| Música | `compose.music.yaml` autónomo, ciclo integrado con `make up` | Usuario |
| Aislamiento | Laboratorio S01 (30 comprobaciones reales) | CI *Library isolation* |
| Almacenamiento (S02) | Inventario, reservas/cuotas transaccionales, página **Almacenamiento** en `/admin/storage` (discos, reparto, cuota por defecto y por persona, aviso de disco virtual de Docker Desktop, panel «Discos del servidor») y `make nas-discover`/`nas-apply` para discos reales | CI + usuario (GUI); scripts de discos probados con herramientas simuladas, pendientes en servidor real |
| Mis archivos (S03.1–S03.3) | Recepción privada, carpetas con elementos y tamaño exacto, subida web, descarga, vista previa de solo lectura, papelera con borrado verificado, versiones y retención diaria | CI (`PersonalFilesTest`); papelera/versiones pendientes de QA del usuario |
| Entorno de desarrollo | `make up` prepara NAS de desarrollo (`deploy/compose.nas-dev.yaml`, `NAS_DEV`) y ejecuta `portal:dev-bootstrap` (migraciones + límites sin configurar, `DEV_BOOTSTRAP`) | CI (`DevBootstrapTest`, Make con Docker simulado) + arranque real del usuario en Docker Desktop |

Decisiones vigentes: Mis archivos es del portal (excepción acotada en AGENTS.md); entorno local desechable mientras no haya producción.

## En revisión: S03.4 descargas ZIP — rama `feat/s03-zip-exports`
- Selección, carpeta o todo; ZIP sin compresión en la ubicación privada con reserva temporal que no carga la cuota; ≤ 50 MiB al momento y el resto por `portal:exports-run`; 24 h y borrado verificado antes de liberar el espacio.
- Sin dependencias nuevas (extensión `zip` de PHP ya presente; Packagist no es accesible desde el entorno del agente).
- Tests: `FileExportsTest` (contenido y rutas, nombres repetidos, papelera/versiones excluidas, cuota intacta, scheduler y caducidad, fallo con limpieza, aislamiento, límites, sin espacio). `PersonalFilesTest` usa el nuevo trait `UsesPrivateStorage`.

## Siguiente acción ejecutable
1. Usuario: `git checkout main && git pull && make up` (migra solo) y probar papelera, restaurar, vaciar y versiones (subir dos veces el mismo nombre).
2. En el servidor Linux: `make nas-discover` y `make nas-apply TARGETS="…"`.
3. S03.4 Descargas ZIP y «Exportar todo» (rama nueva desde `main`).

## Límites conocidos
- Producción no desplegada; VPN/TLS, backups y piloto pendientes (P03/P05).
- La biblioteca habitual de Navidrome es común: no hay contenido privado por usuario en el despliegue actual.
- Las cuotas controlan lo que entra por el portal; archivos añadidos por fuera reducen el espacio físico pero no se imputan a nadie.
- Inventario real de discos, capacidad y host del usuario: desconocido; pedirlo al preparar el despliegue.
