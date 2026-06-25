<#
.SYNOPSIS
  The loop. Claim -> worker -> critic -> gate -> commit/escalate. No LLM, no judgment.

.DESCRIPTION
  The immortal conductor. It only routes files and runs sub-scripts; all intelligence
  lives in the worker (claude -p) and critic (codex GPT-5.5) subprocesses, so the
  conductor has no context to rot and no rate limit to hit. Every "decision" it makes is
  mechanical (exit codes, grep, file moves); anything requiring judgment ESCALATES.

  Per-iteration spine (runbook section 4):
    0. branch preflight: refuse to run unless HEAD matches AllowedBranchPattern (never main)
    1. claim a file-disjoint task (scheduler, atomic move pending -> active)
    2. circuit breaker: attempts > MaxAttempts -> quarantine + escalate
    3. worker (model ladder + watchdog)         [skipped with -AssumeWorkerDone]
    4. read worker-report -> TOO_BIG/NEEDS_GUARD/BLOCKED route out
    4b. dirty-file fence: worker touched files outside file_set / forbidden_paths -> escalate
    5. critic (read-only, fenced). clean -> gate. non-contract reject -> bounded
       worker<->critic retry (CriticRetryMax/max_rounds). contract reject / retries spent -> human.
    6. gate (composer + success_commands + INVARIANTS per-VALUE mutation-check)
    7. COMMIT (checkpoint) | ESCALATE | RETRY
    8. periodic: anti-drift (+ digest) every AntiDriftEveryCommits commits; DRIFT verdict -> escalate
    *. worker/critic 429 -> attempt refunded + RateLimitBackoffSeconds backoff (no busy-loop)

.PARAMETER Once
  Run a single iteration and exit (no idle loop).

.PARAMETER MaxIterations
  Stop after N iterations (default: unbounded).

.PARAMETER AssumeWorkerDone
  Do NOT spawn a worker; assume the worker artefacts (diff, worker-report) already exist.
  Used for the bootstrap guard workload, where the operator's implementing session acted
  as the worker (sanctioned by the brief: "you ARE the planner for the guard workload").

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

# Pure predicate: does an anti-drift verdict line warrant an escalation? Only a DRIFT verdict
# does -- ON-TRACK/UNCERTAIN are logged to the digest but do not interrupt the operator. Used
# by BOTH the outer loop and the self-test so the routing cannot silently regress to "log only".
function Test-AutodevDriftEscalates {
    param([string]$DriftLine)
    if ($null -eq $DriftLine) { return $false }
    return ($DriftLine -match '(?im)^\s*DRIFT:')
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
    # Reset the rate-limit flag: the outer loop sleeps RateLimitBackoffSeconds when a worker or
    # critic 429 set this, instead of immediately re-claiming (which would busy-loop on 429s).
    $script:iterationRateLimited = $false

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
    $verdictFile = Join-Path $rtDir 'verdict.json'
    $feedbackFile = Join-Path $rtDir 'critic-feedback.md'
    # Start each iteration with NO stale checker feedback (a prior re-claim of this id may have
    # left one). The retry loop (re)writes it only when it actually sends work back to a worker.
    if (Test-Path $feedbackFile) { Remove-Item $feedbackFile -Force -ErrorAction SilentlyContinue }

    # 3-5. WORKER -> dirty-file fence -> CRITIC, with a BOUNDED worker<->critic retry.
    # A contract-zone reject always routes to the human on the FIRST critic objection (never
    # auto-retry a contract risk). An ordinary (non-contract) reject sends the checker's notes
    # back to a fresh worker up to maxRounds times before escalating -- the L4->L5 step that
    # keeps the operator out of routine fix-ups.
    $isContract = [bool]$task.touches_contract_zone
    $maxRounds = if ($task.max_rounds) { [int]$task.max_rounds } else { [int]$Config.CriticRetryMax }
    $verdict = $null
    $round = 0
    # Fingerprint baseline of pre-existing working-tree dirt (raw path -> content hash), captured
    # BEFORE any worker spawn. The fence flags only files the worker NEWLY created or FURTHER
    # edited (fingerprint changed), so untouched pre-existing dirt does not false-stall the loop
    # AND a worker editing a pre-existing-dirty out-of-file_set file is still caught.
    $preFingerprints = if (-not $AssumeWorkerDone) { Get-AutodevFileFingerprints -RawPaths (Get-GitChangedFilesRaw -Config $Config) -Config $Config } else { @{} }
    while ($true) {
        # ---- 3. WORKER ----
        if (-not $AssumeWorkerDone) {
            $w = & (Join-Path $here 'invoke-worker.ps1') -TaskId $task.id `
                    -Model ([string]$task.model) `
                    -TouchesContractZone:$isContract -DryRun:$DryRunWorker
            if ($w.Status -eq 'RATE_LIMITED') {
                # A 429 is an EXTERNAL pause, not a failed attempt -- refund the attempt counter so
                # repeated rate-limits can never trip the circuit breaker into a false poison
                # (observed 2026-06-06: warehouse-store poisoned by two back-to-back 429s on opus).
                Restore-Attempt -TaskId $task.id -Attempts $attempts
                $script:iterationRateLimited = $true
                Write-AutodevLog -Level WORKER -Message "Rate-limited; returning task $($task.id) to pending (attempt refunded; conductor backs off)." -Config $Config
                Move-Task -TaskId $task.id -ToDir $Config.QueuePending
                return $task
            }
            if ($w.Status -eq 'TIMED_OUT') {
                Write-AutodevLog -Level WORKER -Message "Timed out; moving to pending for a fresh attempt (attempts=$attempts)." -Config $Config
                Move-Task -TaskId $task.id -ToDir $Config.QueuePending
                return $task
            }
        }

        # ---- 4. WORKER REPORT routing ----
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

        # ---- 4b. DIRTY-FILE FENCE: worker must touch ONLY its file_set (+ no forbidden_paths) ----
        # The conductor only diffs/gates/commits the file_set, so any worker edit OUTSIDE it would
        # persist invisibly in the shared tree and poison later gates. Catch it before the critic.
        # Only NEW dirt (vs the pre-worker baseline) is judged, so pre-existing tree dirt does not
        # falsely stall the loop. Scratch under .autodev/ is ignored, but the constitution files
        # there are NOT (DirtyFenceIgnore). For -AssumeWorkerDone the operator's scope is
        # intentional so stray-files are not flagged, but declared forbidden_paths are STILL honored.
        $allChangedRaw = @(Get-GitChangedFilesRaw -Config $Config)
        if (-not $AssumeWorkerDone) {
            $curFingerprints = Get-AutodevFileFingerprints -RawPaths $allChangedRaw -Config $Config
            $workerChanged = @(Get-AutodevWorkerTouchedFiles -Baseline $preFingerprints -Current $curFingerprints)
            $stray = @(Get-AutodevStrayChangedFiles -ChangedFiles $workerChanged -FileSet $task.file_set -IgnorePrefixes $Config.DirtyFenceIgnore)
            $forbidden = @(Get-AutodevForbiddenTouches -ChangedFiles $workerChanged -ForbiddenGlobs $task.forbidden_paths)
        } else {
            $stray = @()
            $forbidden = @(Get-AutodevForbiddenTouches -ChangedFiles $allChangedRaw -ForbiddenGlobs $task.forbidden_paths)
        }
        if ($stray.Count -gt 0 -or $forbidden.Count -gt 0) {
            Move-Task -TaskId $task.id -ToDir $Config.QueueEscalated
            try {
                Invoke-Escalation -Id "dirty-$($task.id)" -Reason 'worker touched files outside file_set' -Type 'dirty-file' -Task $task `
                    -What "Stray (outside file_set): $($stray -join ', '). Forbidden-path touches: $($forbidden -join ', ')." `
                    -Decision 'Re-scope the task to own these files, or discard the stray edits?' `
                    -OptionA 'Add stray files to file_set and re-queue' -OptionB 'Discard stray edits / mark human-only' `
                    -Cost 'unowned edits persist invisibly in the shared tree and poison later gates' `
                    -Evidence ("changed files:`n" + ($allChangedRaw -join "`n"))
            } catch {
                Write-AutodevLog -Level WARN -Message "Escalation artifact write failed for dirty-$($task.id): $($_.Exception.Message)" -Config $Config
            }
            return $task
        }

        # ---- 5. DIFF + CRITIC ----
        # Real-worker runs leave the change UNCOMMITTED (gate-as-lock). Regenerate the diff
        # authoritatively from the working tree so the critic/gate judge the actual change.
        if (-not $AssumeWorkerDone) {
            New-WorkerDiff -Task $task -OutPath $diffPath
        }
        elseif (-not (Test-Path $diffPath) -or ((Get-Item $diffPath).Length -eq 0)) {
            New-WorkerDiff -Task $task -OutPath $diffPath
        }
        $criticExit = 0
        $reused = $false
        # Verdict reuse is a round-0 optimization only; a retry always re-runs a fresh critic.
        if ($round -eq 0 -and $ReuseVerdict -and (Test-Path $verdictFile)) {
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
            # A critic 429 is an EXTERNAL pause, not a failed attempt -- refund + back off,
            # symmetric with the worker rate-limit path above.
            Restore-Attempt -TaskId $task.id -Attempts $attempts
            $script:iterationRateLimited = $true
            Write-AutodevLog -Level CRITIC -Message "Critic rate-limited; returning task to pending (attempt refunded; conductor backs off)." -Config $Config
            Move-Task -TaskId $task.id -ToDir $Config.QueuePending
            return $task
        }

        if ($verdict.verdict -eq 'clean') {
            if (Test-Path $feedbackFile) { Remove-Item $feedbackFile -Force -ErrorAction SilentlyContinue }
            break   # -> 6. GATE
        }

        # ---- critic NON-clean ----
        # Contract risk is the DECLARED flag OR the ACTUAL diff touching a contract zone OR the
        # critic naming broken_contracts -- so a task whose frontmatter mislabels it as
        # non-contract still cannot have a contract-risk diff auto-retried.
        $diffTextNow = if (Test-Path $diffPath) { Get-Content $diffPath -Raw -Encoding utf8 } else { '' }
        $diffLinesNow = @(Get-GitDiffAddedRemovedLines -DiffText $diffTextNow)
        # Capture BOTH the new-side (+++ b/) and old-side (--- a/) paths, skipping /dev/null, so a
        # DELETED file in a path_glob-only contract zone is still detected as contract risk.
        $changedNow = @()
        foreach ($l in ($diffTextNow -split '\r?\n')) {
            if ($l -match '^\+\+\+ b/(.+)$' -and $Matches[1] -ne '/dev/null') { $changedNow += (ConvertTo-NormalizedPath $Matches[1]) }
            elseif ($l -match '^--- a/(.+)$' -and $Matches[1] -ne '/dev/null') { $changedNow += (ConvertTo-NormalizedPath $Matches[1]) }
        }
        $changedNow = @($changedNow | Select-Object -Unique)
        $actualZones = @(Get-AutodevTouchedZoneIds -ChangedFiles $changedNow -DiffLines $diffLinesNow -Config $Config)
        $criticNamedBreak = (($verdict.PSObject.Properties.Name -contains 'broken_contracts') -and $verdict.broken_contracts -and (@($verdict.broken_contracts).Count -gt 0))
        $contractRisk = $isContract -or ($actualZones.Count -gt 0) -or $criticNamedBreak

        # Escalate immediately when: contract-zone risk (never auto-retry a contract break),
        # retries exhausted, or no worker to re-run (-AssumeWorkerDone). Otherwise feed the
        # checker's notes back and let a FRESH worker try again.
        if ($contractRisk -or $round -ge $maxRounds -or $AssumeWorkerDone) {
            Move-Task -TaskId $task.id -ToDir $Config.QueueEscalated
            try {
                Invoke-Escalation -Id "critic-$($task.id)" -Reason "critic $($verdict.verdict)" `
                    -Type $(if ($verdict.verdict -eq 'broken') { 'disagreement' } else { 'uncertain' }) -Task $task `
                    -What "Critic verdict: $($verdict.verdict) after $($round + 1) worker round(s). $($verdict.notes)" `
                    -Decision 'Override the critic, or fix the diff?' -OptionA 'Send back to worker' -OptionB 'Override (commit anyway)' `
                    -Cost 'a real contract break could land' -Evidence ($verdict | ConvertTo-Json -Depth 5)
            } catch {
                Write-AutodevLog -Level WARN -Message "Escalation artifact write failed for critic-$($task.id): $($_.Exception.Message)" -Config $Config
            }
            return $task
        }

        # bounded retry: write the checker's notes for the next worker, then loop back to WORKER.
        $round++
        $brokenJson = if (($verdict.PSObject.Properties.Name -contains 'broken_contracts') -and $verdict.broken_contracts) { $verdict.broken_contracts | ConvertTo-Json -Depth 6 } else { '[]' }
        $fb = "Checker verdict: $($verdict.verdict).`n`nNotes:`n$($verdict.notes)`n`nBroken contracts:`n$brokenJson"
        Set-Content -Path $feedbackFile -Value $fb -Encoding utf8
        Write-AutodevLog -Level CRITIC -Message "Non-contract critic reject; worker<->critic retry $round/$maxRounds (feedback written)." -Config $Config
    }

    # 6. GATE
    & (Join-Path $here 'gate.ps1') -TaskId $task.id -FileSet $task.file_set -SkipComposer:$SkipComposer `
        -SuccessCommands $task.success_commands | Out-Null
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

    # Commit path: entered ONLY when the gate says the literal 'COMMIT'.
    # Unknown / malformed gate decisions MUST NOT fall through here (fail-closed): only the
    # literal string 'COMMIT' is accepted; everything else routes to the ESCALATE handler below
    # (including 'GARBAGE', empty string, or any future decision value).
    #
    # NOTE on guard bootstrapping: new guards are blessed by EDITING .autodev/GUARDS.md, which is
    # a constitution path -- the gate NEVER auto-commits a diff that touches it (it ESCALATEs, or
    # RETRYs first if the build is red, but never COMMITs). So a guard-writing task that includes
    # GUARDS.md is human-reviewed by construction; there is no separate auto-commit-then-bless flow
    # (the old -GuardBootstrap path was structurally unreachable for exactly that reason, removed).
    if (Test-AutodevCommitDecision -Decision $gate.decision) {
        # Commit-time branch re-check: a worker could have moved HEAD during the run. Never commit
        # off the loop branch (the startup preflight alone is not enough -- HEAD can change mid-run).
        $commitBranch = Get-AutodevCurrentBranch -Config $Config
        if (-not (Test-AutodevBranchAllowed -Branch $commitBranch -Pattern $Config.AllowedBranchPattern)) {
            Write-AutodevLog -Level ERROR -Message "Refusing to commit $($task.id): HEAD '$commitBranch' is off the loop branch ('$($Config.AllowedBranchPattern)')." -Config $Config
            Move-Task -TaskId $task.id -ToDir $Config.QueueEscalated
            try {
                Invoke-Escalation -Id "branch-$($task.id)" -Reason 'HEAD off the loop branch at commit time' -Type 'blocked' -Task $task `
                    -What "Gate said COMMIT but HEAD is '$commitBranch', not matching '$($Config.AllowedBranchPattern)'. Not committing." `
                    -Decision 'Restore the loop branch and re-queue this task?' `
                    -OptionA 'Checkout the loop branch + re-queue' -OptionB 'Investigate how HEAD moved' `
                    -Cost 'a verified commit could land on the wrong branch' -Evidence $commitBranch
            } catch {
                Write-AutodevLog -Level WARN -Message "Escalation artifact write failed for branch-$($task.id): $($_.Exception.Message)" -Config $Config
            }
            return $task
        }
        $kind = if ($task.type -eq 'guard') { 'test' } else { 'refactor' }
        $hash = Invoke-CommitTask -Task $task -Message "$kind(autodev): $($task.title)"
        Write-AutodevLog -Level GATE -Message "Committed $($task.id) as $hash." -Config $Config
        Move-Task -TaskId $task.id -ToDir $Config.QueueDone
        Add-Content -Path (Join-Path $Config.QueueDone "$($task.id).md") -Value "`n<!-- committed: $hash -->" -Encoding utf8
        # Signal to the outer loop that this iteration produced an actual commit.
        # This is the canonical commit-counter source; the outer loop MUST use this flag,
        # not done/ file existence (which can be stale from a prior run of the same task id).
        $script:iterationCommitted = $true
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

    # (5) BRANCH PREFLIGHT: the loop must run only on an allowed branch (never main).
    $c5_loop = (Test-AutodevBranchAllowed -Branch 'autodev/loop-s2' -Pattern '^autodev/')  # must be $true
    $c5_main = (Test-AutodevBranchAllowed -Branch 'main' -Pattern '^autodev/')             # must be $false
    $case5 = $c5_loop -and (-not $c5_main)
    if ($case5) { Write-Host "Case 5 PASS -- branch preflight: autodev/* allowed, main refused." -ForegroundColor Green }
    else { Write-Host "Case 5 FAIL -- branch preflight: loop=$c5_loop main=$c5_main" -ForegroundColor Red }

    # (6) DIRTY-FILE FENCE: out-of-file_set edits are stray; file_set + loop SCRATCH are not, but
    # a constitution edit (.autodev/GUARDS.md) IS caught (NOT in the scratch ignore list). A
    # file-shaped ignore ('.autodev/conductor.log') is boundary-safe: it ignores that exact file
    # but NOT '.autodev/conductor.log.bak'.
    $c6_changed = @('woodev/class-plugin.php', 'woodev/class-rogue.php', '.autodev/runtime/x/heartbeat',
                    '.autodev/queue/active/t.md', '.autodev/GUARDS.md', '.autodev/conductor.log', '.autodev/conductor.log.bak')
    $c6_ignore = @('.autodev/runtime/', '.autodev/queue/', '.autodev/escalations/', '.autodev/conductor.log', '.autodev/digest.md')
    $c6_stray = @(Get-AutodevStrayChangedFiles -ChangedFiles $c6_changed -FileSet @('woodev/class-plugin.php') -IgnorePrefixes $c6_ignore)
    # Normalization strips a leading '.' (ConvertTo-NormalizedPath) consistently on BOTH sides, so
    # match constitution/backup files on their suffix. Expect stray: class-rogue, GUARDS.md, conductor.log.bak.
    # count==3 (rogue + GUARDS.md + conductor.log.bak) proves '.autodev/conductor.log' itself was
    # correctly ignored while '.autodev/conductor.log.bak' was NOT (boundary-safe); a count of 4
    # would mean the file-prefix over-matched.
    $case6 = ($c6_stray.Count -eq 3 -and ($c6_stray -contains 'woodev/class-rogue.php') -and `
              (@($c6_stray | Where-Object { $_ -like '*GUARDS.md' }).Count -eq 1) -and `
              (@($c6_stray | Where-Object { $_ -like '*conductor.log.bak' }).Count -eq 1))
    if ($case6) { Write-Host "Case 6 PASS -- dirty-file fence: constitution caught, scratch ignored, file-prefix boundary-safe." -ForegroundColor Green }
    else { Write-Host "Case 6 FAIL -- dirty-file fence stray=$($c6_stray -join ',')" -ForegroundColor Red }

    # (7) DRIFT routing: only a DRIFT verdict warrants an escalation; ON-TRACK/UNCERTAIN/null do not.
    $case7 = (Test-AutodevDriftEscalates -DriftLine 'DRIFT: wandered off the phase intent') -and `
             (-not (Test-AutodevDriftEscalates -DriftLine 'ON-TRACK: matches intent')) -and `
             (-not (Test-AutodevDriftEscalates -DriftLine 'UNCERTAIN: could not run')) -and `
             (-not (Test-AutodevDriftEscalates -DriftLine $null))
    if ($case7) { Write-Host "Case 7 PASS -- drift routing: only DRIFT escalates." -ForegroundColor Green }
    else { Write-Host "Case 7 FAIL -- drift routing predicate wrong." -ForegroundColor Red }

    # (8) FINGERPRINT FENCE: a worker FURTHER editing a pre-existing-dirty file IS caught (its
    # fingerprint changes), a NEW file is caught, but untouched pre-existing dirt is excluded.
    $c8_base = @{ 'a.php' = 'h1'; 'b.php' = 'h2' }                          # pre-existing dirt
    $c8_cur  = @{ 'a.php' = 'h1'; 'b.php' = 'h2_EDITED'; 'c.php' = 'h3' }   # b edited, c new, a untouched
    $c8_touched = @(Get-AutodevWorkerTouchedFiles -Baseline $c8_base -Current $c8_cur)
    $case8 = ($c8_touched.Count -eq 2 -and ($c8_touched -contains 'b.php') -and ($c8_touched -contains 'c.php') -and ($c8_touched -notcontains 'a.php'))
    if ($case8) { Write-Host "Case 8 PASS -- fingerprint fence: edited pre-existing-dirty file + new file caught; untouched dirt excluded." -ForegroundColor Green }
    else { Write-Host "Case 8 FAIL -- fingerprint worker-touched=$($c8_touched -join ',')" -ForegroundColor Red }

    Write-Host "--- Conductor circuit-breaker attempt-counter self-test ---"
    Write-Host "External pauses (worker/critic 429): max breaker input = $maxBreakerInput (threshold $max); never poisons = $externalNeverPoisons"
    Write-Host "Genuine failures: breaker fires after >$max attempts = $poisoned"

    if ($externalNeverPoisons -and $poisoned -and $case3 -and $case4 -and $case5 -and $case6 -and $case7 -and $case8) {
        Write-Host "RESULT: PASS -- external pauses refunded (no false poison); genuine failures trip the breaker; fail-closed gate; escalate does not increment; branch preflight; dirty-file fence (constitution + boundary + fingerprint); drift routing." -ForegroundColor Green
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

# 0. BRANCH PREFLIGHT: the loop commits with `git commit` on whatever branch HEAD points at.
# GOAL.md says it must NEVER run on main; refuse unless HEAD matches AllowedBranchPattern.
$branch = Get-AutodevCurrentBranch -Config $Config
if (-not (Test-AutodevBranchAllowed -Branch $branch -Pattern $Config.AllowedBranchPattern)) {
    Write-AutodevLog -Level ERROR -Message "Refusing to run: branch '$branch' does not match AllowedBranchPattern '$($Config.AllowedBranchPattern)'. Check out the loop branch (e.g. autodev/loop-s2) first." -Config $Config
    exit 1
}
Write-AutodevLog -Level INFO -Message "Branch preflight OK: '$branch' matches '$($Config.AllowedBranchPattern)'." -Config $Config

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
                $driftOut = & (Join-Path $here 'anti-drift.ps1') `
                    -SinceRef "HEAD~$driftWindow" `
                    -CommitsSinceLast $driftWindow
                # A DRIFT verdict is NOT advisory -- escalate it so the operator sees the loop
                # has wandered off the phase intent. ON-TRACK/UNCERTAIN stay in the digest only.
                $driftMatches = @($driftOut | Where-Object { $_ -match '(?im)^\s*(ON-TRACK|DRIFT|UNCERTAIN):' })
                $driftLine = if ($driftMatches.Count -gt 0) { "$($driftMatches[-1])".Trim() } else { '' }
                if (Test-AutodevDriftEscalates -DriftLine $driftLine) {
                    $driftId = 'drift-' + (Get-Date).ToString('yyyyMMdd-HHmmss')
                    try {
                        & (Join-Path $here 'escalate.ps1') -Id $driftId -Reason 'anti-drift flagged DRIFT' -Type 'drift' `
                            -Title 'program drift detected' -What $driftLine `
                            -Decision 'Is the recent autodev work still on-plan?' `
                            -OptionA 'On-plan -- continue' -OptionB 'Off-plan -- pause loop and re-scope tasks' `
                            -CostOfWrong 'autonomous work compounds in the wrong direction' -Evidence $driftLine | Out-Null
                        Write-AutodevLog -Level ESCALATE -Message "Anti-drift DRIFT -> escalated ($driftId)." -Config $Config
                    } catch {
                        Write-AutodevLog -Level WARN -Message "Drift escalation write failed (non-fatal): $($_.Exception.Message)" -Config $Config
                    }
                }
            } catch {
                Write-AutodevLog -Level WARN -Message "Anti-drift run failed (non-fatal): $($_.Exception.Message)" -Config $Config
            }
        }
    }

    if ($Once) { break }
    if ($MaxIterations -gt 0 -and $iterations -ge $MaxIterations) { break }
    # Rate-limit backoff: a worker/critic 429 returns a (non-null) task, so the idle-sleep below
    # would NOT fire and the loop would immediately re-claim and re-hit the limit (busy-loop).
    # Sleep RateLimitBackoffSeconds instead, giving the quota window time to reset.
    if ($script:iterationRateLimited) {
        Write-AutodevLog -Level WARN -Message "Rate-limit backoff: sleeping $($Config.RateLimitBackoffSeconds)s before next claim." -Config $Config
        Start-Sleep -Seconds $Config.RateLimitBackoffSeconds
    } elseif ($null -eq $did) {
        Start-Sleep -Seconds $SleepSeconds
    }
}
Write-AutodevLog -Message "Conductor stopped after $iterations iteration(s)." -Config $Config
