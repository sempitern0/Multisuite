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
