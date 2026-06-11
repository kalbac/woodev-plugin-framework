<#
.SYNOPSIS
  Anti-drift check. Feeds recent diffs to a Sonnet critic and appends one verdict line to digest.md.

.DESCRIPTION
  Every M commits the conductor calls this. It does NOT compare commit titles (too
  shallow to catch real drift). It feeds a Sonnet critic the PHASE INTENT + goals from
  docs-internal/platform-v2-program-tracker.md PLUS the actual DIFFS of recent done/
  tasks, and asks: "does this work advance the phase's stated intent, or has it wandered
  -- satisfied the letter of the tasks while missing their purpose?"

  Appends one timestamped verdict line (ON-TRACK/DRIFT/UNCERTAIN) to .autodev/digest.md.

  Transport: claude -p --model sonnet (cheap, frequent). If claude is rate-limited or
  unavailable, the anti-drift line records that it could not run (never a false "on-track").

.PARAMETER SinceRef
  Git ref to diff recent work from (default: the previous commit before the autodev run).
.PARAMETER DigestOnly
  Skip the Sonnet call; just emit the digest block (used when anti-drift already ran).
.PARAMETER CommitsSinceLast
  Number of commits this digest covers; included in the digest line as '(window: N commits)'.
#>
[CmdletBinding()]
param(
    [string]$SinceRef = 'HEAD~3',
    [switch]$DigestOnly,
    [int]$CommitsSinceLast = 1
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $PSCommandPath
. (Join-Path $here '_common.ps1')
$Config = Get-AutodevConfig

function Get-TrackerIntent {
    param([pscustomobject]$Config)
    if (-not (Test-Path $Config.Tracker)) { return '(tracker not found)' }
    $text = Get-Content $Config.Tracker -Raw -Encoding utf8
    # Grab the "Next action" section + the Stage map -- the phase intent, not titles.
    $m = [regex]::Match($text, '(?s)##\s*Next action(.*?)(\r?\n##\s)')
    $intent = if ($m.Success) { $m.Groups[1].Value.Trim() } else { '' }
    $m2 = [regex]::Match($text, '(?s)(##\s*Stage map.*?)(\r?\n##\s)')
    if ($m2.Success) { $intent += "`n`n" + $m2.Groups[1].Value.Trim() }
    return $intent
}

function Get-RecentDoneDiffs {
    param([string]$SinceRef, [pscustomobject]$Config)
    $rLog  = Invoke-Native -Exe 'git' -CommandArgs @('log', "$SinceRef..HEAD", '--grep=(autodev)', '--oneline') -WorkingDirectory $Config.RepoRoot
    $rDiff = Invoke-Native -Exe 'git' -CommandArgs @('diff', "$SinceRef..HEAD") -WorkingDirectory $Config.RepoRoot
    return [pscustomobject]@{ Log = ($rLog.Output | Out-String).Trim(); Diff = ($rDiff.Output | Out-String) }
}

$driftLine = ''
if (-not $DigestOnly) {
    $intent = Get-TrackerIntent -Config $Config
    $recent = Get-RecentDoneDiffs -SinceRef $SinceRef -Config $Config
    $prompt = @"
You are an anti-drift reviewer. You are given (1) the PHASE INTENT of an in-flight program,
and (2) the actual code DIFFS of the most recent completed tasks. Judge ONE thing: does the
work advance the phase's STATED INTENT, or has it wandered -- satisfied the letter of the
tasks while missing their purpose? Do NOT judge by commit titles; judge by the diffs.

Answer in EXACTLY one line, starting with one of: ON-TRACK: | DRIFT: | UNCERTAIN:
followed by a single sentence of justification grounded in the diffs vs the intent.

===== PHASE INTENT (from platform-v2-program-tracker.md) =====
$intent

===== RECENT DONE-TASK COMMITS =====
$($recent.Log)

===== RECENT DONE-TASK DIFFS =====
$($recent.Diff)
"@

    $combinedFile = Join-Path $Config.Runtime 'antidrift-output.txt'
    $rtParent = Split-Path -Parent $combinedFile
    if (-not (Test-Path $rtParent)) { New-Item -ItemType Directory -Path $rtParent -Force | Out-Null }
    $prevEAP = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        Write-AutodevLog -Message "Anti-drift: invoking claude -p --model $($Config.AntiDriftModel) ..." -Config $Config
        $out = ($prompt | & $Config.ClaudeExe -p --model $Config.AntiDriftModel 2>&1 | Out-String)
        $exit = $LASTEXITCODE
    } catch { $out = $_.Exception.Message; $exit = 1 } finally { $ErrorActionPreference = $prevEAP }

    if ($exit -eq 0 -and $out) {
        $lm = [regex]::Match($out, '(?im)^\s*(ON-TRACK|DRIFT|UNCERTAIN):.*$')
        if ($lm.Success) {
            $driftLine = $lm.Value.Trim()
        } else {
            # Model output has no recognized prefix -- degrade to UNCERTAIN rather than recording
            # an unstructured line that would be invisible to any downstream grep/parser.
            $firstChars = ($out -replace '[\r\n]+',' ').Trim()
            if ($firstChars.Length -gt 120) { $firstChars = $firstChars.Substring(0, 120) }
            $driftLine = "UNCERTAIN: model output had no ON-TRACK/DRIFT/UNCERTAIN prefix -- $firstChars"
        }
    } else {
        $driftLine = "UNCERTAIN: anti-drift could not run (claude exit $exit) -- not asserting on-track."
    }
    Write-AutodevLog -Message "Anti-drift result: $driftLine" -Config $Config
} else {
    # -DigestOnly: emit/append the digest line without the Sonnet call.
    $driftLine = "UNCERTAIN: -DigestOnly requested -- Sonnet not invoked."
}

# Append one-line verdict to digest (timestamped). Format:
#   [yyyy-MM-dd HH:mm:ss] [anti-drift] (window: N commits) <verdict line>
$stamp = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
$digestEntry = "[$stamp] [anti-drift] (window: $CommitsSinceLast commits) $driftLine"
try {
    Add-Content -Path $Config.Digest -Value $digestEntry -Encoding utf8
} catch {
    Write-AutodevLog -Level WARN -Message "Anti-drift: could not write to digest ($($Config.Digest)): $($_.Exception.Message)" -Config $Config
}
Write-Output $driftLine
