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
    param([pscustomobject]$Config = (Get-AutodevConfig))
    $sets = @()
    if (-not (Test-Path $Config.QueueActive)) { return $sets }
    foreach ($f in Get-ChildItem -Path $Config.QueueActive -Filter '*.md' -File) {
        try {
            $t = ConvertFrom-AutodevTask -Path $f.FullName
            $sets += , @($t.file_set)
        } catch {
            Write-AutodevLog -Level WARN -Message "Active task $($f.Name) unparseable: $($_.Exception.Message)"
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

        if (-not (Test-TaskClaimable -Task $task -ActiveSets $activeSets)) {
            Write-AutodevLog -Message "Task $($task.id) blocked: file_set intersects an active task (serialized)."
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
        $task = ConvertFrom-AutodevTask -Path $file.FullName
        $claimable = Test-TaskClaimable -Task $task -ActiveSets $activeSets
        $blockedBy = @()
        if (-not $claimable) {
            foreach ($file2 in Get-ChildItem -Path $Config.QueueActive -Filter '*.md' -File) {
                $at = ConvertFrom-AutodevTask -Path $file2.FullName
                if (-not (Test-FileSetsDisjoint -A $task.file_set -B $at.file_set)) { $blockedBy += $at.id }
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
    $pending = Join-Path $tmp 'queue\pending'
    $active  = Join-Path $tmp 'queue\active'
    New-Item -ItemType Directory -Path $pending -Force | Out-Null
    New-Item -ItemType Directory -Path $active  -Force | Out-Null
    $testCfg = $cfg.PSObject.Copy()
    $testCfg.QueuePending = $pending
    $testCfg.QueueActive  = $active

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

    $report = Show-ClaimableReport -Config $testCfg
    $b = $report | Where-Object { $_.id -eq 'taskB' }
    $c = $report | Where-Object { $_.id -eq 'taskC' }

    $pass = ($b.claimable -eq $false) -and ($b.blocked_by -eq 'taskA') -and ($c.claimable -eq $true)

    Write-Host "--- Scheduler file-set serialization self-test ---"
    $report | Format-Table -AutoSize | Out-String | Write-Host
    Write-Host "Expected: taskB blocked by taskA (overlap on class-plugin.php); taskC claimable (disjoint)."

    # Also prove the atomic claim actually serializes: claiming must take C, never B.
    $claimed = Invoke-ClaimNextTask -Config $testCfg
    $claimedId = if ($null -ne $claimed) { $claimed.id } else { '<none>' }
    $claimedDisjoint = ($claimedId -eq 'taskC')
    Write-Host "Claimed task id: $claimedId (expected taskC)"

    Remove-Item -Path $tmp -Recurse -Force -ErrorAction SilentlyContinue

    if ($pass -and $claimedDisjoint) {
        Write-Host "RESULT: PASS -- intersecting file_sets are serialized; disjoint task claimed." -ForegroundColor Green
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
