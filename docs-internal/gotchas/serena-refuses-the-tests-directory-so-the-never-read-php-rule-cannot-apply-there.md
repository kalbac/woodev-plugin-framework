# Serena refuses `tests/` — the "never `Read` a `.php` file" rule cannot apply there

**Namespace:** `[tooling/*]`
**Found:** s105 (30.08.2026), editing a test file on PR #661.
**Recurred:** s112 (02.09.2026) — and the recurrence is the interesting part, see below.

## The trap

`CLAUDE.md` and `AGENT-RULES.md` used to state the rule without an exception: **never use `Read` on
a `.php` file, always go through Serena.** Every subagent brief is required to repeat it.

But this project's Serena config **ignores `tests/`**. Any symbolic operation against a path under
it fails:

```text
Error executing tool insert_after_symbol:
ValueError: Explicitly requested symbols in
'tests/unit/Shipping/ShippingPluginDefaultLocalityStaleNoticeTest.php'
while the path is ignored
```

`find_symbol`, `insert_after_symbol`, `replace_symbol_body` and friends are all unavailable there —
not flaky, not a timeout, a flat refusal. So for test files the built-in `Read`/`Edit`/`Write` tools
are not a fallback, they are **the only tool**, and using them there is not a rule violation.

Do not confuse this with the *other* Serena failure mode seen the same session — a genuine
`CONNECT_TIMEOUT after 120000ms` when a fresh Orca worktree is still being indexed. That one IS the
environment defect the rule is about: retry, and report it rather than silently falling back.

| symptom | meaning | correct response |
|---|---|---|
| `… while the path is ignored` | the path is outside Serena's scope by config | use `Read`/`Edit` — expected, no exception needed |
| `CONNECT_TIMEOUT after 120000ms` | Serena is not up for that project yet | retry `activate_project`; if it persists, report it, and scope + record any fallback |

## Why it recurred, and what actually fixed it (s112)

s105 wrote this file and correctly named the root cause in its own first paragraph — the rule text
in `CLAUDE.md` and `AGENT-RULES.md` carried no exception. **Nobody changed that text.** Seven
sessions later a #270 worker was handed a brief repeating the bare rule, spent its opening moves
proving Serena would not serve `tests/`, and reported the config back as if it were news.

A gotcha describing a defect in a rule does not fix the rule. **The exception now lives in
`AGENT-RULES.md` → "Use Serena MCP" and in `CLAUDE.md`'s Serena section**, where a session actually
reads it, and both name the full ignore list rather than `tests/` alone:

```
tests/**  docs/**  .github/**  .ai/**  .serena/**  .claude/**
```

(read off `.serena/project.yml` → `ignored_paths`, s112). The lesson generalises: when a gotcha's
own text says "the rule says X and the rule is wrong", the fix belongs in the rule that session
start loads, not only in the gotcha that a session opens on demand.

## One benefit worth knowing

Because Serena never touches `tests/`, test files **do not get the CRLF flip** that Serena applies
to every production file it edits (gotcha `serena-replace-content-eol-flip`). A test file edited
with the built-in tools stays LF, so it needs no line-ending clean-up before committing.

## Related

- [serena-activate-path-must-be-the-worker-s-worktree](serena-activate-path-must-be-the-worker-s-worktree.md) — activating on the wrong path splits edits across two checkouts
- [serena-replace-content-eol-flip](serena-replace-content-eol-flip.md) — the CRLF flip that `tests/` escapes
