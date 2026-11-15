# Estado de Multisuite

Instantánea a 2026-11-15. Historial detallado: git log y PRs #1–#27. Este archivo se **reescribe** (no se acumula) al cerrar cada incremento.

## Integrado en `main` (PRs #1–#27)
| Área | Qué hay | Validación |
| --- | --- | --- |
| Portal | Portada «Servicios» (`DashboardController` + `ServiceTiles`), `/services/{id}` con destinos administrados | CI; QA visual del usuario pendiente |
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

## En revisión
### D03 Ajustes de interfaz — PR #28 (`feat/d03-ui-polish`)
- Tarjetas de servicios con fondo vectorial según su contenido (`x-ui::tile-art`).
- Mayús + clic para marcar rangos de casillas en Mis archivos.
- Visor: Ctrl/⌘ + rueda o pellizco para el zoom; la rueda sola ya no secuestra el scroll de la página y en móvil un dedo desplaza la página salvo con la imagen ampliada.
- Iconos de navegación revisados (engranaje real, almacenamiento, actividad, invitaciones) y botón para contraer el menú lateral, recordado por persona (`PUT settings/sidebar`); la opción sale de Preferencias.
- Audio en Mis archivos con el reproductor del navegador.
- Validado: CI y prueba en Chrome de la selección por rango y de la rueda; el usuario confirmó la mayoría en su entorno.

### D04 Navegación y jerarquía de Mis archivos — PR apilada sobre #28 (`feat/d04-files-navigation`)
- Flecha atrás en cabecera (carpetas, papelera, descargas, detalle de archivo, alta y edición de personas) y migas más legibles.
- Zonas diferenciadas: cabecera con acciones principales, pestañas Archivos / Papelera / Descargas ZIP, barra de filtros en panel propio y selector de vista con texto.
- Barra de selección contextual (Descargar en ZIP, Mover a la papelera en bloque, quitar selección); acciones de fila al pasar el ratón; nueva carpeta y opciones de la carpeta en diálogos.
- Validado: CI. No validado en el portal real.

### S03.7 Cifrado en reposo — PR apilada sobre D04 (`feat/s03-file-encryption`)
- Archivos y ZIP cifrados en disco con XChaCha20-Poly1305 por bloques (libsodium) y clave por archivo derivada de `NAS_FILES_KEY`, que vive solo en `app/.env`. `make up` la crea en desarrollo; sin clave no se guardan subidas nuevas.
- Las subidas se cifran fragmento a fragmento con SHA-256 incremental: sin copia en claro (salvo JPEG ≤ 64 MiB que se optimizan), sin doble espacio y con un último fragmento rápido; el navegador espera a `ready`.
- Descargas, vídeo y audio con rangos sobre el contenido cifrado; ZIP escritos en flujo directamente cifrados (sin `ZipArchive` ni copias intermedias).
- Validado: CI y pruebas locales del formato (rangos, alteración, recorte, clave equivocada) y del ZIP con libzip, unzip y Python. **Pendiente del usuario**: guardar `NAS_FILES_KEY` fuera del servidor.

## Siguiente acción ejecutable
1. Usuario: revisar D03, D04 y S03.7 (`make up` crea la clave de cifrado; `make npm CMD="run build"`). Los archivos subidos antes siguen abriéndose sin cifrar; los nuevos se guardan cifrados.
2. Usuario: ensayo de copia de seguridad desde WSL (`make nas-backup`, `make nas-backup-verify`) y QA pendiente de D02.
3. Siguiente hito: S04 necesita tres decisiones del usuario (ROADMAP); si no, P03.

## Límites conocidos
- Producción no desplegada; VPN/TLS, copia de seguridad real programada y piloto pendientes (P03/P05).
- La biblioteca habitual de Navidrome es común: no hay contenido privado por usuario en el despliegue actual.
- Las cuotas controlan lo que entra por el portal; archivos añadidos por fuera reducen el espacio físico pero no se imputan a nadie.
- Inventario real de discos, capacidad y host del usuario: desconocido; pedirlo al preparar el despliegue.
