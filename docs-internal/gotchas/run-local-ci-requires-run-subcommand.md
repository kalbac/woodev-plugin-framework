# Gotcha: [tooling/local-ci] — `run-local-ci` requires the `run` subcommand
> Tags: local-ci, npx, command-line | Session: s178

Calling `npx run-local-ci --all …` omits the CLI's `run` subcommand, prints usage and exits 1. The failure is command shape, not a repository check failure.

❌ **Wrong:**

```sh
npx run-local-ci --all --commit-status
```

✅ **Correct:**

```sh
npx run-local-ci run --all --commit-status --github-token "$(gh auth token)"
```

## Related

- [every-ci-job-failing-in-two-seconds-is-a-billing-block.md](every-ci-job-failing-in-two-seconds-is-a-billing-block.md) — local-ci runs the repository workflow locally
- [../gotcha-index/tooling.md](../gotcha-index/tooling.md) — tooling index
