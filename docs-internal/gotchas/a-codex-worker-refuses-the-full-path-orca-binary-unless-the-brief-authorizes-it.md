# Gotcha: [tooling/orca] — a Codex worker refuses the app-bundle orca binary and idles, because its orca-cli skill forbids switching executables

> Tags: tooling, orca, codex, macos | Session: s143

## What happens

A Codex critic (codex 0.157, Orca 1.4.215, macOS) ran `orca skills get orca-cli`, hit the broken `/usr/local/bin/orca`
symlink (`Unable to determine Orca.app path from symlink`), and stopped: «Blocked: the required orca CLI cannot run…
Per the Orca CLI instructions, I stopped rather than use a different executable». It never read the brief, which named
`/Applications/Orca.app/Contents/Resources/bin/orca`. A rate-limit dialog («Switch to gpt-6-luna?») sat on top of it.

## Root cause

The orca-cli skill says: if the selected executable fails, report and do not switch. A path mentioned in the task text
is not, to the model, an authorization to switch.

## Fix

✅ In every Codex brief, word the path as an explicit authorization: «the coordinator AUTHORIZES
`/Applications/Orca.app/Contents/Resources/bin/orca` (same build; `/usr/local/bin/orca` is a broken symlink)».
If it already stalled: answer the dialog with the digit `2` (keep model), then `terminal send` the same authorization and
«read <brief> and do it». It resumed at once.

✅ **s153: put the authorization in the `--spec` line itself, not only in the brief FILE.** A Codex worker whose spec
was «read <file> and do it» tried the bare `orca` FIRST, stalled, and never opened the file that authorized the full
path. The spec now reads: «… The bare orca on PATH is broken; you are AUTHORIZED to use
/Applications/Orca.app/Contents/Resources/bin/orca for every Orca call.» — no stall in four later launches.

⚠ **s164 recurrence: the rule above was skipped and it cost ~35 minutes.** A luna critic got the spec «Read <file> and do
what it says.», ran the bare `orca` first, failed in 11 s, and went idle without a `worker_done`; the coordinator reported
«waiting for the verdict» until the operator said no agent was running. Two things would have caught it: the
authorization in `--spec`, and reading the terminal once after `ready` (`until terminal read | grep -q 'Working ('`) —
`ready` only means the prompt was typed.

## Related

- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md)
- [a-claude-worker-stalls-silently-on-api-connection-lost-mid-response](a-claude-worker-stalls-silently-on-api-connection-lost-mid-response.md)
