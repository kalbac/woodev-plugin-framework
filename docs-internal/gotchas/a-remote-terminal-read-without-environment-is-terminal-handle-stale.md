# gotcha: reading a Homelab worker's terminal without `--environment` answers `terminal_handle_stale` — and the watchdog silently skips it

**Namespace:** `[tooling/orca]`
**Discovered:** s186 (2026-10-17), Orca 1.4.227

## What happens

`worker-start --on Homelab` returns a normal `term_…` handle. A plain
`orca terminal read --terminal <that handle> --screen --json` from the Mac coordinator answers:

```json
{"ok": false, "error": {"code": "terminal_handle_stale", "message": "terminal_handle_stale"}}
```

The terminal is alive; the handle is only resolvable on the connected server. The canonical watchdog
(`~/.claude/skills/orca-team/references/watch-workers.py`) treated the failed read as "no data" and kept going,
so a Homelab worker was **never watched** — exactly the stall the watchdog exists to catch.

## ❌ Wrong

```bash
python3 watch-workers.py term_<homelab-handle> term_<mac-handle>
```

## ✅ Correct

```bash
orca terminal read --environment Homelab --terminal term_<homelab-handle> --screen --json
python3 watch-workers.py Homelab:term_<homelab-handle> term_<mac-handle>
```

The script (patched s186) takes `<environment>:<handle>` and now ALERTS on a failed read instead of skipping it.

## Related

- [run-a-terminal-watchdog note in the orca wiki](../wiki/orchestrating-agents-with-orca.md) — «Workers in woodev-ru on Homelab»
- [the-worker-watchdog-re-alerts-on-a-stale-api-error-line](the-worker-watchdog-re-alerts-on-a-stale-api-error-line.md) — the other watchdog false signal
