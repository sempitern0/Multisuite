# Private network operations (docs/VPN.md), called by `make vpn-*`. POSIX twin: scripts/vpn.sh.
#   join | person NAME | approve NAME CODE | key NAME | devices | remove ID | urls
#   compose ARGS: docker compose with this stack's files (the only place that chooses them)
# Only validated values reach Headscale; nothing from the env files is evaluated.
# A plain param block on purpose: everything after the command stays in $args (compose flags).
param([string]$Command = '')
$rest = @($args)
$First = if ($rest.Count -gt 0) { [string]$rest[0] } else { '' }
$Second = if ($rest.Count -gt 1) { [string]$rest[1] } else { '' }
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

$envFile = if ($env:VPN_ENV_FILE) { $env:VPN_ENV_FILE } else { 'vpn.env' }
if (-not (Test-Path -LiteralPath $envFile)) {
    [Console]::Error.WriteLine('Falta vpn.env: copialo de vpn.env.example (docs/VPN.md).')
    exit 1
}

function Setting([string]$Name, [string]$Fallback = '') {
    $found = $Fallback
    foreach ($line in [System.IO.File]::ReadAllLines((Resolve-Path -LiteralPath $envFile))) {
        if ($line -match ('^' + [regex]::Escape($Name) + '=(.*)$')) {
            $value = $Matches[1].Trim().Trim('"') -replace '[\x00-\x1f\x7f]', ''
            $found = if ($value -ne '') { $value } else { $Fallback }
        }
    }
    return $found
}

function Compose {
    $files = @('--env-file', $envFile, '-f', 'compose.vpn.yaml')
    if ((Setting 'VPN_TLS' 'none') -eq 'acme-dns') { $files += @('-f', 'deploy/compose.vpn-tls.yaml') }
    & docker compose @files @args
    if ($LASTEXITCODE -ne 0) { throw "docker compose failed ($LASTEXITCODE)" }
}
function Headscale { Compose exec -T headscale headscale @args }

function Need([string]$Label, [string]$Value, [string]$Pattern, [string]$Hint) {
    if ($Value -notmatch $Pattern) {
        [Console]::Error.WriteLine("$Label no valido. $Hint")
        exit 2
    }
}
function PersonName([string]$Value) {
    Need 'Nombre' $Value '^[a-z0-9][a-z0-9-]{0,30}$' 'Usa minusculas, numeros y guiones (ej. ana o tio-luis).'
}
function LastLine($Output) { return (@($Output) | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' } | Select-Object -Last 1) }

switch ($Command) {
    'compose' { Compose @rest }
    'join' {
        $current = ''
        try { $current = LastLine (Compose exec -T vpn-node tailscale ip -4 2>$null) } catch { $current = '' }
        if ($current -match '^100\.') {
            Write-Output "El NAS ya esta en la red privada: $current"
            exit 0
        }
        $key = LastLine (Headscale preauthkeys create --tags tag:nas --expiration 10m)
        Need 'Clave' $key '^[A-Za-z0-9_-]{16,}$' 'Headscale no ha devuelto una clave; revisa make vpn-logs.'
        Compose exec -T vpn-node sh /opt/multisuite/node.sh join $key
        Write-Output ("El NAS se ha unido a la red privada: " + (LastLine (Compose exec -T vpn-node tailscale ip -4)))
    }
    'person' {
        PersonName $First
        $existing = (Headscale users list --name $First 2>$null | Out-String)
        if ($existing -match [regex]::Escape($First)) {
            Write-Output "'$First' ya existe en la red privada."
        } else {
            Headscale users create $First | Out-Null
            Write-Output "'$First' creada. Sus dispositivos se aprueban con: make vpn-approve NAME=$First CODE=<codigo>"
        }
    }
    'approve' {
        PersonName $First
        Need 'Codigo' $Second '^[A-Za-z0-9_-]{8,128}$' 'Copia el codigo que muestra la pagina de acceso del dispositivo.'
        Headscale auth register --user $First --auth-id $Second
    }
    'key' {
        PersonName $First
        $json = (Headscale users list --name $First --output json 2>$null | Out-String)
        $id = ''
        if ($json -match '"id"\s*:\s*"?(\d+)') { $id = $Matches[1] }
        Need 'Persona' $id '^\d+$' "Crea primero a '$First' con make vpn-person NAME=$First."
        LastLine (Headscale preauthkeys create --user $id --expiration 1h)
    }
    'devices' { Headscale nodes list }
    'remove' {
        Need 'Dispositivo' $First '^\d+$' 'Usa el numero de la columna ID de make vpn-devices.'
        Headscale nodes delete --identifier $First --force
    }
    'urls' {
        $scheme = if ((Setting 'VPN_TLS' 'none') -eq 'acme-dns') { 'https' } else { 'http' }
        $portal = Setting 'VPN_PORTAL_HOST'
        $music = Setting 'VPN_MUSIC_HOST'
        $reading = Setting 'VPN_READING_HOST'
        $video = Setting 'VPN_VIDEO_HOST'
        Write-Output '# .env del portal (y reinicia con make up):'
        if ($portal) { Write-Output "APP_URL=${scheme}://$portal" }
        Write-Output 'TRUSTED_PROXIES=172.16.0.0/12,192.168.0.0/16,10.0.0.0/8'
        if ($scheme -eq 'https') { Write-Output 'SESSION_SECURE_COOKIE=true' }
        if ($music) { Write-Output "PORTAL_MUSIC_URL=${scheme}://$music" }
        if ($reading) { Write-Output "PORTAL_KOMGA_URL=${scheme}://$reading" }
        if ($video) { Write-Output "PORTAL_VIDEO_URL=${scheme}://$video" }
        if ($music) { Write-Output '# music.env:'; Write-Output "MUSIC_PUBLIC_ORIGIN=${scheme}://$music" }
        if ($video) { Write-Output '# video.env:'; Write-Output "PORTAL_VIDEO_URL=${scheme}://$video" }
    }
    default {
        Write-Output 'Uso: make vpn-up | vpn-join | vpn-person NAME= | vpn-approve NAME= CODE= | vpn-key NAME= | vpn-devices | vpn-remove ID= | vpn-urls'
        exit 2
    }
}
