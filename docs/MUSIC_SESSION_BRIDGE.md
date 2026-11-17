# Abrir Navidrome desde Multisuite

Con una cuenta musical gestionada y preparada, **Abrir Música** abre la biblioteca personal directamente. Funciona también tras entrar en el portal con passkey o segundo factor. El acceso directo con las credenciales habituales sigue disponible para clientes Subsonic y navegadores sin sesión del gateway.

## Instalación y QA

1. Actualiza la rama y ejecuta `make up` y `make artisan CMD="config:clear"`. El entorno local con `music.env` activa el gateway automáticamente. Conserva los volúmenes y las cuentas musicales existentes.
2. En una ventana privada entra como A y pulsa **Abrir Música**. Comprueba el usuario, reproduce un tema y avanza en la pista. Volver a abrir Música como A debe conservar la cola.
3. Sal de Multisuite. Una nueva petición por el gateway con la sesión musical de A debe quedar denegada. Una pista ya descargada o en reproducción puede continuar.
4. En el mismo navegador entra como B y pulsa **Abrir Música**. Comprueba que muestra a B y su biblioteca sin pedir contraseña y sin la cola de A. Prueba también una cuenta sin permiso y una pendiente de preparación.
5. Para salir solo de Música usa el cierre de sesión de Navidrome. Si quedó una cookie caducada, vuelve a abrir Música desde el portal o visita `/_multisuite/logout` en el origen musical antes de usar el login directo.

## Funcionamiento

El portal emite un pase cifrado válido durante 60 segundos. Una redirección automática de ida y vuelta vincula el pase final al navegador mediante una cookie temporal de desafío. El navegador lo retira del fragmento de la URL y lo canjea una sola vez. Laravel comprueba la sesión original, el permiso, el estado local y que el UUID remoto todavía corresponde al mismo nombre de usuario y a una cuenta musical ordinaria. Un fallo de comprobación impide abrir Música.

El resultado es una cookie opaca de sesión, HttpOnly, SameSite=Strict, limitada al host y Secure con HTTPS. El gateway consulta al portal en cada petición gestionada, comprueba que la sesión original sigue activa y envía a Navidrome el nombre verificado. Las peticiones musicales no prolongan la sesión del portal. Tras su caducidad hay que volver a abrir Música. El proxy conserva las peticiones parciales de audio y el streaming. El acceso directo de clientes externos mantiene su propio ciclo de sesión.

La autorización usa la caché compartida y el almacén de sesiones del servidor de Laravel (la configuración del proyecto usa base de datos). Navidrome confía solo en la IP /32 del gateway en la red dedicada. El puerto interno 4533 queda sin publicar. Gestiona las altas, renombrados y bajas de las cuentas vinculadas desde Multisuite. Las pestañas de un mismo origen comparten la cuenta activa: para utilizar A y B simultáneamente usa perfiles de navegador separados.

La comprobación remota usa la lectura del propio usuario mediante un socket Unix privado entre Laravel y el gateway. Make prepara su volumen Docker también en Windows. Navidrome comprueba que ese usuario puede leer el UUID solicitado y Laravel contrasta nombre y rol. Las credenciales administrativas se reservan para el aprovisionamiento. En despliegues personalizados monta el socket en ambos contenedores según los overlays de ejemplo.

Si falla el acceso automático, la transición Nocturne muestra el motivo disponible y abre el formulario nativo después de limpiar las credenciales y la cola del navegador. Si la cookie anterior sigue activa, se detiene y ofrece reintentar. Se puede volver al portal durante la transición.

## Configuración

- El overlay integrado activa `NAVIDROME_AUTOLOGIN_ENABLED`. Para conservar únicamente la limpieza de sesión anterior, configura esa variable a `false` en el archivo de entorno raíz, ejecuta `make up` y limpia la caché de configuración. Para usar un Navidrome externo sin gateway desactiva también `NAVIDROME_SESSION_BRIDGE_ENABLED`.
- Solo si existe un conflicto de redes, ajusta `MUSIC_AUTH_SUBNET` y `MUSIC_AUTH_GATEWAY_IP` en `music.env` a una subred libre y una IP de host dentro de ella.
- En producción usa un hostname HTTPS exclusivo para Música, publica solo el gateway y enruta también `/_multisuite/*` en la raíz. Conserva la red privada entre el gateway y el portal, los alias de Compose y la URL interna de aprovisionamiento. Las cookies se comparten entre puertos de un mismo host.
