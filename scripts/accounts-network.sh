#!/bin/sh
set -eu
network=multisuite-accounts
if ! docker network inspect "$network" >/dev/null 2>&1; then
    # Another startup may create it between inspect and create.
    docker network create "$network" >/dev/null ||
        docker network inspect "$network" >/dev/null
fi
