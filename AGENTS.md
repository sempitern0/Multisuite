# AGENTS.md — Multisuite

Fuente común de instrucciones para cualquier agente de IA o colaborador. CLAUDE.md la importa; no duplicar reglas en otros archivos.

## Producto en una frase
NAS doméstico con portal privado Laravel + Livewire para el propietario, familia y amistades: portada de servicios de terceros, invitaciones y cuentas, reparto de almacenamiento y auditoría. Derivado de Laravel Foundry; se versiona `app/`, su infraestructura y los lockfiles. No confundir el scaffold heredado con funcionalidades implementadas.

## Retomar una sesión (en este orden)
1. Leer [docs/PROJECT_STATUS.md](docs/PROJECT_STATUS.md) (instantánea actual) y la sección correspondiente de [docs/ROADMAP.md](docs/ROADMAP.md) (solo pendientes).
2. Leer [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) para localizar código y el documento del dominio que vayas a tocar (p. ej. [docs/STORAGE.md](docs/STORAGE.md)).
3. `git status`, rama, diff y commits recientes. Preservar cambios concurrentes.
4. Confirmar versiones reales en `app/composer.lock` y `app/package-lock.json` antes de usar APIs de Laravel/Livewire/Flux.
5. Elegir la primera tarea desbloqueada; declarar alcance y criterio de aceptación. No rehacer trabajo comprobado ni avanzar varios hitos a la vez.
6. Al terminar: **reescribir** PROJECT_STATUS (instantánea, no diario), marcar o retirar tareas del ROADMAP y actualizar el documento del dominio. El chat o la memoria privada del agente no son el registro del proyecto.

## Mantenimiento de la documentación
- `docs/` describe el estado actual. Lo completado se resume en una línea en PROJECT_STATUS; el detalle vive en git/PRs. No acumular bitácoras de iteraciones, planes sustituidos ni listas de QA ya superadas.
- Un documento por dominio (STORAGE, USER_MANAGEMENT, INVITATIONS, ADMIN_AUDIT, NAVIDROME, SERVICE_ACCOUNTS, MUSIC_SESSION_BRIDGE, ENVIRONMENTS, DEPENDENCIES). Ampliar el existente antes de crear otro.
- `docs/foundry/` son guías heredadas de la plantilla; adaptarlas, no tratarlas como estado del producto. CI comprueba algunas rutas de docs (`.github/workflows/ci.yml`): si mueves un documento, actualiza CI y referencias en la misma PR.
- Distinguir siempre *implementado*, *validado en CI*, *validado por el usuario* y *desplegado*.

## Arquitectura y límites de alcance
- Portada Laravel/Livewire con registro de servicios (`config/portal.php`), tarjetas y rutas de acceso controladas.
- Cada servicio conserva interfaz, cuentas, catálogo, reproductor, historial y datos. No implementar equivalentes propios (reproductor, lector de libros/cómics, catálogo multimedia, notas, descargador) ni adaptadores que dupliquen catálogos.
- Excepción decidida por el usuario el 2026-11-13: **Mis archivos** es del portal (carpetas privadas, subida, descarga, ZIP, papelera/versiones y exportación) con **vista previa de solo lectura** de imágenes, PDF (visor nativo del navegador), Markdown saneado y texto. Sin editores, ofimática online, transcodificación ni galerías: eso sigue siendo de servicios de terceros. Ver docs/STORAGE.md.
- Navidrome es la interfaz completa de música; `compose.music.yaml` es autónomo y se ejecuta separado de `compose.yaml`.
- El portal no monta discos, no formatea, no recibe el socket Docker y nunca acepta rutas de usuarios. Las ubicaciones NAS las declara el operador (`NAS_STORAGE_VOLUMES`); la GUI solo reparte espacio. En desarrollo, `deploy/compose.nas-dev.yaml` (aplicado por Make) prepara un volumen privado sin pasos manuales. Ver docs/STORAGE.md.
- El portal aprovisiona cuentas y sincroniza contraseñas en servicios compatibles. Credenciales administrativas solo en configuración secreta; contraseñas de usuarios cifradas temporalmente para reintentos, con caducidad de una hora y borrado tras éxito (docs/SERVICE_ACCOUNTS.md).
- Una ruta de redirección no es proxy ni SSO. Solo destinos configurados por el administrador; no aceptar redirects arbitrarios.
- El puente de sesión musical usa un gateway Nginx y un pase cifrado breve; no es SSO, no copia tokens a Laravel ni revoca otros dispositivos. Mantener compatibilidad con Navidrome 0.64.2 y pruebas de proxy/Range (docs/MUSIC_SESSION_BRIDGE.md).
- Preferir enlaces; subrutas proxy solo con compatibilidad validada. La autenticación del portal no protege el acceso directo a los servicios.
- Contenido privado por defecto; solo el administrador publica para todos. El aislamiento lo aplica el servicio; ocultar enlaces no es autorización.
- Mantener bibliotecas, volúmenes, backups y autenticación propios de cada servicio; la VPN no sustituye permisos.

## Librerías PHP y JavaScript: autorización explícita
Se pueden instalar librerías de terceros de PHP, JavaScript y CSS cuando agilicen el desarrollo, mejoren la interfaz, accesibilidad o fiabilidad. No pedir autorización repetida para una dependencia normal dentro del alcance autorizado.
Antes de adoptarla:
- Comprobar versión estable compatible con las versiones realmente instaladas.
- Comprobar adopción comunitaria, documentación, mantenimiento y actualizaciones relativamente frecuentes. Revisar releases y actividad de los últimos 12 meses como indicador, sin rechazar automáticamente bibliotecas maduras con menos cambios.
- Revisar licencia libre compatible, avisos de seguridad, dependencias transitivas y tamaño/coste de mantenimiento.
- Comparar con funciones ya disponibles en Laravel/Livewire/Flux y evitar paquetes duplicados.
- Registrar decisión y fuentes fechadas en docs/DEPENDENCIES.md, versionar manifiestos y lockfiles y ejecutar las comprobaciones pertinentes.
No usar betas, forks abandonados o dependencias de pago como base por defecto. Una excepción importante necesita decisión documentada y acuerdo si cambia el alcance/coste. No instalar paquetes globales del host para resolver lo que puede ejecutarse en contenedores.

## Código y datos
- PSR-12, PSR-4, Pint (preset laravel) y PHPStan nivel 7. Servicios/actions solo si resuelven complejidad real.
- Policies/Gates en cada lectura, modificación, descarga y exportación; filtros de propiedad también en búsquedas, jobs, caché y APIs. Revalidar roles bajo bloqueo en operaciones administrativas.
- Form Requests o validación explícita, consultas parametrizadas, CSRF y asignación masiva restringida.
- Migraciones incrementales, índices, transacciones e idempotencia. No editar migraciones ya fusionadas en `main`; dentro de una PR abierta se pueden corregir.
- Jobs reintentables, cancelación, backoff y timeouts coherentes con retry_after. No duplicar efectos externos.
- Archivos privados fuera de public; nombres controlados, MIME/tamaño verificados, cuotas y extracción segura de comprimidos. Fallar cerrado ante capacidad desconocida.
- Auditoría con lista blanca de campos; nunca payloads, rutas, contraseñas ni respuestas remotas.
- Markdown/HTML no confiable saneado. Descargas externas con allowlist, defensa SSRF y control de redirecciones/egress; nunca interpolar URLs en shell.
- Nunca versionar secretos, cookies, tokens, bibliotecas personales, dumps ni datos reales de usuarios.

## Interfaz
- Español claro para personas sin conocimientos técnicos: opciones predefinidas, unidades humanas, mensajes de error junto al control que los causa y explicación breve de consecuencias.
- Portada con categorías y tarjetas; cada tarjeta abre su aplicación. Responsive, teclado, foco visible, contraste, estados desactivado/error y reduced-motion. No inventar métricas de disponibilidad ni construir reproductor persistente común.
- Offline se resuelve con descargas/clientes de cada servicio. La portada Livewire necesita servidor.

## Entornos y operación
Leer docs/ENVIRONMENTS.md, docs/NAVIDROME.md y docs/foundry/DEPLOYMENT.md antes de cambiar despliegues.
- Mantener desarrollo Windows/PowerShell y Linux/WSL; runtime y herramientas containerizados.
- Automatizar por defecto: `make build`/`make up` deben dejar el entorno de desarrollo listo (migraciones, ubicación privada, límites iniciales) sin crear IDs, marcas ni permisos a mano. Lo que no se pueda automatizar se documenta en README como paso único.
- Producción configurable por variables y perfiles, sin editar código. Secretos, dominio, TLS, VPN y destino de backup son configuración del operador.
- Producción: imágenes inmutables, assets compilados, APP_DEBUG=false, volúmenes persistentes, worker/scheduler y healthchecks.
- Ningún servicio de administración, base de datos o desarrollo expuesto públicamente. Entrada privada por VPN; comprobar firewall y puertos publicados.
- No afirmar que la plantilla heredada está lista para producción hasta completar P03 y P05.
- El entorno local de desarrollo es desechable mientras no exista producción (decisión del usuario 2026-11-13): se puede recrear (`make rebuild`, `make reset-project` + `make build`, `migrate:fresh` en la DB de desarrollo, borrar volúmenes Docker del propio proyecto) cuando acelere el trabajo, avisando de lo que se pierde. Nunca borrar bibliotecas o carpetas montadas desde el host, archivos fuera del repositorio ni recursos de otros proyectos o contenedores.
- Producción, datos reales de personas, secretos reales y despliegues requieren autorización específica; ahí no se depura con reset, `down -v` ni migraciones destructivas.
- No generar certificados de confianza del host ni modificar hosts sin permiso.

## Comandos y pruebas
Consultar el Makefile antes de ejecutar comandos: `make up`, `make test CMD="--filter=…"`, `make artisan CMD="…"`, `make npm CMD="run build"`, `make lint`, `make music-*`. No ejecutar bootstrap (`make setup`) sobre la app existente.
Tests proporcionales: autorización entre dos usuarios, recuperación, jobs y flujos críticos; no tests que solo repitan implementación. No inventar resultados: si el entorno no tiene PHP/Docker, decirlo y validar en CI. Pedir comprobaciones manuales al usuario solo para lo que CI no puede validar (visual, dispositivos reales, red local).
Para Compose validar configuración renderizada; para scripts comprobar variantes Unix/PowerShell afectadas.
CI: agrupar cambios coherentes, evitar pushes de prueba, no repetir jobs verdes sin causa. Construir una vez por versión y promover los mismos digests. No desactivar controles necesarios para conseguir verde.
Cada hito o incremento revisable tiene su propia rama desde `main` actualizado y su propia pull request. Si depende de una PR aún sin fusionar, se apila (base = rama de esa PR) y se indica en la descripción. No integrar directamente en `main` ni fusionar automáticamente. Nunca force-push ni sobrescribir cambios ajenos.

## Definición de terminado
Código y docs coherentes; criterios del hito comprobados; pruebas relevantes registradas; permisos y exportación revisados; dependencia anotada; PROJECT_STATUS reescrito y ROADMAP actualizado. Un bloqueo debe incluir causa, trabajo preservado y acción concreta para resolverlo.
