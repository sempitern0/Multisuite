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
TENANCY_CHECK_SCRIPT := powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/tenancy-check.ps1
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
TENANCY_CHECK_SCRIPT := sh scripts/tenancy-check.sh
endif

.DEFAULT_GOAL := help
DC := docker compose
# A configured music.env opts the independent stack into local lifecycle commands.
# Override with MUSIC_AUTO=0 for Laravel-only operation.
MUSIC_AUTO ?= $(if $(wildcard music.env),1,0)
MUSIC_DC := docker compose --env-file music.env -f compose.music.yaml
# Development NAS volume + automatic migrations/limits on up. NAS_DEV=0 opts out
# (e.g. when NAS_STORAGE_VOLUMES points at real disks in app/.env).
NAS_DEV ?= 1
DC_FILES := -f compose.yaml $(if $(wildcard compose.override.yaml),-f compose.override.yaml)
ifeq ($(MUSIC_AUTO),1)
# Keep host-generated overrides; append service connectivity without rewriting them.
DC_FILES += -f deploy/compose.portal-accounts.example.yaml
MUSIC_DC += -f deploy/compose.music-accounts.example.yaml
endif
ifeq ($(NAS_DEV),1)
DC_FILES += -f deploy/compose.nas-dev.yaml
endif
ifneq ($(MUSIC_AUTO)$(NAS_DEV),00)
DC := docker compose $(DC_FILES)
endif
# Waits for the database, migrates and fills unset storage limits. Never overwrites
# choices made in the GUI and does nothing in production.
DEV_BOOTSTRAP := $(DC) exec -T app php artisan portal:dev-bootstrap
STARTER ?=
# Explicit all-profiles stop also removes containers started manually through a
# profile not included in the current .env.
ALL_PROFILES := --profile dev --profile tools --profile workers --profile scheduler --profile redis --profile https --profile postgres --profile mariadb --profile mysql --profile phpmyadmin --profile mailpit --profile pgweb --profile adminer
.PHONY: music-up music-stop music-status music-logs music-config help check status doctor host-config setup reconfigure up up-detached up-wait up-logs start stop restart down build images rebuild ps logs shell artisan composer npm migrate test lint backup env-sync https-setup reset-project worker scheduler redis config tenancy-check

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
	@echo "Auto music:  music.env enables Navidrome; use MUSIC_AUTO=0 to skip"
	@echo "Dev NAS:     private volume + migrations on up; use NAS_DEV=0 to skip"
	@echo "Operations:  make env-sync | backup | https-setup | reset-project"
	@echo "SaaS:        make tenancy-check     Check local tenant hostname routing"
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
ifeq ($(NAS_DEV),1)
	-@$(DEV_BOOTSTRAP)
endif
	-@$(STATUS_SCRIPT)

up-detached: up

up-wait: accounts-network
	@$(HOST_PREPARE)
	@$(PREFLIGHT_SCRIPT)
	@$(DC) up -d --wait --wait-timeout 120
ifeq ($(MUSIC_AUTO),1)
	@$(MUSIC_DC) up -d --wait --wait-timeout 120
endif
ifeq ($(NAS_DEV),1)
	-@$(DEV_BOOTSTRAP)
endif
	-$(HTTP_CHECK)
	-@$(STATUS_SCRIPT)

up-logs: accounts-network
	@$(HOST_PREPARE)
ifeq ($(MUSIC_AUTO),1)
	@$(MUSIC_DC) up -d
endif
	@$(DC) up

# Retain containers/networks for fast daily resume; no destructive operations.
start: accounts-network
	@$(HOST_PREPARE)
	@$(PREFLIGHT_SCRIPT)
ifeq ($(MUSIC_AUTO),1)
	@$(DC) up -d
else
	@$(DC) start
endif
ifeq ($(MUSIC_AUTO),1)
	@$(MUSIC_DC) up -d
endif
ifeq ($(NAS_DEV),1)
	-@$(DEV_BOOTSTRAP)
endif
	-@$(STATUS_SCRIPT)

stop:
ifeq ($(MUSIC_AUTO),1)
	@$(MUSIC_DC) stop
endif
	@$(DC) stop

restart: stop start

down:
ifeq ($(MUSIC_AUTO),1)
	@$(MUSIC_DC) down
endif
	@$(DC) $(ALL_PROFILES) down --remove-orphans

# Build explicit image targets; do not force Vite/HMR when HTTPS was selected.
build: setup

images:
	@$(HOST_PREPARE)
	@$(DC) build app composer node

rebuild: build accounts-network
	@$(DC) up -d --wait --wait-timeout 120 --force-recreate
ifeq ($(NAS_DEV),1)
	-@$(DEV_BOOTSTRAP)
endif

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

tenancy-check:
	$(TENANCY_CHECK_SCRIPT)

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

# Shared external network survives either stack's down/recreation.
.PHONY: accounts-network
accounts-network:
ifeq ($(MUSIC_AUTO),1)
	@$(ACCOUNTS_NETWORK)
endif
