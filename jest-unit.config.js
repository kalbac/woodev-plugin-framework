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
	/**
	 * Per-test limit, raised from jest's 5 s default together with Testing Library's
	 * `asyncUtilTimeout` (5 s, set in `tests/js/jest.setup.js`, #1892). The two have to move as a
	 * pair: a `findBy*` allowed 5 s inside a test allowed 5 s would always lose to jest's own timer
	 * and report "Exceeded timeout" instead of the element that never appeared. A passing test
	 * is unaffected; this only changes how long a hung one takes to fail.
	 */
	testTimeout: 15000,
	/**
	 * jsdom gap-fillers — see `tests/js/jest.setup.js` for what and why. Appended to
	 * whatever the preset already registers rather than replacing it: the wp-scripts
	 * preset supplies `@wordpress/jest-console` and the DOM matchers through this same
	 * key, and dropping those would quietly disable half the suite's assertions.
	 */
	setupFilesAfterEnv: [
		...( defaultConfig.setupFilesAfterEnv || [] ),
		'<rootDir>/tests/js/jest.setup.js',
	],
};
