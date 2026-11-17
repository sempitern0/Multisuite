# Kavita (Lectura)

Lectura de novelas, cómics, manga y documentación técnica con [Kavita](https://www.kavitareader.com/) 0.9.1 (GPL-3.0), servicio de terceros con su propia interfaz, lectores (EPUB, PDF, CBZ/CBR/CB7, imágenes) y OPDS para lectores electrónicos. El portal no lee libros: muestra la tarjeta «Lectura», crea y gestiona las cuentas y deja Kavita preparado.

## Arranque integrado con Make
1. Copiar `reading.env.example` a `reading.env` (ignorado por git). Ajustar las rutas si los libros ya están en otro sitio.
2. `make up`. Make arranca `compose.reading.yaml`, lo conecta a la red `multisuite-accounts` y pasa al portal la URL y el aprovisionamiento (`deploy/compose.portal-reading.example.yaml`). En desarrollo, `portal:reading-setup` (desde `portal:dev-bootstrap`) crea las credenciales de la cuenta de servicio en `app/.env`, la registra como primera administradora de Kavita y crea las bibliotecas que falten:

| Biblioteca | Carpeta en el host (`reading.env`) | Tipo en Kavita |
| --- | --- | --- |
| Libros | `KAVITA_BOOKS_PATH` | Book |
| Cómics | `KAVITA_COMICS_PATH` | Comic |
| Manga | `KAVITA_MANGA_PATH` | Manga |
| Documentación técnica | `KAVITA_DOCS_PATH` | Book |

3. Copiar los libros a esas carpetas del servidor (Kavita las vigila; si tarda, Kavita → Ajustes del servidor → Bibliotecas → Escanear). Este contenido es común para todos y solo lo puede añadir quien tenga acceso al servidor; la subida desde Mis archivos a una biblioteca privada por persona es el siguiente hito (ROADMAP § S04). Abrir Lectura desde la portada en `http://127.0.0.1:5000`.

Comandos: `make reading-up | reading-stop | reading-status | reading-logs | reading-config`. `make up READING_AUTO=0` arranca sin Lectura. Las bibliotecas se pueden renombrar o ampliar desde Kavita (Ajustes del servidor → Bibliotecas); el portal solo crea las que faltan por nombre y nunca modifica las existentes.

Producción: fijar `KAVITA_ADMIN_USERNAME` y `KAVITA_ADMIN_PASSWORD` en `app/.env` (secretos del operador) y ejecutar una vez `make artisan CMD="portal:reading-setup"`. Si Kavita ya tiene otro administrador, el comando se niega y pide las credenciales correctas.

## Cuentas (adaptador `KavitaAccounts`)
Mismo ciclo que Música (SERVICE_ACCOUNTS.md): invitación o alta con servicios, cambio de contraseña, renombrado, activar/desactivar, suspensión y baja desde la ficha de persona. Las personas que ya existían antes de activar Lectura la reciben automáticamente la próxima vez que entran con contraseña.
- Kavita asigna sus propios identificadores; cada cuenta se reconoce por la dirección sintética `{uuid}@multisuite.invalid`, con el UUID reservado en Multisuite. Un reintento encuentra la misma cuenta y nunca se adopta una cuenta existente por coincidir el correo real.
- Alta: invitación **sin** el rol `Login` (una invitación pendiente conserva la contraseña por defecto pública de Kavita), confirmación con el nombre y la contraseña de la persona, y solo entonces se concede `Login`. Se comprueba entrando con la contraseña nueva.
- Roles de un miembro: `Login`, `Download`, `Bookmark` (y `Pleb`, que Kavita añade). Nunca `Admin` ni `Change Password`: la contraseña se cambia en el portal.
- Bibliotecas: al crear la cuenta se conceden las comunes (Libros, Cómics, Manga, Documentación técnica), nunca las personales de otra persona.
- Bibliotecas personales (S04a, STORAGE.md § Carpetas conectadas): «Mis libros · {usuario}» y «Mis cómics · {usuario}» sobre `/personal/{uuid}/libros` y `/personal/{uuid}/comics`, creadas por `ensurePersonalLibrary` la primera vez que la persona sube algo, asignadas solo a su cuenta y escaneadas tras cada cambio. El administrador de Kavita las ve todas.
- Desactivar o suspender retira `Login` (Kavita rechaza el inicio de sesión como «cuenta desactivada») y revoca sus claves OPDS; libros leídos, progreso y listas se conservan. Reactivar devuelve `Login` en su siguiente acceso con contraseña.
- Una cuenta remota con rol `Admin` o un nombre ocupado por otra cuenta se marcan como conflicto y no se tocan.

Contrato revisado en el código de v0.9.1.4 (`Kavita.Server/Controllers/AccountController.cs`, `UsersController.cs`, `LibraryController.cs`). Verificarlo de nuevo antes de cambiar `KAVITA_IMAGE`.

## Seguridad y límites
- Escucha solo en `127.0.0.1:5000`. La autenticación del portal no protege el acceso directo: Kavita conserva su propio inicio de sesión. Exponerlo solo tras VPN/TLS (P03).
- Bibliotecas montadas en solo lectura (también `/personal`, que escribe el portal); configuración, base de datos y portadas en el volumen Docker `kavita-config`. La imagen de Kavita se ejecuta como root y su carpeta de configuración pertenece a otro usuario: conserva solo `CHOWN`, `DAC_OVERRIDE` y `FOWNER` (el resto de capacidades se quitan) y `no-new-privileges`. Las bibliotecas siguen siendo de solo lectura.
- Las sesiones ya abiertas en Kavita siguen hasta que caducan aunque se desactive la cuenta (igual que Música; ROADMAP P03).
- Metadatos externos, scrobbling y recomendaciones requieren la suscripción Kavita+: no se usan. La coincidencia de metadatos queda desactivada en las bibliotecas creadas.
- Copia de seguridad del volumen `kavita-config`: pendiente de añadir a `make nas-backup` (ROADMAP). Los libros son archivos normales del host y entran en la copia del operador.

## QA pendiente con Docker real
- [ ] `cp reading.env.example reading.env` y `make up`: Kavita arranca, la tarjeta Lectura aparece y las cuatro bibliotecas existen.
- [ ] Copiar un EPUB, un PDF, un CBZ y un manga; leerlos desde el navegador y desde el móvil (o una app OPDS) y comprobar que el progreso se conserva.
- [ ] Invitar a una persona con Lectura, entrar en Kavita con su usuario y contraseña del portal; cambiar la contraseña en el portal y comprobar la nueva.
- [ ] Desactivar Lectura en su ficha: Kavita rechaza la entrada; activarla, salir y entrar en el portal con contraseña: vuelve a entrar con su progreso.

Los archivos sueltos de las carpetas conectadas Libros y Cómics se publican en subdirectorios estables por archivo para que Kavita los escanee. La siguiente ejecución de `portal:media-sync` migra los enlaces existentes. Regresión cubierta en ConnectedFoldersTest; ejecución PHP y QA real pendientes en este entorno.
