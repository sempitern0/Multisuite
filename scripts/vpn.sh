#!/bin/sh
# Private network operations (docs/VPN.md), called by `make vpn-*`. PowerShell twin: scripts/vpn.ps1.
#   join            the NAS joins its own network (single-use key, tag:nas); safe to repeat
#   person NAME     creates a person in the network (lowercase letters, digits and hyphens)
#   approve NAME CODE  approves a device of NAME with the code its login page shows
#   key NAME        single-use key valid 1 hour, for a computer joining from the command line
#   devices         lists devices;  remove ID: removes a device (it must sign in again)
#   urls            prints the addresses to set in .env and the services' env files
# Only validated values reach Headscale; nothing from the env files is evaluated by the shell.
set -eu
cd "$(dirname "$0")/.."

env_file=${VPN_ENV_FILE:-vpn.env}
if [ ! -f "$env_file" ]; then
    echo "Falta vpn.env: cópialo de vpn.env.example (docs/VPN.md)." >&2
    exit 1
fi

setting() {
    v=$(sed -n "s/^$1=//p" "$env_file" | tail -n 1 | tr -d '\r' | sed 's/^"//;s/"$//')
    if [ -n "$v" ]; then printf '%s' "$v"; else printf '%s' "${2:-}"; fi
}

compose() {
    if [ "$(setting VPN_TLS none)" = acme-dns ]; then
        docker compose --env-file "$env_file" -f compose.vpn.yaml -f deploy/compose.vpn-tls.yaml "$@"
    else
        docker compose --env-file "$env_file" -f compose.vpn.yaml "$@"
    fi
}
headscale() { compose exec -T headscale headscale "$@"; }

need() {
    if ! printf '%s' "$2" | grep -Eq "$3"; then
        echo "$1 no válido. $4" >&2
        exit 2
    fi
}
person_name() { need 'Nombre' "$1" '^[a-z0-9][a-z0-9-]{0,30}$' 'Usa minúsculas, números y guiones (ej. ana o tio-luis).'; }

command=${1:-}
case "$command" in
join)
    if compose exec -T vpn-node tailscale ip -4 >/dev/null 2>&1; then
        echo "El NAS ya está en la red privada: $(compose exec -T vpn-node tailscale ip -4 | tr -d '\r' | head -n 1)"
        exit 0
    fi
    key=$(headscale preauthkeys create --tags tag:nas --expiration 10m | tr -d '\r' | tail -n 1)
    need 'Clave' "$key" '^[A-Za-z0-9_-]{16,}$' 'Headscale no ha devuelto una clave; revisa make vpn-logs.'
    compose exec -T vpn-node sh /opt/multisuite/node.sh join "$key"
    echo "El NAS se ha unido a la red privada: $(compose exec -T vpn-node tailscale ip -4 | tr -d '\r' | head -n 1)"
    ;;
person)
    person_name "${2:-}"
    if headscale users list --name "$2" 2>/dev/null | grep -q "$2"; then
        echo "«$2» ya existe en la red privada."
    else
        headscale users create "$2" >/dev/null
        echo "«$2» creada. Sus dispositivos se aprueban con: make vpn-approve NAME=$2 CODE=<código>"
    fi
    ;;
approve)
    person_name "${2:-}"
    need 'Código' "${3:-}" '^[A-Za-z0-9_-]{8,128}$' 'Copia el código que muestra la página de acceso del dispositivo.'
    headscale auth register --user "$2" --auth-id "$3"
    ;;
key)
    person_name "${2:-}"
    id=$(headscale users list --name "$2" --output json 2>/dev/null | tr -d '\r\n ' | sed -n 's/.*"id":"\{0,1\}\([0-9][0-9]*\).*/\1/p' | head -n 1)
    need 'Persona' "$id" '^[0-9]+$' "Crea primero a «$2» con make vpn-person NAME=$2."
    headscale preauthkeys create --user "$id" --expiration 1h | tr -d '\r' | tail -n 1
    ;;
devices)
    headscale nodes list
    ;;
remove)
    need 'Dispositivo' "${2:-}" '^[0-9]+$' 'Usa el número de la columna ID de make vpn-devices.'
    headscale nodes delete --identifier "$2" --force
    ;;
urls)
    scheme=http
    [ "$(setting VPN_TLS none)" = acme-dns ] && scheme=https
    portal=$(setting VPN_PORTAL_HOST)
    echo "# .env del portal (y reinicia con make up):"
    [ -n "$portal" ] && echo "APP_URL=$scheme://$portal"
    echo "TRUSTED_PROXIES=172.16.0.0/12,192.168.0.0/16,10.0.0.0/8"
    [ "$scheme" = https ] && echo "SESSION_SECURE_COOKIE=true"
    music=$(setting VPN_MUSIC_HOST); reading=$(setting VPN_READING_HOST); video=$(setting VPN_VIDEO_HOST)
    [ -n "$music" ] && echo "PORTAL_MUSIC_URL=$scheme://$music"
    [ -n "$reading" ] && echo "PORTAL_KOMGA_URL=$scheme://$reading"
    [ -n "$video" ] && echo "PORTAL_VIDEO_URL=$scheme://$video"
    [ -n "$music" ] && printf '# music.env:\nMUSIC_PUBLIC_ORIGIN=%s://%s\n' "$scheme" "$music"
    [ -n "$video" ] && printf '# video.env:\nPORTAL_VIDEO_URL=%s://%s\n' "$scheme" "$video"
    exit 0
    ;;
*)
    sed -n '2,9p' "$0" | sed 's/^# \{0,1\}//'
    exit 2
    ;;
esac
