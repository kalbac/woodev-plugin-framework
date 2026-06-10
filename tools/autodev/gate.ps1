<#
.SYNOPSIS
  Machine gate: composer check + INVARIANTS grep + (per-guarded-zone) mutation-check.

.DESCRIPTION
  The real lock. Given a change (working tree or a commit range), it produces a verdict:
    - ComposerGreen        : did `composer check` pass?
    - ConstitutionTouched  : changed files matching a constitution path (ALWAYS human)
    - ZonesTouched         : contract zones the diff touches (path/grep/exact-string)
    - For each touched zone: is it auto_guardable? is there a BLESSED, mutation-verified
      guard covering it? does that guard's mutation-recipe still go RED on flip?
    - Decision             : COMMIT | ESCALATE | RETRY  (+ Reasons[])

  The gate makes NO judgment beyond these mechanical checks. "COMMIT" means every
  mechanical condition for autonomy is satisfied; anything else routes to the human.

  A guard whose `blessed_by` is still `pending-operator` does NOT grant autonomy: the
  operator must bless it once (via escalation) before its zone becomes autonomous.

.PARAMETER TaskId
  Task id (used to locate runtime dir for writing the verdict). Optional.

.PARAMETER Range
  Git range to diff (e.g. HEAD~1..HEAD). Default: working tree vs HEAD.

.PARAMETER SkipComposer
  Skip `composer check` (for fast structural dry-runs). Decision then ignores tests.

.PARAMETER ComposerSubcommand
  composer subcommand to run as the test gate (default: check).
#>
[CmdletBinding()]
param(
    [string]$TaskId,
    [string]$Range,
    [string[]]$FileSet,
    [switch]$SkipComposer,
    [string]$ComposerSubcommand = 'check'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path (Split-Path -Parent $PSCommandPath) '_common.ps1')

function Get-AutodevGuards {
    <# Parse the GUARDS.md markdown table into objects. #>
    param([pscustomobject]$Config = (Get-AutodevConfig))
    $guards = @()
    $lines = Get-Content -Path $Config.Guards -Encoding utf8
    foreach ($line in $lines) {
        if ($line -notmatch '^\s*\|') { continue }
        $cells = ($line -split '\|') | ForEach-Object { $_.Trim() }
        # cells[0] is empty (leading pipe). Expect: id,value,test,recipe,verified,blessed,date
        if ($cells.Count -lt 8) { continue }
        $id = $cells[1]
        if ($id -eq 'contract_id' -or $id -match '^-+$') { continue }  # header / separator
        $guards += [pscustomobject]@{
            contract_id       = $id
            contract_value    = ($cells[2] -replace '`', '')
            guard_test        = ($cells[3] -replace '`', '')
            recipe            = ($cells[4] -replace '`', '')
            mutation_verified = $cells[5]
            blessed_by        = $cells[6]
            date              = $cells[7]
        }
    }
    return $guards
}

function Invoke-AutodevGate {
    param(
        [string]$TaskId,
        [string]$Range,
        [string[]]$FileSet,
        [switch]$SkipComposer,
        [string]$ComposerSubcommand = 'check',
        [pscustomobject]$Config = (Get-AutodevConfig)
    )
    # Empty/missing file_set: no files are owned by this task, so there is nothing safe to
    # judge or commit. Escalate immediately -- checked BEFORE loading invariants/guards so
    # that a load failure (missing INVARIANTS.md) cannot prevent writing the gate-verdict.json
    # and cannot prevent the conductor from routing to escalation.
    if (-not $Range -and (-not $FileSet -or $FileSet.Count -eq 0)) {
        $emptyVerdict = [pscustomobject]@{
            task_id              = $TaskId
            composer_green       = $false
            constitution_touched = @()
            zones_touched        = @()
            decision             = 'ESCALATE'
            reasons              = @('empty file_set -- nothing can be safely judged')
            changed_files        = @()
        }
        if ($TaskId) {
            $rtDir = Join-Path $Config.Runtime $TaskId
            if (-not (Test-Path $rtDir)) { New-Item -ItemType Directory -Path $rtDir -Force | Out-Null }
            $emptyVerdict | ConvertTo-Json -Depth 6 | Set-Content -Path (Join-Path $rtDir 'gate-verdict.json') -Encoding utf8
        }
        Write-AutodevLog -Level GATE -Message "Task $TaskId has empty file_set; escalating." -Config $Config
        return $emptyVerdict
    }

    $inv = Get-AutodevInvariants -Config $Config
    $guards = Get-AutodevGuards -Config $Config

    if ($Range) {
        $changedFiles = Get-GitChangedFiles -Range $Range -Config $Config
        $diffText = Get-GitDiffText -Range $Range -Config $Config
    } elseif ($FileSet -and $FileSet.Count -gt 0) {
        # Scope zone + constitution detection to the TASK'S OWN file_set only. The whole-tree
        # default lets a parked task's uncommitted files contaminate this task's verdict
        # (observed 2026-06-06: a warehouse-store dbDelta leaked db_schema into pickup-selection;
        # earlier a Warehouse $this->id= leaked gateway_id into checkout-fields). The conductor
        # commits only the file_set, so judging only the file_set is exactly what would land.
        # NOTE: composer check (below) still runs over the whole tree -- it MUST validate the
        # whole codebase compiles/passes, that is not a per-task contract-zone question.
        $changedFiles = Get-GitFileSetChangedFiles -FileSet $FileSet -Config $Config
        $diffText = Get-GitFileSetDiffText -FileSet $FileSet -Config $Config
    } else {
        $changedFiles = Get-GitChangedFiles -Config $Config
        $diffText = Get-GitDiffText -Config $Config
    }
    $diffLines = Get-GitDiffAddedRemovedLines -DiffText $diffText

    $reasons = @()

    # 1. composer check
    $composerGreen = $true
    if (-not $SkipComposer) {
        Write-AutodevLog -Level GATE -Message "Running composer $ComposerSubcommand ..." -Config $Config
        $cc = Invoke-ComposerCheck -Subcommand $ComposerSubcommand -Config $Config
        $composerGreen = $cc.Green
        if (-not $composerGreen) { $reasons += "composer $ComposerSubcommand FAILED (exit $($cc.ExitCode))" }
    }

    # 2. constitution (always human)
    $constitutionTouched = @()
    foreach ($f in $changedFiles) {
        if (Test-PathMatchesAnyGlob -Path $f -Globs $inv.constitution.path_globs) { $constitutionTouched += $f }
    }
    if ($constitutionTouched.Count -gt 0) {
        $reasons += "constitution path(s) changed: $($constitutionTouched -join ', ')"
    }

    # 3. contract zones
    $zonesTouched = @()
    foreach ($zone in $inv.contract_zones) {
        if (-not (Test-ZoneTouched -Zone $zone -ChangedFiles $changedFiles -DiffLines $diffLines)) { continue }

        $zoneResult = [ordered]@{
            id = $zone.id; auto_guardable = [bool]$zone.auto_guardable
            guarded = $false; guard_test = $null; mutation_passed = $false; blessed = $false
        }
        if (-not $zone.auto_guardable) {
            $reasons += "zone '$($zone.id)' touched but is NOT auto_guardable (human-only: $($zone.why))"
            $zonesTouched += [pscustomobject]$zoneResult
            continue
        }
        # find a blessed, mutation-verified guard whose recipe targets this zone
        $cover = $null
        foreach ($g in $guards) {
            if ($g.mutation_verified -notmatch '^yes') { continue }
            $recipePath = Join-Path $Config.RepoRoot $g.recipe
            if (-not (Test-Path $recipePath)) { continue }
            $recipe = Get-Content $recipePath -Raw -Encoding utf8 | ConvertFrom-Json
            $rZone = if ($recipe.PSObject.Properties.Name -contains 'zone_id') { $recipe.zone_id } else { $null }
            if ($rZone -eq $zone.id) { $cover = $g; break }
        }
        if ($null -eq $cover) {
            $reasons += "zone '$($zone.id)' touched but no mutation-verified guard covers it (needs guard)"
            $zonesTouched += [pscustomobject]$zoneResult
            continue
        }
        $zoneResult.guarded = $true
        $zoneResult.guard_test = $cover.guard_test
        $zoneResult.blessed = ($cover.blessed_by -ne 'pending-operator' -and $cover.blessed_by -ne '')
        # re-verify the guard still protects (mutation-check) unless caller skipped
        $mc = & (Join-Path (Split-Path -Parent $PSCommandPath) 'mutation-check.ps1') `
                 -RecipePath (Join-Path $Config.RepoRoot $cover.recipe) -Quiet
        $zoneResult.mutation_passed = ($LASTEXITCODE -eq 0)
        if (-not $zoneResult.mutation_passed) {
            $reasons += "guard for zone '$($zone.id)' did NOT go red on mutation (guard not protecting)"
        } elseif (-not $zoneResult.blessed) {
            $reasons += "zone '$($zone.id)' guarded + mutation-proven but guard not yet blessed by operator"
        }
        $zonesTouched += [pscustomobject]$zoneResult
    }

    # 4. decision
    $decision = 'COMMIT'
    if (-not $composerGreen) { $decision = 'RETRY' }
    elseif ($constitutionTouched.Count -gt 0) { $decision = 'ESCALATE' }
    else {
        foreach ($z in $zonesTouched) {
            if (-not $z.auto_guardable) { $decision = 'ESCALATE'; break }
            if (-not $z.guarded)        { $decision = 'ESCALATE'; break }
            if (-not $z.mutation_passed) { $decision = 'ESCALATE'; break }
            if (-not $z.blessed)         { $decision = 'ESCALATE'; break }
        }
    }

    $verdict = [pscustomobject]@{
        task_id              = $TaskId
        composer_green       = $composerGreen
        constitution_touched = $constitutionTouched
        zones_touched        = $zonesTouched
        decision             = $decision
        reasons              = $reasons
        changed_files        = $changedFiles
    }

    if ($TaskId) {
        $rtDir = Join-Path $Config.Runtime $TaskId
        if (-not (Test-Path $rtDir)) { New-Item -ItemType Directory -Path $rtDir -Force | Out-Null }
        $verdict | ConvertTo-Json -Depth 6 | Set-Content -Path (Join-Path $rtDir 'gate-verdict.json') -Encoding utf8
    }
    return $verdict
}

# Entry point
$v = Invoke-AutodevGate -TaskId $TaskId -Range $Range -FileSet $FileSet -SkipComposer:$SkipComposer -ComposerSubcommand $ComposerSubcommand
$v | ConvertTo-Json -Depth 6
switch ($v.decision) {
    'COMMIT'   { exit 0 }
    'RETRY'    { exit 2 }
    default    { exit 3 }   # ESCALATE
}
