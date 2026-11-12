# S03.1 — Conectar reservas y archivos privados

Incremento dependiente de PR #10 (y #9). Implementa recepción HTTP sin interfaz de selección todavía. No publica ni escanea contenido en Navidrome y no modifica sus mounts.

## Preparación del operador

1. Aplicar migraciones con `make artisan CMD="migrate"`.
2. Configurar una ubicación NAS dedicada, persistente y accesible por PHP. **Nunca usar la biblioteca compartida de Navidrome, public/ ni un directorio servido por HTTP**. No se añaden mounts ni se escriben marcadores automáticamente.
3. En esa ubicación, mantener `.multisuite-volume-id` según STORAGE_INVENTORY.md. Crear `.multisuite-private` con propietario PHP y permisos 0700. Archivos creados con 0600. Se rechazan enlaces simbólicos en directorio privado/archivos; la raíz canónica y el ID quedan vinculados a cada operación. Administrador del host y procesos del mismo UID son de confianza; no pueden modificar rutas durante operaciones. Volúmenes Windows deben soportar permisos Unix efectivos dentro del contenedor; se rechaza de forma conservadora si no los soportan.
4. Configurar cuotas usuario/grupo según STORAGE_RESERVATIONS.md. En `app/.env`: `NAS_UPLOADS_ENABLED=true`, `NAS_UPLOAD_LOCATION=<id>` y `NAS_UPLOAD_MAX_BYTES=104857600` (100 MiB por defecto). Ejecutar `make artisan CMD="config:clear"`.
5. Mantener scheduler activo. `portal:uploads-cleanup` se ejecuta cada diez minutos; también se puede ejecutar con `make artisan CMD="portal:uploads-cleanup"`.

La activación es explícita porque montar una biblioteca compartida rompería la privacidad. Desactivar admisión permite todavía consultar/cancelar y limpiar pendientes. Conservar ubicación y permisos hasta que no queden operaciones; mover/reconfigurar requiere reconciliación.

## Contrato HTTP

Sesión autenticada/verificada, cambio inicial de contraseña y CSRF del portal; límite 20 peticiones/minuto. No es una API pública ni usa credenciales musicales. Enviar `Accept: application/json`.

| Acción | Petición | Resultado |
| --- | --- | --- |
| Reservar | `POST /uploads`, JSON `{"key":"<UUID nuevo>","bytes":123}` | ID y estado; 201. Repetir mismo UUID/tamaño no reserva de nuevo |
| Recibir | `PUT /uploads/<UUID>`, `Content-Type: application/octet-stream`, cuerpo binario | `ready` tras verificación y confirmación |
| Consultar | `GET /uploads/<UUID>` | ID/estado propios, sin rutas ni hashes |
| Cancelar pendiente | `DELETE /uploads/<UUID>` | Elimina restos antes de liberar; repetible |

El cliente debe reservar **antes** del PUT. Tamaño exacto obligatorio, máximo según configuración y cuota. Nombres/rutas/MIME proporcionados por el cliente no se utilizan. Se verifican bytes y MIME del archivo completo mediante fileinfo; admitidos MPEG/FLAC/OGG/WAV, PDF y texto plano. No se renderiza, descarga, extrae ni ejecuta contenido. MIME no equivale a análisis antivirus. Un PUT repetido de una operación ya `ready` devuelve ese estado sin modificar el archivo: para otro contenido usar otro UUID.

## Fallos, concurrencia y limpieza

La recepción mantiene bloqueo transaccional de usuario durante el stream y serializa cancelaciones/escrituras del mismo usuario, también entre procesos. Penaliza operaciones simultáneas de esa cuenta; este incremento acota archivos a 100 MiB por defecto. No reintentar automáticamente transacciones que consumen streams. PostgreSQL es el motor previsto para concurrencia real.

Se escribe `.part` en exclusivo, comprueban tamaño/MIME/SHA-256, hace flush/fsync y rename en el mismo directorio a `.blob`. Confirmación de reserva y estado `ready` comparten transacción. Error de escritura, formato, conexión o confirmación deja reserva y estado `pending`: nunca se libera a ciegas desde un catch. Cancelación explícita o limpiador de caducadas adquiere el mismo bloqueo, elimina ambos nombres y solo entonces libera. Un crash tras rename y antes del commit también se recupera. Si falta volumen, cambió ubicación, hay symlinks o no se puede eliminar, conserva cuota y retorna fallo. No borra archivos `ready` ni modifica consumo confirmado.

Una transacción DB y un filesystem no ofrecen atomicidad física conjunta frente a cualquier corte eléctrico. El incremento recupera intentos sin confirmar; auditoría/reconciliación de archivos confirmados ausentes, corrupción o restauración de backups sigue pendiente. No borrar archivos manualmente para reducir cuotas.

## Límites de la recepción web

Nginx/PHP pueden almacenar el cuerpo antes de entrar en Laravel, incluso usando PUT binario. La reserva controla el destino privado, **no** el directorio temporal del servidor HTTP. Ajustar `client_max_body_size`, límites/timeout de PHP/FPM, almacenamiento temporal y límites por VPN antes de habilitar cargas grandes. No se cambian aquí esos límites existentes; una petición puede recibir 413 antes de Laravel. Sin uploads multipart/Livewire ni soporte chunk/resumible todavía.

## QA

- Usuario A con cuota: reservar y enviar texto de prueba; comprobar `ready`, una sola contabilización y ningún cambio en Navidrome.
- Usuario B: consultar/enviar/cancelar UUID de A debe fallar; no conocer rutas.
- Repetir reserva/PUT y comprobar que no duplica consumo; un UUID cancelado no se reutiliza.
- Exceder tamaño reservado, enviar menos bytes o HTML: no confirmar; cancelar y comprobar recuperación de cuota.
- Simular resto `.part`/`.blob` de operación pendiente caducada: limpiador elimina antes de liberar.
- Quitar marcador o permisos del volumen: conservar reserva; restituir y repetir limpieza.
- No usar bibliotecas reales para estas pruebas. No existe aún botón de subida, descarga, borrado de confirmados ni publicación global. Siguiente incremento: interfaz privada y reconciliación antes de integrar bibliotecas por usuario.
