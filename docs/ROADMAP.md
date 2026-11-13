# Roadmap de Multisuite

Solo trabajo **pendiente**. Lo completado se resume en [PROJECT_STATUS.md](PROJECT_STATUS.md) y en el historial de git/PRs. Revisión 2026-11-13.

## Contrato del producto (no cambia sin decisión explícita del usuario)
- Portal Laravel/Livewire privado para propietario, familia y amistades: portada de servicios, invitaciones, cuentas, almacenamiento y auditoría.
- Cada servicio de terceros (Navidrome hoy; Komga/Jellyfin/… mañana) conserva su interfaz, catálogo y datos. **No** construir reproductor, lector, catálogo, notas ni descargador propios.
- El portal no recibe el socket Docker, no monta/formatea discos y nunca acepta rutas de usuarios.
- Contenido privado por defecto; solo el administrador publica para todos.
- Un incremento = una rama desde `main` + una PR. Sin merges automáticos.

## Orden vigente
1. **S02.3** — GUI de almacenamiento: PR #12 lista para merge.
2. **S03.2** — Mis archivos: carpetas, subida web, descarga y vista previa (rama `feat/s03-my-files`).
3. **S03.3** — Papelera y versiones con purga verificada que devuelve cuota.
4. **S03.4** — Descargas ZIP (selección, carpeta, «Exportar todo») en segundo plano.
5. **S05.1** — Backup del NAS (DB, servicios y archivos) a destino externo con restauración ensayada.
6. **S04** — Publicación/retirada administrativa y movimientos entre discos.
7. **P03** — Cuentas existentes, baja integral, VPN/TLS y segundo dispositivo.
8. **P04/P05** — Nuevos servicios (uno por PR), despliegue reproducible y piloto familiar.

## S02 — Almacenamiento y cuotas
- [ ] S02.3 QA ampliado (opcional antes del piloto): móvil 360 px, teclado, zoom 200 %, disco desconectado, conflicto de grupos, sobreasignación, admin frente a owner.
- [ ] Probar con topología real: un disco con dos carpetas, dos discos, disco retirado, disco lleno (ENOSPC).
- [ ] Reconciliación: comparar contabilidad con bytes reales y detectar reservas caducadas sin limpiar.
- [x] Mostrar en la GUI reservas caducadas que siguen contando (limpieza automática frente a revisión manual) — en la rama S02.3.
- [ ] Comando de reconciliación para reservas sin subida asociada (solo tras verificación física).
- [ ] Opcional operador: guía de XFS project quota como defensa adicional.

## S03 — Mis archivos (decisión 2026-11-13: dentro del portal, carpetas propias)
- [ ] S03.2 Carpetas por persona, subida web con progreso, listado, renombrado, descarga individual y vista previa de solo lectura (imágenes, PDF, Markdown saneado, texto). Tipos de documento ampliados (ofimática, EPUB) solo para descarga.
- [ ] S03.3 Papelera (borrado lógico, restaurar, vaciado con borrado físico verificado y decremento de cuota) y versiones al subir un archivo con el mismo nombre.
- [ ] S03.4 ZIP de selección o carpeta y «Exportar todo», generado por job con reserva temporal, caducidad y limpieza; nunca extraer ZIP subidos.
- [ ] Revisar límites Nginx/PHP para archivos grandes; valorar subida por trozos/reanudable.
- [ ] Reconciliar archivos `ready` ausentes, corruptos o restaurados desde backup.
- [ ] Conectar contenido con bibliotecas por usuario de Navidrome/servicios (escaneo bajo demanda).

## S04 — Publicación administrativa
- [ ] Cerrar con el usuario: imputación de contenido global a cuotas, borrado por el aportante de contenido publicado, baja del aportante.
- [ ] Estados personal → pendiente → global (rechazo vuelve a personal); solo admin publica/retira; permisos aplicados en el servicio antes de confirmar.
- [ ] Auditoría de transiciones e idempotencia ante reintentos. Sin duplicar bytes.

## S05 — Copias de seguridad y movimientos
- [ ] S05.1 Backup programado de DB del portal, datos de servicios y archivos privados a destino externo (herramienta a evaluar en DEPENDENCIES, p. ej. restic), retención, verificación y restauración ensayada en destino limpio. Otra partición del mismo disco no es backup.
- [ ] Copia entre discos con reserva en destino, verificación de integridad, cambio de referencias y borrado del origen solo tras confirmación.

## S01 — Restos de validación de Navidrome
- [ ] Cliente móvil/Subsonic y navegador con bibliotecas por usuario; streams ya abiertos tras retirar acceso.
- [ ] Publicación de un archivo individual sin moverlo (o alternativa documentada).
- [ ] Permisos por defecto de usuarios nuevos (`defaultNewUsers`) y ventana de aprovisionamiento.

## P01/P02 — Validación pendiente
- [ ] P01-03/04/05: `compose config`, digest de imagen, dos cuentas, escaneo, reproducción/seek, favoritos/playlists, recrear contenedor, permisos y backup/restore (checklist en NAVIDROME.md).
- [ ] P01-06: confirmar `make up`/`stop`/`start` en Docker Desktop con la biblioteca del usuario.
- [ ] P02-03/06: QA visual de la portada 360/768/1440 px, foco, contraste, zoom y reduced-motion.

## P03 — Identidad, acceso y red privada
- [ ] P03-06c Vincular cuentas preexistentes y añadir servicios a usuarios existentes (prueba de titularidad; nunca por coincidencia de email).
- [ ] P03-06d Baja integral, revocación y permisos por servicio/biblioteca; auditar invitaciones y consola.
- [ ] P03-06e Entrada web sin segundo login (proxy/identidad central) sin romper clientes móviles. QA pendiente del puente actual en MUSIC_SESSION_BRIDGE.md.
- [ ] P03-02 Subruta `/music/` solo si se valida BaseUrl, assets, Range y clientes.
- [ ] P03-03 VPN (Tailscale u otra), TLS, permisos mínimos y revocación.
- [ ] P03-05 Prueba desde un segundo dispositivo real.

## P04 — Servicios adicionales (uno por PR)
Para cada uno: licencia, mantenimiento, consumo, autenticación, puertos, volúmenes, backup/restore, cuotas y aislamiento.
- [ ] Lectura: Komga (EPUB/CBZ/PDF).
- [ ] Vídeo: Jellyfin (medir transcodificación antes de dimensionar).
- [ ] Descargas: frontend mantenido para yt-dlp con cuotas y aislamiento.
- [ ] Notas/documentos con UI web, adjuntos y exportación.
- [ ] Offline: clientes nativos de cada servicio; sin caché offline del portal.
- [ ] Ideas sin decidir (requieren aprobación de alcance): automatizaciones (p. ej. tareas programadas o un orquestador self-hosted), invitados temporales con caducidad de acceso.

## P05 — Producción y operación familiar
- [ ] Release Foundry adaptada: variables completas, imágenes fijadas, datos separados por entorno.
- [ ] Servicios tras entrada privada; sin herramientas dev ni debug expuestas.
- [ ] Backups fuera del host y restauración ensayada.
- [ ] Piloto con dos usuarios/dispositivos; alta/baja y fallo de un servicio sin tumbar el portal.
- [ ] Medir disco/CPU/RAM/tráfico y costes.
- [ ] CI proporcional sin reruns innecesarios.
