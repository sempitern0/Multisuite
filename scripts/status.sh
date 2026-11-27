#!/usr/bin/env sh
# Read-only status card. Never print passwords or application secrets.
set -eu
if [ -t 1 ] && [ "${NO_COLOR:-}" = '' ] && [ "${TERM:-}" != dumb ]; then
  cyan=$(printf '\033[36m'); green=$(printf '\033[32m'); yellow=$(printf '\033[33m'); dim=$(printf '\033[90m'); reset=$(printf '\033[0m')
else cyan=''; green=''; yellow=''; dim=''; reset=''; fi
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
[ -f .env ] || { echo 'Foundry: missing .env; run make setup.' >&2; exit 1; }
value() {
  sed -n "s/^$1=//p" .env | tail -n 1 | tr -d '\r' | sed 's/^"//;s/"$//'
}
setting() {
  v=$(value "$1")
  if [ -n "$v" ]; then printf '%s' "$v" | tr -d '\r\n'; else printf '%s' "$2"; fi
}
name=$(setting PROJECT_NAME foundry)
label=$(setting APP_LABEL "$name")
db=$(setting DB_ENGINE postgres)
db_name=$(setting DB_DATABASE foundry)
queue=$(setting QUEUE_ENGINE sync)
mail=$(setting MAIL_ENGINE log)
ui=$(setting DB_UI none)
https=$(setting HTTPS_ENABLED 0)
http_port=$(setting HTTP_PORT 8080)
https_port=$(setting HTTPS_PORT 443)
domain=$(setting LOCAL_DOMAIN foundry.test)
profiles=$(setting COMPOSE_PROFILES '-')
if [ -f app/.letsgo-starter ]; then starter=$(tr -d '\r\n' < app/.letsgo-starter)
elif [ -f app/artisan ]; then starter=existing
else starter=pending
fi

running=''
if docker compose ps --services --status running >/dev/null 2>&1; then
  running=$(docker compose ps --services --status running 2>/dev/null)
  status=available
else
  status=unavailable
fi
active() { printf '%s\n' "$running" | grep -Fxq "$1"; }
section() { printf '\n%s-- %s ------------------------------------------------%s\n' "$cyan" "$1" "$reset"; }
item() { printf '  %-20s %s\n' "$1" "$2"; }
service() {
  if active "$1"; then state="${green}[OK] running${reset}"
  elif [ "$status" = unavailable ]; then state="${yellow}[?] unknown${reset}"
  else state="${yellow}[--] stopped${reset}"; fi
  printf '  %-23s %s\n' "$2" "$state"
}
link() {
  if [ "$3" = yes ]; then printf '  %-20s %s%s%s\n' "$1" "$4" "$2" "$reset"
  else printf '  %-20s %s[--] service not running%s\n' "$1" "$yellow" "$reset"; fi
}
ready=no
if active app && active web; then ready=yes; fi

# Multisuite services run as separate Compose stacks (music.env, video.env, reading.env, Komga).
# Queried by Compose labels, so the extra override files Make adds do not matter.
file_setting() {
  [ -f "$1" ] || { printf '%s' "$3"; return; }
  v=$(sed -n "s/^$2=//p" "$1" | tail -n 1 | tr -d '\r' | sed 's/^"//;s/"$//')
  if [ -n "$v" ]; then printf '%s' "$v" | tr -d '\r\n'; else printf '%s' "$3"; fi
}
stack_running() {
  [ "$status" = available ] || return 1
  [ -n "$(docker ps -q --filter "label=com.docker.compose.project=$1" --filter "label=com.docker.compose.service=$2" --filter status=running 2>/dev/null)" ]
}
stack_exists() {
  [ "$status" = available ] || return 1
  [ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$1" 2>/dev/null)" ]
}
# Entry is the gateway when the stack runs with the portal's account bridge, else the service itself.
entry_running() {
  if stack_running "$1" "$2"; then return 0; fi
  [ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$1" --filter "label=com.docker.compose.service=$2" 2>/dev/null)" ] && stack_running "$1" "$3"
}
nas_link() {
  if [ "$3" = yes ]; then printf '  %-20s %s%s%s\n' "$1" "$green" "$2" "$reset"
  elif [ "$status" = unavailable ]; then printf '  %-20s %s[?] unknown%s\n' "$1" "$yellow" "$reset"
  else printf '  %-20s %s[--] stopped%s  %s(%s)%s\n' "$1" "$yellow" "$reset" "$dim" "$4" "$reset"; fi
}
nas_services() {
  shown=no
  if [ -f music.env ]; then
    shown=yes; up=no; entry_running "$(file_setting music.env MUSIC_PROJECT_NAME multisuite-music)" music-gateway navidrome && up=yes
    nas_link 'Music (Navidrome)' "http://localhost:$(file_setting music.env NAVIDROME_PORT 4533)" "$up" 'make music-up'
  fi
  if stack_exists multisuite-komga; then
    shown=yes; up=no; stack_running multisuite-komga komga-gateway && up=yes
    nas_link 'Reading (Komga)' "http://localhost:$(setting KOMGA_PORT 25600)" "$up" 'make komga-up'
  fi
  if [ -f reading.env ]; then
    shown=yes; up=no; entry_running "$(file_setting reading.env READING_PROJECT_NAME multisuite-reading)" reading-gateway kavita && up=yes
    nas_link 'Reading (Kavita)' "http://localhost:$(file_setting reading.env KAVITA_PORT 5000)" "$up" 'make reading-up'
  fi
  if [ -f video.env ]; then
    shown=yes; up=no; stack_running "$(file_setting video.env VIDEO_PROJECT_NAME multisuite-video)" video-gateway && up=yes
    nas_link 'Video (Jellyfin)' "http://localhost:$(file_setting video.env VIDEO_PORT 8096)" "$up" 'make video-up'
    item 'Video GPU' "$(file_setting video.env VIDEO_HARDWARE none)"
  fi
  [ "$shown" = yes ] || printf '  %sNone configured: make music-up, make video-detect (docs/JELLYFIN.md).%s\n' "$dim" "$reset"
}
printf '\n%s======================================================%s\n' "$cyan" "$reset"
printf '%s  LARAVEL FOUNDRY  /  %s%s\n' "$cyan" "$label" "$reset"
printf '%s======================================================%s\n' "$cyan" "$reset"
section 'ENDPOINTS'
if [ "$https" = 1 ]; then
  suffix=":$https_port"; [ "$https_port" = 443 ] && suffix=''
  webready=no
  if [ "$ready" = yes ] && active caddy-local; then webready=yes; fi
  link 'Primary app' "https://$domain$suffix" "$webready" "$green"
fi
primary="$cyan"; [ "$https" = 1 ] || primary="$green"
link 'Application HTTP' "http://localhost:$http_port" "$ready" "$primary"
if [ "$mail" = mailpit ]; then
  enabled=no; active mailpit && enabled=yes
  link 'Mailpit' "http://localhost:$(setting MAILPIT_PORT 8025)" "$enabled" "$cyan"
fi
case "$ui" in
 pgweb|phpmyadmin|adminer)
   enabled=no; active "$ui" && enabled=yes
   link "$ui" "http://localhost:$(setting DB_UI_PORT 8081)" "$enabled" "$cyan";;
esac
if [ "$https" != 1 ] && active node; then
  link 'Vite HMR' "http://localhost:$(setting VITE_PORT 5173)" yes "$cyan"
fi
section 'MULTISUITE SERVICES'
nas_services
section 'CONFIGURATION'
item 'Project / Starter' "$name / $starter"
item 'Database' "$db | $db_name"
item 'Queue / Mail' "$queue | $mail"
item 'Database UI' "$ui"
if [ "$https" = 1 ]; then https_label=enabled; else https_label=disabled; fi
item 'HTTPS' "$https_label"
item 'Profiles' "$profiles"
section 'SERVICES'
[ "$status" = available ] || printf '  %sDocker is unavailable; service status cannot be verified.%s\n' "$yellow" "$reset"
service app 'Laravel PHP'
service web 'Nginx'
[ "$https" = 1 ] && service caddy-local 'HTTPS Caddy'
[ "$db" = sqlite ] || service "$db" "$db"
[ "$queue" = sync ] || service worker 'Queue worker'
service scheduler 'Scheduler'
[ "$queue" = redis ] && service redis 'Redis'
[ "$mail" = mailpit ] && service mailpit 'Mailpit'
[ "$ui" = none ] || service "$ui" "$ui"
section 'NEXT STEPS'
[ "$https" = 1 ] && printf '  %sHTTPS: hosts 127.0.0.1 %s / make https-setup.%s\n' "$yellow" "$domain" "$reset"
printf '  %s[OK] = container started; this is not an HTTP health probe.%s\n' "$dim" "$reset"
echo '  make status | make ps | make logs | make up-wait | make reconfigure'
printf '%s======================================================%s\n' "$cyan" "$reset"
