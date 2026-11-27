#!/bin/sh
# The NAS as a device of the private network (compose.vpn.yaml, docs/VPN.md).
# - tailscaled in userspace mode: connections to the NAS's private address are delivered to this
#   network namespace, where vpn-entry (Traefik) listens. No /dev/net/tun or extra privileges.
# - Once joined (`make vpn-join`), it publishes the private names (VPN_*_HOST) with its address in
#   the records file Headscale reads, and refreshes them if the address ever changes.
# Values come from this container's environment only; nothing is evaluated.
set -eu

# `make vpn-join` runs `node.sh join KEY` in the running container: the login address is derived
# here from this container's environment (the public control name, or Headscale on the stack network).
if [ "${1:-}" = join ]; then
    printf '%s' "${2:-}" | grep -Eq '^[A-Za-z0-9_-]{16,}$' || { echo 'vpn-node: invalid key' >&2; exit 2; }
    url="http://headscale:${VPN_LISTEN_PORT:-8080}"
    [ -z "${VPN_CONTROL_DOMAIN:-}" ] || url="https://$VPN_CONTROL_DOMAIN"
    exec tailscale up --login-server="$url" --auth-key="$2" --hostname=multisuite --accept-dns=false --accept-routes=false
fi

state=/var/lib/tailscale
records=/dns/extra-records.json
mkdir -p /var/run/tailscale

tailscaled --tun=userspace-networking --statedir="$state" --socket=/var/run/tailscale/tailscaled.sock &
daemon=$!
trap 'kill "$daemon" 2>/dev/null; wait "$daemon"; exit 0' TERM INT

valid_host() {
    printf '%s' "$1" | grep -Eq '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$'
}

write_records() {
    ip=$1
    out='['
    sep=''
    for host in "${VPN_PORTAL_HOST:-}" "${VPN_MUSIC_HOST:-}" "${VPN_READING_HOST:-}" "${VPN_VIDEO_HOST:-}"; do
        [ -n "$host" ] || continue
        if ! valid_host "$host"; then
            echo "vpn-node: ignoring invalid private name" >&2
            continue
        fi
        out="$out$sep{\"name\":\"$host\",\"type\":\"A\",\"value\":\"$ip\"}"
        sep=','
    done
    out="$out]"
    # In place (not a rename): Headscale watches this file.
    if [ "$(cat "$records" 2>/dev/null || true)" != "$out" ]; then
        printf '%s\n' "$out" > "$records"
        echo "vpn-node: private names point to $ip"
    fi
}

while kill -0 "$daemon" 2>/dev/null; do
    ip=$(tailscale ip -4 2>/dev/null | head -n 1 || true)
    if printf '%s' "$ip" | grep -Eq '^100\.[0-9]+\.[0-9]+\.[0-9]+$'; then
        write_records "$ip"
    fi
    sleep 15 &
    wait $! || true
done
wait "$daemon"
