# Serena refuses `tests/` — the "never `Read` a `.php` file" rule cannot apply there

**Namespace:** `[tooling/*]`
**Found:** s105 (30.08.2026), editing a test file on PR #661.

## The trap

`CLAUDE.md` and `AGENT-RULES.md` state the rule without an exception: **never use `Read` on a
`.php` file, always go through Serena.** Every subagent brief is required to repeat it.

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

## One benefit worth knowing

Because Serena never touches `tests/`, test files **do not get the CRLF flip** that Serena applies
to every production file it edits (gotcha `serena-replace-content-eol-flip`). A test file edited
with the built-in tools stays LF, so it needs no line-ending clean-up before committing.

## Related

- [serena-activate-path-must-be-the-worker-s-worktree](serena-activate-path-must-be-the-worker-s-worktree.md) — activating on the wrong path splits edits across two checkouts
- [serena-replace-content-eol-flip](serena-replace-content-eol-flip.md) — the CRLF flip that `tests/` escapes
