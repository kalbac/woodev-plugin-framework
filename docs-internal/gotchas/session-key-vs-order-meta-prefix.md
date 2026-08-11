# Session key ≠ order-meta prefix — two distinct installed-site contracts

**Namespace:** `[shipping/contracts]`
**Discovered:** 2026-06-06 (autodev loop, escalation `critic-s1-p1-pickup-selection`)

## The trap

When persisting "the customer's chosen X" (pickup point, time interval, etc.) it is tempting to
compose ONE key from the plugin's order-meta prefix and write it to **both** the WC checkout
**session** and the **order meta**. This silently breaks installed-site data on plugins where
the two stores use different key namespaces.

For the Yandex reference plugin they are genuinely different:

| Store | Installed key | Source |
|-------|--------------|--------|
| WC session (checkout) | `chosen_yandex_pickup_point` / `chosen_yandex_pickup_point_test` | `includes/functions.php:316-323` |
| Order meta | prefix `_yandex_delivery_` (decomposed `_destination_station_id`, `_destination_station_address`, …) | `includes/class-order.php:45-100` |

A single composed key (`$prefix . $key`) can satisfy **neither** — `chosen_yandex_pickup_point`
is not under `_yandex_delivery_`, and the order-meta side is decomposed fields, not one blob.

## Correct pattern

- **Session persistence** (during checkout) is keyed by a plugin-supplied **session key** and
  lives in a session-only primitive: `Woodev\Framework\Shipping\Pickup\Pickup_Selection`
  (`woodev/shipping-method/pickup/class-pickup-selection.php`).
- **Order-meta persistence** is a SEPARATE responsibility owned by the order handler
  (`class-shipping-order-handler.php`), keyed by the plugin's **order-meta prefix**.
- Never funnel both through one composed key. They are two independent entries on the
  "never break" list (`.autodev/INVARIANTS.md` `order_session_meta.exact_strings` lists BOTH
  `_yandex_delivery_` AND `chosen_yandex_pickup_point*` precisely because they are distinct).

### What the session primitive actually looks like (corrected s66, #143)

This section previously prescribed `Pickup_Selection: set`/`get`/`clear` — a single-slot store.
That class was deleted on `feat/pickup-map` (SP-5 T4) and **rebuilt with a different shape** by
#176, so the old prescription named methods that do not exist. The observation above is unchanged
and still correct; only the recommended implementation was stale.

The live API is a **map, not a slot** — the whole point of #176:

| Method | Purpose |
|---|---|
| `remember( string $locality, string $type, string $point_id )` | Store one point under a `(locality, type)` pair |
| `recall( string $locality, string $type ): ?string` | The point chosen for that exact pair |
| `recall_latest( string $locality ): ?string` | Most recently written entry for the locality (`Selection_Scope::TYPE_ANY`) |
| `forget_all()` | Drop the whole map — called only on order creation |

Three consequences worth carrying forward, all of them contract-level:

1. **The plugin, not the framework, decides the key.** Locality and type codes come from the
   plugin's `Selection_Scope` (`locality_for_point()`, `current_locality()`, `type_for_method()`),
   because three reference plugins use three incompatible locality dictionaries (ФИАС, `city_id`,
   `geo_id`). The framework never compares or normalizes those strings — see
   [[an-empty-domain-key-is-not-a-key]] for the one thing it does refuse.
2. **Keying by locality is why a real carrier keeps its point on return.** Почта РФ's single slot
   with a ФИАС guard loses the point when the customer leaves a city and comes back; the map does
   not. The map is bounded by `DEFAULT_MAX_ENTRIES` with sequence-ordered eviction.
3. **The session key itself is still the plugin's**, handed over by
   `Selection_Scope::session_key()` — the whole reason this gotcha exists. A map inside that key
   does not change the contract; it changes only the value's shape.

## Why it matters

Both keys are release-blocking installed-site data contracts (CLAUDE.md "never break" list).
Conflating them orphans live checkout session selections and/or live order meta on every
installed site at plugin-migration time.

## Related
- Spec corrected: `docs-internal/archive/platform-v2-s1-shipping-spec.md` §4.1.v (session-only)
- Contract source of truth: `docs-internal/migration/yandex-data-preservation-checklist.md`
  ("Chosen-point session key" row)
- `.autodev/INVARIANTS.md` — `order_session_meta` zone
- [[an-empty-domain-key-is-not-a-key]] — the framework's one rule about these plugin-supplied
  keys: `''` is the seam failing to answer, not a key, and must be refused on read AND write.
- [[guest-session-write-needs-the-cart-cookie]] — why this store needs no user-meta twin the way
  `WC_Edostavka_Customer_Location_Data` does: the guest-loses-the-write state is unreachable on
  the checkout.
- [[custom-checkout-field-is-empty-on-reload-by-construction]] — the restore side of the same
  mechanism (`woocommerce_checkout_get_value` → `Pickup_Handler::restore_selection()`).
