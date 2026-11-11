# Multisuite — Portal de servicios
Revisión 2026-11-08. Sustituye el plan H00–H09 del commit 614ab0f: el usuario elige interfaces de terceros y una portada propia. No desarrollar reproductor, catálogo multimedia, lector, notas ni descargador propios.
Contexto: [rescate de private-nas](PRIVATE_NAS_REVIEW.md). Estado: [PROJECT_STATUS.md](PROJECT_STATUS.md).

## Contrato
Laravel + Livewire gestiona índice, invitaciones y aprovisionamiento de identidad en servicios compatibles. Cada servicio conserva contenido, progreso y permisos propios. No hay SSO implícito. Se empieza por enlaces y se añaden subrutas proxy cuando el servicio lo soporte.
Mantener cuentas individuales, formatos exportables, backups y acceso privado. El portal no recibe acceso al socket Docker. El eventual acceso restringido a bibliotecas para incorporar contenido debe diseñarse y validarse en S03; todavía no está habilitado.

| Hito | Dependencia | Salida |
| --- | --- | --- |
| P00 | Ninguna | Alcance y rescate documentados |
| P01 | P00 | Navidrome local probado |
| P02 | P00 | Portada Livewire |
| P03 | P01, P02 | Navegación y acceso privado |
| P04 | P03 | Servicios adicionales seleccionados |
| P05 | P03, módulos incluidos | Producción y recuperación |

Estados: pendiente/en curso/bloqueado/en revisión/completado. Mantener IDs, evidencia y próxima acción en PROJECT_STATUS. No completar por existir un YAML.

## P00 — Cambio de alcance
- [x] P00-01 Inspeccionar main y rama musical de private-nas.
- [x] P00-02 Documentar qué se rescata y qué se descarta.
- [x] P00-03 Alinear README, AGENTS y planning con portal de servicios.
Salida: ningún documento activo ordena implementar el antiguo catálogo/reproductor propio.

## P01 — Navidrome autónomo
- [x] P01-01 Adaptar Compose independiente, loopback, música read-only y data persistente.
- [x] P01-02 Añadir variables y guía Linux/Windows sin dependencia de Laravel.
- [ ] P01-03 Ejecutar docker compose config y arranque con versión fijada; registrar entorno y digest.
- [ ] P01-04 Probar login, dos cuentas, escaneo, reproducción/seek, favoritos y playlists.
- [ ] P01-05 Recrear contenedor, probar permisos y backup/restore.
Salida: checklist de NAVIDROME.md ejecutado con muestras propias. YAML validado no significa runtime validado.

### P01-06 — Ciclo de vida integrado (añadido por petición del usuario)
- [x] Arrancar Navidrome automáticamente con make up cuando existe music.env.
- [x] Coordinar start/stop/restart/down sin borrar datos y conservar opt-out.
- [x] Pruebas Make con Docker simulado: ausencia/configuración, errores y dispatch Windows.
- [ ] Confirmar make up y stop/start en Docker Desktop con la biblioteca del usuario.

## P02 — Índice Laravel/Livewire
- [x] P02-01 Auditar Foundry y generar starter Livewire en app/; versionar código y lockfiles.
- [x] P02-02 Configuración de servicios: id, título, descripción, icono, categoría, URL por entorno y enabled. Sin credenciales.
- [ ] P02-03 Portada responsive con tarjetas y navegación por teclado; estados vacíos y servicios aún no configurados.
- [x] P02-04 Ruta /services/{id}: solo IDs del registro y destinos HTTP(S) administrados; 404 para desconocidos, sin redirect arbitrario.
- [x] P02-05 Invitaciones firmadas, roles y registro público cerrado (PR #2). Usuario confirma flujo funcional.
- [ ] P02-06 Tests de navegación, validación URL y acceso; comprobar 360/768/1440px, foco, contraste, zoom y reduced-motion.
Salida: abrir Música conduce a Navidrome. La portada funciona aunque Navidrome esté caído y no importa su catálogo.

## P03 — Rutas y red privada
- [x] P03-01 Elegir enlace directo, subdominio o subruta por servicio. Empezar con enlace para evitar reescrituras.
- [ ] P03-02 Para /music/: BaseUrl correcto y proxy conserva prefijo; probar login, assets, Range y cliente móvil.
- [ ] P03-03 Tailscale o alternativa elegida; permisos mínimos, TLS y revocación. No confiar solo en ocultar enlaces.
- [ ] P03-04 Aprovisionamiento y credenciales comunes, permisos por servicio, reconciliación y baja integral. Ver P03-06.
- [ ] P03-05 Probar desde un segundo dispositivo; localhost nunca representa el servidor remoto.
Salida: acceso privado funciona, puertos admin/DB no expuestos y URLs directas requieren permisos propios.

## P04 — Ampliación con software existente
- [ ] P04-01 Lectura: evaluar Komga con EPUB/CBZ/PDF y dispositivos reales; añadir Compose y tarjeta.
- [ ] P04-02 Vídeo: evaluar Jellyfin si aporta reproducción/clientes; medir transcodificación antes de dimensionar.
- [ ] P04-03 Descargas: elegir frontend mantenido para yt-dlp; probar login, cuotas y aislamiento antes de integrarlo. No desarrollar backend propio por defecto.
- [ ] P04-04 Notas/documentación: elegir servicio con UI web, adjuntos y exportación. Joplin Server por sí solo no garantiza la experiencia de editor web buscada.
- [ ] P04-05 Offline: clientes y descargas nativos de cada servicio; Kiwix/Organic Maps en dispositivos, sin prometer caché offline del portal.
Para cada incorporación registrar licencia, mantenimiento, consumo, autenticación, puertos, volúmenes, backup/restore y límites. Añadir un servicio cada vez.
Salida: software seleccionado ejecutado con cuenta normal, descarga/exportación comprobada y documentación de operación.

## P05 — Producción y operación familiar
- [ ] P05-01 Adaptar release de portal Foundry; variables completas sin editar código, imágenes fijas y datos separados por entorno.
- [ ] P05-02 Instalar servicios independientes detrás de entrada privada; sin herramientas dev ni debug.
- [ ] P05-03 Backups consistentes de cada servicio y originales fuera del host; restauración en destino limpio.
- [ ] P05-04 QA familiar con dos usuarios/dispositivos; alta/baja y fallo de servicio sin tumbar índice.
- [ ] P05-05 Medir disco/CPU/RAM/tráfico, registrar costes y aportaciones opcionales, sin cobros automáticos.
- [ ] P05-06 CI proporcional: checks de Compose ante cambios de infraestructura, app ante código; una publicación coherente y sin reruns innecesarios.
Salida: configuración reproducible, TLS/VPN probado, backups recuperables y responsable de mantenimiento. Despliegue real requiere host/configuración autorizados.

## Evidencia por tarea
Registrar commit/PR, comando, resultado, entorno, pruebas pendientes y siguiente acción. Preservar cambios de otras sesiones. No reabrir Hxx salvo nueva decisión explícita.

## P03-06 — Identidad y aprovisionamiento (nuevo alcance autorizado)
- [x] P03-06a Seleccionar servicios en invitaciones y crear cuentas Navidrome normales con correo como usuario y contraseña elegida una vez. PR feat/p03-service-accounts.
- [x] P03-06b Sincronizar cambios/reset, reintentar fallos con credencial cifrada efímera, mostrar estados y probar API real de versión fijada.
- [ ] P03-06c Vinculación segura de cuentas preexistentes y alta en servicios añadidos posteriormente. Pedir prueba de titularidad o procedimiento del operador; nunca adoptar por coincidencia de email.
- [ ] P03-06d Baja integral, revocación de accesos, cambios de permisos/bibliotecas y auditoría administrativa.
- [ ] P03-06e Entrada web sin segundo login mediante proxy/identidad central, conservando clientes móviles compatibles. Validar confianza de cabeceras y bypass.

## Orden vigente de los siguientes hitos — 2026-11-11
Decisión confirmada: contenido personal y publicación administrativa tienen prioridad sobre ampliar cuentas o servicios. Plan operativo: [CONTENT_STORAGE.md](CONTENT_STORAGE.md).
1. S01 — comprobar aislamiento personal/global y capacidades reales de Navidrome.
2. S02 — ubicaciones, capacidad compartida, cuotas y reservas para uno o varios discos/particiones.
3. S03 — incorporación privada de contenido con límites y recuperación de fallos.
4. S04 — publicación/retirada administrativa y permisos efectivos del servicio.
5. S05 — movimientos entre volúmenes y backup/restore comprobado.
6. Retomar P03-06c/d y P03-03/05: cuentas, revocación, VPN/TLS y segundo dispositivo.
7. P04/P05: nuevos servicios uno por PR, despliegue reproducible y piloto familiar.
La auditoría de PR #6 es una base transversal pendiente de merge; no se fusiona por aprobar este plan. El puente de sesión de PR #5 ya está integrado; SSO completo sigue pendiente.

### P03-06e.1 — Puente de sesión musical (integrado, QA ampliado pendiente)
- [x] Pase cifrado de 60 segundos emitido para la cuenta musical preparada del usuario autenticado.
- [x] Gateway en el origen de Navidrome, mismo puerto local y activación mediante make up.
- [x] Mantener sesión coincidente; limpiar credenciales y estado musical persistido si no coincide. Fallo cerrado si no se verifica.
- [x] Pruebas JS, autorización/caducidad y proxy real/Range; integración API Navidrome pasa por gateway.
- [ ] QA navegador real: A → B → A, misma cuenta, almacenamiento bloqueado y pestañas simultáneas.
- [ ] QA cliente Subsonic y reproducción/seek con archivos de muestra.
No es SSO ni cierre global: pestañas musicales ya abiertas pueden conservar estado en memoria; otros dispositivos y tokens del servidor no se revocan.

### Ampliación P03: administración de usuarios (PR #5)
CRUD básico administrativo implementado: listado/búsqueda, alta manual existente, edición limitada y baja coordinada de Música. Pendiente aceptación visual. Ver USER_MANAGEMENT.md. Suspensión, auditoría completa y gestión granular de servicios quedan fuera de este incremento.

### P03-06d.1 — Auditoría del CRUD administrativo
Incremento posterior a PR #5: historial protegido de alta manual, edición y baja; persistencia tras eliminar usuarios, metadatos mínimos y escritura transaccional. Implementado en feat/p03-admin-audit, validación en PR. Ver ADMIN_AUDIT.md. No completa P03-06d: quedan permisos por servicio, revocación integral y cobertura de otros flujos. Se prioriza antes de P03-06c para que la próxima ampliación de gestión tenga trazabilidad.
