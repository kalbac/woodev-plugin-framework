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
