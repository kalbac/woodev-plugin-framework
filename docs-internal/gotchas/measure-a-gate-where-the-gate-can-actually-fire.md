# Gotcha: [testing/*] — Measure a gate only where the gate can actually fire, or you prove someone else's point
> Tags: rig, measurement, control, location | Session: s78

## What happens

Issue #346 claims a stale customer record UNLOCKS the address field: the layer thinks a
settlement is chosen, so rule #337 stands down.

The obvious rig pass: pick a settlement in RU, switch the country, reload, read
`shipping_address_1.disabled`. Done on **AM**, it returned `disabled: false` — the claim
apparently confirmed.

It proved nothing. `levels.AM.address === false` — the layer does not serve the address level in
Armenia at all, so `isAddressLocked()` returns `false` at its `isNodeActive()` guard long before
it ever looks at the settlement record. The field would read `disabled: false` with no stored
record, no country change, and no bug. The measurement could not have come out any other way.

Re-run on **BY** (`levels.BY.address === true`), the claim is genuinely confirmed: country BY,
settlement field EMPTY, address field open, `current = { key: "test-cdek:44" }` — a Russian
settlement.

## Root cause

A gate with several AND-ed preconditions can be satisfied by ANY of them. Reading the output
tells you the gate is open; it does not tell you WHICH condition opened it. Pick an environment
where the conditions you are not testing are all satisfied, or the one you are testing never gets
a vote.

Here `isAddressLocked()` reads:

```js
if ( ! chainNodeForLevel( entry, 'settlement' ) || ! isNodeActive( entry, node ) ) {
    return false;                       // <- AM exits here, every time
}
return null === scopeKeyFor( entry, 'address' );   // <- the condition under test
```

## Fix

❌ Wrong — one country, one reading, conclusion drawn:

```
country = AM → address disabled: false → "the stale record unlocks the address" ✓
```

✅ Correct — choose the environment so the untested preconditions hold, and say why:

```
levels.AM.address === false  → AM cannot test this gate at all, the early return wins
levels.BY.address === true   → BY can
country = BY → address disabled: false, city EMPTY, current = test-cdek:44  → claim confirmed
```

General rule for this rig: before measuring anything gated per level, read
`config.location.levels[country]` and pick a country where the level under test is served.
`RU`, `BY`, `KZ` and `UZ` serve `address`; `AM`, `AZ`, `KG`, `TJ` and `TM` do not.

## Related

- [a-restore-tied-to-a-server-confirmation-looks-like-a-render-artefact](a-restore-tied-to-a-server-confirmation-looks-like-a-render-artefact.md)
  — the same failure from the other side: settle "who did this" with a control, never by watching
- [a-probe-that-uses-the-production-accessor-creates-the-state-it-measures](a-probe-that-uses-the-production-accessor-creates-the-state-it-measures.md)
  — another way a rig probe answers a question you did not ask
- [a-level-served-can-come-from-the-fallback-not-the-active-provider](a-level-served-can-come-from-the-fallback-not-the-active-provider.md)
  — where the per-country `levels` map comes from
