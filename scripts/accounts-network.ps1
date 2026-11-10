$ErrorActionPreference = 'Stop'
$network = 'multisuite-accounts'

function Invoke-NetworkCommand {
    param([string[]]$Arguments)
    $previousPreference = $ErrorActionPreference
    try {
        # Windows PowerShell 5.1 wraps redirected native stderr as ErrorRecord.
        # A missing network is expected here; decide from the native exit code.
        $ErrorActionPreference = 'Continue'
        & docker network @Arguments *> $null
        return [int]$LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousPreference
    }
}

if ((Invoke-NetworkCommand -Arguments @('inspect', $network)) -ne 0) {
    if ((Invoke-NetworkCommand -Arguments @('create', $network)) -ne 0) {
        # Another startup may have created it between inspect and create.
        if ((Invoke-NetworkCommand -Arguments @('inspect', $network)) -ne 0) {
            throw "Cannot create shared Docker network $network. Check Docker availability and permissions."
        }
    }
}
# A recovered native failure must not become powershell.exe's exit status.
$global:LASTEXITCODE = 0
