# Contenido personal, publicación y almacenamiento

Decisión confirmada por el usuario el 2026-11-11. Este orden sustituye las prioridades anteriores de cuentas y nuevos servicios. Estado: planificación aprobada; capacidades de servicios pendientes de validar. No hay cambios de discos, permisos, cuentas ni bibliotecas desplegados.

## Contrato del producto
- Cada usuario incorpora contenido personal. Privado por defecto; el administrador decide qué se publica para todos.
- Propietario, ubicación física y visibilidad son conceptos distintos. Compartir no implica duplicar bytes ni cambiar de propietario.
- El servicio debe hacer cumplir el aislamiento en web, APIs, clientes móviles y descargas. Ocultar enlaces en el portal no sirve como autorización.
- Multisuite coordina políticas y operaciones; las aplicaciones existentes conservan catálogo, reproducción y lectura. No recuperar el antiguo reproductor personalizado.
- El portal no administra particiones, no formatea ni monta discos, ni recibe acceso al socket Docker. Ubicaciones preconfiguradas por el operador y acceso mínimo necesario.
- La posibilidad de introducir contenido desde el portal es alcance autorizado a diseñar, no una subida genérica ya habilitada. Preferir funciones nativas cuando satisfagan privacidad y cuotas.

## S01 — Validar capacidades antes de implementar
- [ ] S01-01 Revisar documentación y código oficiales de Navidrome en la versión fijada por el repositorio. Registrar referencias, versión, limitaciones y decisión.
- [ ] S01-02 Crear laboratorio desechable con dos usuarios normales, bibliotecas A/B/global y archivos sintéticos; no usar datos reales.
- [ ] S01-03 Probar listado, búsqueda, portada, reproducción, descarga y enlaces/API directos cruzados. A no debe descubrir ni leer B. Comprobar clientes Subsonic y tokens ya emitidos.
- [ ] S01-04 Verificar concesión y retirada de biblioteca global, escaneo, identidad estable de archivos y persistencia de listas.
- [ ] S01-05 Verificar quién puede subir, cómo se validan archivos y si existen cuotas efectivas. No presuponer estas capacidades.
Salida: matriz de capacidades comprobada. Si falla el aislamiento, documentar alternativas (otra versión, instancias separadas o servicio distinto) y costes antes de implementar. No anunciar privacidad hasta pasar pruebas negativas.

## S02 — Modelo de almacenamiento y cuotas
- [ ] Registrar ubicaciones con ID estable, ruta administrada, disponibilidad, capacidad y grupo de capacidad compartida.
- [ ] Distinguir disco físico, sistema de archivos/partición y directorio. Varias carpetas/bind mounts sobre el mismo sistema de archivos no suman capacidad.
- [ ] Un disco: carpetas y cuotas lógicas. Particiones distintas: reservas independientes, mismo dominio de fallo físico. Varios discos: ubicaciones independientes; sin pooling/RAID automático.
- [ ] Inventariar propiedad, servicio, ubicación, tamaño confirmado y visibilidad; no duplicar catálogo multimedia.
- [ ] Reservar espacio de forma atómica para subidas simultáneas, temporales y derivados; verificar también espacio real. Liberar reservas caducadas con reconciliación.
- [ ] Definir límite por usuario, reserva mínima del volumen, límite global y tratamiento de disco desconectado/solo lectura. Ante capacidad desconocida, bloquear nuevas escrituras.
- [ ] Mostrar consumo y límites comprensibles. Ficheros externos al portal también reducen espacio disponible.
Salida: pruebas con un volumen, dos carpetas del mismo volumen, dos volúmenes, concurrencia, falta de espacio y desconexión. Nunca aceptar rutas proporcionadas por usuarios.

## S03 — Incorporación privada de contenido
- [ ] Elegir subida nativa del servicio o componente mínimo aislado con escritura solo en ubicaciones autorizadas; documentar decisión.
- [ ] Verificar autenticación, propiedad, límite de tamaño, contenido real y nombre seguro; impedir traversal y escapes mediante symlinks.
- [ ] Si se admiten archivos comprimidos, limitar expansión, profundidad y entradas; mantenerlos deshabilitados hasta soportarlos de forma segura.
- [ ] Recibir en staging privado con reserva, validar, finalizar de forma atómica y solicitar escaneo. Ante fallo no publicar archivos parciales.
- [ ] Reconciliar subidas interrumpidas, duplicados y contabilidad. Auditoría sin contenido ni credenciales.
Salida: dos usuarios suben sin acceso cruzado; pruebas de cancelación, concurrencia y ENOSPC. No incorporar antivirus o servicios externos sin una decisión de alcance y operación.

## S04 — Publicación administrativa
- [ ] Estados propuestos: personal → pendiente de publicación → global; rechazo devuelve a personal. El propietario puede proponer; solo el administrador publica/retira.
- [ ] Aplicar permisos en el servicio antes de dar la operación por confirmada; verificar APIs y clientes existentes tras cambios.
- [ ] Registrar actor, propietario, servicio, transición y resultado; evitar duplicados por reintento.
- [ ] Resolver explícitamente eliminación por el propietario de contenido publicado y baja del usuario aportante. Hasta definirlo, no permitir borrado automático de contenido global.
- [ ] Aprobar política de cuotas: propuesta, contenido personal carga al usuario y global a la reserva compartida; una copia física se cuenta una sola vez. No implementar cambio contable implícito.
Salida: publicación/retirada sin fuga de datos, sin duplicación innecesaria y con recuperación ante fallo remoto.

## S05 — Movimientos y recuperación
- [ ] Copiar entre volúmenes con reserva en destino, verificar integridad y cambiar referencias; borrar origen solo tras confirmación verificable.
- [ ] Conservar propietario, visibilidad y relaciones del servicio; evitar pérdida de playlists/progreso al cambiar rutas.
- [ ] Backup consistente de DB portal, datos de aplicaciones y originales; restaurar en destino limpio y probar permisos.
- [ ] Distinguir redundancia de backup. Otra partición del mismo disco no protege ante fallo físico. Acordar retención y destino externo antes del piloto.
Salida: ensayo de restore y traslado interrumpido con archivos de muestra, sin pérdidas ni referencias huérfanas.

## Después
Retomar P03-06c/d (cuentas/permisos), P03-03/05 (VPN/TLS/segundo dispositivo), P04 (un servicio por PR) y P05 (producción/piloto/costes), incorporando este contrato en cada integración. El acceso web unificado sigue separado de la privacidad del contenido.

## Relevo para agentes
1. Leer AGENTS.md, PROJECT_STATUS.md, este plan y la configuración real; preservar cambios concurrentes.
2. Primera tarea ejecutable: S01-01/02. Nueva rama desde main actualizado y PR propia. La PR de auditoría #6 sigue independiente y no está fusionada.
3. Registrar evidencias y límites por tarea. No marcar un hito completo por tener Compose o documentación.
4. Aún no se conoce el inventario real de discos, capacidad, usuarios o topología. Diseñar escenarios reproducibles; pedir esos datos al preparar el despliegue, sin inventarlos ni bloquear el laboratorio.
5. Antes de S04 cerrar políticas de eliminación, revisión de contenido personal y cuotas globales con el usuario. Su autorización para este plan no equivale a ejecutar operaciones destructivas sobre bibliotecas reales.
