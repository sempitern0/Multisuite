$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
function docker {
    $global:networkTestCalls.Add(($args -join ' '))
    $code = $global:networkTestCodes.Dequeue()
    # Use native stderr to reproduce Windows PowerShell 5.1's ErrorRecord.
    & cmd.exe /d /c "echo diagnostic 1>&2 & exit /b $code"
    $global:LASTEXITCODE = $LASTEXITCODE
}
$cases = @(
    @{ Codes = @(0); Calls = 1; Fails = $false },
    @{ Codes = @(1, 0); Calls = 2; Fails = $false },
    @{ Codes = @(1, 1, 0); Calls = 3; Fails = $false },
    @{ Codes = @(1, 1, 1); Calls = 3; Fails = $true }
)
foreach ($case in $cases) {
    $global:networkTestCodes = New-Object 'System.Collections.Generic.Queue[int]'
    foreach ($code in $case.Codes) { $global:networkTestCodes.Enqueue($code) }
    $global:networkTestCalls = New-Object 'System.Collections.Generic.List[string]'
    $failed = $false
    try { & "$PSScriptRoot/../scripts/accounts-network.ps1" } catch {
        $failed = $true
        if ($_ -notmatch 'Cannot create shared Docker network') { throw }
    }
    if ($failed -ne $case.Fails) { throw 'Unexpected failure outcome' }
    if ($global:networkTestCalls.Count -ne $case.Calls) { throw 'Unexpected Docker call count' }
    if ($global:networkTestCalls[0] -ne 'network inspect multisuite-accounts') { throw 'Missing initial inspection' }
    if ($case.Calls -gt 1 -and $global:networkTestCalls[1] -ne 'network create multisuite-accounts') { throw 'Missing creation' }
    if (-not $failed -and $LASTEXITCODE -ne 0) { throw 'Recovered error leaked into exit status' }
    if ($ErrorActionPreference -ne 'Stop') { throw 'Caller error preference changed' }
}
$global:LASTEXITCODE = 0
Write-Host 'Network creation: existing, missing, concurrent creation and daemon failure passed.'
