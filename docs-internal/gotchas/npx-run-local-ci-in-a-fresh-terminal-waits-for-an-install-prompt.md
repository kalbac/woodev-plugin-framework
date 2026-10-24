# gotcha: `npx run-local-ci` in a fresh terminal waits forever on «Ok to proceed? (y)»

**Namespace:** `[tooling/local-ci]`
**Discovered:** s195 (2026-10-24), run-local-ci 0.18.1

## Symptom

Three merge-evidence local-ci runs started in new Orca terminals produced no job output for many minutes; the coordinator's
waiter (polling for an exit marker) never fired. Each terminal was parked on npx's «Need to install the following packages:
run-local-ci@0.18.1 — Ok to proceed? (y)».

## Root cause

`npx` asks before installing a package that is not in the local cache for that shell; a non-interactive launch has nobody to
answer.

## ❌ Wrong

```bash
npx run-local-ci run -w .github/workflows/ci.yml --commit-status --github-token "$(gh auth token)"
```

## ✅ Correct

```bash
npx -y run-local-ci run -w .github/workflows/ci.yml --commit-status --github-token "$(gh auth token)"
```

## Also (s196): the `run` subcommand is required

`npx -y run-local-ci -w .github/workflows/ci.yml …` (no `run`) prints the usage text and exits — a waiter that greps for an
exit marker reads it as a finished run. Always `npx -y run-local-ci run --workflow <file> …`, and read `Status: ✓ N passed`
from the log, not only the exit code. Under zsh, `${PIPESTATUS[0]}` is empty — run the wrapper with `#!/bin/bash`.

## Related

- [a-coordinator-terminal-consumes-one-orca-run-at-a-time](a-coordinator-terminal-consumes-one-orca-run-at-a-time.md)
