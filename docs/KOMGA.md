# Lectura con Komga — piloto de administrador

Komga 1.30.1 sustituye temporalmente a Kavita. La tarjeta se llama **Lectura**.
Kavita permanece deshabilitado y conserva su configuración y datos.
Este piloto es para el propietario/administrador: el volumen contiene las carpetas
de todas las cuentas existentes. No crear usuarios familiares en Komga todavía.

## Uso

Después de actualizar la rama, el habitual **make up** inicia Komga y su gateway.
No se cambia el puerto (25600), la cuenta creada ni el volumen de configuración.
Abre Lectura, selecciona el libro y lee. El botón amarillo **← Multisuite**
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
No se almacenan nuevas contraseñas ni se copian tokens de Komga a Multisuite.

## Infraestructura y seguridad

- compose.komga.yaml: Komga interno y gateway Nginx, único puerto en loopback.
- /data es de solo lectura; /config conserva biblioteca, cuenta y progreso.
- Gateway conectado a multisuite-accounts para el regreso al portal.
- JavaScript y CSS solo se inyectan en la carcasa de la aplicación, no en EPUB.
- La revisión requiere autenticación Komga y no contiene nombres ni rutas.
- Es un piloto de desarrollo con multisuite-nas-dev; discos reales y producción
  necesitan montajes explícitos. El acceso directo conserva la autenticación
  nativa de Komga. El portal no convierte este gateway en SSO.

## Validación

1. Abrir Lectura: nombre sin sufijo; biblioteca existente y progreso conservados.
2. Abrir un EPUB y un cómic; mostrar controles y volver con ← Multisuite.
3. Subir otro archivo a Libros/Cómics y volver a Lectura: aparece tras el escaneo,
   sin comandos ni selección manual de carpetas.
4. Probar móvil, teclado y foco; el enlace no se solapa con controles.
5. Papelera/restauración en Mis archivos: el siguiente escaneo refleja el cambio.
   Komga puede conservar una entrada en su papelera nativa; no borramos su historial.
6. Un miembro de Multisuite no ve ni puede abrir la tarjeta del piloto.

CI prueba con un EPUB sintético: arranque, inyección, autenticación del endpoint
de revisión, escaneo, descarga completa SHA-256 y rangos HTTP. Las pruebas JS
cubren cambios, deduplicación, reintentos y rechazo de usuarios no administradores.
La comprobación visual de la interfaz real sigue siendo necesaria.

## Reactivar Kavita

make stop, seguido de make up KOMGA_AUTO=0 READING_AUTO=1, reutiliza reading.env
y los datos anteriores. No usar down -v: no es necesario.

## Pendiente antes de abrirlo a familiares

Aprovisionamiento de cuentas, bibliotecas privadas por persona, autologin,
revocación y vinculación segura de cuentas existentes; escaneo servidor a
servidor sin depender de una sesión abierta; backup/restauración de Komga.
