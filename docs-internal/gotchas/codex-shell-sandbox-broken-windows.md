# gotcha: codex exec shell-sandbox broken on this Windows box — run critics with an inline bundle

**Namespace:** `[tooling/codex-critic]`
**Discovered:** s10 (2026-06-12)

## Symptom

Running the GPT-5.5 critic via `codex exec ... -s read-only` and asking it to inspect
a repo with shell commands (`git show`, `Get-Content`, etc.) fails on every command:

```
windows sandbox: runner error: CreateProcessAsUserW failed: 5
ERROR codex_core::exec: exec error: windows sandbox: runner error: CreateProcessAsUserW failed: 5
```

(`5` = ERROR_ACCESS_DENIED.) The model keeps trying shell commands, they all get
rejected, and it eventually emits OPEN QUESTIONS like "the full file was not included"
instead of a real review. The `-s read-only` sandbox's process-spawning path is broken
on this machine (codex-cli 0.136.0).

## Why bypassing the sandbox is NOT the fix

`--dangerously-bypass-approvals-and-sandbox` is denied by the Claude Code auto-mode
classifier (unsafe autonomous agent loop, never operator-authorized). Don't reach for it.

## The fix — feed the critic an INLINE bundle (no shell needed)

Assemble everything the critic needs into one prompt with PowerShell, then pipe it to a
read-only codex run that never relies on shell access:

1. Build a bundle file: the spec(s) + the FULL diffs (`git diff A..B`, `git show`) +
   any frozen reference source the critic must compare against (e.g. the framework's
   `woodev_normalize_site`, parity test, frozen-contract spec sections).
2. Prepend a prompt that says **"NO SHELL: your shell is policy-blocked; ALL materials
   are inline below; if something is genuinely missing list it under OPEN QUESTIONS."**
3. Run: `Get-Content bundle.txt -Raw | codex exec -m gpt-5.5 -c 'model_reasoning_effort="high"' -s read-only -C <repo> --skip-git-repo-check -o out.md -`
   (the `Bash`/`PowerShell` tool needs `dangerouslyDisableSandbox: true` so the OUTER
   Claude-side call may spawn codex; this is unrelated to codex's own broken inner sandbox.)
4. For a fix re-review, bundle the fix diff + the post-fix FULL files so the critic can
   confirm completeness without grepping.

This is how s10's 3-round deactivator review (BLOCK→fixes→SHIP) was run. It also fences
the critic from the worker's rationale for free (only facts you choose go in the bundle).

## Cost

Bundles get large (s10's was ~150 KB) but well within context. The tradeoff vs a
shell-capable critic: you must decide up front WHAT source the critic may need to compare
against — if you under-include, it correctly flags an OPEN QUESTION rather than guessing.

## s17 wrinkle — the `codex:codex-rescue` subagent hits the SAME wall, silently

Dispatching a review through the `codex:codex-rescue` subagent (Agent tool) inherits the
same broken inner sandbox. Observed s17 (2026-06-17): a background `codex-rescue` review
returned "I'll forward this to Codex as a background task, you'll be notified" — but the
underlying codex run died on `CreateProcessAsUserW failed: 5`, switched to a Node REPL,
and **stalled with no SHIP/HOLD verdict and no notification**. The subagent's optimistic
"you'll be notified" is NOT proof a verdict arrived.

Two rules from this:
1. **Always verify a background codex result actually landed** — read the latest
   `~/.codex/sessions/<YYYY>/<MM>/<DD>/rollout-*.jsonl` and extract the final assistant
   message before trusting "done". (Extract with a `PYTHONIOENCODING=utf-8` python one-liner;
   the transcripts contain non-cp1251 chars that crash a naive `print`.)
2. **Make the rescue inline-bundle too** — put the full diff + the contract list IN the
   prompt and explicitly instruct: *"DO NOT run any shell/git/file commands; the Windows
   sandbox is broken; review ONLY the pasted diff with reasoning and return a verdict."*
   s17's re-run did exactly this and Codex returned a clean SHIP from reasoning alone.

## Related

- [[autodev-critic-ratelimit-false-positive]] — the other codex-critic transport gotcha
- `tools/autodev/invoke-critic.ps1` — the autodev loop's codex-critic wrapper (uses the
  same `-s read-only` path; would hit this same wall on this box if it spawned shell)
