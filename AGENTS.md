# AGENTS.md — Multisuite

## Producto y alcance
Multisuite es un portal privado de acceso a servicios existentes para el propietario, familiares y conocidos. Laravel + Livewire es la interfaz elegida. Este repositorio es el producto derivado de Laravel Foundry; se versiona la aplicación bajo app/, además de su infraestructura y lockfiles. No confundir el scaffold heredado con funcionalidades implementadas.

## Retomar cualquier sesión
1. Leer README.md, docs/PROJECT_STATUS.md y el hito correspondiente de docs/ROADMAP.md.
2. Inspeccionar git status, rama, diferencias y commits recientes. Preservar cambios concurrentes.
3. Leer instrucciones anidadas y los archivos relevantes. Confirmar versiones en app/composer.lock y el lockfile JS; si app/artisan no existe, empezar por P02.
4. Elegir la primera tarea desbloqueada; indicar alcance y criterio de aceptación. No rehacer trabajo comprobado ni avanzar todos los hitos a la vez.
5. Al terminar, actualizar PROJECT_STATUS con tareas, evidencia, limitaciones y siguiente acción ejecutable. El chat o la memoria privada del agente no son el registro del proyecto.

## Arquitectura y límite de alcance
- Página principal Laravel/Livewire con registro de servicios, tarjetas y rutas de acceso controladas.
- Cada servicio conserva su interfaz, cuentas, catálogo, reproductor, historial y datos. No implementar equivalentes propios ni adaptadores para duplicar catálogos.
- Navidrome es la interfaz completa de música. compose.music.yaml es autónomo y se ejecuta separado de compose.yaml.
- El portal no monta música ni controla Docker. Por petición del usuario, aprovisiona cuentas y sincroniza contraseñas en servicios compatibles. Credenciales administrativas solo en configuración secreta; contraseñas de usuarios cifradas temporalmente para reintentos, con caducidad de una hora y borrado tras éxito. Ver docs/SERVICE_ACCOUNTS.md.
- Una ruta de redirección no es proxy ni SSO. Solo destinos configurados por el administrador; no aceptar redirects arbitrarios.
- Preferir enlaces; subrutas proxy únicamente con compatibilidad validada. Autenticación del portal no protege el acceso directo a los servicios.
- Mantener bibliotecas, volúmenes, backups y autenticación propios de cada servicio; la VPN no sustituye permisos.
- Los hitos activos son P00–P05. H00–H09 quedan sustituidos por la decisión de portal.

## Librerías PHP y JavaScript: autorización explícita
Se pueden instalar librerías de terceros de PHP, JavaScript y CSS cuando agilicen el desarrollo, mejoren la interfaz, accesibilidad o fiabilidad. No pedir autorización repetida para una dependencia normal dentro del alcance autorizado.
Antes de adoptarla:
- Comprobar versión estable compatible con las versiones realmente instaladas.
- Comprobar adopción comunitaria, documentación, mantenimiento y actualizaciones relativamente frecuentes. Revisar releases y actividad de los últimos 12 meses como indicador, sin rechazar automáticamente bibliotecas maduras con menos cambios.
- Revisar licencia libre compatible, avisos de seguridad, dependencias transitivas y tamaño/coste de mantenimiento.
- Comparar brevemente con funciones ya disponibles en Laravel/Livewire y evitar paquetes duplicados.
- Registrar decisión y fuentes fechadas en docs/DEPENDENCIES.md, versionar manifiestos y lockfiles y ejecutar las comprobaciones pertinentes.
No usar betas, forks abandonados o dependencias de pago como base por defecto. Una excepción importante necesita decisión documentada y acuerdo si cambia el alcance/coste. No instalar paquetes globales del host para resolver lo que puede ejecutarse en contenedores.

## Código y datos
- PSR-12, PSR-4 y convenciones del proyecto. Servicios/actions solo si resuelven complejidad real.
- Policies/Gates en cada lectura, modificación, descarga y exportación; filtros de propiedad también en búsquedas, jobs, caché y APIs.
- Form Requests cuando proceda, consultas parametrizadas, protección CSRF y asignación masiva restringida.
- Migraciones incrementales, índices, transacciones e idempotencia; no editar migraciones ya aplicadas.
- Jobs reintentables, cancelación, backoff y timeouts coherentes con retry_after. No duplicar efectos externos.
- Archivos privados fuera de public; nombres controlados, MIME/tamaño verificados, cuotas y extracción segura de archivos comprimidos.
- Markdown/HTML no confiable saneado. Descargas externas con allowlist, defensa SSRF y control de redirecciones/egress; nunca interpolar URLs en shell.
- Nunca versionar secretos, cookies, tokens, bibliotecas personales, dumps ni datos reales de usuarios.

## Interfaz
Portada con categorías y tarjetas de servicios; cada tarjeta abre su aplicación. Responsive, teclado, foco visible, contraste, estados desactivado/error y reduced-motion. No inventar métricas de disponibilidad ni construir reproductor persistente común.
Offline se resuelve con descargas/clientes de cada servicio. La portada Livewire necesita servidor.

## Entornos y operación
Leer docs/ENVIRONMENTS.md, docs/NAVIDROME.md y docs/DEPLOYMENT.md antes de cambiar despliegues.
- Mantener desarrollo Windows/PowerShell y Linux/WSL; runtime y herramientas containerizados.
- Producción debe configurarse por variables y perfiles, sin editar código. Secretos, dominio, TLS, VPN y destino de backup son configuración necesaria del operador.
- Producción: imágenes inmutables, assets compilados, APP_DEBUG=false, volúmenes persistentes, worker/scheduler y healthchecks.
- Ningún servicio de administración, base de datos o desarrollo expuesto públicamente. Entrada privada por VPN; comprobar firewall y puertos realmente publicados.
- No afirmar que la plantilla heredada está lista para producción hasta completar P03 y P05.
- No hacer reset, down -v, cambios de secretos o migraciones destructivas como depuración. Despliegues reales y operaciones destructivas requieren autorización específica, salvo autorización previa aplicable.
- No generar certificados de confianza del host ni modificar hosts sin permiso.

## Comandos y pruebas
Consultar Makefile antes de ejecutar comandos. Bootstrap previsto: make setup STARTER=livewire; Windows sin Make: powershell -NoProfile -File scripts/setup.ps1 -Starter livewire.
Usar make up, make config, make test y make npm CMD="run build" cuando estén disponibles y configurados. No ejecutar bootstrap sobre una app existente como si fuera vacía.
Tests proporcionales: autorización entre dos usuarios, recuperación, jobs y flujos críticos; no tests que solo repitan implementación. No inventar resultados.
Para Compose validar configuración renderizada; para scripts comprobar variantes Unix/PowerShell afectadas.
CI: agrupar cambios coherentes, evitar pushes de prueba, no repetir jobs verdes sin causa. Construir una vez por versión y promover los mismos digests. No desactivar controles necesarios para conseguir verde.
Cada nuevo hito o incremento revisable debe tener su propia rama desde main actualizado y su propia pull request. No integrar directamente en main ni fusionar automáticamente. Esta instrucción sustituye la autorización inicial de escribir en main. Nunca force-push ni sobrescribir cambios ajenos.

## Definición de terminado
Código y docs coherentes; criterios del hito comprobados; pruebas relevantes registradas; permisos y exportación revisados; dependencia anotada; estado actualizado. Distinguir implementado, validado localmente y desplegado. Un bloqueo debe incluir causa, trabajo preservado y acción concreta para resolverlo.
