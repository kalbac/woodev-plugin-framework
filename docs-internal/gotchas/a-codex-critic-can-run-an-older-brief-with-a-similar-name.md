# Gotcha: [tooling/codex] — A Codex critic can follow an OLDER brief from the same scratchpad folder
> Tags: tooling/codex, tooling/orca | Session: s158

## What happens

A re-check critic was pointed at `c7b.md`; it read the earlier `c7.md` from the same folder,
reviewed the old commit, wrote over the old report file and answered "APPROVE" — with the OLD
canary. The real question of the re-check was never answered.

## Root cause

Several briefs with near-identical names sit side by side; the model picks the one it already knows.

## Fix

✅ Give every brief a UNIQUE canary and check it in the report body every time — a mismatched
canary means the critic followed the wrong brief, so the verdict is void. Re-dispatch with the
exact file name and the HEAD commit stated in the one-line spec.

## Related

- [codex-shell-sandbox-broken-windows.md](codex-shell-sandbox-broken-windows.md) — where the canary rule comes from.
