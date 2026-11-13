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
2. **S03.2** — Interfaz de subida privada y reconciliación de archivos confirmados.
3. **S04** — Publicación/retirada administrativa.
4. **S05** — Movimientos entre discos y backup/restore comprobado.
5. **P03** — Cuentas existentes, baja integral, VPN/TLS y segundo dispositivo.
6. **P04/P05** — Nuevos servicios (uno por PR), despliegue reproducible y piloto familiar.

## S02 — Almacenamiento y cuotas
- [ ] S02.3 QA ampliado (opcional antes del piloto): móvil 360 px, teclado, zoom 200 %, disco desconectado, conflicto de grupos, sobreasignación, admin frente a owner.
- [ ] Probar con topología real: un disco con dos carpetas, dos discos, disco retirado, disco lleno (ENOSPC).
- [ ] Reconciliación: comparar contabilidad con bytes reales y detectar reservas caducadas sin limpiar.
- [x] Mostrar en la GUI reservas caducadas que siguen contando (limpieza automática frente a revisión manual) — en la rama S02.3.
- [ ] Comando de reconciliación para reservas sin subida asociada (solo tras verificación física).
- [ ] Opcional operador: guía de XFS project quota como defensa adicional.

## S03 — Incorporación privada de contenido
- [ ] Interfaz de subida para miembros (selector, progreso, cancelación) sobre el contrato HTTP existente.
- [ ] Revisar límites Nginx/PHP (`client_max_body_size`, timeouts, temporales) antes de admitir archivos grandes; valorar subida por trozos/reanudable.
- [ ] Listado, descarga y borrado de contenido propio con decremento verificado del consumo.
- [ ] Reconciliar `ready` ausentes, corruptos o restaurados desde backup.
- [ ] Conectar la ubicación privada con bibliotecas por usuario de Navidrome (escaneo bajo demanda). Archivos comprimidos: deshabilitados hasta soportarlos de forma segura.

## S04 — Publicación administrativa
- [ ] Cerrar con el usuario: imputación de contenido global a cuotas, borrado por el aportante de contenido publicado, baja del aportante.
- [ ] Estados personal → pendiente → global (rechazo vuelve a personal); solo admin publica/retira; permisos aplicados en el servicio antes de confirmar.
- [ ] Auditoría de transiciones e idempotencia ante reintentos. Sin duplicar bytes.

## S05 — Movimientos y recuperación
- [ ] Copia entre discos con reserva en destino, verificación de integridad, cambio de referencias y borrado del origen solo tras confirmación.
- [ ] Backup consistente de DB del portal, datos de servicios y originales; restauración en destino limpio. Otra partición del mismo disco no es backup.

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
