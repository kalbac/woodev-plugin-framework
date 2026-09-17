# After the Mac sleeps, OrbStack can hang — and every rig call hangs with it, silently

**Namespace:** `[tooling/macos]`
**Found:** s137 (17.09.2026), on the MacBook.

## The trap

The laptop went to sleep mid-session. Afterwards:

- `curl -m 20 http://localhost:8973/…` returned `000` after **900 seconds** — the `-m` limit did
  not save it;
- `docker ps` never returned at all;
- an Orca `check --wait` in the same window came back `outcome_unknown`, and a Sonnet worker's
  turn ended with *"Your computer went to sleep mid-response"* — its commit was made, its
  `worker_done` was never sent.

Nothing printed an error. Every symptom reads as "the rig is slow" or "the worker died".

The first recovery attempt made it worse: a wait loop written as
`until timeout 5 docker info …` spun silently — **macOS ships no `timeout` (nor `gtimeout`
without coreutils)**, so every iteration failed with `command not found` and the loop simply ran
out its count.

## ✅ What to do

- After a sleep, probe Docker with a hard bound before trusting anything:
  `perl -e 'alarm 15; exec @ARGV' docker ps`. No output within the bound = OrbStack is hung.
- Restart it: `osascript -e 'quit app "OrbStack"'`, wait for the process to go, `open -a OrbStack`,
  then `npx @wordpress/env start` (the rig containers come back `Exited`; volumes are intact —
  never prune). ⚠ This restarts every other project's containers too (open-warehouse-saas) — ask
  first.
- On macOS bound any command with `perl -e 'alarm N; exec @ARGV' <cmd>`, never `timeout`.
- Kill the hung shells you left behind (`pgrep -fl curl`), or they keep holding the old socket.
- A worker whose turn ended on the sleep has usually committed its work: read its screen, then
  ask it (`orca terminal send`) to send the missing `worker_done` — do not relaunch.

## Related

- [an-oom-killed-check-wait-reads-as-an-empty-timeout](an-oom-killed-check-wait-reads-as-an-empty-timeout.md) — the same "silence is not death" rule for Orca waits
- [../wiki/two-machine-setup.md](../wiki/two-machine-setup.md) — the laptop runs OrbStack, not Docker Desktop
