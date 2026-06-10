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
        [int]$StaleSeconds = 900,
        [int]$TimeoutSeconds = 1200,
        [int]$PollSeconds = 5,
        [string]$StdinText = $null,
        [string]$WorkingDirectory = $null,
        [string]$StdoutLogPath = $null,
        [string]$StderrLogPath = $null,
        [string[]]$ActivityPaths = @()
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

    # --- Process-driven liveness ----------------------------------------------------
    # A worker stays "alive" as long as the PROCESS shows activity -- NOT only when the
    # LLM remembers to touch the heartbeat (models routinely forget during long read/
    # reason phases, so a model-driven heartbeat kills healthy workers). We stream the
    # child's stdout/stderr line-by-line; every emitted line bumps a shared last-activity
    # clock. Run the worker with --output-format stream-json (see invoke-worker.ps1) so
    # output is continuous, making this a reliable signal even before any file is written.
    # File writes under $ActivityPaths and the model's own heartbeat touch also count.
    $shared = [hashtable]::Synchronized(@{
        LastTicks = [DateTime]::UtcNow.Ticks
        Out       = New-Object System.Text.StringBuilder
        Err       = New-Object System.Text.StringBuilder
    })
    $sink = {
        # $Event.SourceEventArgs.Data works on both Windows PowerShell 5.1 and PS 7+.
        # $EventArgs.Data is unreliable on 5.1 and silently drops lines, killing stdout liveness.
        $d = $Event.SourceEventArgs.Data
        if ($null -ne $d) {
            $s = $Event.MessageData
            $s['LastTicks'] = [DateTime]::UtcNow.Ticks
            if ($Event.SourceIdentifier -like '*-err-*') { [void]$s['Err'].AppendLine($d) }
            else { [void]$s['Out'].AppendLine($d) }
        }
    }
    $outSid = "wd-out-$($proc.Id)"
    $errSid = "wd-err-$($proc.Id)"
    $null = Register-ObjectEvent -InputObject $proc -EventName OutputDataReceived -SourceIdentifier $outSid -Action $sink -MessageData $shared
    $null = Register-ObjectEvent -InputObject $proc -EventName ErrorDataReceived  -SourceIdentifier $errSid -Action $sink -MessageData $shared
    $proc.BeginOutputReadLine()
    $proc.BeginErrorReadLine()

    $start = Get-Date
    $timedOut = $false
    while (-not $proc.HasExited) {
        Start-Sleep -Seconds $PollSeconds

        # Most-recent activity = newest of: process output, worker file writes, model heartbeat.
        $activity = [DateTime]::new([long]$shared['LastTicks'], [DateTimeKind]::Utc)
        if (Test-Path $HeartbeatPath) {
            $hb = (Get-Item $HeartbeatPath).LastWriteTimeUtc
            if ($hb -gt $activity) { $activity = $hb }
        }
        foreach ($p in $ActivityPaths) {
            if ($p -and (Test-Path $p)) {
                $newest = Get-ChildItem -Path $p -Recurse -File -Force -ErrorAction SilentlyContinue |
                    Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1
                if ($newest -and $newest.LastWriteTimeUtc -gt $activity) { $activity = $newest.LastWriteTimeUtc }
            }
        }
        $idleSeconds = ([DateTime]::UtcNow - $activity).TotalSeconds
        $elapsed = ((Get-Date) - $start).TotalSeconds

        if ($idleSeconds -gt $StaleSeconds) {
            Write-AutodevLog -Level WARN -Message "Watchdog: no process activity for $([int]$idleSeconds)s (> ${StaleSeconds}s). Killing PID $($proc.Id)."
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
    try { $proc.CancelOutputRead() } catch { }
    try { $proc.CancelErrorRead() } catch { }
    Start-Sleep -Milliseconds 200   # let trailing OutputDataReceived / ErrorDataReceived events drain
    Unregister-Event -SourceIdentifier $outSid -ErrorAction SilentlyContinue
    Unregister-Event -SourceIdentifier $errSid -ErrorAction SilentlyContinue
    Get-Job | Where-Object { $_.Name -eq $outSid -or $_.Name -eq $errSid } | Remove-Job -Force -ErrorAction SilentlyContinue

    $stdout = $shared['Out'].ToString()
    $stderr = $shared['Err'].ToString()
    $exit = if ($proc.HasExited) { $proc.ExitCode } else { -1 }

    # Persist worker output for postmortem (previously discarded -> failures were invisible).
    if ($StdoutLogPath) { Set-Content -Path $StdoutLogPath -Value $stdout -Encoding utf8 }
    if ($StderrLogPath) { Set-Content -Path $StderrLogPath -Value $stderr -Encoding utf8 }

    return [pscustomobject]@{
        ExitCode    = $exit
        TimedOut    = $timedOut
        RateLimited = (Test-RateLimited -ExitCode $exit -Stderr ($stderr + "`n" + $stdout))
        Stdout      = $stdout
        Stderr      = $stderr
    }
}

function Invoke-WatchdogSelfTest {
    # Resolve the current PowerShell engine so child processes use the same binary.
    # Hardcoding 'powershell' breaks on systems where only pwsh (PS 7+) is installed.
    $psExe = if ($PSVersionTable.PSEdition -eq 'Core') { 'pwsh' } else { 'powershell' }

    # Case 1: heartbeat-staleness kill.
    # Spawn a process that sleeps 60s but NEVER updates the heartbeat. With a 6s stale
    # window the watchdog must kill it well before it finishes.
    $hb = Join-Path ([System.IO.Path]::GetTempPath()) ("autodev-hb-" + [guid]::NewGuid().ToString('N'))
    $sleeper = 'Start-Sleep -Seconds 60'
    Write-Host "Watchdog self-test case 1: launching a 60s sleeper with a 6s stale window (no heartbeat updates)..."
    $t0 = Get-Date
    $r = Start-WatchedProcess -FilePath $psExe `
            -ArgumentList @('-NoProfile', '-Command', $sleeper) `
            -HeartbeatPath $hb -StaleSeconds 6 -TimeoutSeconds 120 -PollSeconds 2
    $elapsed = ((Get-Date) - $t0).TotalSeconds
    Remove-Item $hb -Force -ErrorAction SilentlyContinue
    Write-Host ("  Killed after {0:N0}s; TimedOut={1}; ExitCode={2}" -f $elapsed, $r.TimedOut, $r.ExitCode)
    $case1 = ($r.TimedOut -and $elapsed -lt 30)
    if ($case1) {
        Write-Host "  Case 1 PASS -- watchdog killed the hung process on heartbeat staleness." -ForegroundColor Green
    } else {
        Write-Host "  Case 1 FAIL" -ForegroundColor Red
    }

    # Case 2: stdout-liveness (sink must receive data).
    # Spawn a short-lived process that emits lines to stdout. With a 6s stale window and
    # NO heartbeat file the watchdog MUST stay alive long enough to let the process finish
    # naturally (stdout events keep LastTicks fresh). If the event sink is broken (e.g.
    # $EventArgs.Data instead of $Event.SourceEventArgs.Data), LastTicks never updates,
    # the watchdog kills the process early as "stale", and TimedOut=true -- FAIL.
    $hb2 = Join-Path ([System.IO.Path]::GetTempPath()) ("autodev-hb2-" + [guid]::NewGuid().ToString('N'))
    # Emit one line per second for 8 seconds (> the 6s stale window) then exit normally.
    $emitter = '1..8 | ForEach-Object { Write-Host "line $_"; Start-Sleep -Seconds 1 }'
    Write-Host "Watchdog self-test case 2: 8s stdout-emitting process with 6s stale window (stdout keeps sink alive)..."
    $t1 = Get-Date
    $r2 = Start-WatchedProcess -FilePath $psExe `
            -ArgumentList @('-NoProfile', '-Command', $emitter) `
            -HeartbeatPath $hb2 -StaleSeconds 6 -TimeoutSeconds 60 -PollSeconds 1
    $elapsed2 = ((Get-Date) - $t1).TotalSeconds
    Remove-Item $hb2 -Force -ErrorAction SilentlyContinue
    Write-Host ("  Elapsed {0:N0}s; TimedOut={1}; StdoutLines={2}" -f $elapsed2, $r2.TimedOut, ($r2.Stdout -split "`n" | Where-Object { $_.Trim() }).Count)
    # Process must NOT have been killed (TimedOut=false) and must have emitted all 8 lines.
    $linesReceived = ($r2.Stdout -split "`n" | Where-Object { $_.Trim() -match '^line \d' }).Count
    $case2 = ((-not $r2.TimedOut) -and $linesReceived -ge 8)
    if ($case2) {
        Write-Host "  Case 2 PASS -- sink received all stdout lines; process ran to completion." -ForegroundColor Green
    } else {
        Write-Host "  Case 2 FAIL -- sink did not receive lines or process was killed early (linesReceived=$linesReceived, TimedOut=$($r2.TimedOut))." -ForegroundColor Red
    }

    Write-Host "--- Watchdog self-test summary ---"
    if ($case1 -and $case2) {
        Write-Host "RESULT: PASS -- heartbeat-staleness kill + stdout-liveness both verified." -ForegroundColor Green
        return 0
    }
    Write-Host "RESULT: FAIL" -ForegroundColor Red
    return 1
}

if ($PSCmdlet.ParameterSetName -eq 'SelfTest') { exit (Invoke-WatchdogSelfTest) }
