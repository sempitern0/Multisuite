# Estado de Multisuite

Instantánea del 20 de noviembre de 2026. Se reescribe al cerrar cada incremento; el detalle vive en git y en las PR.

## Integrado en main
Portal Laravel/Livewire con personas, invitaciones, cuentas de servicio, Mis archivos (carpetas conectadas con los servicios), cuotas, almacenamiento y auditoría. Música con Navidrome y Lectura con Komga 1.30.1 (PR #41; Kavita desactivado y reactivable), ambos con cuentas automáticas y bibliotecas privadas. Validado en CI; QA de Komga con dos personas pendiente.

## En revisión: asistente y puente MCP (docs/MCP.md)
- **PR #42 MCP-00**: RFC con las decisiones D1–D4 (el owner elige quién lo usa, claves en el entorno, solo proveedores externos, historial de 30 días). Solo documentación.
- **MCP-01** (rama `feat/mcp-01-core`, apilada sobre #42): `laravel/mcp` v1.2.1; servidor `MultisuiteServer` sin ruta pública; herramientas de solo lectura `identity_me`, `services_list`, `storage_my_usage`, `files_list` y `files_search` que actúan siempre como la persona de la sesión; acceso, herramienta activa y límite diario comprobados en cada llamada; registro sin argumentos (`assistant_tool_calls`, 90 días); página *Administración › Asistente* solo para el owner. Implementado; validación en CI (en el entorno del agente no hay PHP 8.4 para ejecutar las pruebas).

## Próximos pasos
QA de Komga; revisar y fusionar #42 y MCP-01; después MCP-02 (chat con `laravel/ai`).

## Acciones pendientes del usuario
- Ejecutar `make composer CMD="audit --locked"` tras instalar MCP-01.
- Guardar `NAS_FILES_KEY` fuera del servidor y ensayar la copia (`make nas-backup`, `make nas-backup-verify`).

## Límites conocidos
- Producción no desplegada: faltan VPN/TLS, copia programada y piloto (P03/P05). El MCP externo depende de P03.
- El asistente aún no conversa: solo existen sus herramientas y su configuración.
- Las sesiones abiertas en Navidrome o Komga siguen hasta caducar tras retirar el acceso.
