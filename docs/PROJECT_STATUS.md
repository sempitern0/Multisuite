# Estado de Multisuite

Instantánea a 2026-11-15. Historial detallado: git log y PRs #1–#31. Este archivo se **reescribe** (no se acumula) al cerrar cada incremento.

## Integrado en `main` (PRs #1–#31)
| Área | Qué hay | Validación |
| --- | --- | --- |
| Portal | Portada «Servicios» (`DashboardController` + `ServiceTiles`), `/services/{id}` con destinos administrados | CI; QA visual del usuario pendiente |
| Ajustes de interfaz y navegación (D03–D04) | Fondos vectoriales de tarjetas, Mayús + clic para rangos, menú contraíble, flecha atrás, pestañas y barra de selección contextual que no desplaza la lista | CI + usuario |
| Cifrado en reposo (S03.7) | Archivos y ZIP cifrados con XChaCha20-Poly1305 por bloques y clave por archivo derivada de `NAS_FILES_KEY` (solo en `app/.env`) | CI; **pendiente del usuario**: guardar `NAS_FILES_KEY` fuera del servidor |
| Fotos, vídeo y explorador (S03.5–S03.6) | Visor con zoom, vídeo en el navegador, JPEG sin pérdida, subida por fragmentos con bandeja y arrastrar y soltar, explorador con búsqueda, filtros, orden y vistas Detalles/Lista/Galería; Almacenamiento en tablas | CI + usuario (probado en main) |
| Copia de seguridad (S05.1) | `make nas-backup` con restic a destino externo, ensayo de restauración y estado en Almacenamiento | CI con Docker simulado; ninguna copia real aún |
| Modelo de datos | Índices de claves foráneas, consultas agregadas y `QueryPlanTest` en PostgreSQL; `/` redirige a entrar o a la página de inicio | CI |
| Perfil y Seguridad (D02b.2b) | Perfil, contraseña, 2FA y llaves de acceso en el tema; fin de la migración de diseño | CI; QA con dispositivos reales pendiente |
| Mis archivos (D02b.2a) | Explorador, vista previa, papelera y Descargas ZIP como páginas Livewire con el tema, sin recargas | CI; subida real en navegador pendiente del usuario |
| Administración (D02b.1) | Personas (crear/editar/eliminar) y Almacenamiento como páginas Livewire sobre Actions de entrada única; diálogo de confirmación del tema | CI; QA del usuario pendiente |
| Interfaz (D02a) | Listas Livewire (Personas, Invitaciones, Actividad), navegación SPA, avisos y Preferencias | CI; QA del usuario pendiente |
| Diseño (D01) | Capa de tema Nocturne sustituible: `x-ui::` + `themes/nocturne` + `nocturne.css`; navegación calculada en `Support/Navigation`; acceso, 2FA, contraseñas, invitación y portada migrados; contraste AA y foco comprobados | CI (`DesignSystemTest`) + capturas estáticas 1440/768/390 px; pendiente revisión del usuario en la app real |
| Acceso | Invitaciones firmadas, roles owner/admin/member, alta manual, username o correo, 2FA/passkeys | CI + usuario |
| Cuentas de servicio | Aprovisionamiento Navidrome 0.64.2 con reintentos cifrados efímeros | CI con Navidrome real |
| Sesión de Música | Gateway Nginx + pase cifrado de 60 s; no es SSO | CI; QA navegador pendiente |
| Administración | CRUD de usuarios, auditoría administrativa (incluye `storage.*`) | CI |
| Música | `compose.music.yaml` autónomo, ciclo integrado con `make up` | Usuario |
| Aislamiento | Laboratorio S01 (30 comprobaciones reales) | CI *Library isolation* |
| Almacenamiento (S02) | Inventario, reservas/cuotas transaccionales, página **Almacenamiento** en `/admin/storage` y `make nas-discover`/`nas-apply` | CI + usuario (GUI); scripts de discos pendientes en servidor real |
| Mis archivos (S03.1–S03.4) | Carpetas con elementos y tamaño exacto, subida, descarga, vista previa de solo lectura, papelera y versiones, descargas ZIP (selección, carpeta, todo) con reserva temporal y `portal:exports-run`; limitadores de peticiones con nombre por área | CI (`PersonalFilesTest`, `FileExportsTest`); QA del usuario pendiente |
| Entorno de desarrollo | `make up` prepara NAS de desarrollo y ejecuta `portal:dev-bootstrap` | CI + arranque real del usuario |

Decisiones vigentes: Mis archivos es del portal (excepción acotada en AGENTS.md); entorno local desechable mientras no haya producción; el diseño va por delante para que lo nuevo nazca con el tema; sin librerías de tablas por ahora (D010 en DEPENDENCIES.md).

## En revisión: U03 Gestión de personas — rama `feat/u03-user-admin`
- Ficha de persona: cambiar su nombre de usuario (también en Música, sin tocar su contraseña), activar y desactivar cada servicio, suspender y reactivar la cuenta, retirar su foto.
- Desactivar o suspender bloquea la entrada en el propio servicio y conserva sus datos; al reactivar, los servicios vuelven en su próximo acceso con contraseña. Una cuenta suspendida no puede entrar y sus sesiones se cierran.
- Foto de perfil con editor de encuadre propio; el servidor guarda un WebP de 256 px sin metadatos en el disco privado, visible solo para la persona y los administradores.
- Arquitectura: cada edición es una Action (la interfaz es un adaptador) y los servicios se tocan a través del puerto `ServiceProvisioner` con un adaptador por servicio.
- Validación: CI (pruebas nuevas en `UserAdministrationTest` e integración real con Navidrome para renombrar y bloquear). No probado aún en el portal real ni en móvil. Necesita `make rebuild` (GD con WebP).

## Siguiente acción ejecutable
1. Usuario: `make rebuild` y QA de U03 (ROADMAP § U03).
2. Usuario: ensayo de copia de seguridad desde WSL (`make nas-backup`, `make nas-backup-verify`) y QA pendiente de D02/D03/D04.
3. Siguiente hito: S04 necesita tres decisiones del usuario (ROADMAP); si no, P03.

## Límites conocidos
- Producción no desplegada; VPN/TLS, copia de seguridad real programada y piloto pendientes (P03/P05).
- La biblioteca habitual de Navidrome es común: no hay contenido privado por usuario en el despliegue actual.
- Las cuotas controlan lo que entra por el portal; archivos añadidos por fuera reducen el espacio físico pero no se imputan a nadie.
- Inventario real de discos, capacidad y host del usuario: desconocido; pedirlo al preparar el despliegue.
