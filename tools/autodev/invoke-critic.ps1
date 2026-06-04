<#
.SYNOPSIS
  Adversarial critic (codex exec, GPT-5.5 high) over a diff. READ-ONLY repo, fenced
  from the worker's rationale. Tiered by a mechanical signal.

.DESCRIPTION
  Runs the heterogeneous (non-Claude) critic described in the runbook section 3.

  TIERING (mechanical, never an LLM pre-call): the expensive GPT-5.5 critic runs only
  when the diff TOUCHES A CONTRACT ZONE or the diff is LARGER THAN a line threshold.
  Otherwise the critic is "cheap" -> here that means machine-gate-only (a pass-through
  verdict), because the gate still runs afterward and a zone-free small diff carries
  little contract risk. This keeps the cost where the risk is.

  FENCING (mechanical, not just instruction): before invoking codex, the worker's
  rationale files (worker-report.md and the commit message) are physically moved OUT of
  the repo tree, and restored in a finally block. Combined with codex's `-s read-only`
  sandbox, the critic gets repo FACTS (grep/Serena) but cannot read the author's
  justification and cannot write anything. Anchoring on the worker's reasoning is the
  one thing the critic must avoid.

  The diff under review is passed INLINE in the prompt; the critic does not need the
  runtime scratch dir for it.

.PARAMETER TaskId
  Task id (locates runtime dir).

.PARAMETER DiffPath
  Path to the unified diff to review. Default: runtime/<TaskId>/diff.patch.

.PARAMETER Mode
  auto (default) | expensive | cheap | none. 'auto' applies the mechanical tiering.

.OUTPUTS
  Writes runtime/<TaskId>/verdict.json and prints it. Exit code:
   0 = clean, 3 = broken/uncertain (route to human), 4 = rate-limited (caller backs off).
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$TaskId,
    [string]$DiffPath,
    [ValidateSet('auto', 'expensive', 'cheap', 'none')][string]$Mode = 'auto'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path (Split-Path -Parent $PSCommandPath) '_common.ps1')

$Config = Get-AutodevConfig
$rtDir = Join-Path $Config.Runtime $TaskId
if (-not (Test-Path $rtDir)) { New-Item -ItemType Directory -Path $rtDir -Force | Out-Null }
if (-not $DiffPath) { $DiffPath = Join-Path $rtDir 'diff.patch' }
$verdictPath = Join-Path $rtDir 'verdict.json'

function Write-Verdict {
    param([string]$Verdict, [string]$Notes, [double]$Confidence, [array]$Broken = @())
    $v = [pscustomobject]@{
        verdict = $Verdict; broken_contracts = $Broken; notes = $Notes; confidence = $Confidence
    }
    $v | ConvertTo-Json -Depth 6 | Set-Content -Path $verdictPath -Encoding utf8
    $v | ConvertTo-Json -Depth 6
    return $v
}

if (-not (Test-Path $DiffPath)) { throw "Diff not found: $DiffPath" }
$diffText = Get-Content -Path $DiffPath -Raw -Encoding utf8
$diffLines = Get-GitDiffAddedRemovedLines -DiffText $diffText
$changedFiles = @()
foreach ($l in ($diffText -split '\r?\n')) {
    if ($l -match '^\+\+\+ b/(.+)$') { $changedFiles += (ConvertTo-NormalizedPath $Matches[1]) }
}

# ---- TIERING (mechanical) ----
$touchedZones = Get-AutodevTouchedZoneIds -ChangedFiles $changedFiles -DiffLines $diffLines -Config $Config
$diffLineCount = $diffLines.Count
$tier = $Mode
if ($Mode -eq 'auto') {
    if ($touchedZones.Count -gt 0 -or $diffLineCount -gt $Config.CriticDiffLineThreshold) { $tier = 'expensive' }
    else { $tier = 'cheap' }
}
Write-AutodevLog -Level CRITIC -Message "Tier=$tier (zones touched: $($touchedZones -join ',' ); diff lines: $diffLineCount)" -Config $Config

if ($tier -eq 'none' -or $tier -eq 'cheap') {
    Write-Verdict -Verdict 'clean' -Confidence 0.5 `
        -Notes "cheap tier (zone-free, small diff): machine-gate-only. Zones touched: none. Diff lines: $diffLineCount." | Out-Null
    Get-Content $verdictPath -Raw
    exit 0
}

# ---- EXPENSIVE CRITIC (codex GPT-5.5 high, read-only, fenced) ----

# Fence: move worker rationale out of the repo tree for the duration of the call.
$fenced = @()
$workerReport = Join-Path $rtDir 'worker-report.md'
if (Test-Path $workerReport) {
    $stash = Join-Path ([System.IO.Path]::GetTempPath()) ("autodev-fenced-" + [guid]::NewGuid().ToString('N') + '.md')
    Move-Item -Path $workerReport -Destination $stash
    $fenced += , @($workerReport, $stash)
}

$schemaFile = Join-Path (Split-Path -Parent $PSCommandPath) 'critic-verdict.schema.json'
$outFile = Join-Path $rtDir 'critic-last-message.json'

$prompt = @"
You are an adversarial verifier. Default assumption: this diff BREAKS an installed-site
data contract. Your job is to prove it does, citing a specific file and line. Only if you
genuinely cannot, return "clean". You are NOT the author; you have no stake in this passing.

You have READ-ONLY access to the repository at $($Config.RepoRoot). USE IT (grep / ripgrep
/ read files). A contract break is often invisible in the patch itself: a removed method
whose NAME is a contract, an external string/hook reference, a load-order regression.
Reason about the WHOLE repo, not only the changed lines.

FENCED: do NOT attempt to read .autodev/runtime/**/worker-report.md or the worker's commit
message. Repo access is for facts, never for the author's justification.

INVARIANTS (the never-break contract zones) live in: .autodev/INVARIANTS.md
GUARDS (already-blessed guards) live in:            .autodev/GUARDS.md

Check, in order:
1. Does the diff touch any contract zone in INVARIANTS? List each touch.
2. For each touch: is there a guard in GUARDS asserting THAT contract? A renamed/removed
   option key, hook, REST route, gateway/instance id, cron hook, meta key, log source,
   AJAX action, admin slug, or DB schema with no guard => BROKEN.
3. Is any test in the diff modified to MATCH a changed contract (fabricated proof)? That
   is BROKEN, not a fix.
4. Logic/regression risk independent of contracts.

This particular diff claims to ADD mutation-verified contract guards (tests only) plus a
GUARDS.md registry row. Scrutinize specifically: are the guards asserting the REAL contract
strings ('edostavka', 'woocommerce_edostavka_settings'), or a tautology? Is any PRODUCTION
code edited to match a changed string (it must not be)?

Return ONLY the JSON verdict conforming to the provided schema:
{ "verdict": "clean" | "broken" | "uncertain",
  "broken_contracts": [ { "zone": "...", "file": "...", "line": N, "evidence": "..." } ],
  "notes": "...", "confidence": 0.0-1.0 }
When in doubt -> "uncertain" (routes to a human, never a silent pass).

===== DIFF UNDER REVIEW =====
$diffText
===== END DIFF =====
"@

$rateLimited = $false
$stderr = ''
try {
    Write-AutodevLog -Level CRITIC -Message "Invoking codex $($Config.CriticModel) ($($Config.CriticEffort)) read-only..." -Config $Config
    $errFile = Join-Path $rtDir 'critic-stderr.txt'
    $prompt | & $Config.CodexExe exec `
        -m $Config.CriticModel `
        -c "model_reasoning_effort=`"$($Config.CriticEffort)`"" `
        -c 'approval_policy="never"' `
        -s read-only `
        -C $Config.RepoRoot `
        --skip-git-repo-check `
        --output-schema $schemaFile `
        -o $outFile `
        - 2> $errFile | Out-Null
    $exit = $LASTEXITCODE
    if (Test-Path $errFile) { $stderr = Get-Content $errFile -Raw }
    $rateLimited = Test-RateLimited -ExitCode $exit -Stderr $stderr
}
catch {
    $stderr = $_.Exception.Message
    $exit = 1
}
finally {
    foreach ($pair in $fenced) { Move-Item -Path $pair[1] -Destination $pair[0] -Force }
}

if ($rateLimited) {
    Write-AutodevLog -Level CRITIC -Message "Critic rate-limited; caller should back off." -Config $Config
    Write-Verdict -Verdict 'uncertain' -Confidence 0.0 -Notes "critic rate-limited: $stderr" | Out-Null
    exit 4
}

# Parse the structured final message.
$parsed = $null
if (Test-Path $outFile) {
    $raw = Get-Content $outFile -Raw -Encoding utf8
    # Be tolerant: extract the first {...} JSON object if extra text surrounds it.
    $m = [regex]::Match($raw, '(?s)\{.*\}')
    if ($m.Success) { try { $parsed = $m.Value | ConvertFrom-Json } catch { $parsed = $null } }
}

if ($null -eq $parsed -or -not ($parsed.PSObject.Properties.Name -contains 'verdict')) {
    Write-AutodevLog -Level CRITIC -Message "Critic produced no parseable verdict (exit $exit). Routing to human." -Config $Config
    Write-Verdict -Verdict 'uncertain' -Confidence 0.0 `
        -Notes "no parseable verdict from codex (exit $exit). stderr: $stderr" | Out-Null
    exit 3
}

$broken = @()
if ($parsed.PSObject.Properties.Name -contains 'broken_contracts' -and $parsed.broken_contracts) {
    $broken = $parsed.broken_contracts
}
$notes = if ($parsed.PSObject.Properties.Name -contains 'notes') { $parsed.notes } else { '' }
$conf = if ($parsed.PSObject.Properties.Name -contains 'confidence') { [double]$parsed.confidence } else { 0.0 }
Write-Verdict -Verdict $parsed.verdict -Confidence $conf -Notes $notes -Broken $broken | Out-Null

switch ($parsed.verdict) {
    'clean' { exit 0 }
    default { exit 3 }   # broken | uncertain -> human
}
