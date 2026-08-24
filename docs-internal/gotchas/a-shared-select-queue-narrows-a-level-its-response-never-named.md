# One `/select` response narrows EVERY level, including one it could not possibly have named

**Namespace:** `[shipping/location]` · **Discovered:** s89 (2026-08-24), reproducing the last 1-in-5
failure of #490 after two earlier fixes had already landed

## The trap

`adoptChain()` is the function that turns an optimistic local record into a server-confirmed one. It
narrows `entry.records[level]` down to `{ key, confirmed: true }` — deliberately, because
`confirmed` marks PROVENANCE, not validity.

The problem is scope. The `/select` queue (`sendNextSelect()`) is **per ENTRY, not per LEVEL**, and
`adoptChain()` narrows **every** level in the chain from whichever response happens to land. So a
still-QUEUED pick — one the server has not been told about yet — gets its optimistic record wiped by
an earlier, unrelated response.

Concretely: the customer picks a region, then picks a settlement ~250 ms later while the region's
`/select` is still in flight. The settlement pick queues. The region's response lands and narrows the
settlement level too, to a `key` that response never mentioned. Anything that then reads the
settlement record — in #490's case the Rule 7c column-swap carry — finds nothing.

## Why it survived two rounds of fixes

It looks exactly like the two defects fixed before it, and it is intermittent, so a green run proves
nothing:

1. round 1 fixed "the record was narrowed, so `fieldValueFor()` had nothing to read";
2. round 2 fixed "the outgoing DOM element was stale after `detach()`";
3. **this** is "the record was wiped by someone else's response before either of those ran".

All three present as "the settlement did not carry". Rounds 1 and 2 each moved the failure rate
without closing it — measured 0/2, then 4/5. **A partial improvement in a flaky measurement is the
signature of more than one cause**, and it is the point at which to stop fixing and start reproducing
deterministically instead.

## ✅ Correct

Give the narrowing a level it must not touch, and have the queue name the pick that is still waiting:

```js
// adoptChain( entry, chain, protectedLevel ) — the level of a pick that is still QUEUED has not
// been confirmed by THIS response and must survive it.
adoptChain( entry, body.chain, entry.pendingRecord ? entry.pendingRecord.level : null );
```

## How to reproduce it on purpose

Do not wait for the first `/select` to settle. Pick the parent level, wait ~250 ms, then pick the
child — the window is the length of one round trip. In a test, resolve the parent's response AFTER
the child's pick has been queued.

## Related

- [a-locality-display-name-is-not-an-identifier](a-locality-display-name-is-not-an-identifier.md)
- [the-classic-adapter-reverts-a-select-the-location-cascade-owns](the-classic-adapter-reverts-a-select-the-location-cascade-owns.md)
- `docs-internal/AGENT-RULES.md` → Rule 7c, the rule this broke
- `woodev/shipping-method/assets/js/frontend/location-cascade.js` → `adoptChain()`, `sendNextSelect()`
