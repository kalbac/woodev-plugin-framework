# A local jest run counts every agent worktree nested inside the repo

**Namespace:** `[testing/js]`
**Found:** s55 (2026-08-07), while taking baseline suite numbers on a freshly merged `main`.

## Symptom

`npm run test:js` on `main` reported:

```
Test Suites: 56 passed, 56 total
Tests:       4889 passed, 4889 total
```

against a true suite of **8 files / 690 tests**. Everything was green, so nothing looked
wrong — the run simply reported a number ~7× too large.

## Root cause

Subagents dispatched with `isolation: "worktree"` get a git worktree created **inside the
repository**, at `.claude/worktrees/agent-<id>/`. Each worktree is a full checkout,
including `tests/js/`.

`.claude/worktrees/` **is** listed in `.gitignore` (line 29), and that is exactly what
makes this trap quiet: it keeps the copies out of `git status`, so the tree looks clean
and nothing hints that seven extra checkouts are sitting inside the project.

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

## Correct practice

- **Never quote a local jest total while agent worktrees exist.** Run `git worktree list`
  first, or check `ls .claude/worktrees/`.
- **You do not have to wait for the worktrees to clear.** Scoping the run to this repo's own
  suite gives a trustworthy total immediately:

  ```bash
  npm run test:js -- --roots "<rootDir>/tests/js"
  ```

  Observed s55 with three sibling worktrees alive: the bare command reported
  `24 suites / 2109 tests, 2 failed`; the scoped command reported `8 suites / 701 tests, all
  passing`. **Both failures belonged to another agent's worktree, mid-edit.** Without scoping,
  the honest reading of that run is "something is broken and I do not know whose" — and the
  tempting reading is "I broke it", which sends you debugging code you never touched.
- Remove worktrees when their agent finishes: `git worktree remove --force .claude/worktrees/<dir>`.
  On Windows this can fail with `Permission denied` while a process still holds the files;
  the worktree gets deregistered from `git worktree list` but the **directory survives on
  disk and jest keeps scanning it**. Verify with `ls`, not with `git worktree list`.
- **CI is unaffected** — a CI checkout has no worktrees — so this only ever misleads
  locally, which is precisely where the baseline numbers quoted in handoffs come from.

## Not yet done

Hardening this by adding `testPathIgnorePatterns` for `.claude/` would need a
`jest.config.js` extending `@wordpress/scripts`' own preset. This repo deliberately has
**no jest config of its own** — `wp-scripts test-unit-js` owns it and supplies jsdom — and
that arrangement has already burned the project once. Changing it is an operator
decision, tracked separately, not something to do casually.

## Related

- [[npx-jest-bypasses-wp-scripts-jsdom]] — the other way a jest TOTAL lies in this repo
- [[jest-toequal-empty-array-ignores-undefined]] — a third case of a green run proving less than it appears
- [[mutation-sweep-branch-only-false-confidence]] — on what a passing suite does not prove
