# Descargas (P04.3)

Descargar vídeos y audio de webs con [MeTube](https://github.com/alexta69/metube), una interfaz web
mantenida para yt-dlp, y guardarlos en Mis archivos. Multisuite no tiene descargador propio.

**Estado:** implementado y validado en CI (pasarela con la imagen real de MeTube y flujo del portal
en pruebas). Pendiente: QA del usuario.

## Quién lo usa
Solo **propietario y administradores** (decisión del usuario del 2026-11-28). MeTube no tiene cuentas:
su cola y su historial son comunes a quien lo abre, por eso no se ofrece a las demás personas.

## Cómo funciona
- `compose.downloads.yaml` (stack `multisuite-downloads`), opcional: existe si hay `downloads.env`
  (copia de `downloads.env.example`); `make up` lo arranca y da al portal la carpeta de espera.
  `make downloads-up|stop|status|logs` para manejarlo aparte.
- `metube` (2026.11.24) no publica puertos ni está en la red compartida: solo lo alcanza la pasarela.
  Sin opciones de yt-dlp por descarga (permitirían ejecutar órdenes), nombres de archivo seguros y
  tamaño máximo por descarga (`DOWNLOADS_MAX_FILE_BYTES`, 20 GiB por defecto). Lo parcial y su
  estado quedan fuera de la carpeta de espera.
- `downloads-gateway` (Nginx, `docker/downloads-proxy/nginx.conf`) es la entrada (`DOWNLOADS_PORT`,
  en loopback; con la red privada, un nombre privado). En cada petición pregunta al portal
  (`/api/downloads/check`) si su cookie pertenece a un owner o administrador activo; si no, vuelve al
  portal. La cookie del portal nunca llega a MeTube.
- Entrar: la tarjeta **Descargas** de la portada (página común de entrada a servicios,
  `App\Support\ServiceLaunch`, la misma que Lectura y Vídeo) envía un ticket de un minuto y un solo uso, atado a la
  sesión del portal y a su página, a `/_multisuite/launch`; el portal responde con una cookie cifrada
  para el origen de la pasarela (`DownloadsAccess`). Dura 8 horas y se anula al cambiar la contraseña
  o el rol, al suspender la cuenta o al quitarle la administración.
- Guardar: **Administración → Descargas por guardar** lista lo terminado (sin ocultos, parciales ni
  enlaces). «Guardar» lo copia a la carpeta elegida de *tu* Mis archivos como una subida normal
  (cuota, tipos permitidos, cifrado; en Música o Vídeos aparece también en Navidrome o Jellyfin) y
  lo quita de la espera. Si no cabe o el tipo no se admite, sigue en espera y no queda espacio
  reservado; se puede volver a intentar (un intento cancelado nunca bloquea el siguiente). «Descartar» lo borra. Los archivos se eligen por un identificador opaco, nunca por ruta
  (`StagedDownloads`).
- La carpeta de espera es el volumen Docker `multisuite-downloads` (lo crea Make), montado en MeTube
  y en el portal (`deploy/compose.portal-downloads.example.yaml`); nunca una ruta del host.

## Límites
- Lo que está en espera no cuenta en ninguna cuota: vigila el espacio del volumen (`make status`,
  Almacenamiento) y guarda o descarta lo terminado.
- Las cookies de webs (contenido con inicio de sesión) no se configuran desde Multisuite.
- Respeta los términos de cada web y los derechos de autor de lo que descargues.

## Pruebas
- `tests/downloads-smoke.py` (CI): MeTube real tras la pasarela con un portal simulado; sin sesión
  todo vuelve al portal (también los archivos), el lanzamiento fija la cookie y MeTube responde con
  ella (página y conexión en vivo).
- `DownloadsTest`: solo owner/administradores, ticket de un uso atado a la página del portal, sesión
  anulada al cambiar la contraseña; guardar con cuota y tipos, sin reserva tras un fallo, reintento
  tras un tipo rechazado, descartar y archivos ocultos, parciales y enlaces nunca ofrecidos.
- Manual: descargar un vídeo y un audio reales y guardarlos en Vídeos y Música.
