# Detects the GPU Jellyfin can use and records it in video.env (VIDEO_HARDWARE, VIDEO_RENDER_GID).
# Docker Desktop only passes NVIDIA GPUs (WSL 2); Intel/AMD fall back to the CPU (none).
# $env:HW = intel|amd|nvidia|none forces a choice.
$ErrorActionPreference = 'Stop'
$envFile = if ($env:VIDEO_ENV_FILE) { $env:VIDEO_ENV_FILE } else { 'video.env' }
if (-not (Test-Path $envFile)) { Copy-Item 'video.env.example' $envFile }

$hardware = 'none'
if ($env:HW) {
    $hardware = $env:HW
} elseif (Get-Command nvidia-smi -ErrorAction SilentlyContinue) {
    & nvidia-smi -L *> $null
    if ($LASTEXITCODE -eq 0) { $hardware = 'nvidia' }
}
if ($hardware -notin @('intel', 'amd', 'nvidia', 'none')) {
    Write-Error 'HW debe ser intel, amd, nvidia o none'
    exit 2
}

$lines = [System.Collections.Generic.List[string]]::new()
$seenHw = $false
$seenGid = $false
foreach ($line in Get-Content $envFile) {
    if ($line -match '^VIDEO_HARDWARE=') { $lines.Add("VIDEO_HARDWARE=$hardware"); $seenHw = $true; continue }
    if ($line -match '^VIDEO_RENDER_GID=') { $lines.Add($line); $seenGid = $true; continue }
    $lines.Add($line)
}
if (-not $seenHw) { $lines.Add("VIDEO_HARDWARE=$hardware") }
if (-not $seenGid) { $lines.Add('VIDEO_RENDER_GID=109') }
# LF endings: the file is also read by Docker Compose and the POSIX scripts.
$fullPath = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($envFile)
[System.IO.File]::WriteAllText($fullPath, (($lines -join "`n") + "`n"))

switch ($hardware) {
    'intel' { Write-Output 'Video: GPU Intel forzada (Quick Sync / VA-API).' }
    'amd' { Write-Output 'Video: GPU AMD forzada (VA-API).' }
    'nvidia' { Write-Output 'Video: GPU NVIDIA detectada (NVENC con WSL 2).' }
    'none' { Write-Output 'Video: sin GPU utilizable; Jellyfin convertira con el procesador.' }
}
Write-Output "Guardado en $envFile. Reinicia Video con: make video-up"
