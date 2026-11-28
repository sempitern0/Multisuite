# Entornos del portal y servicios
Portal Laravel/Livewire separado de servicios con interfaces propias.

## Desarrollo
- Foundry conserva compose.yaml para generar y ejecutar el portal; configuración y release aún pendientes P02/P05.
- compose.music.yaml es autónomo con nombre de proyecto propio. No combinar ambos archivos ni incorporar Navidrome al wizard/reset de Laravel.
- Navidrome puede probarse antes de instalar PHP/Laravel: seguir [NAVIDROME.md](NAVIDROME.md).
- Vídeo: `compose.video.yaml` autónomo (Jellyfin). `make video-detect` crea `video.env` con la GPU detectada (Intel, AMD, NVIDIA o procesador) y Make añade el override de dispositivos; el portal nunca recibe dispositivos ni el socket ([JELLYFIN.md](JELLYFIN.md)).
- `make status` (también al final de `make up` y `make up-wait`) muestra en **MULTISUITE SERVICES** la dirección de Música, Lectura (Komga o Kavita) y Vídeo con su puerto real (`music.env`, `.env`, `reading.env`, `video.env`) cuando su entrada está en marcha, o el comando para arrancarla si está parada, y la GPU detectada para Vídeo. Komga aparece siempre que se use el NAS de desarrollo (la misma regla que `make up`), y Kavita, configurado pero desactivado, se indica como tal sin proponer arrancarlo. Solo lee puertos y nombres, nunca secretos (`scripts/status.sh` y `scripts/status.ps1`).
- Descargas: `compose.downloads.yaml` opcional con `downloads.env`; `make up` lo arranca y monta en el portal el volumen de espera `multisuite-downloads` ([DOWNLOADS.md](DOWNLOADS.md)).
- Red privada: `compose.vpn.yaml` autónomo y opcional (Headscale, el NAS como dispositivo y la entrada privada). Sin `vpn.env` no se arranca y el desarrollo local no cambia; con él, `make vpn-up` y `make vpn-join` ([VPN.md](VPN.md)). `make status` muestra su estado y la dirección privada del portal.
- No guardar música ni DB del servicio en app/storage. Los datos locales están ignorados en /local/; producción usará directorios durables configurados.
- Windows con Docker Desktop; Linux con permisos UID/GID reales. Comprobar ambas plataformas, no asumir equivalencia.
- PORTAL: versiones/lockfiles y PostgreSQL si el starter requiere persistencia. No añadir colas/Redis para simples enlaces.
- Entorno local desechable mientras no haya producción: `make up` migra y prepara el NAS de desarrollo (`deploy/compose.nas-dev.yaml`, `NAS_DEV=0` para omitirlo); `make reset-project` + `make build` lo recrea desde cero.

## Producción pendiente
Portal: imágenes inmutables de Foundry, APP_DEBUG=false, secretos propios, assets compilados, healthchecks y DB si se usa.
Servicios: imágenes fijadas, datos/usuarios propios, autenticación y backups por servicio.
Entrada VPN + TLS: `compose.vpn.yaml` (VPN.md). Solo Headscale publica 80/443; la entrada privada vive en el espacio de red del NAS dentro de la VPN y llega al portal y a las pasarelas por `multisuite-accounts`. El portal confía en `X-Forwarded-*` solo de `TRUSTED_PROXIES`.
El Compose heredado de producción publica puertos por defecto: adaptarlo antes de desplegar. compose.music.yaml mantiene loopback incluso en servidor; no publica una web pública.
El operador aporta dominio/resolución DNS, secretos, VPN y destinos de almacenamiento/backup. Objetivo: solo configuración por entorno, sin editar código.
Probar paridad local y staging con misma imagen antes de promoción; nunca copiar datos personales a staging.
Asistente: claves OAuth de Passport (`PASSPORT_PRIVATE_KEY`/`PASSPORT_PUBLIC_KEY` o `passport:keys` una vez) y `MCP_REDIRECT_DOMAINS`; el endpoint `/mcp` solo debe ser accesible por la red privada (docs/MCP.md § MCP-05). En desarrollo `make up` crea las claves.

## Operación
Las altas y contraseñas de cuentas nuevas pueden aprovisionarse desde el portal mediante SERVICE_ACCOUNTS.md. Sesión web unificada y baja integral siguen pendientes; la red privada está en VPN.md.
Backup de cada DB de forma consistente y de archivos originales. Ensayar restore con versión compatible antes de actualizar.
Despliegue real, cambios de secretos y operaciones destructivas requieren autorización aplicable. down -v o reset no son procedimientos de actualización.
No se afirma readiness de producción hasta completar P03 y P05. Guía Foundry: [DEPLOYMENT.md](foundry/DEPLOYMENT.md); adaptar al alcance actual.
