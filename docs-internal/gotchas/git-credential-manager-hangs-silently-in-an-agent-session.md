# Gotcha: [tooling/git-credentials] — Git Credential Manager hangs an agent's `git push` silently
> Tags: tooling, git, agents | Session: s100

## What happens

`git push` (or any authenticated git operation) from an agent terminal produces **no output, no
error and no prompt** — it just sits there until something times it out. Nothing in the git
output says what it is waiting for, so it reads as a network stall or a hung agent.

## Root cause

Git for Windows ships `credential.helper = manager` in its **system** config
(`C:/Program Files/Git/etc/gitconfig`). Git Credential Manager wants to raise an interactive
prompt — a GUI dialog or a console read. An agent terminal is non-interactive, so nobody
answers it and the process blocks forever. The credential is never the problem; the *prompt* is.

`gh` being logged in does not help on its own: `gh auth status` can be perfectly healthy while
git still routes through GCM and hangs.

## Fix

Operator decision, #560 (27.08.2026): point github.com at `gh`, which answers from its own
token and never prompts. Use the official command rather than hand-editing — it writes the
**empty first entry** that neutralises the inherited system helper, which a hand-rolled
`git config credential.helper` does not:

❌ Wrong — leaves `manager` first in the chain, so it still runs and still hangs:

```bash
git config --global credential.helper "!gh auth git-credential"
```

✅ Correct:

```bash
gh auth setup-git
```

Resulting global config — note the deliberate empty value that clears the inherited helper,
and that the override is **scoped to github.com**, so every other host keeps using GCM:

```
credential.https://github.com.helper=
credential.https://github.com.helper=!'C:\Program Files\GitHub CLI\gh.exe' auth git-credential
credential.https://gist.github.com.helper=
credential.https://gist.github.com.helper=!'C:\Program Files\GitHub CLI\gh.exe' auth git-credential
```

Verify it non-interactively — this must return immediately, without a prompt:

```bash
printf 'protocol=https\nhost=github.com\n\n' | git credential fill
# protocol=https / host=github.com / username=kalbac / password=gho_…
```

And confirm nothing else moved — the unscoped helper is still GCM:

```bash
git config --get-all credential.helper   # manager
```

Measured on 27.08.2026: before the change the system helper was `manager` with no global or
local override; after it, github.com resolves through `gh` instantly and other hosts are
untouched.

## Related

- [codex-shell-sandbox-broken-windows](codex-shell-sandbox-broken-windows.md) — the other "an agent's shell is not your shell" trap on this machine
- [two-agents-one-file-is-the-orchestrator-s-bug](two-agents-one-file-is-the-orchestrator-s-bug.md) — the other class of failure that shows up as a worker silently achieving nothing
