# Stopping a running `watch-workers.py` loses the deliveries it already acked

**Namespace:** `[tooling/orca]`
**Found:** s232 (16.11.2026), overnight wave with 4–6 workers and critics on one Run.

## Trap

`watch-workers.py` takes the Run's mail (it acks each delivery) and prints it before it exits. A coordinator that restarts the
watchdog to add a new handle — `pkill -f watch-workers…`, or `TaskStop` — kills it between the ack and the print, or discards its
output file. The acked `worker_done` is then gone from `check`, and nothing wakes the coordinator: in s232 two reports (a critic's
verdict and a fix round) vanished this way, and a third watchdog started with `&` and `> /dev/null` would have swallowed every
delivery.

## ❌ Wrong

```bash
pkill -f "watch-workers.py term_A"                       # its acked mail dies with it
python3 watch-workers.py term_A term_B > /dev/null 2>&1 &  # acks and discards everything
```

## ✅ Correct

- Start ONE watchdog per wave as a tracked background task (its output file is the record) and let it exit on its own.
- When a handle must be added, start the new watchdog only after the old one has exited, or read the old one's output file
  after stopping it.
- After any watchdog restart, read what `check` already handled:
  `orca orchestration inbox --terminal run:<run_id> --limit 5 --json`.

## Related

- [the-orca-team-watchdog-reports-exited-for-a-terminal-that-was-released-on-purpose](the-orca-team-watchdog-reports-exited-for-a-terminal-that-was-released-on-purpose.md)
