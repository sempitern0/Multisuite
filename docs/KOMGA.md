# Lectura con Komga

Komga 1.30.1 conserva catálogo, lector, progreso y cuentas nativas. Kavita permanece deshabilitado y conserva sus datos para reactivarlo. `make up` aplica el overlay de Lectura, habilita aprovisionamiento y arranca el gateway; no editar variables para el entorno local.

## Conexión inicial

Al entrar el propietario con contraseña, se intenta conectar automáticamente usando sus credenciales: funciona con una instalación vacía o una cuenta administradora nativa con el mismo correo y contraseña. Si la cuenta anterior de Komga tiene otros datos, se necesita autorizarla una vez.

En ese caso, el propietario abre **Administración → Conexión de Lectura** e introduce el correo y contraseña del administrador existente de Komga. Si Komga está vacío, se crea esa primera cuenta. Se verifica la identidad nativa y se guarda una clave API cifrada con APP_KEY; nunca se conserva la contraseña administrativa. Esta prueba de titularidad no puede deducirse del inicio de sesión en Multisuite.

Después confirma su contraseña actual de Multisuite para vincular su acceso. La cuenta nativa administradora vinculada mantiene privilegios administrativos. El propietario puede ver todas las bibliotecas, como administrador del NAS.

Los usuarios anteriores quedan reservados automáticamente y se activan cuando vuelven a entrar con contraseña. No se pueden recuperar contraseñas desde los hashes de Laravel. Las nuevas invitaciones preparan la cuenta usando la contraseña elegida al aceptarlas; el alta administrativa lo hace al establecer la contraseña personal. Una invitación pendiente de Kavita pasa a Komga cuando Kavita está deshabilitado. Las revocaciones de Lectura se conservan.

## Sesión y permisos

El servidor crea cuentas nativas sin rol ADMIN, inicialmente sin acceso a bibliotecas. Prepara Libros y Cómics personales y concede solo esos identificadores. La identidad externa queda fijada por ID y correo: un cambio de nombre de usuario del portal no la altera. Una coincidencia de correo nativo no permite apropiarse de la cuenta: se exige prueba de contraseña y nunca se adopta silenciosamente un administrador.

El login del portal prepara una cookie nativa cifrada en su sesión. El pase de entrada dura un minuto, se consume una vez y verifica sesión activa del portal, acceso vigente e identidad nativa. El navegador recibe la cookie HttpOnly del gateway sin recibir contraseñas. Si caduca, una pantalla con el diseño del portal permite renovar usando la contraseña actual de Multisuite.

Al revocar un miembro se vacían sus bibliotecas y permisos y se sustituye su contraseña. Los datos se conservan. La cuenta administradora que autoriza la conexión no se elimina ni bloquea automáticamente: exige cambiar primero la integración.

## Archivos y sincronización

Las carpetas Libros/Cómics de Mis archivos conservan sus nombres e identidad. Komga lee `/data/{identidad-media}/{libros|comics}` del montaje existente `.multisuite-media/reading`; se reutiliza el UUID de la cuenta Kavita cuando existe. No se copian ni convierten los originales.

Subidas y cambios desencadenan el espejo y escaneo desde el servidor, con reintentos y reconciliación periódica. El navegador solo añade el enlace de regreso. No crea bibliotecas globales ni necesita una sesión administradora para sincronizar.

Las bibliotecas globales preexistentes del piloto se conservan para el administrador y no se conceden a miembros. La publicación de contenido global desde Multisuite es un incremento separado.

## Validación

Pruebas de sesión y permisos en `KomgaSessionTest` y `KomgaProvisioningTest`; integración contra Komga real en `Integration/KomgaAccountsTest` (CI). Cubren dos cuentas, reserva de usuarios anteriores, biblioteca privada, misma contraseña, cambio de contraseña, bloqueo y eliminación. El smoke de infraestructura verifica gateway, cookie nativa y EPUB.

QA local: conectar una vez como propietario, aceptar una invitación con Lectura, subir un EPUB en Libros y abrir Lectura. Debe entrar sin segundo formulario. Repetir con otra cuenta: no debe ver los libros de la primera. Revisar regreso a Multisuite en ordenador y móvil.
