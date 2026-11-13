# Puente de sesión de Música

## Resultado
Al pulsar **Abrir Música**, una cuenta gestionada y preparada pasa por una página breve en el mismo origen de Navidrome:
- Si userId y username coinciden con la cuenta remota del usuario del portal, conserva la sesión y la cola.
- Si no coinciden, elimina las claves de autenticación de Navidrome y su estado persistido (incluye cola y selección de biblioteca), y abre la interfaz de Navidrome para iniciar sesión.
- Si el pase es inválido/caducado, el portal no responde o localStorage está bloqueado, no continúa a una cuenta sin verificar. Volver al portal y abrir Música de nuevo.

No es SSO: no inicia una sesión nueva automáticamente. No se borran playlists, favoritos, historial ni música del servidor. El borrado de estado local también puede restablecer preferencias musicales incluidas en state.

## Actualizar el entorno existente
En la rama del hito:

    git pull --ff-only
    make up
    make artisan CMD="config:clear"

Sin migraciones, secretos nuevos ni copia manual de overlays. music.env activa los overlays de Make. Compose recrea Navidrome sin publicar su puerto directamente y publica music-gateway en el mismo 127.0.0.1:NAVIDROME_PORT; conservar el mismo hostname del navegador mantiene su almacenamiento. El gateway se inicia después de Navidrome. NAVIDROME_INTERNAL_URL sigue apuntando al Navidrome interno, no al gateway.

NAVIDROME_SESSION_BRIDGE_ENABLED en el .env raíz controla el modo integrado (predeterminado true). El overlay lo pasa a app; tiene prioridad sobre app/.env. Para una URL musical externa que no use este gateway, poner false en el .env raíz y ejecutar make up + config:clear. Fuera del overlay, app/.env lo desactiva por defecto; habilitarlo solo cuando el destino tenga el gateway.
make music-up, stop/start/restart/down conservan la composición; MUSIC_AUTO=0 y compose.music.yaml autónomo siguen disponibles, pero no habilitan el puente. No lanzar el Compose autónomo a la vez que el gateway en el mismo puerto.

Las cuentas antiguas sin vinculación musical no tienen identidad remota verificable y conservan el enlace directo. No se adopta una cuenta por coincidencia de correo. Enlazarlas queda en P03-06c.

## Contrato y seguridad
1. /services/music exige autenticación, correo verificado y el cambio inicial de contraseña; verifica inclusión del servicio y estado ready.
2. Emite un pase cifrado/autenticado con APP_KEY, referencia de usuario/cuenta, destino configurado y caducidad de 60 s. No contiene contraseña ni token musical.
3. El pase viaja en el fragmento #ticket=…; el navegador lo elimina del historial antes de verificarlo. No se envía en la URL HTTP ni en Referer.
4. El gateway recibe un POST JSON sin cookies ni Authorization, y reenvía únicamente al endpoint fijo /api/music/session/verify. No permite elegir otro upstream. Laravel vuelve a comprobar estado, usuario, verificación, cambio inicial, inclusión del servicio, configuración y caducidad. No crea cookies de sesión.
5. La respuesta solo contiene UUID remoto, username y destino. El navegador exige que el destino tenga su mismo origen y sea HTTP(S), sin credenciales ni ruta del puente.
6. Las claves locales siguen el contrato de Navidrome 0.64.2. Nunca se envían los tokens existentes a Multisuite. El gateway no inyecta identidad ni activa ExtAuth.

El pase no concede acceso musical ni sirve como JWT. Es reutilizable durante esos 60 s y solo autoriza esta comprobación/limpieza local; no es una sesión ni un mecanismo de revocación. Se omiten logs de acceso del gateway para no registrar parámetros Subsonic. El puente envía no-store, no-referrer y CSP restrictiva; la CSP no se aplica a Navidrome. No se habilita CORS para leer el verificador desde otro origen.

## Límites
- Protege el flujo **Abrir Música**. Un acceso directo a la URL de Navidrome conserva su autenticación propia.
- Pestañas musicales ya abiertas pueden conservar estado/token en memoria y volver a escribir almacenamiento. Para QA A/B cerrar las pestañas musicales anteriores; gestionar simultaneidad y cierre coordinado queda para SSO. El puente no es una frontera de seguridad contra quien controla el navegador.
- Otros dispositivos, clientes móviles y tokens del servidor no se cierran.
- No controla cuentas no gestionadas ni usuarios externos creados directamente en Navidrome.
- El mismo navegador comparte localStorage entre pestañas de un origen. Para personas que deban usar cuentas simultáneamente, usar perfiles de navegador diferentes.
- No prometer privacidad del contenido ya abierto en otras pestañas o guardado por el navegador; no se borra todo el almacenamiento/caché del origen.

## Producción
El ejemplo sigue siendo local. Publicar el gateway exclusivamente tras TLS/VPN y configurar PORTAL_MUSIC_URL con el origen HTTPS real; el launcher rechaza destinos HTTP en producción. El proxy exterior debe conservar las rutas /_multisuite/* en la raíz del origen además de las rutas Navidrome. El BaseUrl musical se conserva tal cual; no hay reescritura de prefijos.
Conectar gateway y portal web en una red privada con alias multisuite-portal-web. El Compose de producción aún requiere adaptar esa red/entrada; no se activa automáticamente por este hito. No publicar el puerto de Navidrome directamente si se pretende que el gateway sea la entrada.
Copiar montajes estáticos de docker/music-proxy a una imagen inmutable en la preparación de release P05. Validar certificado, puertos, backups y clientes antes de ofrecer acceso fuera del equipo.

## QA pendiente (navegador real)
1. Actualizar, ejecutar make up y comprobar make music-status: Navidrome y music-gateway activos.
2. Usuario A: abrir Música e iniciar sesión. Volver al portal y abrir Música otra vez: debe conservar cuenta y cola.
3. Cerrar la pestaña musical, salir del portal y entrar como B: abrir Música debe pedir login de Navidrome, sin mostrar la cuenta ni cola de A. Entrar con B.
4. Repetir B → A. Comprobar que playlists/favoritos de ambos siguen intactos al entrar en cada cuenta.
5. Cuenta sin preparar: botón bloqueado. Pase caducado o alterado: mensaje breve; no abrir cuenta anterior.
6. Almacenamiento bloqueado o portal detenido durante verificación: no continuar; volver a iniciar desde portal al recuperarlo.
7. Reproducir audio, avanzar/retroceder, probar portada/álbumes y BaseUrl si está configurado. Comprobar cliente Subsonic sin pasar por launcher.
8. Documentar comportamiento de pestañas simultáneas (límite anterior), móvil, zoom 200 % y navegación con teclado.
