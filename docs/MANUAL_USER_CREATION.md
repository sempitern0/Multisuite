# Alta manual de cuentas (P03-06, complemento)

La gestión manual permite crear cuentas sin SMTP ni invitación. Solo owner/admin pueden entrar en **Crear usuario** desde el menú; solo owner puede crear admin. Nadie puede crear owner desde web. Se exige confirmación reciente de contraseña al administrador.

## Operación

1. Entrar en **Crear usuario**, elegir nombre, correo, rol permitido y servicios habilitados.
2. Establecer una contraseña temporal conforme a la política de la aplicación y entregarla privadamente al titular por un canal seguro (no por logs, tickets ni capturas).
3. La cuenta queda creada y marcada `must_change_password`. El correo se considera verificado por decisión explícita del administrador: confirmar su titularidad antes de crearla y entregar las credenciales.
4. Al autenticarse, el usuario solo puede visitar la pantalla de cambio inicial o cerrar sesión. Introduce la contraseña temporal y una nueva diferente.
5. La nueva contraseña se guarda a través de `ServiceAccounts::changePassword`; los accesos a servicios seleccionados se aprovisionan por primera vez con esa contraseña personal, no la temporal. La sincronización remota puede quedar pendiente y exige scheduler/configuración ya descritos en `SERVICE_ACCOUNTS.md`.

La creación manual no entrega correo, no crea una cuenta remota con la contraseña temporal y no revela la contraseña en el mensaje de éxito. Los intentos de utilizar una invitación previa para ese correo quedan invalidados. Las cuentas existentes y las creadas por invitación no tienen que pasar por este flujo.

## Seguridad y limitaciones

- El acceso web está protegido mediante autenticación, rol y confirmación reciente de contraseña. Se revalida la jerarquía en el backend.
- Cambiar contraseña por recuperación Fortify también limpia la marca de primera entrada mediante `ServiceAccounts::changePassword`; requiere poder recibir el correo de recuperación.
- La contraseña temporal funciona hasta que se sustituye; el administrador debe transmitirla por un canal seguro, no reutilizarla y solicitar al usuario que entre pronto.
- Los roles owner/admin del portal no se trasladan a permisos administrativos de Navidrome.
- Revisar en entorno real migración, disponibilidad de servicios, middleware Livewire, 2FA/passkeys, concurrencia PostgreSQL, flujos sin SMTP y accesibilidad antes de desplegar en producción.
- Ejecutar `make artisan CMD="migrate"` y los tests antes de utilizar.
