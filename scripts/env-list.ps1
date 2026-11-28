# Index of environment files (`make env-list`, docs/ENVIRONMENTS.md). POSIX twin: scripts/env-list.sh.
# Prints each template's first line ("# name - purpose") and whether the real file exists; values are never read.
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)
$templates = @('.env.example', 'app/.env.example') + @(Get-ChildItem -File -Filter '*.env.example' | Sort-Object Name | ForEach-Object { $_.Name })
'{0,-22} {1,-10} {2}' -f 'ARCHIVO', 'ESTADO', 'PARA QUE'
foreach ($template in $templates) {
    if (-not (Test-Path -LiteralPath $template)) { continue }
    $file = $template.Substring(0, $template.Length - '.example'.Length)
    $state = if (Test-Path -LiteralPath $file) { 'creado' } else { 'sin crear' }
    $first = [System.IO.File]::ReadLines((Resolve-Path -LiteralPath $template)) | Select-Object -First 1
    $purpose = if ($first -match '^# \S+ \S (.+)$') { $Matches[1] } else { '(sin descripcion)' }
    '{0,-22} {1,-10} {2}' -f $file, $state, $purpose
}
''
'Crear uno: cp ARCHIVO.example ARCHIVO. Cada plantilla explica como se activa.'
