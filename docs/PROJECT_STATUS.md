# Estado de Multisuite
2026-11-08. Decisión vigente: portal Laravel/Livewire de enlaces a servicios existentes. Sustituye Hxx; ver [ROADMAP.md](ROADMAP.md).

| Hito | Estado | Evidencia |
| --- | --- | --- |
| P00 | Completado | private-nas revisado; cambio de alcance documentado |
| P01 | En curso | Usuario confirma Navidrome operativo; ciclo Make integrado, restore y QA completo pendientes |
| P02 | En curso | Starter existente; portada y rutas implementadas; pruebas runtime/QA pendientes |
| P03 | En curso | P03-01 enlace directo elegido; proxy/VPN y segundo dispositivo pendientes |
| P04 | Pendiente | Selección de servicios adicionales |
| P05 | Pendiente | Producción/restore/piloto |

## Rescate realizado
Origen: private-nas rama feat/music-library-player, a97d48ee8872dfcd36ada9b6be364428bc4a90ff.
Rescatado patrón Docker Navidrome, música read-only, persistencia y loopback; separados del storage Laravel.
No portados: catálogo, parsers, sincronización, Svelte player ni agente de host. private-nas no modificado.
Imagen fijada navidrome/navidrome:0.64.2. Usuario confirma que Navidrome funciona; no se ha registrado digest ni versión observada en su dispositivo.

## Próxima acción
P01-03: en equipo con Docker, copiar music.env.example a music.env, crear directorios y ajustar UID/GID; ejecutar comandos de docs/NAVIDROME.md. Completar reproducción/persistencia/restore antes de marcar P01 terminado.
El starter ya está versionado; no repetir bootstrap. Continuar comprobaciones P02-06 y flujo de invitaciones P02-05.

## Validación y límites
Validación estática YAML y documentación. No Docker disponible en entorno del agente: no se ha arrancado ni probado audio, permisos o Compose con su CLI. No hay despliegue ni migración de datos.
Presupuesto, dispositivos y host pendientes; no bloquean preparar portal y prueba local.

## Relevo por iteración
Registrar fecha/agente, rama/commit, IDs de tareas, archivos cambiados, comandos y resultados, pruebas no ejecutadas, bloqueos, cambios sin commit y siguiente paso. Nunca guardar secretos. Completado requiere evidencia de aceptación.

## Iteración de portada
Starter encontrado en main d60f641. Añadidos portal.php, portada y redirección autenticada con URL controlada. Activar PORTAL_MUSIC_ENABLED=true en app/.env; limpiar config. Tests de acceso añadidos, pendientes de ejecución: entorno sin PHP/Docker. Continuar P02-05 (invitaciones/registro) y P02-06 (QA y tests). No repetir bootstrap.

## Iteración de ciclo de vida
Petición: Navidrome junto a make up. Implementado opt-in por presencia de music.env, opt-out MUSIC_AUTO=0 y comandos music-*. No modifica bibliotecas ni credenciales.
Validado: python3 tests/music-lifecycle.py y make -n up OS=Windows_NT MUSIC_AUTO=1; errores de Docker no se silencian. No se ha ejecutado Docker/PowerShell real aquí.
CI anterior (run 42528988764) ya fallaba en compose-healthchecks y https-compose.ps1; su estado no prueba estas nuevas funciones. Se añade validación dedicada al principio del job Unix, sin repetir ejecuciones manualmente.
Próximo paso: usuario ejecuta make up y stop/start; comprobar persistencia de playlist/usuarios. Después completar P02-05 (alta por invitación/registro) y P02-06. P03 mantiene enlace directo; no prometer SSO ni acceso móvil por localhost.

## Iteración P02-06 — CI del producto (en revisión)
Rama: `feat/p02-product-validation`, basada en main `05658e7`.
Se activa CI Laravel desde la raíz: PHP 8.4 compatible con el lockfile, dependencias bloqueadas, build, Pint, PHPStan y tests. Los workflows se filtran por rutas y cancelan ejecuciones obsoletas; no se ejecutan builds por cambios solo documentales.
Infraestructura conserva sus controles, corrige YAML de env_file en producción, aislamiento del checkout de prueba, extracción de secciones y el código de salida residual de PowerShell. app/ debe estar versionada.
Validación runtime pendiente de GitHub Actions: este entorno no dispone de PHP, Docker ni PowerShell. P02-06 sigue abierto hasta superar CI y QA visual. La plantilla de workflow bajo app/.github es inactiva; el workflow autoritativo es .github/workflows/application.yml.
Siguiente acción: revisar CI de esta PR y corregir fallos; después QA visual de P02-03/P02-06 y nueva PR para P02-05 (registro cerrado/invitaciones). Cada incremento nuevo requiere PR; no fusionar automáticamente.

## Iteración P02-05 — Invitaciones
2026-11-09. Rama `feat/p02-invitations`; PR independiente de la validación P02-06.
Implementado: invitación por consola, hash del código, caducidad, reemisión/revocación y consumo atómico al registrar. Formulario requiere código y el servidor rechaza altas libres. No envía mensajes ni modifica cuentas de Navidrome. Operación y QA: docs/INVITATIONS.md.
Pruebas añadidas: registro sin código, incorrecto/caducado/usado/correo distinto, consumo único y comandos del operador. Ejecución local de PHP no disponible; validar en CI antes de fusionar. QA visual y simultaneidad en motor de producción pendientes.
Siguiente paso: completar QA del portal y acceso desde segundo dispositivo (P03), con cuentas independientes y VPN. P02-05 permanece en revisión hasta aceptación.

PR #1 fusionada en main 6a120319: Application CI y ambos jobs de Infrastructure CI superados tras corregir permisos del volumen vendor y la aserción del mensaje preflight. QA visual P02-06 sigue pendiente.

## Revisión P02-05 — Flujo exclusivo y roles (PR #2)
Sustituye el alta con código manual: retirados /register, su vista, enlaces, acción Fortify de alta y portal:invite. Añadidos owner/admin/member, gestión autorizada con correo y rol, correo de invitación y enlace firmado de un solo uso. Rol/correo/emisor solo proceden de DB; se rechazan campos inyectados y se revalidan permisos del emisor al aceptar.
Migración incremental invalida invitaciones antiguas y mantiene usuarios previos como miembros. Bootstrap explícito del primer propietario por consola; docs/INVITATIONS.md contiene pasos y límites. Sin mensajes reales ni cambios en usuarios del servidor durante desarrollo.
Pruebas cubren endpoints eliminados, permisos, firma manipulada, rol inyectado, revocación, reemisión y uso único. Validación runtime pendiente de CI de esta revisión; QA visual/SMTP y concurrencia PostgreSQL pendientes. Continuar en la misma PR #2 hasta aceptar P02-05.

## Iteración P03-06a/b — Cuentas de servicios
Usuario confirma invitaciones y recuperación operativas. PR #2 fusionada en main 14106fa; 45 tests/142 assertions y CI verde. Nueva decisión autorizada: eliminar altas manuales en servicios mediante aprovisionamiento de identidad.
Rama feat/p03-service-accounts desde main. Navidrome 0.64.2 mediante API nativa, selección de servicios en invitación, cuenta normal, identificador remoto reservado, cambios/reset de contraseña y reintentos con secreto cifrado efímero. Documentación y configuración: SERVICE_ACCOUNTS.md. No se han enviado mensajes ni modificado cuentas reales.
Validación de esta iteración pendiente de CI (sin PHP/Docker local). Integración real añadida al job app, un contenedor efímero. Próximos hitos y límites en ROADMAP P03-06. No marcar completo hasta QA de entorno/SMTP/scheduler. Cuentas existentes, SSO y revocación integral pendientes.

PR #3 abierta. CI 42676163302: build, PHPStan, 50 tests/164 assertions e integración real Navidrome (7 assertions) superados; solo formato pendiente en ese commit. El adaptador crea y actualiza credenciales válidas en contenedor 0.64.2. Última revisión añade rechazo de servicios inyectados y diferencia fallos transitorios del login administrativo. Validación final en la PR.

### Corrección de acceso durante aprovisionamiento
Usuario reporta spinner y vuelta a portada: /services/music bloqueaba cuentas pendientes pero la tarjeta ofrecía Abrir. Se desactiva el botón hasta ready, se añade Actualizar estado y diagnóstico de solo lectura portal:accounts-status {email}. Test cubre cuenta ausente, pendiente, configuración, conflicto, caducada y preparada. La causa operativa en el equipo del usuario sigue pendiente de diagnóstico de red/configuración; este cambio corrige la interfaz, no presupone haber reparado su instancia.


### Corrección de arranque de cuentas — 2026-11-10
- Make aplica automáticamente los overlays de cuentas con music.env, crea/reutiliza la red compartida y activa scheduler.
- start reconcilia contenedores existentes; music-up conserva la conexión al recrear. Overrides del host preservados; MUSIC_AUTO=0 mantiene modo independiente.
- Validación: pruebas de despacho Make con Docker simulado; ejecución real Windows/Linux pendiente de CI y QA del operador. No se han tocado cuentas ni datos reales.
- Siguiente comprobación: actualizar rama, make up, config:clear y accounts-status; restablecer contraseña si la credencial pendiente caducó.


### Corrección Windows PowerShell 5.1
- La inspección de una red ausente captura stderr nativo sin abortar antes de crearla. Se comprueban códigos de salida y se conserva el fallo si Docker no permite crear ni consultar la red.
- Regresión Windows añadida: red existente, ausente, creación concurrente y fallo de daemon. Ejecución en CI; PowerShell no está disponible en el entorno local del agente.


### Conservación de perfiles de Compose
- Se elimina --profile scheduler del comando global: reemplazaba COMPOSE_PROFILES e impedía arrancar DB, HTTPS y otros servicios configurados.
- El overlay integrado activa scheduler mediante profiles: !reset []; Compose sigue leyendo los perfiles del .env o del entorno.
- Prueba CI con Compose real: perfiles del .env, prioridad del entorno, scheduler sin perfiles y servicios opcionales excluidos. Despacho Make validado localmente; renderizado real pendiente de CI.


### Alta manual y nombres de usuario
- Se integra el contenido de PR #4 en PR #3: alta owner/admin, jerarquía de roles, contraseña temporal obligatoria y aprovisionamiento solo con contraseña personal.
- Usuario opcional en altas/invitaciones; cuentas previas lo eligen una vez en Perfil con contraseña actual. Migraciones incrementales conservan cuentas existentes.
- Multisuite admite usuario o correo, conserva 2FA y comparte límite de intentos entre alias. Recuperación sigue por correo. Navidrome usa solo su userName; decisión del usuario confirmada.
- Renombrado remoto por UUID con verificación de identidad, conservación de ajustes y reintentos tras respuesta perdida; nunca apropiación de cuentas por coincidencia de nombre.
- QA: migrar, alta manual member/admin, primer cambio, invitación con usuario, login por ambos en portal, renombrado de cuenta existente y login musical. Validación PHP/real Navidrome en CI; no disponibles localmente.


### Ajuste visual de autenticación y tarjetas
- El estado de Música y su usuario aparecen dentro de la tarjeta; se elimina el aviso separado de la portada.
- Autenticación con contenedor más ancho, campos de 52 px, botones de 48 px y texto mayor. Textos más breves; cambio inicial usa «Contraseña actual».
- Selector de recuperación 2FA accesible con teclado. Sin cambios en autenticación, autorización ni sincronización.
- QA pendiente: móvil 320/375 px, escritorio, zoom 200 %, tabulación, errores de formulario y estados de Música dentro de la tarjeta.


## Hito activo P03-06e.1 — Sesión musical en navegador compartido
Base main d753c944, PR #3/#4 fusionadas con CI verde y QA funcional/visual aceptada por el usuario. Se prioriza el proxy/puente antes de continuar vinculación/bajas.
Implementado: gateway sobre el mismo origen/puerto, pase cifrado de 60 s, verificación sin cookies ni credenciales musicales, limpieza local solo cuando no coincide cuenta, arranque integrado y fallback explícito para instalaciones externas. Sin nuevas librerías ni migraciones.
Validación local: 6 tests Node de reconciliación, destino/origen, flujo de verificación y fallos; pruebas de ciclo Make. Docker/PHP no disponibles aquí: validación real en CI de PHP, gateway, Range y Navidrome.
QA del operador y límites: ver docs/MUSIC_SESSION_BRIDGE.md. No hay SSO ni revocación de otros dispositivos/pestañas ya abiertas. No se ha desplegado ni modificado ninguna cuenta real.
