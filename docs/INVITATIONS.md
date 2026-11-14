# Invitaciones privadas y roles

No existen GET/POST /register, pantalla de registro libre, códigos manuales ni el antiguo comando portal:invite. La portada y el login no enlazan a registro. Recuperación de contraseña, 2FA, passkeys y ajustes siguen disponibles porque protegen cuentas existentes.

## Permisos
| Rol | Invitar miembros | Invitar administradores | Crear propietarios por web |
| --- | --- | --- | --- |
| Propietario (`owner`) | Sí | Sí | No |
| Administrador (`admin`) | Sí | No | No |
| Miembro (`member`) | No | No | No |

Gestión exige login, email verificado, confirmación reciente de contraseña, Gate y límite de peticiones. Administradores no pueden consultar, sustituir ni revocar invitaciones de administrador. El rol no es asignable masivamente ni se edita en perfil. Las cuentas anteriores reciben `member`; no se promueven automáticamente.

## Preparación
1. `make artisan CMD="migrate"`. La migración incremental invalida códigos anteriores sin emisor: deben reemitirse.
2. Desde consola de confianza, convertir una cuenta existente verificada en primer propietario: `make artisan CMD="portal:owner propietario@example.com"`.
3. En una instalación vacía: `make artisan CMD="portal:owner propietario@example.com --create"`; nombre y contraseña se solicitan interactivamente. Esta es la única excepción de bootstrap al alta por invitación y solo está disponible al operador del servidor, mientras no exista propietario. No ejecutar dos bootstrap simultáneamente.
4. Configurar APP_URL con el dominio HTTPS definitivo y el transporte MAIL. En local usar Mailpit y revisar su bandeja. No enviar invitaciones reales con MAIL_MAILER=log ni guardar enlaces en logs compartidos.

## Flujo
Desde **Invitaciones**, seleccionar correo y rol permitido y pulsar Enviar invitación. El correo debe estar en minúsculas. El destinatario recibe un enlace firmado, aleatorio, de uso único, con duración de 7 días; solo introduce nombre y contraseña. Correo y rol se muestran como texto y se leen de la base de datos.

La firma se verifica en GET y POST sin ignorar parámetros. Cambiar token, caducidad o añadir parámetros invalida la URL. Inyectar correo/rol/emisor en el formulario se rechaza. El servidor comprueba caducidad, revocación, uso previo y permisos vigentes del emisor. Un emisor eliminado, no verificado o degradado invalida sus invitaciones incompatibles. Consumo y creación ocurren en una transacción con bloqueo y actualización condicional.

Reenviar a un correo pendiente reemplaza su token: el enlace anterior deja de funcionar. Revocar invalida el enlace, no elimina cuentas. Si el transporte de correo falla, no se anuncia éxito: comprobar configuración y volver a emitir. El enlace prueba acceso al buzón, por lo que la nueva cuenta queda verificada. Es una credencial: quien lo posea puede aceptar la invitación; el destinatario no debe reenviarlo. El servidor solo guarda el hash del token; usar logs de proxy que oculten rutas de aceptación/query strings. La pantalla responde con no-store y no-referrer.

Estos roles solo afectan al portal. Navidrome conserva usuarios y permisos independientes. No se han modificado usuarios ni enviado mensajes reales durante el desarrollo.

## Pruebas
`make test CMD="--filter=InvitationTest"`: endpoints de registro retirados, permisos por rol, firma manipulada, rol/correo inyectados, revocación, reemisión y uso único. Flujo confirmado por el usuario (PR #2). Pendiente: doble envío simultáneo con PostgreSQL real.

Referencia: Laravel 13 URL Generation (temporarySignedRoute) y Authorization (Gates), consultadas 2026-11-09. Sin dependencias añadidas.

## Estados en la lista
`Invitation::status()` (y su forma SQL `scopeStatus()`): *aceptada* si se usó; *pendiente* si `isAcceptable()` (sin usar, sin caducar y emitida por alguien que aún puede invitar ese rol); *caducada o anulada* en el resto. Solo las pendientes se pueden revocar.
