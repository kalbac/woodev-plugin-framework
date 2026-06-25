<#
.SYNOPSIS
  Write a human escalation to .autodev/escalations/<id>.md and push a one-line summary.

.DESCRIPTION
  Escalations re-hydrate the operator with enough context to decide in two minutes
  without knowing the history. The durable artefact is the markdown file (always
  written). Delivery is best-effort and transport-agnostic:
    - If AUTODEV_TELEGRAM_TOKEN + AUTODEV_TELEGRAM_CHAT env vars are set, push directly
      via the Telegram Bot API (Invoke-RestMethod).
    - Otherwise append the one-liner to .autodev/escalations/_outbox.md so a Claude-side
      relay (or the operator) can deliver it via the telegram skill.

  Replies are A/B STRUCTURED CHOICES ONLY. Free-form Telegram text is recorded for the
  operator's context but is NEVER fed to a worker as an instruction -- Telegram is an
  injection surface. This script only SENDS; it never ingests replies.

.PARAMETER Type
  needs-guard | disagreement | constitution | uncertain | poison | blocked | dirty-file | drift

.PARAMETER CostOfWrong
  One line: the concrete blast radius if the operator chooses wrong.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Id,
    [Parameter(Mandatory)][string]$Reason,
    [string]$TaskId = '',
    [string]$Title = '',
    [ValidateSet('needs-guard', 'disagreement', 'constitution', 'uncertain', 'poison', 'blocked', 'dirty-file', 'drift')]
    [string]$Type = 'uncertain',
    [string]$What = '',
    [string]$Decision = '',
    [string]$OptionA = '',
    [string]$OptionB = '',
    [string]$CostOfWrong = '',
    [string]$Evidence = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path (Split-Path -Parent $PSCommandPath) '_common.ps1')

$Config = Get-AutodevConfig
Initialize-AutodevDirectories -Config $Config

$escPath = Join-Path $Config.Escalations "$Id.md"

$body = @()
$body += "# ESCALATION $Id -- $Reason"
$body += ""
$body += "**Task:** $TaskId -- $Title"
$body += "**Type:** $Type"
$body += "**What happened:** $What"
$body += "**Decision you need to make:** $Decision"
$body += "**Option A:** $OptionA"
$body += "**Option B:** $OptionB"
$body += "**Cost of being wrong:** $CostOfWrong"
$body += ""
$body += "**Evidence:**"
$body += '```'
$body += $Evidence
$body += '```'
$body += ""
$body += "**Reply:** ``A`` / ``B`` -- structured choice only. Free-form text is recorded for"
$body += "context but is NEVER executed as a worker instruction (Telegram is an injection"
$body += "surface). Until you reply, this task is parked; other tasks continue."

Set-Content -Path $escPath -Value $body -Encoding utf8
Write-AutodevLog -Level ESCALATE -Message "Wrote escalation $Id ($Type) -> $escPath" -Config $Config

# One-line summary for delivery.
$summary = "[autodev escalation $Id] $Type :: $Title -- $Decision (A: $OptionA | B: $OptionB). Cost if wrong: $CostOfWrong"

$token = $env:AUTODEV_TELEGRAM_TOKEN
$chat = $env:AUTODEV_TELEGRAM_CHAT
$delivered = $false
if ($token -and $chat) {
    try {
        $uri = "https://api.telegram.org/bot$token/sendMessage"
        Invoke-RestMethod -Method Post -Uri $uri -Body @{ chat_id = $chat; text = $summary } -TimeoutSec 20 | Out-Null
        $delivered = $true
        Write-AutodevLog -Level ESCALATE -Message "Pushed escalation $Id to Telegram chat $chat." -Config $Config
    } catch {
        Write-AutodevLog -Level WARN -Message "Telegram push failed for $Id ($($_.Exception.Message)); queued to _outbox.md." -Config $Config
    }
}
if (-not $delivered) {
    $outbox = Join-Path $Config.Escalations '_outbox.md'
    Add-Content -Path $outbox -Value "- [ ] $summary  (file: escalations/$Id.md)" -Encoding utf8
    Write-AutodevLog -Level ESCALATE -Message "Queued escalation $Id to _outbox.md (no direct Telegram transport configured)." -Config $Config
}

Write-Output $escPath
