#!/bin/sh
# Every environment template starts with "# <file> — <purpose>" and `make env-list` reports
# which real files exist without printing their values.
set -eu
root=$(cd "$(dirname "$0")/.." && pwd)
for template in "$root"/.env.example "$root"/app/.env.example "$root"/*.env.example; do
    name=$(basename "${template%.example}")
    [ "$template" = "$root/app/.env.example" ] && name=app/.env
    head -n 1 "$template" | grep -q "^# $(printf '%s' "$name" | sed 's/[.]/[.]/g') — ." || { echo "Missing header in $template" >&2; exit 1; }
done
temp=$(mktemp -d)
trap 'rm -rf "$temp"' EXIT
mkdir -p "$temp/scripts" "$temp/app"
cp "$root/scripts/env-list.sh" "$temp/scripts/"
cp "$root/.env.example" "$root/music.env.example" "$root/video.env.example" "$temp/"
cp "$root/app/.env.example" "$temp/app/"
printf 'NAVIDROME_PORT=4533\nSECRET_TOKEN=do-not-print\n' > "$temp/music.env"
out=$(sh "$temp/scripts/env-list.sh")
printf '%s\n' "$out" | grep -q '^music.env *creado *Música con Navidrome'
printf '%s\n' "$out" | grep -q '^video.env *sin crear'
printf '%s\n' "$out" | grep -q '^app/.env *sin crear'
if printf '%s\n' "$out" | grep -q 'do-not-print'; then echo 'env-list printed a value' >&2; exit 1; fi
echo 'PASS env templates have headers and env-list reports files without values'
