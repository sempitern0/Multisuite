# S02.2 — Reservas y cuotas contables

Base: PR #9 (inventario), PR nueva dependiente. No admite subidas ni crea, mueve o elimina archivos. Implementa el registro transaccional que utilizarán posteriormente los escritores de S03. El inventario sigue declarando write_admission_enabled=false.

## Configuración por operador
Aplicar migración: `make artisan CMD="migrate --force"`. Configurar ubicaciones/marcas según STORAGE_INVENTORY.md. Por defecto no hay grupos contables ni cuotas; no se concede capacidad ilimitada.

```text
make artisan CMD="portal:storage-quota pool disk-main 107374182400"
make artisan CMD="portal:storage-quota user persona@example.com 10737418240"
```

El grupo debe existir y ser observable en el inventario. Límite 0 deniega nuevas reservas. Límites expresados en bytes enteros hasta 10^15; sin unidades implícitas. No se puede reducir por debajo de usado + reservado. Esta consola es para el operador con acceso al servidor; no hay endpoint de autoservicio ni edición de cuotas por miembros.

## Contrato interno
- `reserve(User, poolId, bytes, UUID)` bloquea usuario, grupo y cuota en ese orden. Comprueba cuota global del usuario (entre grupos), límite del grupo y headroom recién observado menos reservas del grupo.
- La misma clave y parámetros devuelve la misma operación, incluso terminal o caducada; nunca vuelve a reservar. Otro usuario o parámetros distintos no puede reutilizarla. El integrador debe inspeccionar estado y vencimiento antes de actuar.
- `commit(User, UUID, actualBytes)` lo llama SOLO el finalizador de confianza tras comprobar bytes reales. Tamaño final no puede exceder lo reservado ni confirmarse tras caducar. Transfiere reserva a consumo, devuelve sobrante y admite reintento idéntico.
- `release(User, UUID)` lo llama SOLO un limpiador tras detener escritor y confirmar ausencia de temporales. Repetir no libera dos veces. No permite liberar una reserva ya confirmada.
- Caducidad: 30 minutos. No existe un cron que recupere capacidad ciegamente: una reserva vencida sigue contabilizada hasta limpieza explícita. El timeout no demuestra que los bytes hayan desaparecido.
- El consumo confirmado permanece contabilizado. Borrado de contenido y decremento del consumo deben implementarse junto a verificación de eliminación física en S03/S05; no hay comando que borre contadores para eludir cuota.
- Mientras exista usado/reservado, la baja del usuario se bloquea antes de eliminar su cuenta musical. Una cuota vacía se limpia al eliminar el usuario; el historial terminal de reservas conserva referencia al grupo y usuario nulo.

El límite contable del grupo es independiente de su capacidad física; no se reserva disco a nivel del sistema operativo. Procesos externos pueden consumir espacio después de comprobarlo. El futuro escritor debe manejar ENOSPC, limitar bytes y validar permisos de escritura. Un grupo declarado sobre varios FS usa una observación conservadora y no distribuye archivos por sí solo. No cambia el tratamiento de bibliotecas ya existentes: esos bytes reducen espacio físico, pero no se atribuyen automáticamente a un usuario.

## Concurrencia y validación
Feature tests: reintentos, capacidad compartida entre usuarios, cuota entre grupos, caducidad, titularidad, baja y reducción de límites. CI ejecuta además dos procesos PHP simultáneos contra PostgreSQL desechable: ambos solicitan 700 bytes de un grupo con 1000; solo uno puede reservar y los contadores deben quedar en 700. La prueba protege migrate:fresh con nombre de DB y opt-in exclusivos de CI. No ejecutarla contra bases reales.

SQLite sirve para pruebas funcionales, no para certificar bloqueos de fila en producción. El contrato de concurrencia se valida con PostgreSQL. Los tests no comprueban todavía escritura física concurrente, extensiones de reservas, reconciliación de bytes existentes ni cuota personal/global tras publicar contenido.

## Siguiente incremento
Antes de habilitar subidas: integrar ubicación concreta, permisos, staging privado, límites de archivo, finalizador que mide bytes y limpiador que verifica la eliminación; añadir reconciliación y auditoría de operaciones. Resolver política de imputación de contenido global. No conectar reserve a una subida que pueda seguir escribiendo tras release ni confiar actualBytes al cliente.
