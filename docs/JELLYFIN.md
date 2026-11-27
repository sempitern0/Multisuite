# Vídeo (Jellyfin)

Jellyfin 10.11.11 es la interfaz completa de vídeo: biblioteca, reproductor, apps y transcodificación son suyos. El portal solo prepara cuentas, bibliotecas, permisos y la aceleración por hardware. Estado: **implementado y validado en CI** (PR #47); falta QA del usuario con Docker y GPU reales.

## Puesta en marcha (desarrollo)
1. `make video-detect` (crea `video.env` desde `video.env.example` y anota la GPU). `HW=intel|amd|nvidia|none` fuerza una opción. En Windows (`scripts/video-detect.ps1`) Docker Desktop solo pasa GPU NVIDIA (WSL 2); Intel/AMD quedan en procesador.
2. `make up` (o `make video-up`): con `video.env` presente, Make arranca `compose.video.yaml`, le añade el override de la GPU detectada (`deploy/compose.video-{intel,amd,nvidia}.yaml`) y el montaje del NAS de desarrollo (`deploy/compose.video-media.dev.yaml`), y conecta el portal (`deploy/compose.portal-video.example.yaml`). `VIDEO_AUTO=0` lo omite.
3. Como propietario: **Administración → Conexión de Vídeo**. En un Jellyfin vacío eliges nombre y contraseña de su administrador (distinto de tu usuario del portal) y el portal completa el asistente inicial (español, España, sin acceso remoto). En uno ya configurado pides una cuenta administradora existente. El portal crea una clave de API (`Auth/Keys`), la guarda cifrada con `APP_KEY` (`service_connections`) y cierra la sesión usada.
4. Cuentas automáticas: al conectar, a quienes ya existen se les reserva la cuenta y se crea en su siguiente inicio de sesión en Multisuite, con la misma contraseña (las contraseñas del portal no se pueden recuperar de sus hashes). Las altas nuevas, los cambios de contraseña y de usuario se sincronizan solos; un fallo se reintenta durante una hora (docs/SERVICE_ACCOUNTS.md).

Comandos: `make video-detect | video-up | video-stop | video-status | video-logs | video-config`.

## Entrada, aspecto y vuelta a Multisuite
- Jellyfin no publica puertos: la única entrada es `video-gateway` (Nginx, `docker/video-proxy`, `127.0.0.1:${VIDEO_PORT}`), que pasa sin cambios la API, el WebSocket y los vídeos (con Range) que usan la web y las apps.
- Solo en la página de la web añade `video.css` (paleta oscura de Nocturne: fondos, acento lima, foco visible; copiada de `nocturne-tokens.css`, mantener a la par) y `video.mjs`, que coloca **← Multisuite** en la cabecera de Jellyfin junto a sus botones atrás e inicio. Igual que en Música y Lectura, lleva a `/_multisuite/portal`, que responde el propio portal (redirige a la portada; no acepta destinos arbitrarios). Se oculta con la cabecera mientras se reproduce a pantalla completa; en móvil queda solo la flecha.
- La pantalla de acceso de Jellyfin indica «Entra con tu usuario y contraseña de Multisuite» (configuración de marca aplicada al conectar); solo aparece si no hay sesión, por ejemplo en las apps.

## Entrada sin segundo inicio de sesión
Igual que Lectura: abrir Vídeo desde la portada entra directamente con la cuenta de la persona (`App\Services\VideoSession`).
1. Al iniciar sesión en Multisuite (también al aceptar una invitación o elegir la primera contraseña), mientras el portal tiene la contraseña, crea la cuenta si faltaba y pide a Jellyfin una sesión de esa cuenta con un dispositivo propio de ese navegador. Guarda solo el token, cifrado en la sesión del portal, con la huella del hash de la contraseña: si cambia, deja de valer.
2. Al abrir Vídeo, el portal emite un pase de un minuto y un solo uso, ligado a su sesión y a su origen, y lo envía por POST a `/_multisuite/launch` del gateway, que lo pasa al portal (`POST /api/video/session`).
3. El portal comprueba el pase y que el token sigue siendo de esa cuenta (no administradora, no desactivada) y responde una página en el origen de Vídeo con CSP `script-src 'self'`; `enter.js` guarda la sesión donde la web de Jellyfin guarda la suya (`localStorage` `jellyfin_credentials`, sustituyendo la de ese servidor) y abre `/web/`.
4. Si no hay sesión preparada (entrada con passkey, Jellyfin caído al entrar o token revocado), se pide una vez la contraseña en **Abrir Vídeo** (`/services/video/confirm`).
5. Cada navegador tiene un único dispositivo en Jellyfin (`multisuite-web-…`, derivado con HMAC de la persona y de una cookie cifrada y HTTP-only del portal): volver a entrar sustituye la sesión anterior en lugar de acumular otra. `portal:video-sessions-prune` (diario, 03:50) retira los dispositivos del portal sin actividad en 30 días, con su token. Cerrar sesión en Multisuite cierra en Jellyfin la sesión que abrió el portal para ese navegador. Bloquear la cuenta (desactivar el servicio o suspender) la desactiva en Jellyfin, y sus sesiones dejan de valer.
Nunca se envía una contraseña al navegador ni se acepta una URL o un usuario del cliente. Las apps de Jellyfin siguen pidiendo usuario y contraseña (los del portal).

## Cuentas y bibliotecas
- Se crea con una contraseña aleatoria descartada y recibe la de la persona solo después de guardar y comprobar su política: Jellyfin da todas las bibliotecas a las cuentas nuevas, así que una cuenta a medias no sirve para entrar. Bloquear cambia primero la contraseña y después desactiva, sin depender de crear bibliotecas.
- Cuenta **no administradora**, oculta en la pantalla de inicio de Jellyfin, sin borrar contenido, sin controlar otras sesiones ni compartir. Nombre = usuario del portal; una cuenta nativa con el mismo nombre nunca se adopta (`conflict`). El portal no gestiona administradores de Jellyfin.
- `EnableAllFolders=false` y solo estas bibliotecas, comprobadas tras guardar la política:
  - **Mis vídeos** (`/personal/{uuid}`, tipo `homevideos`): la carpeta conectada «Vídeos» de Mis archivos, reflejada por enlaces duros (docs/STORAGE.md § Carpetas conectadas). Admite MP4, WebM, MOV, AVI, MKV, MPEG-TS/PS, WMV/ASF y FLV, guardados tal cual.
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
Montar `.multisuite-media/video` como `/personal` del NAS real y `VIDEO_COMMON_PATH` a la carpeta común; publicar Jellyfin solo tras la entrada privada (VPN/TLS) con `PORTAL_VIDEO_URL` HTTPS. Fijar la imagen por digest.

## Copia y recuperación
En Linux, con `video.env` presente, `make nas-backup` copia el volumen que el contenedor monta en `/config` en una instantánea cifrada propia (`--tag jellyfin`), justo después del volcado de la base de datos del portal, que guarda la relación entre personas y cuentas. Jellyfin se detiene solo durante esa copia (minutos, no la copia completa del NAS) y vuelve a arrancar aunque falle. `make nas-backup-restore` la restaura en `source/jellyfin-config`. Para ensayar una recuperación, usa un volumen vacío, la misma versión de Jellyfin, las mismas rutas de bibliotecas y la `APP_KEY` del portal original. En el ensayo local con restic 0.19.1 se verificaron los archivos restaurados y se arrancó un segundo Jellyfin 10.11.11 que conservaba el identificador del servidor y admitía la contraseña de la cuenta sintética. El destino externo y los pasos de restauración del NAS se describen en STORAGE.md.

## Pruebas
- La prueba de integración sube un MP4 sintético por las rutas de Mis archivos. Comprueba que aparece en el catálogo real de su propietario y que otra persona no lo encuentra. Al cambiar los enlaces, el adaptador solicita a Jellyfin un escaneo de biblioteca. Una revisión del contenido por carpeta evita repetir el escaneo en cada sincronización y caduca al cabo de un día.
- `make test CMD="--filter=JellyfinProvisioningTest"`: conexión, opciones por fabricante, cuenta privada con límite, cambio de límite, bloqueo, borrado y nombre ya ocupado (Jellyfin simulado).
- CI (`application.yml`, «Jellyfin account integration and gateway»): asistente inicial, dos personas que solo ven sus bibliotecas, límite y bloqueo contra `jellyfin/jellyfin:10.11.11`; el gateway añade estilo y vuelta solo al HTML, deja la API intacta y enruta `/_multisuite/portal`; `tests/jellyfin-web-session.mjs` carga en Chrome la sesión con `enter.js` y comprueba que la web real de Jellyfin entra sin pedir contraseña, con el aspecto de Multisuite y el botón ← Multisuite en su cabecera; capturas en el artefacto `jellyfin-screens`. `ci.yml` valida los Compose renderizados y los scripts de detección (Unix y PowerShell).

## QA del usuario (lo que CI no puede comprobar)
- `make video-detect` en el servidor real: fabricante correcto y transcodificación por GPU (Panel de Jellyfin → Reproducción, o `make video-logs` al forzar una calidad menor).
- Dos personas en dos dispositivos: cada una ve sus vídeos y los comunes; una cuenta limitada no ve contenido para mayores ni películas sin clasificar.
- Apps oficiales (Android/iOS/TV) con el usuario y la contraseña del portal, apuntando al gateway.
- Abrir Vídeo desde la portada en el navegador real: entra sin pedir contraseña; aspecto en inicio, biblioteca y ajustes; botón ← Multisuite en escritorio y móvil; al cerrar sesión en Multisuite, Jellyfin vuelve a pedir acceso.
