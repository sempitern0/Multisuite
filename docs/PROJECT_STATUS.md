# Estado de Multisuite

Instantánea del 19 de noviembre de 2026.

## Integrado en main

Portal Laravel/Livewire con personas, invitaciones, cuentas de servicio, Mis archivos, cuotas, almacenamiento y auditoría. Navidrome y Kavita disponen de acceso integrado validado por el usuario. PR #38 fusionada.

## En revisión · PR #41

Komga 1.30.1 sustituye al lector activo, preservando Kavita deshabilitado y sus datos. La rama incluye subida por fragmentos y espejo de carpetas recuperados de #40, enlaces de servicios en el menú y regreso a Multisuite desde el lector.

El incremento actual incorpora conexión administrativa desde el panel, aprovisionamiento de miembros, bibliotecas privadas, reutilización de carpetas existentes, escaneo desde el servidor y pase de sesión nativa. Los usuarios anteriores se activan al entrar con contraseña; las invitaciones nuevas al aceptarse. Sin recuperación de hashes ni credenciales en el navegador.

Validado localmente: sintaxis JS, ciclo Make simulado y diff. PHP/Docker no están disponibles en este entorno; la batería de aplicación e integración real se valida en CI antes de dar el incremento por terminado. El usuario validó el lector del piloto, pero reportó errores de autenticación; este nuevo flujo aún requiere su QA visual tras pasar CI.

## Próximos pasos

Completar validación del flujo de Lectura con dos personas y dispositivos. La publicación de contenido global por el administrador, passkeys y operación de producción siguen pendientes. PR #39 y #40 cerradas sin fusionar; ramas preservadas.
