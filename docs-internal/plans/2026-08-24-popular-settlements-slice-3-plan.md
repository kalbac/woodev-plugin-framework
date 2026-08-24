# Popular settlements — slice 3 implementation plan (#488)

> Written at the start of s90 (24.08.2026) as the shared contract for two parallel workers.
> Design authority: [../specs/2026-08-24-popular-settlements-design.md](../specs/2026-08-24-popular-settlements-design.md).
> **The spec wins.** This file only fixes the seams the two workers must agree on so they can be
> built at the same time without reading each other's code.

Slices 1 and 2 are merged. What is left is D5–D7 (lazy verification at `/select`) and D8 (the two
merchant actions).

## What already exists (do not rebuild)

| Thing | Where |
|---|---|
| `Location_Provider::resolve_key( string $key ): ?Location_Record` + `CAPABILITY_RESOLVE_KEY` | `woodev/shipping-method/location/interface-location-provider.php` |
| `Popular_Settlement_Store` — `install()`, `enroll()`, `all_for_provider()`, `evict_expired()`, `remember_candidate()`, `recall_candidate()` | `woodev/shipping-method/location/class-popular-settlement-store.php` |
| `Popular_Settlement_Entry` — `id()`, `provider_id()`, `country()`, `record()`, `order_count()`, `last_ordered_at()`, `last_verified_at()`, `created_at()` | `woodev/shipping-method/location/class-popular-settlement-entry.php` |
| `Location_Provider_Registry::popular_settlement_store()` | `woodev/shipping-method/location/class-location-provider-registry.php` |
| `Location_Controller::handle_select_request()` — the `/select` route | `woodev/shipping-method/rest-api/class-location-controller.php` |
| Enrolment: `woocommerce_checkout_order_processed` → order meta → `Shipping_Admin_Order` export → `enroll()` | registry + `woodev/shipping-method/admin/class-shipping-admin-order.php` |

**The `null` contract is load-bearing and must not be weakened.** `resolve_key()` returning `null`
means exactly one thing — *the provider was asked and confirmed it does not know this key*. Every
other condition (provider not configured, transport failure, malformed payload, unmappable row,
derived key) **throws**. D6 deletes a row on `null`; a weakened `null` deletes live data.

## Seam A — the verification engine

New class `Woodev\Framework\Shipping\Location\Popular_Settlement_Verifier`, file
`woodev/shipping-method/location/class-popular-settlement-verifier.php`, registered in
`woodev/class-map.php`.

```php
public function __construct( Popular_Settlement_Store $store )

/** Applies D6 to ONE entry. Never throws for a provider-side failure — it reports it. */
public function verify_entry( Location_Provider $provider, Popular_Settlement_Entry $entry ): Popular_Settlement_Verification

/** Applies D6 to every row of one provider (D8's "Проверить актуальность"). */
public function sweep( Location_Provider $provider ): array   // { checked, unchanged, updated, deleted, failed }
```

`Popular_Settlement_Verification` is a small immutable value object in the same namespace
(`class-popular-settlement-verification.php`), carrying:

| Member | Meaning |
|---|---|
| `outcome(): string` | one of `unchanged`, `updated`, `gone`, `failed` |
| `record(): ?Location_Record` | the provider's fresh record for `unchanged`/`updated`; `null` otherwise |
| `error(): ?\Throwable` | the exception for `failed`; `null` otherwise |

Behaviour, straight from D6:

| `resolve_key()` said | Store side effect | `outcome` |
|---|---|---|
| the same record | bump `last_verified_at` only | `unchanged` |
| a changed record (incl. a changed key) | overwrite `record`, `locality_key`, `country`; bump `last_verified_at`; **do not touch** `order_count` / `last_ordered_at` | `updated` |
| `null` | **delete the row** | `gone` |
| threw | **nothing at all** — no delete, no clock bump | `failed` |

`failed` is not `gone`. A provider that could not be asked has said nothing, and the row survives.

## Seam B — new store methods

Added to `Popular_Settlement_Store`, all public:

```php
public function find_entry_by_key( string $provider_id, string $key ): ?Popular_Settlement_Entry
public function touch_verified( int $id, ?int $timestamp = null ): void
public function replace_record( int $id, Location_Record $record, ?int $timestamp = null ): void
public function delete_entry( int $id ): void
public function clear_provider( string $provider_id ): int   // D8's second button; returns rows deleted
```

Plus the SECOND clock's calibration (D2 — the existing `DEFAULT_TTL_SECONDS` /
`FILTER_TTL_SECONDS` pair is the **usage** clock, `last_ordered_at`, and must not be reused):

```php
public const DEFAULT_VERIFY_TTL_SECONDS = 2 * WEEK_IN_SECONDS;
public const FILTER_VERIFY_TTL_SECONDS  = 'woodev_location_popular_settlement_verify_ttl_seconds';
public static function verify_ttl_seconds(): int;      // mirror the shape of the existing ttl_seconds()
public function is_stale( Popular_Settlement_Entry $entry, ?int $ttl_seconds = null ): bool;
```

`last_verified_at === null` **is stale** — nobody has confirmed the row yet. The number itself is
calibration, not design (spec: "Numbers, deliberately not invented here"); it sits behind the filter
so it can be changed without a release.

## Seam C — the `/select` response, and it is the whole client contract

`Location_Controller::handle_select_request()` gains a D5 step **before** it persists the customer's
record:

1. Look the posted record up with `find_entry_by_key( $record->provider_id(), $record->key() )`.
   Not found, or found and fresh → **behave exactly as today**. This is the overwhelmingly common
   path and it must not gain a provider call, a query it did not have, or a changed response.
2. Found and stale → `verify_entry()`, then:

| `outcome` | What `/select` persists | Response |
|---|---|---|
| `unchanged` | the customer's record, as today | as today |
| `updated` | **the provider's fresh record**, not the posted one (D1 equivalence: search would have returned the new one) | as today, with `current`/`chain` naturally carrying the new key |
| `failed` | the customer's record, as today | as today. A provider outage must never block a purchase. Log via the controller's existing `log_failure()` |
| `gone` | see D7 below | see D7 below |

D7, on `gone` — the row is already deleted by then:

1. Run the ordinary search (`$provider->suggest()`) for the stored settlement name, scoped to the
   stored region.
2. **Exactly one** result whose settlement name AND region both match the stored ones → adopt it
   silently: persist THAT record and answer as an ordinary success, `current` carrying the adopted
   key. The customer notices nothing.
   Compare on a trimmed, `mb_strtolower`-ed string, and require BOTH fields. Two candidates, zero
   candidates, a mismatched region, or a `suggest()` that throws → not a match. Never substitute a
   near match: gotcha `a-locality-display-name-is-not-an-identifier`.
3. Anything else → **cancel the pick**. Write nothing to the customer store and answer:

```php
[
    'cancelled' => true,
    'reason'    => 'stale_record',
    'message'   => __( 'Данные не актуальны, выберите заново', 'woodev-plugin-framework' ),
    'current'   => null,
    'persisted' => false,
    'chain'     => [ /* the server's chain as it stands — unchanged by this request */ ],
]
```

`cancelled` is **absent** (not `false`) on every ordinary response, so a client that has not been
updated keeps working unchanged. HTTP status stays 200: this is a real answer about the data, not a
transport failure, and the client has to tell the two apart to show the right thing.

**The message is not optional** (D7, and the project default on blocked controls). The customer
clicks, the field empties and the address field re-locks on top of it; silence reads as two
breakages in a row.

## Seam D — the client half of D7

`woodev/shipping-method/assets/js/frontend/location-cascade.js` (and
`location-select-modes.js` where the widget is owned) must, on a `/select` response carrying
`cancelled: true`:

- clear the field's value and the widget's selection, leaving no synthetic `<option>` behind;
- show `response.message` where the empty-suggestions case already shows
  «Поиск не дал результатов…» — the existing precedent, reused, not a new surface;
- **not** treat it as a transport error (no retry, no silent swallow), and leave the deeper chain
  levels exactly as the response's `chain` says.

And, for the `updated`/adopt paths: the client must adopt the server's `current` when it differs
from what it posted, rather than keeping its own posted key. The response is already documented as
the server's authoritative answer; this makes that true for the key as well as the chain.

## Seam E — D8, the two merchant actions

Both live on the location settings handler
(`woodev/shipping-method/location/class-location-settings.php`) and follow the connection-test
seam — `Woodev_Settings_Connection_Test` / `Woodev_Connection_Result`, route
`/woodev/v1/settings/{provider}/connection/{id}/test`, see
`woodev/rest-api/controllers/class-rest-api-settings-page.php`.

- **«Проверить актуальность популярных городов»** → `Popular_Settlement_Verifier::sweep()` on the
  active provider, reporting the counts it returns.
- **«Очистить список популярных городов»** → `Popular_Settlement_Store::clear_provider()` on the
  active provider, reporting how many rows went.

Both are absent — not present-and-disabled — when the active provider lacks
`CAPABILITY_RESOLVE_KEY`, matching D4: no capability, no popular list, nothing about it in the UI.

**The spec flags this as an implementation-time check, not an assumption:** *"How cleanly that seam
generalises is an implementation-time check."* If the connection-test seam turns out to be bound to
connection blocks in a way these two actions cannot honestly use, say so with the `file:line` that
proves it and stop for a decision rather than bending the seam.

## Related

- [../specs/2026-08-24-popular-settlements-design.md](../specs/2026-08-24-popular-settlements-design.md) — the design; D1–D8 and D4a
- [../specs/2026-08-21-settlement-search-design.md](../specs/2026-08-21-settlement-search-design.md) — #437, the surrounding redesign
- [../AGENT-RULES.md](../AGENT-RULES.md) — Rule 7a/7b/7c
