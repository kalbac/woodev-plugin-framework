# One identity, two roles: one must refuse, the other must fall back

> `[shipping/location]` — established across #334 (s74) and #336 (s74), after an adversarial
> review caught the rule being copied in the wrong direction.

## The shape

The customer's settlement key is read by two different consumers on the pickup surface:

| role | consumer | what happens when there is no settlement |
|---|---|---|
| **storage key** | `Provider_Selection_Scope::current_locality()` → `Pickup_Selection` | must answer `''` and REFUSE |
| **addressing key** | `Pickup_Handler::current_location_record()` → the map | must FALL BACK to the current record |

It is the same value, derived from the same chain, one method apart — so the natural instinct
(and the first draft of #336) is to make them agree. **Making them agree breaks one of them,
whichever way you do it:**

- give the STORAGE key a fallback → the point is written under the settlement key and read
  back under the address key; the customer's chosen point becomes unreachable, silently. That
  is #334, the bug this whole line of work started from.
- make the MAP refuse → a customer who typed an address without ever picking a settlement (the
  cascade's backwards fill writes the settlement FIELD's text but creates no settlement RECORD)
  gets «в этом населённом пункте нет пунктов выдачи» over a live carrier with points. That is a
  regression introduced by a fix.

## The rule

**The correct degradation is a property of the ROLE, not of the value.** Ask what a wrong
answer costs in each place:

- a key that FILES something (a session map, a cache, order meta) mis-files it silently and
  permanently — refusing is cheap, guessing is not (gotcha
  `an-empty-domain-key-is-not-a-key`);
- a key that only ADDRESSES a read (a query, a viewport, a display) costs one wrong-looking
  result the customer can see and correct — falling back keeps the feature alive, refusing
  kills it.

So: **write both rules into both docblocks, each naming the other.** The asymmetry looks like
an inconsistency to every future reader, and a reader who "fixes" it without knowing which
direction is safe will reintroduce one of the two defects above. Both `current_locality()` and
`current_location_record()` now carry that cross-reference for exactly this reason.

## How it was caught

Not by tests — a test written from the same misunderstanding pins it (the #336 draft had one
asserting the config block's `current.key` should carry the settlement, which also made
`current` contradict its own sibling `implicit`). It took an adversarial reviewer reading the
diff against the design doc, plus a rig probe exercising BOTH halves in one run: an
address-only chain (map still addresses, key refuses) and a settlement+address chain (map
addresses by the settlement).

## Related

- [[a-derived-ancestor-is-not-the-one-the-customer-picked]] — why the settlement cannot simply
  be derived when it is missing
- [[an-empty-domain-key-is-not-a-key]] — the refusal half of the rule
- [[session-key-vs-order-meta-prefix]] — the other "one value, two contracts" split in this
  subsystem
