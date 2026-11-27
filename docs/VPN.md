# Red privada (P03)

Multisuite se usa desde fuera de casa a través de una red privada propia. La familia y las amistades
instalan la app de Tailscale, la apuntan al servidor de coordinación del NAS y solo desde ahí ven el
portal y los servicios. Nada del portal ni de los servicios se publica en Internet.

**Estado:** implementado y validado en CI (Headscale real, el NAS y un dispositivo con permisos,
nombres privados y retirada). Pendiente: prueba del usuario con un servidor y un móvil reales.

## Piezas (`compose.vpn.yaml`, stack `multisuite-vpn`)

| Pieza | Qué hace | Qué se expone |
| --- | --- | --- |
| `headscale` 0.29.8 | Servidor de coordinación libre: alta de dispositivos, claves y permisos. No ve el contenido. | Solo él, y solo en el servidor público: 443 (y 80 para su certificado). En local, `127.0.0.1:8090`. |
| `vpn-node` (Tailscale 1.106.1) | El NAS como dispositivo de la red, en modo usuario: sin `/dev/net/tun` ni privilegios, también en Docker Desktop. Publica los nombres privados con su dirección. | Nada. |
| `vpn-entry` (Traefik 3.8.0) | Dentro del espacio de red de `vpn-node`: solo responde por la red privada y envía cada nombre privado al portal o a la pasarela de su servicio por la red `multisuite-accounts`. | Nada (puertos 80/443 solo dentro de la red privada). |

Permisos (`docker/headscale/policy.hujson`): los dispositivos de cada persona solo llegan al NAS y
solo a sus puertos web (80 y 443). Los dispositivos de personas distintas no se ven entre sí y el NAS
no inicia conexiones. Ocultar un enlace no es autorización: la red solo deja pasar a la entrada, y el
portal y cada servicio siguen pidiendo su propia cuenta.

Relés: cuando dos dispositivos no pueden conectar directamente se usan los relés públicos de
Tailscale (DERP). El tráfico va cifrado de extremo a extremo y el relé no puede leerlo. El relé propio
(DERP integrado en Headscale) está pendiente en ROADMAP.

## Funcionamiento en local (sin cambios)

Sin `vpn.env` no se arranca nada de esto: `make up` sigue sirviendo en `http://localhost` como
siempre. Con `vpn.env` (copia de `vpn.env.example`) los valores por defecto son de prueba: Headscale
en `http://127.0.0.1:8090`, nombres `*.multisuite.internal` y HTTP dentro de la red. `make status`
muestra la VPN y la dirección privada del portal cuando existe `vpn.env`.

## Puesta en marcha en el servidor

Requisitos: un nombre público para la coordinación (p. ej. `vpn.example.org`) que apunte a la IP
pública del servidor, y los puertos 80 y 443 abiertos hacia él. El resto de puertos del servidor
siguen cerrados.

1. `cp vpn.env.example vpn.env` y rellenar el bloque «Public server» (`VPN_SERVER_URL`,
   `VPN_CONTROL_DOMAIN`, `VPN_ACME_EMAIL`, `VPN_BIND_IP=0.0.0.0`, puertos 443/443/80) y los nombres
   privados `VPN_*_HOST` (de un dominio propio si se quiere HTTPS, ver abajo).
2. `make vpn-up`: crea la red compartida si falta, arranca Headscale (obtiene su certificado) y la entrada.
3. `make vpn-join`: el NAS entra en su propia red con una clave de un solo uso (10 minutos) y la
   etiqueta `tag:nas`. Repetirlo no hace nada si ya está dentro.
4. `make vpn-urls` imprime las líneas para `.env` (`APP_URL`, `TRUSTED_PROXIES`, URL de cada
   servicio) y para `music.env`/`video.env`. Copiarlas y reiniciar con `make up` y el `make *-up` de
   cada servicio. No se reescriben archivos automáticamente.

`compose.production.yaml` heredado publica también 80/443 para el portal: en el mismo servidor no se
usa esa entrada pública (adaptación pendiente en P05).

## Invitar a una persona

1. `make vpn-person NAME=ana` (minúsculas, números y guiones).
2. Ana instala Tailscale y elige servidor alternativo con la dirección de `VPN_SERVER_URL`:
   - Android: ajustes (arriba a la derecha) → Accounts → menú ⋮ → «Use an alternate server».
   - iPhone/iPad: icono de cuenta → «Log in…» → menú de opciones → «Use custom coordination server».
   - macOS (app): Opción + clic en el icono → Debug → Custom Login Server → «Add Account…».
   - Windows/Linux: `tailscale up --login-server https://vpn.example.org`.
   La propia instalación explica los pasos en `VPN_SERVER_URL/apple` y `/windows`.
3. Al iniciar sesión, la página que se abre muestra un código: `make vpn-approve NAME=ana CODE=<código>`.
4. Para un ordenador sin navegador: `make vpn-key NAME=ana` da una clave de un solo uso válida 1 hora
   (`tailscale up --login-server … --auth-key <clave>`).

Después abre el portal en `VPN_PORTAL_HOST` e inicia sesión con su cuenta de Multisuite (la invitación
del portal sigue siendo necesaria; la red no crea cuentas).

Retirar un dispositivo: `make vpn-devices` y `make vpn-remove ID=<n>`. Pierde el acceso al momento y
tendría que volver a pedir aprobación.

## HTTPS para los nombres privados

- `VPN_TLS=none` (por defecto): HTTP dentro de la red. WireGuard ya cifra el tráfico, pero el
  navegador lo marca como «no seguro» y no permite funciones que exigen HTTPS (llaves de acceso).
- `VPN_TLS=acme-dns`: certificados de Let's Encrypt por desafío DNS (los nombres privados no se ven
  desde Internet, así que no sirve el desafío HTTP). Necesita un dominio propio para `VPN_*_HOST`,
  `VPN_ACME_EMAIL`, `VPN_DNS_PROVIDER` con el código de [lego](https://go-acme.github.io/lego/dns/)
  (cloudflare, ovh, duckdns…) y sus credenciales en `vpn.secrets.env` (ignorado por git; usa un
  token limitado a la edición DNS de esa zona). Make añade `deploy/compose.vpn-tls.yaml`.

Los nombres privados se resuelven solo dentro de la red: Headscale los sirve a los dispositivos con el
archivo que mantiene `vpn-node`. No hace falta crear registros DNS públicos para ellos.

## Operación

- `make vpn-status`, `make vpn-logs`, `make vpn-stop`, `make vpn-restart` (tras cambiar la política).
- Datos persistentes: volúmenes `headscale-data` (base de datos, claves del servidor y su certificado)
  y `vpn-node-state` (identidad del NAS). Incluirlos en la copia del servidor (pendiente en P05).
- Actualizar: subir las versiones en `vpn.env` y `compose.vpn.yaml` de una en una, leyendo las notas
  de Headscale (pide pasar por cada versión menor) y repetir `python3 tests/vpn-smoke.py`.

## Pruebas

- `python3 tests/vpn-smoke.py` (CI): Headscale, el NAS y un dispositivo reales; unión con clave
  etiquetada, nombres privados por DNS, política (el puerto 8082 del NAS queda cerrado), entrada al
  portal y retirada del dispositivo.
- `tests/vpn.ps1` (CI Windows): validación de entradas y direcciones impresas por `scripts/vpn.ps1`.
- Validación de Compose con y sin HTTPS en el job `unix-scripts`.
- Manual (no automatizable): servidor con nombre público, móvil Android e iPhone reales fuera de casa.
