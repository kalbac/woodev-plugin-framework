<#
.SYNOPSIS
  Run a disposable worker (claude -p) on one task, with model ladder + watchdog.

.DESCRIPTION
  Spawns a fresh `claude -p` worker for a single task in its worktree. Implements:
    - MODEL LADDER: Opus -> Sonnet -> Haiku for ordinary tasks.
    - CONTRACT-ZONE PIN: a task that touches a contract zone is pinned to Opus. On a
      rate-limit it PAUSES (returns rate_limited) -- it is NEVER silently downgraded to
      a weaker model. A license-zone edit must not land on Haiku just because Opus is busy.
    - WATCHDOG: the worker touches runtime/<id>/heartbeat; a stale heartbeat kills the
      hung process so the conductor can respawn (attempts++).
    - RATE-LIMIT DETECTION: 429/quota in exit code or stderr steps down the ladder
      (ordinary tasks) or pauses (contract-zone tasks).

  Returns: @{ Status; Model; RateLimited; TimedOut; ExitCode }.
  Status in DONE-ish (the conductor then reads runtime/<id>/worker-report.md for the
  authoritative worker status) | RATE_LIMITED | TIMED_OUT | ERROR.

.PARAMETER DryRun
  Build and print the ladder + claude command(s) without spawning claude. Used to verify
  command construction and the contract-zone Opus pin in this bootstrap session.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$TaskId,
    [string]$Worktree,
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
blackboard on disk, not your context. Use Serena for all PHP reads -- never the Read tool.

TASK: $TaskId  (full spec below, also at .autodev/queue/active/$TaskId.md)
ANCHOR: read .autodev/GOAL.md before anything. Do not exceed the task scope.
INVARIANTS: read .autodev/INVARIANTS.md. You MUST NOT break any contract zone.

Rules:
- Work in this git worktree: $Worktree . Touch ONLY files the task names in file_set.
- Make the smallest change that completes the task.
- If the task needs >1 logical change, STOP: write worker-report.md status=TOO_BIG with a
  proposed decomposition. Do not code.
- If completing it REQUIRES touching a contract zone with no blessed guard in GUARDS.md,
  STOP: status=NEEDS_GUARD. Do not code.

Output to .autodev/runtime/$TaskId/ :
- the change, committed to THIS worktree (do NOT push, do NOT touch main)
- diff.patch  = git diff of your change
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
[string[]]$ladder = if ($TouchesContractZone) { , $Config.WorkerLadder[0] } else { $Config.WorkerLadder }
Write-AutodevLog -Level WORKER -Message "Task $TaskId ladder: $($ladder -join ' -> ')$(if ($TouchesContractZone) { ' (CONTRACT-ZONE: pinned to ' + $ladder[0] + ', pause-not-downgrade on 429)' })" -Config $Config

$prompt = Build-WorkerPrompt -TaskId $TaskId -Worktree $Worktree

if ($DryRun) {
    Write-Host "=== invoke-worker DRY RUN (task $TaskId) ==="
    Write-Host "TouchesContractZone: $([bool]$TouchesContractZone)"
    Write-Host "Ladder: $($ladder -join ' -> ')"
    foreach ($model in $ladder) {
        Write-Host "  would run: $($Config.ClaudeExe) -p --model $model --permission-mode acceptEdits  (cwd=$Worktree, prompt via stdin, heartbeat=$heartbeat)"
    }
    Write-Host "Prompt preview (first 280 chars):"
    Write-Host ($prompt.Substring(0, [Math]::Min(280, $prompt.Length)) + ' ...')
    return [pscustomobject]@{ Status = 'DRYRUN'; Model = $ladder[0]; RateLimited = $false; TimedOut = $false; ExitCode = 0 }
}

$result = $null
foreach ($model in $ladder) {
    Write-AutodevLog -Level WORKER -Message "Spawning claude -p --model $model for $TaskId ..." -Config $Config
    $args = @('-p', '--model', $model, '--permission-mode', 'acceptEdits')
    $r = Start-WatchedProcess -FilePath $Config.ClaudeExe -ArgumentList $args `
            -HeartbeatPath $heartbeat `
            -StaleSeconds ($Config.WatchdogStaleMinutes * 60) `
            -TimeoutSeconds ($Config.WorkerTimeoutMinutes * 60) `
            -StdinText $prompt -WorkingDirectory $Worktree

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
