# Estado de Multisuite

Instantánea del 20 de noviembre de 2026. Se reescribe al cerrar cada incremento; el detalle vive en git y en las PR.

## Integrado en main
Portal Laravel/Livewire con personas, invitaciones, cuentas de servicio, Mis archivos (carpetas conectadas con los servicios), cuotas, almacenamiento y auditoría. Música con Navidrome y Lectura con Komga 1.30.1 (Kavita desactivado y reactivable), con cuentas automáticas y bibliotecas privadas. Validado en CI; QA de Komga con dos personas pendiente.

## En revisión: asistente (docs/MCP.md)
- **PR #42 MCP-00**: RFC y decisiones D1–D4 (el owner elige quién lo usa y configura proveedores y claves en Multisuite; solo proveedores externos; historial de 30 días). Solo documentación.
- **PR #44 MCP-01 + MCP-02** (apilada sobre #42):
  - `laravel/mcp` v1.2.1 con herramientas de solo lectura (`identity_me`, `services_list`, `storage_my_usage`, `files_list`, `files_search`), siempre como la persona de la sesión, con acceso, interruptor y límite diario comprobados en cada llamada.
  - `laravel/ai` v1.4.1: página **Asistente** con respuesta progresiva e historial privado; proveedores externos dados de alta por el owner con clave cifrada de solo escritura, prueba de conexión y conmutación por error; límite diario de tokens; purga a 30 días.
  - Validado en CI. Pendiente: QA del usuario con un proveedor real y `composer audit`.

## Próximos pasos
QA del asistente con un modelo real (MCP.md § Probar con un modelo real) y de Komga; revisar y fusionar #42 y #44; después MCP-03 (contenido de archivos) y MCP-04 (escritura con aprobación).

## Acciones pendientes del usuario
- `make up` y conectar un proveedor desde *Ajustes del asistente*.
- `make composer CMD="audit --locked"`.
- Guardar `NAS_FILES_KEY` y `APP_KEY` fuera del servidor (sin `APP_KEY` las claves de IA no se pueden descifrar) y ensayar la copia (`make nas-backup`, `make nas-backup-verify`).

## Límites conocidos
- Producción no desplegada: faltan VPN/TLS, copia programada y piloto (P03/P05). El MCP externo depende de P03.
- El asistente solo consulta metadatos: no lee contenido de archivos ni cambia nada.
- Las preguntas y los nombres de archivos consultados se envían al proveedor elegido.
- Las sesiones abiertas en Navidrome o Komga siguen hasta caducar tras retirar el acceso.
