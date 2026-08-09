# A per-viewport cache is unbounded by construction

**Namespace:** `[shipping/pickup]`
**Found:** s58 (09.08.2026), on the live Russian Post source. Operator's call; issue #226 follow-up.

## Root cause

The live viewport source cached its listing response per bounding box. The key was carefully
built — coordinates rounded to 3 decimals so a near-identical viewport still hits, the type filter
folded in so filtered and unfiltered views do not collide — and it was still the wrong idea.

**A viewport source is queried once per bbox, so the key space grows with every viewport a
customer explores.** There is no natural bound: a customer panning a map generates keys for as
long as they pan.

Measured on the rig:

- one central-Moscow listing response: **308 676 bytes**;
- thirty minutes of testing from **one** browser: **823 KB across 14 `wp_options` rows**.

Multiply by real concurrent customers on a real store. `autoload` being `off` bounds the
per-pageload cost but not the growth, the write churn, or the row count. The operator's verdict:
«ни один клиентский сайт не выдержит такого количества данных в транзиентах».

## Why the sibling source got away with it

The bulk Yandex source caches one global transient for a whole city, and a day-long TTL is safe
there because there is exactly **one key**. Copying that shape to a bbox-addressed source copies
the TTL reasoning while silently discarding its precondition. Shortening the TTL — which the first
version did, 15 minutes instead of a day — treats the symptom: it bounds how long each entry
lingers, not how many are created.

## The rule

**Before caching, ask what BOUNDS the key space — not what bounds each entry's lifetime.** A cache
whose key is derived from a continuous, user-driven input (a viewport, a scroll offset, a free-text
query) has no bound by construction, and no TTL fixes that.

Then cache the thing whose count IS bounded. Here that is the individual point: ~2 KB, keyed by
id, bounded by how many cards a customer opens rather than how far they pan. After the change, the
same run that had produced 823 KB left **4 rows, 4 820 bytes**.

## Two things that made the per-point cache safe to keep

- **The verdict is not in it.** `Constraint_Checker` recomputes `selectable` against the live cart
  on every request, outside the source entirely, so a cached carrier record can never serve a
  stale "yes" to a cart that changed. Worth checking explicitly before agreeing to any cache that
  sits under a customer-facing decision.
- **A negative answer is not cached.** An empty/unshaped detail response is far likelier to mean
  "we sent the wrong key" — our own bug — than "this point is gone, and caching it would hide that
  for a full TTL at a time.

## The trade, stated

Every pan now costs a live upstream call, bounded by the existing per-IP rate limiter. The
carrier's own widget does the same: no server-side listing cache at all. A slower correct picker
beats a fast one that fills the merchant's options table.

## Related

- [[a-per-cycle-memo-is-not-in-flight-deduplication]] — the other cache-shaped defect in this
  subsystem: one map doing two jobs with two different lifetimes.
- [[an-invented-fixture-tests-your-assumptions-not-the-carrier]] — the same session's lesson about
  copying a shape without its preconditions.
