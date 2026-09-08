# A hand-written `.d.ts` for a runtime global is an assertion, not a check

> Namespace: `js/*` — added session 127 (2026-09-08). Two page-killing crashes in one evening, both
> of this shape, both past a green `typecheck`.

## The trap

Route B (see the SP-10 spec §D7) consumes WooCommerce through `window.wc.*` and describes that
surface in our own `src/shipping-orders-page/wc-globals.d.ts`. That file is a **claim about someone
else's bundle**. TypeScript checks our code against the claim; nothing checks the claim against the
bundle. Get it wrong and `npm run typecheck` stays green while the page dies on load — and because
it dies inside WooCommerce's React app, it takes **the whole wc-admin screen** with it, not just the
one control.

## Both real cases, same evening

**1. A module typed as a function.**

```ts
// the declaration said this…
currency?: ( storeSettings?: Record<string, unknown> ) => Record<string, unknown>;
```

```js
// …so the page called it, and the browser said:
const currency = window.wc.currency();   // TypeError: B is not a function
```

Measured in the browser: `typeof wc.currency === 'object'`, **not callable**, and the factory hangs
off it as `wc.currency.CurrencyFactory`. Correct: `window.wc?.currency?.CurrencyFactory?.()`.

**2. A required input treated as optional.** `DEFAULT_DATE_RANGE` was `'period=year'`, with no
`compare`, because this page shows no period comparison. But `getCurrentDates()` resolves `compare`
against a fixed list and throws `Cannot find compare:` when it is absent — it is required INPUT, not
an offered feature. WooCommerce's own default is `period=month&compare=previous_year`.

## Why the jest suite passed both

**Both fakes were more forgiving than the real module.** One made `wc.currency` a function; the other
defaulted `compare` itself and parsed the default range with `split('=')`. A fake written from the
same wrong understanding as the code cannot contradict it.

Fix the fakes to mirror the MEASURED shape, including the parts that throw:

```js
currency: { CurrencyFactory: () => ( { … } ) },      // an object, not a function

getCurrentDates: ( query, defaultRange ) => {
    const compare = query.compare || parse( defaultRange ).compare;
    if ( ! [ 'previous_period', 'previous_year' ].includes( compare ) ) {
        throw new Error( `Cannot find compare: ${ compare || '' }` );   // as the real one does
    }
    …
}
```

Then prove the guard: revert the fix and watch the suite go red. Both were proven that way.

## The rule

**Before adding or changing a line in `wc-globals.d.ts`, measure the shape in a browser.** A one-line
`page.evaluate()` against the rig settles it:

```js
const wc = window.wc || {};
return { type: typeof wc.currency, callable: typeof wc.currency === 'function',
         keys: Object.keys( wc.currency || {} ) };
```

And measure the PAGE, not the bundle: reading the built file proves the code shipped, never that it
mounts.

## Related

- [declaring-wc-settings-as-a-script-dependency-silently-drops-the-bundle](declaring-wc-settings-as-a-script-dependency-silently-drops-the-bundle.md) — the third way this page died, also invisible to every gate
- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — the general form
