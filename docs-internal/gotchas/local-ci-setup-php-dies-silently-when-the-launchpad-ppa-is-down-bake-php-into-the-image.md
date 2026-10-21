# Gotcha: [tooling/local-ci] — `Set up PHP` under local-ci dies with no error when the Launchpad PPA is down; bake PHP into the image

> Tags: local-ci, setup-php, docker, ppa | Session: s191 (21.10.2026) | **Measured on:** macOS, local-ci 0.18.1, OrbStack

## What happens

A local-ci run of a plugin workflow failed at `Set up PHP` for PHP 7.4 with no error in the step output — the step simply ended red. Nothing in
the code under test had changed. The cause was a Launchpad outage (HTTP 503, then ~50 kB/s) on the day.

## Root cause

Under local-ci `shivammathur/setup-php` runs as `runner=self-hosted`, which **installs PHP from the ondrej PPA at job time** and hides
apt's output. When Launchpad is down or throttled the install dies, and the hidden output leaves no message to read. The default local-ci image
is the bare runner, so every job pays this network install again, and a flaky PPA fails otherwise-green runs.

## Fix

❌ Re-run until Launchpad recovers; or read the step as a code failure.

✅ Install the PHP versions the matrix needs into `.github/local-ci.Dockerfile` once (PHP 7.4 and 8.3 are baked into
`woodev-russian-post` `v2`, commit `6c7647b`; the same change is carded for CDEK `edostavka#128` and Yandex `yandex#130`). The step then finds
PHP already present and the PPA is not touched at job time.

Also: **never run two local-ci runs at once** — in s191 a REAL 7.4 failure looked like a flake because two runs overlapped on one Docker host. Run
one at a time, read the log, and re-run a suspected flake ONCE alone (see [run-local-ci-requires-run-subcommand](run-local-ci-requires-run-subcommand.md)).

## Related

- [run-local-ci-requires-run-subcommand](run-local-ci-requires-run-subcommand.md) — the other local-ci traps (`run`, `npx -y`, container-name collisions)
- [every-ci-job-failing-in-two-seconds-is-a-billing-block](every-ci-job-failing-in-two-seconds-is-a-billing-block.md) — why private repos gate on local-ci at all
