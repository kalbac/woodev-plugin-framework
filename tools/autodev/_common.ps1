<#
.SYNOPSIS
  Shared configuration + helpers for the autodev adversarial dev loop.

.DESCRIPTION
  Dot-sourced by every tools/autodev/*.ps1 script. Holds NO loop logic and makes NO
  judgment calls -- it only provides config, path resolution, parsing, and small pure
  helpers. Compatible with Windows PowerShell 5.1 (no null-coalescing / ternary ops).

  ASCII-ONLY: these files are read by Windows PowerShell 5.1, which on a non-UTF-8
  system codepage (here CP1251) mis-decodes UTF-8 bytes. A mis-decoded em-dash can
  surface as a "smart quote" that PowerShell treats as a string delimiter, corrupting
  the parse. So keep every tools/autodev/*.ps1 file pure 7-bit ASCII.

  The conductor never reasons; these helpers never reason either. Anything that would
  "decide" belongs in the worker/critic subprocesses or escalates to the operator.

  NOTE: this file is DOT-SOURCED, so it deliberately does NOT set Set-StrictMode or
  $ErrorActionPreference at top level (that would leak into the caller's scope). Each
  entry-point script sets its own strictness.
#>

# --------------------------------------------------------------------------------------
# Repo root + paths
# --------------------------------------------------------------------------------------

function Get-AutodevRepoRoot {
    <# Walk up from this script until we find the repo marker (composer.json + woodev/). #>
    $dir = Split-Path -Parent $PSCommandPath
    while ($null -ne $dir -and $dir -ne '') {
        if ((Test-Path (Join-Path $dir 'composer.json')) -and (Test-Path (Join-Path $dir 'woodev'))) {
            return $dir
        }
        $parent = Split-Path -Parent $dir
        if ($parent -eq $dir) { break }
        $dir = $parent
    }
    throw "Could not locate repo root (composer.json + woodev/) from $PSCommandPath"
}

function Get-AutodevConfig {
    $root = Get-AutodevRepoRoot
    $autodev = Join-Path $root '.autodev'
    return [pscustomobject]@{
        RepoRoot        = $root
        Autodev         = $autodev
        Goal            = Join-Path $autodev 'GOAL.md'
        Invariants      = Join-Path $autodev 'INVARIANTS.md'
        Guards          = Join-Path $autodev 'GUARDS.md'
        Digest          = Join-Path $autodev 'digest.md'
        Log             = Join-Path $autodev 'conductor.log'
        QueuePending    = Join-Path $autodev 'queue\pending'
        QueueActive     = Join-Path $autodev 'queue\active'
        QueueDone       = Join-Path $autodev 'queue\done'
        QueueQuarantine = Join-Path $autodev 'queue\quarantine'
        Runtime         = Join-Path $autodev 'runtime'
        Escalations     = Join-Path $autodev 'escalations'
        CurrentState    = Join-Path $root 'docs-internal\CURRENT-STATE.md'
        Tracker         = Join-Path $root 'docs-internal\platform-v2-program-tracker.md'

        # Runtime roles (model ladder). Contract-zone tasks pin to the first entry only.
        WorkerLadder    = @('opus', 'sonnet', 'haiku')
        CriticModel     = 'gpt-5.5'
        CriticEffort    = 'high'
        AntiDriftModel  = 'sonnet'

        # Mechanical thresholds (NEVER an LLM pre-call -- keep tiering mechanical).
        CriticDiffLineThreshold = 40   # diff > N lines -> expensive critic even if zone-free
        WatchdogStaleMinutes    = 8    # heartbeat older than this -> kill + respawn
        MaxAttempts             = 3    # attempts > this -> quarantine + escalate
        AntiDriftEveryCommits   = 5    # run anti-drift every M commits
        DigestEveryCommits      = 5    # append digest every N commits
        WorkerTimeoutMinutes    = 20

        # Tool transports
        ClaudeExe       = 'claude'
        CodexExe        = 'codex'
    }
}

function Initialize-AutodevDirectories {
    param([pscustomobject]$Config = (Get-AutodevConfig))
    foreach ($p in @($Config.QueuePending, $Config.QueueActive, $Config.QueueDone,
                      $Config.QueueQuarantine, $Config.Runtime, $Config.Escalations)) {
        if (-not (Test-Path $p)) { New-Item -ItemType Directory -Path $p -Force | Out-Null }
    }
}

# --------------------------------------------------------------------------------------
# Logging
# --------------------------------------------------------------------------------------

function Write-AutodevLog {
    param(
        [Parameter(Mandatory)][string]$Message,
        [ValidateSet('INFO', 'WARN', 'ERROR', 'GATE', 'CRITIC', 'WORKER', 'ESCALATE')]
        [string]$Level = 'INFO',
        [pscustomobject]$Config = (Get-AutodevConfig)
    )
    $stamp = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    $line = "[$stamp] [$Level] $Message"
    Write-Host $line
    try { Add-Content -Path $Config.Log -Value $line -Encoding utf8 } catch { }
}

# --------------------------------------------------------------------------------------
# INVARIANTS parsing (single machine source = JSON block between markers)
# --------------------------------------------------------------------------------------

function Get-AutodevInvariants {
    param([pscustomobject]$Config = (Get-AutodevConfig))
    $text = Get-Content -Path $Config.Invariants -Raw -Encoding utf8
    $m = [regex]::Match($text,
        '(?s)<!-- BEGIN MACHINE-INVARIANTS -->\s*```json\s*(.*?)\s*```\s*<!-- END MACHINE-INVARIANTS -->')
    if (-not $m.Success) { throw "INVARIANTS.md: machine-invariants JSON block not found." }
    return $m.Groups[1].Value | ConvertFrom-Json
}

# --------------------------------------------------------------------------------------
# Glob / path matching
# --------------------------------------------------------------------------------------

function ConvertTo-NormalizedPath {
    param([string]$Path)
    return ($Path -replace '\\', '/').TrimStart('./')
}

function Test-GlobMatch {
    <# Minimal glob: ** matches across slashes, * matches within a segment. #>
    param([string]$Path, [string]$Glob)
    $p = ConvertTo-NormalizedPath $Path
    $g = ConvertTo-NormalizedPath $Glob
    $rx = [regex]::Escape($g)
    $rx = $rx -replace '\\\*\\\*', '___DOUBLESTAR___'
    $rx = $rx -replace '\\\*', '[^/]*'
    $rx = $rx -replace '___DOUBLESTAR___', '.*'
    return [regex]::IsMatch($p, "^$rx$")
}

function Test-PathMatchesAnyGlob {
    param([string]$Path, [string[]]$Globs)
    foreach ($g in $Globs) { if (Test-GlobMatch -Path $Path -Glob $g) { return $true } }
    return $false
}

function Test-ZoneTouched {
    <#
      Does a change touch this contract zone, by any of:
        - a changed file path matching one of the zone's path_globs
        - a +/- diff line matching one of the zone's grep_patterns
        - a +/- diff line containing one of the zone's exact_strings
      Pure mechanical signal -- no LLM, used by both the gate and the critic tiering.
    #>
    param([pscustomobject]$Zone, [string[]]$ChangedFiles, [string[]]$DiffLines)
    foreach ($f in $ChangedFiles) {
        if ($Zone.path_globs.Count -gt 0 -and (Test-PathMatchesAnyGlob -Path $f -Globs $Zone.path_globs)) { return $true }
    }
    foreach ($l in $DiffLines) {
        foreach ($pat in $Zone.grep_patterns) { if ($l -match $pat) { return $true } }
        foreach ($s in $Zone.exact_strings)   { if ($l -like "*$s*") { return $true } }
    }
    return $false
}

function Get-AutodevTouchedZoneIds {
    <# Returns the ids of all contract zones the change touches. #>
    param([string[]]$ChangedFiles, [string[]]$DiffLines, [pscustomobject]$Config = (Get-AutodevConfig))
    $inv = Get-AutodevInvariants -Config $Config
    $ids = @()
    foreach ($zone in $inv.contract_zones) {
        if (Test-ZoneTouched -Zone $zone -ChangedFiles $ChangedFiles -DiffLines $DiffLines) { $ids += $zone.id }
    }
    return $ids
}

# --------------------------------------------------------------------------------------
# Task file (frontmatter) parsing
# --------------------------------------------------------------------------------------

function ConvertFrom-AutodevTask {
    <#
      Parses a queue task .md file with simple YAML frontmatter:
        ---
        id: ...
        title: ...
        type: guard
        touches_contract_zone: true
        writes_guard: true
        file_set:
          - path/one.php
          - path/two.json
        ---
        body
      No external YAML dependency: handles scalars and one level of list.
    #>
    param([Parameter(Mandatory)][string]$Path)
    $raw = Get-Content -Path $Path -Raw -Encoding utf8
    $m = [regex]::Match($raw, '(?s)^\s*---\s*\r?\n(.*?)\r?\n---\s*\r?\n?(.*)$')
    if (-not $m.Success) { throw "Task $Path has no YAML frontmatter block." }
    $front = $m.Groups[1].Value
    $body = $m.Groups[2].Value

    $obj = [ordered]@{
        id = $null; title = $null; type = $null
        touches_contract_zone = $false; writes_guard = $false
        file_set = @(); body = $body; path = $Path
    }
    $currentList = $null
    foreach ($line in ($front -split '\r?\n')) {
        if ($line -match '^\s*-\s+(.+?)\s*$' -and $null -ne $currentList) {
            $val = $Matches[1].Trim().Trim('"').Trim("'")
            $obj[$currentList] += $val
            continue
        }
        if ($line -match '^([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$') {
            $key = $Matches[1]; $val = $Matches[2].Trim()
            if ($val -eq '') {
                $currentList = $key
                if (-not ($obj.Contains($key))) { $obj[$key] = @() }
                elseif ($obj[$key] -isnot [array]) { $obj[$key] = @() }
                continue
            }
            $currentList = $null
            $clean = $val.Trim('"').Trim("'")
            if ($clean -in @('true', 'True', 'yes')) { $obj[$key] = $true }
            elseif ($clean -in @('false', 'False', 'no')) { $obj[$key] = $false }
            else { $obj[$key] = $clean }
        }
    }
    return [pscustomobject]$obj
}

function Test-FileSetsDisjoint {
    param([string[]]$A, [string[]]$B)
    $na = @(); foreach ($x in $A) { $na += (ConvertTo-NormalizedPath $x) }
    $nb = @(); foreach ($x in $B) { $nb += (ConvertTo-NormalizedPath $x) }
    foreach ($x in $na) { if ($nb -contains $x) { return $false } }
    return $true
}

# --------------------------------------------------------------------------------------
# Git diff helpers
# --------------------------------------------------------------------------------------

function Get-GitChangedFiles {
    <# Files changed in the working tree (staged+unstaged) or for a given range. #>
    param([string]$Range = $null, [pscustomobject]$Config = (Get-AutodevConfig))
    Push-Location $Config.RepoRoot
    try {
        if ($Range) { $out = & git diff --name-only $Range }
        else        { $out = & git status --porcelain | ForEach-Object { ($_ -replace '^...', '').Trim() } }
        return @($out | Where-Object { $_ -and $_.Trim() -ne '' } | ForEach-Object { ConvertTo-NormalizedPath $_ })
    } finally { Pop-Location }
}

function Get-GitDiffText {
    param([string]$Range = $null, [pscustomobject]$Config = (Get-AutodevConfig))
    Push-Location $Config.RepoRoot
    try {
        if ($Range) { return (& git diff $Range | Out-String) }
        else        { return (& git diff HEAD | Out-String) }
    } finally { Pop-Location }
}

function Get-GitDiffAddedRemovedLines {
    <# Only +/- content lines (excludes +++/--- headers). #>
    param([string]$DiffText)
    $lines = @()
    foreach ($l in ($DiffText -split '\r?\n')) {
        if ($l -match '^[+-]' -and $l -notmatch '^(\+\+\+|---)') { $lines += $l }
    }
    return $lines
}

# --------------------------------------------------------------------------------------
# composer check
# --------------------------------------------------------------------------------------

function Invoke-ComposerCheck {
    param(
        [string]$Subcommand = 'check',
        [pscustomobject]$Config = (Get-AutodevConfig)
    )
    Push-Location $Config.RepoRoot
    try {
        $out = & composer $Subcommand 2>&1 | Out-String
        $green = ($LASTEXITCODE -eq 0)
        return [pscustomobject]@{ Green = $green; ExitCode = $LASTEXITCODE; Output = $out }
    } finally { Pop-Location }
}

# --------------------------------------------------------------------------------------
# Rate-limit detection (worker/critic transports)
# --------------------------------------------------------------------------------------

function Test-RateLimited {
    param([int]$ExitCode, [string]$Stderr)
    if ($null -eq $Stderr) { $Stderr = '' }
    return ($ExitCode -ne 0 -and $Stderr -match '(?i)(429|rate.?limit|quota|overloaded|too many requests|usage limit)')
}
