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
    [string]$ComposerSubcommand = 'check',
    [string[]]$SuccessCommands = @(),
    [switch]$SelfTest
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

function Get-AutodevGuardRecipePairs {
    <#
      Load each mutation-verified guard together with its parsed recipe object, once. Returns
      @( @{ guard=<guard>; recipe=<recipe obj> } ). Guards whose recipe file is missing or
      whose mutation_verified is not 'yes...' are skipped (they cannot grant autonomy).
      Reading recipes here lets the PURE selectors below operate on in-memory objects, so the
      guard-coverage logic is unit-testable without touching disk.
    #>
    param([array]$Guards, [pscustomobject]$Config = (Get-AutodevConfig))
    $pairs = @()
    foreach ($g in $Guards) {
        if ($g.mutation_verified -notmatch '^yes') { continue }
        $recipePath = Join-Path $Config.RepoRoot $g.recipe
        if (-not (Test-Path $recipePath)) { continue }
        try { $recipe = Get-Content $recipePath -Raw -Encoding utf8 | ConvertFrom-Json } catch { continue }
        $pairs += @{ guard = $g; recipe = $recipe }
    }
    return $pairs
}

function Select-AutodevGuardForValue {
    <#
      PURE (unit-tested): return the guard whose recipe's canonical_value EQUALS the touched
      contract value, or $null. This is the fix for the zone-level over-coverage bug: a guard
      may only cover the EXACT value it mutation-proves, never every value in its zone.
    #>
    param([array]$Pairs, [string]$Value)
    foreach ($p in $Pairs) {
        $cv = if ($p.recipe.PSObject.Properties.Name -contains 'canonical_value') { $p.recipe.canonical_value } else { $null }
        if ($cv -eq $Value) { return $p.guard }
    }
    return $null
}

function Select-AutodevGuardForZone {
    <#
      PURE (unit-tested): legacy zone-level match (recipe.zone_id == zone id). Used ONLY as the
      fallback when a zone is touched via path_glob/grep_pattern with NO enumerated contract
      string in the diff -- a weaker proxy that preserves existing autonomy for sensitive-area
      edits. The per-string path above is what closes the real over-coverage hole.
    #>
    param([array]$Pairs, [string]$ZoneId)
    foreach ($p in $Pairs) {
        $zid = if ($p.recipe.PSObject.Properties.Name -contains 'zone_id') { $p.recipe.zone_id } else { $null }
        if ($zid -eq $ZoneId) { return $p.guard }
    }
    return $null
}

function Test-AutodevGuardBlessed {
    <# PURE: a guard grants autonomy only once an operator (not 'pending-operator'/'') blessed it. #>
    param([pscustomobject]$Guard)
    return ($Guard.blessed_by -ne 'pending-operator' -and $Guard.blessed_by -ne '')
}

function Test-AutodevGuardStillRed {
    <# Side-effecting: run mutation-check for a guard's recipe; $true iff it still goes red-on-flip. #>
    param([pscustomobject]$Guard, [pscustomobject]$Config = (Get-AutodevConfig))
    & (Join-Path (Split-Path -Parent $PSCommandPath) 'mutation-check.ps1') `
        -RecipePath (Join-Path $Config.RepoRoot $Guard.recipe) -Quiet | Out-Null
    return ($LASTEXITCODE -eq 0)
}

function Invoke-AutodevGate {
    param(
        [string]$TaskId,
        [string]$Range,
        [string[]]$FileSet,
        [switch]$SkipComposer,
        [string]$ComposerSubcommand = 'check',
        [string[]]$SuccessCommands = @(),
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
    $guardPairs = Get-AutodevGuardRecipePairs -Guards $guards -Config $Config

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

    # 1b. task acceptance commands (operator-authored). Each MUST exit 0 to COMMIT. A failure is
    # worker-fixable, so -- like a composer failure -- it yields RETRY, not ESCALATE. This makes
    # a task's success criteria machine-checkable (yardstick task-contract requirement) instead
    # of "touched the right files" being treated as done.
    $successGreen = $true
    foreach ($cmd in $SuccessCommands) {
        if (-not $cmd -or "$cmd".Trim() -eq '') { continue }
        Write-AutodevLog -Level GATE -Message "Running success_command: $cmd" -Config $Config
        $sc = Invoke-Native -Exe 'cmd' -CommandArgs @('/c', "$cmd") -WorkingDirectory $Config.RepoRoot -Merge
        if ($sc.ExitCode -ne 0) {
            $successGreen = $false
            $reasons += "success_command FAILED (exit $($sc.ExitCode)): $cmd"
        }
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
    #
    # Coverage is per CONTRACT VALUE, not per zone. A zone (e.g. 'option_keys') bundles many
    # distinct enumerated strings; a single guard proves exactly ONE value and must NOT bless
    # the others. So: find WHICH enumerated strings the diff actually touches, and require a
    # guard for EACH of them. Only when the diff touches the zone via path_glob/grep_pattern
    # with NO enumerated string present do we fall back to the legacy zone-level guard (a weaker
    # proxy that keeps existing autonomy for sensitive-area edits touching no known contract).
    $zonesTouched = @()
    foreach ($zone in $inv.contract_zones) {
        if (-not (Test-ZoneTouched -Zone $zone -ChangedFiles $changedFiles -DiffLines $diffLines)) { continue }

        $zoneResult = [ordered]@{
            id = $zone.id; auto_guardable = [bool]$zone.auto_guardable
            guarded = $false; guard_test = $null; mutation_passed = $false; blessed = $false
            touched_strings = @(); uncovered_strings = @()
        }
        if (-not $zone.auto_guardable) {
            $reasons += "zone '$($zone.id)' touched but is NOT auto_guardable (human-only: $($zone.why))"
            $zonesTouched += [pscustomobject]$zoneResult
            continue
        }

        $touchedStrings = @(Get-AutodevZoneTouchedStrings -Zone $zone -DiffLines $diffLines)
        $zoneResult.touched_strings = $touchedStrings

        if ($touchedStrings.Count -gt 0) {
            # ---- PER-VALUE coverage: every touched enumerated value needs its OWN guard ----
            $guardsUsed = @()
            $allCovered = $true
            foreach ($s in $touchedStrings) {
                $g = Select-AutodevGuardForValue -Pairs $guardPairs -Value $s
                if ($null -eq $g) {
                    $allCovered = $false
                    $zoneResult.uncovered_strings += $s
                    $reasons += "zone '$($zone.id)': contract value '$s' touched but NO mutation-verified guard covers THAT value (needs guard)"
                } else {
                    $guardsUsed += $g
                }
            }
            if (-not $allCovered) {
                $zonesTouched += [pscustomobject]$zoneResult   # guarded stays $false -> ESCALATE
                continue
            }
            # All touched values have a guard: require EVERY one blessed + still-red-on-flip.
            $zoneResult.guarded = $true
            $zoneResult.guard_test = (($guardsUsed | ForEach-Object { $_.guard_test }) -join ', ')
            $blessedAll = $true; $mutAll = $true
            foreach ($g in $guardsUsed) {
                if (-not (Test-AutodevGuardBlessed -Guard $g)) {
                    $blessedAll = $false
                    $reasons += "zone '$($zone.id)': guard '$($g.contract_id)' mutation-proven but not yet blessed by operator"
                }
                if (-not (Test-AutodevGuardStillRed -Guard $g -Config $Config)) {
                    $mutAll = $false
                    $reasons += "zone '$($zone.id)': guard '$($g.contract_id)' did NOT go red on mutation (guard not protecting)"
                }
            }
            $zoneResult.blessed = $blessedAll
            $zoneResult.mutation_passed = $mutAll
            $zonesTouched += [pscustomobject]$zoneResult
            continue
        }

        # ---- Fallback: zone touched via path_glob/grep only (no enumerated value in diff) ----
        $cover = Select-AutodevGuardForZone -Pairs $guardPairs -ZoneId $zone.id
        if ($null -eq $cover) {
            $reasons += "zone '$($zone.id)' touched (path/grep, no enumerated value) but no mutation-verified guard covers it (needs guard)"
            $zonesTouched += [pscustomobject]$zoneResult
            continue
        }
        $zoneResult.guarded = $true
        $zoneResult.guard_test = $cover.guard_test
        $zoneResult.blessed = (Test-AutodevGuardBlessed -Guard $cover)
        $zoneResult.mutation_passed = (Test-AutodevGuardStillRed -Guard $cover -Config $Config)
        if (-not $zoneResult.mutation_passed) {
            $reasons += "guard for zone '$($zone.id)' did NOT go red on mutation (guard not protecting)"
        } elseif (-not $zoneResult.blessed) {
            $reasons += "zone '$($zone.id)' guarded + mutation-proven but guard not yet blessed by operator"
        }
        $zonesTouched += [pscustomobject]$zoneResult
    }

    # 4. decision
    $decision = 'COMMIT'
    if (-not $composerGreen -or -not $successGreen) { $decision = 'RETRY' }
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
        success_green        = $successGreen
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

# --------------------------------------------------------------------------------------
# Self-test: the per-VALUE guard-coverage invariant (acceptance for the zone-level
# over-coverage fix). Pure -- no disk, no phpunit. Drives the SAME selector functions the
# gate uses, so a regression that re-broadens coverage to zone-level is caught here.
# --------------------------------------------------------------------------------------
function Invoke-GateSelfTest {
    # Synthetic guard+recipe pairs: ONE guard on the edostavka settings key (blessed), ONE
    # guard on a shipping id (pending). NOTE both recipes carry zone_id 'option_keys'/'shipping_method_id'.
    $pairs = @(
        @{ guard  = [pscustomobject]@{ contract_id = 'settings_option_key_edostavka'; guard_test = 'T_edostavka'; blessed_by = 'maksim' }
           recipe = [pscustomobject]@{ zone_id = 'option_keys'; canonical_value = 'woocommerce_edostavka_settings' } },
        @{ guard  = [pscustomobject]@{ contract_id = 'shipping_method_id_edostavka'; guard_test = 'T_ship'; blessed_by = 'pending-operator' }
           recipe = [pscustomobject]@{ zone_id = 'shipping_method_id'; canonical_value = 'edostavka' } }
    )

    # (1) the guarded value resolves to its guard.
    $c1 = (Select-AutodevGuardForValue -Pairs $pairs -Value 'woocommerce_edostavka_settings')
    $case1 = ($null -ne $c1 -and $c1.contract_id -eq 'settings_option_key_edostavka')

    # (2) THE FIX: a DIFFERENT enumerated value in the SAME zone is NOT covered by that guard.
    $c2 = (Select-AutodevGuardForValue -Pairs $pairs -Value 'wc_edostavka_webhook_ids')
    $case2 = ($null -eq $c2)

    # (3) zone-level fallback still resolves (used only for path/grep touches with no value).
    $c3a = (Select-AutodevGuardForZone -Pairs $pairs -ZoneId 'option_keys')
    $c3b = (Select-AutodevGuardForZone -Pairs $pairs -ZoneId 'no_such_zone')
    $case3 = ($null -ne $c3a -and $c3a.contract_id -eq 'settings_option_key_edostavka' -and $null -eq $c3b)

    # (4) blessing predicate.
    $case4 = (Test-AutodevGuardBlessed -Guard ([pscustomobject]@{ blessed_by = 'maksim' })) -and `
             (-not (Test-AutodevGuardBlessed -Guard ([pscustomobject]@{ blessed_by = 'pending-operator' }))) -and `
             (-not (Test-AutodevGuardBlessed -Guard ([pscustomobject]@{ blessed_by = '' })))

    # (5) Get-AutodevZoneTouchedStrings returns ONLY the enumerated value actually in the diff.
    $zone = [pscustomobject]@{ exact_strings = @('woocommerce_edostavka_settings', 'wc_edostavka_webhook_ids') }
    $touched = @(Get-AutodevZoneTouchedStrings -Zone $zone -DiffLines @('+        get_option( ''wc_edostavka_webhook_ids'' )'))
    $case5 = ($touched.Count -eq 1 -and $touched[0] -eq 'wc_edostavka_webhook_ids')

    foreach ($c in @(@('1 value->guard', $case1), @('2 sibling-value uncovered (FIX)', $case2),
                     @('3 zone fallback', $case3), @('4 blessed predicate', $case4),
                     @('5 touched-strings precise', $case5))) {
        $color = if ($c[1]) { 'Green' } else { 'Red' }
        Write-Host ("Case {0}: {1}" -f $c[0], $(if ($c[1]) { 'PASS' } else { 'FAIL' })) -ForegroundColor $color
    }
    if ($case1 -and $case2 -and $case3 -and $case4 -and $case5) {
        Write-Host "RESULT: PASS -- guard coverage is per-value; a sibling value in the same zone is NOT auto-blessed." -ForegroundColor Green
        return 0
    }
    Write-Host "RESULT: FAIL" -ForegroundColor Red
    return 1
}

# Entry point
if ($SelfTest) { exit (Invoke-GateSelfTest) }

$v = Invoke-AutodevGate -TaskId $TaskId -Range $Range -FileSet $FileSet -SkipComposer:$SkipComposer `
        -ComposerSubcommand $ComposerSubcommand -SuccessCommands $SuccessCommands
$v | ConvertTo-Json -Depth 6
switch ($v.decision) {
    'COMMIT'   { exit 0 }
    'RETRY'    { exit 2 }
    default    { exit 3 }   # ESCALATE
}
