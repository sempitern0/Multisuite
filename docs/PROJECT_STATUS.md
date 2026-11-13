# Estado de Multisuite

Instantánea a 2026-11-13. Historial detallado: git log y PRs #1–#11. Este archivo se **reescribe** (no se acumula) al cerrar cada incremento.

## Integrado en `main` (3d98ca0)
| Área | Qué hay | Validación |
| --- | --- | --- |
| Portal | Starter Livewire, portada de servicios, `/services/{id}` con destinos administrados | CI verde; QA visual de portada pendiente |
| Acceso | Registro libre retirado; invitaciones firmadas de un uso; roles owner/admin/member; alta manual con contraseña temporal; username o correo; 2FA/passkeys | CI + confirmación del usuario |
| Cuentas de servicio | Aprovisionamiento Navidrome 0.64.2 (alta, cambio/reset, renombrado, baja) con reintentos cifrados efímeros | CI con Navidrome real desechable |
| Sesión de Música | Gateway Nginx + pase cifrado de 60 s; no es SSO | CI (proxy/Range); QA navegador pendiente |
| Administración | CRUD de usuarios, auditoría administrativa protegida | CI; QA visual del CRUD pendiente |
| Música | `compose.music.yaml` autónomo, ciclo integrado con `make up` | Usuario confirma que funciona; checklist P01 sin evidencia |
| Aislamiento | Laboratorio S01 con 30 comprobaciones reales | Workflow *Library isolation* verde |
| Almacenamiento | Inventario de discos, reservas/cuotas transaccionales, recepción privada HTTP | CI + concurrencia PostgreSQL |

## En curso: S02.3 — rama `feat/s02-storage-admin-gui` (commit fac3b9a subido, PR pendiente)
- Página **Almacenamiento** para owner/admin: estado y barra de cada disco, límite con opciones predefinidas o unidades humanas, reparto/sobreasignación, cuota por defecto y cuota por persona. Owner gestiona discos, defecto y a cualquiera; admin solo cuotas de miembros.
- Cuota por defecto (`storage_settings`, migración incremental) aplicada de forma perezosa en la primera reserva; 0 conserva el comportamiento anterior.
- Auditoría `storage.*`, «Tu almacenamiento» en portada, enlace desde la ficha de usuario.
- Reservas caducadas visibles por disco, separando limpieza automática y revisión manual (solo lectura). `portal:storage-quota` acepta unidades (`50GB`, `1,5TB`).
- Tests nuevos: `StorageAdminTest` (incluye reservas caducadas y comando con unidades), `BytesTest`. **No ejecutados**: el entorno del agente no tiene PHP ni Docker; validar en CI (`make test`, Pint, PHPStan).
- Documentación reorganizada: obsoletos eliminados, Foundry en `docs/foundry/`, almacenamiento consolidado en STORAGE.md, guía para agentes en AGENTS.md y ARCHITECTURE.md.

## Siguiente acción ejecutable
1. Abrir la PR de la rama (GitHub la marca como fusionable sin conflictos a 2026-11-13; las 11 PRs anteriores están fusionadas) y corregir lo que indique CI.
2. QA visual de `/storage` (ver ROADMAP S02.3) con discos de prueba, nunca bibliotecas reales.
3. Empezar S03.2 (interfaz de subida) en rama nueva desde `main` actualizado.

## Límites conocidos
- Producción no desplegada; VPN/TLS, backups y piloto pendientes (P03/P05).
- La biblioteca habitual de Navidrome es común: no hay contenido privado por usuario en el despliegue actual.
- Las cuotas controlan lo que entra por el portal; archivos añadidos por fuera reducen el espacio físico pero no se imputan a nadie.
- Inventario real de discos, capacidad y host del usuario: desconocido; pedirlo al preparar el despliegue.
