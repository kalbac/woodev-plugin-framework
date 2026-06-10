<#
.SYNOPSIS
  The loop. Claim -> worker -> critic -> gate -> commit/escalate. No LLM, no judgment.

.DESCRIPTION
  The immortal conductor. It only routes files and runs sub-scripts; all intelligence
  lives in the worker (claude -p) and critic (codex GPT-5.5) subprocesses, so the
  conductor has no context to rot and no rate limit to hit. Every "decision" it makes is
  mechanical (exit codes, grep, file moves); anything requiring judgment ESCALATES.

  Per-iteration spine (runbook section 4):
    1. claim a file-disjoint task (scheduler, atomic move pending -> active)
    2. circuit breaker: attempts > MaxAttempts -> quarantine + escalate
    3. worker (model ladder + watchdog)         [skipped with -AssumeWorkerDone]
    4. read worker-report -> TOO_BIG/NEEDS_GUARD/BLOCKED route out
    5. critic (tiered, read-only, fenced) -> uncertain/broken route to human
    6. gate (composer check + INVARIANTS grep + per-guard mutation-check)
    7. COMMIT (checkpoint) | ESCALATE | RETRY
    8. periodic: anti-drift (+ digest) every AntiDriftEveryCommits commits

.PARAMETER Once
  Run a single iteration and exit (no idle loop).

.PARAMETER MaxIterations
  Stop after N iterations (default: unbounded).

.PARAMETER AssumeWorkerDone
  Do NOT spawn a worker; assume the worker artefacts (diff, worker-report) already exist.
  Used for the bootstrap guard workload, where the operator's implementing session acted
  as the worker (sanctioned by the brief: "you ARE the planner for the guard workload").

.PARAMETER GuardBootstrap
  Special section-6 flow: used when the worker session itself implements new mutation-verified
  guards + GUARDS.md rows. This flag does NOT override the gate decision: the gate still runs
  normally and the change must earn a 'COMMIT' decision on its own merits. The only extra
  behaviour is that AFTER a successful commit, the conductor also emits one escalation asking
  the operator to bless the new guards (blessed_by: pending-operator). Autonomy for the new
  contract zones switches on only after that blessing.

.PARAMETER DryRunWorker
  Pass -DryRun to invoke-worker (build the command + ladder, do not spawn claude).

.PARAMETER SkipComposer
  Pass -SkipComposer to the gate (fast structural runs).
#>
[CmdletBinding()]
param(
    [switch]$Once,
    [switch]$SelfTest,
    [int]$MaxIterations = 0,
    [switch]$AssumeWorkerDone,
    [switch]$GuardBootstrap,
    [switch]$DryRunWorker,
    [switch]$SkipComposer,
    [switch]$ReuseVerdict,
    [int]$SleepSeconds = 30
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $PSCommandPath
. (Join-Path $here '_common.ps1')

$Config = Get-AutodevConfig
Initialize-AutodevDirectories -Config $Config

function Get-Attempts {
    param([string]$TaskId)
    $f = Join-Path (Join-Path $Config.Runtime $TaskId) 'attempts'
    if (Test-Path $f) { return [int](Get-Content $f -Raw) }
    return 0
}
function Set-Attempts {
    param([string]$TaskId, [int]$N)
    $d = Join-Path $Config.Runtime $TaskId
    if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d -Force | Out-Null }
    Set-Content -Path (Join-Path $d 'attempts') -Value $N -Encoding utf8
}
function Restore-Attempt {
    # Refund ONE attempt after an EXTERNAL pause (a 429/rate-limit from the worker OR critic
    # transport), as opposed to a genuine failed attempt. An external pause must never advance
    # the circuit breaker toward a false "poison": observed 2026-06-06, warehouse-store was
    # quarantined as poison after 3 back-to-back codex (critic) 429s, even though the worker
    # output was DONE and composer-green. Shared by the worker AND critic rate-limit paths so
    # the two stay symmetric (the critic path was missing this refund until then).
    param([string]$TaskId, [int]$Attempts)
    Set-Attempts -TaskId $TaskId -N ([Math]::Max(0, $Attempts - 1))
}

function Move-Task {
    param([string]$TaskId, [string]$ToDir)
    $src = Join-Path $Config.QueueActive "$TaskId.md"
    if (Test-Path $src) { Move-Item -Path $src -Destination (Join-Path $ToDir "$TaskId.md") -Force }
}

# Pure predicate: returns $true only when the gate issued a literal 'COMMIT' decision.
# Used by BOTH the production commit-path condition (Invoke-ConductorIteration) and the
# self-test cases (3 & 4), so a regression in the condition is caught by the tests.
function Test-AutodevCommitDecision {
    param([string]$Decision)
    return ($Decision -eq 'COMMIT')
}

# Pure predicate: returns $true only when an iteration's committed flag is set, meaning the
# counter should be incremented. Used by BOTH the outer loop counter and self-test case 4,
# so any change that re-adds false-increment paths is caught by the tests.
function Test-AutodevCounterIncrement {
    param([bool]$IterationCommitted)
    return $IterationCommitted
}

function New-WorkerDiff {
    <# Capture a diff of the task's file_set, intent-adding any new files so they show. #>
    param([pscustomobject]$Task, [string]$OutPath)
    foreach ($f in $Task.file_set) {
        if (Test-Path (Join-Path $Config.RepoRoot $f)) {
            Invoke-Native -Exe 'git' -CommandArgs @('add', '-N', '--', $f) -WorkingDirectory $Config.RepoRoot | Out-Null
        }
    }
    $r = Invoke-Native -Exe 'git' -CommandArgs (@('diff', '--') + $Task.file_set) -WorkingDirectory $Config.RepoRoot
    Set-Content -Path $OutPath -Value ($r.Output | Out-String) -Encoding utf8
}

function Invoke-Escalation {
    param([string]$Id, [string]$Reason, [string]$Type, [pscustomobject]$Task,
          [string]$What, [string]$Decision, [string]$OptionA, [string]$OptionB,
          [string]$Cost, [string]$Evidence)
    & (Join-Path $here 'escalate.ps1') -Id $Id -Reason $Reason -Type $Type `
        -TaskId $Task.id -Title $Task.title -What $What -Decision $Decision `
        -OptionA $OptionA -OptionB $OptionB -CostOfWrong $Cost -Evidence $Evidence | Out-Null
}

function Invoke-CommitTask {
    param([pscustomobject]$Task, [string]$Message)
    foreach ($f in $Task.file_set) {
        Invoke-Native -Exe 'git' -CommandArgs @('add', '--', $f) -WorkingDirectory $Config.RepoRoot | Out-Null
    }
    Invoke-Native -Exe 'git' -CommandArgs @('commit', '-m', $Message) -WorkingDirectory $Config.RepoRoot | Out-Null
    $r = Invoke-Native -Exe 'git' -CommandArgs @('rev-parse', '--short', 'HEAD') -WorkingDirectory $Config.RepoRoot
    return ($r.Output | Out-String).Trim()
}

function Invoke-ConductorIteration {
    # Reset the commit flag for each iteration. Set to $true ONLY by the COMMIT route below so
    # the caller can count actual commits without relying on done/ file existence (which can
    # false-increment on a reused/re-queued task id that had a prior done/ entry).
    $script:iterationCommitted = $false

    # 1. CLAIM (atomic, file-disjoint)
    $claimedId = (& (Join-Path $here 'scheduler.ps1') | Select-Object -First 1)
    if (-not $claimedId) { return $null }
    $claimedId = $claimedId.ToString().Trim()
    $task = ConvertFrom-AutodevTask -Path (Join-Path $Config.QueueActive "$claimedId.md")
    Write-AutodevLog -Level INFO -Message "=== iteration: task $($task.id) ===" -Config $Config

    # 2. CIRCUIT BREAKER
    $attempts = (Get-Attempts -TaskId $task.id) + 1
    Set-Attempts -TaskId $task.id -N $attempts
    if ($attempts -gt $Config.MaxAttempts) {
        Move-Task -TaskId $task.id -ToDir $Config.QueueQuarantine
        try {
            Invoke-Escalation -Id "poison-$($task.id)" -Reason 'poison task' -Type 'poison' -Task $task `
                -What "Task failed across $($attempts-1) fresh agents." -Decision 'Re-scope or drop this task?' `
                -OptionA 'Re-scope and re-queue' -OptionB 'Drop' -Cost 'wasted token spend' -Evidence ''
        } catch {
            Write-AutodevLog -Level WARN -Message "Escalation artifact write failed for poison-$($task.id): $($_.Exception.Message)" -Config $Config
        }
        return $task
    }

    $rtDir = Join-Path $Config.Runtime $task.id
    if (-not (Test-Path $rtDir)) { New-Item -ItemType Directory -Path $rtDir -Force | Out-Null }
    $diffPath = Join-Path $rtDir 'diff.patch'

    # 3. WORKER
    if (-not $AssumeWorkerDone) {
        $w = & (Join-Path $here 'invoke-worker.ps1') -TaskId $task.id `
                -Model ([string]$task.model) `
                -TouchesContractZone:([bool]$task.touches_contract_zone) -DryRun:$DryRunWorker
        if ($w.Status -eq 'RATE_LIMITED') {
            # A 429 is an EXTERNAL pause, not a failed attempt -- refund the attempt counter so
            # repeated rate-limits can never trip the circuit breaker into a false poison
            # (observed 2026-06-06: warehouse-store poisoned by two back-to-back 429s on opus).
            Restore-Attempt -TaskId $task.id -Attempts $attempts
            Write-AutodevLog -Level WORKER -Message "Rate-limited; returning task $($task.id) to pending (lose nothing; attempt refunded)." -Config $Config
            Move-Task -TaskId $task.id -ToDir $Config.QueuePending
            return $task
        }
        if ($w.Status -eq 'TIMED_OUT') {
            Write-AutodevLog -Level WORKER -Message "Timed out; moving to pending for a fresh attempt (attempts=$attempts)." -Config $Config
            Move-Task -TaskId $task.id -ToDir $Config.QueuePending
            return $task
        }
    }

    # 4. WORKER REPORT routing
    $reportPath = Join-Path $rtDir 'worker-report.md'
    if (Test-Path $reportPath) {
        $report = Get-Content $reportPath -Raw -Encoding utf8
        if ($report -match '(?im)^\s*status\s*[:=]\s*TOO_BIG') {
            Write-AutodevLog -Message "Task $($task.id) TOO_BIG; archiving for re-decomposition." -Config $Config
            Move-Task -TaskId $task.id -ToDir $Config.QueueQuarantine
            try {
                Invoke-Escalation -Id "toobig-$($task.id)" -Reason 'task too big' -Type 'blocked' -Task $task `
                    -What 'Worker reported TOO_BIG; needs decomposition.' -Decision 'Approve the proposed split?' `
                    -OptionA 'Approve split' -OptionB 'Re-scope manually' -Cost 'none (no code landed)' -Evidence $report
            } catch {
                Write-AutodevLog -Level WARN -Message "Escalation artifact write failed for toobig-$($task.id): $($_.Exception.Message)" -Config $Config
            }
            return $task
        }
        foreach ($pair in @(@('NEEDS_GUARD', 'needs-guard'), @('BLOCKED', 'blocked'))) {
            if ($report -match "(?im)^\s*status\s*[:=]\s*$($pair[0])") {
                Move-Task -TaskId $task.id -ToDir $Config.QueueEscalated
                try {
                    Invoke-Escalation -Id "$($pair[1])-$($task.id)" -Reason $pair[0] -Type $pair[1] -Task $task `
                        -What "Worker reported $($pair[0])." -Decision 'Provide guidance.' `
                        -OptionA 'Write/bless a guard' -OptionB 'Mark human-only' -Cost 'blocks the task' -Evidence $report
                } catch {
                    Write-AutodevLog -Level WARN -Message "Escalation artifact write failed for $($pair[1])-$($task.id): $($_.Exception.Message)" -Config $Config
                }
                return $task
            }
        }
    }

    # 5. DIFF + CRITIC
    # Real-worker runs leave the change UNCOMMITTED (gate-as-lock: the conductor commits only
    # after the gate passes). Regenerate the diff authoritatively from the working tree so the
    # critic/gate judge the actual uncommitted change, not the worker's self-reported copy.
    # -AssumeWorkerDone (operator-as-worker bootstrap) keeps the existing "only if missing" path.
    if (-not $AssumeWorkerDone) {
        New-WorkerDiff -Task $task -OutPath $diffPath
    }
    elseif (-not (Test-Path $diffPath) -or ((Get-Item $diffPath).Length -eq 0)) {
        New-WorkerDiff -Task $task -OutPath $diffPath
    }
    $verdictFile = Join-Path $rtDir 'verdict.json'
    $criticExit = 0
    $reused = $false
    if ($ReuseVerdict -and (Test-Path $verdictFile)) {
        $existing = Get-Content $verdictFile -Raw -Encoding utf8 | ConvertFrom-Json
        if ($existing.verdict -eq 'clean') {
            # Verify the stored verdict was computed over the SAME diff we have now.
            $currentDiff = if (Test-Path $diffPath) { Get-Content $diffPath -Raw -Encoding utf8 } else { '' }
            $currentHash = Get-AutodevTextSha256 -Text $currentDiff
            $storedHash = if ($existing.PSObject.Properties.Name -contains 'diff_sha256') { $existing.diff_sha256 } else { '' }
            if ($storedHash -ne '' -and $storedHash -eq $currentHash) {
                Write-AutodevLog -Level CRITIC -Message "Reusing CLEAN verdict (-ReuseVerdict); diff hash matches ($currentHash)." -Config $Config
                $reused = $true
            } else {
                Write-AutodevLog -Level CRITIC -Message "Verdict hash mismatch (stored=$storedHash current=$currentHash); falling through to fresh critic run." -Config $Config
            }
        }
    }
    if (-not $reused) {
        & (Join-Path $here 'invoke-critic.ps1') -TaskId $task.id -DiffPath $diffPath | Out-Null
        $criticExit = $LASTEXITCODE
    }
    $verdict = Get-Content $verdictFile -Raw -Encoding utf8 | ConvertFrom-Json
    if ($criticExit -eq 4) {
        # A critic 429 is an EXTERNAL pause, not a failed attempt -- refund the attempt counter,
        # symmetric with the worker rate-limit path above. Without this refund, repeated codex
        # (critic) rate-limits march the counter to MaxAttempts and quarantine a DONE,
        # composer-green task as a FALSE poison (observed 2026-06-06: warehouse-store, whose
        # worker was DONE but whose critic took 3 back-to-back 429s).
        Restore-Attempt -TaskId $task.id -Attempts $attempts
        Write-AutodevLog -Level CRITIC -Message "Critic rate-limited; returning task to pending (attempt refunded)." -Config $Config
        Move-Task -TaskId $task.id -ToDir $Config.QueuePending
        return $task
    }
    if ($verdict.verdict -ne 'clean') {
        # Move-Task FIRST so the task is in escalated/ even if the artifact write throws.
        Move-Task -TaskId $task.id -ToDir $Config.QueueEscalated
        try {
            Invoke-Escalation -Id "critic-$($task.id)" -Reason "critic $($verdict.verdict)" `
                -Type $(if ($verdict.verdict -eq 'broken') { 'disagreement' } else { 'uncertain' }) -Task $task `
                -What "Critic verdict: $($verdict.verdict). $($verdict.notes)" `
                -Decision 'Override the critic, or fix the diff?' -OptionA 'Send back to worker' -OptionB 'Override (commit anyway)' `
                -Cost 'a real contract break could land' -Evidence ($verdict | ConvertTo-Json -Depth 5)
        } catch {
            Write-AutodevLog -Level WARN -Message "Escalation artifact write failed for critic-$($task.id): $($_.Exception.Message)" -Config $Config
        }
        return $task
    }

    # 6. GATE
    & (Join-Path $here 'gate.ps1') -TaskId $task.id -FileSet $task.file_set -SkipComposer:$SkipComposer | Out-Null
    $gateExit = $LASTEXITCODE
    $gate = Get-Content (Join-Path $rtDir 'gate-verdict.json') -Raw -Encoding utf8 | ConvertFrom-Json

    # 7. DECISION
    if ($gate.decision -eq 'RETRY') {
        # Gate RETRY == `composer check` FAILED -- the ONLY RETRY trigger (gate.ps1: contract /
        # constitution issues ESCALATE instead, never RETRY). A build error is worker-fixable, so
        # return the task to PENDING for a fresh worker attempt. NOT to active/: the scheduler only
        # claims from pending/, so an active/ RETRY is never re-picked -- it silently strands a
        # composer-failing task with no retry, no escalation, no commit (observed 2026-06-06:
        # rest-bootstrap / status-view / abstract-api dead-ended this way). The attempt is NOT
        # refunded: unlike a rate-limit pause, a composer failure is a genuine attempt, so repeated
        # failures still trip the breaker into a (legitimate) poison escalation -- a VISIBLE stuck
        # signal instead of a silent one. NOTE: `composer check` runs whole-tree (gate.ps1), so a
        # task can RETRY on OTHER parked tasks' uncommitted breakage, not its own; keeping the
        # escalation backlog drained (tree clean) is what prevents that cross-task false retry.
        Write-AutodevLog -Level GATE -Message "Gate RETRY ($($gate.reasons -join '; ')); returning to pending for a fresh worker attempt." -Config $Config
        Move-Task -TaskId $task.id -ToDir $Config.QueuePending
        return $task
    }

    # -GuardBootstrap enters the commit path ONLY when the gate says COMMIT.
    # Unknown / malformed gate decisions MUST NOT fall through to the commit path (fail-closed):
    # only the literal string 'COMMIT' is accepted here; everything else routes to the ESCALATE
    # handler below (including 'GARBAGE', empty string, or any future decision value).
    if (Test-AutodevCommitDecision -Decision $gate.decision) {
        $kind = if ($task.type -eq 'guard') { 'test' } else { 'refactor' }
        $hash = Invoke-CommitTask -Task $task -Message "$kind(autodev): $($task.title)"
        Write-AutodevLog -Level GATE -Message "Committed $($task.id) as $hash." -Config $Config
        Move-Task -TaskId $task.id -ToDir $Config.QueueDone
        Add-Content -Path (Join-Path $Config.QueueDone "$($task.id).md") -Value "`n<!-- committed: $hash -->" -Encoding utf8
        # Signal to the outer loop that this iteration produced an actual commit.
        # This is the canonical commit-counter source; the outer loop MUST use this flag,
        # not done/ file existence (which can be stale from a prior run of the same task id).
        $script:iterationCommitted = $true

        if ($GuardBootstrap) {
            # section 6.5: one escalation for the operator to BLESS the new guards.
            try {
                Invoke-Escalation -Id "bless-$($task.id)" -Reason 'bless new contract guards' -Type 'constitution' -Task $task `
                    -What "Committed $($hash): mutation-verified guards + GUARDS.md rows (blessed_by pending-operator). Until you bless, those zones still escalate." `
                    -Decision 'Bless these guards so their contract zones become autonomous?' `
                    -OptionA 'Bless (set blessed_by to your name in GUARDS.md)' -OptionB 'Reject / keep human-only' `
                    -Cost 'a wrong guard would auto-pass a real contract break' `
                    -Evidence ($gate | ConvertTo-Json -Depth 5)
            } catch {
                Write-AutodevLog -Level WARN -Message "Escalation artifact write failed for bless-$($task.id): $($_.Exception.Message)" -Config $Config
            }
        }
        return $task
    }

    # ESCALATE (constitution / no-guard / guard-not-protecting / unblessed / empty file_set)
    # Move-Task FIRST so the task is in escalated/ even if the artifact write throws.
    Move-Task -TaskId $task.id -ToDir $Config.QueueEscalated
    try {
        Invoke-Escalation -Id "gate-$($task.id)" -Reason 'gate escalation' `
            -Type $(if ($gate.constitution_touched.Count -gt 0) { 'constitution' } else { 'needs-guard' }) -Task $task `
            -What "Gate decision: ESCALATE. $($gate.reasons -join '; ')" `
            -Decision 'Approve this change manually?' -OptionA 'Approve + commit' -OptionB 'Reject' `
            -Cost 'touches an unguarded contract zone or the constitution' -Evidence ($gate | ConvertTo-Json -Depth 5)
    } catch {
        Write-AutodevLog -Level WARN -Message "Escalation artifact write failed for gate-$($task.id): $($_.Exception.Message)" -Config $Config
    }
    return $task
}

# ----------------------------------------------------------------------------------
# Self-test: the circuit-breaker attempt-counter invariant (acceptance for the
# 2026-06-06 false-poison fix). No subprocesses: it drives the REAL Get/Set/Restore-Attempt
# helpers against a temp runtime and asserts both halves of the invariant:
#   (1) repeated EXTERNAL pauses (worker/critic 429 -> Restore-Attempt) NEVER reach the
#       breaker threshold, so a rate-limited-but-DONE task is never quarantined as poison;
#   (2) genuine failed attempts (no refund) DO cross the threshold, so the safety net still
#       fires for actually-stuck tasks (the fix must not disarm the breaker).
# ----------------------------------------------------------------------------------
function Invoke-ConductorSelfTest {
    $tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("autodev-cond-" + [guid]::NewGuid().ToString('N'))
    $runtime = Join-Path $tmp 'runtime'
    New-Item -ItemType Directory -Path $runtime -Force | Out-Null
    # Get/Set-Attempts read $Config from the calling scope (PowerShell dynamic scope), so a
    # local override here redirects them at the temp runtime without touching the real one.
    $Config = $script:Config.PSObject.Copy()
    $Config.Runtime = $runtime
    $max = $Config.MaxAttempts

    # (1) EXTERNAL-PAUSE cycles: claim -> increment -> refund, more times than the threshold.
    # Each cycle mirrors one conductor iteration that ends in a worker/critic 429. The value
    # the breaker would test is (Get-Attempts)+1 at the top of the next iteration.
    $taskA = 'selftest-external-pause'
    $maxBreakerInput = 0
    for ($i = 0; $i -lt ($max + 3); $i++) {
        $a = (Get-Attempts -TaskId $taskA) + 1
        Set-Attempts -TaskId $taskA -N $a
        if ($a -gt $maxBreakerInput) { $maxBreakerInput = $a }
        Restore-Attempt -TaskId $taskA -Attempts $a       # external pause: refund the attempt
    }
    $externalNeverPoisons = ($maxBreakerInput -le $max)   # breaker input never exceeds threshold

    # (2) GENUINE-FAILURE cycles: claim -> increment, NO refund. The breaker must still fire.
    $taskB = 'selftest-genuine-failure'
    $poisoned = $false
    for ($i = 0; $i -lt ($max + 1); $i++) {
        $a = (Get-Attempts -TaskId $taskB) + 1
        Set-Attempts -TaskId $taskB -N $a
        if ($a -gt $max) { $poisoned = $true }            # breaker fires (would quarantine)
    }

    Remove-Item -Path $tmp -Recurse -Force -ErrorAction SilentlyContinue

    # (3) FAIL-CLOSED: a GARBAGE gate decision must NOT take the commit path.
    # Calls Test-AutodevCommitDecision -- the SAME function the production commit path uses --
    # so this test FAILS if someone changes the function to return $true for non-'COMMIT' values.
    # Three inputs are checked: 'GARBAGE' -> $false, 'COMMIT' -> $true, 'ESCALATE' -> $false.
    $c3_garbage  = (Test-AutodevCommitDecision -Decision 'GARBAGE')   # must be $false
    $c3_commit   = (Test-AutodevCommitDecision -Decision 'COMMIT')    # must be $true
    $c3_escalate = (Test-AutodevCommitDecision -Decision 'ESCALATE')  # must be $false
    $c3_null     = (Test-AutodevCommitDecision -Decision $null)       # must be $false
    $case3 = (-not $c3_garbage) -and $c3_commit -and (-not $c3_escalate) -and (-not $c3_null)
    if ($case3) {
        Write-Host "Case 3 PASS -- Test-AutodevCommitDecision: GARBAGE=$c3_garbage COMMIT=$c3_commit ESCALATE=$c3_escalate null=$c3_null (fail-closed verified)." -ForegroundColor Green
    } else {
        Write-Host "Case 3 FAIL -- Test-AutodevCommitDecision returned unexpected value(s): GARBAGE=$c3_garbage COMMIT=$c3_commit ESCALATE=$c3_escalate null=$c3_null" -ForegroundColor Red
    }

    # (4) ESCALATED outcome must NOT increment the commit counter.
    # Calls Test-AutodevCounterIncrement -- the SAME function the outer loop uses -- so this
    # test FAILS if someone changes the function to return $true when committed flag is $false.
    $c4_notCommitted = (Test-AutodevCounterIncrement -IterationCommitted $false)  # must be $false
    $c4_committed    = (Test-AutodevCounterIncrement -IterationCommitted $true)   # must be $true
    $case4 = (-not $c4_notCommitted) -and $c4_committed
    if ($case4) {
        Write-Host "Case 4 PASS -- Test-AutodevCounterIncrement: notCommitted=$c4_notCommitted committed=$c4_committed (counter guard verified)." -ForegroundColor Green
    } else {
        Write-Host "Case 4 FAIL -- Test-AutodevCounterIncrement returned unexpected value(s): notCommitted=$c4_notCommitted committed=$c4_committed" -ForegroundColor Red
    }

    Write-Host "--- Conductor circuit-breaker attempt-counter self-test ---"
    Write-Host "External pauses (worker/critic 429): max breaker input = $maxBreakerInput (threshold $max); never poisons = $externalNeverPoisons"
    Write-Host "Genuine failures: breaker fires after >$max attempts = $poisoned"

    if ($externalNeverPoisons -and $poisoned -and $case3 -and $case4) {
        Write-Host "RESULT: PASS -- external pauses are refunded (no false poison); genuine failures still trip the breaker; fail-closed gate; escalate does not increment counter." -ForegroundColor Green
        return 0
    } else {
        Write-Host "RESULT: FAIL" -ForegroundColor Red
        return 1
    }
}

# ----------------------------------------------------------------------------------
# Entry: loop or single iteration
# ----------------------------------------------------------------------------------
if ($SelfTest) { exit (Invoke-ConductorSelfTest) }

$sessionStart = Get-Date
$iterations = 0
$commitsSinceDrift = 0
while ($true) {
    # MaxSessionHours wall-clock cap: exit gracefully before spawning another worker.
    $elapsedHours = ((Get-Date) - $sessionStart).TotalHours
    if ($elapsedHours -ge $Config.MaxSessionHours) {
        Write-AutodevLog -Level WARN -Message "MaxSessionHours ($($Config.MaxSessionHours)) reached after $([Math]::Round($elapsedHours,2)) h; stopping conductor." -Config $Config
        break
    }
    $did = Invoke-ConductorIteration
    $iterations++

    # Anti-drift trigger: count successful COMMITs via the explicit flag set by the COMMIT route.
    # Using the flag (not done/ file existence) prevents false-increments from reused task ids.
    if ($null -ne $did -and (Test-AutodevCounterIncrement -IterationCommitted $script:iterationCommitted)) {
        $commitsSinceDrift++
        if ($commitsSinceDrift -ge $Config.AntiDriftEveryCommits) {
            $commitsSinceDrift = 0
            try {
                Write-AutodevLog -Level INFO -Message "Anti-drift: running after $($Config.AntiDriftEveryCommits) commits ..." -Config $Config
                # Pass the exact commit window so anti-drift reviews ALL commits since the last
                # check, not just a fixed HEAD~3 default. $commitsSinceDrift was the running
                # count before the reset above; use $Config.AntiDriftEveryCommits as the count.
                $driftWindow = $Config.AntiDriftEveryCommits
                & (Join-Path $here 'anti-drift.ps1') `
                    -SinceRef "HEAD~$driftWindow" `
                    -CommitsSinceLast $driftWindow | Out-Null
            } catch {
                Write-AutodevLog -Level WARN -Message "Anti-drift run failed (non-fatal): $($_.Exception.Message)" -Config $Config
            }
        }
    }

    if ($Once) { break }
    if ($MaxIterations -gt 0 -and $iterations -ge $MaxIterations) { break }
    if ($null -eq $did) {
        Start-Sleep -Seconds $SleepSeconds
    }
}
Write-AutodevLog -Message "Conductor stopped after $iterations iteration(s)." -Config $Config
