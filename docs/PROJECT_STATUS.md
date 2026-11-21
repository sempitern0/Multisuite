# Estado de Multisuite

Instantánea del 21 de noviembre de 2026. Se reescribe al cerrar cada incremento; el detalle vive en git y en las PR.

## Integrado en main
- Portal Laravel/Livewire con personas, invitaciones, cuentas de servicio, Mis archivos (carpetas conectadas con los servicios), cuotas, almacenamiento y auditoría. Música con Navidrome y Lectura con Komga 1.30.1 (Kavita desactivado y reactivable), con cuentas automáticas y bibliotecas privadas. Validado en CI; QA de Komga con dos personas pendiente.
- Interacciones de Nocturne y navegación móvil (PR #43). Paleta revisada en OKLCH para los temas claro y oscuro, sin colores escritos a mano, y botón sol/luna en la barra superior y en el acceso (docs/design-system.md § Color). Casillas como filas completas con interruptores para ajustes generales e insignias de rol con nivel de permisos.
- Mis finanzas (PR #46): ingresos y gastos personales con resumen anual y gráfico mensual, presupuesto por mes, moneda y formato por persona, y tickets subidos desde la propia página o desde Mis archivos con lectura local en segundo plano (JPG, PNG, WebP, PDF). Validado en CI y por el usuario (lectura de tickets) ([PERSONAL_FINANCES.md](PERSONAL_FINANCES.md)).
- Personajes de perfil (PR #45): 18 imágenes propias, elegibles en Perfil con confirmación si hay foto, y uno al azar para cada cuenta nueva (USER_MANAGEMENT.md).
- **Asistente y puente MCP** (docs/MCP.md; PR #42 con el RFC y decisiones D1–D4, PR #44 con MCP-01 a MCP-07), validado en CI y pendiente de QA del usuario con un proveedor real:
  - `laravel/mcp` v1.2.1 con herramientas que actúan siempre como la persona de la sesión, con acceso, interruptor y límite diario comprobados en cada llamada (`AssistantAccess::toolDenial`).
  - `laravel/ai` v1.4.1: página **Asistente** y botón flotante con panel de chat (ocultable por persona), respuesta progresiva, historial privado de 30 días; proveedores externos dados de alta por el owner con clave cifrada de solo escritura, prueba de conexión y conmutación por error; límite diario de tokens.
  - Lectura del texto de los archivos propios (texto, Markdown, CSV, JSON y PDF con `pdftotext`) solo si el owner lo permite y cada persona lo acepta.
  - Cambios reversibles en Mis archivos (crear carpetas, renombrar, papelera, restaurar), apagados por defecto, con aprobación de la persona en el chat y una sola respuesta a la vez por conversación.
  - Búsqueda en Música (Navidrome) y Lectura (Komga) limitada a las bibliotecas de cada persona.
  - Búsqueda por significado en los archivos de quien acepte la lectura (embeddings del proveedor, texto cifrado, cada 15 minutos; una caída del proveedor deja los archivos pendientes para la siguiente pasada).
  - Clientes MCP externos por OAuth (Passport), solo consultas, con interruptor del owner y desconexión por persona; pendiente de probar con clientes reales tras P03.

## Próximos pasos
QA del asistente con un modelo real (MCP.md § Probar con un modelo real) y de Komga; los dos pendientes de la revisión de calidad (ROADMAP); después P03 (red privada) para probar los clientes externos y MCP-08 (piloto).

## Acciones pendientes del usuario
- `make rebuild` (añade `pdftotext`), `make composer CMD="install"` y `make up` (migraciones y claves OAuth); conectar un proveedor desde *Ajustes del asistente*.
- `make composer CMD="audit --locked"`.
- Guardar `NAS_FILES_KEY` y `APP_KEY` fuera del servidor (sin `APP_KEY` las claves de IA no se pueden descifrar) y ensayar la copia (`make nas-backup`, `make nas-backup-verify`).

## Límites conocidos
- Producción no desplegada: faltan VPN/TLS, copia programada y piloto (P03/P05). `/mcp` no debe exponerse sin P03.
- La búsqueda por significado compara vectores en PHP (sin pgvector): límite de 6.000 fragmentos por persona.
- El asistente solo hace cambios reversibles en Mis archivos y con aprobación; lee contenido solo con doble permiso.
- Las preguntas, los nombres de archivos consultados y, con permiso, el texto leído se envían al proveedor elegido.
- Las sesiones abiertas en Navidrome o Komga siguen hasta caducar tras retirar el acceso.
