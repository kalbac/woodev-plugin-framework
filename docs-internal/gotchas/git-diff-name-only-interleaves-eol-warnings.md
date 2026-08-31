# Gotcha: [tooling/git] — `git diff --name-only` interleaves EOL warnings and invents files
> Tags: tooling, git, eol, agents | Session: s110

## What happens

`git diff --name-only` is parsed as a list of changed files, and the list contains files that were
never touched — `.github/SECURITY.md`, `.github/labeler.yml`, `src/**/*.js`. An agent then reports
"seven unrelated CRLF-to-LF rewrites are about to ship" and blocks a clean change.

In s110 this fooled **two independent agents within the same hour**: the coordinator first, and then
the Codex critic reviewing the coordinator's work, which reported it as a should-fix finding. Both
saw the same phantom files. Nothing was wrong with the tree.

## Root cause

This repo sets `* text=auto` plus `eol=lf` in `.gitattributes`. Whenever git refreshes its stat
cache for a file whose working-copy EOL differs from what normalization would produce, it prints to
**stderr**:

```
warning: in the working copy of '.github/SECURITY.md', CRLF will be replaced by LF the next time Git touches it
```

`2>&1`, a pipe in a shell that merges streams, or any capture that does not separate the streams
splices those warning lines into the file list. The quoted paths inside them then read as filenames.

Two properties make it especially deceptive:

* The warnings are **one-shot per file** — once git updates its cache they stop, so re-running the
  same command minutes later produces a clean list and the phantom files "disappear". That looks
  like the problem was fixed rather than never having existed.
* The paths in the warnings are real repo paths, so nothing looks obviously wrong.

## Fix

❌ Wrong — merges stderr into the parsed output:

```bash
git diff --name-only 2>&1 | grep -v '\.php$'
git diff --name-only | wc -l          # in a shell that merges streams
```

✅ Correct — discard stderr, or use a command that reports state rather than a diff:

```bash
git diff --name-only 2>/dev/null
git status --short 2>/dev/null        # authoritative: shows modified AND untracked
git diff --check 2>/dev/null          # whitespace errors, empty when clean
```

**Falsify a phantom before acting on it.** `git status --short` lists every modified path; if a file
is not there, it is not modified, whatever a `--name-only` list appeared to say.

## Related

- [serena-replace-content-eol-flip](serena-replace-content-eol-flip.md) — the other EOL trap here; that one is a real corruption, this one is a reporting artefact
- [an-orca-worktree-starts-dirty-with-crlf-churn](an-orca-worktree-starts-dirty-with-crlf-churn.md) — where genuinely CRLF-dirty files DO appear, so the phantom is plausible
- [a-phpcs-rule-silenced-by-exclude-pattern-cannot-be-revived-from-the-cli](a-phpcs-rule-silenced-by-exclude-pattern-cannot-be-revived-from-the-cli.md) — the other s110 finding from the same critic pass, that one real
