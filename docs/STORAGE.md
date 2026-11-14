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
- **Discos**: barra con contenido del portal, otros datos, margen y libre; límite actual y máximo recomendado. El propietario elige el límite con opciones predefinidas («Todo lo recomendado», «No usar este disco», 1 GB…4 TB) u «Otra cantidad» (número + MB/GB/TB).
- **Reparto**: total de límites, total prometido, reparto equitativo sugerido y aviso de sobreasignación.
- **Espacio por defecto** para personas nuevas (solo propietario).
- **Personas**: barra de uso y selector de espacio máximo. El propietario gestiona a cualquiera (incluido él mismo); un administrador solo a miembros. Reducir por debajo de lo usado/reservado se rechaza con mensaje junto al formulario.
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
- `releaseCommitted(User, UUID)`: solo el borrado definitivo, tras verificar que el blob ya no existe; devuelve los bytes confirmados (reserva `purged`). Repetirlo no tiene efecto.
- `setUserQuota`, `setPoolLimit`, `setDefaultUserQuota`: devuelven el valor anterior (para auditoría); rechazan límites por debajo de usado + reservado.
- La baja de un usuario se bloquea mientras tenga espacio usado/reservado.
- Concurrencia certificada en CI con dos procesos contra PostgreSQL (`tests/Integration/StorageConcurrencyTest.php`). SQLite solo sirve para tests funcionales.

### Reconciliación de reservas caducadas
La página Almacenamiento muestra, por disco, las reservas caducadas que siguen contando contra la cuota:
- **Con subida privada pendiente**: `portal:uploads-cleanup` (scheduler, cada 10 min) borra `.part`/`.blob` y solo después libera. Si falla (volumen ausente, permisos, symlink), conserva la reserva y lo registra en consola.
- **Sin subida asociada**: hoy no deberían existir, porque `PrivateUploads::begin` crea la reserva y la subida en la misma transacción. Aparecerán si un futuro integrador reserva sin registro propio. No hay comando para liberarlas todavía (ROADMAP S02): no editar la base de datos a mano; comprobar primero que no quedan bytes en disco.

`portal:storage-quota` acepta bytes o unidades (`50GB`, `1,5TB`, `500MB`).

## 5. Recepción privada (S03.1)

Desactivada por defecto. Activar: ubicación dedicada con `.multisuite-volume-id` y directorio `.multisuite-private` (propietario PHP, 0700), cuotas configuradas y en `app/.env` `NAS_UPLOADS_ENABLED=true`, `NAS_UPLOAD_LOCATION=<id>`, `NAS_UPLOAD_MAX_BYTES=104857600`. Scheduler activo (`portal:uploads-cleanup` cada 10 min).

| Acción | Petición | Resultado |
| --- | --- | --- |
| Reservar | `POST /uploads` `{"key":"<UUID>","bytes":123}` | 201, idempotente |
| Recibir | `PUT /uploads/<UUID>` binario `application/octet-stream` | `ready` tras verificar tamaño, MIME (MPEG/FLAC/OGG/WAV, PDF, texto) y SHA-256 |
| Consultar | `GET /uploads/<UUID>` | Estado propio, sin rutas |
| Cancelar | `DELETE /uploads/<UUID>` | Borra restos y después libera |

Sesión del portal + CSRF, 20 peticiones/min. Escritura `.part` exclusiva → fsync → rename a `.blob` → commit en la misma transacción. Un fallo deja la reserva `pending`; nunca se libera a ciegas. Nginx/PHP pueden almacenar el cuerpo antes de Laravel: ajustar `client_max_body_size` y límites PHP antes de admitir archivos grandes. Sin multipart, chunks ni reanudación todavía.

## 5b. Mis archivos (S03.2)

Decisión del usuario (2026-11-13): gestor de archivos propio del portal con carpetas, en lugar de un servicio de terceros, para reutilizar cuotas, reservas, auditoría y cuentas. Alcance acotado en AGENTS.md: vista previa de **solo lectura**; sin editores ni ofimática online.

- **Rutas** (`/files`, menú «Mis archivos»): listado por carpeta, `POST /files` (reserva + registro), subida del cuerpo con el `PUT /uploads/{uuid}` existente, `GET /files/{id}` (vista previa), `GET /files/{id}/content[?download=1]` (contenido). Nunca bajo `/storage/`.
- **Modelo**: `folders` (propietario, padre, nombre; 8 niveles máx.; nombres únicos por nivel sin distinguir mayúsculas) y `user_files` (propietario, carpeta, nombre visible, `upload_id` → `private_uploads`). El nombre es solo una etiqueta: en disco el archivo sigue siendo `<uuid>.blob`.
- **Privacidad**: todas las consultas filtran por propietario y hay policies; un administrador no ve archivos ajenos (404). Carpeta ajena en una subida → 404 antes de reservar cuota.
- **Tipos admitidos** (`config/nas.php` → `allowed_mimes`, detectados con fileinfo): audio, PDF, texto/Markdown/CSV/JSON, JPEG/PNG/GIF/WebP, EPUB y ofimática (Word/Excel/PowerPoint/OpenDocument). Rechazados: comprimidos, ejecutables, HTML y SVG. Un `.md` que contenga etiquetas como `<script>` o `<html>` se detecta como HTML y se rechaza al subirlo.
- **Vista previa**: imágenes (`<img>`), PDF (visor nativo del navegador en iframe del mismo origen), Markdown renderizado con `html_input=strip` y sin enlaces inseguros, texto escapado (máx. 1 MiB). El resto, solo descarga.
- **Cabeceras de contenido**: `nosniff`, `Cache-Control: private, no-store`, `Cross-Origin-Resource-Policy: same-origin`, CSP `default-src 'none'` con `sandbox` (salvo PDF, porque los navegadores no abren su visor en sandbox). Descargas siempre `application/octet-stream` + `attachment`. Soporta peticiones Range.
- **Límites**: `NAS_UPLOAD_MAX_BYTES` (100 MiB por defecto) debe ser ≤ `client_max_body_size` de Nginx (110 MiB). Subidas secuenciales con progreso; un fallo cancela su reserva al momento.
- **Totales de carpeta**: cada carpeta muestra sus elementos directos y el tamaño exacto acumulado (incluye subcarpetas), calculados con los bytes verificados de la base de datos; las subidas sin terminar no cuentan.
- **Papelera (S03.3)**: «Mover a la papelera» saca el archivo de su carpeta (que puede quedar vacía y borrarse) y guarda la carpeta original. Restaurar lo devuelve allí, o a la raíz si ya no existe; si el nombre está ocupado, se guarda como «nombre (2)». Mientras está en la papelera **sigue contando en la cuota**.
- **Borrado definitivo**: `PrivateUploads::purge` borra el blob, comprueba que ha desaparecido y, en la misma transacción, `StorageReservations::releaseCommitted` devuelve los bytes a la cuota y al disco (reserva `purged`, subida `purged`). Las versiones se purgan antes que el archivo, cada una en su propia transacción. Si el volumen no está disponible o hay un enlace simbólico, no se borra nada y todo sigue contado (fallo cerrado).
- **Versiones**: subir un archivo con el mismo nombre (sin distinguir mayúsculas) en la misma carpeta crea una versión. El cambio ocurre **solo cuando los bytes nuevos están verificados**: un listener síncrono de `PrivateUploadReady` dentro de la transacción de recepción. Las versiones cuentan en la cuota, se pueden descargar, restaurar (intercambio con la actual, conservando el nombre visible) o borrar.
- **Retención**: `portal:files-purge` (scheduler, diario 03:30) borra lo que lleva más de `NAS_TRASH_DAYS` (30) en la papelera y las versiones que exceden `NAS_MAX_VERSIONS` (5) por archivo. Lo que falla se reintenta al día siguiente.
- **Pendiente**: mover entre carpetas, ZIP (S03.4).

## 6. Contenido personal y publicación (contrato aprobado 2026-11-11)

- Privado por defecto; solo el administrador publica para todos. Propietario, ubicación física y visibilidad son conceptos distintos; publicar no duplica bytes.
- El aislamiento lo debe aplicar el servicio (web, API, clientes, descargas); ocultar enlaces no es autorización.
- Navidrome 0.64.2 (laboratorio S01, 30 comprobaciones en CI): asignación por biblioteca y aislamiento entre miembros comprobados por API; sus administradores ven todo. **No** demostrado: publicar un archivo individual sin moverlo, cuotas o subida de audio nativas, clientes móviles y streams ya abiertos. Ver `tests/isolation/navidrome.py` y la sección de aislamiento en NAVIDROME.md.
- Pendiente de decidir con el usuario antes de S04: imputación de contenido global a cuotas, borrado de contenido publicado y baja del aportante.

## 7. Estado

| Pieza | Estado |
| --- | --- |
| Inventario de solo lectura (S02.1) | Integrado (PR #9) |
| Reservas y cuotas transaccionales (S02.2) | Integrado (PR #10), concurrencia PostgreSQL en CI |
| Recepción privada HTTP (S03.1) | Integrado (PR #11) |
| Mis archivos: carpetas, subida web, vista previa y descarga (S03.2) | Integrado (PR #13) |
| Descubrimiento de discos del servidor y panel de solo lectura (S02.4) | Integrado (PR #16); pendiente prueba en servidor real |
| Papelera, borrado verificado, versiones y retención (S03.3) | Integrado (PR #17) |
| GUI de almacenamiento y cuota por defecto (S02.3) | Rama `feat/s02-storage-admin-gui`, pendiente de CI y QA visual |
| ZIP, mover entre carpetas, reconciliación, bibliotecas por usuario, publicación, movimientos y backup | Pendiente (ROADMAP S03–S05) |

Tests: `make test CMD="--filter=Storage"`, `--filter=PrivateUploadsTest`, `--filter=PersonalFilesTest`, `--filter=BytesTest`.
