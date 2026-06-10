<#
.SYNOPSIS
  Claim the next queue task whose file_set is disjoint from every active task.

.DESCRIPTION
  The scheduler is the file-set lock. It enforces the runbook rule:
  "serialize any two tasks whose file_sets intersect -- they must never run in parallel
  worktrees, or the worktrees diverge and integration conflicts." (Exactly how P4's
  tasks 2-4, all editing class-plugin.php, would collide.)

  Claiming is atomic: Move-Item pending\<id> -> active\<id>. If the move throws, another
  iteration already claimed it -- we skip. A task whose file_set intersects any currently
  active task is left in pending (serialized), not claimed.

  No LLM. No judgment. Pure file moves + set intersection.

.PARAMETER ClaimOne
  Claim and return ONE disjoint task (atomic). Prints the claimed task id, or nothing.

.PARAMETER ListClaimable
  Dry-run: list which pending tasks are currently claimable vs blocked (and by what),
  without moving anything. Used by the 2-task overlap self-test.

.PARAMETER SelfTest
  Run the file-set serialization self-test (acceptance criterion: 2-task overlap case).
#>
[CmdletBinding(DefaultParameterSetName = 'ClaimOne')]
param(
    [Parameter(ParameterSetName = 'ClaimOne')][switch]$ClaimOne,
    [Parameter(ParameterSetName = 'ListClaimable')][switch]$ListClaimable,
    [Parameter(ParameterSetName = 'SelfTest')][switch]$SelfTest
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path (Split-Path -Parent $PSCommandPath) '_common.ps1')

function Get-AutodevActiveFileSets {
    <# Collects file_sets from BOTH active/ and escalated/ so that escalated tasks block
       intersecting pending tasks exactly like active ones (serialization preserved). #>
    param([pscustomobject]$Config = (Get-AutodevConfig))
    $sets = @()
    $dirs = @($Config.QueueActive)
    if ($Config.PSObject.Properties.Name -contains 'QueueEscalated' -and (Test-Path $Config.QueueEscalated)) {
        $dirs += $Config.QueueEscalated
    }
    foreach ($dir in $dirs) {
        if (-not (Test-Path $dir)) { continue }
        foreach ($f in Get-ChildItem -Path $dir -Filter '*.md' -File) {
            try {
                $t = ConvertFrom-AutodevTask -Path $f.FullName
                $sets += , @($t.file_set)
            } catch {
                Write-AutodevLog -Level WARN -Message "Task $($f.Name) unparseable: $($_.Exception.Message)"
            }
        }
    }
    return $sets
}

function Test-TaskClaimable {
    <# True if Task's file_set is disjoint from every active task's file_set. #>
    param([pscustomobject]$Task, [array]$ActiveSets)
    foreach ($activeSet in $ActiveSets) {
        if (-not (Test-FileSetsDisjoint -A $Task.file_set -B $activeSet)) { return $false }
    }
    return $true
}

function Get-AutodevTaskDependencies {
    <#
      depends_on may be absent (StrictMode -> reach it via PSObject), a block list
      (parsed as an array), or an inline array string ("[]" / "[a, b]"). Normalize all
      forms to a clean string[] of ids.
    #>
    param([pscustomobject]$Task)
    $p = $Task.PSObject.Properties['depends_on']
    if (-not $p -or $null -eq $p.Value) { return @() }
    $raw = $p.Value
    if ($raw -is [array]) {
        $items = $raw
    } else {
        $s = "$raw".Trim()
        if ($s.StartsWith('[') -and $s.EndsWith(']')) { $s = $s.Substring(1, [Math]::Max(0, $s.Length - 2)) }
        $items = $s -split ','
    }
    return @($items | ForEach-Object { "$_".Trim().Trim('"').Trim("'") } | Where-Object { $_ -ne '' })
}

function Test-DependenciesMet {
    <#
      True if every id in the task's depends_on has a matching file in done/. Tasks are
      ordered by a dependency DAG, not just alphabetically: claiming a task whose deps are
      still pending wastes a worker run rediscovering "BLOCKED" that the queue already states.
    #>
    param([pscustomobject]$Task, [pscustomobject]$Config = (Get-AutodevConfig))
    foreach ($dep in (Get-AutodevTaskDependencies -Task $Task)) {
        if (-not (Test-Path (Join-Path $Config.QueueDone "$dep.md"))) { return $false }
    }
    return $true
}

function Get-AutodevPendingTasks {
    param([pscustomobject]$Config = (Get-AutodevConfig))
    if (-not (Test-Path $Config.QueuePending)) { return @() }
    # Deterministic order: by name (callers can prefix ids with a priority number).
    return @(Get-ChildItem -Path $Config.QueuePending -Filter '*.md' -File | Sort-Object Name)
}

function Invoke-ClaimNextTask {
    <#
      Returns the claimed task object (moved to active/) or null if nothing claimable.
      Atomic: the Move-Item is the lock. Intersecting-file_set tasks are skipped.
    #>
    param([pscustomobject]$Config = (Get-AutodevConfig))
    Initialize-AutodevDirectories -Config $Config
    $activeSets = Get-AutodevActiveFileSets -Config $Config
    foreach ($file in Get-AutodevPendingTasks -Config $Config) {
        $task = $null
        try { $task = ConvertFrom-AutodevTask -Path $file.FullName }
        catch { Write-AutodevLog -Level WARN -Message "Skipping unparseable pending task $($file.Name)"; continue }

        if (-not (Test-DependenciesMet -Task $task -Config $Config)) {
            Write-AutodevLog -Message "Task $($task.id) not ready: depends_on not all in done/ (dependency-gated)."
            continue
        }

        if (-not (Test-TaskClaimable -Task $task -ActiveSets $activeSets)) {
            Write-AutodevLog -Message "Task $($task.id) blocked: file_set intersects an active or escalated task (serialized)."
            continue
        }

        $dest = Join-Path $Config.QueueActive $file.Name
        try {
            Move-Item -Path $file.FullName -Destination $dest -ErrorAction Stop
        } catch {
            # Lost the race: another iteration claimed it. Not an error.
            Write-AutodevLog -Message "Task $($task.id) claimed by another iteration; skipping."
            continue
        }
        Write-AutodevLog -Message "Claimed task $($task.id) -> active/."
        return (ConvertFrom-AutodevTask -Path $dest)
    }
    return $null
}

function Show-ClaimableReport {
    param([pscustomobject]$Config = (Get-AutodevConfig))
    $activeSets = Get-AutodevActiveFileSets -Config $Config
    $report = @()
    foreach ($file in Get-AutodevPendingTasks -Config $Config) {
        $task = $null
        try { $task = ConvertFrom-AutodevTask -Path $file.FullName }
        catch { Write-AutodevLog -Level WARN -Message "Skipping unparseable pending task in report $($file.Name): $($_.Exception.Message)"; continue }

        $depsMet = Test-DependenciesMet -Task $task -Config $Config
        $fileDisjoint = Test-TaskClaimable -Task $task -ActiveSets $activeSets
        $claimable = $depsMet -and $fileDisjoint
        $blockedBy = @()
        if (-not $fileDisjoint) {
            foreach ($file2 in Get-ChildItem -Path $Config.QueueActive -Filter '*.md' -File) {
                try {
                    $at = ConvertFrom-AutodevTask -Path $file2.FullName
                    if (-not (Test-FileSetsDisjoint -A $task.file_set -B $at.file_set)) { $blockedBy += "active:$($at.id)" }
                } catch { Write-AutodevLog -Level WARN -Message "Skipping unparseable active task in report $($file2.Name): $($_.Exception.Message)" }
            }
            if ($Config.PSObject.Properties.Name -contains 'QueueEscalated' -and (Test-Path $Config.QueueEscalated)) {
                foreach ($file2 in Get-ChildItem -Path $Config.QueueEscalated -Filter '*.md' -File) {
                    try {
                        $at = ConvertFrom-AutodevTask -Path $file2.FullName
                        if (-not (Test-FileSetsDisjoint -A $task.file_set -B $at.file_set)) { $blockedBy += "escalated:$($at.id)" }
                    } catch { Write-AutodevLog -Level WARN -Message "Skipping unparseable escalated task in report $($file2.Name): $($_.Exception.Message)" }
                }
            }
        }
        if (-not $depsMet) {
            foreach ($dep in (Get-AutodevTaskDependencies -Task $task)) {
                if (-not (Test-Path (Join-Path $Config.QueueDone "$dep.md"))) { $blockedBy += "dep:$dep" }
            }
        }
        $report += [pscustomobject]@{
            id = $task.id; claimable = $claimable; blocked_by = ($blockedBy -join ',')
        }
    }
    return $report
}

# --------------------------------------------------------------------------------------
# Self-test: 2-task overlap case (acceptance criterion)
# --------------------------------------------------------------------------------------
function Invoke-SchedulerSelfTest {
    $cfg = Get-AutodevConfig
    $tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("autodev-sched-" + [guid]::NewGuid().ToString('N'))
    $pending   = Join-Path $tmp 'queue\pending'
    $active    = Join-Path $tmp 'queue\active'
    $done      = Join-Path $tmp 'queue\done'
    $escalated = Join-Path $tmp 'queue\escalated'
    New-Item -ItemType Directory -Path $pending   -Force | Out-Null
    New-Item -ItemType Directory -Path $active    -Force | Out-Null
    New-Item -ItemType Directory -Path $done      -Force | Out-Null
    New-Item -ItemType Directory -Path $escalated -Force | Out-Null
    $testCfg = $cfg.PSObject.Copy()
    $testCfg.QueuePending   = $pending
    $testCfg.QueueActive    = $active
    $testCfg.QueueDone      = $done
    $testCfg.QueueEscalated = $escalated

    # ACTIVE task A holds class-plugin.php.
    $taskA = @('---', 'id: taskA', 'title: Active task editing class-plugin.php',
               'file_set:', '  - woodev/class-plugin.php', '---')
    Set-Content -Path (Join-Path $active 'taskA.md') -Encoding utf8 -Value $taskA

    # PENDING task B also edits class-plugin.php  => MUST be blocked (intersects A).
    $taskB = @('---', 'id: taskB', 'title: Pending task ALSO editing class-plugin.php (overlap)',
               'file_set:', '  - woodev/class-plugin.php', '  - woodev/class-helper.php', '---')
    Set-Content -Path (Join-Path $pending 'taskB.md') -Encoding utf8 -Value $taskB

    # PENDING task C edits unrelated files => MUST be claimable (disjoint from A).
    $taskC = @('---', 'id: taskC', 'title: Pending task editing an unrelated file (disjoint)',
               'file_set:', '  - woodev/class-lifecycle.php', '---')
    Set-Content -Path (Join-Path $pending 'taskC.md') -Encoding utf8 -Value $taskC

    # DONE task depDone exists -> a dependency on it is satisfied.
    Set-Content -Path (Join-Path $done 'depDone.md') -Encoding utf8 -Value @('---', 'id: depDone', 'file_set:', '  - woodev/class-done.php', '---')

    # PENDING task D: disjoint, depends on depDone (in done/) => MUST be claimable.
    $taskD = @('---', 'id: taskD', 'title: Pending task, dependency satisfied',
               'file_set:', '  - woodev/class-d.php', 'depends_on:', '  - depDone', '---')
    Set-Content -Path (Join-Path $pending 'taskD.md') -Encoding utf8 -Value $taskD

    # PENDING task E: disjoint, depends on depMissing (NOT in done/) => MUST be dependency-gated.
    $taskE = @('---', 'id: taskE', 'title: Pending task, dependency unmet',
               'file_set:', '  - woodev/class-e.php', 'depends_on:', '  - depMissing', '---')
    Set-Content -Path (Join-Path $pending 'taskE.md') -Encoding utf8 -Value $taskE

    # ESCALATED task F holds class-lifecycle.php (same as C) => escalated must block pending G.
    $taskF = @('---', 'id: taskF', 'title: Escalated task holding class-lifecycle.php',
               'file_set:', '  - woodev/class-lifecycle.php', '---')
    Set-Content -Path (Join-Path $escalated 'taskF.md') -Encoding utf8 -Value $taskF

    # PENDING task G: intersects escalated taskF => MUST be blocked (escalated acts as active).
    $taskG = @('---', 'id: taskG', 'title: Pending task blocked by escalated taskF',
               'file_set:', '  - woodev/class-lifecycle.php', '---')
    Set-Content -Path (Join-Path $pending 'taskG.md') -Encoding utf8 -Value $taskG

    $report = Show-ClaimableReport -Config $testCfg
    $b = $report | Where-Object { $_.id -eq 'taskB' }
    $c = $report | Where-Object { $_.id -eq 'taskC' }
    $d = $report | Where-Object { $_.id -eq 'taskD' }
    $e = $report | Where-Object { $_.id -eq 'taskE' }
    $g = $report | Where-Object { $_.id -eq 'taskG' }

    $pass = ($b.claimable -eq $false) -and ($b.blocked_by -eq 'active:taskA') -and `
            ($c.claimable -eq $false) -and ($c.blocked_by -eq 'escalated:taskF') -and `
            ($d.claimable -eq $true) -and `
            ($e.claimable -eq $false) -and ($e.blocked_by -eq 'dep:depMissing') -and `
            ($g.claimable -eq $false) -and ($g.blocked_by -eq 'escalated:taskF')

    Write-Host "--- Scheduler serialization + dependency self-test ---"
    $report | Format-Table -AutoSize | Out-String | Write-Host
    Write-Host "Expected: B blocked by active:taskA; C blocked by escalated:taskF; D claimable (dep depDone in done/); E gated by dep:depMissing; G blocked by escalated:taskF."

    # Also prove the atomic claim actually serializes: claiming must take D (C and G are blocked by escalated F).
    $claimed = Invoke-ClaimNextTask -Config $testCfg
    $claimedId = if ($null -ne $claimed) { $claimed.id } else { '<none>' }
    $claimedDisjoint = ($claimedId -eq 'taskD')
    Write-Host "Claimed task id: $claimedId (expected taskD -- C blocked by escalated:taskF)"

    Remove-Item -Path $tmp -Recurse -Force -ErrorAction SilentlyContinue

    if ($pass -and $claimedDisjoint) {
        Write-Host "RESULT: PASS -- intersecting file_sets serialized; escalated tasks block pending; disjoint task claimed." -ForegroundColor Green
        return 0
    } else {
        Write-Host "RESULT: FAIL" -ForegroundColor Red
        return 1
    }
}

# --------------------------------------------------------------------------------------
# Entry point
# --------------------------------------------------------------------------------------
switch ($PSCmdlet.ParameterSetName) {
    'SelfTest'      { exit (Invoke-SchedulerSelfTest) }
    'ListClaimable' { Show-ClaimableReport | Format-Table -AutoSize; break }
    default {
        $t = Invoke-ClaimNextTask
        if ($null -ne $t) { Write-Output $t.id }
    }
}
