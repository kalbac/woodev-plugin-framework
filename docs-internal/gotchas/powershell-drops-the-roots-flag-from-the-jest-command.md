# PowerShell drops `--roots` from the documented jest command

**Namespace:** `[testing/js]`
**Found:** s73 (14.08.2026).

**Update (s107, #188):** a bare `npm run test:js` no longer needs `--roots` at all —
`jest-unit.config.js` now scopes it by default (see
[[jest-scans-agent-worktrees-inside-the-repo]]). The trap below still applies whenever a
flag IS passed to an npm script through PowerShell, `--roots` or otherwise.

## The trap

`AGENTS.md` and `CLAUDE.md` used to document the JS test command as

```bash
npm run test:js -- --roots "<rootDir>/tests/js"
```

Run through **PowerShell**, the `--roots` flag itself is lost and only its value survives, as a
positional argument:

```
> wp-scripts test-unit-js <rootDir>/tests/js          # note: no --roots
...
No tests found, exiting with code 1
Pattern: <rootDir>\\tests\\js - 0 matches
```

jest then treats `<rootDir>/tests/js` as a **testPathPattern**, matches nothing, and exits 1. The
identical line in Git Bash works exactly as documented.

The dangerous shape is not the visible failure — it is a *partial* one. With an extra argument
present the run looks fine:

```
> npm run test:js -- --roots "<rootDir>/tests/js" --testPathPattern "checkout-field-classic"
Ran all test suites matching /<rootDir>\\tests\\js|checkout-field-classic/i.
Tests: 16 passed
```

Sixteen tests passed, so the run reads as successful — but the roots restriction was never
applied; the file was selected only by the *other* pattern, ORed in. A run intended to be scoped
was not scoped, and a full-suite run would have said "No tests found" while nothing was wrong with
the tests.

## ✅ Correct

Run the documented command from **bash**, not PowerShell. Both shells are available in this
environment; the file/test tooling does not care, but npm argument forwarding does.

When a targeted run must happen, still read the tail: `Ran all test suites matching /…/` shows what
jest actually selected, and `Tests: N` against a known baseline (1103 at the end of s73) shows
whether the scope was what you meant.

Never reach for `npx jest` as a workaround — that loses the wp-scripts jsdom environment and scans
agent worktrees inside the repo (see the two gotchas below).

## Related

- [[npx-jest-bypasses-wp-scripts-jsdom]] — why the wrapper exists in the first place
- [[jest-scans-agent-worktrees-inside-the-repo]] — the other reason the roots restriction matters
- [[wpenv-windows-gitbash-path-mangling]] — the mirror-image trap: wp-env needs PowerShell, because Git Bash mangles container paths
- [[../GOTCHAS.md]] — `[testing/js]`
