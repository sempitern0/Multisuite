# Multisuite — Planning por hitos
Fecha: 2026-11-08. Plan de producto, no declaración de funcionalidades implementadas.
Estado operativo: [PROJECT_STATUS.md](PROJECT_STATUS.md). Reglas: [../AGENTS.md](../AGENTS.md). Entornos: [ENVIRONMENTS.md](ENVIRONMENTS.md).

## Visión y alcance
Suite privada para propietario, familiares y conocidos, con costes asumidos o compartidos. Un portal Laravel/Livewire reúne música, vídeo, lectura, notas y descargas. Los archivos originales y las exportaciones permiten salir de la plataforma. El acceso remoto inicial es privado mediante VPN; el uso offline conserva copias en cada dispositivo.

MVP familiar: H00–H04 más la validación H09 aplicada a ese alcance. Lecturas y vídeo son ampliaciones H05–H06; experiencia transversal H07 y offline H08. No vender como completas funcionalidades pendientes. No se incluyen al inicio pagos, recomendaciones mediante IA, federación, editor colaborativo, nube pública ni clon completo de YouTube.

## Reanudación y estados
Estados: pendiente → en curso → bloqueado o en revisión → completado. Completar solo con evidencia del criterio de salida.
Cada tarea tiene ID estable Hxx-nn. Marcar checkboxes cuando esté terminada, enlazar commit/PR y pruebas en PROJECT_STATUS. Si queda a medias, conservar rama, cambios, comando fallido y siguiente paso. Las tareas dependientes esperan a su predecesora; los arreglos urgentes pueden interrumpir el orden dejando registro.
Estimaciones y fechas de entrega se fijarán tras H01; no convertir este planning en promesa de calendario.

| Hito | Dependencias | Entrega |
| --- | --- | --- |
| H00 | Ninguna | Plan y reglas de continuidad |
| H01 | H00 | App Livewire local reproducible |
| H02 | H01 | Producción privada reproducible y recuperación |
| H03 | H01 | Identidad, permisos, catálogo y diseño base |
| H04 | H02, H03 | Música y notas útiles para el piloto |
| H05 | H03 y entorno H02 | EPUB, cómics y manga |
| H06 | H02, H03 | Importación y reproducción de vídeo/audio |
| H07 | H04; H05/H06 para integrar sus módulos | Experiencia común y progreso |
| H08 | H04; módulos que se quieran descargar | Disponibilidad offline comprobada |
| H09 | H02 y todos los hitos del alcance de release | Aceptación familiar y operación |

## H00 — Contrato del proyecto
- [x] H00-01 Definir alcance y arquitectura Laravel/Livewire basada en Foundry.
- [x] H00-02 Mantener AGENTS.md común y CLAUDE.md con importación.
- [x] H00-03 Registrar política de dependencias, criterios de entornos y protocolo de relevo.
Salida: documentos enlazados y publicados juntos, sin confundir planificación con despliegue. Registrar el commit real en Git; no inventar SHA anticipado.

## H01 — Bootstrap y desarrollo reproducible
- [ ] H01-01 Auditar scaffold, scripts, workflow, versiones PHP/Node/PostgreSQL; revisar licencias y estado de soporte antes de fijarlas.
- [ ] H01-02 Generar starter Livewire bajo app/ en checkout limpio, nombre Multisuite, PostgreSQL y colas database. No usar reset sobre datos existentes.
- [ ] H01-03 Versionar fuente, migraciones, manifiestos, lockfiles y configuración portable sin secretos. Adaptar README con comandos ejecutados.
- [ ] H01-04 Preparar fixtures sintéticas y configuración de tests aislada. Confirmar inicio sin datos reales ni servicios multimedia.
- [ ] H01-05 Verificar clon nuevo en Linux y flujo Windows/PowerShell o registrar explícitamente la plataforma aún no comprobada.
- [ ] H01-06 Adaptar CI a producto: checks baratos primero, tests PHP/frontend, build y controles de infraestructura afectados. Filtrar cambios documentales sin dejar checks obligatorios pendientes.
Salida: otro agente clona, configura variables y abre la app con login; reinicia conservando datos; build frontend y tests relevantes pasan. Guardar comandos, versiones y resultados.

## H02 — Producción privada desde el principio
- [ ] H02-01 Aplicar contrato de ENVIRONMENTS.md: dev, paridad local, staging y producción con secretos/volúmenes independientes.
- [ ] H02-02 Adaptar Compose de producción y ejemplos: VPN bind, TLS confiable, disco privado, healthchecks HTTP, readiness y límites.
- [ ] H02-03 Elegir Tailscale para piloto o documentar Headscale/NetBird; revisar planes actuales y dispositivos. Denegar acceso lateral y probar revocación.
- [ ] H02-04 Construir imágenes PHP/web inmutables; ejecutar localmente con configuración de producción y promover las mismas a staging.
- [ ] H02-05 Crear comprobación previa que falle ante secretos placeholder, debug activo, variables ausentes o puertos indebidos; no imprimir secretos.
- [ ] H02-06 Respaldar DB, archivos, configuración recuperable y secretos necesarios cifrados fuera del servidor. Restaurar en instancia aislada.
- [ ] H02-07 Documentar instalación, actualización, migración, rollback de código y recuperación de datos. No asumir rollback de esquema automático.
Salida: clon → configuración del operador → despliegue sin editar código; acceso VPN+HTTPS, login y jobs operativos; acceso externo no autorizado rechazado; backup restaurado.
Bloqueos esperables: host, dominio, VPN, credenciales SMTP/backup. Mientras falten, completar paridad local sin afirmar despliegue real.

## H03 — Usuarios, biblioteca y UI base
- [ ] H03-01 Invitaciones limitadas y caducables, login, recuperación, revocación de sesiones y administración protegida; registro público cerrado.
- [ ] H03-02 Modelar grupos, miembros, bibliotecas, elementos, adjuntos y permisos con Policies. Usuario individual, contenido compartido y privado.
- [ ] H03-03 Definir catálogo normalizado: tipo, proveedor, ID externo, propietario, permisos y metadatos. Evitar copiar bases completas de terceros.
- [ ] H03-04 Subidas privadas con MIME/tamaño, cuotas, nombres internos y descarga autorizada. Proteger preview, búsqueda y exportación.
- [ ] H03-05 Layout responsive: Inicio, Música, Vídeos, Lecturas, Notas y Descargas; ocultar módulos no disponibles. Componentes accesibles y tokens visuales.
- [ ] H03-06 Pruebas adversarias con dos usuarios y un administrador: lectura, edición, URL directa, búsqueda, cola y exportación cruzadas.
Salida: invitado usa biblioteca compartida sin ver notas ni archivos privados ajenos; teclado/móvil y errores funcionan. Compartir servidor no equivale a compartir toda la información.

## H04 — Primer piloto: música y notas
- [ ] H04-01 Incorporar Navidrome como servicio opcional con volumen persistente; acceso de solo lectura a música cuando sea posible.
- [ ] H04-02 Cuenta por persona, permisos de biblioteca y adaptador API; mantener tokens del lado servidor y registrar compatibilidad.
- [ ] H04-03 Importar música propia de muestra, validar etiquetas, duplicados y carátulas. Escuchar mediante interfaz existente primero.
- [ ] H04-04 Implementar notas Markdown con cuadernos, etiquetas, adjuntos, búsqueda y saneamiento. Control optimista de versión para evitar sobreescritura silenciosa.
- [ ] H04-05 Exportar notas y recursos en ZIP con rutas relativas; exportar/copiar música y playlists sin prometer formatos universales.
- [ ] H04-06 Probar restauración, cambios concurrentes y notas privadas. Documentar clientes móviles libres elegidos y su descarga offline real.
Salida: dos personas escuchan y toman notas; una exportación se puede abrir fuera de Multisuite; fallo de Navidrome no inutiliza notas.
Límite: no construir sincronización compatible con Joplin ni editor colaborativo en este hito.

## H05 — Lecturas
- [ ] H05-01 Integrar Komga con bibliotecas, cuentas, volúmenes y acceso API por usuario; calibre queda como preparación de metadatos/conversión.
- [ ] H05-02 Probar EPUB, PDF, CBZ, manga derecha-izquierda y dobles páginas con muestras permitidas.
- [ ] H05-03 Catálogo y filtros en Livewire; abrir lector existente inicialmente. Decidir lector embebido solo con beneficio demostrado, sin iframes como arquitectura obligatoria.
- [ ] H05-04 Exportación/descarga autorizada y prueba en lector local; proteger extracción frente a traversal/ZIP bombs.
- [ ] H05-05 Registrar capacidades y fuente de progreso del lector. Documentar límites de anotaciones entre aplicaciones.
Salida: lectura cómoda en móvil/escritorio, permisos verificados, archivo descargado abre sin servidor.

## H06 — Vídeo y audio desde enlaces
- [ ] H06-01 Job aislado yt-dlp/FFmpeg con versiones fijadas y mecanismo de actualización probado. Descargas propias o permitidas; sin DRM ni cookies personales compartidas.
- [ ] H06-02 URL de proveedores admitidos; validación SSRF, DNS, redirecciones y bloqueo de destinos privados/metadata; egress restringido sin acceso a DB/red interna.
- [ ] H06-03 Límites de duración, bytes, CPU/RAM, disco temporal y concurrencia; ejecutar sin root y sin socket Docker.
- [ ] H06-04 Estados queued/running/completed/failed/cancelled, idempotencia, retries con backoff y cancelación que detenga el proceso y limpie temporales.
- [ ] H06-05 Formato compatible, subtítulos y procedencia; reproducción con Range, búsqueda temporal, descarga y autorización.
- [ ] H06-06 Simular extractor roto, enlace eliminado, disco lleno, job duplicado y servicio remoto lento; no reintentar indefinidamente.
Salida: una URL válida termina en archivo reproducible; cancelación/fallo no bloquea Laravel ni excede cuotas.
Límite: no garantizar catálogo/búsqueda global de YouTube ni extracción permanente sin mantenimiento. Evaluar Jellyfin después si TV/transcodificación justifican otro servicio.

## H07 — Experiencia unificada
- [ ] H07-01 Reproductor persistente con navegación Livewire; estado inmediato en JS/Alpine, sin peticiones por segundo.
- [ ] H07-02 Favoritos, listas y Continuar; adaptador por servicio con fuente de verdad única y mapeo de identidad.
- [ ] H07-03 Progreso versionado por usuario/contenido; definir conflictos entre dos dispositivos y contenidos eliminados.
- [ ] H07-04 Búsqueda limitada por permisos, paginación y degradación cuando un servicio cae.
- [ ] H07-05 QA de foco, lector de pantalla, teclado, contraste, zoom 200%, reduced-motion y 360/768/1440 px.
Salida: navegar no interrumpe audio; reanudar en otro dispositivo funciona para módulos compatibles; no se mezcla historial entre personas.

## H08 — Offline real
- [ ] H08-01 Selección descargable con tamaño estimado, versión, fecha, checksum y procedencia; límites según espacio.
- [ ] H08-02 Kit Kiwix/documentos/mapas: integrar enlaces y manifiestos; instalar clientes adecuados, no reimplementar motores.
- [ ] H08-03 PWA opcional: shell y caché selectiva, estado offline, gestión de cuotas y borrado local al cerrar sesión según política.
- [ ] H08-04 Mantener descargas independientes de caché del navegador. Avisar que revocar una cuenta no borra archivos ya exportados.
- [ ] H08-05 Ensayar sin Internet, sin VPN y sin servidor; usar batería/dispositivo local. Documentar qué acciones quedan indisponibles.
Salida: música, lectura y kit seleccionados abren sin red; interfaz no promete guardar notas offline si no existe cola local/resolución de conflictos.
Fuera del primer alcance: editor offline bidireccional; añadir ADR e hito propio si se necesita.

## H09 — Release familiar y operación
- [ ] H09-01 Ejecutar checklist transversal y piloto con al menos dos cuentas/dispositivos; registrar incidencias y cerrar bloqueantes.
- [ ] H09-02 Medir RAM/CPU/disco/egress, colas y reproducción concurrente; fijar límites a partir de resultados, no cifras comerciales supuestas.
- [ ] H09-03 Alertas mínimas de disco, backup fallido y servicio caído; logs rotados sin secretos ni contenido privado.
- [ ] H09-04 Ensayar restauración completa, pérdida de dispositivo y baja de usuario; medir RPO/RTO reales.
- [ ] H09-05 Registro de costes servidor+almacenamiento+backup+tráfico+dominio y aportaciones opcionales; sin plataforma de pagos inicial.
- [ ] H09-06 Documentar onboarding, privacidad del administrador, uso permitido, soporte y mantenimiento; revisar licencias y actualizaciones.
Salida: despliegue repetible desde cero, restore documentado, usuarios aceptan los flujos del alcance y existe responsable de operación.

## Contratos de datos e integración
Propuesta a concretar en H03, no migraciones existentes: User, Group, Membership, Library, LibraryItem, Attachment, Note, Favorite, PlaybackProgress, DownloadJob y ServiceAccount.
Laravel conserva permisos, notas y catálogo común. Cada motor conserva sus datos específicos; el adaptador declara campos sincronizados, frecuencia, idempotencia, revocación y comportamiento ante fallo. No usar una cuenta admin compartida como sustituto de permisos individuales.
No implementar SSO propio: comenzar con cuentas por servicio, evaluar proveedor estándar después. Probar especialmente clientes móviles y endpoints que no usan sesión web.

## Checklist transversal de release
- [ ] Dos usuarios no pueden acceder a recursos privados ajenos por UI, URL, API, búsqueda o exportación.
- [ ] Invitación/revocación/recuperación y sesiones funcionan.
- [ ] Archivos perduran al recrear contenedores; datos y backups están separados.
- [ ] Fallo de servicio o worker no tumba todo el portal.
- [ ] Importación, reproducción, búsqueda temporal y exportación funcionan con muestras representativas.
- [ ] Restauración en destino limpio y simulacro offline ejecutados.
- [ ] Navegación móvil y teclado comprobadas; errores recuperables y comprensibles.
- [ ] Producción sin debug ni herramientas dev; puertos, TLS y VPN verificados.
- [ ] Versiones, licencias, costes y mantenimiento documentados.

## Referencias que deben revalidarse al implementar
- Foundry: https://github.com/sempitern0/laravel-foundry
- Livewire: https://livewire.laravel.com/docs
- Navidrome: https://www.navidrome.org/docs/
- Komga: https://komga.org/docs/
- yt-dlp: https://github.com/yt-dlp/yt-dlp
- Tailscale: https://tailscale.com/pricing
- Headscale: https://headscale.net/stable/
- NetBird: https://docs.netbird.io/selfhosted/selfhosted-quickstart
No fijar tarifas ni compatibilidades futuras usando únicamente este documento.
