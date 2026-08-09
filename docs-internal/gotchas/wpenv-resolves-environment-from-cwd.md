# wp-env resolves its environment from the current working directory

**Namespace:** `[testing/integration]` — added s60 (2026-08-09)

## The trap

`wp-env` identifies "its" Docker environment by hashing the config found from the **current
working directory**. Running any `npx wp-env run …` from a subdirectory of the repo computes a
different install hash and fails with:

```
✖ Environment not initialized. Run `wp-env start` first.
```

— while the containers are up and perfectly healthy. Nothing in the message points at cwd.

## What it cost

- **s59, trap 8 of the handoff:** "`wp config get` returns empty for override constants; could not
  extract `WOODEV_TEST_YANDEX_SANDBOX_TOKEN`" — this **blocked the #214 measurement** (spread of
  address forms across 812 live Yandex points). The recorded explanation was wrong: the command
  had been run from the wrong directory.
- **s60 reproduced both directions:** `wp config list` from `docs-internal/` → "Environment not
  initialized"; the identical command from the repo root → every constant prints, including the
  sandbox token. #214 is measurable after all.

## Rules

1. **Always run `wp-env` commands from the repo root** (`D:\Projects\woodev_framework`). A `cd`
   left over from earlier shell work is enough to break them.
2. Combine with `MSYS_NO_PATHCONV=1` for any command carrying container paths — see
   [[wpenv-windows-gitbash-path-mangling]].
3. Related fact observed s60: constants written with `wp config set` live in the container's
   `wp-config.php` and **survive `npx wp-env start` restarts** — the rig can carry state that no
   `.wp-env*.json` file declares. When auditing rig state, trust `wp config list` (from the root),
   not the config files.

## Related

- [[wpenv-windows-gitbash-path-mangling]] — the other way the same command family fails on this box
- [[rig-serves-the-working-tree-branch-switch-reverts-fixes]] — name branch + constants before asking anyone to look
