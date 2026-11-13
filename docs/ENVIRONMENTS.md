# Entornos del portal y servicios
Portal Laravel/Livewire separado de servicios con interfaces propias.

## Desarrollo
- Foundry conserva compose.yaml para generar y ejecutar el portal; configuración y release aún pendientes P02/P05.
- compose.music.yaml es autónomo con nombre de proyecto propio. No combinar ambos archivos ni incorporar Navidrome al wizard/reset de Laravel.
- Navidrome puede probarse antes de instalar PHP/Laravel: seguir [NAVIDROME.md](NAVIDROME.md).
- No guardar música ni DB del servicio en app/storage. Los datos locales están ignorados en /local/; producción usará directorios durables configurados.
- Windows con Docker Desktop; Linux con permisos UID/GID reales. Comprobar ambas plataformas, no asumir equivalencia.
- PORTAL: versiones/lockfiles y PostgreSQL si el starter requiere persistencia. No añadir colas/Redis para simples enlaces.

## Producción pendiente
Portal: imágenes inmutables de Foundry, APP_DEBUG=false, secretos propios, assets compilados, healthchecks y DB si se usa.
Servicios: imágenes fijadas, datos/usuarios propios, autenticación y backups por servicio.
Entrada VPN + TLS: loopback para proxy en host; si el proxy es un contenedor, diseñar red explícita. No usar 127.0.0.1 para referirse a otro contenedor.
El Compose heredado de producción publica puertos por defecto: adaptarlo antes de desplegar. compose.music.yaml mantiene loopback incluso en servidor; no publica una web pública.
El operador aporta dominio/resolución DNS, secretos, VPN y destinos de almacenamiento/backup. Objetivo: solo configuración por entorno, sin editar código.
Probar paridad local y staging con misma imagen antes de promoción; nunca copiar datos personales a staging.

## Operación
Las altas y contraseñas de cuentas nuevas pueden aprovisionarse desde el portal mediante SERVICE_ACCOUNTS.md. VPN, sesión web unificada y baja integral siguen pendientes.
Backup de cada DB de forma consistente y de archivos originales. Ensayar restore con versión compatible antes de actualizar.
Despliegue real, cambios de secretos y operaciones destructivas requieren autorización aplicable. down -v o reset no son procedimientos de actualización.
No se afirma readiness de producción hasta completar P03 y P05. Guía Foundry: [DEPLOYMENT.md](foundry/DEPLOYMENT.md); adaptar al alcance actual.
