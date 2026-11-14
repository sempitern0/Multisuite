# Preparing real disks only makes sense on the Linux server: Docker Desktop cannot
# give containers direct access to Windows drives with POSIX permissions.
$ErrorActionPreference = 'Stop'
Write-Host 'nas-apply: se ejecuta en el servidor Linux (make nas-apply TARGETS="/punto/de/montaje").'
Write-Host 'En Windows el NAS de desarrollo ya se prepara solo con make up.'
exit 2
