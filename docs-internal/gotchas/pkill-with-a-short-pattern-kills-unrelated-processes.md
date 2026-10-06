# `pkill -f` with a short pattern kills processes far outside the command you meant

**Namespace:** `[tooling/shell]`
**Discovered:** s157 (06.10.2026), MacBook

## What happens

To stop one stuck `cat` started by a tool call, the coordinator ran `pkill -f "cat" -P $$`. The `-f` pattern matches
the FULL command line, so any process with `cat` anywhere in its argv qualifies, and `-P $$` did not confine it as
expected in the harness shell. The result: **OrbStack died (the whole Docker daemon, so the wp-env rig went down)**,
the context7 MCP server disconnected, and three background wait loops were killed — in one keystroke, with no error.

## ✅ Correct

Kill by PID you have just read, never by a substring:

```bash
pgrep -fl 'exact full command' # look first
kill <pid>                     # then the one PID
```

For a background tool command, stop it through the harness (its task id), not `pkill`.

❌ Wrong: `pkill -f "cat"`, `pkill -f php`, `pkill -f term_…` with any pattern shorter than the whole command.

If it already happened: `open -a OrbStack`, wait for `docker info`, then `npx @wordpress/env start` — the rig's
containers do not restart on their own.

## Related

- `docs-internal/wiki/local-rig.md` — starting the rig
