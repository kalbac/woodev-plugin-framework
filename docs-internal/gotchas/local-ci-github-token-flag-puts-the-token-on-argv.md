# Gotcha: [tooling/local-ci] — `--github-token "$(gh auth token)"` puts the token on argv; pass `LOCAL_CI_GITHUB_TOKEN` instead
> Tags: local-ci, secrets, argv, commit-status | Session: s205

## What happens

`run-local-ci run … --commit-status --github-token "$(gh auth token)"` expands the token into the process's command line. Any
`ps` run under the same user session — including by an agent working in another terminal — prints it in clear text, and an agent's
transcript can then keep it.

## Root cause

A secret passed as an argument is world-readable to the user's processes for the whole run. `run-local-ci` 0.18.x also reads the token
from the environment, which `ps` does not show.

## Fix

```bash
# ❌ the token is in argv for the whole run
npx -y run-local-ci run -w .github/workflows/ci.yml --commit-status --github-token "$(gh auth token)"

# ✅ the token stays in the environment
export LOCAL_CI_GITHUB_TOKEN="$(gh auth token)"
npx -y run-local-ci run -w .github/workflows/ci.yml --commit-status
```

Export it in the coordinator's own shell (not in a file, not in a brief). The other local-ci gotchas used to show the flag and were
corrected in s205. If a secret did reach a transcript, rotation is the operator's decision.

## Related

- [run-local-ci-requires-run-subcommand](run-local-ci-requires-run-subcommand.md) — the command shape
- [npx-run-local-ci-in-a-fresh-terminal-waits-for-an-install-prompt](npx-run-local-ci-in-a-fresh-terminal-waits-for-an-install-prompt.md) — the unattended form
