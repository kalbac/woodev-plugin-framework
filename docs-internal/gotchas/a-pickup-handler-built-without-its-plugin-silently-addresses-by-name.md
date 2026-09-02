# A `Pickup_Handler` built without its plugin silently addresses the carrier BY NAME

**Namespace:** `[shipping/pickup]` · **Measured:** s113 (03.09.2026), on the live rig, answering the
operator's question about how a carrier is told WHICH settlement to return points for.

## The designed contract

`Point_Query`'s class docblock (#159, Task 15, spec §4.5.4) is explicit:

> What changed is what a client now puts there: the Location Provider layer's namespaced
> **locality KEY** (`provider_id:native_id`) rather than a raw, DOM-read place name.

and the server adds two richer things a real carrier needs — `Point_Query::get_record()` (the
customer's neutral `Location_Record`) and `Point_Query::get_resolved_identity()` (**this plugin's
own** carrier identity, from `Location_Service::resolve_for()` — CDEK's `city_code`, Yandex's
`geo_id`).

## The trap — all of that switches off from ONE forgettable argument

`Pickup_Handler`'s constructor takes the owning plugin as its **last positional parameter, the
14th**. Omit it and:

- `Pickup_Handler::$plugin` is `null`, so no `location_context` callable reaches
  `Pickup_Controller`, so `attach_location_context()` is a **no-op**: the source gets neither the
  record nor the resolved identity;
- `location_config_block()` publishes no `location` block, so the browser's
  `resolveLocalityKey()` falls through to `resolveLocality()` — **`document.getElementById(target +
  '_city').value`**, a raw DOM-read place name.

Nothing warns. Both halves degrade quietly and independently of each other.

Measured on the rig, the two fixtures side by side:

| carrier | `location` block | what `?locality=` carries |
|---|---|---|
| `woodev-test-shipping-method` (passes `$this`) | present, `settlementKey: test-cdek:44` | `test-cdek:44` — the KEY |
| `woodev-realistic-shipping` (omits it) | `null` | `Москва` — a DOM-read NAME |

## Why it is invisible until production

A name works perfectly for any settlement whose name is unique. «Москва» behaves identically
whether addressed by name or by key, so every rig pass, every screenshot and every green test looks
right. It breaks on «Октябрьский», «Первомайское», «Красноармейский» — names that exist in dozens
of regions — where the carrier cannot know which one is meant, and by then it is a live shop.

## ❌ Wrong

```php
new Pickup_Handler( 'my-carrier', 'my_pickup_point', $source, $map, $default );
```

## ✅ Correct

```php
new Pickup_Handler(
    'my-carrier', 'my_pickup_point', $source, $map, $default,
    …, $selection_scope,
    $this            // ← the owning plugin. Without it, addressing degrades to a place name.
);
```

## How to check in one line

Open the picker and read the request URL:

- `?locality=test-cdek:44` → wired correctly, addressing by key.
- `?locality=Москва` → the plugin was not passed; the carrier is being addressed by an ambiguous
  name and the source is getting no record and no resolved identity.

Card **#746** proposes making this degradation loud (a `_doing_it_wrong()` under `WP_DEBUG`, the
same treatment #709/#736 gave to incomplete pickup declarations).

## Related

- [the-pickup-modal-s-locality-comes-from-the-resolved-record-not-the-city-field.md](the-pickup-modal-s-locality-comes-from-the-resolved-record-not-the-city-field.md) — the other way a locality goes wrong, from the checkout side
- [a-process-static-once-per-request-gate-checks-only-the-first-plugin.md](a-process-static-once-per-request-gate-checks-only-the-first-plugin.md) — the other "silently only half-applied" trap in this layer
- [standing-up-a-second-carrier-plugin-has-three-traps-a-green-unit-suite-cannot-see.md](standing-up-a-second-carrier-plugin-has-three-traps-a-green-unit-suite-cannot-see.md) — the fixture this was measured on
