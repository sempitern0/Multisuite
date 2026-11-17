# Roadmap de Multisuite

Solo trabajo **pendiente**; lo terminado está en [PROJECT_STATUS.md](PROJECT_STATUS.md) y en git. Revisión 2026-11-15.

## Contrato del producto (no cambia sin decisión explícita del usuario)
- Portal Laravel/Livewire privado para propietario, familia y amistades: portada de servicios, invitaciones, cuentas, almacenamiento y auditoría.
- Cada servicio de terceros conserva su interfaz, catálogo y datos. **No** construir reproductor, lector, catálogo, notas ni descargador propios.
- El portal no recibe el socket Docker, no monta/formatea discos y nunca acepta rutas de usuarios.
- Contenido privado por defecto; solo el administrador publica para todos.
- Un incremento = una rama desde `main` + una PR. Se fusiona solo con indicación del usuario.

Pendiente de validar en el piloto: autologin musical con dos usuarios y un navegador compartido ([guía](MUSIC_SESSION_BRIDGE.md)).

## Orden vigente
1. **P04.1 Lectura (Kavita)** — en revisión; falta la QA del usuario con libros reales.
2. **S04a Puente Mis archivos → servicios** — en revisión en la PR #36; falta la QA del usuario.
3. **S04b** — Publicación administrativa (necesita tres decisiones del usuario).
4. **P03** — Red privada (VPN/TLS), segundo dispositivo y vinculación de cuentas existentes.
5. **P05** — Despliegue reproducible y piloto familiar.

## P04 — Servicios (uno por PR)
Para cada uno: licencia, mantenimiento, consumo, autenticación, puertos, volúmenes, copia y restauración, cuotas y aislamiento; adaptador `ServiceProvisioner` con prueba en CI contra la versión fijada.
- [ ] P04.1 Lectura con Kavita: QA del usuario (KAVITA.md § QA) y copia del volumen `kavita-config` en `make nas-backup`.
- [ ] P04.2 Vídeo: Jellyfin (medir transcodificación antes de dimensionar).
- [ ] P04.3 Descargas: interfaz mantenida para yt-dlp con cuotas y aislamiento.
- [ ] P04.4 Notas/documentos con interfaz web, adjuntos y exportación.
- [ ] Ideas sin decidir: automatizaciones, invitados temporales con caducidad.

## S04a — Puente Mis archivos → servicios (en revisión, PR #36)
- [ ] QA del usuario: subir un MP3, un EPUB y un CBZ a sus carpetas, verlos en Navidrome y Kavita con su cuenta, comprobar que otra persona no los ve y que la papelera los retira.
- [ ] Vídeos → Jellyfin cuando llegue P04.2.
- [ ] Producción con discos reales: generar el montaje de `.multisuite-media/{servicio}` como `/personal` en los compose de cada servicio (hoy solo en desarrollo) → P05.

## S04b — Publicación administrativa
- [ ] Decidir con el usuario: imputación de contenido global a cuotas, borrado por el aportante de contenido publicado, baja del aportante.
- [ ] Estados personal → pendiente → global; solo admin publica/retira; permisos aplicados en el servicio antes de confirmar; auditoría e idempotencia sin duplicar bytes.

## P03 — Identidad y red privada
- [ ] VPN (Tailscale u otra), TLS, permisos mínimos y revocación; prueba desde un segundo dispositivo real.
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

## Deuda técnica
- [ ] Enums PHP respaldados (`Role`, `ReservationState`, `UploadState`, `ExportState`, `ServiceAccountStatus`) con casts y `CHECK` en PostgreSQL.
- [ ] Sustituir `flux:otp` por un campo propio del tema y retirar Flux.
- [ ] Resumen de administración con métricas reales.
- [ ] Retirar el andamiaje SaaS heredado de Foundry (`wizard`, `tenancy-check`, `Caddyfile.saas`, docs/foundry/SAAS.md y sus pasos de CI): Multisuite no es multi-inquilino.

## QA pendiente del usuario (lo que CI no puede comprobar)
- [ ] Tras `make rebuild`: foto de perfil (encuadrar, guardar, quitar; también en móvil); cambiar el usuario de alguien con Música; desactivar/activar Música; suspender/reactivar una cuenta de prueba (USER_MANAGEMENT.md § QA).
- [ ] Foto del móvil con ubicación: tras subirla y descargarla, `exiftool` no muestra GPS ni cámara y la orientación es correcta.
- [ ] Mis archivos en el navegador real: subida grande con corte de red, vídeo MP4 y MOV, explorador en móvil.
- [ ] Puente de sesión de Música con dos personas (MUSIC_SESSION_BRIDGE.md § QA) y checklist de Navidrome con Docker real (NAVIDROME.md).
- [ ] Discos reales: `make nas-discover`/`nas-apply`, disco lleno o retirado, y una copia real con `make nas-backup`.
