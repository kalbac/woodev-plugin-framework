# Gotcha: [tooling/parallel-agents] — Codex cannot read an Orca worktree until its `.git` is relative, and the git config that writes one breaks the whole repo
> Tags: tooling, orca, codex, git, wsl | Session: s107

## What happens

Codex under Orca on this machine runs in **WSL**. Start it in an Orca worktree, and its very first
git command dies:

```
fatal: not a git repository:
/mnt/d/Projects/woodev_framework/.orca/worktrees/woodev_framework/<name>/D:/Projects/woodev_framework/.git/worktrees/<name>
```

Every later command fails the same way. The main checkout reads fine, so the failure looks like
something about that one worktree rather than about the whole arrangement. Before s107 this made
Codex unusable as a **worker** — it could only critique the main checkout — and card #510 recorded
it as an open blocker (s91 worked around it by swapping the critic to Claude Opus, which is a role
substitution, not a fix).

## Root cause

A worktree created by **Windows git** carries `.git` as a FILE holding an absolute Windows path:

```
gitdir: D:/Projects/woodev_framework/.git/worktrees/<name>
```

WSL git does not recognise `D:/…` as absolute. It treats the line as a **relative** path and glues
it onto the cwd — which is exactly the garbage path in the error. The main checkout is unaffected
because its `.git` is a real directory, with no path inside it at all.

## Fix — rewrite that one line, per worktree

```sh
cd /mnt/d/Projects/woodev_framework/.orca/worktrees/woodev_framework/<name>
printf 'gitdir: ../../../../.git/worktrees/<name>\n' > .git
git rev-parse --show-toplevel
```

Four levels up is correct for Orca's layout (`<repo>/.orca/worktrees/<repo-name>/<worktree>`).
Measured s107: after this, **both** gits work in the same worktree — WSL git reads it, and Windows
git still reports the same toplevel it did before. It is safe to hand to the worker as its own
step 0, where it doubles as the anti-fabrication canary: a Codex that could not run it cannot read
the repository, and anything it says about the code afterwards is invention.

## ⚠ The trap: do NOT reach for `worktree.useRelativePaths`

Git 2.49 has exactly the option this problem seems to want, and it is the first thing anyone will
try:

❌ Wrong — looks canonical, breaks the entire repository:

```bash
git config worktree.useRelativePaths true
```

It does write a relative `gitdir:` — and it also writes a **repository extension** into the shared
config:

```ini
[extensions]
	relativeworktrees = true
```

Git 2.43, which is what WSL ships here, does not know that extension and therefore refuses the
repository outright — worktrees **and the main checkout**:

```
fatal: unknown repository extension found:
	relativeworktrees
```

So the config turns a per-worktree failure into a total one, and it does it to the shared
`.git/config`, i.e. for every checkout at once. Measured s107, then reverted with
`git config --local --unset worktree.useRelativePaths` **and**
`git config --local --unset extensions.relativeWorktrees` — the unset of the first does not remove
the second.

The manual rewrite has none of this exposure: it touches one worktree's `.git` file and writes no
extension.

## Related

- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — the launch itself
- [codex-shell-sandbox-broken-windows](codex-shell-sandbox-broken-windows.md) — why a canary is mandatory for Codex at all
- [codex-model-terra-is-a-400-that-looks-like-a-warning](codex-model-terra-is-a-400-that-looks-like-a-warning.md) — omit `--model`
- [sharing-vendor-breaks-composer-autoload-in-a-worktree](sharing-vendor-breaks-composer-autoload-in-a-worktree.md) — the other worktree-path trap
- [wiki/orchestrating-agents-with-orca.md](../wiki/orchestrating-agents-with-orca.md) — the launch recipe this belongs to
