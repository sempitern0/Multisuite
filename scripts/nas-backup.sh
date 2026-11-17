#!/bin/sh
# Encrypted, deduplicated backup of the portal to an external destination with restic,
# run by the operator (cron/systemd) on the Linux server. The portal never runs this:
# it has no Docker socket and only reads local/backup-status.json.
#   run        portal DB dump + private files (+ extra paths), Navidrome data, retention, check
#   verify     rehearsed restore of the latest snapshot into a throwaway volume and database
#   restore    restore into an EMPTY directory (TARGET=/path [SNAPSHOT=id]); never over live data
#   snapshots  list what the repository holds
# Settings live in backup.env (ignored by Git); see backup.env.example and docs/STORAGE.md.
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
DOCKER=${DOCKER:-docker}
FINDMNT=${FINDMNT:-findmnt}
LSBLK=${LSBLK:-lsblk}
command=${1:-run}
status=local/backup-status.json
staging=local/backup-staging
lock=local/backup.lock
rehearsal=multisuite-restore-rehearsal
step=preflight
started=''
music_stopped=0
kavita_to_restart=
trial_volume=
trial_container=
locked=0

die() { echo "nas-backup: $*" >&2; exit 1; }
now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
setting() { sed -n "s/^$1=//p" "$2" 2>/dev/null | tail -n 1 | tr -d '\r'; }
safe_path() {
    case "$1" in /*) ;; *) die "$1: usa rutas absolutas." ;; esac
    case "$1" in *[!A-Za-z0-9/._-]*) die "$1: solo letras, números, / . _ -" ;; esac
}
# Physical disk behind a path (parent of its partition), or nothing when unknown.
disk_of() {
    source=$("$FINDMNT" -no SOURCE -T "$1" 2>/dev/null | head -n 1) || true
    [ -n "$source" ] || return 0
    parent=$("$LSBLK" -no PKNAME "$source" 2>/dev/null | head -n 1) || true
    printf '%s' "${parent:-$source}"
}
# Written in place: the file is bind-mounted into the portal and a rename would detach it.
write_status() {
    [ -n "$started" ] || return 0
    printf '{"schema_version":1,"started_at":"%s","finished_at":"%s","ok":%s,"step":"%s"}\n' "$started" "$2" "$1" "$step" > "$status"
}
music() { "$DOCKER" compose --env-file music.env -f compose.music.yaml "$@"; }
cleanup() {
    code=$?
    trap - EXIT HUP INT TERM
    if [ "$music_stopped" = 1 ]; then
        music start navidrome >/dev/null 2>&1 || echo "nas-backup: Navidrome no volvió a arrancar; ejecuta make music-up." >&2
    fi
    if [ -n "$kavita_to_restart" ]; then
        "$DOCKER" start "$kavita_to_restart" >/dev/null || echo "nas-backup: ejecuta make reading-up para reanudar Kavita." >&2
    fi
    [ -z "$trial_container" ] || "$DOCKER" rm -f "$trial_container" >/dev/null
    [ -z "$trial_volume" ] || "$DOCKER" volume rm "$trial_volume" >/dev/null
    [ "$command" = verify-kavita ] || rm -rf "$staging"
    [ "$step" != database ] || "$DOCKER" compose exec -T postgres rm -f /tmp/portal.dump >/dev/null 2>&1 || true
    [ "$command" != verify ] || "$DOCKER" volume rm -f "$rehearsal" >/dev/null 2>&1 || true
    if [ "$command" = run ] && [ "$code" != 0 ] && [ "$locked" = 1 ]; then
        write_status false "$(now)"
        echo "nas-backup: la copia falló en el paso '$step'." >&2
    fi
    [ "$locked" != 1 ] || rmdir "$lock" 2>/dev/null || true
    exit "$code"
}

[ "$(uname -s)" = Linux ] || die "se ejecuta en el servidor Linux."
[ -f backup.env ] || die "falta backup.env (copia backup.env.example y rellénalo)."
chmod 600 backup.env
[ -n "$(setting RESTIC_PASSWORD backup.env)" ] || die "RESTIC_PASSWORD vacío en backup.env. Guárdalo también fuera del servidor: sin él la copia no se puede leer."
image=$(setting RESTIC_IMAGE backup.env); image=${image:-restic/restic:0.19.1}
local_repo=$(setting BACKUP_REPOSITORY_PATH backup.env)
remote_repo=$(setting RESTIC_REPOSITORY backup.env)
if [ -n "$local_repo" ] && [ -n "$remote_repo" ]; then die "elige BACKUP_REPOSITORY_PATH (disco externo) o RESTIC_REPOSITORY (remoto), no ambos."; fi
if [ -z "$local_repo" ] && [ -z "$remote_repo" ]; then die "indica el destino en backup.env."; fi

# Private files: every mount prepared by nas-apply, or the development NAS volume.
sources=''
mounts=''
if [ -f local/nas-targets ]; then
    while IFS= read -r target; do
        [ -n "$target" ] || continue
        safe_path "$target"
        mounts="$mounts -v $target/multisuite:/source/nas$target:ro"
        sources="$sources $target"
    done < local/nas-targets
else
    project=$(setting COMPOSE_PROJECT_NAME .env | tr -d '"')
    project=${project:-$(basename "$PWD" | tr 'A-Z' 'a-z' | tr -cd 'a-z0-9_-')}
    # Fixed name since connected folders (deploy/compose.nas-dev.yaml); older environments used the project prefix.
    for volume in multisuite-nas-dev "${project}_nas_private"; do
        if "$DOCKER" volume inspect "$volume" >/dev/null 2>&1; then
            mounts="$mounts -v $volume:/source/nas/dev:ro"
            break
        fi
    done
fi
for extra in $(setting BACKUP_EXTRA_PATHS backup.env); do
    safe_path "$extra"
    [ -d "$extra" ] || die "$extra (BACKUP_EXTRA_PATHS) no existe."
    mounts="$mounts -v $extra:/source/extra$extra:ro"
    sources="$sources $extra"
done

repo_args='--env-file backup.env'
if [ -n "$local_repo" ]; then
    safe_path "$local_repo"
    [ -d "$local_repo" ] || die "$local_repo no existe: monta el disco externo antes (este script no monta nada)."
    # Another partition of the same disk dies with it: that is not a backup.
    if [ "$(setting BACKUP_ALLOW_SAME_DISK backup.env)" != 1 ]; then
        repo_disk=$(disk_of "$local_repo")
        [ -n "$repo_disk" ] || die "no se pudo comprobar en qué disco está $local_repo (BACKUP_ALLOW_SAME_DISK=1 solo para ensayos)."
        for source in . $sources; do
            [ "$(disk_of "$source")" != "$repo_disk" ] || die "$local_repo está en el mismo disco ($repo_disk) que los datos ($source). Usa otro disco o un destino remoto."
        done
    fi
    repo_args="$repo_args -e RESTIC_REPOSITORY=/repository -v $local_repo:/repository --network none"
fi
# shellcheck disable=SC2086 # mount lists are built from validated paths without spaces
restic() { "$DOCKER" run --rm --hostname multisuite $repo_args -e RESTIC_CACHE_DIR=/cache -v multisuite-restic-cache:/cache $extra_mounts "$image" "$@"; }
extra_mounts=''

mkdir -p local
trap cleanup EXIT HUP INT TERM

case "$command" in
snapshots)
    restic snapshots
    ;;

run)
    mkdir "$lock" 2>/dev/null || die "ya hay una copia en curso (o quedó $lock de una interrumpida: bórralo si no hay ninguna)."
    locked=1
    started=$(now)
    write_status null ''
    step=repository
    restic cat config >/dev/null 2>&1 || restic init

    step=database
    username=$(setting DB_USERNAME .env | tr -d '"'); database=$(setting DB_DATABASE .env | tr -d '"')
    [ -n "$username" ] && [ -n "$database" ] || die "faltan DB_USERNAME/DB_DATABASE en .env."
    case "$username:$database" in *[!a-zA-Z0-9_:.-]*) die "caracteres inesperados en los datos de la DB." ;; esac
    rm -rf "$staging"; mkdir -p "$staging"; chmod 700 "$staging"
    "$DOCKER" compose exec -T postgres pg_dump -Fc --no-owner --no-acl -U "$username" -d "$database" -f /tmp/portal.dump
    "$DOCKER" compose cp postgres:/tmp/portal.dump "$staging/portal.dump"
    [ -s "$staging/portal.dump" ] || die "el volcado de la base de datos está vacío."

    "$DOCKER" compose exec -T postgres rm -f /tmp/portal.dump
    step=files
    extra_mounts="-v $PWD/$staging:/source/portal-db:ro $mounts"
    restic backup --tag portal /source

    if [ -f music.env ]; then
        step=navidrome
        data=$(setting NAVIDROME_DATA_PATH music.env)
        case "$data" in /*) ;; *) data="$PWD/${data#./}" ;; esac
        safe_path "$data"
        if [ -d "$data" ]; then
            # SQLite must be quiet while it is copied; the pause lasts one incremental snapshot.
            if [ -n "$(music ps -q --status running navidrome 2>/dev/null)" ]; then
                music stop navidrome >/dev/null
                music_stopped=1
            fi
            extra_mounts="-v $data:/source/navidrome:ro"
            restic backup --tag navidrome /source
            if [ "$music_stopped" = 1 ]; then
                music start navidrome >/dev/null
                music_stopped=0
            fi
        fi
    fi

    if [ -f reading.env ]; then
        step=kavita
        container=$("$DOCKER" compose --env-file reading.env -f compose.reading.yaml ps -aq kavita)
        [ -n "$container" ] || die "falta el contenedor de Kavita; comprueba make reading-status."
        volume=$("$DOCKER" inspect --format '{{range .Mounts}}{{if and (eq .Destination "/kavita/config") (eq .Type "volume")}}{{.Name}}{{end}}{{end}}' "$container")
        case "$volume" in ''|*[!a-zA-Z0-9_.-]*) die "no se encontró el volumen de configuración de Kavita." ;; esac
        state=$("$DOCKER" inspect --format '{{.State.Status}}' "$container")
        case "$state" in
            running)
                kavita_to_restart=$container
                "$DOCKER" stop "$container" >/dev/null
                ;;
            exited|created) ;;
            *) die "Kavita en estado $state; vuelve a intentar la copia cuando esté estable." ;;
        esac
        extra_mounts="-v $volume:/source/kavita:ro"
        restic backup --tag kavita /source/kavita
        if [ -n "$kavita_to_restart" ]; then
            "$DOCKER" start "$container" >/dev/null
            kavita_to_restart=''
        fi
    fi

    step=retention
    extra_mounts=''
    daily=$(setting BACKUP_KEEP_DAILY backup.env); weekly=$(setting BACKUP_KEEP_WEEKLY backup.env); monthly=$(setting BACKUP_KEEP_MONTHLY backup.env)
    restic forget --prune --group-by host,tags --keep-daily "${daily:-7}" --keep-weekly "${weekly:-4}" --keep-monthly "${monthly:-6}"

    step=check
    subset=$(setting BACKUP_CHECK_SUBSET backup.env)
    restic check --read-data-subset "${subset:-10%}"

    step=done
    write_status true "$(now)"
    echo "Copia terminada y comprobada. Ensaya la restauración de vez en cuando: make nas-backup-verify"
    ;;

verify-kavita)
    trial_volume=$("$DOCKER" volume create)
    extra_mounts="-v $trial_volume:/restore"
    restic restore latest --tag kavita --target /restore
    trial_mount="type=volume,source=$trial_volume,destination=/kavita/config,volume-subpath=source/kavita"
    progress() {
        "$DOCKER" run --rm --network none --mount "$trial_mount,readonly" \
            -v "$PWD/scripts/kavita-progress.py:/check.py:ro" python:3.13-alpine python /check.py
    }
    before=$(progress)
    trial_image=$(setting KAVITA_IMAGE reading.env)
    trial_container=$("$DOCKER" run -d --network none --mount "$trial_mount" "${trial_image:-jvmilazz0/kavita:0.9.1}")
    attempt=0
    until "$DOCKER" exec "$trial_container" curl -fsS http://localhost:5000/api/health >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        [ "$attempt" -lt 60 ] || die "la instancia restaurada no responde."
        sleep 2
    done
    "$DOCKER" stop "$trial_container" >/dev/null
    after=$(progress)
    [ "$before" = "$after" ] || die "las posiciones de lectura difieren después del arranque."
    echo "Kavita restaurado: la instancia arrancó y conservó las posiciones de lectura."
    ;;

verify)
    "$DOCKER" volume rm -f "$rehearsal" >/dev/null 2>&1 || true
    "$DOCKER" volume create "$rehearsal" >/dev/null
    extra_mounts="-v $rehearsal:/restore"
    if [ "${FILES:-1}" = 0 ]; then
        restic restore latest --tag portal --target /restore --include /source/portal-db
    else
        restic restore latest --tag portal --target /restore
    fi
    # Clean PostgreSQL without network: proves the dump loads and holds the portal tables.
    "$DOCKER" run --rm --network none -v "$rehearsal:/restore:ro" "${VERIFY_POSTGRES_IMAGE:-postgres:17-alpine}" sh -ec '
        cp /restore/source/portal-db/portal.dump /tmp/portal.dump
        chown postgres /tmp/portal.dump
        cat > /tmp/rehearsal.sh <<"REHEARSAL"
set -e
initdb -D /tmp/pg >/dev/null
pg_ctl -D /tmp/pg -o "-c listen_addresses=" -w start >/dev/null
createdb rehearsal
pg_restore --no-owner -d rehearsal /tmp/portal.dump
printf "Base de datos restaurada: %s personas, %s migraciones.\n" "$(psql -d rehearsal -Atc "select count(*) from users")" "$(psql -d rehearsal -Atc "select count(*) from migrations")"
pg_ctl -D /tmp/pg -m fast stop >/dev/null
REHEARSAL
        su postgres -c "sh /tmp/rehearsal.sh"
        if [ -d /restore/source/nas ]; then
            [ -n "$(find /restore/source/nas -name .multisuite-volume-id | head -n 1)" ] || { echo "Faltan las marcas de los discos en la copia." >&2; exit 1; }
            printf "Archivos privados restaurados: %s.\n" "$(find /restore/source/nas -type f | wc -l)"
        fi
    '
    echo "Ensayo correcto. El volumen temporal se elimina ahora; los datos reales no se han tocado."
    ;;

restore)
    target=${TARGET:-}
    [ -n "$target" ] || die 'indica TARGET="/carpeta/vacía".'
    safe_path "$target"
    [ -d "$target" ] || die "$target no existe."
    [ -z "$(ls -A "$target")" ] || die "$target no está vacía: se restaura siempre en un destino limpio."
    extra_mounts="-v $target:/restore"
    if [ -n "${SNAPSHOT:-}" ]; then
        case "$SNAPSHOT" in *[!a-f0-9]*) die "SNAPSHOT es el identificador que muestra make nas-backup-snapshots." ;; esac
        restic restore "$SNAPSHOT" --target /restore
    else
        restic restore latest --tag portal --target /restore
        if [ -f reading.env ]; then
            restic restore latest --tag kavita --target /restore
        fi
        restic restore latest --tag navidrome --target /restore || echo "nas-backup: no hay copia de Navidrome." >&2
    fi
    echo "Restaurado en $target/source. Pasos para volver a ponerlo en servicio: docs/STORAGE.md (Restaurar)."
    ;;

*)
    die "uso: nas-backup.sh run|verify|restore|snapshots"
    ;;
esac
