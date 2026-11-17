# Estado de Multisuite

Instantánea a 2026-11-15 (PRs #1–#35, #36 en revisión). Se **reescribe** al cerrar cada incremento; el detalle vive en git y en las PRs.

## Integrado en `main`
| Área | Qué hay | Validación |
| --- | --- | --- |
| Acceso y personas | Invitaciones firmadas, alta manual, roles owner/admin/member, usuario o correo, 2FA y llaves de acceso. Ficha de persona: datos, rol, nombre de usuario (también en los servicios), servicios por persona, suspensión, baja. Foto de perfil con Cropper.js (WebP 256 px privado) | CI con Navidrome real; QA del usuario pendiente |
| Portada y servicios | Portada con tarjetas, `/services/{id}` con destinos administrados; puerto `ServiceProvisioner` con un adaptador por servicio | CI |
| Lectura (Kavita 0.9.1) | Stack autónomo con `reading.env`, `portal:reading-setup` (cuenta de servicio y bibliotecas Libros, Cómics, Manga, Documentación técnica), cuentas gestionadas como Música | CI con Kavita real; autologin validado por el usuario; copia de configuración con restic probada con Docker simulado; QA con libros reales pendiente |
| Música (Navidrome 0.64.2) | Stack autónomo, cuentas aprovisionadas con reintentos cifrados de una hora, renombrado y bloqueo, autologin por proxy con verificación de la cuenta propia y salida al login manual | CI con Navidrome real; autologin validado por el usuario |
| Mis archivos | Carpetas, subida por fragmentos reanudable, explorador con búsqueda/filtros/vistas, vista previa (imagen con zoom, vídeo, audio, PDF, Markdown, texto), papelera y versiones, ZIP, cifrado en reposo, metadatos de fotos fuera con ExifTool | CI + usuario |
| Almacenamiento | Inventario de discos, cuotas y reservas transaccionales, página Almacenamiento, `make nas-discover`/`nas-apply`, copia externa con restic (`make nas-backup`) | CI (Docker simulado); sin prueba en servidor real |
| Administración | Personas, Invitaciones, Actividad (auditoría con lista blanca), Preferencias | CI |
| Diseño | Tema Nocturne sustituible (`x-ui::`), contraste AA, responsive, menú contraíble | CI + usuario |

Decisiones vigentes: Mis archivos es del portal (excepción acotada en AGENTS.md); librería antes que código propio; entorno local desechable mientras no haya producción.

## En revisión: PR #36 — rama `feat/u04-new-services-on-login`
- Servicios nuevos al entrar: las personas existentes reciben los servicios añadidos después (p. ej. Lectura) al entrar con contraseña; nunca los que un administrador dejó fuera o desactivó (`users.services_revoked`).
- S04a carpetas conectadas: Música, Libros y Cómics en Mis archivos de cada persona; lo que sube aparece solo para ella en Navidrome y Kavita (biblioteca personal), sin cifrar y con una sola copia en disco (enlace duro). Sincronización automática tras cada cambio y repaso cada 15 minutos (`portal:media-sync`).
- Validación: CI (pruebas de funcionalidad e integración con Navidrome y Kavita reales). QA del usuario pendiente (ROADMAP § S04a).

## Acciones pendientes del usuario
- `make rebuild` (WebP y ExifTool; recrea el volumen NAS de desarrollo con el nombre fijo `multisuite-nas-dev`, así que se pierden los archivos de prueba anteriores) y QA de lo reciente.
- Guardar `NAS_FILES_KEY` fuera del servidor.
- Ensayo de copia real: `make nas-backup` y `make nas-backup-verify`.

## Límites conocidos
- Producción no desplegada: faltan VPN/TLS, copia programada y piloto (P03/P05).
- Contenido privado por persona en los servicios solo a través de las carpetas conectadas; las bibliotecas montadas por el operador son comunes.
- El montaje de las carpetas conectadas en los servicios solo está automatizado en desarrollo (P05).
- Las cuotas solo cuentan lo que entra por el portal.
- Hardware real del servidor (discos, capacidad): desconocido; pedirlo al preparar el despliegue.
