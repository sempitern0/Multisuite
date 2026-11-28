#!/bin/sh
# Index of environment files (`make env-list`, docs/ENVIRONMENTS.md § Archivos de entorno).
# Each template's first line says what it is ("# name — purpose"): this prints that line and
# whether the real file exists. Values are never read. PowerShell twin: scripts/env-list.ps1.
set -eu
cd "$(dirname "$0")/.."
printf '%-22s %-10s %s\n' 'ARCHIVO' 'ESTADO' 'PARA QUÉ'
for template in .env.example app/.env.example *.env.example; do
    [ -f "$template" ] || continue
    file=${template%.example}
    state='sin crear'
    [ -f "$file" ] && state='creado'
    purpose=$(sed -n '1s/^# [^ ]* — //p' "$template")
    printf '%-22s %-10s %s\n' "$file" "$state" "${purpose:-(sin descripción)}"
done
printf '\nCrear uno: cp ARCHIVO.example ARCHIVO. Cada plantilla explica cómo se activa.\n'
