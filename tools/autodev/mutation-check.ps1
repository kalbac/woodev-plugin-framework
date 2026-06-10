<#
.SYNOPSIS
  Prove a contract guard is REAL by flipping the contract and asserting the guard goes RED.

.DESCRIPTION
  Implements the autonomy boundary's keystone check. Given a mutation-recipe:
    { zone_id, contract_id, file, locator, canonical_value, mutated_value, guard_test }
  it performs:
    1. baseline : run guard_test  -> MUST be GREEN (the guard passes on the real contract)
    2. mutate   : in `file`, replace `locator` with locator(canonical -> mutated)
    3. red       : run guard_test -> MUST be RED  (the guard catches the broken contract)
    4. restore  : write the ORIGINAL bytes back (ALWAYS, even on error/Ctrl-C)
    5. green    : run guard_test  -> MUST be GREEN again (clean revert)

  A guard that stays GREEN under mutation is NOT protecting the contract -> FAIL. The
  conductor must then treat the contract as human-only, never as "guarded".

  SAFETY: the target file's exact original bytes are snapshotted up front and restored
  in a finally block. A crash mid-run leaves the file byte-identical to how it started.

.PARAMETER RecipePath
  Path to the mutation-recipe.json.

.PARAMETER Quiet
  Suppress phpunit output (used when called from the gate). Result is the exit code.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$RecipePath,
    [switch]$Quiet
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path (Split-Path -Parent $PSCommandPath) '_common.ps1')

function Invoke-GuardTest {
    <# Run a single phpunit test file. Returns @{ Green; Output }. #>
    param([string]$TestFile, [pscustomobject]$Config)
    $phpunit = Join-Path $Config.RepoRoot 'vendor\bin\phpunit.bat'
    if (-not (Test-Path $phpunit)) { $phpunit = Join-Path $Config.RepoRoot 'vendor\bin\phpunit' }
    # Route through Invoke-Native so phpunit stderr (test summaries, notices) does not trigger
    # NativeCommandError under $ErrorActionPreference='Stop' on Windows PowerShell 5.1.
    # Exit-code semantics are preserved: non-zero = RED (expected on mutation step).
    $r = Invoke-Native -Exe $phpunit -CommandArgs @($TestFile) -WorkingDirectory $Config.RepoRoot -Merge
    return [pscustomobject]@{ Green = ($r.ExitCode -eq 0); Output = $r.Output }
}

function Write-Maybe { param([string]$Msg) if (-not $Quiet) { Write-Host $Msg } }

function Invoke-MutationCheck {
    param([string]$RecipePath, [switch]$Quiet, [pscustomobject]$Config = (Get-AutodevConfig))

    if (-not (Test-Path $RecipePath)) { throw "Recipe not found: $RecipePath" }
    $recipe = Get-Content -Path $RecipePath -Raw -Encoding utf8 | ConvertFrom-Json

    foreach ($req in @('file', 'locator', 'canonical_value', 'mutated_value', 'guard_test')) {
        if (-not ($recipe.PSObject.Properties.Name -contains $req)) {
            throw "Recipe $RecipePath missing required field '$req'."
        }
    }

    $targetFile = Join-Path $Config.RepoRoot $recipe.file
    $guardTest  = $recipe.guard_test
    if (-not (Test-Path $targetFile)) { throw "Recipe target file not found: $targetFile" }

    $mutatedLine = $recipe.locator.Replace($recipe.canonical_value, $recipe.mutated_value)
    if ($mutatedLine -eq $recipe.locator) {
        throw "Recipe canonical_value '$($recipe.canonical_value)' not found inside locator -> nothing would change."
    }

    # SNAPSHOT exact original bytes.
    $originalBytes = [System.IO.File]::ReadAllBytes($targetFile)
    $restored = $false

    try {
        # 1. baseline GREEN
        Write-Maybe "[mutation-check] baseline: running guard $guardTest ..."
        $base = Invoke-GuardTest -TestFile $guardTest -Config $Config
        if (-not $base.Green) {
            Write-Maybe "[mutation-check] FAIL: guard is RED on the REAL contract (baseline must be green)."
            if (-not $Quiet) { Write-Host $base.Output }
            return 1
        }

        # 2. MUTATE
        $text = [System.IO.File]::ReadAllText($targetFile, [System.Text.Encoding]::UTF8)
        # Literal substring check -- NOT -like: a locator with PHP syntax ('[]', '*', '?')
        # is not a wildcard pattern and would throw WildcardPatternException under -like.
        if (-not $text.Contains($recipe.locator)) {
            Write-Maybe "[mutation-check] FAIL: locator not found in $($recipe.file) (stale recipe)."
            return 1
        }
        $mutatedText = $text.Replace($recipe.locator, $mutatedLine)
        [System.IO.File]::WriteAllText($targetFile, $mutatedText, (New-Object System.Text.UTF8Encoding($false)))
        Write-Maybe "[mutation-check] mutated: '$($recipe.canonical_value)' -> '$($recipe.mutated_value)' in $($recipe.file)"

        # 3. expect RED
        $mutResult = Invoke-GuardTest -TestFile $guardTest -Config $Config
        $wentRed = (-not $mutResult.Green)

        # 4. RESTORE (also done in finally as a backstop)
        [System.IO.File]::WriteAllBytes($targetFile, $originalBytes)
        $restored = $true
        Write-Maybe "[mutation-check] reverted $($recipe.file) to original bytes."

        if (-not $wentRed) {
            Write-Maybe "[mutation-check] FAIL: guard stayed GREEN under mutation -> NOT a real guard."
            return 1
        }

        # 5. post-revert GREEN sanity
        $after = Invoke-GuardTest -TestFile $guardTest -Config $Config
        if (-not $after.Green) {
            Write-Maybe "[mutation-check] FAIL: guard RED after revert -> revert imperfect (investigate)."
            return 1
        }

        Write-Maybe "[mutation-check] PASS: guard GREEN -> RED-on-flip -> GREEN-on-revert. Real guard."
        return 0
    }
    finally {
        if (-not $restored) {
            [System.IO.File]::WriteAllBytes($targetFile, $originalBytes)
            Write-Maybe "[mutation-check] (finally) restored original bytes of $($recipe.file)."
        }
    }
}

$code = Invoke-MutationCheck -RecipePath $RecipePath -Quiet:$Quiet
exit $code
