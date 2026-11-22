# Vídeo (Jellyfin)

Jellyfin 10.11.11 es la interfaz completa de vídeo: biblioteca, reproductor, apps y transcodificación son suyos. El portal solo prepara cuentas, bibliotecas, permisos y la aceleración por hardware. Estado: **implementado y validado en CI** (PR #47); falta QA del usuario con Docker y GPU reales.

## Puesta en marcha (desarrollo)
1. `make video-detect` (crea `video.env` desde `video.env.example` y anota la GPU). `HW=intel|amd|nvidia|none` fuerza una opción. En Windows (`scripts/video-detect.ps1`) Docker Desktop solo pasa GPU NVIDIA (WSL 2); Intel/AMD quedan en procesador.
2. `make up` (o `make video-up`): con `video.env` presente, Make arranca `compose.video.yaml`, le añade el override de la GPU detectada (`deploy/compose.video-{intel,amd,nvidia}.yaml`) y el montaje del NAS de desarrollo (`deploy/compose.video-media.dev.yaml`), y conecta el portal (`deploy/compose.portal-video.example.yaml`). `VIDEO_AUTO=0` lo omite.
3. Como propietario: **Administración → Conexión de Vídeo**. En un Jellyfin vacío eliges nombre y contraseña de su administrador (distinto de tu usuario del portal) y el portal completa el asistente inicial (español, España, sin acceso remoto). En uno ya configurado pides una cuenta administradora existente. El portal crea una clave de API (`Auth/Keys`), la guarda cifrada con `APP_KEY` (`service_connections`) y cierra la sesión usada.
4. Cada persona con Vídeo tiene su cuenta al volver a entrar en Multisuite (la contraseña del portal se sincroniza; docs/SERVICE_ACCOUNTS.md).

Comandos: `make video-detect | video-up | video-stop | video-status | video-logs | video-config`.

## Cuentas y bibliotecas
- Cuenta **no administradora**, oculta en la pantalla de inicio de Jellyfin, sin borrar contenido, sin controlar otras sesiones ni compartir. Nombre = usuario del portal; una cuenta nativa con el mismo nombre nunca se adopta (`conflict`). El portal no gestiona administradores de Jellyfin.
- `EnableAllFolders=false` y solo estas bibliotecas, comprobadas tras guardar la política:
  - **Mis vídeos** (`/personal/{uuid}`, tipo `homevideos`): la carpeta conectada «Vídeos» de Mis archivos, reflejada por enlaces duros (docs/STORAGE.md § Carpetas conectadas).
  - **Vídeos para todos** (`/common`, tipo `movies`): carpeta del NAS declarada por el operador con `VIDEO_COMMON_PATH` (solo lectura). Por defecto `docker/jellyfin/common`, vacía; en desarrollo, `.multisuite-video-common` del volumen NAS.
  - **Vídeos para todos** publicado (`/personal/comun`): lo que un administrador publica desde Administración → Para todos (S04b).
- Bloquear (desactivar el servicio o suspender): `IsDisabled`, sin bibliotecas y contraseña aleatoria descartada. Eliminar la cuenta del portal borra la de Jellyfin.

## Control parental
En la ficha de cada persona (**Personas → Gestionar**), «Vídeo: contenido permitido»: sin límite, 7, 12, 16 o 18 años. Se guarda en `users.video_max_rating`, se audita (`user.updated`, campo `video_max_rating`) y se aplica en la política de Jellyfin (`MaxParentalRating`; desde 10.9 la puntuación de las clasificaciones sigue la edad mínima). Con límite, las películas, series y TV sin clasificación quedan ocultas (`BlockUnratedItems`); los vídeos propios (tipo vídeo casero) siguen visibles. Si Jellyfin no responde, la siguiente sincronización de bibliotecas lo aplica.

## Aceleración por hardware
- `make video-detect` lee el fabricante del nodo `/dev/dri/renderD128` (Intel `0x8086`, AMD `0x1002`) o `nvidia-smi`. Si no lo confirma, **procesador** (`none`).
- El override pasa `/dev/dri` con el grupo `render` del host (Intel/AMD) o la GPU NVIDIA (NVIDIA Container Toolkit). El portal nunca recibe dispositivos: conoce el fabricante por `VIDEO_HARDWARE`.
- Opciones en Conexión de Vídeo: Intel → Quick Sync (por defecto) o VA-API; AMD → VA-API; NVIDIA → NVENC; siempre procesador. Se aplican con `System/Configuration/encoding` conservando el resto de ajustes (decodificación h264, hevc, mpeg2, vc1, vp9; dispositivo `/dev/dri/renderD128`). Si cambias de tarjeta: `make video-detect` y `make video-up`.
- AMF no se ofrece: en Linux Jellyfin usa VA-API para AMD.

## Producción (pendiente, P05)
Montar `.multisuite-media/video` como `/personal` del NAS real y `VIDEO_COMMON_PATH` a la carpeta común; publicar Jellyfin solo tras la entrada privada (VPN/TLS) con `PORTAL_VIDEO_URL` HTTPS; incluir `jellyfin-config` en la copia de seguridad. Fijar la imagen por digest.

## Pruebas
- `make test CMD="--filter=JellyfinProvisioningTest"`: conexión, opciones por fabricante, cuenta privada con límite, cambio de límite, bloqueo, borrado y nombre ya ocupado (Jellyfin simulado).
- CI (`application.yml`, «Jellyfin account integration»): asistente inicial, dos personas que solo ven sus bibliotecas, límite y bloqueo contra `jellyfin/jellyfin:10.11.11`. `ci.yml` valida los Compose renderizados y los scripts de detección (Unix y PowerShell).

## QA del usuario (lo que CI no puede comprobar)
- `make video-detect` en el servidor real: fabricante correcto y transcodificación por GPU (Panel de Jellyfin → Reproducción, o `make video-logs` al forzar una calidad menor).
- Dos personas en dos dispositivos: cada una ve sus vídeos y los comunes; una cuenta limitada no ve contenido para mayores ni películas sin clasificar.
- Apps oficiales (Android/iOS/TV) con el usuario y la contraseña del portal.
