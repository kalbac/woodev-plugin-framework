# gotcha: a hook committed `100644` is silently ignored by POSIX git — and `core.fileMode=false` hides it on Windows
> **Platform:** The silent detection half is Windows-only — `core.fileMode=false` hides executable-bit drift; POSIX enforcement applies on all POSIX hosts.

**Namespace:** `[tooling/*]`
**Discovered:** s122 (2026-09-06), by a Codex critic on PR #806

## Symptom

New hooks were written, `chmod +x`'d on disk, tested on Windows, and shipped. They worked
here and did nothing at all on Linux/WSL. Git says so, but only on the platform that
refuses them:

```text
hint: The '.githooks/post-checkout' hook was ignored because it's not set as executable.
```

On Windows there is no message, because git-bash runs them regardless of the recorded mode.

## Root cause

Two facts that only bite together:

1. **Git stores the executable bit in the tree**, as `100755` versus `100644`, and POSIX git
   refuses to run a hook whose recorded mode is not executable.
2. **This box has `core.fileMode=false`** (normal for Windows), which tells git to IGNORE the
   filesystem's permission bits. So `chmod +x` changes the working copy and never reaches
   the index — `git status` stays clean and there is nothing to notice.

The result is a change that passes every local test and is inert for everyone else. The same
audit found `.githooks/commit-msg` had been `100644` since s81, so the closing-keyword gate
had never run on a POSIX clone in its whole life.

## ✅ Correct

Set the bit in the INDEX, not just on disk, and verify it in the tree:

```bash
git update-index --chmod=+x .githooks/post-merge .githooks/post-checkout
git ls-tree HEAD .githooks/          # every hook must read 100755
```

Then prove it where it actually matters — a real POSIX clone, not a local `ls -l`:

```bash
wsl.exe -- bash -c 'git clone -q --depth 1 --branch <branch> <url> /tmp/c && stat -c "%a %n" /tmp/c/.githooks/*'
```

## ❌ Wrong

- `chmod +x` on Windows and assuming git recorded it.
- `ls -l` in git-bash as the check — it reports the working copy, which is exactly the half
  that does not travel.
- Concluding "the hook works" from a Windows test. Windows is the platform that cannot
  detect this defect.

## Related

- [the-mo-is-reproducible-from-the-po](the-mo-is-reproducible-from-the-po.md) — same family: an artifact that looks right locally
  and is wrong in the tree everyone else receives.
- `.gitattributes` pins `.githooks/**` to LF for the sibling defect — a CRLF hook runs on
  git-bash and dies under WSL with `set: Illegal option -`.
