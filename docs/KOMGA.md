# Lectura con Komga — piloto de administrador

Komga 1.30.1 sustituye temporalmente a Kavita. La tarjeta se llama **Lectura**.
Kavita permanece deshabilitado y conserva su configuración y datos.
Este piloto es para el propietario/administrador: el volumen contiene las carpetas
de todas las cuentas existentes. No crear usuarios familiares en Komga todavía.

## Uso

Después de actualizar la rama, el habitual **make up** inicia Komga y su gateway.
No se cambia el puerto (25600), la cuenta creada ni el volumen de configuración.
La cuenta administrativa existente en Komga debe tener el mismo correo y
contraseña que tu cuenta de Multisuite. Komga usa el correo aunque en el portal
entres con tu nombre de usuario. Al instalar este incremento, cierra y vuelve
a iniciar la sesión de Multisuite una vez para preparar el acceso automático.

Abre Lectura desde su tarjeta o desde Aplicaciones en el menú, selecciona
el libro y lee. El botón amarillo **← Multisuite**
aparece dentro de la barra del servicio; en móvil se compacta a la flecha.
En el lector sigue la visibilidad de sus controles, sin cubrir las páginas.

Los libros y cómics se siguen subiendo a las carpetas conectadas **Libros** y
**Cómics** de Mis archivos. No hay que duplicarlos ni cambiar sus rutas:
se enlazan al mismo volumen de lectura, sin segunda copia ni transformación.

Con la sesión administrativa de Komga abierta, se prepara la biblioteca /data
si no existe ninguna dentro de ese montaje, y se solicita un escaneo cuando
cambia el reflejo. Las bibliotecas existentes y sus permisos no se modifican.
Cada 15 segundos, mientras la pestaña es visible, se comprueba una revisión
opaca; no se escanea si no hay cambios. Los fallos se reintentan, sin recargar
ni interrumpir el libro abierto. La indexación y portadas tardan lo que necesite
Komga; aceptar la petición de escaneo no significa que haya terminado.

La sincronización de archivos no depende del navegador: las operaciones de
Mis archivos la disparan y el scheduler reconcilia cada 15 minutos.
El aviso inmediato a Komga sí utiliza su sesión administrativa; sin pestaña
abierta quedan sus escaneos periódicos y la actualización al siguiente acceso.
El login del portal verifica las credenciales con Komga y guarda únicamente
su cookie de sesión cifrada en la sesión del portal. La contraseña no se conserva.
Un pase de un minuto y un solo uso transfiere esa cookie al gateway después de
revalidar identidad, rol, contraseña vigente y sesión activa del portal.
Se elimina la cookie Recordarme previa para evitar entrar como otra persona.
La sesión nativa usa el mismo tiempo de inactividad configurado para el portal.

El desafío HTTP Basic se oculta en el gateway: los clientes siguen recibiendo
401, pero el navegador no abre un segundo diálogo. Si no hay sesión preparada
(por ejemplo, entrada con passkey), ha caducado o las credenciales no coinciden,
se abre el formulario nativo de Komga. Este incremento no crea cuentas Komga
ni vincula cuentas con otro correo; ambas operaciones siguen pendientes.

## Infraestructura y seguridad

- compose.komga.yaml: Komga interno y gateway Nginx, único puerto en loopback.
- /data es de solo lectura; /config conserva biblioteca, cuenta y progreso.
- Gateway conectado a multisuite-accounts para el regreso al portal.
- JavaScript y CSS solo se inyectan en la carcasa de la aplicación, no en EPUB.
- La revisión requiere autenticación Komga y no contiene nombres ni rutas.
- Es un piloto de desarrollo con multisuite-nas-dev; discos reales y producción
  necesitan montajes explícitos. El acceso directo conserva la autenticación
  nativa de Komga. El puente de sesión no es un proveedor OIDC ni revoca automáticamente las sesiones de otros dispositivos.

## Validación

1. Entrar de nuevo en Multisuite con contraseña y abrir Lectura: nombre sin sufijo; biblioteca existente y progreso conservados.
2. Abrir un EPUB y un cómic; mostrar controles y volver con ← Multisuite.
3. Subir otro archivo a Libros/Cómics y volver a Lectura: aparece tras el escaneo,
   sin comandos ni selección manual de carpetas.
4. Probar móvil, teclado y foco; el enlace no se solapa con controles.
5. Papelera/restauración en Mis archivos: el siguiente escaneo refleja el cambio.
   Komga puede conservar una entrada en su papelera nativa; no borramos su historial.
6. Probar un pase reutilizado/caducado y una cuenta suspendida: no se transfiere sesión.
7. Un miembro de Multisuite no ve ni puede abrir la tarjeta del piloto.

CI prueba con un EPUB sintético: arranque, inyección, autenticación del endpoint
de revisión, escaneo, descarga completa SHA-256 y rangos HTTP. Las pruebas JS
cubren cambios, deduplicación, reintentos y rechazo de usuarios no administradores.
La comprobación visual de la interfaz real sigue siendo necesaria.

## Reactivar Kavita

make stop, seguido de make up KOMGA_AUTO=0 READING_AUTO=1, reutiliza reading.env
y los datos anteriores. No usar down -v: no es necesario.

## Pendiente antes de abrirlo a familiares

Aprovisionamiento de cuentas, bibliotecas privadas por persona, acceso automático con passkeys,
revocación y vinculación segura de cuentas existentes; escaneo servidor a
servidor sin depender de una sesión abierta; backup/restauración de Komga.
