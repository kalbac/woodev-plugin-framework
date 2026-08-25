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

## How it bit here

`docs-internal/next-session-prompt.md` was untracked for about an hour on 25.08.2026 (the repo was
public then). Every feature branch cut before that change still tracked it, so the ordinary act of
switching to a feature branch to drive the rig and switching back destroyed the handoff — the one
file the next session is required to read first. It happened twice within minutes.

**That specific exposure is over**: the repo went private the same evening and the handoff is
tracked again. The trap itself is not project-specific and stays recorded, because the setup
recurs — any `git rm --cached` + `.gitignore` on a file that older branches still track reproduces
it exactly, and the `woodev-docs-system` skill still mandates that setup for PUBLIC repos.

It also destroys `next-session-prompt.md.prev`, the snapshot the handoff drop-check falls back to
when the handoff is untracked — quietly disabling that gate along with it.

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
deleted. Until then, treat such a file as one that does not survive `git checkout`.

## Related

- [rig-serves-the-working-tree-branch-switch-reverts-fixes](rig-serves-the-working-tree-branch-switch-reverts-fixes.md) — the other reason branch switching here is not free
- `../DOCS-SCHEMA.md` → Handoff Format — why the file is untracked at all
