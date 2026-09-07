# Gotcha: [git/eol] — A fresh worktree of this repo is born DIRTY on four JS files, and it looks exactly like a tool that just reformatted them

> Tags: git, orchestration, windows | Session: s125

## What happens

Create any new worktree — Orca's or a plain `git worktree add` — and `git status` is dirty before
anyone has touched anything:

```text
 M woodev/assets/js/admin/jquery.jquery-confirm.min.js
 M woodev/assets/js/admin/woodev-admin-job-batch-handler.js
 M woodev/assets/js/admin/woodev-admin-script.js
 M woodev/payment-gateway/assets/js/frontend/woodev-payment-gateway-frontend.js
```

The diff is **pure line-ending churn** — every line of the file shown as changed, no content
difference, 286 insertions and 286 deletions across the four:

```text
warning: in the working copy of '…/woodev-admin-script.js', CRLF will be replaced by LF the next time Git touches it
@@ -1,92 +1,92 @@
-'use strict';
+'use strict';
```

`git checkout -- <path>` does **not** clear it. The files come straight back.

## Why

Those four blobs are stored in history **with CRLF**, while `.gitattributes` (added 06.09.2026) now
declares them text and `core.autocrlf` is `true`. Checkout writes the working copy one way, `diff`
normalises it the other, and the mismatch is permanent until the blobs are renormalised.

The main checkout does not show it, because its working files predate the attribute change and
already match. **Only new worktrees are affected** — which is exactly where agents work.

## Why it matters more than it looks

1. **A worker that runs `git add -A` commits 286 lines of invisible churn** into an unrelated PR.
2. **It frames the wrong suspect.** In s125 the churn appeared in a worker's tree right after it ran
   `phpcbf`, and the coordinator told the worker phpcbf had done it. It had not. A throwaway
   `git worktree add --detach HEAD` reproduced the identical four-file dirt with no tool run at all —
   which is the cheap test that settles it.

## Correct

- **Never `git add -A` in a worktree here.** Add your own files by path.
- Before blaming a formatter for line-ending churn, create a throwaway worktree from `HEAD` and look
  at `git status`. If the same files are dirty there, no tool did it.
- The real fix is to renormalise those four blobs (`git add --renormalize`) in a commit of its own,
  so new worktrees are born clean.

## Related

- [serena-replace-content-eol-flip](serena-replace-content-eol-flip.md) — a genuine tool-caused EOL
  flip, which is what this one impersonates
