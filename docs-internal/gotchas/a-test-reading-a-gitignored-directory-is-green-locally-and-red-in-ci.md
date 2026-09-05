# Gotcha: [testing/ci] — A test that reads `plugins-reference/` is green on every local checkout and red in CI, because the directory is gitignored

> Tags: testing, ci, jest, fixtures | Session: s117

## What happens

`npm run test:js` is green locally — 1628 tests, 25 suites. The same commit goes to CI and the
`test-js` job fails. Nothing about the code differs; the *inputs* do.

`plugins-reference/` is in `.gitignore` (line 30, `/plugins-reference/`). It holds donor plugins —
`woocommerce-edostavka`, `woocommerce-yandex-delivery`, `woodev-russian-post` and others — as a
local reading convenience. It is copied into agent worktrees by `.worktreeinclude`, so a worker's
checkout has it too. **A CI runner and a fresh clone do not.** Any test that opens a file under it
passes everywhere a human or an agent works, and fails in the one place that gates the merge.

The same applies to a script whose *default* arguments point in there. It works for everyone who
built it and throws for everyone who clones the repo.

## Root cause

The directory is genuinely useful and genuinely untracked, and both of those are deliberate: donor
plugins are third-party source that has no business in this repo's history. The trap is that its
presence is invisible — nothing about a green local run says "this depended on files git does not
have".

Worth noticing that the usual worktree caution runs the *other* way here. The standing rule is
that a worktree's green gate is weaker than the primary checkout's
([a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md)).
This is the opposite: the worktree and the primary checkout agree, and both are *stronger* than
CI, so the agreement proves nothing about the environment that decides the merge.

## Fix

Never let a test hard-fail on an untracked input. Skip it, and say why in the file:

```js
const HAS_DONOR_PLUGINS = existsSync(
	path.resolve( __dirname, '../../plugins-reference/woocommerce-edostavka' )
);
const itWithDonorPlugins = HAS_DONOR_PLUGINS ? it : it.skip;

itWithDonorPlugins( 'reports the edostavka acceptance figures', () => { /* ... */ } );
```

Keep the *rules* under test on self-built fixtures written to a temp directory, so they stay gated
in CI; let only the acceptance case — the one whose whole point is real files — depend on the donor
plugins.

For a CLI, distinguish a missing DEFAULT from a missing ARGUMENT. A default that was never going to
be present is an ordinary state: name what was skipped and exit 0. A path the caller typed is an
error, because they asked for a file that is not there.

**And verify it, do not reason about it** — the check costs one command:

```bash
mv plugins-reference plugins-reference-hidden
npm run test:js          # 1627 passed, 1 skipped
npm run probe:signature  # prints what it skipped, exits 0
mv plugins-reference-hidden plugins-reference
```

## Related

- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the same class of gap, running the other way
- [local-npm-run-build-is-not-assets-parity-evidence](local-npm-run-build-is-not-assets-parity-evidence.md) — another local-green/CI-red pair
- [jest-scans-agent-worktrees-inside-the-repo](jest-scans-agent-worktrees-inside-the-repo.md) — the other way a local jest run disagrees with CI
- Card #767 · PR #770
