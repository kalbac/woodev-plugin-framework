# Gotcha: [rig/wp-env] — `npx wp-env` installs a STUB package; the rig never starts and nothing says so
> Tags: rig, wp-env, npm, npx, two-machines | Session: s136
> **Measured on:** macOS laptop, 13.09.2026 (first setup there). It bites any checkout where
> `@wordpress/env` is not already installed — the Windows desktop and CI both happen to have it.

## What happens

`scripts/machine/rig-state-import.sh` starts the rig when it is not running, then looks for the
container. On the laptop it printed:

```text
• dev rig not running — starting it (first start downloads images; minutes)
npm warn exec The following package was not found and will be installed: wp-env@1.0.1
Please run the command 'npx @wordpress/env <command>' instead.
✗ the rig did not come up — check docker and npx wp-env start output
```

Docker was healthy, the transfer bundle was intact, and the message points at both. Neither was the
problem. **`npx wp-env` exits 0**, so a caller that checks the exit status learns nothing — only the
missing container gives it away, one step later and under a misleading message.

## Root cause

`wp-env` and `@wordpress/env` are two different packages on npm. The real tool is
**`@wordpress/env`**; the unscoped **`wp-env`** is a stub whose whole behaviour is to print
*"Please run the command `npx @wordpress/env <command>` instead."* and exit successfully.

`npx wp-env` finds a local `node_modules/.bin/wp-env` first — which is why the invocation works
wherever `@wordpress/env` is installed. **This repo does not depend on it**: `package.json` has no
`@wordpress/env` in `devDependencies`, so `node_modules/.bin/wp-env` does not exist. On a machine
that also has no global install, npx falls through to the registry and fetches the stub.

⚠ The desktop and CI are not evidence that `npx wp-env` is correct — they are evidence that those
two environments happen to resolve the binary some other way.

## Fix

Name the package, never the bare binary:

```sh
# ❌ wrong — resolves to the stub wherever @wordpress/env is not already installed
npx wp-env start
npx wp-env run tests-cli …

# ✅ correct — always the real tool, no install step needed
npx @wordpress/env start
npx @wordpress/env run tests-cli …
```

Fixed in all four `scripts/machine/*.sh` (s136). `.github/workflows/integration-tests.yml` still
says `npx wp-env` and is GREEN, so it was deliberately left alone — a workflow edit touches the merge
gate and needs its own measurement.

## Related

- [wpenv-resolves-environment-from-cwd](wpenv-resolves-environment-from-cwd.md) — the other way a wp-env command silently addresses the wrong thing
- [grep-q-under-pipefail-turns-a-successful-match-into-a-failed-pipeline](grep-q-under-pipefail-turns-a-successful-match-into-a-failed-pipeline.md) — the other defect the same first run surfaced
- [../wiki/two-machine-setup.md](../wiki/two-machine-setup.md) — the scripts this was measured in
