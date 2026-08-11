# SP-5 — Pickup selection persistence: implementation plan (#176)

> Design: `docs-internal/specs/2026-08-11-sp5-pickup-selection-persistence-design.md`.
> Read it first — it carries the measurements the design rests on and the rejected
> alternatives. This file only says what to build.

## Ground rules for anyone executing this

- **Serena MCP is mandatory for PHP.** Navigate and read `.php` with `find_symbol` /
  `get_symbols_overview` / `search_for_pattern`, never raw `Read`. Verify Serena answers a
  symbol call before starting; if it does not, stop and report rather than falling back.
- **Edit existing PHP with the built-in `Edit` tool, not Serena's writers.** Serena's
  `replace_content` / `replace_symbol_body` rewrite the whole file as CRLF on Windows and break
  LF source assertions (gotcha `serena-replace-content-eol-flip`). New files are fine either
  way; normalize with `sed -i 's/\r$//'` if in doubt.
- **You are licensed to contradict this plan.** If the code disagrees with something written
  here, the code wins — say so in your report and do the right thing. Three of four errors in a
  previous session's plan were caught exactly this way.
- **Tests: never `npx jest`.** `npm run test:js -- --roots "<rootDir>/tests/js"`, from Bash
  (`<rootDir>` breaks in PowerShell). PHP: `composer test:unit`, `composer phpcs`.
- **Any number a test asserts must be mutated against a neighbouring value** before you trust
  the test (gotcha `advancing-the-whole-interval-does-not-pin-a-delay`). That applies here to
  the cap and to the eviction count.

## Step 1 — `Selection_Scope` (the plugin seam)

New file `woodev/shipping-method/pickup/interface-selection-scope.php`, in the house style of
`interface-point-source.php`: namespace `Woodev\Framework\Shipping\Pickup`, `ABSPATH` guard,
`interface_exists` guard, docblocks with `@since 2.0.2`.

```php
interface Selection_Scope {
    public const TYPE_ANY = '*';

    public function session_key(): string;
    public function locality_for_point( Pickup_Point $point ): string;
    public function current_locality(): string;
    public function type_for_method( string $method_id ): ?string;
}
```

The docblocks carry the contract, and must state plainly:

- the framework never interprets, normalizes or compares the locality key except to another
  string from the same scope — carriers disagree on what a locality is (Почта РФ: settlement
  name, СДЭК: `city_id`, Яндекс: `geo_id`) and a customer spells one city several ways;
- `locality_for_point()` and `current_locality()` are asymmetric on purpose — one answers off a
  point, the other off checkout state (this mirrors the reference implementation, see the spec);
- `type_for_method()` receives the method id **after** instance-suffix normalization;
- the three return cases of `type_for_method()` (code / `TYPE_ANY` / `null`) and what each does.

## Step 2 — `Pickup_Selection` (the framework mechanism)

New file `woodev/shipping-method/pickup/class-pickup-selection.php`. Owns the session map and
nothing else: it must not know what a locality or a type means.

Stored shape, under the scope's own session key:

```php
[ <locality> => [ <type code> => [ 'id' => <point id>, 'seq' => <int> ] ] ]
```

Methods (final shape is yours to choose; these are the required behaviours):

- `remember( string $locality, string $type, string $point_id ): void` — writes the entry with
  the next sequence number. Overwriting an existing entry **must** refresh its sequence, or the
  recency order silently inverts (§6 of the spec).
- `recall( string $locality, string $type ): ?string` — exact `(locality, type)` lookup.
- `recall_latest( string $locality ): ?string` — highest `seq` within that locality; backs
  `TYPE_ANY`.
- `forget_all(): void` — clears the whole map (order created).

Bounding, per §6:

- total entries across all localities are capped; oldest `seq` evicted first;
- the cap goes through a filter (`woodev_pickup_max_remembered_selections` or similar — match
  the naming of `woodev_pickup_max_accumulated_points`), default a small integer;
- `0` means unbounded, matching the existing filter's convention — **check that convention in
  `Pickup_Handler`/`pickup-mount.js` before copying it**;
- the entry that was just written is never the one evicted.

Session access: no WooCommerce global may be reached in a way that fatals when WooCommerce is
absent. Follow `Pickup_Handler::wc_session_chosen_shipping_methods()`'s shape — guard on
`function_exists( 'WC' )` and a null session, and keep the accessor `protected` as a test seam
(that is why every sibling accessor in that class is `protected`).

Unit tests in `tests/unit/`. Must include: overwrite-refreshes-recency (write A, write B,
re-write A, assert A is latest), eviction drops the oldest and never the newest, cap mutated to
a neighbouring value, `recall` on an absent locality/type, and no WooCommerce → no crash and no
write.

## Step 3 — wire it into `Pickup_Handler`

Read the class with Serena first. Its constructor already carries 14 positional parameters and
that is a known complaint (#170) — add exactly one nullable parameter,
`?Selection_Scope $selection_scope = null`, at the END, and do not reorder anything.

**Write** — on the confirmed `/select` path, after the server has allowed the point and never
before (D-1). Find where the selection result is produced and store there:

```
remember( scope->locality_for_point( $point ), $point->get_type()['code'], $point->get_id() )
```

`Pickup_Controller` must stay free of WooCommerce globals — its docblock states this and it is
what lets its dispatch core be unit-tested without WooCommerce. If the REST context has no cart
or session yet, use the bridge that already exists in this class:
`wc_load_cart_available()` / `load_wc_cart()`, as `current_cart_weight_grams()` does.

**Restore** — hook `woocommerce_checkout_get_value` in `register()`. Answer only for
`$this->field_id`; return the untouched incoming `$value` for every other field, or the filter
will short-circuit other people's fields. Logic is §5 of the spec, steps 2–4.

Read the chosen shipping method through the accessor that already exists
(`wc_session_chosen_shipping_methods()`), and normalize the instance suffix the same way
`Checkout_Handler::normalize_method_id()` does — if that helper cannot be reached from here,
replicate its rule rather than inventing another.

**Clear** — `forget_all()` on order creation. `handle_checkout_order_processed()` already runs
there; note it returns early when the plugin wired no order handler, so the clear must not sit
behind that early return.

Unit tests: the filter answers only for its own field id; a non-pickup method
(`type_for_method()` → `null`) restores nothing; a typed method restores the right type when two
types are stored for one locality; `TYPE_ANY` restores the most recent; no scope → the handler
behaves exactly as today (this one guards every existing consumer).

## Step 4 — fixture

`tests/_fixtures/woodev-test-shipping-method/` needs a `Selection_Scope` so the rig can exercise
this. Note the fixture trap: a class that `implements` a `Woodev\Framework\*` symbol must be
declared INSIDE the plugin's init callback, never at file top level (gotcha
`fixture-classes-must-live-inside-plugin-init`).

The fixture scope should be deliberately unlike the framework's own vocabulary, so a hidden
assumption shows up: return a coded locality (not the city name) and map the single fixture
shipping method to `TYPE_ANY`.

## Step 5 — verification

`composer phpcs`, `composer test:unit`, `npm run test:js -- --roots "<rootDir>/tests/js"` — the
JS suite must be unchanged in count, since this task touches no JS. PHPStan crashes locally on
Windows (`phpstan-windows-parallel-worker-segfault`); Linux CI is the authoritative gate.

Rig verification is a separate step and stays with the session owner, not the implementer:
Москва → СПб → Москва with a different point each, and one locality holding both a ПВЗ and a
постамат while the method switches. Measured by probe (read the field's `.value`, listen on
`document.body`), never by screenshot timing.

## Out of scope

Map state persistence (filter, viewport) — separate cards. The pre-existing behaviour where a
stale id is posted after switching to a courier method without reloading is noted in the spec
and is not this card's to fix.
