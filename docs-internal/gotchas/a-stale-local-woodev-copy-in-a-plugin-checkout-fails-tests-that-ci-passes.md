# Gotcha: [tooling/worktrees] — a stale local `woodev/` in a plugin checkout fails tests that CI passes, and every worker worktree inherits it
> Tags: woodev-copy, sync-framework, worktreeinclude, baseline | Session: s205

## What happens

A plugin repo (CDEK, Yandex, Russian Post, T-Bank, Ozon) vendors the framework as a **gitignored** `woodev/` directory; CI builds it from
the framework tag pinned in `ci.yml`. The copy in a developer's primary checkout is whatever was synced last. In s205 it had drifted:
Yandex (pin `v2.0.15`) failed `YandexMigrationWizardTest` locally — an eager `Setup_Wizard` constructor from an old copy — while CI
was green; CDEK differed from `v2.0.16` in 146 files and Russian Post from `v2.0.15` in 68.

`.worktreeinclude` **copies the primary checkout's `woodev/` into every worker worktree**, so each worker starts on the stale copy and
reports a baseline failure that is not in the code. A critic or worker then "fixes" a test that was never broken.

## Root cause

`woodev/` is not tracked, so no `git pull` refreshes it; only `scripts/sync-framework.sh` does, and nothing re-runs it when the pin
in `ci.yml` moves.

## Fix

Re-sync from the pinned tag, never from the framework's `main`:

```bash
# ✅ extract the exact pinned tag, then sync from it
mkdir -p "$TMPDIR/woodev-pin" && git -C <framework checkout> archive v2.0.16 woodev | tar -x -C "$TMPDIR/woodev-pin"
WOODEV_FRAMEWORK_DIR="$TMPDIR/woodev-pin" bash scripts/sync-framework.sh
```

```bash
# ❌ syncing from a framework checkout that sits on main or an old branch — a different version than CI uses
bash scripts/sync-framework.sh
```

Re-sync **after every pin bump**, before creating worker worktrees. A baseline failure in an untouched plugin test is first a stale
`woodev/`, then a bug. All three plugins were re-synced in s205.

## Related

- [a-stale-primary-checkout-degrades-every-worktree-made-from-it](a-stale-primary-checkout-degrades-every-worktree-made-from-it.md) — the same inheritance path through `.worktreeinclude`
- [a-fresh-cdek-plugin-worktree-has-no-vendor-and-no-woodev](a-fresh-cdek-plugin-worktree-has-no-vendor-and-no-woodev.md) — the opposite case: no copy at all
