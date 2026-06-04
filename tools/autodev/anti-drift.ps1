<#
.SYNOPSIS
  Anti-drift check + digest. The highest-value safeguard: green gate, wrong direction.

.DESCRIPTION
  Every M commits the conductor calls this. It does NOT compare commit titles (too
  shallow to catch real drift). It feeds a Sonnet critic the PHASE INTENT + goals from
  docs-internal/platform-v2-program-tracker.md PLUS the actual DIFFS of recent done/
  tasks, and asks: "does this work advance the phase's stated intent, or has it wandered
  -- satisfied the letter of the tasks while missing their purpose?"

  Produces one strong line for the digest, appends a digest block to .autodev/digest.md,
  and mirrors the operator-facing summary into docs-internal/CURRENT-STATE.md.

  Transport: claude -p --model sonnet (cheap, frequent). If claude is rate-limited or
  unavailable, the anti-drift line records that it could not run (never a false "on-track").

.PARAMETER SinceRef
  Git ref to diff recent work from (default: the previous commit before the autodev run).
.PARAMETER DigestOnly
  Skip the Sonnet call; just emit the digest block (used when anti-drift already ran).
.PARAMETER CommitsSinceLast
  Number of commits this digest covers (for the header).
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
    Push-Location $Config.RepoRoot
    try {
        $log = & git log "$SinceRef..HEAD" --grep='(autodev)' --oneline 2>$null | Out-String
        $diff = & git diff "$SinceRef..HEAD" 2>$null | Out-String
        return [pscustomobject]@{ Log = $log.Trim(); Diff = $diff }
    } finally { Pop-Location }
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
        $driftLine = if ($lm.Success) { $lm.Value.Trim() } else { ($out -split "`n" | Where-Object { $_.Trim() } | Select-Object -Last 1).Trim() }
    } else {
        $driftLine = "UNCERTAIN: anti-drift could not run (claude exit $exit) -- not asserting on-track."
    }
    Write-AutodevLog -Message "Anti-drift result: $driftLine" -Config $Config
}

Write-Output $driftLine
