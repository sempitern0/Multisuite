# Estado de Multisuite

Instantánea a 2026-11-14. Historial detallado: git log y PRs #1–#25. Este archivo se **reescribe** (no se acumula) al cerrar cada incremento.

## Integrado en `main` (PRs #1–#25)
| Área | Qué hay | Validación |
| --- | --- | --- |
| Portal | Portada «Servicios» (`DashboardController` + `ServiceTiles`), `/services/{id}` con destinos administrados | CI; QA visual del usuario pendiente |
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

## En revisión: S03.5 Fotos y vídeo en Mis archivos — rama `feat/s03-media-viewer`
- Visor de imágenes con zoom (botones, rueda, doble clic, pellizco, teclado), arrastre, tamaño real y pantalla completa; al abrir, la imagen se ajusta al espacio disponible y muestra su porcentaje real.
- Subida múltiple con bandeja flotante (resumen, barra total, errores primero), arrastrar y soltar, 3 archivos en paralelo y archivos nuevos resaltados en la lista.
- Tablas de Mis archivos (archivos, papelera, versiones, descargas ZIP) con acciones de solo icono en una fila alineada, nombres largos recortados a dos líneas y tamaños sin partir; formularios de nombre en línea. La celda de acciones y el botón de los formularios en línea se corrigen en el tema, así que también mejoran Personas, Invitaciones y Seguridad.
- Vídeo MP4/WebM/MOV reproducido por el navegador, con avance por tramos; sin conversión en el servidor.
- Subida por fragmentos de 8 MiB con reanudación: el tamaño ya no lo limitan Nginx ni PHP (antes fallaba a partir de ~8 MB); tope de seguridad de 100 GiB por archivo, el límite real es el espacio de cada persona.
- Al subir, los JPEG se reempaquetan sin pérdida con `jpegtran` y se descuenta el tamaño final; el resto se guarda tal cual (`Services/Storage/LosslessOptimizer`).
- Regla de alcance ampliada en AGENTS.md (decisión del usuario 2026-11-14); DEPENDENCIES D012. Sin migraciones ni paquetes PHP/JS; la imagen PHP añade `libjpeg-turbo-progs` (**requiere `make rebuild`**).
- Validado: CI. **No validado**: el visor y el reproductor en un navegador real, ni `jpegtran` real dentro del contenedor (las pruebas lo simulan).

## Siguiente acción ejecutable
1. Usuario: `git pull`, quitar o cambiar `NAS_UPLOAD_MAX_BYTES` en `app/.env` si conserva 104857600, `make rebuild`, `make npm CMD="run build"` y probar la lista S03.5 del ROADMAP.
2. Usuario: ensayo de copia de seguridad desde WSL (`make nas-backup`, `make nas-backup-verify`) y QA pendiente de D02.
3. Siguiente hito: S04 necesita tres decisiones del usuario (ROADMAP); si no, P03.

## Límites conocidos
- Producción no desplegada; VPN/TLS, copia de seguridad real programada y piloto pendientes (P03/P05).
- La biblioteca habitual de Navidrome es común: no hay contenido privado por usuario en el despliegue actual.
- Las cuotas controlan lo que entra por el portal; archivos añadidos por fuera reducen el espacio físico pero no se imputan a nadie.
- Inventario real de discos, capacidad y host del usuario: desconocido; pedirlo al preparar el despliegue.
