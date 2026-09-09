<#
.SYNOPSIS
  Generates _design/MANIFEST.md with a SHA-256 per imported design file.

.DESCRIPTION
  Run after the Claude Design import lands files under _design/.
  A later re-import can then be DIFFED against this manifest rather than
  guessed at: any changed hash is a wireframe that moved under the build.

  Verification gate (all must hold, else the import is rejected):
    - exactly 43 files imported
    - zero files under any excluded prefix (uploads/)
    - every path in import-targets.json is present

.EXAMPLE
  pwsh tools/design-manifest.ps1
#>
[CmdletBinding()]
param(
    [string]$DesignDir = (Join-Path $PSScriptRoot '..\_design'),
    [string]$TargetsFile = (Join-Path $PSScriptRoot '..\_design\import-targets.json')
)

$ErrorActionPreference = 'Stop'

$DesignDir = (Resolve-Path $DesignDir).Path
$targets = Get-Content $TargetsFile -Raw | ConvertFrom-Json

# Build the expected path list from the targets file.
$expected = [System.Collections.Generic.List[string]]::new()
$targets.documents  | ForEach-Object { $expected.Add($_) }
$targets.sharedScript | ForEach-Object { $expected.Add($_) }
foreach ($folder in $targets.wireframes.PSObject.Properties) {
    foreach ($file in $folder.Value) { $expected.Add("$($folder.Name)/$file") }
}

$snapshotRoot = Join-Path $DesignDir 'wireframes'
if (-not (Test-Path $snapshotRoot)) {
    Write-Error "No import found at $snapshotRoot. Run the design import first."
}

$actual = Get-ChildItem -Path $snapshotRoot -Recurse -File |
    ForEach-Object { $_.FullName.Substring($snapshotRoot.Length + 1).Replace('\', '/') }

# --- Gate 1: excluded prefixes must not appear ---------------------------
$violations = @()
foreach ($prefix in $targets.excludedPrefixes) {
    $violations += $actual | Where-Object { $_ -like "$prefix*" }
}
if ($violations.Count -gt 0) {
    Write-Error ("IMPORT REJECTED - excluded files present:`n  " +
        ($violations -join "`n  ") + "`n`n" + $targets.excludeReason)
}

# --- Gate 2: every expected file present ---------------------------------
$missing = $expected | Where-Object { $_ -notin $actual }
if ($missing.Count -gt 0) {
    Write-Error ("IMPORT REJECTED - missing files:`n  " + ($missing -join "`n  "))
}

# --- Gate 3: exact count -------------------------------------------------
if ($actual.Count -ne $targets.expectedFileCount) {
    $unexpected = $actual | Where-Object { $_ -notin $expected }
    Write-Error ("IMPORT REJECTED - expected $($targets.expectedFileCount) files, found $($actual.Count)." +
        $(if ($unexpected) { "`nUnexpected:`n  " + ($unexpected -join "`n  ") } else { '' }))
}

# --- Emit the manifest ---------------------------------------------------
$rows = foreach ($rel in ($expected | Sort-Object)) {
    $full = Join-Path $snapshotRoot ($rel -replace '/', '\')
    $hash = (Get-FileHash -Path $full -Algorithm SHA256).Hash.ToLower()
    $size = (Get-Item $full).Length
    [pscustomobject]@{ Path = $rel; Sha256 = $hash; Bytes = $size }
}

$sb = [System.Text.StringBuilder]::new()
[void]$sb.AppendLine('# Design Import Manifest')
[void]$sb.AppendLine()
[void]$sb.AppendLine("Source project: $($targets.projectUrl)")
[void]$sb.AppendLine("Files: $($rows.Count) (expected $($targets.expectedFileCount))")
[void]$sb.AppendLine("Token source of truth: ``$($targets.tokenSourceOfTruth)``")
[void]$sb.AppendLine()
[void]$sb.AppendLine('`uploads/` is excluded by policy and is NOT part of this manifest.')
[void]$sb.AppendLine($targets.excludeReason)
[void]$sb.AppendLine()
[void]$sb.AppendLine('Regenerate with `pwsh tools/design-manifest.ps1` and diff to detect drift.')
[void]$sb.AppendLine()
[void]$sb.AppendLine('| File | SHA-256 | Bytes |')
[void]$sb.AppendLine('| --- | --- | --- |')
foreach ($r in $rows) {
    [void]$sb.AppendLine("| ``$($r.Path)`` | ``$($r.Sha256)`` | $($r.Bytes) |")
}

$out = Join-Path $DesignDir 'MANIFEST.md'
Set-Content -Path $out -Value $sb.ToString() -Encoding utf8
Write-Output "OK - $($rows.Count) files verified, manifest written to $out"
