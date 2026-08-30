/**
 * Overrides `@wordpress/scripts`' default jest-unit config to scope test
 * discovery to `tests/js`.
 *
 * Without this, jest walks the whole filesystem from `rootDir` (see
 * `@wordpress/jest-preset-default`'s `testMatch`, which has no `roots`
 * restriction) and picks up every `*.test.js` under `.orca/worktrees/`,
 * `.claude/worktrees/` and any future agent-worktree location too — those
 * are gitignored, not jest-ignored. A worktree is a full copy of the tree,
 * `tests/js/` included, so a bare `npm run test:js` from the repo root
 * silently counts every agent worktree nested inside it on top of the real
 * suite. See docs-internal/gotchas/jest-scans-agent-worktrees-inside-the-repo.md.
 */

const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...defaultConfig,
	roots: [ '<rootDir>/tests/js' ],
};
