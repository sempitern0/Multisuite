#!/bin/sh
# Read-only disk discovery for the Linux server that runs Multisuite.
# Lists disks, partitions, filesystems and real free space, suggests candidate
# mount points and writes local/nas-hardware.json for the Storage page.
# Never formats, partitions, mounts or writes to any disk.
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
LSBLK=${LSBLK:-lsblk}
FINDMNT=${FINDMNT:-findmnt}
if [ "$(uname -s)" != Linux ] || ! command -v "$LSBLK" >/dev/null 2>&1; then
    echo "nas-discover: needs a Linux host with lsblk (util-linux)." >&2
    echo "With Docker Desktop the containers only see a virtual disk: use the dev NAS (make up)." >&2
    exit 2
fi
columns=NAME,PATH,TYPE,SIZE,FSTYPE,MOUNTPOINT,LABEL,UUID,MODEL,RM,RO,FSSIZE,FSAVAIL
mkdir -p local
host=$(hostname 2>/dev/null | tr -cd 'A-Za-z0-9.-' || true)
tmp=$(mktemp ./local/.nas-hardware.XXXXXX)
trap 'rm -f "$tmp"' EXIT HUP INT TERM
{
    printf '{"schema_version":1,"generated_at":"%s","host":"%s","portal_targets":[' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${host:-server}"
    # Targets previously applied by nas-apply, one per line (paths without quotes or backslashes).
    first=1
    if [ -f local/nas-targets ]; then
        while IFS= read -r target; do
            case "$target" in ''|*'"'*|*'\'*) continue ;; esac
            [ "$first" = 1 ] || printf ','
            printf '"%s"' "$target"
            first=0
        done < local/nas-targets
    fi
    printf '],"lsblk":'
    "$LSBLK" -J -b -o "$columns"
    printf '}\n'
} > "$tmp"
mv "$tmp" local/nas-hardware.json
trap - EXIT HUP INT TERM

echo "== Discos y particiones (solo lectura) =="
"$LSBLK" -o NAME,TYPE,SIZE,FSTYPE,MOUNTPOINT,LABEL,MODEL
echo
echo "== Candidatos para Multisuite (montados, lectura/escritura, no del sistema) =="
"$FINDMNT" -rn -o TARGET,SOURCE,FSTYPE,OPTIONS -t ext4,xfs,btrfs,zfs,f2fs | while read -r target source fstype options; do
    case "$target" in /|/boot|/boot/*|/efi|/usr|/var|/var/*|/snap/*|/run/*|/proc*|/sys*|/dev*) continue ;; esac
    case ",$options," in *,ro,*) continue ;; esac
    target=$(printf '%b' "$target")
    free=$(df -B1 --output=avail "$target" 2>/dev/null | tail -1 | tr -d ' ')
    printf '  %s  (%s, %s, libre %s bytes)\n' "$target" "$source" "$fstype" "${free:-?}"
done
echo
echo "Inventario guardado en local/nas-hardware.json (lo muestra la página Almacenamiento)."
echo "Siguiente paso: make nas-apply TARGETS=\"/punto/de/montaje ...\""
