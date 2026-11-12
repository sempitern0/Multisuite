# S02.1 — Inventario de almacenamiento de solo lectura

Primer incremento de S02. No monta discos, no crea directorios o marcas, no permite subidas, no asigna cuotas y no mueve archivos. Solo el operador puede ejecutar el diagnóstico por consola; no existe endpoint web que acepte rutas.

## Configuración
`NAS_STORAGE_VOLUMES` en app/.env es una lista JSON de hasta 64 ubicaciones. Por defecto `[]`: no se presupone dónde está el disco del usuario.

```dotenv
NAS_STORAGE_VOLUMES='[{"id":"music","group":"disk-main","path":"/nas/music","reserve_bytes":10737418240},{"id":"books","group":"disk-main","path":"/nas/books","reserve_bytes":10737418240}]'
```

Las rutas son las vistas por el contenedor Linux, incluso en Docker Desktop para Windows. No son rutas C:\\ del host. El operador debe montar explícitamente los directorios que quiera observar, inicialmente en solo lectura, y colocar en cada raíz un archivo de texto `.multisuite-volume-id` cuyo contenido sea exactamente el ID (music o books en el ejemplo). La aplicación nunca genera la marca: una ruta vacía tras desconectar el disco no debe aparentar ser el disco correcto. No copiar la marca al directorio subyacente del host.

El ejemplo no activa mounts automáticamente ni altera Navidrome. Sus volúmenes read-only actuales se conservan. No reutilizar nombres de ID para discos distintos. La marca prueba la configuración esperada, no es un identificador físico criptográfico ni detecta todas las topologías virtuales.

Tras configurar: `make artisan CMD="config:clear"` y `make artisan CMD="portal:storage-status"`. Salida JSON estable con versión, ubicaciones y grupos de capacidad; no incluye rutas ni nombres de archivos. Código 1 ante configuración inválida, volumen no observable o grupos incompatibles. Lista vacía produce éxito con cero ubicaciones, no indica que exista almacenamiento disponible.

## Capacidad compartida
- Dos carpetas del mismo sistema de archivos deben declarar el mismo group. El inventario nunca suma sus capacidades: toma el mínimo observado de total/libre y la mayor reserva configurada.
- Si stat.dev identifica un mismo sistema de archivos declarado en grupos distintos, ambos grupos quedan configuration_conflict y sus métricas de capacidad son null.
- Discos/particiones distintos pueden tener grupos independientes. No se calcula un total global que pueda confundirse con espacio intercambiable.
- Un grupo que contiene varias ubicaciones es una reserva conservadora compartida, no un pool RAID ni autorización para repartir archivos.
- stat.dev se interpreta solo en Linux y en el namespace actual del contenedor. No identifica discos físicos, redundancia, cuotas externas ni topologías de Btrfs/ZFS/red. Docker Desktop puede ocultar identidad del host: ante conflicto se corrige el inventario, nunca se ignora para habilitar escrituras.
- Marca ausente/incorrecta, error de stat o de capacidad y plataformas no soportadas dan unavailable y capacidad desconocida. No se visita el árbol ni se calcula consumo por usuario.

`headroom_bytes = max(0, free_bytes - reserve_bytes)` es una observación, NO una reserva ni una garantía. No comprueba permiso de escritura ni sirve para admitir subidas. El informe fija write_admission_enabled=false, también cuando hay espacio. Otros procesos pueden consumir bytes inmediatamente después.

## Próximo incremento S02.2
Persistir ubicaciones/grupos y consumo atribuible; implementar reservas atómicas por usuario y grupo, expiración/reconciliación, temporales y derivados, límites de tamaño y reserva del volumen. Probar concurrencia y disco lleno antes de S03. Separar consumo personal y global según política pendiente. No deducir capacidad física a partir de la suma de directorios.

## QA
1. Ejecutar sin configuración: salida vacía, sin cambios en disco.
2. En un directorio de prueba montado explícitamente, probar marca ausente, incorrecta y correcta.
3. Configurar dos carpetas del mismo FS en un grupo: capacidad no duplicada. Separarlas en grupos: error conservador.
4. Probar dos FS/particiones y retirar uno: el grupo afectado queda unavailable.
5. Reserva superior al espacio libre: headroom cero. Confirmar que nunca se habilitan escrituras.

Tests: `make artisan CMD="test --filter=StorageInventoryTest"`. Los escenarios multi-FS se simulan; la prueba de marca utiliza directorio temporal real. QA con topología física y Docker Desktop del operador sigue pendiente.
