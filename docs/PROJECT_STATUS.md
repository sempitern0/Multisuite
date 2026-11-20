# Estado de Multisuite

Instantánea del 20 de noviembre de 2026 (PR #41 y PR MCP-00 en revisión).

## Integrado en main

Portal Laravel/Livewire con personas, invitaciones, cuentas de servicio, Mis archivos, cuotas, almacenamiento y auditoría. Navidrome y Kavita disponen de acceso integrado validado por el usuario. PR #38 fusionada.

## En revisión · PR #41

Komga 1.30.1 sustituye al lector activo, preservando Kavita deshabilitado y sus datos. La rama incluye subida por fragmentos y espejo de carpetas recuperados de #40, enlaces de servicios en el menú y regreso a Multisuite desde el lector.

El incremento actual incorpora conexión administrativa desde el panel, aprovisionamiento de miembros, bibliotecas privadas, reutilización de carpetas existentes, escaneo desde el servidor y pase de sesión nativa. Los usuarios anteriores se activan al entrar con contraseña; las invitaciones nuevas al aceptarse. Sin recuperación de hashes ni credenciales en el navegador.

Validado en CI: formato, análisis estático, pruebas de aplicación, integración PostgreSQL y flujo real Komga con conexión del propietario, invitación, dos miembros y cookie nativa. La batería incluye además protección frente a sincronizaciones atrasadas tras revocar acceso y verificación de integridad del EPUB privado. Consultar el estado del último commit en PR #41 antes de fusionar. El usuario reportó confusión y un error genérico al conectar Lectura. El formulario ahora usa por defecto su cuenta de Multisuite, detecta el estado nativo y diferencia las causas del error; CI cubre también el gateway y la reconexión de una instalación inicializada. El nuevo formulario requiere QA visual.

## En revisión · MCP-00 (rama `feat/mcp-00-rfc`, apilada sobre #41)

RFC del asistente y del puente MCP ([MCP.md](MCP.md)): configuración solo del propietario, herramientas sobre los servicios del dominio con los permisos de quien las usa, `laravel/ai` para el chat con uno o varios modelos y `laravel/mcp` para clientes externos tras la red privada. Solo documentación; esperando las decisiones D1–D4 del usuario.

## Próximos pasos

Completar validación del flujo de Lectura con dos personas y dispositivos. Después, MCP-01 (núcleo del asistente). La publicación de contenido global por el administrador, passkeys y operación de producción siguen pendientes. PR #39 y #40 cerradas sin fusionar; ramas preservadas.
