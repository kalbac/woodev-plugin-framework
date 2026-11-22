# Gotcha: [tooling/local-ci] — a waiter that greps an Orca terminal for `LOCALCI_EXIT=` matches the command's own echo; require digits
> Tags: local-ci, orca, terminal, waiter | Session: s241

## What happens

The merge-evidence local-ci run is started in a visible Orca terminal as
`npx -y run-local-ci run -w .github/workflows/ci.yml --commit-status; echo LOCALCI_EXIT=$?`, and a background loop polls
`orca terminal read` for the marker. With `grep -o 'LOCALCI_EXIT=[0-9]*'` the loop ends at once: the terminal buffer holds the typed
command line, `LOCALCI_EXIT=$?`, and `[0-9]*` matches zero digits. s241 reported a finished run that had only reached «Set up PHP».

## Root cause

`terminal read` returns the whole buffer, the echoed command included; `*` allows an empty match.

## Fix

```bash
# ❌ matches «LOCALCI_EXIT=» in the typed command
grep -o 'LOCALCI_EXIT=[0-9]*'

# ✅ only the real exit line
grep -oE 'LOCALCI_EXIT=[0-9]+'
```

Report the exit code AND the run's own summary line (`✓ 6 passed (6 total)`), never the marker alone.

## Related

- [local-ci-github-token-flag-puts-the-token-on-argv](local-ci-github-token-flag-puts-the-token-on-argv.md)
- [run-local-ci-requires-run-subcommand](run-local-ci-requires-run-subcommand.md)
