# Gotcha: [tooling/local-ci] — `run-local-ci` requires the `run` subcommand
> Tags: local-ci, npx, command-line | Session: s178

Calling `npx run-local-ci --all …` omits the CLI's `run` subcommand, prints usage and exits 1. The failure is command shape, not a repository check failure.

❌ **Wrong:**

```sh
npx run-local-ci --all --commit-status
```

✅ **Correct:**

```sh
npx run-local-ci run --all --commit-status
```

## s184 additions (16.10.2026)

- **`npx` without `-y` hangs an unattended run** on «Ok to proceed?». The unattended form is
  `npx -y run-local-ci run -w <workflow file>` — the `run` subcommand is still required.
- **Two local-ci runs at once can collide on generated container names (Docker 409).** Serialize merge-evidence runs; do not start
  the second until the first has finished (the existing flake rule: re-run ONCE alone). **s191:** a REAL PHP 7.4 failure looked like a flake
  for exactly this reason, because two runs overlapped on one Docker host.
- A silent red `Set up PHP` is a Launchpad outage, not your code:
  [local-ci-setup-php-dies-silently-when-the-launchpad-ppa-is-down-bake-php-into-the-image](local-ci-setup-php-dies-silently-when-the-launchpad-ppa-is-down-bake-php-into-the-image.md).

## s197 addition (26.10.2026, MacBook)

- **`/var/run/docker.sock` can be missing on the Mac** (OrbStack serves `~/.orbstack/run/docker.sock`; the `default` Docker context
  points at the missing path). local-ci then dies before any job with «couldn't use a Docker socket at /var/run/docker.sock». Pass the
  socket explicitly: `LOCAL_CI_DOCKER_HOST=unix://$HOME/.orbstack/run/docker.sock npx -y run-local-ci run -w .github/workflows/ci.yml …`.

> **Token (s205):** `--commit-status` needs a GitHub token; export `LOCAL_CI_GITHUB_TOKEN` in the shell and never pass `--github-token` — the flag puts the token on argv: [local-ci-github-token-flag-puts-the-token-on-argv](local-ci-github-token-flag-puts-the-token-on-argv.md).

## Related

- [npx-run-local-ci-in-a-fresh-terminal-waits-for-an-install-prompt](npx-run-local-ci-in-a-fresh-terminal-waits-for-an-install-prompt.md) — owner of the `npx -y` rule summarised above
- [local-ci-on-orbstack-needs-local-ci-docker-host-not-docker-host](local-ci-on-orbstack-needs-local-ci-docker-host-not-docker-host.md) — owner of the OrbStack `LOCAL_CI_DOCKER_HOST` rule summarised above
- [local-ci-setup-php-dies-silently-when-the-launchpad-ppa-is-down-bake-php-into-the-image](local-ci-setup-php-dies-silently-when-the-launchpad-ppa-is-down-bake-php-into-the-image.md) — PHP baked into the image
- [every-ci-job-failing-in-two-seconds-is-a-billing-block.md](every-ci-job-failing-in-two-seconds-is-a-billing-block.md) — local-ci runs the repository workflow locally
- [../gotcha-index/tooling.md](../gotcha-index/tooling.md) — tooling index
