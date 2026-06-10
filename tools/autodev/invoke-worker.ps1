<#
.SYNOPSIS
  Run a disposable worker (claude -p) on one task, with model ladder + watchdog.

.DESCRIPTION
  Spawns a fresh `claude -p` worker for a single task in the repository working tree
  (serialized by file_set disjointness -- no per-task git worktrees). Implements:
    - MODEL LADDER: starts at the task-declared tier when present (opus -> sonnet -> haiku
      for 'opus', sonnet -> haiku for 'sonnet', haiku only for 'haiku'); full ladder when
      no model is declared. Rate-limit step-downs only go to cheaper tiers.
    - CONTRACT-ZONE PIN: a task that touches a contract zone is pinned to Opus regardless
      of any declared model. On a rate-limit it PAUSES (returns rate_limited) -- it is
      NEVER silently downgraded to a weaker model. A license-zone edit must not land on
      Haiku just because Opus is busy.
    - WATCHDOG: the worker touches runtime/<id>/heartbeat; a stale heartbeat kills the
      hung process so the conductor can respawn (attempts++).
    - RATE-LIMIT DETECTION: 429/quota in exit code or stderr steps down the ladder
      (ordinary tasks) or pauses (contract-zone tasks).

  Returns: @{ Status; Model; RateLimited; TimedOut; ExitCode }.
  Status in DONE-ish (the conductor then reads runtime/<id>/worker-report.md for the
  authoritative worker status) | RATE_LIMITED | TIMED_OUT | ERROR.

.PARAMETER Model
  Optional model declared in the task frontmatter (haiku|sonnet|opus). When set, the
  ladder starts at that tier and steps down only to cheaper models on rate-limits.
  Ignored (with a WARN) if -TouchesContractZone is set and the declared model is weaker
  than opus. Unknown values fall back to the full ladder with a WARN.

.PARAMETER DryRun
  Build and print the ladder + claude command(s) without spawning claude. Used to verify
  command construction and the contract-zone Opus pin in this bootstrap session.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$TaskId,
    [string]$Worktree,
    [string]$Model,
    [switch]$TouchesContractZone,
    [switch]$DryRun
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $PSCommandPath
. (Join-Path $here '_common.ps1')
. (Join-Path $here 'watchdog.ps1')   # provides Start-WatchedProcess

$Config = Get-AutodevConfig
if (-not $Worktree) { $Worktree = $Config.RepoRoot }
$rtDir = Join-Path $Config.Runtime $TaskId
if (-not (Test-Path $rtDir)) { New-Item -ItemType Directory -Path $rtDir -Force | Out-Null }
$heartbeat = Join-Path $rtDir 'heartbeat'
$taskFile = Join-Path $Config.QueueActive "$TaskId.md"

function Build-WorkerPrompt {
    param([string]$TaskId, [string]$Worktree)
    $taskBody = if (Test-Path $taskFile) { Get-Content $taskFile -Raw -Encoding utf8 } else { "(task file missing)" }
    return @"
You are a disposable worker. You do ONE task, then you die. Your memory is the
blackboard on disk, not your context.
Use Serena tools for PHP if they are available in your session; otherwise use Grep/Read.

TASK: $TaskId  (full spec below, also at .autodev/queue/active/$TaskId.md)
ANCHOR: read .autodev/GOAL.md before anything. Do not exceed the task scope.
INVARIANTS: read .autodev/INVARIANTS.md. You MUST NOT break any contract zone.

Rules:
- Work in the repository working tree (serialized by file_set disjointness): $Worktree
  Touch ONLY files the task names in file_set.
- Make the smallest change that completes the task.
- If the task needs >1 logical change, STOP: write worker-report.md status=TOO_BIG with a
  proposed decomposition. Do not code.
- If completing it REQUIRES touching a contract zone with no blessed guard in GUARDS.md,
  STOP: status=NEEDS_GUARD. Do not code.

Output to .autodev/runtime/$TaskId/ :
- the change WRITTEN TO DISK but NOT committed. Do NOT run ``git commit`` or ``git add``;
  do NOT push, do NOT touch main. The conductor stages + commits your file_set ONLY after
  the gate passes -- committing yourself would land UNVERIFIED code on the branch (the gate
  is the lock, not you).
  EXCEPTION: ``git add -N -- <file_set files>`` is the EXPLICIT sole allowed git-add
  command; it intent-stages new files so they appear in ``git diff`` without staging content.
- diff.patch  = run ``git add -N -- <your file_set files>`` then ``git diff -- <those files>``
  (so new files appear). The conductor also regenerates this authoritatively; this copy is
  for your own reference.
- worker-report.md : status (DONE|TOO_BIG|NEEDS_GUARD|BLOCKED), files_touched[], a one-line
  rationale, and contract_zones_touched: [...]
- if the task WRITES A GUARD: also emit mutation-recipe.json
  ({ zone_id, contract_id, file, locator, canonical_value, mutated_value, guard_test }).
- Touch the heartbeat file .autodev/runtime/$TaskId/heartbeat at every significant step.

Do NOT claim success. Do NOT run the gate. The conductor judges you.

===== TASK SPEC =====
$taskBody
"@
}

# Build the ladder. Cast to [string[]] so a single-element ladder is NOT unwrapped to a
# scalar string (which would make $ladder[0] index a character instead of the model name).
# Priority: (1) contract-zone pin always wins; (2) declared model starts a sub-ladder;
# (3) unknown declared model -> WARN + full ladder; (4) no model -> full ladder (unchanged).
$declaredModel = if ($Model) { $Model.Trim().ToLower() } else { '' }
[string[]]$ladder = @()
if ($TouchesContractZone) {
    $ladder = , $Config.WorkerLadder[0]
    if ($declaredModel -and $declaredModel -ne $Config.WorkerLadder[0]) {
        Write-AutodevLog -Level WARN -Message "Task $TaskId declares model '$declaredModel' but touches a contract zone -- pinned to $($Config.WorkerLadder[0]) (contract-zone pin overrides declared model)." -Config $Config
    }
} elseif ($declaredModel) {
    $idx = [array]::IndexOf($Config.WorkerLadder, $declaredModel)
    if ($idx -ge 0) {
        # Sub-ladder: declared tier first, then cheaper tiers only (rate-limit step-down).
        $ladder = [string[]]($Config.WorkerLadder[$idx..($Config.WorkerLadder.Length - 1)])
    } else {
        Write-AutodevLog -Level WARN -Message "Task $TaskId declares unknown model '$declaredModel' -- falling back to full ladder." -Config $Config
        $ladder = $Config.WorkerLadder
    }
} else {
    $ladder = $Config.WorkerLadder
}
$declaredLabel = if ($declaredModel) { $declaredModel } else { '(none)' }
Write-AutodevLog -Level WORKER -Message "Task $TaskId declared-model: $declaredLabel  ladder: $($ladder -join ' -> ')$(if ($TouchesContractZone) { ' (CONTRACT-ZONE: pinned to ' + $ladder[0] + ', pause-not-downgrade on 429)' })" -Config $Config

$prompt = Build-WorkerPrompt -TaskId $TaskId -Worktree $Worktree

if ($DryRun) {
    Write-Host "=== invoke-worker DRY RUN (task $TaskId) ==="
    Write-Host "DeclaredModel: $declaredLabel"
    Write-Host "TouchesContractZone: $([bool]$TouchesContractZone)"
    Write-Host "Ladder: $($ladder -join ' -> ')"
    foreach ($model in $ladder) {
        Write-Host "  would run: $($Config.ClaudeExe) -p --model $model --permission-mode acceptEdits --max-turns $($Config.WorkerMaxTurns)  (cwd=$Worktree, prompt via stdin, heartbeat=$heartbeat)"
    }
    Write-Host "Prompt preview (first 280 chars):"
    Write-Host ($prompt.Substring(0, [Math]::Min(280, $prompt.Length)) + ' ...')
    return [pscustomobject]@{ Status = 'DRYRUN'; Model = $ladder[0]; RateLimited = $false; TimedOut = $false; ExitCode = 0 }
}

$result = $null
foreach ($model in $ladder) {
    Write-AutodevLog -Level WORKER -Message "Spawning claude -p --model $model for $TaskId ..." -Config $Config
    # --output-format stream-json + --verbose makes claude emit a JSONL event per step, so
    # stdout is continuous -- this feeds the watchdog's process-driven liveness signal even
    # during long read/reason phases that have not yet written any file.
    $args = @('-p', '--model', $model, '--permission-mode', 'acceptEdits',
              '--max-turns', [string]$Config.WorkerMaxTurns,
              '--verbose', '--output-format', 'stream-json')
    $r = Start-WatchedProcess -FilePath $Config.ClaudeExe -ArgumentList $args `
            -HeartbeatPath $heartbeat `
            -StaleSeconds ($Config.WatchdogStaleMinutes * 60) `
            -TimeoutSeconds ($Config.WorkerTimeoutMinutes * 60) `
            -StdinText $prompt -WorkingDirectory $Worktree `
            -StdoutLogPath (Join-Path $rtDir 'worker-stdout.log') `
            -StderrLogPath (Join-Path $rtDir 'worker-stderr.log') `
            -ActivityPaths @($rtDir)

    if ($r.RateLimited) {
        if ($TouchesContractZone) {
            Write-AutodevLog -Level WORKER -Message "Contract-zone task $TaskId rate-limited on $model -- PAUSE (no downgrade)." -Config $Config
            $result = [pscustomobject]@{ Status = 'RATE_LIMITED'; Model = $model; RateLimited = $true; TimedOut = $false; ExitCode = $r.ExitCode }
            break
        }
        Write-AutodevLog -Level WORKER -Message "Rate-limited on $model; stepping down the ladder." -Config $Config
        $result = [pscustomobject]@{ Status = 'RATE_LIMITED'; Model = $model; RateLimited = $true; TimedOut = $false; ExitCode = $r.ExitCode }
        continue
    }
    if ($r.TimedOut) {
        Write-AutodevLog -Level WORKER -Message "Worker $TaskId timed out / heartbeat stale on $model." -Config $Config
        $result = [pscustomobject]@{ Status = 'TIMED_OUT'; Model = $model; RateLimited = $false; TimedOut = $true; ExitCode = $r.ExitCode }
        break
    }
    $result = [pscustomobject]@{ Status = 'DONE'; Model = $model; RateLimited = $false; TimedOut = $false; ExitCode = $r.ExitCode }
    break
}

return $result
