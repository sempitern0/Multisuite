# Mis gastos

Cada persona dispone de un presupuesto mensual en euros y un registro de gastos privados con concepto, fecha, categoría, importe y justificante opcional. El menú «Mis gastos» abre el mes actual. Se puede consultar otro mes, corregir movimientos y ver el total por categoría y el saldo disponible o excedido. La administración del portal no otorga acceso a los presupuestos de otras cuentas.

## Usar una factura

1. Sube una foto JPG/PNG o un PDF a Mis archivos, por ejemplo a una carpeta privada «Facturas». Se aplican la cuota, los permisos y el tratamiento de metadatos del NAS.
2. Abre el documento y pulsa «Registrar como gasto». Si ya lo registraste, se abre el movimiento existente.
3. Pulsa «Leer fecha y total de la factura». Se intenta leer la primera página en el propio servidor. En PDF se aprovecha el texto disponible y, si la página carece de texto, se rasteriza antes de pasarla por Tesseract.
4. Contrasta las sugerencias con el justificante. Comprueba el comercio o concepto, la categoría, la fecha y el total en euros. «Guardar gasto» actualiza el presupuesto del mes correspondiente a la fecha del gasto. Un fallo de lectura permite continuar a mano.
5. El vínculo apunta al archivo concreto, sin duplicar su contenido. Eliminar el gasto conserva la factura. Purgar el archivo deja el gasto sin adjunto.

## Operación

Desde la raíz, ejecuta `make rebuild` para instalar Tesseract, los modelos de español e inglés y Poppler en el runtime. Usa `make up` para iniciar el entorno y aplicar las migraciones habituales. El procesamiento funciona a petición, con un hilo OCR por proceso, límite de cuatro lecturas por persona y minuto, 12 MiB por archivo, 20 megapíxeles por imagen y 12 segundos por comando. No hay daemon adicional ni llamadas externas desde este módulo. Las reglas existentes del asistente para contenido de Mis archivos siguen aplicándose.

Los importes se almacenan en céntimos enteros. La fecha del movimiento selecciona el mes contable. La clave de cada alta y la unicidad del justificante por propietario evitan duplicados por reintento del mismo formulario o reutilización del mismo archivo. Dos subidas independientes de un ticket son dos archivos diferentes. Para recuperar el conjunto se necesitan la base de datos, los volúmenes privados y sus claves.

## Validación

`make test CMD="--filter=PersonalBudgetTest"` cubre lectura de un ticket sintético con el OCR real, corrección antes del asiento, idempotencia, aislamiento entre personas, edición, borrado y cambio de mes. Para QA en móvil, prueba también una foto inclinada y un PDF escaneado, corrige el resultado y abre el justificante desde la lista. El importe sugerido nunca confirma un movimiento por sí solo.

## Posibles ampliaciones

Límites por categoría, gastos recurrentes, devolución de compras, exportación CSV y revisiones de facturas multipágina son incrementos independientes. El presupuesto actual cubre gastos personales en euros.
