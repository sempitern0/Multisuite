# AGENTS.md — Multisuite

## Producto y alcance
Multisuite es una suite privada para el propietario, familiares y conocidos: música, vídeos, lecturas, notas y archivos exportables. Laravel + Livewire es la interfaz elegida. Este repositorio es el producto derivado de Laravel Foundry; se versiona la aplicación bajo app/, además de su infraestructura y lockfiles. No confundir el scaffold heredado con funcionalidades implementadas.

## Retomar cualquier sesión
1. Leer README.md, docs/PROJECT_STATUS.md y el hito correspondiente de docs/ROADMAP.md.
2. Inspeccionar git status, rama, diferencias y commits recientes. Preservar cambios concurrentes.
3. Leer instrucciones anidadas y los archivos relevantes. Confirmar versiones en app/composer.lock y el lockfile JS; si app/artisan no existe, empezar por H01.
4. Elegir la primera tarea desbloqueada; indicar alcance y criterio de aceptación. No rehacer trabajo comprobado ni avanzar todos los hitos a la vez.
5. Al terminar, actualizar PROJECT_STATUS con tareas, evidencia, limitaciones y siguiente acción ejecutable. El chat o la memoria privada del agente no son el registro del proyecto.

## Arquitectura
- Monolito Laravel convencional, Blade/Livewire y JavaScript/Alpine para interacción local. No añadir otra SPA sin una decisión explícita.
- PostgreSQL en desarrollo, pruebas de integración y producción. Colas database al inicio; Redis solo con necesidad comprobada.
- Laravel gobierna permisos, catálogo y tareas. Navidrome y Komga son servicios especializados con adaptadores; nunca escribir directamente sus bases de datos.
- Un usuario autenticado por persona; grupos y permisos por recurso. Compartir biblioteca no comparte notas, historial ni credenciales.
- Streaming por servicio especializado o servidor web autorizado, con HTTP Range. No transmitir grandes archivos dentro de componentes Livewire.
- Trabajo multimedia en procesos aislados, con límites y cola independiente. No dar acceso al socket Docker desde la aplicación.
- Datos originales exportables y respaldos independientes de la aplicación. La VPN no sustituye autorización.

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
Priorizar Inicio/Continuar, Música, Vídeos, Lecturas, Notas y Descargas. Responsive desde móvil, teclado completo, foco visible, etiquetas, contraste, estados vacíos/error/carga y reduced-motion. No autoplay inesperado.
Persistir reproductor al navegar cuando la versión instalada lo permita. Guardar progreso de forma espaciada y al pausar; no una petición por segundo.
Livewire necesita servidor. Una PWA no implica edición offline: descargas y estado offline se verifican en dispositivos reales.

## Entornos y operación
Leer docs/ENVIRONMENTS.md y docs/DEPLOYMENT.md antes de cambiar despliegues.
- Mantener desarrollo Windows/PowerShell y Linux/WSL; runtime y herramientas containerizados.
- Producción debe configurarse por variables y perfiles, sin editar código. Secretos, dominio, TLS, VPN y destino de backup son configuración necesaria del operador.
- Producción: imágenes inmutables, assets compilados, APP_DEBUG=false, volúmenes persistentes, worker/scheduler y healthchecks.
- Ningún servicio de administración, base de datos o desarrollo expuesto públicamente. Entrada privada por VPN; comprobar firewall y puertos realmente publicados.
- No afirmar que la plantilla heredada está lista para producción hasta completar H02 y H09.
- No hacer reset, down -v, cambios de secretos o migraciones destructivas como depuración. Despliegues reales y operaciones destructivas requieren autorización específica, salvo autorización previa aplicable.
- No generar certificados de confianza del host ni modificar hosts sin permiso.

## Comandos y pruebas
Consultar Makefile antes de ejecutar comandos. Bootstrap previsto: make setup STARTER=livewire; Windows sin Make: powershell -NoProfile -File scripts/setup.ps1 -Starter livewire.
Usar make up, make config, make test y make npm CMD="run build" cuando estén disponibles y configurados. No ejecutar bootstrap sobre una app existente como si fuera vacía.
Tests proporcionales: autorización entre dos usuarios, recuperación, jobs y flujos críticos; no tests que solo repitan implementación. No inventar resultados.
Para Compose validar configuración renderizada; para scripts comprobar variantes Unix/PowerShell afectadas.
CI: agrupar cambios coherentes, evitar pushes de prueba, no repetir jobs verdes sin causa. Construir una vez por versión y promover los mismos digests. No desactivar controles necesarios para conseguir verde.
Por defecto trabajar en rama/PR; respetar instrucciones explícitas de escribir en main. Nunca force-push ni sobrescribir cambios ajenos.

## Definición de terminado
Código y docs coherentes; criterios del hito comprobados; pruebas relevantes registradas; permisos y exportación revisados; dependencia anotada; estado actualizado. Distinguir implementado, validado localmente y desplegado. Un bloqueo debe incluir causa, trabajo preservado y acción concreta para resolverlo.
