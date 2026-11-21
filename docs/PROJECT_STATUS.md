# Estado de Multisuite

Instantánea del 20 de noviembre de 2026. Se reescribe al cerrar cada incremento; el detalle vive en git y en las PR.

## Integrado en main
Portal Laravel/Livewire con personas, invitaciones, cuentas de servicio, Mis archivos (carpetas conectadas con los servicios), cuotas, almacenamiento y auditoría. Música con Navidrome y Lectura con Komga 1.30.1 (Kavita desactivado y reactivable), con cuentas automáticas y bibliotecas privadas. Validado en CI; QA de Komga con dos personas pendiente.

## En revisión: asistente (docs/MCP.md)
- **PR #42 MCP-00**: RFC y decisiones D1–D4 (el owner elige quién lo usa y configura proveedores y claves en Multisuite; solo proveedores externos; historial de 30 días). Solo documentación.
- **PR #44 MCP-01 a MCP-07** (apilada sobre #42):
  - `laravel/mcp` v1.2.1 con herramientas de solo lectura (`identity_me`, `services_list`, `storage_my_usage`, `files_list`, `files_search`), siempre como la persona de la sesión, con acceso, interruptor y límite diario comprobados en cada llamada.
  - `laravel/ai` v1.4.1: página **Asistente** con respuesta progresiva e historial privado; proveedores externos dados de alta por el owner con clave cifrada de solo escritura, prueba de conexión y conmutación por error; límite diario de tokens; purga a 30 días.
  - Lectura del texto de los archivos propios (texto, Markdown, CSV, JSON y PDF con `pdftotext`) solo si el owner lo permite y cada persona lo acepta.
  - Cambios reversibles en Mis archivos (crear carpetas, renombrar, papelera, restaurar), apagados por defecto y siempre con aprobación de la persona en el chat.
  - Búsqueda en Música (Navidrome) y Lectura (Komga) limitada a las bibliotecas de cada persona.
  - Búsqueda por significado en los archivos de quien acepte la lectura (embeddings del proveedor, texto cifrado, cada 15 minutos).
  - Clientes MCP externos por OAuth (Passport), solo consultas, con interruptor del owner y desconexión por persona; pendiente de probar con clientes reales tras P03.
  - Validado en CI. Pendiente: QA del usuario con un proveedor real y `composer audit`.

## Próximos pasos
QA del asistente con un modelo real (MCP.md § Probar con un modelo real) y de Komga; revisar y fusionar #42 y #44; después P03 (red privada) para probar los clientes externos y MCP-08 (piloto).

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
