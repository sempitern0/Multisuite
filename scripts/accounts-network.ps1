$ErrorActionPreference = 'Stop'
$network = 'multisuite-accounts'
& docker network inspect $network *> $null
if ($LASTEXITCODE -ne 0) {
    & docker network create $network
    if ($LASTEXITCODE -ne 0) {
        & docker network inspect $network *> $null
        if ($LASTEXITCODE -ne 0) { throw "Cannot create shared Docker network $network" }
    }
}
