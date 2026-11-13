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

Menú **Almacenamiento** (`/storage`, requiere confirmación reciente de contraseña):
- **Discos**: barra con contenido del portal, otros datos, margen y libre; límite actual y máximo recomendado. El propietario elige el límite con opciones predefinidas («Todo lo recomendado», «No usar este disco», 1 GB…4 TB) u «Otra cantidad» (número + MB/GB/TB).
- **Reparto**: total de límites, total prometido, reparto equitativo sugerido y aviso de sobreasignación.
- **Espacio por defecto** para personas nuevas (solo propietario).
- **Personas**: barra de uso y selector de espacio máximo. El propietario gestiona a cualquiera (incluido él mismo); un administrador solo a miembros. Reducir por debajo de lo usado/reservado se rechaza con mensaje junto al formulario.
- Cada cambio queda en **Actividad** (`storage.user_quota`, `storage.pool_limit`, `storage.default_quota`) con tamaño anterior y nuevo; sin rutas.
- Los miembros ven «Tu almacenamiento» en la portada cuando tienen espacio asignado. La ficha de usuario enlaza a su cuota.

Unidades: binarias (1 GB = 1024³ bytes, como Windows). Entrada admitida: hasta 6 dígitos y 2 decimales, coma o punto. Límite interno 10^15 bytes.

La GUI **no** acepta rutas, no monta ni formatea discos y no edita `NAS_STORAGE_VOLUMES`: añadir un disco es tarea del operador (sección 3) y después aparece en la página.

## 3. Preparación del operador (una vez por disco)

`NAS_STORAGE_VOLUMES` en `app/.env`, lista JSON (máx. 64). Rutas vistas **dentro del contenedor Linux**, también con Docker Desktop:

```dotenv
NAS_STORAGE_VOLUMES='[{"id":"music","group":"disk1","path":"/nas/music","reserve_bytes":53687091200},{"id":"private","group":"disk1","path":"/nas/private","reserve_bytes":53687091200}]'
```

1. Montar explícitamente cada carpeta en el servicio PHP (Compose override). Nunca la biblioteca compartida de Navidrome como ubicación privada, ni `public/`.
2. En cada raíz crear `.multisuite-volume-id` con el ID exacto. La app nunca lo genera: así un punto de montaje vacío (disco desconectado) no se confunde con el disco real.
3. `make artisan CMD="migrate"`, `make artisan CMD="config:clear"` y comprobar `make artisan CMD="portal:storage-status"` (JSON sin rutas; código 1 ante configuración inválida o volumen no observable).
4. Fijar límites en la GUI, o por consola: `portal:storage-quota pool disk1 <bytes>` / `portal:storage-quota user persona@example.com <bytes>`.

Reglas del inventario: mismo FS declarado en grupos distintos → `configuration_conflict` y capacidad nula; marca ausente/incorrecta, error de stat o plataforma no Linux → `unavailable`. `stat.dev` no identifica discos físicos, redundancia ni topologías Btrfs/ZFS/red: ante duda se corrige la configuración, nunca se ignora.

## 4. Contrato interno de reservas (`StorageReservations`)

- `reserve(User, poolId, bytes, UUID)`: bloquea usuario → grupo → cuota → reserva (orden fijo en todas las mutaciones). Idempotente por UUID; otro usuario o parámetros distintos no reutilizan la clave. Caduca a los 30 min, pero **una reserva caducada sigue contabilizada** hasta limpieza verificada.
- `commit(User, UUID, actualBytes)`: solo el finalizador de confianza tras medir bytes reales; no supera lo reservado ni confirma tras caducar.
- `release(User, UUID)`: solo el limpiador tras detener el escritor y verificar que no quedan temporales.
- `setUserQuota`, `setPoolLimit`, `setDefaultUserQuota`: devuelven el valor anterior (para auditoría); rechazan límites por debajo de usado + reservado.
- La baja de un usuario se bloquea mientras tenga espacio usado/reservado.
- Concurrencia certificada en CI con dos procesos contra PostgreSQL (`tests/Integration/StorageConcurrencyTest.php`). SQLite solo sirve para tests funcionales.

## 5. Recepción privada (S03.1, sin interfaz todavía)

Desactivada por defecto. Activar: ubicación dedicada con `.multisuite-volume-id` y directorio `.multisuite-private` (propietario PHP, 0700), cuotas configuradas y en `app/.env` `NAS_UPLOADS_ENABLED=true`, `NAS_UPLOAD_LOCATION=<id>`, `NAS_UPLOAD_MAX_BYTES=104857600`. Scheduler activo (`portal:uploads-cleanup` cada 10 min).

| Acción | Petición | Resultado |
| --- | --- | --- |
| Reservar | `POST /uploads` `{"key":"<UUID>","bytes":123}` | 201, idempotente |
| Recibir | `PUT /uploads/<UUID>` binario `application/octet-stream` | `ready` tras verificar tamaño, MIME (MPEG/FLAC/OGG/WAV, PDF, texto) y SHA-256 |
| Consultar | `GET /uploads/<UUID>` | Estado propio, sin rutas |
| Cancelar | `DELETE /uploads/<UUID>` | Borra restos y después libera |

Sesión del portal + CSRF, 20 peticiones/min. Escritura `.part` exclusiva → fsync → rename a `.blob` → commit en la misma transacción. Un fallo deja la reserva `pending`; nunca se libera a ciegas. Nginx/PHP pueden almacenar el cuerpo antes de Laravel: ajustar `client_max_body_size` y límites PHP antes de admitir archivos grandes. Sin multipart, chunks ni reanudación todavía.

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
| Recepción privada HTTP (S03.1) | Integrado (PR #11), sin interfaz |
| GUI de almacenamiento y cuota por defecto (S02.3) | Rama `feat/s02-storage-admin-gui`, pendiente de CI y QA visual |
| Reconciliación de confirmados, borrado con decremento, interfaz de subida, bibliotecas por usuario, publicación, movimientos y backup | Pendiente (ROADMAP S03–S05) |

Tests: `make test CMD="--filter=Storage"`, `make test CMD="--filter=PrivateUploadsTest"`, `make test CMD="--filter=BytesTest"`.
