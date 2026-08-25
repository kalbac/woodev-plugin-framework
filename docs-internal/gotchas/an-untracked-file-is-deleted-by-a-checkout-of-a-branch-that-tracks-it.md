# gotcha: a newly-untracked file is DELETED by checking out an older branch and back

**Namespace:** `[tooling/git]`
**Discovered:** s93 (2026-08-25), minutes after `next-session-prompt.md` was untracked

## What happens

You stop tracking a file on `main` (`git rm --cached` + `.gitignore`) and keep it on disk. Then
you check out any branch created **before** that commit — where the file is still tracked — and
come back:

```bash
git checkout main                      # file present, untracked, ignored
git checkout feat/older-branch         # git WRITES the tracked version over it
git checkout main                      # git DELETES it — that branch tracked it, main does not
```

The file is gone. Not modified, not stashed — **gone**, with no warning, because from git's point
of view it removed a tracked file that the target branch does not have. `.gitignore` does not
protect it: ignore rules apply to files git is not managing, and on the other branch git IS
managing it.

An untracked-and-ignored file only survives a checkout while **no branch involved tracks it**.

## Why it bites here specifically

`docs-internal/next-session-prompt.md` is deliberately untracked (this repo is public — see
`.gitignore` and the `woodev-docs-system` skill). Every feature branch cut before that change
still tracks it. So the ordinary act of switching to a feature branch to verify something on the
rig, then switching back, silently destroys the handoff — the one file the next session is
required to read first.

It also destroys `next-session-prompt.md.prev`, the snapshot the handoff drop-check falls back to,
which would quietly disable that gate as well.

## ✅ What to do

**Back it up before any branch switch, restore after:**

```bash
cp docs-internal/next-session-prompt.md /tmp/handoff.keep
git checkout <other-branch>
# … work …
git checkout main
cp /tmp/handoff.keep docs-internal/next-session-prompt.md
```

`npm run lint:docs` fails loudly when the file is missing (it is in the session-start set twice
over), so the loss is caught — but it is caught **after** the content is already gone. The backup
is what saves it.

The exposure ends by itself once every branch predating the untracking commit is merged or
deleted. Until then, treat the handoff as a file that does not survive `git checkout`.

## Related

- [rig-serves-the-working-tree-branch-switch-reverts-fixes](rig-serves-the-working-tree-branch-switch-reverts-fixes.md) — the other reason branch switching here is not free
- `../DOCS-SCHEMA.md` → Handoff Format — why the file is untracked at all
