# Gotcha: [testing/js] — `npm run test:js` is one seventh of the `JS Tests` job, and the gate it omits is the one that fails a brand-new page

> Tags: testing, js, ci, typescript | Session: s125

## What happens

A page is written, jest is green locally (`1765 tests in 29 suites`), everything else is green, the
PR is opened — and CI's **`JS Tests`** job fails in 30 seconds on a step that was never run locally:

```text
TypeScript-by-default gate: 4 problem(s)
  ✗ src/shipping-orders-page/app.js is a new .js/.jsx file under src/. TypeScript is the default
    for new files (#542) — author it as .ts/.tsx instead.
```

## Why

`npm run test:js` is `wp-scripts test-unit-js` and nothing else. The CI job called `JS Tests` runs
**seven** commands in order (`.github/workflows/ci.yml`):

```bash
npm run lint:ts-baseline    # TypeScript-by-default for new files under src/ (#542)
npm run typecheck           # tsc --noEmit
npm run lint:phone-masks    # generated table is current (#503)
npm run lint:imask          # vendored IMask matches package.json (#704)
npm run lint:i18n           # Russian catalogue has no untranslated English msgid (#771)
npm run lint:mo             # compiled .mo is current relative to the .po (#771)
npm run test:js             # ← the only one people run locally
```

Six of the seven are about **generated artefacts and conventions**, not about tests, and they are
exactly the ones a local `test:js` cannot speak for.

## Correct

Any brief or checklist that says "run the JS gate" must name all seven, and a new file under `src/`
is **TypeScript** — `.ts`, or `.tsx` where there is JSX.

⚠ **`scripts/ts-baseline.txt` is NOT the escape hatch.** It lists grandfathered `.js` files awaiting
conversion; you delete a line from it when you migrate one. Adding a line for a brand-new file
defeats the gate instead of satisfying it, and the gate's own message says so.

## Related

- [a-docs-gate-checks-links-not-listings](a-docs-gate-checks-links-not-listings.md) — the same shape
  of miss in the docs gate: what the local command checks is narrower than what the job checks
- [wp-scripts-names-a-chunk-from-basename-minus-js](wp-scripts-names-a-chunk-from-basename-minus-js.md)
  — the trap waiting on the other side of that conversion
