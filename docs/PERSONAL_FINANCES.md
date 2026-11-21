# Mis finanzas

Excepción de alcance decidida por el usuario el 2026-11-21 (AGENTS.md): cada persona lleva sus **ingresos y gastos personales** en el portal, como en una aplicación de finanzas sencilla. Sin conexión con bancos, sin cuentas compartidas, sin conversión de monedas ni inversiones; eso sigue siendo de servicios de terceros.

## Qué puede hacer cada persona
- **Resumen anual:** ingresos, gastos y balance del año, mes a mes, y totales por categoría. Selector de los últimos 10 años y el siguiente.
- **Mes:** movimientos con fecha, concepto, categoría, importe y factura opcional; presupuesto de gastos del mes con lo que queda o lo que se ha superado.
- **Movimientos:** gasto o ingreso, con categorías fijas guardadas por clave (`App\Support\Finance\Categories`), así que cambiar un nombre no altera el historial.
- **Moneda y formato:** EUR, USD, GBP, CHF, MXN, ARS, COP, CLP, BRL, CAD o JPY, y formato de cantidades `1.234,56 €` (es_ES), `€1,234.56` (en_US), `1 234,56 €` (fr_FR) o `€ 1’234.56` (de_CH). Se guarda en las preferencias de la persona. Cada movimiento conserva la moneda con que se guardó: **nada se convierte**; los totales suman solo la moneda elegida y avisan si hay movimientos en otras.
- **Facturas:** se suben a Mis archivos (cuota, permisos y limpieza de metadatos del NAS). Desde la factura, «Registrar en Mis finanzas» abre el formulario; «Leer fecha y total» la lee en segundo plano y sugiere fecha e importe, que la persona revisa antes de guardar. Una factura se registra una sola vez por persona; abrirla de nuevo edita el movimiento.

Nadie más ve las finanzas de una persona, tampoco la administración. El operador puede ocultar el módulo a todos con `PORTAL_FINANCES=false`.

## Código
- Migración `2026_11_26_000000_create_personal_finances`: `personal_transactions` (tipo, categoría, importe en unidades mínimas de su moneda, moneda, fecha, factura opcional, clave de alta) y `monthly_budgets` (mes y moneda).
- Modelos `PersonalTransaction` (alcance `ownedBy`, siempre el punto de partida de una lectura) y `MonthlyBudget`; `PersonalTransactionPolicy` (solo la propietaria edita o borra).
- Actions `RecordTransaction` (alta o corrección; idempotente por clave de alta y por factura, con bloqueo de la factura), `DeleteTransaction`, `SetMonthlyBudget`. `FinanceSummary` calcula año y presupuesto. La página `pages::finances` solo valida y pinta.
- `MoneyFormat` (extensión `intl` de PHP): formato por moneda y país, decimales de cada moneda (JPY sin decimales) y lectura de lo que escribe la persona sin pasar por números con coma flotante. Acepta cualquiera de los dos separadores: un último grupo de 1-2 cifras son decimales y uno de 3, miles (`12.5` → 12,50; `1.234` → 1234; `1,234.56` → 1234,56). No se ofrecen monedas de 3 decimales para evitar la ambigüedad.
- OCR: `ReadReceipt` (job, 2 intentos, 60 s) usa `ReceiptReader` (pdftotext, pdftoppm y tesseract como procesos aparte, 12 s por orden, un hilo, 12 MiB y 20 megapíxeles como máximo) y deja el resultado 15 minutos en la caché; la página lo consulta cada 2 s. Los fallos esperados (`ReceiptUnreadable`) muestran su causa; los demás se reintentan y acaban en un mensaje genérico.

## Límites
`finances` 60 acciones por minuto y `receipt-ocr` 4 lecturas por minuto por persona. Los importes son enteros (hasta 12 cifras).

## Privacidad con el asistente
Las facturas son archivos de Mis archivos: si una persona acepta que el asistente lea el texto de sus archivos, también puede leer sus facturas y enviarlas al proveedor de IA. Los movimientos de Mis finanzas no están al alcance del asistente.

## Validación
`make test CMD="--filter=PersonalFinanceTest"`: lectura en segundo plano con el OCR real y un ticket sintético, revisión antes de guardar, una sola vez por factura, fallo de lectura explicado, resumen anual por meses y categorías, cambio de moneda y formato sin conversión, aislamiento entre personas, presupuesto y el interruptor del operador. A mano (móvil): una foto inclinada y un PDF escaneado.

## Posibles ampliaciones
Gastos recurrentes, exportación CSV, límites por categoría, facturas de varias páginas y que el asistente consulte los totales (con una herramienta que reutilice `FinanceSummary`).
