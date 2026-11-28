# Roadmap de Multisuite

Solo trabajo **pendiente**; lo terminado está en [PROJECT_STATUS.md](PROJECT_STATUS.md) y en git. Revisión 2026-11-28.

## Contrato del producto (no cambia sin decisión explícita del usuario)
- Portal Laravel/Livewire privado para propietario, familia y amistades: portada de servicios, invitaciones, cuentas, almacenamiento y auditoría.
- Cada servicio de terceros conserva su interfaz, catálogo y datos. **No** construir reproductor, lector, catálogo, notas ni descargador propios (Descargas usa MeTube).
- El portal no recibe el socket Docker, no monta/formatea discos y nunca acepta rutas de usuarios.
- Contenido privado por defecto; solo el administrador publica para todos.
- Un incremento = una rama desde `main` + una PR. Se fusiona solo con indicación del usuario.

Pendiente de validar en el piloto: autologin musical con dos usuarios y un navegador compartido ([guía](MUSIC_SESSION_BRIDGE.md)).

## Prioridad vigente desde el 20 de noviembre de 2026
1. QA de Komga y, tras validarlo, el resto de **Lectura (Komga)**.
2. **Asistente y puente MCP** ([MCP.md](MCP.md)):
   - [ ] QA del usuario de MCP-01 a MCP-07 (#42 y #44) con un proveedor real (MCP.md § Probar con un modelo real) y `composer audit`.
   - [ ] QA de clientes externos (MCP-05) con Claude/ChatGPT tras la QA de la red privada.
   - [ ] MCP-08 Piloto: dos personas, dos dispositivos, red privada.
3. QA de lo integrado (S04b, P04.2, P04.3 y red privada; ver sus secciones).

## Lectura (Komga)
- [ ] Evaluar Komga con los libros ya subidos (KOMGA.md). Kavita deshabilitado y reactivable.
- [ ] Tras validar su lector: aprovisionamiento, bibliotecas privadas por usuario, escaneo servidor a servidor y acceso Komga con passkeys. El puente de contraseña para cuentas existentes ya está en main.
- [ ] Adaptar copia y recuperación al lector elegido.
- Si se reactiva Kavita (`READING_AUTO=1`): su QA (KAVITA.md § QA) y la copia de `kavita-config`; las PR cerradas #39 (copia) y #40 (libros en la raíz) sirven de referencia.

## P04 — Servicios (uno por PR)
Para cada uno: licencia, mantenimiento, consumo, autenticación, puertos, volúmenes, copia y restauración, cuotas y aislamiento; adaptador `ServiceProvisioner` con prueba en CI contra la versión fijada.
- [ ] P04.2 Vídeo con Jellyfin: QA del usuario con GPU y apps reales (JELLYFIN.md § QA) y medir transcodificación antes de dimensionar.
- [ ] P04.3 Descargas con MeTube (PR #54, [DOWNLOADS.md](DOWNLOADS.md)): QA del usuario (descargar un vídeo y un audio, guardarlos en Vídeos y Música y verlos en Jellyfin y Navidrome); copia del volumen `metube-state` si se quiere conservar el historial.
- [ ] P04.4 Notas/documentos con interfaz web, adjuntos y exportación.
- [ ] Ideas sin decidir: automatizaciones, invitados temporales con caducidad.

## S04a — Puente Mis archivos → servicios (fusionado en PR #36)
- [ ] QA del usuario: subir un MP3, un EPUB y un CBZ a sus carpetas, verlos en Navidrome y Komga con su cuenta, comprobar que otra persona no los ve y que la papelera los retira.
- [ ] Producción con discos reales: generar el montaje de `.multisuite-media/{servicio}` como `/personal` en los compose de cada servicio (hoy solo en desarrollo) → P05.

## S04b — Publicación para todos (PR #47)
- [ ] QA del usuario: proponer una canción y un libro, publicarlos, verlos con otra cuenta en Navidrome y Komga, retirar y eliminar (STORAGE.md § Publicación para todos).
- [ ] QA del usuario de la selección múltiple (PR #51): proponer varios con una cuenta normal y publicar varios como owner desde Mis archivos.

## P03 — Identidad y red privada
- [ ] QA del usuario de la red privada (PR #53, VPN.md): servidor con nombre público, `make vpn-join`, invitar a una persona y entrar desde un móvil real fuera de casa; probar `VPN_TLS=acme-dns` con su proveedor DNS.
- [ ] Relé propio (DERP integrado en Headscale, UDP 3478) para no depender de los relés públicos.
- [ ] Copia de `headscale-data` y `vpn-node-state` en la copia del servidor.
- [ ] Alta en la red desde la invitación del portal (API de Headscale con clave de solo servidor), sin pasar por la terminal.
- [ ] Vincular cuentas remotas preexistentes con prueba de titularidad (nunca por coincidencia de correo).
- [ ] Cerrar sesiones ya abiertas en el servicio al desactivarlo o suspender; permisos por biblioteca.
- [ ] Entrada web sin segundo inicio de sesión (proxy/identidad central) sin romper clientes móviles.

## P05 — Producción y operación familiar
- [ ] Release adaptada: variables completas, imágenes fijadas, datos separados por entorno; servicios tras entrada privada sin herramientas de desarrollo.
- [ ] Copia programada fuera del host con restauración ensayada (S05.1 ya implementado).
- [ ] Piloto con dos personas y dispositivos; medir disco/CPU/RAM/tráfico.

## Mis archivos y almacenamiento
- [ ] Mover archivos y carpetas entre carpetas.
- [ ] Quitar ubicación y datos del dispositivo de los vídeos (MP4/MOV) con ExifTool, sin recodificar.
- [ ] Reconciliar archivos ausentes o corruptos y reservas sin subida asociada (tras verificación física).
- [ ] Copia entre discos con reserva en destino, verificación y borrado del origen tras confirmar.
- [ ] Opcional: foto anterior/siguiente en el visor; miniaturas en el servidor si la galería se vuelve lenta.

## QA pendiente del usuario (lo que CI no puede comprobar)
- [ ] Tras `make rebuild`: foto de perfil (encuadrar, guardar, quitar; también en móvil); cambiar el usuario de alguien con Música; desactivar/activar Música; suspender/reactivar una cuenta de prueba (USER_MANAGEMENT.md § QA).
- [ ] Foto del móvil con ubicación: tras subirla y descargarla, `exiftool` no muestra GPS ni cámara y la orientación es correcta.
- [ ] Mis archivos en el navegador real: subida grande con corte de red, vídeo MP4 y MOV, explorador en móvil.
- [ ] Puente de sesión de Música con dos personas (MUSIC_SESSION_BRIDGE.md § QA) y checklist de Navidrome con Docker real (NAVIDROME.md).
- [ ] Discos reales: `make nas-discover`/`nas-apply`, disco lleno o retirado, y una copia real con `make nas-backup`.
