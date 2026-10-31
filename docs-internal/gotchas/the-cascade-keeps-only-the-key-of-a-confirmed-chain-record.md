# Gotcha: [shipping/location] The cascade keeps only the KEY of a confirmed chain record

> Tags: location-cascade, adoptChain, cross-provider | Session: s208 (#1534)

## What happens

A client-side check that compares a new pick with the settlement the customer already confirmed —
"is this house in the same city?" — finds no name to compare with. `entry.records.settlement` holds
the full provider record only between the pick and the server's answer. After that it is
`{ key, confirmed, implicit, implicitSource }` (plus `lat`/`lon`), and on a page reload it never held
more. A check written against `entry.records.settlement.settlement.name` passes its unit test (the
test picks and checks in one tick) and is always false on the rig.

## Root cause

`adoptChain()` in `location-cascade.js` replaces every level with the server's chain, and the wire
chain is `Location_Record::to_chain_node()`: key, level and coordinates only. The node stays small
because it travels with every checkout refresh.

## Fix

Read the name from the settlement FIELD. The settlement pick wrote it there (`fieldValueFor()`), and it
is what the customer sees. Use the record only to prove that something was confirmed.

```js
// ❌ empty after the /select round trip and after every reload
var name = entry.records.settlement.settlement.name;

// ✅ the confirmed record proves a pick; the field holds its name
var confirmed = entry.records.settlement;
var field = document.getElementById( chainNodeForLevel( entry, 'settlement' ).fieldId );
var shown = confirmed && confirmed.key ? normalizeSettlementName( field.value, type ) : '';
```

A test of such a check must resolve the settlement's `/select` with a chain and flush the microtasks
before the second pick. Otherwise it reads the optimistic full record, which production never has at
that moment.

## Related

- [a-locality-display-name-is-not-an-identifier.md](a-locality-display-name-is-not-an-identifier.md) — a name compare also fails when the two providers answer in different locales
- [a-shared-select-queue-narrows-a-level-its-response-never-named.md](a-shared-select-queue-narrows-a-level-its-response-never-named.md) — the same `adoptChain()` overwrite, seen from the queue
