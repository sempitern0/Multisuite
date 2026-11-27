# Almacenamiento: discos, espacio por usuario y subidas privadas

Documento único del dominio de almacenamiento (sustituye a STORAGE_INVENTORY, STORAGE_RESERVATIONS, PRIVATE_UPLOADS y CONTENT_STORAGE). Estado por pieza al final.

## 1. Decisión de diseño: cuotas lógicas, no particiones por usuario

**Problema.** Varias personas comparten uno o varios discos. El administrador debe poder repartir espacio desde la web sin saber de particiones.

**Opciones evaluadas.**

| Opción | Ventajas | Inconvenientes | Decisión |
| --- | --- | --- | --- |
| Partición/LV por usuario | Aislamiento duro del kernel | Espacio inmovilizado, redimensionar requiere root y riesgo, inviable desde una web, no escala con invitados | Descartada |
| Carpeta por usuario + cuota del sistema de archivos (XFS project quota, Btrfs qgroups, ZFS dataset quota) | Lo aplica el kernel, incluso a escrituras externas | Depende del FS, requiere root/capacidades que el portal no debe tener, mala visibilidad Docker Desktop/Windows | Opcional como defensa en profundidad del operador |
| **Cuotas lógicas en la base de datos + reservas atómicas + comprobación de espacio físico** | Independiente del FS, editable desde la GUI, transaccional, auditable, funciona con 1..N discos | Solo controla lo que entra por el portal | **Elegida** |

**Modelo resultante (thin provisioning seguro).**
- *Ubicación*: carpeta montada en el contenedor con marca de identidad. *Grupo de capacidad* (`group`) = un sistema de archivos/disco. Varias carpetas del mismo disco comparten grupo y nunca suman capacidad.
- *Límite de disco* (`storage_pools.limit_bytes`): máximo que el portal guardará en ese grupo. El máximo recomendado es `usado por el portal + (libre − margen de seguridad)`.
- *Cuota de persona* (`user_storage_quotas.limit_bytes`): techo personal sumando todos los discos. **No aparta espacio**: lo no usado sigue disponible para otros. Se permite prometer más que el total (sobreasignación) y la GUI lo avisa.
- *Cuota por defecto* (`storage_settings.default_user_quota_bytes`): se aplica de forma perezosa en la primera reserva a quien no tiene cuota explícita; nunca reescribe cuotas existentes. 0 = comportamiento previo (sin cuota no hay escritura).
- *Reserva*: antes de escribir se reserva el tamaño exacto; se admite solo si cabe en cuota personal, límite del disco **y** espacio físico recién observado menos reservas en curso. Ante disco desconectado, marca ausente o capacidad desconocida: se bloquea (fail closed).

Recomendación de topología para el operador:
1. Un sistema de archivos por disco físico (ext4 o XFS), montado en el host, p. ej. `/srv/nas/disk1`. Nada de RAID/pooling automático desde el portal.
2. Dentro, carpetas por función (`music`, `private`, …) montadas en el contenedor y declaradas con el mismo `group`.
3. Margen de seguridad (`reserve_bytes`) del 5–10 % del disco para que el sistema y los servicios no se queden sin espacio.
4. Opcional: XFS project quota sobre la carpeta privada como segundo cinturón. Backups en otro disco/destino (otra partición del mismo disco no es backup).

## 2. Uso desde la interfaz (propietario/administrador)

Menú **Almacenamiento** (`/admin/storage`, requiere confirmación reciente de contraseña):
- **Resumen**: límite total de los discos, ocupado, prometido (con aviso si supera lo que hay) y estado de la copia de seguridad.
- **Discos del portal** (tabla): barra con contenido del portal, otros datos, margen y libre; límite, usado y máximo recomendado; el propietario cambia el límite en la misma fila («Todo lo recomendado», «No usar este disco», 1 GB…4 TB u «Otra cantidad»). Avisos breves debajo solo si hay subidas interrumpidas o un límite por encima del espacio real.
- **Personas** (tabla con búsqueda y paginación): uso y espacio máximo editable en la fila. El propietario gestiona a cualquiera (incluido él mismo); un administrador solo a miembros. Reducir por debajo de lo usado/reservado se rechaza con mensaje junto al selector. El **espacio por defecto** para personas nuevas está junto a la búsqueda (solo propietario).
- **Discos del servidor** (tabla, solo lectura): particiones de `make nas-discover`.
- Cada cambio queda en **Actividad** (`storage.user_quota`, `storage.pool_limit`, `storage.default_quota`) con tamaño anterior y nuevo; sin rutas.
- Los miembros ven «Tu almacenamiento» en la portada cuando tienen espacio asignado. La ficha de usuario enlaza a su cuota.

La página vive en `/admin/storage`: nunca colgar rutas de Laravel de `/storage/`, que Nginx sirve como disco público (`docker/nginx/site.conf`); un test lo impide.

**Docker Desktop (Windows/macOS):** los volúmenes viven en el disco virtual de la VM de Docker (`ext4.vhdx`, ~1 TB de máximo por defecto) y `statvfs` informa de ese disco, no del físico. La página y `portal:dev-bootstrap` lo avisan al detectar el kernel `microsoft-standard`/`linuxkit`. En un servidor Linux con discos montados las cifras son exactas.

Unidades: binarias (1 GB = 1024³ bytes, como Windows). Entrada admitida: hasta 6 dígitos y 2 decimales, coma o punto. Límite interno 10^15 bytes.

La GUI **no** acepta rutas, no monta ni formatea discos y no edita `NAS_STORAGE_VOLUMES`: añadir un disco es tarea del operador (sección 3) y después aparece en la página.

## 3. Preparación

### Desarrollo (automático)
Make aplica `deploy/compose.nas-dev.yaml` salvo `NAS_DEV=0`:
- Volumen Docker `nas_private` montado en `/nas/private` en `app`, `worker` y `scheduler`. Vive en el sistema de archivos Linux de Docker, así que los permisos 0700 funcionan también con Docker Desktop en Windows.
- El servicio de un solo uso `nas-init` (root, sin red) crea la marca `private` y `.multisuite-private` (0700, propietario `DEV_UID`). Es idempotente: no reescribe la marca ni toca archivos guardados. La aplicación sigue sin generar marcas.
- Variables `NAS_*` inyectadas en los contenedores; tienen prioridad sobre `app/.env`.
- `make up`/`up-wait`/`start`/`rebuild` ejecutan `portal:dev-bootstrap` (independiente de `NAS_DEV`; `DEV_BOOTSTRAP=0` lo omite): espera a la DB, migra (si falla, termina con error), muestra las ubicaciones efectivas, fija el límite de cada disco sin configurar (todo lo disponible) y el espacio por defecto (10 GB) solo si nunca se eligió. No hace nada en producción.
- Las variables del contenedor tienen prioridad sobre `app/.env`: con discos reales usa `NAS_DEV=0`.
- Borrar el volumen (p. ej. `make reset-project`) elimina los archivos de desarrollo; la DB conserva referencias que fallarán al abrirlas. Recrear ambos juntos.

### Servidor Linux con discos reales (automatizado)
1. Monta los discos en el host como siempre (fstab/systemd). Multisuite no formatea, particiona ni monta nada.
2. `make nas-discover` (solo lectura): lista discos, particiones, sistema de archivos, punto de montaje y libre real (`lsblk`/`findmnt`), sugiere candidatos y guarda `local/nas-hardware.json`, que la página Almacenamiento muestra en **Discos del servidor**.
3. `make nas-apply TARGETS="/srv/disco1 /srv/disco2" [UPLOAD=disco2] [YES=1]`: valida que cada destino sea un punto de montaje de lectura/escritura y no del sistema; crea `<montaje>/multisuite` con su marca (ID derivado del nombre, p. ej. `disco1`) y `.multisuite-private` (0700, propietario `DEV_UID`); no sobrescribe marcas ajenas; agrupa por UUID del sistema de archivos (`fs-<uuid>`), y escribe `compose.nas.yaml` (local, ignorado por Git) con los bind mounts, `NAS_*`, una reserva de 10 GiB por volumen y el inventario montado en solo lectura. Puede necesitar `sudo` si los discos pertenecen a root. Es idempotente.
4. `make up`: Make detecta `compose.nas.yaml`, lo usa en lugar del NAS de desarrollo y `portal:dev-bootstrap` fija los límites que falten. El espacio por defecto por persona se elige en la GUI.
En Windows/Docker Desktop los scripts PowerShell solo informan: allí se usa el NAS de desarrollo.

### Operador con discos reales (manual, una vez por disco)

`NAS_STORAGE_VOLUMES` en `app/.env`, lista JSON (máx. 64). Rutas vistas **dentro del contenedor Linux**, también con Docker Desktop:

```dotenv
NAS_STORAGE_VOLUMES='[{"id":"music","group":"disk1","path":"/nas/music","reserve_bytes":53687091200},{"id":"private","group":"disk1","path":"/nas/private","reserve_bytes":53687091200}]'
```

1. Montar explícitamente cada carpeta en el servicio PHP (Compose override). Nunca la biblioteca compartida de Navidrome como ubicación privada, ni `public/`.
2. En cada raíz crear `.multisuite-volume-id` con el ID exacto. La app nunca lo genera: así un punto de montaje vacío (disco desconectado) no se confunde con el disco real.
3. `make artisan CMD="migrate"`, `make artisan CMD="config:clear"` y comprobar `make artisan CMD="portal:storage-status"` (JSON sin rutas; código 1 ante configuración inválida o volumen no observable).
4. Fijar límites en la GUI, o por consola: `portal:storage-quota pool disk1 500GB` / `portal:storage-quota user persona@example.com 50GB`.

Reglas del inventario: mismo FS declarado en grupos distintos → `configuration_conflict` y capacidad nula; marca ausente/incorrecta, error de stat o plataforma no Linux → `unavailable`. `stat.dev` no identifica discos físicos, redundancia ni topologías Btrfs/ZFS/red: ante duda se corrige la configuración, nunca se ignora.

## 4. Contrato interno de reservas (`StorageReservations`)

- `reserve(User, poolId, bytes, UUID)`: bloquea usuario → grupo → cuota → reserva (orden fijo en todas las mutaciones). Idempotente por UUID; otro usuario o parámetros distintos no reutilizan la clave. Caduca a los 30 min, pero **una reserva caducada sigue contabilizada** hasta limpieza verificada.
- `commit(User, UUID, actualBytes)`: solo el finalizador de confianza tras medir bytes reales; no supera lo reservado ni confirma tras caducar.
- `release(User, UUID)`: solo el limpiador tras detener el escritor y verificar que no quedan temporales.
- `reserveTemporary(User, pool, bytes, UUID, ttl)`: espacio para una copia temporal (ZIP) comprobado contra el disco, sin tocar la cuota (`charges_user=false`); nunca se confirma, se libera con `release()` tras verificar el borrado.
- `releaseCommitted(User, UUID)`: solo el borrado definitivo, tras verificar que el blob ya no existe; devuelve los bytes confirmados (reserva `purged`). Repetirlo no tiene efecto.
- `setUserQuota`, `setPoolLimit`, `setDefaultUserQuota`: devuelven el valor anterior (para auditoría); rechazan límites por debajo de usado + reservado.
- La baja de un usuario se bloquea mientras tenga espacio usado/reservado.
- Concurrencia certificada en CI con dos procesos contra PostgreSQL (`tests/Integration/StorageConcurrencyTest.php`). SQLite solo sirve para tests funcionales.

### Reconciliación de reservas caducadas
La página Almacenamiento muestra, por disco, las reservas caducadas que siguen contando contra la cuota:
- **Con subida privada pendiente**: `portal:uploads-cleanup` (scheduler, cada 10 min) borra `.part`/`.blob` y solo después libera. Si falla (volumen ausente, permisos, symlink), conserva la reserva y lo registra en consola.
- **Sin subida asociada**: hoy no deberían existir, porque `PrivateUploads::begin` crea la reserva y la subida en la misma transacción. Aparecerán si un futuro integrador reserva sin registro propio. No hay comando para liberarlas todavía (ROADMAP, Mis archivos y almacenamiento): no editar la base de datos a mano; comprobar primero que no quedan bytes en disco.

`portal:storage-quota` acepta bytes o unidades (`50GB`, `1,5TB`, `500MB`).

## 5. Recepción privada (S03.1)

Desactivada por defecto. Activar: ubicación dedicada con `.multisuite-volume-id` y directorio `.multisuite-private` (propietario PHP, 0700), cuotas configuradas y en `app/.env` `NAS_UPLOADS_ENABLED=true`, `NAS_UPLOAD_LOCATION=<id>`, `NAS_UPLOAD_MAX_BYTES=104857600`. Scheduler activo (`portal:uploads-cleanup` cada 10 min).

| Acción | Petición | Resultado |
| --- | --- | --- |
| Reservar | `POST /uploads` `{"key":"<UUID>","bytes":123}` | 201, idempotente |
| Recibir | `PUT /uploads/<UUID>?offset=N` binario `application/octet-stream`, en fragmentos de 8 MiB | Añade el fragmento; `409` con `received` si no continúa donde terminó el anterior; con el último, `ready` tras verificar tamaño, MIME y SHA-256 |
| Consultar | `GET /uploads/<UUID>` | Estado propio y bytes recibidos, sin rutas |
| Cancelar | `DELETE /uploads/<UUID>` | Borra restos y después libera |

Sesión del portal + CSRF. Registrar, consultar y cancelar usan el limitador `uploads` (60/min); los fragmentos, `upload-chunks` (1200/min, por encima de una red de 1 Gbit). Cada fragmento se añade a `.part` bajo el bloqueo de la subida, con fsync, y renueva la reserva 30 min; el último verifica, optimiza sin pérdida, renombra a `.blob` y confirma en la misma transacción. Una subida abandonada caduca y la limpieza la borra. Un fallo deja la reserva `pending`; nunca se libera a ciegas. El navegador reintenta cada fragmento hasta 3 veces y, tras un corte, pregunta cuánto llegó y continúa desde ahí.

## 5b. Mis archivos (S03.2)

Decisión del usuario (2026-11-13): gestor de archivos propio del portal con carpetas, en lugar de un servicio de terceros, para reutilizar cuotas, reservas, auditoría y cuentas. Alcance acotado en AGENTS.md: vista previa de **solo lectura**; sin editores ni ofimática online.

- **Rutas** (`/files`, menú «Mis archivos»): listado por carpeta, `POST /files` (reserva + registro), subida del cuerpo con el `PUT /uploads/{uuid}` existente, `GET /files/{id}` (vista previa), `GET /files/{id}/content[?download=1]` (contenido). Nunca bajo `/storage/`.
- **Modelo**: `folders` (propietario, padre, nombre; 8 niveles máx.; nombres únicos por nivel sin distinguir mayúsculas) y `user_files` (propietario, carpeta, nombre visible, `upload_id` → `private_uploads`). El nombre es solo una etiqueta: en disco el archivo sigue siendo `<uuid>.blob`.
- **Privacidad**: todas las consultas filtran por propietario y hay policies; un administrador no ve archivos ajenos (404). Carpeta ajena en una subida → 404 antes de reservar cuota.
- **Tipos admitidos** (`config/nas.php` → `allowed_mimes`, detectados con fileinfo): audio, PDF, texto/Markdown/CSV/JSON, JPEG/PNG/GIF/WebP, EPUB y ofimática (Word/Excel/PowerPoint/OpenDocument). Rechazados: comprimidos, ejecutables, HTML y SVG. Un `.md` que contenga etiquetas como `<script>` o `<html>` se detecta como HTML y se rechaza al subirlo.
- **Vista previa**: imágenes (`<img>`), PDF (visor nativo del navegador en iframe del mismo origen), Markdown renderizado con `html_input=strip` y sin enlaces inseguros, texto escapado (máx. 1 MiB). El resto, solo descarga.
- **Cabeceras de contenido**: `nosniff`, `Cache-Control: private, no-store`, `Cross-Origin-Resource-Policy: same-origin`, CSP `default-src 'none'` con `sandbox` (salvo PDF, porque los navegadores no abren su visor en sandbox). Descargas siempre `application/octet-stream` + `attachment`. Soporta peticiones Range.
- **Límites**: el único límite práctico es el espacio de cada persona (Almacenamiento). `NAS_UPLOAD_MAX_BYTES` (100 GiB por defecto) es solo un tope de seguridad por archivo. Como los archivos viajan en fragmentos de 8 MiB, Nginx (`client_max_body_size 16m`) y PHP (`post_max_size` 8M por defecto, que el fragmento no debe superar) no limitan el tamaño total. Hasta 3 archivos a la vez, cada uno en fragmentos ordenados; un fallo definitivo cancela su reserva al momento.
- **Totales de carpeta**: cada carpeta muestra sus elementos directos y el tamaño exacto acumulado (incluye subcarpetas), calculados con los bytes verificados de la base de datos; las subidas sin terminar no cuentan.
- **Papelera (S03.3)**: «Mover a la papelera» saca el archivo de su carpeta (que puede quedar vacía y borrarse) y guarda la carpeta original. Restaurar lo devuelve allí, o a la raíz si ya no existe; si el nombre está ocupado, se guarda como «nombre (2)». Mientras está en la papelera **sigue contando en la cuota**.
- **Borrado definitivo**: `PrivateUploads::purge` borra el blob, comprueba que ha desaparecido y, en la misma transacción, `StorageReservations::releaseCommitted` devuelve los bytes a la cuota y al disco (reserva `purged`, subida `purged`). Las versiones se purgan antes que el archivo, cada una en su propia transacción. Si el volumen no está disponible o hay un enlace simbólico, no se borra nada y todo sigue contado (fallo cerrado).
- **Versiones**: subir un archivo con el mismo nombre (sin distinguir mayúsculas) en la misma carpeta crea una versión. El cambio ocurre **solo cuando los bytes nuevos están verificados**: un listener síncrono de `PrivateUploadReady` dentro de la transacción de recepción. Las versiones cuentan en la cuota, se pueden descargar, restaurar (intercambio con la actual, conservando el nombre visible) o borrar.
- **Retención**: `portal:files-purge` (scheduler, diario 03:30) borra lo que lleva más de `NAS_TRASH_DAYS` (30) en la papelera y las versiones que exceden `NAS_MAX_VERSIONS` (5) por archivo. Lo que falla se reintenta al día siguiente.
- **Descargas ZIP (S03.4)**: casillas en cada carpeta/archivo y botones «Descargar selección en ZIP», «Descargar esta carpeta en ZIP» o, en la raíz, «Exportar todo en ZIP». Página **Descargas ZIP** (`/files/exports`).
  - Contenido: instantánea de los archivos actuales al pedirlo (nunca papelera ni versiones), con la estructura de carpetas; un nombre repetido dentro de una carpeta del ZIP se guarda como «nombre (2)». Sin compresión (`CM_STORE`, ZIP64 automático de libzip), así que el tamaño estimado es exacto; nunca se extrae ningún ZIP subido.
  - Espacio: `StorageReservations::reserveTemporary` reserva la estimación en el disco (límite y espacio físico) **sin cargarla a la cuota de la persona**; si no cabe, se rechaza antes de empezar. El ZIP vive en la ubicación privada (`<uuid>.zip`, 0600) con la identidad de la ubicación comprobada.
  - Generación: hasta `NAS_EXPORT_SYNC_MAX_BYTES` (50 MiB) en la propia petición; si es mayor, `portal:exports-run` (scheduler, cada minuto, en segundo plano y sin solaparse) con reclamación atómica `queued → building`, sin reintentos de cola que dupliquen trabajo. Un proceso caído se marca como fallido a las 6 h.
  - Ciclo de vida: descargable durante `NAS_EXPORT_TTL_HOURS` (24 h). Después, y al eliminarlo o si falla, se borran `.zip`/`.zip.part`, se verifica y solo entonces se libera la reserva (fallo cerrado si la ubicación no está disponible). Máximo 3 activas por persona y 10 000 archivos por ZIP. Una cuenta con ZIP activos no se puede eliminar hasta que caduquen.
- **Pendiente**: mover entre carpetas.

## 6. Contenido personal y publicación (contrato aprobado 2026-11-11)

- Privado por defecto; solo el administrador publica para todos. Propietario, ubicación física y visibilidad son conceptos distintos; publicar no duplica bytes.
- El aislamiento lo debe aplicar el servicio (web, API, clientes, descargas); ocultar enlaces no es autorización.
- Navidrome 0.64.2 (laboratorio S01, 30 comprobaciones en CI): asignación por biblioteca y aislamiento entre miembros comprobados por API; sus administradores ven todo. **No** demostrado: publicar un archivo individual sin moverlo, cuotas o subida de audio nativas, clientes móviles y streams ya abiertos. Ver `tests/isolation/navidrome.py` y la sección de aislamiento en NAVIDROME.md.
- Decisiones del usuario (2026-11-22): el espacio publicado pasa a un **fondo común**; solo un administrador retira o elimina lo publicado; al eliminar la cuenta del aportante, lo publicado se queda como propiedad común.

### Publicación para todos (S04b)

- **Quién**: cualquier persona propone desde la página de un archivo de una carpeta conectada («Proponer para todos»); solo propietario o administradores deciden en **Administración → Para todos** (`/admin/publications`, gate `publish-content`, confirmación de contraseña). Estados `requested → published` (`App\Enums\PublicationState`); rechazar o cancelar borra la propuesta.
- **Sin duplicar bytes**: la publicación apunta al blob de la subida, no al archivo. `CommonLibrary` refleja lo publicado con enlaces duros en `{volumen}/.multisuite-media/{servicio}/comun[/sección]` (`LinkMirror`, el mismo ayudante que las bibliotecas personales) y el espejo personal del aportante deja de incluirlo, así que se ve una sola vez.
- **Permisos en el servicio antes de confirmar**: `publish` enlaza y concede la biblioteca común (`CommonLibraries::ensureCommonLibrary`: Navidrome asigna la biblioteca `/personal/comun`, Komga la clave `comun-{sección}`, Jellyfin la biblioteca `/personal/comun`) a cada cuenta lista con derecho al servicio; solo entonces, en una transacción, mueve los bytes y marca `published`. Si el servicio falla se deshacen los enlaces y no se publica nada. Las cuentas nuevas reciben la biblioteca común en su siguiente sincronización, y `portal:media-sync` reconstruye la biblioteca común desde la base de datos (un enlace que no se pudo retirar desaparece en la siguiente pasada).
- **Espacio**: `StorageReservations::moveToCommon` resta los bytes de la cuota del aportante y los suma a `common_storage` (la reserva queda con `charges_user=false`; el disco sigue contándolos). Retirar (`moveFromCommon`) los devuelve y **falla cerrado** si ya no le caben: el error sugiere eliminarlo.
- **Bloqueos**: mientras está propuesto o publicado, el aportante no puede renombrarlo, moverlo a la papelera, restaurar una versión ni subir otro con el mismo nombre encima. Puede cancelar su propuesta o «Pedir que se retire» (marca visible para el administrador).
- **Baja del aportante**: `DeleteUser` llama a `Publications::releaseContributor`: borra sus propuestas, suelta su entrada de Mis archivos y conserva lo publicado sin aportante (ya no se puede retirar, solo eliminar).
- **Eliminar del NAS**: retira el enlace común, borra el archivo de Mis archivos del aportante y purga el blob con `PrivateUploads::purgeCommon` (mismas comprobaciones de fallo cerrado), liberando el fondo común.
- **Auditoría**: `content.published`, `content.rejected`, `content.withdrawn`, `content.deleted`, con servicio y tamaño; nunca nombres de archivo ni rutas.
- Pruebas: `make test CMD="--filter=PublicationsTest"` (autorización entre dos personas, fondo común, fallo del servicio, baja del aportante, auditoría).

## Fotos y vídeo en Mis archivos (S03.5)

- **Música**: los archivos de audio se escuchan con el reproductor del navegador en su página de detalle, tal como están guardados.
- **Selección múltiple**: Mayús + clic en una casilla marca o desmarca todo lo que hay entre ella y la anterior, sin mover el foco ni seleccionar texto (`resources/js/range-select.js`, contenedor `data-range-select`).
- **Estructura de la pantalla**: migas y flecha para volver a la carpeta padre; cabecera con el título, el resumen y las acciones principales (Subir archivos destacado, Nueva carpeta, descargar en ZIP y opciones de la carpeta en un diálogo); pestañas Archivos / Papelera / Descargas ZIP; barra de filtros sobre su propio panel. Al seleccionar elementos, esa barra se sustituye por la de selección (número, Descargar en ZIP, Mover a la papelera, quitar selección); las acciones de cada fila aparecen al pasar el ratón.
- **Explorador**: búsqueda por nombre en todas las carpetas de la persona (con columna de ubicación), filtro por tipo (imágenes, vídeos, audio, documentos, otros) según el MIME verificado, orden por nombre, fecha de subida, tamaño o tipo en ambos sentidos, y vistas **Detalles** (tabla), **Lista** (compacta en columnas) y **Galería** (miniaturas). La vista se recuerda por persona (`files_view` en preferencias); búsqueda, filtro y orden van en la URL. Todas las consultas filtran por propietario; como máximo 2000 archivos y 500 carpetas por vista. Las miniaturas cargan la imagen original de forma diferida (no se generan miniaturas en el servidor).

- **Subida de muchos archivos**: botón o arrastrar sobre la página. El progreso aparece en una bandeja flotante con un solo resumen (archivos terminados, MB enviados y barra total); la lista se despliega a demanda y pone los errores arriba. Al terminar sin errores se pliega y se puede cerrar. Los archivos aparecen en la lista según terminan, marcados como «Nuevo». Salir de la página con subidas en curso pide confirmación.

- **Visor de imágenes**: acercar y alejar con los botones, Ctrl/⌘ + rueda (la rueda sola desplaza la página, o mueve la imagen si está ampliada), doble clic o pellizco; arrastrar para moverse; «Tamaño real» (un píxel de la foto por píxel de pantalla); pantalla completa; teclado (`+`, `-`, `0`, flechas). Solo muestra: no edita ni guarda nada.
- **Vídeo**: se admiten MP4, WebM y MOV y los reproduce el propio navegador con sus controles (avance, velocidad, pantalla completa, imagen en imagen). El servidor entrega el archivo tal cual, por tramos (`Range`), sin convertirlo. Si el navegador no entiende el formato, se indica y se ofrece descargarlo.
- **Metadatos privados fuera** (`NAS_STRIP_IMAGE_METADATA`, activado): toda foto JPEG, PNG, GIF o WebP pierde al subir sus metadatos con ExifTool (EXIF, ubicación GPS, cámara y número de serie, fechas, autor, XMP, IPTC, comentarios y miniaturas incrustadas). Solo se conservan la orientación (para que no se vea girada) y el perfil de color (para que los colores no cambien). Los píxeles no se tocan. Falla cerrado: si no se pueden quitar (herramienta ausente, más de 60 s, foto ilegible o resultado distinto), la subida se rechaza con un aviso y no se guarda nada. Las fotos subidas antes de este cambio no se modifican. Los vídeos aún conservan sus metadatos (incluida la ubicación del móvil): pendiente en ROADMAP.
- **Optimización sin pérdida al subir** (`NAS_OPTIMIZE_IMAGES`, activada): después, un JPEG se reempaqueta con `jpegtran` antes de confirmarlo. Los píxeles y los metadatos que quedan son idénticos; cambian los bytes, así que el archivo descargado no es igual bit a bit al que se subió y ocupa algo menos. A la persona se le descuenta el tamaño final. Si la herramienta falta, tarda más de 20 s o el resultado no es la misma imagen más pequeña, se guarda el original.
- PNG, WebP y GIF no se reducen más allá de quitar sus metadatos, y el vídeo se guarda sin cambios: nunca se recodifica.
- Tamaño: sin límite práctico por archivo (tope de seguridad de 100 GiB, ver Límites); cuenta el espacio de la persona.

## Cifrado en reposo (S03.7)

Objetivo: quien obtenga la base de datos o el disco de datos, pero no la configuración secreta del servidor, no puede leer los archivos. No sustituye a los permisos del portal ni protege frente a alguien con acceso completo al servidor en marcha.

- **Qué se cifra**: el contenido de cada archivo de Mis archivos (y sus versiones) y los ZIP de Descargas. Nombres, carpetas, tamaños y tipos siguen en la base de datos sin cifrar.
- **Cómo** (`Services/Storage/FileCipher`, formato `msf1`): libsodium XChaCha20-Poly1305 en bloques de 64 KiB; cada bloque autentica la cabecera, su posición y si es el último, así que se detecta cualquier modificación, reordenación o recorte. Cada archivo tiene su propia clave, derivada con HKDF-SHA256 de la clave maestra y su identificador. Los rangos (vídeo y audio con avance) solo descifran los bloques necesarios (`Http/Responses/BlobResponse`).
- **Clave maestra**: `NAS_FILES_KEY` en `app/.env`, nunca en la base de datos ni en el disco de datos. `make up` la crea en desarrollo (`portal:dev-bootstrap` → `portal:files-key`); en producción, una vez: `make artisan CMD="portal:files-key"`. El comando nunca sustituye una clave existente. **Guarda una copia fuera del servidor**: sin ella los archivos son irrecuperables, también desde la copia de seguridad (que guarda los archivos cifrados y no incluye `.env`).
- **Sin clave**, con `NAS_ENCRYPT_FILES=true` (por defecto), el portal rechaza subidas nuevas en lugar de guardarlas sin cifrar, y los archivos cifrados no se abren. `NAS_ENCRYPT_FILES=false` desactiva el cifrado para archivos nuevos.
- **Archivos anteriores al cifrado** (`private_uploads.cipher` vacío) se siguen leyendo sin cambios; no hay migración porque el producto aún no tiene datos reales.
- **Subidas**: cada fragmento se cifra al llegar (`.enc`, bloques enteros de 64 KiB; el último puede ser más corto) y el SHA-256 se calcula sobre la marcha (estado en `private_uploads.hash_state`, recuperable tras un corte), así que no hay copia sin cifrar en disco, no se necesita el doble de espacio y el último fragmento solo renombra el archivo. El tipo se decide con el primer fragmento. Excepción: las fotos (JPEG, PNG, GIF y WebP) se guardan enteras sin cifrar mientras llegan, en la zona privada, para quitar sus metadatos (y reempaquetar un JPEG de hasta `NAS_OPTIMIZE_MAX_BYTES`), y se cifran al terminar. El navegador solo da una subida por buena cuando el servidor responde `ready`. Los ZIP se escriben directamente cifrados, sin copias intermedias.
- **Límites conocidos**: El cifrado añade 24 bytes y 16 bytes por cada 64 KiB (≈0,03 %), que no cuentan en la cuota. Rotar la clave maestra no está implementado.

## Carpetas conectadas con los servicios (S04a)

Decisiones del usuario (2026-11-15): lo que una persona sube a sus carpetas conectadas se guarda **sin cifrar y una sola vez**, y solo lo ve ella. Todo se hace desde Mis archivos; nadie copia archivos al servidor a mano.

- **Carpetas** (`Services/Files/ConnectedFolders`): en la raíz de Mis archivos de cada persona, solo para los servicios activos y a los que tiene acceso: **Música** → Navidrome (biblioteca «Mi música»), **Libros** y **Cómics** → Kavita o Komga («Mis libros», «Mis cómics»), **Vídeos** → Jellyfin («Mis vídeos», docs/JELLYFIN.md). Se crean al abrir Mis archivos y en cada sincronización; no se pueden renombrar ni borrar (`folders.media_link`, única por persona). Si la persona ya tenía una carpeta con ese nombre, la conectada se llama «Libros (conectada)» y la suya no se toca. Las subcarpetas se reflejan tal cual.
- **Formatos**: los mismos de Mis archivos; la carpeta Cómics admite además CBZ, CBR y CB7 (ZIP, RAR y 7z sin extraer: Kavita los lee así).
- **Sin cifrar**: los servicios tienen que leer el archivo. La carpeta lo avisa. El resto de Mis archivos sigue cifrado. Se marca en `private_uploads.media_link` al empezar la subida (el destino se decide antes del primer byte); un archivo no cambia de modo después.
- **Una sola copia** (`Services/Files/MediaLibrary`): el original privado (`.multisuite-private/{id}.blob`, permisos 0644 dentro de una carpeta 0700) se enlaza con un **enlace duro** en `{ubicación}/.multisuite-media/{music|reading|video}/{uuid de la cuenta}[/libros|/comics]/…`. Mismo inodo: la cuota cuenta los bytes una vez y borrar uno de los nombres no libera espacio mientras exista el otro. Requiere que ambas rutas estén en el mismo sistema de archivos (lo están: misma ubicación).
- **Nombres**: los decide el portal a partir de nombres de Mis archivos ya validados; cada segmento se sanea y nunca se usa una ruta del usuario. El servicio monta su subcarpeta de `.multisuite-media` en **solo lectura** como `/personal`.
- **Sincronización**: cada cambio (subida terminada, renombrar, papelera, restaurar, versión, borrado, cambio de acceso o suspensión, cuenta lista) programa `MediaLibrary::sync` tras la respuesta, bajo un bloqueo por persona. La pasada calcula el estado deseado desde la base de datos, crea los enlaces que faltan (nombre temporal + `rename`, atómico) y retira los sobrantes; después pide al adaptador del servicio (`Provisioning/PersonalLibraries`) que exista la biblioteca personal, asignada solo a esa cuenta, y que se escanee. Es idempotente: `portal:media-sync` (cada 15 minutos en el planificador, `--user=` para una persona) repara cualquier fallo o cambio manual.
- **Papelera y acceso**: un archivo en la papelera o borrado desaparece del servicio; al restaurarlo vuelve. Si la persona pierde el servicio o se suspende, su carpeta en el servicio se vacía; sus archivos siguen en Mis archivos.
- **Desarrollo**: `make up` monta el volumen `multisuite-nas-dev` (subruta `.multisuite-media/{music|reading|video}`) en Navidrome, Kavita y Jellyfin (`deploy/compose.{music,reading,video}-media.dev.yaml`). **Producción con discos reales**: el montaje de `/personal` aún no se genera automáticamente (ROADMAP P05); hasta entonces el operador lo añade a los compose de cada servicio.

## Copia de seguridad (S05.1)

La hace el operador en el servidor Linux con restic (DEPENDENCIES D011) en un contenedor de un solo uso. El portal no la ejecuta, no la programa y no recibe el socket Docker: solo muestra el resultado en **Almacenamiento → Copia de seguridad** (reciente, en curso, antigua —más de `NAS_BACKUP_MAX_AGE_HOURS`, 48 h— o fallida).

Qué se copia en cada `make nas-backup`:
1. Volcado `pg_dump -Fc` de la base de datos del portal (se crea en `local/backup-staging`, 0700, y se borra al terminar).
2. Los archivos privados de cada disco preparado por `make nas-apply` (`<montaje>/multisuite`, en solo lectura) y las carpetas de `BACKUP_EXTRA_PATHS` (p. ej. la biblioteca de música). En desarrollo, el volumen `nas_private`.
3. Con Vídeo configurado, el volumen de configuración de Jellyfin en su propia instantánea (`--tag jellyfin`, `source/jellyfin-config`), justo después del volcado de la base de datos: Jellyfin se detiene solo mientras se copia ese volumen, no durante la copia de los archivos del NAS, y vuelve a arrancar aunque falle. `make nas-backup-restore` la restaura junto a las demás. Para restaurarlo, copia su contenido conservando permisos al volumen vacío montado en `/config`, con Jellyfin detenido. Guarda la `APP_KEY` del portal por separado para recuperar la clave de conexión cifrada. Consulta JELLYFIN.md para el ensayo.
4. Los datos de Navidrome (`NAVIDROME_DATA_PATH`): Navidrome se detiene durante esa instantánea incremental para copiar su SQLite en reposo y vuelve a arrancar aunque la copia falle.
5. Retención (`forget --prune`: 7 diarias, 4 semanales y 6 mensuales por defecto) y comprobación (`check --read-data-subset 10%`).

Configuración, una vez:
1. `cp backup.env.example backup.env` y rellenar `RESTIC_PASSWORD` (guardar una copia **fuera del servidor**: sin ella la copia es ilegible) y un destino: `BACKUP_REPOSITORY_PATH` (disco externo ya montado) o `RESTIC_REPOSITORY` (sftp, rest, s3, b2…) con sus credenciales. Formato `CLAVE=valor` sin comillas; el script lo deja en modo 600 y Git lo ignora.
2. `make nas-backup` (la primera vez crea el repositorio) y `make nas-backup-verify`.
3. Programarla, por ejemplo con cron del usuario que administra Docker: `30 3 * * * cd /ruta/a/multisuite && make nas-backup >> local/backup.log 2>&1`.
4. Si `compose.nas.yaml` se generó antes de este cambio, repetir `make nas-apply` (mismos `TARGETS`) y `make up` para que el portal vea el resultado.

Reglas que aplica el script: se niega si el destino local está en el mismo disco físico que los datos o el repositorio (otra partición del mismo disco no es backup; `BACKUP_ALLOW_SAME_DISK=1` solo para ensayos), no monta ni formatea nada, monta los orígenes en solo lectura, usa red solo con destino remoto, no permite dos copias a la vez (`local/backup.lock`) y escribe `local/backup-status.json` sin rutas ni secretos.

### Ensayo y restauración
- `make nas-backup-verify`: restaura la última copia en un volumen temporal, carga el volcado en un PostgreSQL limpio y sin red, cuenta personas y migraciones, comprueba las marcas de los discos y borra el volumen. `FILES=0` ensaya solo la base de datos cuando no hay espacio para todos los archivos. No toca datos reales.
- `make nas-backup-snapshots` lista las copias; `make nas-backup-restore TARGET=/carpeta/vacía [SNAPSHOT=id]` restaura solo en una carpeta vacía (`TARGET/source/portal-db/portal.dump`, `source/nas/<montaje>` y `source/navidrome`).
- Restaurar: volver a poner en servicio un servidor nuevo es una operación de producción y se hace a mano y con autorización: preparar discos (`make nas-apply`), copiar `source/nas/<montaje>/` a `<montaje>/multisuite/` conservando propietario y permisos (`cp -a`), `make up`, cargar el volcado (`docker compose cp` + `pg_restore --clean --if-exists --no-owner` en el contenedor `postgres`) y, con Música detenida, copiar `source/navidrome` a `NAVIDROME_DATA_PATH`. Los secretos (`.env`, `app/.env`, `music.env`, `backup.env`) no van en la copia: guardarlos aparte, en especial `NAS_FILES_KEY` (sin ella los archivos restaurados no se pueden abrir).

Tests: `python3 tests/nas-backup.py`, `make test CMD="--filter=BackupStatusTest"`, `make test CMD="--filter=Storage"`, `--filter=PrivateUploadsTest`, `--filter=PersonalFilesTest`, `--filter=BytesTest`.
