# gotcha: an empty `owners[country][level]` does not mean "no owner" — it disarms the guard that reads it

**Namespace:** `[shipping/location]`
**Discovered:** s88 (2026-08-24)

## What happened

On a mixed-provider rig — `test-cdek` serving region+settlement, DaData serving address — picking
an **address** suggestion overwrote the **region** field with DaData's own spelling. The preset
region `<select>` grew a second, foreign option («Sankt-Peterburg» beside «Санкт-Петербург»), and a
reload emptied the chain because that value is in no preset list.

The settlement level was untouched in the same session. **That asymmetry is the whole clue.**

## Root cause

`location-cascade.js`'s `backwardsFill()` refuses to write an ancestor level owned by a different
provider:

```js
var owner = levelOwner( entry, country, ancestorLevel );
if ( owner && owner !== record.provider_id ) { return; }
```

The guard is **falsy-guarded**. An empty owner is not "nobody owns it, be careful" — it is "skip
the check entirely". Measured on the rig:

```text
owners.RU = { region: "", settlement: "test-cdek", address: "dadata" }
```

Settlement was protected because its owner was set. Region was not, because its owner was `''`.

And the server knew better all along:

```text
service:   get_levels_for_country('RU').region = true
           get_level_owners_for_country('RU').region = "test-cdek"
           owns_region_states('RU', final)          = true
published: levels.RU.region = false,  owners.RU.region = ""
```

`Checkout_Config::build_location_block()` blanked the owner whenever `levels['region']` was false.
In `related-list` region mode **this layer's own injector** fills `woocommerce_states` (measured: 87
CDEK regions), so `$states_present` was always true, the #294 arbitration stood the layer down from
its own list, and the owner went with it.

## The distinction that was missing

`levels` and `owners` answer **two different questions**, and under `related-list` they legitimately
diverge:

| | question | related-list answer |
|---|---|---|
| `levels[c].region` | is this a **typeahead** target? | `false` — it renders a native `<select>` |
| `owners[c].region` | **who owns** this level? | `test-cdek` — we fill that select |

Fixed by keying the blanking on ownership rather than on `levels` alone: stand down only when the
state list is genuinely someone else's (`! owns_region_states()`).

## ⚠ Do not "fix" `levels` too

An earlier attempt also forced `levels['region']` true in related-list mode. The existing test
`test_doing_it_wrong_does_not_fire_for_the_layers_own_related_list_injection` pins that `false`
deliberately, and it is right: the client reaches a related-list region node through
`isRelatedListRegionNode()`, its own documented exception to the D15 level gate — not through this
flag.

## ✅ The rule

**A falsy entry in a permission/ownership map is an OPEN gate, not a closed one.** Before blanking
such a value "for coherence", find every consumer and ask what a falsy value makes them do. Here,
one consumer (`mayEnterChain()`) reads `settlement` only and was unaffected; the other
(`backwardsFill()`) was silently disarmed.

## Related

- [a-level-served-can-come-from-the-fallback-not-the-active-provider.md](a-level-served-can-come-from-the-fallback-not-the-active-provider.md)
- [a-locality-display-name-is-not-an-identifier.md](a-locality-display-name-is-not-an-identifier.md)
- [a-select-value-write-with-no-matching-option-submits-nothing.md](a-select-value-write-with-no-matching-option-submits-nothing.md)
