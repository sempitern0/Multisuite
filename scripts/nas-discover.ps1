# Disk discovery is for the Linux server that runs Multisuite. On Windows with
# Docker Desktop the containers only see Docker's virtual disk, so this script just
# shows the Windows volumes (read-only) and explains what to use instead.
$ErrorActionPreference = 'Stop'
Write-Host 'nas-discover: el descubrimiento de discos se usa en el servidor Linux (make nas-discover alli).'
Write-Host 'Con Docker Desktop el portal solo ve el disco virtual de Docker; usa el NAS de desarrollo (make up).'
Write-Host ''
Write-Host '== Volumenes de Windows (solo informativo) =='
try {
    Get-Volume | Where-Object { $_.DriveLetter } | Sort-Object DriveLetter |
        Format-Table DriveLetter, FileSystemLabel, FileSystem,
            @{ Name = 'Tamano (GB)'; Expression = { [math]::Round($_.Size / 1GB, 1) } },
            @{ Name = 'Libre (GB)'; Expression = { [math]::Round($_.SizeRemaining / 1GB, 1) } } -AutoSize
} catch {
    Write-Host 'No se pudieron leer los volumenes de Windows.'
}
$global:LASTEXITCODE = 0
