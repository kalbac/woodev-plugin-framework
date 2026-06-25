<#
.SYNOPSIS
  Adversarial critic (codex exec, GPT-5.5 high) over a diff. READ-ONLY repo, fenced
  from the worker's rationale.

.DESCRIPTION
  Runs the heterogeneous (non-Claude) critic described in the runbook section 3.

  POLICY: in 'auto' mode the real critic runs on EVERY non-empty diff -- there is no
  "cheap" rubber-stamp tier. A zone-free small diff can still carry a logic or architecture
  regression that `composer check` alone would miss. The only legitimate cost lever is a
  cheaper Codex tier (set Config.CriticModel = 'gpt-5.3-codex-spark'), never a skip and never
  a Claude model (Claude reviewing Claude is not independent). An empty diff passes through
  (nothing to review). '-Mode none'/'cheap' remain as EXPLICIT caller overrides (e.g. tests).

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
$here = Split-Path -Parent $PSCommandPath
. (Join-Path $here '_common.ps1')
. (Join-Path $here 'watchdog.ps1')   # provides Start-WatchedProcess (clean stdin/stdout/stderr)

$Config = Get-AutodevConfig
$rtDir = Join-Path $Config.Runtime $TaskId
if (-not (Test-Path $rtDir)) { New-Item -ItemType Directory -Path $rtDir -Force | Out-Null }
if (-not $DiffPath) { $DiffPath = Join-Path $rtDir 'diff.patch' }
$verdictPath = Join-Path $rtDir 'verdict.json'

function Write-Verdict {
    param([string]$Verdict, [string]$Notes, [double]$Confidence, [array]$Broken = @(), [string]$DiffSha256 = '')
    $v = [pscustomobject]@{
        verdict = $Verdict; broken_contracts = $Broken; notes = $Notes; confidence = $Confidence
        diff_sha256 = $DiffSha256
    }
    $v | ConvertTo-Json -Depth 6 | Set-Content -Path $verdictPath -Encoding utf8
    $v | ConvertTo-Json -Depth 6
    return $v
}

if (-not (Test-Path $DiffPath)) { throw "Diff not found: $DiffPath" }
$diffText = Get-Content -Path $DiffPath -Raw -Encoding utf8
$diffSha256 = Get-AutodevTextSha256 -Text $diffText
# @() guards the PS gotcha where a function returning an empty array unrolls to $null,
# which then throws PropertyNotFoundStrict on .Count under Set-StrictMode -Version Latest
# (a zone-free, non-contract diff produces zero touched zones -> previously crashed here).
$diffLines = @(Get-GitDiffAddedRemovedLines -DiffText $diffText)
$changedFiles = @()
foreach ($l in ($diffText -split '\r?\n')) {
    if ($l -match '^\+\+\+ b/(.+)$') { $changedFiles += (ConvertTo-NormalizedPath $Matches[1]) }
}

# ---- TIER SELECTION (mechanical) ----
# Operator policy: the heterogeneous (non-Claude) critic runs on EVERY non-empty diff. There
# is NO "cheap" rubber-stamp tier in 'auto' -- a zone-free small diff can still carry a logic
# or architecture regression that only `composer check` would miss, and the only legitimate
# cheaper critic is a cheaper Codex tier (set Config.CriticModel = 'gpt-5.3-codex-spark'),
# never a skip and never a Claude model (that would be Claude reviewing Claude). 'none'/'cheap'
# remain as EXPLICIT caller overrides (e.g. unit tests), but 'auto' never selects them.
$touchedZones = @(Get-AutodevTouchedZoneIds -ChangedFiles $changedFiles -DiffLines $diffLines -Config $Config)
$diffLineCount = $diffLines.Count
$tier = $Mode
if ($Mode -eq 'auto') {
    if ($diffLineCount -eq 0) { $tier = 'none' }   # nothing changed -> nothing to review
    else { $tier = 'expensive' }
}
Write-AutodevLog -Level CRITIC -Message "Tier=$tier (zones touched: $($touchedZones -join ',' ); diff lines: $diffLineCount)" -Config $Config

if ($tier -eq 'none' -or $tier -eq 'cheap') {
    $why = if ($diffLineCount -eq 0) { 'empty diff: nothing to review' } else { "explicit -Mode $Mode pass-through" }
    Write-Verdict -Verdict 'clean' -Confidence 0.5 -DiffSha256 $diffSha256 `
        -Notes "pass-through ($why). Zones touched: $($touchedZones -join ',' ). Diff lines: $diffLineCount." | Out-Null
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

Adapt to what THIS diff actually contains (do NOT assume it is any particular kind of task):
- If it adds/modifies tests that claim to be contract guards: are they asserting the REAL
  contract strings at the canonical source, or a tautology? Is any PRODUCTION code edited to
  match a changed contract string (it must not be)?
- If it adds/modifies production code: does it rename, remove, or alter any installed-site
  contract value (option key, hook name, REST route/namespace, gateway/instance id, cron hook
  or payload shape, order/session meta key, log source, AJAX action, admin slug, DB
  table/schema)? Those must be preserved byte-for-byte.
- A purely additive change that introduces NO contract value and alters none is normally
  clean -- say so; do not invent doubt.

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
$combined = ''
$exit = 1
$combinedFile = Join-Path $rtDir 'critic-output.txt'
$prevEAP = $ErrorActionPreference
try {
    Write-AutodevLog -Level CRITIC -Message "Invoking codex $($Config.CriticModel) ($($Config.CriticEffort)) read-only..." -Config $Config
    # Call codex via the call operator (resolves codex.cmd on PATH). Temporarily relax
    # ErrorActionPreference: in PS 5.1, a native command writing to stderr under 'Stop'
    # raises a terminating error even on exit 0. '*>' captures all streams to a file so we
    # can inspect them without the pipe-redirect pitfalls. The verdict itself comes from -o.
    $ErrorActionPreference = 'Continue'
    if (Test-Path $outFile) { Remove-Item $outFile -Force }
    $prompt | & $Config.CodexExe exec `
        -m $Config.CriticModel `
        -c "model_reasoning_effort=`"$($Config.CriticEffort)`"" `
        -c 'approval_policy="never"' `
        -s read-only `
        -C $Config.RepoRoot `
        --skip-git-repo-check `
        --output-schema $schemaFile `
        -o $outFile `
        - *> $combinedFile
    $exit = $LASTEXITCODE
    if (Test-Path $combinedFile) { $combined = Get-Content $combinedFile -Raw }
    # Fallback: if -o was not written, recover the final JSON object from combined output.
    if (-not (Test-Path $outFile) -and $combined) {
        $mm = [regex]::Match($combined, '(?s)\{[^{}]*"verdict".*?\}')
        if ($mm.Success) { Set-Content -Path $outFile -Value $mm.Value -Encoding utf8 }
    }
}
catch {
    $combined = $_.Exception.Message
    $exit = 1
}
finally {
    $ErrorActionPreference = $prevEAP
    foreach ($pair in $fenced) { Move-Item -Path $pair[1] -Destination $pair[0] -Force }
}
$stderr = $combined

# Parse the structured final message FIRST. A successfully parsed verdict means codex
# completed, so it is authoritative and MUST win over any rate-limit heuristic.
# Bug fix 2026-06-07: the old code ran Test-RateLimited over the ENTIRE combined codex
# output with a HARD-CODED non-zero exit code, so a benign rate-limit word the critic
# merely READ from a repo doc (e.g. CURRENT-STATE.md / digest / gotchas, which describe
# the earlier critic-429 fix) falsely classified a real, completed verdict as a 429 --
# discarding it (exit 4) and re-queueing the task endlessly. Now the parsed verdict wins;
# the rate-limit branch is reached ONLY when codex returned NO usable verdict, and it
# uses codex's REAL exit code (a clean exit 0 is never a rate-limit).
$parsed = $null
if (Test-Path $outFile) {
    $raw = Get-Content $outFile -Raw -Encoding utf8
    # Be tolerant: extract the first {...} JSON object if extra text surrounds it.
    $m = [regex]::Match($raw, '(?s)\{.*\}')
    if ($m.Success) { try { $parsed = $m.Value | ConvertFrom-Json } catch { $parsed = $null } }
}

if ($null -eq $parsed -or -not ($parsed.PSObject.Properties.Name -contains 'verdict')) {
    # No usable verdict -> this is the ONLY place a rate-limit can be declared. Use the REAL
    # codex exit code; a benign rate-limit word in an already-parsed verdict cannot reach here.
    if (Test-RateLimited -ExitCode $exit -Stderr $combined) {
        Write-AutodevLog -Level CRITIC -Message "Critic rate-limited (no verdict, exit $exit); caller should back off." -Config $Config
        Write-Verdict -Verdict 'uncertain' -Confidence 0.0 -DiffSha256 $diffSha256 -Notes "critic rate-limited: $stderr" | Out-Null
        exit 4
    }
    Write-AutodevLog -Level CRITIC -Message "Critic produced no parseable verdict (exit $exit). Routing to human." -Config $Config
    Write-Verdict -Verdict 'uncertain' -Confidence 0.0 -DiffSha256 $diffSha256 `
        -Notes "no parseable verdict from codex (exit $exit). stderr: $stderr" | Out-Null
    exit 3
}

$broken = @()
if ($parsed.PSObject.Properties.Name -contains 'broken_contracts' -and $parsed.broken_contracts) {
    $broken = $parsed.broken_contracts
}
$notes = if ($parsed.PSObject.Properties.Name -contains 'notes') { $parsed.notes } else { '' }
$conf = if ($parsed.PSObject.Properties.Name -contains 'confidence') { [double]$parsed.confidence } else { 0.0 }
Write-Verdict -Verdict $parsed.verdict -Confidence $conf -Notes $notes -Broken $broken -DiffSha256 $diffSha256 | Out-Null

switch ($parsed.verdict) {
    'clean' { exit 0 }
    default { exit 3 }   # broken | uncertain -> human
}
