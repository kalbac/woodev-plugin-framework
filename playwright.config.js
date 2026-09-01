/**
 * Playwright configuration for the checkout e2e pass.
 *
 * WHY THIS EXISTS (issue #723): the classic-checkout walkthrough in
 * `docs-internal/wiki/rig-pickup-walkthrough.md` has been run BY HAND in more than twenty
 * sessions, and it is what keeps finding the defects jest cannot see — #721 is the plainest
 * case, a permanently dead «Place order» button in a suite of 1599 green JS tests, because
 * jsdom has no real page and nothing asserted the button at all.
 *
 * WHAT IT COSTS: nothing new. `@playwright/test` 1.60 and its chromium binary already ship
 * as transitive dependencies of `@wordpress/scripts`, which this repo has always had. This
 * file and the specs beside it are the whole addition — deliberately, because the operator's
 * own framing was that the verification pipeline keeps growing.
 *
 * WHERE IT RUNS: against the LIVE DEV RIG (`:8973`), not a CI wp-env. Card #450 already
 * recorded that an e2e pass is "медленно и не в CI", and putting it there is a separate
 * decision with its own cost. Override the target with `WP_BASE_URL` when that changes.
 *
 * The rig serves the WORKING TREE, so this suite tests whatever branch is checked out —
 * which is the point, and also why a failure here can mean "wrong branch parked on the rig"
 * rather than "broken code". See the gotcha `rig-serves-the-working-tree-branch-switch-reverts-fixes`.
 */

const { defineConfig, devices } = require( '@playwright/test' );

const baseURL = process.env.WP_BASE_URL || 'http://localhost:8973';

module.exports = defineConfig( {
	testDir: './tests/e2e',
	// Serial on purpose: every spec drives ONE shared WooCommerce session on a single rig,
	// and a parallel worker would race another's cart and customer location.
	workers: 1,
	fullyParallel: false,
	// No retries locally — a flaky checkout assertion is a finding, not noise to paper over.
	retries: process.env.CI ? 1 : 0,
	// The rig's /suggest answers in 6-10 s for an unknown settlement (measured 25.08.2026),
	// so the default 30 s expect timeout is too tight for anything that waits on one.
	timeout: 120_000,
	expect: { timeout: 15_000 },
	reporter: process.env.CI ? [ [ 'github' ] ] : [ [ 'list' ] ],
	outputDir: './artifacts/e2e',
	use: {
		baseURL,
		headless: true,
		viewport: { width: 1280, height: 900 },
		ignoreHTTPSErrors: true,
		actionTimeout: 15_000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
