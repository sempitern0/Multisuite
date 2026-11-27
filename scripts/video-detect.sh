#!/bin/sh
# Detects the GPU Jellyfin can use and records it in video.env (VIDEO_HARDWARE, VIDEO_RENDER_GID).
# CPU (none) whenever the vendor cannot be confirmed. HW=intel|amd|nvidia|none forces a choice.
set -eu
env_file=${VIDEO_ENV_FILE:-video.env}
[ -f "$env_file" ] || cp video.env.example "$env_file"

hardware=none
render_gid=109
node=/dev/dri/renderD128
if [ -n "${HW:-}" ]; then
    hardware=$HW
elif command -v nvidia-smi >/dev/null 2>&1 && nvidia-smi -L >/dev/null 2>&1; then
    hardware=nvidia
elif [ -e "$node" ]; then
    vendor=$(cat /sys/class/drm/renderD128/device/vendor 2>/dev/null || echo unknown)
    case "$vendor" in
        0x8086) hardware=intel ;;
        0x1002) hardware=amd ;;
        *) hardware=none ;;
    esac
fi
case "$hardware" in
    intel|amd|nvidia|none) ;;
    *) echo "HW debe ser intel, amd, nvidia o none" >&2; exit 2 ;;
esac
if [ -e "$node" ]; then
    render_gid=$(stat -c %g "$node" 2>/dev/null || echo 109)
fi

tmp="$env_file.tmp.$$"
trap 'rm -f "$tmp"' EXIT
awk -v hw="$hardware" -v gid="$render_gid" '
    /^VIDEO_HARDWARE=/ { print "VIDEO_HARDWARE=" hw; seen_hw = 1; next }
    /^VIDEO_RENDER_GID=/ { print "VIDEO_RENDER_GID=" gid; seen_gid = 1; next }
    { print }
    END {
        if (!seen_hw) print "VIDEO_HARDWARE=" hw
        if (!seen_gid) print "VIDEO_RENDER_GID=" gid
    }
' "$env_file" > "$tmp"
mv "$tmp" "$env_file"
trap - EXIT

case "$hardware" in
    intel) echo "Vídeo: GPU Intel detectada (Quick Sync / VA-API)." ;;
    amd) echo "Vídeo: GPU AMD detectada (VA-API)." ;;
    nvidia) echo "Vídeo: GPU NVIDIA detectada (NVENC). Requiere NVIDIA Container Toolkit." ;;
    none) echo "Vídeo: sin GPU utilizable; Jellyfin convertirá con el procesador." ;;
esac
echo "Guardado en $env_file. Reinicia Vídeo con: make video-up"
