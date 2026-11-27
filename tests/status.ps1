$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$statusScript = (Resolve-Path (Join-Path $PSScriptRoot '../scripts/status.ps1')).Path
$tokens = $null
$parseErrors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($statusScript, [ref]$tokens, [ref]$parseErrors)
if ($parseErrors.Count -gt 0) { throw ($parseErrors | Out-String) }
$paramBlock = $ast.ParamBlock.Extent.Text
if ($paramBlock -match 'PSScriptRoot') { throw 'Status script must not use PSScriptRoot in a parameter default.' }
$temp = Join-Path ([System.IO.Path]::GetTempPath()) ('foundry-status-' + [Guid]::NewGuid().ToString('N'))
try {
  [void](New-Item -ItemType Directory -Path $temp)
  $settings = @('PROJECT_NAME="videoapp"', 'APP_LABEL="VideoApp"', 'DB_ENGINE="mariadb"', 'DB_DATABASE="foundry"', 'QUEUE_ENGINE="database"', 'MAIL_ENGINE="mailpit"', 'DB_UI="phpmyadmin"', 'HTTPS_ENABLED="1"', 'LOCAL_DOMAIN="videoapp.test"', 'DB_PASSWORD="DO-NOT-PRINT-THIS"')
  [System.IO.File]::WriteAllLines((Join-Path $temp '.env'), $settings)
  [System.IO.File]::WriteAllLines((Join-Path $temp 'music.env'), @('NAVIDROME_PORT=14533'))
  [System.IO.File]::WriteAllLines((Join-Path $temp 'video.env'), @('VIDEO_PORT=18096', 'VIDEO_HARDWARE=nvidia'))
  function docker {
    $global:LASTEXITCODE = 0
    $joined = $args -join ' '
    if ($args[0] -eq 'ps') {
      # Music and Video gateways running; the Komga stack exists but is stopped.
      if ($joined -match 'multisuite-music' -and $joined -match 'music-gateway' -and $joined -match 'status=running') { return 'm1' }
      if ($joined -match 'multisuite-video' -and $joined -match 'video-gateway' -and $joined -match 'status=running') { return 'v1' }
      if ($joined -match 'multisuite-komga' -and $joined -match '-aq') { return 'k1' }
      return
    }
    @('app','web','caddy-local','mariadb','mailpit','phpmyadmin','worker')
  }
  $output = (& $statusScript -Root $temp 6>&1 | Out-String)
  if ($output -notmatch 'https://videoapp.test') { throw 'HTTPS URL missing from status output' }
  if ($output -notmatch 'Primary app') { throw 'Primary URL not highlighted' }
  if ($output -match 'DO-NOT-PRINT-THIS') { throw 'Status leaked a credential' }
  if ($output -notmatch 'Music \(Navidrome\)\s+http://localhost:14533') { throw 'Music URL missing' }
  if ($output -notmatch 'Video \(Jellyfin\)\s+http://localhost:18096') { throw 'Video URL missing' }
  if ($output -notmatch 'Video GPU\s+nvidia') { throw 'Video GPU missing' }
  if ($output -notmatch 'Reading \(Komga\)\s+OFF\s+stopped\s+\(make komga-up\)') { throw 'Stopped Komga not shown' }
  if ($output -match 'Kavita') { throw 'Unconfigured Kavita shown' }
  # After make down Komga still appears (Make starts it with the development NAS); disabled Kavita is never offered.
  [System.IO.File]::WriteAllLines((Join-Path $temp 'reading.env'), @('KAVITA_PORT=15000'))
  function docker {
    $global:LASTEXITCODE = 0
    if ($args[0] -eq 'ps') { return }
    @('app','web')
  }
  $down = (& $statusScript -Root $temp 6>&1 | Out-String)
  if ($down -notmatch 'Reading \(Komga\)\s+OFF\s+stopped\s+\(make komga-up\)') { throw 'Komga missing after make down' }
  if ($down -notmatch 'Reading \(Kavita\)\s+disabled') { throw 'Disabled Kavita not explained' }
  if ($down -match 'make reading-up') { throw 'Suggested starting disabled Kavita' }
  [void](New-Item -ItemType File -Path (Join-Path $temp 'compose.nas.yaml'))
  $real = (& $statusScript -Root $temp 6>&1 | Out-String)
  if ($real -match 'Reading \(Komga\)') { throw 'Komga shown with real disks and no containers' }
  Write-Host 'PASS Windows status script parameter binding and GUI output'
} finally { if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Recurse -Force } }
