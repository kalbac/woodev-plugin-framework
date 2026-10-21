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

## s184 additions (16.10.2026)

- **`npx` without `-y` hangs an unattended run** on «Ok to proceed?». The unattended form is
  `npx -y run-local-ci run -w <workflow file>` — the `run` subcommand is still required.
- **Two local-ci runs at once can collide on generated container names (Docker 409).** Serialize merge-evidence runs; do not start
  the second until the first has finished (the existing flake rule: re-run ONCE alone). **s191:** a REAL PHP 7.4 failure looked like a flake
  for exactly this reason, because two runs overlapped on one Docker host.
- A silent red `Set up PHP` is a Launchpad outage, not your code:
  [local-ci-setup-php-dies-silently-when-the-launchpad-ppa-is-down-bake-php-into-the-image](local-ci-setup-php-dies-silently-when-the-launchpad-ppa-is-down-bake-php-into-the-image.md).

## Related

- [local-ci-setup-php-dies-silently-when-the-launchpad-ppa-is-down-bake-php-into-the-image](local-ci-setup-php-dies-silently-when-the-launchpad-ppa-is-down-bake-php-into-the-image.md) — PHP baked into the image
- [every-ci-job-failing-in-two-seconds-is-a-billing-block.md](every-ci-job-failing-in-two-seconds-is-a-billing-block.md) — local-ci runs the repository workflow locally
- [../gotcha-index/tooling.md](../gotcha-index/tooling.md) — tooling index
