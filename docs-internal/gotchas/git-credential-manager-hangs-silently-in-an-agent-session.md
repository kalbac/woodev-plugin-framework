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

## It came back — verify the config, do not assume the fix is in place (s114, 03.09.2026)

Six days after the fix was made and written down as a standing rule, the machine was found
WITHOUT it: `credential.helper = manager` in the SYSTEM config and **no `github.com` scope at
all**. A `git push` from the primary checkout hung silently for two minutes before being killed.

So this is not a one-time repair — it is a config that can be absent again, and the symptom
(silence) is the same one that hides it. **Check before the first push of a session, not after a
hang:**

```bash
git config --global --get-regexp credential   # must list a github.com entry, EMPTY then gh
```

An empty first line followed by the `gh` line is the shape that works; the empty entry is what
clears `manager` out of the chain, and it is exactly what a hand-rolled
`git config credential.helper '!gh auth git-credential'` does NOT write.

⚠ **A worker's push succeeding is not evidence your own will.** In s114 four Orca workers pushed
their branches fine while the coordinator's push from the primary checkout hung — same repo, same
remote. Do not infer the config from someone else's success.

## Related

- [codex-shell-sandbox-broken-windows](codex-shell-sandbox-broken-windows.md) — the other "an agent's shell is not your shell" trap on this machine
- [two-agents-one-file-is-the-orchestrator-s-bug](two-agents-one-file-is-the-orchestrator-s-bug.md) — the other class of failure that shows up as a worker silently achieving nothing
