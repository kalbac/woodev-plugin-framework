# Gotcha: [tooling/git] — `git push` hangs forever and prints NOTHING under Git Credential Manager
> Tags: tooling, git, windows, headless | Session: s97

## What happens

`git push` from an agent session never returns. No output, no error, no prompt — the command
just sits there until the tool's timeout kills it. `git fetch`, `git ls-remote` and every `gh`
command work normally on the same remote, which makes it read as "the push is slow" or "the
network is flaky" rather than as a blocked call.

Measured in s97: three attempts, 2 min / 45 s / 120 s, all silent. `GIT_TRACE=1
GIT_CURL_VERBOSE=1` showed the TLS handshake completing and
`GET /…/info/refs?service=git-receive-pack` being sent — so the network was never the problem.

## Root cause

`git config --get credential.helper` is `manager` — Git Credential Manager. For a WRITE it wants
to re-authenticate, and it does that by opening a **GUI dialog**. In an agent session nobody ever
sees that window, so the helper waits forever and git waits on the helper. Reads
(`fetch`/`ls-remote`) are anonymous against a repo the cached credential already covers, so they
never reach the helper — which is exactly why the failure looks selective and confusing.

`gh` is unaffected because it carries its own token (`gh auth status` → `Token: gho_…`) and never
consults the git credential helper.

## Fix

❌ Wrong — hangs with no output, and a `| tail` makes it worse by hiding even the exit code:

```bash
git push -u origin my-branch 2>&1 | tail -5      # $? is tail's, not git's
```

✅ Correct — hand git the `gh` token instead of the GUI helper, and forbid any prompt:

```bash
GIT_TERMINAL_PROMPT=0 git -c credential.helper='!gh auth git-credential' \
  push -u origin my-branch > /tmp/push.log 2>&1
echo "exit=$?"; cat /tmp/push.log
```

`GIT_TERMINAL_PROMPT=0` turns a would-be prompt into an immediate error instead of a hang, so a
credential problem reports itself in seconds rather than eating the whole timeout.

Redirect to a file and echo `$?` on its own line: piping into `tail`/`head` replaces git's exit
code with the pager's, and a push that failed then reads as `exit=0`.

## Related

- [avoid-gh-pr-merge-auto] — the other place where the convenient git/gh path is the wrong one here
- `AGENTS.md` → Git workflow — where the push actually happens in the cycle
