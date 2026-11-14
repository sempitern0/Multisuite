# The backup reads the server's disks and Docker volumes directly, so it only makes
# sense on the Linux server that runs Multisuite.
$ErrorActionPreference = 'Stop'
Write-Host 'nas-backup: se ejecuta en el servidor Linux (make nas-backup).'
Write-Host 'En Windows, make backup guarda un volcado local de la base de datos de desarrollo.'
exit 2
