# Actividad administrativa — P03-06d.1

El menú **Actividad** muestra las altas manuales, modificaciones y eliminaciones confirmadas del panel de Usuarios. Solo propietario/administradores con correo verificado y confirmación reciente de contraseña pueden acceder. Lista paginada de 25 registros, más recientes primero, filtro por acción.

## Datos y garantías
- Identificador y nombre de administrador y usuario afectado, acción, fecha, nombres de campos modificados y transición de rol.
- No registra contraseñas, hashes, cookies, tokens, IP, cuerpos de peticiones ni valores de correo. Los nombres son datos personales: incluir la tabla en la política privada de backup y retención del operador.
- Registro en la misma transacción que la mutación local: si falla escribir el evento, se revierte el cambio local. No se registran éxitos por formularios inválidos, acciones denegadas o guardados sin cambios.
- Identificadores históricos sin FK: el historial sobrevive a la baja de sujeto o administrador. Los nombres son la instantánea de ese momento.
- No existen endpoints para editar o borrar eventos. No es almacenamiento inmutable ni evidencia resistente a manipulación por quien administra la base de datos.
- El flujo remoto de baja sigue sin ser una transacción distribuida: si Navidrome se elimina y falla la transacción local, se conserva el usuario local para reintentar. El evento registra la baja local confirmada, no los intentos remotos fallidos.

## Activación y QA
1. Actualizar esta rama y ejecutar `make artisan CMD="migrate --force"`. Migración incremental: no modifica usuarios existentes ni inventa historial anterior.
2. Con propietario, crear una cuenta de prueba, cambiar nombre/rol y comprobar las entradas en Actividad.
3. Guardar sin cambios: no debe duplicar el registro. Una confirmación de baja incorrecta no debe crear evento.
4. Eliminar la cuenta de prueba; comprobar que su historial permanece y el filtro Eliminación la muestra.
5. Como miembro, comprobar ausencia del menú y HTTP 403 en `/admin/activity`.
6. Comprobar móvil, teclado, zoom 200 % y nombres largos. Las fechas muestran la zona configurada por Laravel.

Tests: `make artisan CMD="test --filter=AdminAuditTest"`. No requiere servicios externos adicionales ni nuevas librerías.

## Límites y continuación
Este incremento audita únicamente el CRUD administrativo. Invitaciones/aceptación, autoeliminación, consola, cambios personales, intentos fallidos, permisos por servicio y vinculación de cuentas preexistentes siguen fuera. No hay purga automática: no se destruye historial sin una política acordada. Próxima PR: P03-06c, incorporación de servicios a usuarios ya existentes con autorización y nueva confirmación de su contraseña, sin recuperar hashes ni adoptar cuentas remotas por email.

## Contrato para futuros servicios del NAS

`AdminAudit::recordService(?User $actor, User $subject, string $service, ServiceAuditAction $action)` registra eventos tipados de concesión/revocación, creación/vinculación/baja y éxito/fallo de sincronización. Identificador estable del registro `portal.services` (por ejemplo music), sujeto usuario local y actor nullable para jobs (`Sistema`). Es auditoría persistente, no un bus de mensajes ni un mecanismo que ejecute acciones remotas.

```php
use App\Enums\ServiceAuditAction;
use App\Services\AdminAudit;

// Dentro de la transacción que confirma el cambio local del adaptador:
app(AdminAudit::class)->recordService($administrator, $user, 'music', ServiceAuditAction::AccessGranted);
// Un job puede utilizar null como actor, sin atribuirse a un administrador.
```

El contrato no acepta payloads arbitrarios ni respuestas de API/excepciones. La versión de esquema queda en cada fila (1). Nuevos detalles necesitan campos explícitos, revisión de privacidad y pruebas. Para nuevas acciones ampliar el enum; validación, etiquetas y filtro se actualizan desde el mismo catálogo. El selector de servicio usa los identificadores históricos, incluso si el servicio se retira del registro.

Cada adaptador debe emitir una sola vez al confirmar una transición real, dentro de su transacción local. Los reintentos sin cambio no deben emitir eventos duplicados; el almacenamiento no deduplica por sí mismo ni promete exactly-once. Los fallos remotos se registrarán con `SyncFailed` al confirmar la transición local de error, nunca copiando mensajes que puedan contener secretos. No ejecutar efectos de red desde listeners de auditoría. Añadir tests del adaptador para rollback y reintentos antes de conectarlo.

La ampliación añade una segunda migración, compatible con instalaciones que ya aplicaron la primera. El rollback conserva actor_id nullable para no destruir eventos de sistema. Los emisores de servicios quedan preparados: este incremento no instrumenta automáticamente Navidrome ni servicios todavía no integrados.
