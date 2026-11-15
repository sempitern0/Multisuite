# Estado de Multisuite

Instantánea a 2026-11-15 (PRs #1–#34). Se **reescribe** al cerrar cada incremento; el detalle vive en git y en las PRs.

## Integrado en `main`
| Área | Qué hay | Validación |
| --- | --- | --- |
| Acceso y personas | Invitaciones firmadas, alta manual, roles owner/admin/member, usuario o correo, 2FA y llaves de acceso. Ficha de persona: datos, rol, nombre de usuario (también en los servicios), servicios por persona, suspensión, baja. Foto de perfil con Cropper.js (WebP 256 px privado) | CI con Navidrome real; QA del usuario pendiente |
| Portada y servicios | Portada con tarjetas, `/services/{id}` con destinos administrados; puerto `ServiceProvisioner` con un adaptador por servicio | CI |
| Música (Navidrome 0.64.2) | Stack autónomo, cuentas aprovisionadas con reintentos cifrados de una hora, renombrado y bloqueo, puente de sesión (no es SSO) | CI con Navidrome real; QA del navegador pendiente |
| Mis archivos | Carpetas, subida por fragmentos reanudable, explorador con búsqueda/filtros/vistas, vista previa (imagen con zoom, vídeo, audio, PDF, Markdown, texto), papelera y versiones, ZIP, cifrado en reposo, metadatos de fotos fuera con ExifTool | CI + usuario |
| Almacenamiento | Inventario de discos, cuotas y reservas transaccionales, página Almacenamiento, `make nas-discover`/`nas-apply`, copia externa con restic (`make nas-backup`) | CI (Docker simulado); sin prueba en servidor real |
| Administración | Personas, Invitaciones, Actividad (auditoría con lista blanca), Preferencias | CI |
| Diseño | Tema Nocturne sustituible (`x-ui::`), contraste AA, responsive, menú contraíble | CI + usuario |

Decisiones vigentes: Mis archivos es del portal (excepción acotada en AGENTS.md); librería antes que código propio; entorno local desechable mientras no haya producción.

## Acciones pendientes del usuario
- `make rebuild` (WebP y ExifTool) y QA de lo reciente (ROADMAP § QA).
- Guardar `NAS_FILES_KEY` fuera del servidor.
- Ensayo de copia real: `make nas-backup` y `make nas-backup-verify`.

## Límites conocidos
- Producción no desplegada: faltan VPN/TLS, copia programada y piloto (P03/P05).
- La biblioteca de Navidrome es común: no hay contenido privado por persona.
- Las cuotas solo cuentan lo que entra por el portal.
- Hardware real del servidor (discos, capacidad): desconocido; pedirlo al preparar el despliegue.
