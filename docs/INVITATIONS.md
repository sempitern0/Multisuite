# P02-05 — Acceso por invitación

El propietario opera las invitaciones desde la consola del servidor. No hay registro libre: GET /register muestra el formulario, pero POST exige un código válido para ese correo. No hay roles administrativos nuevos ni envío automático de emails. La cuenta del portal no crea una cuenta en Navidrome.

## Preparación y alta
1. Aplicar la migración: `make artisan CMD="migrate"` (en producción, desde el contenedor de la versión desplegada).
2. Crear la primera invitación o invitar a otra persona: `make artisan CMD="portal:invite familiar@example.com --days=7"`.
3. Compartir privadamente el código y la URL /register que muestra el comando. Usar el dominio accesible al destinatario; localhost no apunta al servidor desde otro dispositivo.
4. La persona introduce correo, código, nombre y contraseña. El código solo se muestra al emitirlo y se guarda como SHA-256. Caduca en 7 días por defecto (1–30 configurables).
5. Crear la cuenta de Navidrome por separado con sus permisos propios.

Reemitir para el mismo correo invalida el código anterior. Revocar antes del uso: `make artisan CMD="portal:invite familiar@example.com --revoke"`. La revocación no elimina cuentas ya creadas ni revoca sesiones de usuarios existentes. La baja integral de usuarios pertenece al siguiente incremento operativo.

El consumo del código y la creación de cuenta se realizan en una transacción: un fallo revierte el consumo y un código consumido no permite otra alta. No guardar códigos en repositorios, logs compartidos ni URLs. Laravel excluye invitation del input recuperado tras errores de validación.

## QA
- Sin código: rechazo; ninguna cuenta creada.
- Código correcto + otro correo, código caducado/revocado/usado: rechazo.
- Código correcto: alta, login y acceso a la portada; comprobar que Música abre el servicio configurado.
- Reemitir: el código antiguo falla y el nuevo funciona.
- Validación de contraseña fallida: el código sigue disponible para corregir el formulario.
- Repetir envío simultáneo: como máximo una cuenta; no se reutiliza la invitación.
- Formulario a 360/768/1440 px, navegación por teclado, errores legibles y zoom 200 %.

El correo se normaliza a minúsculas. Las cuentas existentes conservan acceso. Verificación de email y recuperación mantienen la configuración Fortify existente; revisar entrega de correo antes del piloto.
