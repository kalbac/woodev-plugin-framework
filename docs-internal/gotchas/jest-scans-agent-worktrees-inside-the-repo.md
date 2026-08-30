# A local jest run counts every agent worktree nested inside the repo

**Namespace:** `[testing/js]`
**Found:** s55 (2026-08-07), while taking baseline suite numbers on a freshly merged `main`.
**Fixed:** s107 (#188, 2026-08-30) — `jest-unit.config.js` now scopes `roots`; see "Fixed" below.
The trap description that follows is kept as the record of what it cost.

## Symptom

`npm run test:js` on `main` reported:

```
Test Suites: 56 passed, 56 total
Tests:       4889 passed, 4889 total
```

against the repo's true suite (the current true baseline lives in the `CURRENT-STATE.md`
header — 800 jest tests as of s59; compare against THAT, not a number frozen in this file).
Everything was green, so nothing looked wrong — the run simply reported a number ~7× too large.

## Root cause

Subagents dispatched with `isolation: "worktree"` get a git worktree created **inside the
repository** — at `.claude/worktrees/agent-<id>/` when this was found (s55); Orca agent
worktrees have since moved to `.orca/worktrees/<repo>/<name>/` (s83), and the trap moves
with the location, wherever it lands next. Each worktree is a full checkout, including
`tests/js/`.

The worktree root **is** listed in `.gitignore`, and that is exactly what makes this trap
quiet: it keeps the copies out of `git status`, so the tree looks clean and nothing hints
that several extra checkouts are sitting inside the project.

**Jest does not read `.gitignore`.** It walks the filesystem from its root and collects
every `*.test.js` it finds. So each live worktree contributes its own complete copy of
the suite.

The arithmetic is exact, which is how the cause was confirmed rather than guessed — with
one worktree left alive:

```
1391 = 690 (main, after #180 removed 11 tests) + 701 (the worktree, based on the pre-#180 commit)
```

Note the second term: the worktree was based on an **older commit**, so the inflated total
was partly made of tests that no longer exist on `main`.

## Why it matters beyond a wrong number

- **A count is the tell for a bad invocation in this repo.** The sibling gotcha
  [`npx-jest-bypasses-wp-scripts-jsdom`](npx-jest-bypasses-wp-scripts-jsdom.md) teaches
  that a changed TOTAL means a broken run, not a regression. That heuristic fires here
  too — but in the opposite direction, and while everything is green.
- **Stale code reports as passing.** A worktree pinned to an older base runs deleted
  tests against deleted implementations. Those greens say nothing about `main`.
- **A real failure gets misattributed.** A red from `.claude/worktrees/agent-XXXX/tests/js/…`
  names a path that looks almost exactly like the real one; it is easy to start
  debugging `main` over a failure that belongs to another branch entirely.

## Fixed (s107, #188)

A project-level `jest-unit.config.js` at the repo root, discovered automatically by
`wp-scripts test-unit-js` (see its README's "Advanced information" for the discovery
rule — a `jest-unit.config.js` next to `package.json` overrides the package's default
`@wordpress/scripts/config/jest-unit.config.js`), spreads that same default config and
adds `roots: [ '<rootDir>/tests/js' ]`. A bare `npm run test:js` now resolves `<rootDir>`
to wherever it is invoked from and only walks that repo's own `tests/js/`, so nested
agent worktrees (`.orca/worktrees/…/tests/js/…`, or wherever a future tool moves them)
are never reached in the first place — jest is now told where to look, instead of being
trusted to look everywhere and filtered after the fact.

Measured s107, with live sibling worktrees under `.orca/worktrees/woodev_framework/`:
before the fix, a bare `npm run test:js` from the repo root reported `63 suites / 4704
tests`; after, the same bare command reports `21 suites / 1568 tests` — this repo's real
set, matching what `npm run test:js -- --roots "<rootDir>/tests/js"` has always reported
(the `--roots` form still works unchanged; both now agree). **CI is a no-op**: a CI
checkout has no worktrees, so `<rootDir>/tests/js` was already the whole suite there —
the config only changes what a bare run scans, never runs an extra test.

Historical correct practice, kept for context: quote a local jest total only after
checking `git worktree list` / `ls .orca/worktrees/` for live worktrees, and remove a
finished agent's worktree with `git worktree remove --force <path>` — on Windows this can
fail with `Permission denied` while a process still holds the files, deregistering it from
`git worktree list` while the **directory survives on disk**; verify with `ls`, not
`git worktree list`. None of this is required anymore for jest specifically, but the
worktree-cleanup half still matters for disk space and for any OTHER tool that, like jest
used to, walks the filesystem instead of reading `.gitignore`.

## Related

- [[npx-jest-bypasses-wp-scripts-jsdom]] — the other way a jest TOTAL lies in this repo
- [[jest-toequal-empty-array-ignores-undefined]] — a third case of a green run proving less than it appears
- [[mutation-sweep-branch-only-false-confidence]] — on what a passing suite does not prove
