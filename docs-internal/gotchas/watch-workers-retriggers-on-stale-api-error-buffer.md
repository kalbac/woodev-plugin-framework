# Gotcha: [tooling/orca] — the worker watchdog can retrigger on a stale API error
> Tags: orca, watchdog, worker, terminal | Session: s178

`watch-workers.py` can alert on an `API Error` line that remains in a terminal's scrollback. After a nudge resumes the worker, the same old line can trigger the watchdog again immediately, making a recovered worker look stalled.

❌ **Wrong:** scan the full terminal buffer repeatedly and treat every matching line as new output.

✅ **Correct:** exclude the already-alerted terminal or compare output against a captured cursor/snapshot and match only newly appended lines. A terminal nudge can resume a Sonnet worker after `Connection lost mid-response`.

## Related

- [a-claude-worker-stalls-silently-on-api-connection-lost-mid-response.md](a-claude-worker-stalls-silently-on-api-connection-lost-mid-response.md) — recovery by nudging a worker after an API disconnect
- [../gotcha-index/tooling.md](../gotcha-index/tooling.md) — tooling index
- [the-worker-watchdog-re-alerts-on-a-stale-api-error-line](the-worker-watchdog-re-alerts-on-a-stale-api-error-line.md) — the same trap seen after a nudge (duplicate; the other index line is `tooling.md` s199)
