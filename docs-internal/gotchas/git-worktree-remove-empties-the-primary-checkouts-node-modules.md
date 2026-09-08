# `git worktree remove` on an Orca worktree EMPTIES the primary checkout's `node_modules`

> Namespace: `tooling/*` — added session 127 (2026-09-08), after doing it to this repo. The rule it
> breaks was already written down; this file records what happens when you break it, because the
> symptom appears an hour later and points nowhere near the cause.

## What happens

`orca.yaml` declares `worktree.sharedDirectories: [node_modules]`, and on Windows Orca materialises
that share as a **directory symlink** inside each worktree. Remove such a worktree with plain git:

```bash
git worktree remove --force .orca/worktrees/<repo>/<name>
```

…and git walks into the symlink and deletes **the contents of its target** — the primary checkout's
own `node_modules` — instead of unlinking. The worktree disappears, git reports success, and nothing
warns you.

## The symptom, and why it misleads

Nothing fails at the time. It fails at the next npm script, with:

```text
'wp-scripts' is not recognized as an internal or external command
```

That reads as a PATH problem or a broken `package.json`, and `npm run build` demonstrably worked an
hour earlier on the same tree, which makes it read as *something the session did to the repo*. The
real state is one `ls`:

```bash
ls node_modules | wc -l     # 0 — the directory exists and is empty
ls node_modules/.bin | wc -l # 0
```

Everything local dies together — `build`, `test:js`, `typecheck`, `lint:ts-baseline`,
`lint:phone-masks`, `lint:i18n-sources`. **CI is unaffected** (it installs its own), and so is the
rig (it serves PHP plus committed bundles), which widens the gap between "my gates are dead" and
"anything is actually wrong with the code".

## ✅ Correct

**Remove an Orca worktree through Orca.** `CURRENT-STATE.md` has said so since s83; this file exists
because the rule was followed until Orca refused, and then abandoned:

```bash
orca worktree remove --worktree "id:<repoId>::<absolute/path>" --json
```

When it refuses with `runtime_error … Failed to delete worktree … M <files>`, that refusal is
**about the worktree being dirty**, and every one of these worktrees is permanently dirty on the
CRLF-only files (`woodev/assets/js/**`, `.github/**`) that a fresh checkout is born with. Resolve
*that* — or leave the worktree in place for the operator — but do not substitute raw git, which does
not know the directory is shared.

## Recovery

```bash
npm ci        # several minutes; restores node_modules from the lockfile
```

Nothing is lost beyond the time: `node_modules` is gitignored and fully described by
`package-lock.json`. But if this happens with agents in flight, **every worktree sharing that
directory is broken too**, and their npm gates will fail in exactly the same misleading way.

## Related

- [sharing-vendor-breaks-composer-autoload-in-a-worktree](sharing-vendor-breaks-composer-autoload-in-a-worktree.md) — why `vendor` is COPIED and `node_modules` is SHARED, which is what makes this asymmetry possible
- [a-fresh-worktree-is-born-dirty-on-four-js-files](a-fresh-worktree-is-born-dirty-on-four-js-files.md) — the dirt that makes Orca refuse the clean removal
- `docs-internal/wiki/orchestrating-agents-with-orca.md` — worktree layout and the sharing config
