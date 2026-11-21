# Async jest tests that pass on an idle machine fail under CPU load — the cause is the test's wait, not the product

**Namespace:** `[testing/js]`
**Found:** s240 (2026-11-21), card #1892 — a full `npm run test:js` while 2–3 other agents ran failed exactly one async test, a different one each time; alone it passed.

## Symptom

One red test per full run, never the same one (`admin-records-matching`, `admin-records-chrome`, `marketplace-product-tab`, and under heavier load `shipping-orders-page-app`). Green when run alone. Reproduced on a 10-core Mac with 30 busy loops (`while :; do :; done &`) next to the suite: **4 of 5 full runs red** before the fix, **8 of 8 green** after.

## Root causes (four, all "the test assumed the machine is fast")

1. **Testing Library's `findBy*` / `waitFor` default to 1000 ms of wall clock** — the same budget for a 5-row table and for the 3 000-line orders app. Fixed once: `configure( { asyncUtilTimeout: 5000 } )` in `tests/js/jest.setup.js`, with `testTimeout: 15000` in `jest-unit.config.js` so a real miss is reported as the missing element, not as a bare jest timeout.
2. **A test double that draws the table while `isLoading`** — `findByRole( 'table' )` then resolves BEFORE the list answered, and every sync assertion on counts/rows after it races the fetch. The doubles now draw no table while loading (like the real card), so "the table is there" means "the list is here".
3. **A fixed `setTimeout( resolve, 30 )` as "wait for the script to set itself up"** (jQuery ready on a timer + one more tick). Replaced by `waitFor` on the thing the setup produces.
4. **A state set by an effect AFTER the commit that the awaited element belongs to** (the account line, `use-activity-loop`) read synchronously. Await the thing itself.

A fifth, product-side: the orders page mounts a 400 ms search debounce whose tick calls `setPage( 1 )` although the box never changed — a test that pages within 400 ms of mount is sent back to page 1 when the tick lands. Idle machines finish the test first; loaded ones do not. Tests now page after the tick (`goToNextPage()` in `shipping-orders-page-app.test.js`). The product behaviour was not touched (delivery code is out of scope); it is a real, if tiny, quirk.

## ❌ / ✅

❌ Raise one test's timeout, add a retry, or sleep a guessed number of ms.
✅ Wait for the observable result (`waitFor` / `findBy*` on the element, not on a neighbour), keep the shared budget in the setup file, and make doubles reflect loading.

## How to reproduce

`for i in $(seq 30); do ( while :; do :; done ) & done; npm run test:js; pkill -f 'while :'` — five full runs; clean the burners up afterwards.

## Related

- [three-agents-is-the-concurrency-cap-on-this-machine](three-agents-is-the-concurrency-cap-on-this-machine.md)
- [jest-scans-agent-worktrees-inside-the-repo](jest-scans-agent-worktrees-inside-the-repo.md)
