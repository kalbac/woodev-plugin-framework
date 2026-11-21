/**
 * Shared jsdom gap-fillers for the JS suite.
 *
 * ⚠ These are NOT product stubs. Each one fills a browser API that jsdom does not
 * implement at all: jsdom logs `Error: Not implemented: …` through `console.error`, and
 * `@wordpress/jest-console` (part of the wp-scripts preset) fails any test that produces
 * an unexpected `console.error`. So a real, working component can fail the suite purely
 * because jsdom is not a browser.
 *
 * Added in s134 when `@wordpress/components`' `Modal` — the destructive-action confirm on
 * the shipping-orders page — took 7 tests red with six `window.scrollTo` errors apiece.
 * The component is fine; jsdom simply has no scrolling.
 *
 * Keep this file for genuine jsdom holes only. Anything that stubs OUR behaviour belongs
 * in the test that makes the claim, where a reader can see it.
 */

// ⚠ Assigned UNCONDITIONALLY, not behind a `typeof` guard. jsdom DOES define these — as
// functions whose entire body reports "Not implemented" through `console.error`. A guard
// that only fills in a missing function therefore never fires, and the errors keep coming;
// that exact mistake cost a round here.
//
// `Modal` calls `scrollTo` when it locks and restores the page scroll position.
window.scrollTo = () => {};

// Same family: focus management inside dialogs and menus reaches for `scrollIntoView`.
window.Element.prototype.scrollIntoView = () => {};

// `matchMedia` is the third jsdom hole, and unlike the two above it costs TIME, not a red test.
// `@wordpress/components`' `Modal` closes through an exit animation: it waits for `animationend`
// — which jsdom never fires, there is no layout engine — and only then falls back to a real
// `setTimeout` of 1.2 × its 200 ms transition. Every Modal close in every suite therefore waited
// ~240 ms of wall-clock for nothing (#1042: the close-and-reopen preview tests cost 350–410 ms
// against 60–90 ms for their neighbours; on a starved CI runner that stretch is what ran into
// the 5 s per-test limit). Modal skips the animation entirely when the user prefers reduced
// motion, and that preference is read through `matchMedia` — so the stub reports exactly that one
// query as matching, and every other query as not matching, which is what jsdom's missing
// `matchMedia` has always meant to `useMediaQuery` (`getValue()` falls back to `false`).
// A real user with the OS setting «reduce motion» gets this same code path in production.
window.matchMedia = ( query ) => ( {
	matches: query.includes( 'prefers-reduced-motion: reduce' ),
	media: query,
	onchange: null,
	addEventListener: () => {},
	removeEventListener: () => {},
	addListener: () => {},
	removeListener: () => {},
	dispatchEvent: () => false,
} );

// ---------------------------------------------------------------------------------------------
// Not a jsdom hole — the one shared knob for async assertions (#1892).
//
// Testing Library's `findBy*` / `waitFor` give up after 1000 ms by default. That is wall-clock,
// not CPU time, and it is the same budget for a 5-row table and for the shipping-orders app that
// mounts ~3 000 lines of React. On an idle machine every such wait finishes in tens of ms; with
// two or three agents running their own suites next to this one (or a starved CI runner) a render
// stretches 10–50× and the wait dies as "Unable to find an element …" — a different test each
// run (`admin-records-matching`, `admin-records-chrome`, the shipping-orders bulk test), green
// when run alone. Measured: 4 of 5 full runs failed under 30 busy loops on a 10-core Mac with the
// 1000 ms default.
//
// So the budget is raised once, here. A wait that succeeds still returns on the first poll that
// passes, so a green suite costs nothing extra; only a real failure takes longer to report. It is
// kept below the per-test limit (`testTimeout`, jest-unit.config.js) so a genuine miss is reported
// as the missing element rather than as a bare jest timeout.
// Do NOT raise individual waits, and do not retry tests: a wait that needs more than this has a
// real defect (a promise that never resolves, a missing act()).
//
// `@testing-library/dom` is one hoisted copy, shared by `@testing-library/react`, so this applies
// to every import path the suites use.
const { configure } = require( '@testing-library/dom' );

configure( { asyncUtilTimeout: 5000 } );
