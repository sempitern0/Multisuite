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
- Corregido de paso: los `throttle:N,1` sin nombre compartían un contador por usuario en todas las rutas web (un ZIP tras subir varios archivos daba 429). Ahora cada área tiene su limitador con nombre y los mismos valores.
- Tests: `FileExportsTest` (contenido y rutas, nombres repetidos, papelera/versiones excluidas, cuota intacta, scheduler y caducidad, fallo con limpieza, aislamiento, límites, sin espacio). `PersonalFilesTest` usa el nuevo trait `UsesPrivateStorage`.

## En revisión: D01 diseño Nocturne — rama `feat/d01-nocturne-design` (apilada sobre S03.4)
- Capa de tema separada: `config/ui.php` (`UI_THEME`), componentes `x-ui::` en `resources/views/themes/nocturne/components`, tokens y clases en `resources/css/themes/nocturne.css`; `layouts/app|auth` solo delegan. Contratos en DESIGN.md.
- Migradas: shell (barra lateral por gates, cuenta abajo, menú móvil), acceso, 2FA, recuperar/guardar contraseña, confirmar, verificar correo, invitación, contraseña inicial, bienvenida y portada «Servicios».
- La lógica de la portada pasa a `DashboardController` + `Support/ServiceTiles`; los servicios gestionados fuera de la invitación ya no se muestran.
- El resto de pantallas hereda la paleta (reasignación `zinc`/`teal` y acento Flux) hasta D02. Tema solo oscuro.
- Tests: `DesignSystemTest` (navegación por rol, servicios ocultos/próximamente, layout de acceso) + los existentes. Sin dependencias nuevas. Validación visual pendiente del usuario.

## Siguiente acción ejecutable
1. Usuario: revisar PR S03.4 y D01; tras fusionar, `make up` + `make npm CMD="run build"` y probar acceso y portada en móvil y escritorio.
2. D02: migrar Mis archivos y administración al tema.
3. En el servidor Linux: `make nas-discover` y `make nas-apply TARGETS="…"`.

## Límites conocidos
- Producción no desplegada; VPN/TLS, backups y piloto pendientes (P03/P05).
- La biblioteca habitual de Navidrome es común: no hay contenido privado por usuario en el despliegue actual.
- Las cuotas controlan lo que entra por el portal; archivos añadidos por fuera reducen el espacio físico pero no se imputan a nadie.
- Inventario real de discos, capacidad y host del usuario: desconocido; pedirlo al preparar el despliegue.
