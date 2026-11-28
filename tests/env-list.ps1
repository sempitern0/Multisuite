# Windows twin of tests/env-list.sh: `make env-list` reports which environment files exist,
# with each template's purpose, and never prints values.
$ErrorActionPreference = 'Stop'
$source = Split-Path -Parent $PSScriptRoot
$temp = Join-Path ([System.IO.Path]::GetTempPath()) ('env-list-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path (Join-Path $temp 'scripts'), (Join-Path $temp 'app') | Out-Null
try {
  Copy-Item -LiteralPath (Join-Path $source 'scripts/env-list.ps1') -Destination (Join-Path $temp 'scripts')
  foreach ($name in @('.env.example', 'music.env.example', 'video.env.example')) { Copy-Item -LiteralPath (Join-Path $source $name) -Destination $temp }
  Copy-Item -LiteralPath (Join-Path $source 'app/.env.example') -Destination (Join-Path $temp 'app')
  [System.IO.File]::WriteAllLines((Join-Path $temp 'music.env'), @('NAVIDROME_PORT=4533', 'SECRET_TOKEN=do-not-print'))
  $out = (& (Join-Path $temp 'scripts/env-list.ps1') | Out-String)
  if ($out -notmatch '(?m)^music\.env\s+creado\s+M') { throw "music.env not reported as created: $out" }
  if ($out -notmatch '(?m)^video\.env\s+sin crear') { throw "video.env not reported as missing: $out" }
  if ($out -notmatch '(?m)^app/\.env\s+sin crear') { throw "app/.env not reported: $out" }
  if ($out -match 'do-not-print') { throw 'env-list printed a value' }
  Write-Host 'PASS Windows env-list reports files without values'
} finally {
  if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Recurse -Force }
}
