# `git add -A` in a fresh worktree sweeps CRLF→LF normalisation of files you never touched into your commit

**Topic:** `[build/*]`
**Discovered:** s71 (13.08.2026), committing the #288 follow-up from a worktree

## What happens

Several JS assets in this repo are stored with CRLF in the working tree while `.gitattributes` normalises to LF on add. In a **freshly created worktree** git has not yet touched those files, so the first `git add -A` normalises every one of them and stages the change. A two-file fix becomes a six-file commit:

```
 tests/unit/Api/ApiBaseSanitizedHeadersTest.php     |  37 ++++
 woodev/api/class-api-base.php                      |  11 +-
 .../assets/js/admin/jquery.jquery-confirm.min.js   |  18 +-      ← never opened
 .../js/admin/woodev-admin-job-batch-handler.js     | 210 +++---   ← never opened
 woodev/assets/js/admin/woodev-admin-script.js      | 182 +++---   ← never opened
 .../js/frontend/woodev-payment-gateway-frontend.js | 162 +++---   ← never opened
```

The warnings git prints (`CRLF will be replaced by LF the next time Git touches it`) scroll past above the push output and are easy to miss, and the payment-gateway asset in that list is production code in a tree the change had nothing to do with.

## Fix

Stage explicitly, or check before committing:

```bash
❌ git add -A
✅ git add <the files you actually changed>
✅ git show --stat --format="" HEAD    # always, before pushing from a fresh worktree
```

If it already landed: `git reset --soft HEAD~1`, `git restore --staged <the swept files>`, recommit. The worktree copies stay CRLF and simply show as modified — harmless if the worktree is temporary.

## Related

- [[build-artifacts-eol-lf-windows-parity]] — the `.gitattributes` rule that makes this normalisation happen at all
- [[jest-scans-agent-worktrees-inside-the-repo]] — the other reason agent worktrees belong OUTSIDE the repository
