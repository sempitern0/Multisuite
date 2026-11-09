# Cuentas de servicios desde invitaciones

## Alcance
La invitación selecciona servicios habilitados con adaptador. Esta iteración soporta Música/Navidrome 0.64.2. Al aceptar, correo y contraseña crean una cuenta normal de Navidrome; el rol owner/admin del portal nunca concede administración de música. La biblioteca accesible es la configurada en Navidrome como predeterminada para nuevos usuarios: revisar esa selección antes de invitar.

Los servicios futuros requieren adaptador y pruebas propias. La sesión web de Navidrome sigue pidiendo login con esas mismas credenciales. SSO/proxy es el siguiente hito. Las cuentas antiguas mantienen su funcionamiento; vinculación de cuentas remotas preexistentes y incorporación posterior de servicios requieren P03-06c.

## Configuración local
1. Aplicar `make artisan CMD="migrate"`.
2. Configurar `app/.env`: `PORTAL_MUSIC_ENABLED=true`, URL pública para navegador, `NAVIDROME_PROVISIONING_ENABLED=true`, `NAVIDROME_INTERNAL_URL` alcanzable desde PHP, `NAVIDROME_ADMIN_USERNAME` y `NAVIDROME_ADMIN_PASSWORD`. Crear antes el administrador de Navidrome con su interfaz. Usar credenciales del operador, nunca las del invitado. No versionar secretos.
3. Para comunicar ambos stacks localmente, crear `docker network create multisuite-accounts`. Combinar deploy/compose.portal-accounts.example.yaml con el compose.override.yaml local (conservar otros ajustes). Ejecutar el stack musical con `docker compose --env-file music.env -f compose.music.yaml -f deploy/compose.music-accounts.example.yaml up -d` y el portal con `make up MUSIC_AUTO=0`. Usar `NAVIDROME_INTERNAL_URL=http://navidrome:4533`. Este es el endpoint interno, distinto del enlace del navegador. Mantener ambos archivos al recrear los contenedores musicales.
4. `make artisan CMD="config:clear"`. Mantener activo el scheduler (`php artisan schedule:work` dentro del contenedor, o servicio scheduler del despliegue). Para probar un ciclo manual: `make artisan CMD="portal:sync-accounts"`.
5. Desde Invitaciones, seleccionar Música y aceptar el correo de Mailpit. Entrar a Navidrome con el correo completo y la contraseña elegida. Cambios y olvidos de contraseña se realizan en el portal. El override musical deshabilita edición directa para evitar divergencias de usuarios normales.

Producción exige endpoint HTTPS con certificado válido y acceso privado. Configurar hostname/CA, red, secretos del operador y scheduler antes de habilitar. Navidrome almacena contraseñas reversibles por compatibilidad Subsonic; configurar su PasswordEncryptionKey propia siguiendo su procedimiento de migración y conservarla en backups seguros. No se deshabilita verificación TLS ni se siguen redirecciones HTTP. BaseUrl se incluye en NAVIDROME_INTERNAL_URL si existe.

## Consistencia y secretos
El portal guarda el hash habitual del usuario y una copia **cifrada temporal** en service_accounts para entregar la contraseña al servicio. Usa APP_KEY, dura como máximo una hora de validez y se borra al sincronizar. El scheduler purga las copias caducadas y reintenta cada cinco minutos. Debe estar activo para el borrado físico puntual; backups también pueden contener ciphertext hasta vencer su retención. No registrar cuerpos HTTP ni volcar la tabla en diagnósticos.

La fila de aprovisionamiento y el alta/cambio de contraseña se guardan en la misma transacción. La llamada externa se realiza después. El identificador remoto aleatorio se reserva antes del primer envío y se reutiliza si la respuesta se pierde. Un usuario remoto con el mismo correo pero otro identificador produce conflicto, sin modificar sus credenciales. La sincronización por fila se serializa mediante bloqueo y no deja que un reintento anterior sobrescriba una nueva contraseña pendiente.

Estados visibles: preparado, pendiente, necesita revisión y credencial caducada. El usuario del portal puede entrar aunque el servicio esté caído. Tras caducar se puede iniciar otra sincronización al actualizar/restablecer la contraseña. Mientras un cambio está pendiente, el servicio puede conservar su contraseña anterior; la baja/revocación integral se trata en P03-06d.

El nombre de usuario remoto queda fijado al correo utilizado al aceptar la invitación. Cambiar el correo de contacto del portal no renombra automáticamente cuentas remotas; la portada muestra el usuario de servicio. La selección queda en servidor y se rechaza su inyección durante la aceptación.

## Validación
Tests Laravel con transporte simulado: aceptación, credencial cifrada/oculta, fallo y recuperación, conflictos, caducidad, contraseña más reciente y restablecimiento por ruta Fortify. Prueba de integración CI contra contenedor desechable 0.64.2: creación, login, cambio de contraseña y rechazo de la anterior. Ninguna prueba usa biblioteca o usuarios reales.

Contrato revisado en código upstream v0.64.2: server/auth.go, server/nativeapi/native_api.go, model/user.go y persistence/user_repository.go. API nativa /auth/login, /api/user y cabecera X-ND-Authorization. Adaptador versionado; verificar de nuevo antes de actualizar Navidrome. No usa createUser de Subsonic.

Pendiente antes del piloto: QA Windows/Linux con Docker real, sincronización con PostgreSQL bajo concurrencia, fallos de red durante commits remotos, entrega de correo y permisos de bibliotecas.
