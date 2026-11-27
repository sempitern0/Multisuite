# Laravel Foundry · portable GNU Make entry point for native PowerShell / POSIX shells
ifeq ($(OS),Windows_NT)
SETUP_SCRIPT := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/setup.ps1
BACKUP_SCRIPT := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/backup.ps1
RESET_SCRIPT := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/reset-project.ps1
HTTPS_SCRIPT := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/https-setup.ps1
STATUS_SCRIPT := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/status.ps1
PREFLIGHT_SCRIPT := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/preflight.ps1
HOST_PREPARE := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/environment.ps1 -Mode Apply
HOST_DOCTOR := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/environment.ps1 -Mode Doctor
HTTP_CHECK := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/check-http.ps1
ACCOUNTS_NETWORK := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/accounts-network.ps1
NAS_DISCOVER := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/nas-discover.ps1
NAS_APPLY := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/nas-apply.ps1
NAS_BACKUP := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/nas-backup.ps1
else
SHELL := /bin/sh
SETUP_SCRIPT := sh scripts/setup.sh
BACKUP_SCRIPT := sh scripts/backup.sh
RESET_SCRIPT := sh scripts/reset-project.sh
HTTPS_SCRIPT := sh scripts/https-setup.sh
STATUS_SCRIPT := sh scripts/status.sh
PREFLIGHT_SCRIPT := sh scripts/preflight.sh
HOST_PREPARE := sh scripts/environment.sh apply
HOST_DOCTOR := sh scripts/environment.sh doctor
HTTP_CHECK := sh scripts/check-http.sh
ACCOUNTS_NETWORK := sh scripts/accounts-network.sh
NAS_DISCOVER := sh scripts/nas-discover.sh
NAS_APPLY := TARGETS="$(TARGETS)" UPLOAD="$(UPLOAD)" YES="$(YES)" sh scripts/nas-apply.sh
NAS_BACKUP := FILES="$(FILES)" TARGET="$(TARGET)" SNAPSHOT="$(SNAPSHOT)" sh scripts/nas-backup.sh
endif

.DEFAULT_GOAL := help
DC := docker compose
# A configured music.env opts the independent stack into local lifecycle commands.
# Override with MUSIC_AUTO=0 for Laravel-only operation.
MUSIC_AUTO ?= $(if $(wildcard music.env),1,0)
MUSIC_DC := docker compose --env-file music.env -f compose.music.yaml
# A configured reading.env does the same for Lectura (Kavita); READING_AUTO=0 skips it.
READING_AUTO ?= 0
READING_DC := docker compose --env-file reading.env -f compose.reading.yaml
# Any integrated service attaches the portal to the shared accounts network.
# Real disks prepared by `make nas-apply` (compose.nas.yaml, local) replace the
# development NAS volume (deploy/compose.nas-dev.yaml). NAS_DEV=0 also opts out.
NAS_REAL := $(wildcard compose.nas.yaml)
NAS_DEV ?= $(if $(NAS_REAL),0,1)
# Local administrator pilot. Production opts in after configuring its own mounts.
KOMGA_AUTO ?= $(NAS_DEV)
KOMGA_DC := docker compose -f compose.komga.yaml
# Vídeo (Jellyfin): a video.env (created by `make video-detect`) opts in; VIDEO_AUTO=0 skips it.
# Its KEY=VALUE lines are valid Make assignments: the detected GPU selects the device override.
VIDEO_AUTO ?= $(if $(wildcard video.env),1,0)
-include video.env
VIDEO_HARDWARE ?= none
export VIDEO_HARDWARE PORTAL_VIDEO_URL
VIDEO_DC := docker compose --env-file video.env -f compose.video.yaml
ifneq ($(filter intel amd nvidia,$(VIDEO_HARDWARE)),)
VIDEO_DC += -f deploy/compose.video-$(VIDEO_HARDWARE).yaml
endif
ifeq ($(OS),Windows_NT)
VIDEO_DETECT := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/video-detect.ps1
else
VIDEO_DETECT := HW="$(HW)" sh scripts/video-detect.sh
endif
# Red privada (Headscale, docs/VPN.md): a vpn.env opts in to `make vpn-*` only; `make up` never
# starts it. Its KEY=VALUE lines are valid Make assignments: VPN_TLS selects the HTTPS override.
-include vpn.env
VPN_TLS ?= none
VPN_DC := docker compose --env-file vpn.env -f compose.vpn.yaml
ifeq ($(VPN_TLS),acme-dns)
VPN_DC += -f deploy/compose.vpn-tls.yaml
endif
ifeq ($(OS),Windows_NT)
VPN_SCRIPT := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/vpn.ps1
else
VPN_SCRIPT := sh scripts/vpn.sh
endif
SERVICES_AUTO := $(if $(filter 1,$(MUSIC_AUTO) $(READING_AUTO) $(KOMGA_AUTO) $(VIDEO_AUTO)),1,0)
DC_FILES := -f compose.yaml $(if $(wildcard compose.override.yaml),-f compose.override.yaml)
ifeq ($(SERVICES_AUTO),1)
# Keep host-generated overrides; append service connectivity without rewriting them.
DC_FILES += -f deploy/compose.portal-accounts.example.yaml
endif
ifeq ($(MUSIC_AUTO),1)
MUSIC_DC += -f deploy/compose.music-accounts.example.yaml
endif
ifeq ($(READING_AUTO),1)
DC_FILES += -f deploy/compose.portal-reading.example.yaml
READING_DC += -f deploy/compose.reading-accounts.example.yaml
endif
ifeq ($(KOMGA_AUTO),1)
DC_FILES += -f deploy/compose.portal-komga.example.yaml
endif
ifeq ($(VIDEO_AUTO),1)
DC_FILES += -f deploy/compose.portal-video.example.yaml
endif
ifeq ($(READING_AUTO),0)
DC_FILES += -f deploy/compose.portal-kavita-disabled.yaml
endif
ifeq ($(NAS_DEV),1)
DC_FILES += -f deploy/compose.nas-dev.yaml
# Services read the people's connected folders from the same development volume.
MUSIC_DC += -f deploy/compose.music-media.dev.yaml
READING_DC += -f deploy/compose.reading-media.dev.yaml
VIDEO_DC += -f deploy/compose.video-media.dev.yaml
endif
ifneq ($(NAS_REAL),)
DC_FILES += -f compose.nas.yaml
endif
DC := docker compose $(DC_FILES)
# After start: wait for the DB, migrate and fill unset storage limits. Independent of
# NAS_DEV; never overwrites GUI choices; no-op in production. DEV_BOOTSTRAP=0 opts out
# (an empty recipe line is skipped by Make on every shell, including Windows).
DEV_BOOTSTRAP ?= 1
BOOTSTRAP_STEP := $(if $(filter 1,$(DEV_BOOTSTRAP)),$(DC) exec -T app php artisan portal:dev-bootstrap)
STARTER ?=
# Explicit all-profiles stop also removes containers started manually through a
# profile not included in the current .env.
ALL_PROFILES := --profile dev --profile tools --profile workers --profile scheduler --profile redis --profile https --profile postgres --profile mariadb --profile mysql --profile phpmyadmin --profile mailpit --profile pgweb --profile adminer
.PHONY: nas-discover nas-apply nas-backup nas-backup-verify nas-backup-restore nas-backup-snapshots music-up music-stop music-status music-logs music-config reading-up reading-stop reading-status reading-logs reading-config help check status doctor host-config setup reconfigure up up-detached up-wait up-logs start stop restart down build images rebuild ps logs shell artisan composer npm migrate test lint backup env-sync https-setup reset-project worker scheduler redis config

help:
	@echo "Laravel Foundry - self-hosted Laravel environments with Docker Compose"
	@echo ""
	@echo "Setup:       make setup [STARTER=laravel|svelte|vue|react|livewire]"
	@echo "Lifecycle:   make up | up-wait | up-logs | start | stop | restart | down"
	@echo "Environment: make build | rebuild | doctor | host-config"
	@echo "Images only: make images"
	@echo "Diagnostics: make check | status | ps | logs | config | shell"
	@echo "Laravel:     make artisan CMD='route:list' | migrate | test | lint"
	@echo "Tooling:     make composer CMD='install' | npm CMD='run build'"
	@echo "Optional:    make worker | scheduler | redis"
	@echo "Music:       make music-up | music-stop | music-status | music-logs"
	@echo "Video:       make video-detect [HW=intel|amd|nvidia|none] | video-up | video-stop | video-status | video-logs"
	@echo "VPN:         make vpn-up | vpn-join | vpn-person NAME= | vpn-approve NAME= CODE= | vpn-key NAME= | vpn-devices | vpn-remove ID= | vpn-urls | vpn-status | vpn-logs | vpn-stop"
	@echo "Auto music:  music.env enables Navidrome; use MUSIC_AUTO=0 to skip"
	@echo "Reading:     make reading-up | reading-stop | reading-status | reading-logs"
	@echo "Auto read:   reading.env enables Kavita; use READING_AUTO=0 to skip"
	@echo "Dev NAS:     private storage volume; use NAS_DEV=0 with real disks"
	@echo "Real disks:  make nas-discover | nas-apply TARGETS='/mnt/disk1' (Linux server)"
	@echo "NAS backup:  make nas-backup | nas-backup-verify | nas-backup-snapshots | nas-backup-restore TARGET=/empty/dir"
	@echo "Bootstrap:   migrations + unset limits after up/start; DEV_BOOTSTRAP=0 skips"
	@echo "Operations:  make env-sync | backup | https-setup | reset-project"
	@echo ""
	@echo "WARNING: reset-project removes generated app files and Docker volumes."

setup: accounts-network
	@$(HOST_PREPARE)
	@$(SETUP_SCRIPT) $(if $(strip $(STARTER)),$(if $(filter Windows_NT,$(OS)),-Starter "$(STARTER)","$(STARTER)"),)

reconfigure:
	@$(HOST_PREPARE)
ifeq ($(OS),Windows_NT)
	@$(SETUP_SCRIPT) -Reconfigure
else
	FOUNDRY_WIZARD_MODE=--reconfigure $(SETUP_SCRIPT)
endif

# Detached startup is the default. Readiness is optional and explicit.
up: accounts-network
	@$(HOST_PREPARE)
	@$(PREFLIGHT_SCRIPT)
	@$(DC) up -d
ifeq ($(MUSIC_AUTO),1)
	@$(MUSIC_DC) up -d
endif
ifeq ($(READING_AUTO),1)
	@$(READING_DC) up -d
endif
ifeq ($(KOMGA_AUTO),1)
	@$(KOMGA_DC) up -d
endif
ifeq ($(VIDEO_AUTO),1)
	@$(VIDEO_DC) up -d
endif
ifeq ($(READING_AUTO),0)
ifneq ($(wildcard reading.env),)
	@docker compose --env-file reading.env -f compose.reading.yaml -f deploy/compose.reading-accounts.example.yaml stop
endif
endif
	-@$(BOOTSTRAP_STEP)
	-@$(STATUS_SCRIPT)

up-detached: up

up-wait: accounts-network
	@$(HOST_PREPARE)
	@$(PREFLIGHT_SCRIPT)
	@$(DC) up -d --wait --wait-timeout 120
ifeq ($(MUSIC_AUTO),1)
	@$(MUSIC_DC) up -d --wait --wait-timeout 120
endif
ifeq ($(READING_AUTO),1)
	@$(READING_DC) up -d --wait --wait-timeout 120
endif
ifeq ($(KOMGA_AUTO),1)
	@$(KOMGA_DC) up -d --wait --wait-timeout 180
endif
ifeq ($(VIDEO_AUTO),1)
	@$(VIDEO_DC) up -d --wait --wait-timeout 180
endif
ifeq ($(READING_AUTO),0)
ifneq ($(wildcard reading.env),)
	@docker compose --env-file reading.env -f compose.reading.yaml -f deploy/compose.reading-accounts.example.yaml stop
endif
endif
	-@$(BOOTSTRAP_STEP)
	-$(HTTP_CHECK)
	-@$(STATUS_SCRIPT)

up-logs: accounts-network
	@$(HOST_PREPARE)
	@$(DC) up -d
ifeq ($(MUSIC_AUTO),1)
	@$(MUSIC_DC) up -d
endif
ifeq ($(READING_AUTO),1)
	@$(READING_DC) up -d
endif
ifeq ($(KOMGA_AUTO),1)
	@$(KOMGA_DC) up -d
endif
ifeq ($(VIDEO_AUTO),1)
	@$(VIDEO_DC) up -d
endif
ifeq ($(READING_AUTO),0)
ifneq ($(wildcard reading.env),)
	@docker compose --env-file reading.env -f compose.reading.yaml -f deploy/compose.reading-accounts.example.yaml stop
endif
endif
	@$(DC) up

# Retain containers/networks for fast daily resume; no destructive operations.
start: accounts-network
	@$(HOST_PREPARE)
	@$(PREFLIGHT_SCRIPT)
ifeq ($(SERVICES_AUTO),1)
	@$(DC) up -d
else
	@$(DC) start
endif
ifeq ($(MUSIC_AUTO),1)
	@$(MUSIC_DC) up -d
endif
ifeq ($(READING_AUTO),1)
	@$(READING_DC) up -d
endif
ifeq ($(KOMGA_AUTO),1)
	@$(KOMGA_DC) up -d
endif
ifeq ($(VIDEO_AUTO),1)
	@$(VIDEO_DC) up -d
endif
ifeq ($(READING_AUTO),0)
ifneq ($(wildcard reading.env),)
	@docker compose --env-file reading.env -f compose.reading.yaml -f deploy/compose.reading-accounts.example.yaml stop
endif
endif
	-@$(BOOTSTRAP_STEP)
	-@$(STATUS_SCRIPT)

stop:
ifeq ($(VIDEO_AUTO),1)
	@$(VIDEO_DC) stop
endif
ifeq ($(KOMGA_AUTO),1)
	@$(KOMGA_DC) stop
endif
ifeq ($(MUSIC_AUTO),1)
	@$(MUSIC_DC) stop
endif
ifeq ($(READING_AUTO),1)
	@$(READING_DC) stop
endif
	@$(DC) stop

restart: stop start

down:
ifeq ($(VIDEO_AUTO),1)
	@$(VIDEO_DC) down
endif
ifeq ($(KOMGA_AUTO),1)
	@$(KOMGA_DC) down
endif
ifeq ($(MUSIC_AUTO),1)
	@$(MUSIC_DC) down
endif
ifeq ($(READING_AUTO),1)
	@$(READING_DC) down
endif
	@$(DC) $(ALL_PROFILES) down --remove-orphans

# Build explicit image targets; do not force Vite/HMR when HTTPS was selected.
build: setup

images:
	@$(HOST_PREPARE)
	@$(DC) build app composer node

rebuild: build accounts-network
	@$(DC) up -d --wait --wait-timeout 120 --force-recreate
	-@$(BOOTSTRAP_STEP)

doctor:
	@$(HOST_DOCTOR)

host-config:
	@$(HOST_PREPARE)

check:
	@$(PREFLIGHT_SCRIPT)

status:
	@$(STATUS_SCRIPT)

ps:
	@$(DC) ps

logs:
	@$(DC) logs -f --tail=100

config:
	@$(DC) config -q

shell:
	@$(DC) exec app sh

artisan:
	@$(DC) exec app php artisan $(CMD)

composer:
	@$(DC) run --rm --no-deps composer composer $(CMD)

npm:
	@$(DC) --profile dev run --rm --no-deps --entrypoint npm node $(CMD)

migrate:
	@$(DC) exec app php artisan migrate

test:
	@$(DC) exec -e APP_ENV=testing -e APP_CONFIG_CACHE=cache/config.testing.php app php artisan test $(CMD)

# Composer lives in the toolbox, not in PHP-FPM. Check availability of
# lint:check for the selected Laravel starter before invoking this target.
lint:
	@$(DC) run --rm --no-deps composer composer run lint:check

worker:
	@$(DC) --profile workers up -d worker

scheduler:
	@$(DC) --profile scheduler up -d scheduler

redis:
	@$(DC) --profile redis up -d redis

backup:
	$(BACKUP_SCRIPT)

env-sync:
	@$(DC) run --rm --no-deps composer php /opt/letsgo/env-sync.php --root-only

https-setup:
	$(HTTPS_SCRIPT)

reset-project:
	$(RESET_SCRIPT)


# Read-only disk inventory, then preparation of chosen mount points (Linux server).
nas-discover:
	@$(NAS_DISCOVER)

nas-apply:
	@$(NAS_APPLY)

# Encrypted backup to an external destination (backup.env); scheduled by the operator.
nas-backup:
	@$(NAS_BACKUP) run

nas-backup-verify:
	@$(NAS_BACKUP) verify

nas-backup-restore:
	@$(NAS_BACKUP) restore

nas-backup-snapshots:
	@$(NAS_BACKUP) snapshots

# Independent commands deliberately fail if music.env is absent or invalid.
music-up: accounts-network
	@$(MUSIC_DC) up -d

music-stop:
	@$(MUSIC_DC) stop

music-status:
	@$(MUSIC_DC) ps

music-logs:
	@$(MUSIC_DC) logs -f --tail=100 navidrome

music-config:
	@$(MUSIC_DC) config --quiet

# Lectura (Kavita): independent commands fail if reading.env is absent or invalid.
reading-up: accounts-network
	@$(READING_DC) up -d

reading-stop:
	@$(READING_DC) stop

reading-status:
	@$(READING_DC) ps

reading-logs:
	@$(READING_DC) logs -f --tail=100 kavita

reading-config:
	@$(READING_DC) config --quiet

# Shared external network survives either stack's down/recreation.
.PHONY: accounts-network
accounts-network:
ifeq ($(SERVICES_AUTO),1)
	@$(ACCOUNTS_NETWORK)
	@docker volume create multisuite-music-identity
endif

.PHONY: video-detect video-up video-stop video-status video-logs video-config
# Detects the GPU and writes video.env; run again after changing the graphics card.
video-detect:
	@$(VIDEO_DETECT)
video-up: accounts-network
	@$(VIDEO_DC) up -d
video-stop:
	@$(VIDEO_DC) stop
video-status:
	@$(VIDEO_DC) ps
video-logs:
	@$(VIDEO_DC) logs -f --tail=100 jellyfin
video-config:
	@$(VIDEO_DC) config --quiet

.PHONY: vpn-up vpn-stop vpn-restart vpn-status vpn-logs vpn-config vpn-join vpn-person vpn-approve vpn-key vpn-devices vpn-remove vpn-urls
# Independent commands deliberately fail if vpn.env is absent or invalid (docs/VPN.md).
vpn-up:
	@$(ACCOUNTS_NETWORK)
	@$(VPN_DC) up -d
vpn-stop:
	@$(VPN_DC) stop
vpn-restart:
	@$(VPN_DC) restart headscale vpn-entry
vpn-status:
	@$(VPN_DC) ps
vpn-logs:
	@$(VPN_DC) logs -f --tail=100 headscale vpn-node vpn-entry
vpn-config:
	@$(VPN_DC) config --quiet
vpn-join:
	@$(VPN_SCRIPT) join
vpn-person:
	@$(VPN_SCRIPT) person "$(NAME)"
vpn-approve:
	@$(VPN_SCRIPT) approve "$(NAME)" "$(CODE)"
vpn-key:
	@$(VPN_SCRIPT) key "$(NAME)"
vpn-devices:
	@$(VPN_SCRIPT) devices
vpn-remove:
	@$(VPN_SCRIPT) remove "$(ID)"
vpn-urls:
	@$(VPN_SCRIPT) urls

.PHONY: komga-up komga-stop komga-status komga-logs
komga-up: up
komga-stop:
	@$(KOMGA_DC) stop
komga-status:
	@$(KOMGA_DC) ps
komga-logs:
	@$(KOMGA_DC) logs --tail=100
