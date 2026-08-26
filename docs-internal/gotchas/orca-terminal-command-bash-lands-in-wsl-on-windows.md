# Gotcha: [tooling/orca] — `terminal create --command "bash"` lands in WSL, and everything then blames Orca
> Namespace: `tooling/orca` — added s97 (2026-08-27)

## What happens

`orca terminal create --command "bash"` opens a shell whose prompt is
`maksim@maksim:/mnt/d/Projects/...` — a **WSL** shell. Inside an Orca worktree, git then dies:

```
fatal: not a git repository:
  /mnt/d/.../s97-critic-555-556/D:/Projects/woodev_framework/.git/worktrees/s97-critic-555-556
```

and a freshly-installed agent CLI has none of the credentials or config it has on Windows, so it
refuses every model with "You need to sign in" and silently auto-rejects its own tool calls.

The whole cascade reads as "Orca runs agents in WSL", which is **false** and sends you off
configuring the wrong machine. s97 lost a stretch of session to exactly that, then wrote a
credentials config into the WSL home that had to be deleted again.

## Root cause

Two independent facts:

1. **Orca's default terminal here is PowerShell on Windows.** Verified by creating a terminal with
   NO `--command`: the prompt is `PS D:\Projects\woodev_framework\.orca\worktrees\...`. The
   Agent runtime setting (Orca → Settings → Agents → *Agent runtime*) is `Windows` and is honoured.
2. **On Windows, `bash` is `C:\Windows\System32\bash.exe` — the WSL launcher.** So
   `--command "bash"` does not open Git Bash; it crosses into the Linux distro. The caller chose
   WSL, not Orca.

Once in WSL, the worktree's `.git` FILE reads `gitdir: D:/Projects/woodev_framework/.git/...` — a
Windows path WSL git cannot resolve. Hence the `fatal`, which is a path-format problem, not a
broken worktree.

## Fix

✅ Launch an agent as an agent, and let Orca place it:

```bash
orca orchestration worker-start --task <task_id> --worktree <selector> --agent kilo --json
```

✅ For a plain shell, take Orca's default (omit `--command`) — that is PowerShell on Windows.

❌ Never `--command "bash"` on a Windows host when you meant a local shell.

If you are already stuck in a WSL terminal and only need history, this works without moving
anything — the MAIN checkout's `.git` is a real directory and resolves fine:

```bash
git -C /mnt/d/Projects/woodev_framework log --oneline origin/main
gh pr diff <n> --repo <owner>/<repo>     # gh needs no local repo when given --repo
```

## Related

- [[codex-shell-sandbox-broken-windows]] — the neighbouring trap: Codex's own WSL shell hitting
  the same Windows-path wall from the other direction.
- [[serena-activate-path-must-be-the-worker-s-worktree]] — the other way a worker silently ends up
  operating on a different checkout than you think.
- [[starting-codex-under-orca-needs-four-steps-not-one]] — the launch-time dialogs that stall an
  agent right after this step.
