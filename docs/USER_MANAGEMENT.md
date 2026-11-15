# Administración de usuarios y alta manual

El menú **Usuarios** está disponible solo para propietario y administradores. Requiere sesión verificada y confirmación reciente de contraseña. Lista paginada con foto, búsqueda por nombre/usuario/correo, estado (activa, suspendida, debe cambiar contraseña) y enlace al alta manual.

- Administrador: gestiona miembros. No puede editar/eliminar administradores, propietarios ni su propia cuenta.
- Propietario: también gestiona administradores. Las cuentas propietarias y la propia están protegidas.

## Arquitectura de las ediciones
Cada operación es una Action en `app/Actions/Users` (caso de uso único): la página Livewire solo valida con `rules()` y la llama, así que cualquier otra entrada futura (consola, API) reutiliza la misma regla. La Action bloquea actor y persona en orden (`LockedUsers`), revalida el permiso con `UserPolicy`, aplica el cambio **primero en los servicios** a través del puerto `ServiceProvisioner` (un adaptador por servicio, hoy Navidrome) y después en Multisuite, y deja un evento de auditoría con lista blanca de campos. Si un servicio no confirma, no cambia nada y repetir es seguro.

## Operaciones sobre otra persona
- **Datos de la cuenta** (`UpdateUser`): nombre, correo y rol autorizado. Cambiar correo exige confirmar su titularidad; invalida el token de recuperación anterior y las invitaciones de ambas direcciones.
- **Nombre de usuario** (`ChangeUsername`): se cambia en cada servicio sin tocar su contraseña ni sus datos (Navidrome conserva la contraseña si no se envía) y luego en Multisuite. Un nombre ocupado en el servicio da error y no cambia nada. Un servicio apagado en la configuración se renombra en su siguiente sincronización.
- **Servicios** (`SetServiceAccess`): desactivar bloquea el acceso en el propio servicio (su contraseña se sustituye por una aleatoria que nadie conoce) y conserva sus datos; también oculta la tarjeta y rechaza `/services/{id}` y el puente de sesión. Activar de nuevo, o por primera vez para alguien que ya tiene cuenta, deja la cuenta **pendiente de su próximo acceso con contraseña**: el portal no guarda contraseñas, así que la usa en ese momento (`ServiceAccounts::resume`, tras la respuesta). Quien entra solo con llave de acceso debe entrar una vez con contraseña o cambiarla.
- **Servicios nuevos**: cuando el portal añade un servicio (p. ej. Lectura), cada persona lo recibe al entrar con contraseña, con su cuenta creada en ese momento (`ServiceAccounts::grantNewServices`, auditado como acción del sistema). No se concede si un administrador lo dejó sin marcar al crear la cuenta o la invitación, o lo desactivó después (`users.services_revoked`); activarlo de nuevo desde la ficha borra esa marca.
- **Suspender / reactivar** (`SetSuspension`): suspender bloquea primero cada servicio (si uno no confirma, la cuenta sigue activa), invalida «recordarme» y el token de recuperación y borra sus sesiones; `EnsureAccountIsActive` cierra en su siguiente petición cualquier sesión que quede. El acceso con contraseña de una cuenta suspendida muestra el motivo solo tras comprobar la contraseña. No se borra nada (archivos, espacio, datos de servicios). Reactivar devuelve el acceso a Multisuite y deja los servicios pendientes de su próximo acceso con contraseña.
- **Foto**: quien administra solo puede retirarla (`RemoveAvatar`, auditado); cambiarla es cosa de la propia persona.
- **Baja** (`DeleteUser`): escribir exactamente el correo. Se comprueba cada cuenta de servicio por su UUID reservado, se elimina y se confirma su ausencia antes de borrar Multisuite. Un error conserva el registro local; repetir es seguro si la baja remota ya ocurrió. Un servicio sin adaptador bloquea toda la operación. Se lleva también su foto.

Límites: las sesiones ya abiertas dentro de Navidrome siguen válidas hasta que caducan (su propia duración de sesión); bloquear la contraseña impide entrar de nuevo, también desde aplicaciones Subsonic. La baja remota y la transacción local no son una transacción distribuida. Solo cubre cuentas vinculadas por Multisuite; no descubre cuentas creadas manualmente.

## Foto de perfil
Ajustes → Perfil. La persona elige una imagen (JPG, PNG, WebP o GIF) y la encuadra en un círculo con Cropper.js; el navegador la recorta a 512 px y la sube. El servidor (`Services/Users/Avatars`) la vuelve a decodificar, recorta al centro y guarda un **WebP de 256 px** en el disco privado, sin metadatos (ubicación incluida). Límites: 8 MB, entre 64 y 4096 px de lado. Se sirve en `/avatars/{id}/{hash}` solo a la propia persona y a propietario/administradores, con caché de un año (la dirección cambia con cada foto). La foto anterior se borra cuando la nueva queda registrada.

## QA pendiente con cuentas desechables
1. Como miembro, comprobar ausencia del menú y HTTP 403 al acceder directamente a `/users`.
2. Como administrador, abrir Usuarios, confirmar contraseña, buscar, crear miembro y editar nombre. Probar móvil, teclado y zoom 200 %.
3. Comprobar que solo el propietario puede gestionar administradores y que nadie puede gestionar propietarios desde aquí.
4. Cambiar correo: sin marcar confirmación debe fallar; confirmado debe actualizarse sin cambiar el usuario musical.
5. Con miembro de prueba vinculado, intentar baja con correo incorrecto; debe conservarse. Parar Música y repetir con correo correcto: debe mostrarse error y conservarse la cuenta local.
6. Arrancar Música, repetir la baja y comprobar desaparición en ambos servicios y rechazo de un nuevo login musical. No usar cuentas reales para este ensayo.
7. Cambiar el nombre de usuario de un miembro con Música y entrar en Navidrome con el nuevo nombre y su contraseña de siempre.
8. Desactivar Música: Navidrome rechaza su contraseña y la tarjeta desaparece. Activarla, salir y entrar en Multisuite con contraseña: Música vuelve a estar disponible con sus listas.
9. Suspender: su sesión abierta se cierra y no puede entrar. Reactivar y comprobar lo mismo que en el punto 8.
10. Subir y encuadrar una foto en Ajustes → Perfil (también en móvil); verla en el menú y en Personas; retirarla como administrador.

Pruebas automatizadas: `make test CMD="--filter='UserManagementTest|UserAdministrationTest'"`; CI ejecuta además el ciclo de alta, cambio de contraseña, renombrado, bloqueo y baja contra Navidrome 0.64.2 desechable.

## Alta manual (sin SMTP ni invitación)
Solo owner/admin desde **Usuarios → Crear usuario**; solo owner crea admin; nadie crea owner desde la web.
1. Elegir nombre, correo (verificar su titularidad: se marca como verificado), usuario opcional, rol y servicios.
2. Fijar una contraseña temporal y entregarla por un canal privado. La cuenta queda con `must_change_password`.
3. En el primer acceso solo puede cambiar la contraseña o salir. La nueva contraseña pasa por `ServiceAccounts::changePassword`, que aprovisiona los servicios con la contraseña personal, nunca con la temporal (ver SERVICE_ACCOUNTS.md).
Invalida invitaciones previas de ese correo. Roles del portal no otorgan privilegios administrativos en Navidrome. Tests: `make test CMD="--filter=ManualUserTest"`.

## Espacio de almacenamiento
La ficha muestra el uso y enlaza a **Almacenamiento**, donde se fija el espacio máximo. Una baja se bloquea mientras la persona tenga espacio usado o reservado (STORAGE.md).
