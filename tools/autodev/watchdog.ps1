<#
.SYNOPSIS
  Heartbeat watchdog: run an external command and kill it if its heartbeat goes stale.

.DESCRIPTION
  A worker touches .autodev/runtime/<id>/heartbeat at every significant step. This
  watchdog runs the worker command and, while it runs, checks the heartbeat mtime. If
  the heartbeat is older than WatchdogStaleMinutes, the worker is considered hung: the
  process tree is killed so the conductor can respawn a fresh agent (attempts++).

  Returns an object: @{ ExitCode; TimedOut; RateLimited; Stdout; Stderr }.

.PARAMETER SelfTest
  Prove the watchdog mechanically: spawn a process that never updates its heartbeat and
  confirm the watchdog kills it once the (tiny) stale window passes.
#>
[CmdletBinding(DefaultParameterSetName = 'Lib')]
param(
    [Parameter(ParameterSetName = 'SelfTest')][switch]$SelfTest
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path (Split-Path -Parent $PSCommandPath) '_common.ps1')

function Stop-ProcessTree {
    param([int]$ProcessId)
    # Kill children first (best-effort), then the process.
    try {
        $children = Get-CimInstance Win32_Process -Filter "ParentProcessId=$ProcessId" -ErrorAction SilentlyContinue
        foreach ($c in $children) { Stop-ProcessTree -ProcessId ([int]$c.ProcessId) }
    } catch { }
    try { Stop-Process -Id $ProcessId -Force -ErrorAction SilentlyContinue } catch { }
}

function Start-WatchedProcess {
    <#
      Run FilePath with ArgumentList, monitoring HeartbeatPath for staleness.
      StdinText (optional) is piped to the process.
    #>
    param(
        [Parameter(Mandatory)][string]$FilePath,
        [string[]]$ArgumentList = @(),
        [Parameter(Mandatory)][string]$HeartbeatPath,
        [int]$StaleSeconds = 480,
        [int]$TimeoutSeconds = 1200,
        [int]$PollSeconds = 5,
        [string]$StdinText = $null,
        [string]$WorkingDirectory = $null
    )

    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $FilePath
    # Windows PowerShell 5.1 (.NET Framework) lacks ProcessStartInfo.ArgumentList in some
    # builds -- build a quoted Arguments string instead.
    $quoted = @()
    foreach ($a in $ArgumentList) {
        if ($a -match '[\s"]') { $quoted += '"' + ($a -replace '"', '\"') + '"' } else { $quoted += $a }
    }
    $psi.Arguments = ($quoted -join ' ')
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.RedirectStandardInput = [bool]$StdinText
    $psi.UseShellExecute = $false
    if ($WorkingDirectory) { $psi.WorkingDirectory = $WorkingDirectory }

    # Seed the heartbeat so the first poll has a baseline.
    $hbDir = Split-Path -Parent $HeartbeatPath
    if (-not (Test-Path $hbDir)) { New-Item -ItemType Directory -Path $hbDir -Force | Out-Null }
    Set-Content -Path $HeartbeatPath -Value 'start' -Encoding utf8

    $proc = [System.Diagnostics.Process]::Start($psi)
    if ($StdinText) { $proc.StandardInput.Write($StdinText); $proc.StandardInput.Close() }

    # Async-read stdout/stderr to avoid pipe deadlock.
    $stdoutTask = $proc.StandardOutput.ReadToEndAsync()
    $stderrTask = $proc.StandardError.ReadToEndAsync()

    $start = Get-Date
    $timedOut = $false
    while (-not $proc.HasExited) {
        Start-Sleep -Seconds $PollSeconds
        $now = Get-Date
        $hbAge = if (Test-Path $HeartbeatPath) {
            ($now - (Get-Item $HeartbeatPath).LastWriteTime).TotalSeconds
        } else { ($now - $start).TotalSeconds }
        $elapsed = ($now - $start).TotalSeconds

        if ($hbAge -gt $StaleSeconds) {
            Write-AutodevLog -Level WARN -Message "Watchdog: heartbeat stale ($([int]$hbAge)s > ${StaleSeconds}s). Killing PID $($proc.Id)."
            Stop-ProcessTree -ProcessId $proc.Id
            $timedOut = $true
            break
        }
        if ($elapsed -gt $TimeoutSeconds) {
            Write-AutodevLog -Level WARN -Message "Watchdog: hard timeout (${TimeoutSeconds}s). Killing PID $($proc.Id)."
            Stop-ProcessTree -ProcessId $proc.Id
            $timedOut = $true
            break
        }
    }
    try { $proc.WaitForExit(5000) | Out-Null } catch { }

    $stdout = ''
    $stderr = ''
    try { $stdout = $stdoutTask.GetAwaiter().GetResult() } catch { }
    try { $stderr = $stderrTask.GetAwaiter().GetResult() } catch { }
    $exit = if ($proc.HasExited) { $proc.ExitCode } else { -1 }

    return [pscustomobject]@{
        ExitCode    = $exit
        TimedOut    = $timedOut
        RateLimited = (Test-RateLimited -ExitCode $exit -Stderr $stderr)
        Stdout      = $stdout
        Stderr      = $stderr
    }
}

function Invoke-WatchdogSelfTest {
    # Spawn a process that sleeps 60s but NEVER updates the heartbeat. With a 6s stale
    # window the watchdog must kill it well before it finishes.
    $hb = Join-Path ([System.IO.Path]::GetTempPath()) ("autodev-hb-" + [guid]::NewGuid().ToString('N'))
    $sleeper = 'Start-Sleep -Seconds 60'
    Write-Host "Watchdog self-test: launching a 60s sleeper with a 6s stale window (no heartbeat updates)..."
    $t0 = Get-Date
    $r = Start-WatchedProcess -FilePath 'powershell' `
            -ArgumentList @('-NoProfile', '-Command', $sleeper) `
            -HeartbeatPath $hb -StaleSeconds 6 -TimeoutSeconds 120 -PollSeconds 2
    $elapsed = ((Get-Date) - $t0).TotalSeconds
    Remove-Item $hb -Force -ErrorAction SilentlyContinue
    Write-Host ("Killed after {0:N0}s; TimedOut={1}; ExitCode={2}" -f $elapsed, $r.TimedOut, $r.ExitCode)
    if ($r.TimedOut -and $elapsed -lt 30) {
        Write-Host "RESULT: PASS -- watchdog killed the hung process on heartbeat staleness." -ForegroundColor Green
        return 0
    }
    Write-Host "RESULT: FAIL" -ForegroundColor Red
    return 1
}

if ($PSCmdlet.ParameterSetName -eq 'SelfTest') { exit (Invoke-WatchdogSelfTest) }
