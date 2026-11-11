# Administración de usuarios

El menú **Usuarios** está disponible solo para propietario y administradores. Requiere sesión verificada y confirmación reciente de contraseña. Lista paginada, búsqueda por nombre/usuario/correo y enlace al alta manual existente.

- Administrador: gestiona miembros. No puede editar/eliminar administradores, propietarios ni su propia cuenta.
- Propietario: también gestiona administradores. Las cuentas propietarias y la propia están protegidas.
- Edición: nombre, correo y rol autorizado. Cambiar correo exige confirmar su titularidad; invalida el token de recuperación anterior y las invitaciones de ambas direcciones. El nombre de acceso musical se conserva. Contraseñas, servicios y username no son editables mediante este formulario.
- Baja: escribir exactamente el correo. Primero se consulta Navidrome por el UUID reservado y se comprueba usuario y ausencia de privilegios administrativos. Se elimina y confirma su ausencia antes de borrar Multisuite. Un error conserva el registro local; repetir es seguro si la baja remota ya ocurrió. Un servicio no soportado bloquea toda la operación.

La baja remota y la transacción local no son una transacción distribuida: si falla la confirmación posterior, Música puede haberse eliminado mientras Multisuite conserva el registro para reintentar. La acción elimina datos personales de Navidrome, no archivos musicales. Solo cubre cuentas vinculadas por Multisuite; no descubre cuentas creadas manualmente ni modifica el flujo de autoeliminación de ajustes. No es suspensión ni gestión de permisos por servicio.

## QA con cuentas desechables
1. Como miembro, comprobar ausencia del menú y HTTP 403 al acceder directamente a `/users`.
2. Como administrador, abrir Usuarios, confirmar contraseña, buscar, crear miembro y editar nombre. Probar móvil, teclado y zoom 200 %.
3. Comprobar que solo el propietario puede gestionar administradores y que nadie puede gestionar propietarios desde aquí.
4. Cambiar correo: sin marcar confirmación debe fallar; confirmado debe actualizarse sin cambiar el usuario musical.
5. Con miembro de prueba vinculado, intentar baja con correo incorrecto; debe conservarse. Parar Música y repetir con correo correcto: debe mostrarse error y conservarse la cuenta local.
6. Arrancar Música, repetir la baja y comprobar desaparición en ambos servicios y rechazo de un nuevo login musical. No usar cuentas reales para este ensayo.

Pruebas automatizadas: `make artisan CMD="test --filter=UserManagementTest"`; CI ejecuta además el ciclo de alta/cambio/baja contra Navidrome 0.64.2 desechable.
