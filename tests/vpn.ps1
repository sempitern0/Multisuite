# Windows twin of the private network commands: validation and printed addresses (no Docker needed;
# the full flow runs against real containers in tests/vpn-smoke.py).
$ErrorActionPreference = 'Stop'
$temp = Join-Path ([System.IO.Path]::GetTempPath()) ('vpn-ps-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temp | Out-Null
try {
  $envFile = Join-Path $temp 'vpn.env'
  [System.IO.File]::WriteAllLines($envFile, @('VPN_TLS=acme-dns', 'VPN_PORTAL_HOST=multisuite.example.org', 'VPN_VIDEO_HOST=video.example.org', 'VPN_MUSIC_HOST='))
  $env:VPN_ENV_FILE = $envFile
  $script = Join-Path (Split-Path -Parent $PSScriptRoot) 'scripts/vpn.ps1'

  $urls = (& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $script urls | Out-String)
  if ($LASTEXITCODE -ne 0) { throw "urls failed: $urls" }
  foreach ($expected in @('APP_URL=https://multisuite.example.org', 'SESSION_SECURE_COOKIE=true', 'PORTAL_VIDEO_URL=https://video.example.org', 'TRUSTED_PROXIES=')) {
    if ($urls -notmatch [regex]::Escape($expected)) { throw "Missing $expected in: $urls" }
  }
  if ($urls -match 'PORTAL_MUSIC_URL') { throw 'An empty private name must be skipped' }

  # Make delegates every stack command here: compose arguments, flags included, pass through intact.
  $full = Join-Path $temp 'full.env'
  Copy-Item -LiteralPath (Join-Path (Split-Path -Parent $PSScriptRoot) 'vpn.env.example') -Destination $full
  $env:VPN_ENV_FILE = $full
  & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $script compose config --quiet
  if ($LASTEXITCODE -ne 0) { throw "compose passthrough failed ($LASTEXITCODE)" }
  $services = (& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $script compose config --services | Out-String)
  foreach ($service in @('headscale', 'vpn-node', 'vpn-entry')) {
    if ($services -notmatch $service) { throw "compose config missing $service" }
  }
  $env:VPN_ENV_FILE = $envFile

  & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $script person 'Ana Lopez' 2>$null | Out-Null
  if ($LASTEXITCODE -ne 2) { throw "Invalid person name must exit 2, got $LASTEXITCODE" }
  & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $script approve ana 'x; rm' 2>$null | Out-Null
  if ($LASTEXITCODE -ne 2) { throw "Invalid code must exit 2, got $LASTEXITCODE" }
  & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $script remove abc 2>$null | Out-Null
  if ($LASTEXITCODE -ne 2) { throw "Invalid device id must exit 2, got $LASTEXITCODE" }
  Write-Host 'PASS Windows private network commands validate input and print addresses'
  # The refused commands above leave exit code 2 behind: this test itself passed.
  $global:LASTEXITCODE = 0
} finally {
  Remove-Item Env:VPN_ENV_FILE -ErrorAction SilentlyContinue
  if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Recurse -Force }
}
