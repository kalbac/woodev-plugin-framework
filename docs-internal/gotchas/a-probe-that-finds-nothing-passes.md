# Gotcha: [testing/probes] — a probe that finds nothing «passes», because «none are wrong» is vacuously true of none
> Tags: measurement, playwright, testing | Session: s134

## What happens

A browser probe checks that a menu has no unlabelled entries:

```js
const items = await page.evaluate( () => {
    const menu = document.querySelector( '.some-class-i-remembered' );
    return menu ? Array.from( menu.querySelectorAll( '.item' ) ).map( … ) : [];
} );

expect( items.filter( ( i ) => '' === i.text ) ).toEqual( [] );   // ✅ green
```

The selector matches nothing, `items` is `[]`, the filter is `[]`, and the assertion **passes**.
The probe reports success over a page it never inspected.

In s134 this happened **four times in a row** on the same check — three different selector guesses
and one wrong container — while the defect it was written for was sitting on screen in a screenshot.

## Why it is worse than a red

A failing probe sends you to look. A vacuous green tells you the thing is fine, and it is
indistinguishable from a real pass in the output. Every later decision rests on it.

The same shape produced three other false reports in one session: a `thead th` selector that finds
zero headers for EVERY column (WooCommerce's `TableCard` renders no `<thead>` at all — the header
row lives inside `<tbody>` with `role="columnheader"`), a `waitFor` on `tbody tr` satisfied by that
same header row inside the loading skeleton, and a fixed pause that caught a spinner and read as
«stuck loading» while the route was answering 200.

## ✅ Correct

1. **Assert the collection is non-empty first.** One line, and it converts a vacuous green into an
   honest red:
   ```js
   expect( items ).not.toBeNull();
   expect( items.length ).toBeGreaterThan( 0 );
   ```
2. **Wait for the state, never for a duration.** `waitForFunction` on the thing you are about to
   measure; a fixed `waitForTimeout` measures the page mid-transition.
3. **Anchor on what a human sees** — rendered text and accessible names — not on class names
   recalled from some version of a vendor's component.
4. **Prefer a repo-durable test to a scratchpad probe** when the fact can be asserted in jest at
   all. The probe dies with the session; the test is still there next time.

## Related

- [npx-jest-bypasses-wp-scripts-jsdom](npx-jest-bypasses-wp-scripts-jsdom.md) — jsdom's limits are
  the reason a browser probe exists here at all.
- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md)
  — same family: a check that confirms its own setup rather than the world.
