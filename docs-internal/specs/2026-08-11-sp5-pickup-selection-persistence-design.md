# SP-5 — Pickup selection persistence across checkout requests (#176)

> Status: design agreed with the operator 11.08.2026 (s65). Implementation plan follows separately.
> Cards: closes #176; resolves the open question in #178; makes #143's gotcha accurate again.

## 1. The defect, as measured

A chosen pickup point does not survive a checkout page reload. The card suspected an
unwritten persist rather than a broken restore. That is confirmed, by construction, on four
independent legs:

1. The field is a plain `hidden` WooCommerce checkout field — `Pickup_Field::create()` sets
   `type = hidden`, and `Checkout_Handler::register()` injects it through
   `woocommerce_checkout_fields`.
2. WooCommerce renders it with `WC_Checkout::get_value()` (`form-shipping.php:63` for the
   `order` section). For a key that is neither `billing_*` nor `shipping_*`, that method falls
   straight through: `$_POST` (empty on a GET reload) → the `woocommerce_checkout_get_value`
   filter (**not hooked anywhere in `woodev/`**) → a `WC_Customer` getter or meta (never
   written) → the `default_checkout_{key}` filter (**not hooked**). Verified against
   WooCommerce 11.0.0, `class-wc-checkout.php:1480-1523`.
3. Nothing in `woodev/` writes the point to `WC()->session`. Session use across the whole
   framework is limited to `chosen_shipping_methods`, `chosen_payment_method` and the payment
   gateways' `held_order_received_text`.
4. There is no client-side storage at all — zero `localStorage`/`sessionStorage` occurrences in
   the framework's JS, and none in the fixtures.

The only persistence that exists happens at order creation: §8's `Checkout_Handler::save()`
writes the id to order meta and `Pickup_Handler::handle_checkout_order_processed()` writes the
full point through the plugin's key map. Both read `$_POST` on the checkout POST.

**`restoreSelection()` is correct and has nothing to read.** The fix belongs on the server.

### 1.1 The cost is worse than the card states

With `replaceAddress` enabled (the default), `applyAddressReplacement()` writes the selected
point's address, locality and postal code into the `billing_*`/`shipping_*` fields
(`pickup-mount.js:1019-1025`). Those are native WooCommerce fields and WooCommerce persists
them itself. So after a reload the customer sees **the pickup point's address still in place**
while the hidden id field is empty — the page looks like a point is chosen, and the A2 gate
blocks the order anyway.

### 1.2 The restore UI already exists and is dead

`syncTriggerLabel()` (`pickup-mount.js:692-701`) swaps the trigger button to "Выбрать другой
пункт выдачи" whenever the field holds a value, and its docblock names the case explicitly:
*"Called at mount time (a checkout reload after an earlier selection)"*. The label logic was
written expecting this persist. No new UI is needed.

## 2. Why `WC()->session` and not a dual store (#178)

#178 asked whether `WC()->session` behaves differently for guests and logged-in customers,
and blocked this card on the answer. Measured against WooCommerce 11.0.0:

| | Guest | Logged in |
|---|---|---|
| Session identity | `wc_rand_hash( 't_' )` in a cookie | user id (`generate_customer_id():460`) |
| Expiry | 2 days | 1 week (`set_session_expiration():403`) |
| Write persists when… | only once the WC session cookie exists | always — `is_user_logged_in()` short-circuits `has_session()` |
| Guest → login | data is **cloned** into the user session (`clone_session_data():204-242`) | — |

The one real asymmetry is the third row. `save_data()` writes only
`if ( $this->_dirty && $this->has_session() )`, and `has_session()` is
`isset( $_COOKIE[...] ) || $this->_has_cookie || is_user_logged_in()`. WooCommerce sets the
guest cookie on `woocommerce_set_cart_cookies` (a non-empty cart) or the `order-pay` endpoint.
So a guest with an empty cart loses every `WC()->session->set()` silently at shutdown, while a
logged-in developer never sees it.

**That condition is unreachable for the pickup point** — the picker only exists on the checkout,
which requires a non-empty cart. It IS reachable for a locality preference chosen on a catalog
or product page, which is what `WC_Edostavka_Customer_Location_Data` exists for: its constructor
loads the user-meta store when `get_id() > 0` and only falls back to the session store
otherwise, mirroring `WC_Customer`'s own dual store.

The dual store buys durability past session expiry and logout, and portability across devices.
A chosen pickup point wants none of that — it is a decision inside one cart, and restoring one
chosen months ago for a different order would be a worse bug than the one being fixed. Plain
`WC()->session` is correct here, and it matches the production contract already shipped in the
Yandex plugin (`chosen_yandex_pickup_point`).

## 3. The contract split

The framework owns the mechanism. **Every value that carries meaning comes from the domain.**

### 3.1 `Pickup_Selection` — framework mechanism

Reads, writes and clears a three-level map in `WC()->session`. It never interprets its keys;
to it they are two opaque strings.

```
session[ <plugin session key> ] = [
    <locality key> => [
        <type code> => <point id>,
    ],
]
```

This restores the primitive deleted in SP-5 T4, which is why the gotcha
`session-key-vs-order-meta-prefix` still prescribes a class that does not exist (#143).

### 3.2 `Selection_Scope` — the plugin seam

A new interface in `woodev/shipping-method/pickup/interface-selection-scope.php`, following the
house style of `Point_Source` (namespace `Woodev\Framework\Shipping\Pickup`, `interface_exists`
guard).

| Method | Question it answers | Called |
|---|---|---|
| `session_key(): string` | under which session key this plugin's map lives | always |
| `locality_for_point( Pickup_Point $point ): string` | which locality **this point** belongs to | on write |
| `current_locality(): string` | which locality the order is being placed to **now** | on restore |
| `type_for_method( string $method_id ): ?string` | which `type` code the chosen shipping method implies — a code, `TYPE_ANY`, or `null` for "no restore" (§5) | on restore |

**The locality key is a domain primary key, not a place name.** Carriers do not agree on what a
locality is, and all three of the operator's production plugins disagree with each other:

| Plugin | Locality identity | Where |
|---|---|---|
| `woodev-russian-post` | FIAS GUID of the settlement | `includes/classes/ajax.php:180` (`'fias' => $normalize_address->get_place_guid()`) |
| `woocommerce-edostavka` | `city_id` in СДЭК's own database | `includes/functions.php:896` |
| `woocommerce-yandex-delivery` | `geo_id` in Яндекс Доставка's own database | — |

A customer also writes one city several ways ("Санкт-Петербург" / "Санкт Петербург" / "Питер").
The framework therefore never derives the key, never normalizes it and never compares it to
anything but another string from the same scope.

The two reference plugins also differ in SHAPE, and the difference is exactly the defect being
fixed. `woocommerce-edostavka` keeps a map keyed `[city_id][type]`, so returning to a previously
used locality restores that locality's point. `woodev-russian-post` keeps a **single slot**
(`Customer_Delivery_Point_Data`) and guards it by comparing the stored `fias` against the
current normalized address's `place_guid` (`includes/classes/checkout.php:318` and `:371`) — so
switching locality overwrites the slot and switching back loses the point. The map generalises
both: a Почта-shaped plugin gets the СДЭК behaviour by returning the FIAS GUID from
`current_locality()` / `locality_for_point()`, without rewriting its own store.

The two locality methods are asymmetric on purpose, and that asymmetry is taken from the
reference implementation: `woocommerce-edostavka` writes under `$data['location']['city_code']`
(off the point) and reads with `$customer_handler->get_city_code()` (off checkout state) —
`functions.php:896` and `:919`.

The type dimension keys on `Pickup_Point::$type['code']`, which is already a required field
supplied by the plugin's own payload. The framework enumerates no types.

**No scope supplied → no persistence.** Same shape as
`handle_checkout_order_processed()`, which skips entirely when the plugin has not wired an order
handler and key map. The framework must not coin a session key of its own: both
`chosen_yandex_pickup_point` and СДЭК's `chosen_delivery_point` are release-blocking
installed-site data contracts owned by their plugins.

## 4. Write path

In `Pickup_Handler`, on the confirmed `/select` round trip — after the server has allowed the
point, never before, so D-1 ("nothing is applied until the server answers") holds and a refusal
stores nothing.

```
map[ scope->locality_for_point( $point ) ][ $point->get_type()['code'] ] = $point->get_id()
```

Not in `Pickup_Controller`: its docblock records that it reads no WooCommerce global at all, and
that is what lets its dispatch core be unit-tested without WooCommerce loaded. The REST-context
bridge already exists and is already used by `current_cart_weight_grams()` —
`wc_load_cart_available()` / `load_wc_cart()`, both `protected` test seams.

Only the id is stored. The full point arrives with the listing when the picker opens, and the
address is already persisted by WooCommerce in the native address fields; a second copy in the
session would be a second source of truth that goes stale.

## 5. Restore path

`Pickup_Handler` hooks `woocommerce_checkout_get_value` and answers only for its own field id:

1. the chosen shipping method is not one this field's selection applies to → return nothing, and
   leave the map untouched, so the selection comes back when the customer returns to pickup;
2. `$type = scope->type_for_method( normalize_method_id( chosen ) )`;
3. `$type` is a string → `map[ scope->current_locality() ][ $type ]`;
4. `$type` is `Selection_Scope::TYPE_ANY` → the most recently written entry for that locality
   (§6 pins what "most recently" means); `$type` is `null` → nothing.

**Step 1 has no separate gate — `type_for_method()` IS the gate.** The pickup method ids are
already declared twice by the plugin (`Pickup_Field::create( $id, $pickup_method_ids )` and
`Checkout_Handler::set_requires_pickup_methods()`); `Pickup_Handler` knows neither, and
`Checkout_Handler::chosen_method_matches()` is `private static`. Rather than wire a third copy
of that list or couple the two handlers, the one seam answers the whole question:

| Return | Meaning | Restore |
|---|---|---|
| a `type` code | this method wants a point of that type | `map[ locality ][ code ]` |
| `Selection_Scope::TYPE_ANY` | a pickup method that is not type-specific | most recently written entry for that locality |
| `null` | this method gets no restored selection (a courier method, or the plugin opting out) | nothing |

`TYPE_ANY` is what preserves the fallback behaviour the operator asked for while keeping `null`
unambiguous. A plugin whose carrier has exactly one point type can equally just return that
code — types are domain values, so it always has one to name.

Note that this gate is *not* a regression introduced here: today a customer who picks a point
and then switches to a courier method without reloading already posts the stale id, and
`handle_checkout_process()` already re-checks it regardless of the chosen method. Whether that
is itself a defect is out of scope for this card; the restore path must simply not make it
worse.

`get_value()` checks `$_POST` before running the filter, so a failed checkout submit still
re-renders what the customer posted rather than what the session holds.

**No JavaScript changes.** `alreadySelected` (`pickup-mount.js:2847`) and `syncTriggerLabel()`
both read the field, and the restore chain downstream of them already works (§1.2).

## 6. Invalidation, clearing and bounds

Agreed behaviour:

| Event | Effect |
|---|---|
| A new point chosen for the same (locality, type) | overwrites that entry |
| Shipping method changed away from pickup | **nothing is cleared** — the entry is simply not applied, and returns when the customer comes back |
| Cart contents or weight changed | **nothing is cleared** — `handle_checkout_process()` already re-fetches the point and re-runs `Constraint_Checker` on submit, and explains the refusal |
| Locality changed | nothing is cleared — every locality remembers its own |
| Order created | the whole map is cleared (the reference does the same, `wc_edostavka_reset_delivery_point_data()`) |
| Session expiry | clears the rest |

Rejected: clearing on a cart change (the customer loses the choice from adding anything at all,
including items that cannot affect the verdict — that is #176 again under another name), and
re-validating the point against the carrier on restore (one carrier request per checkout load,
against the merchant's quota — the exact cost class rejected in s58,
`per-viewport-cache-is-unbounded-by-construction`).

**The key space is unbounded by construction** — localities × types accrue for as long as the
customer keeps switching. Same class as the s58 cache defect. The map carries a cap with
oldest-first eviction, exposed through a filter because point density and how many localities a
real customer visits is domain knowledge (the same reasoning that produced
`woodev_pickup_max_accumulated_points` in #234). The currently-applicable entry is never
evicted.

**Recency must be written down, not inferred from array order.** Both the `$type === null`
fallback (§5 step 4) and the eviction order depend on knowing which entry was written last, and
a PHP array does not carry that: re-assigning an existing key keeps its **original** position,
so `$map[ $loc ][ $type ] = $id` on a point the customer already chose leaves it looking oldest.
This is the PHP-side twin of the s59 gotcha `plain-object-is-not-an-insertion-ordered-map`, and
it fails the same way — quietly, at the right total count. Each entry therefore stores an
explicit monotonic sequence number alongside the id, and both the fallback and the eviction sort
on it. A test that only ever writes distinct keys will not catch a regression here: the
regression test must **overwrite an existing entry** and then assert the order.

## 7. Rejected alternatives

| Alternative | Why not |
|---|---|
| A dual session+user-meta store like `WC_Edostavka_Customer_Location_Data` | Solves durability past logout/expiry and device portability. A pickup point is a per-cart decision; restoring one from a previous order is worse than losing it. §2. |
| `WC_Customer` meta (WooCommerce would restore it for free via `get_value()`) | For a logged-in customer that writes into permanent user meta; for guests it depends on `meta_data` being in `WC_Customer_Data_Store_Session::$session_keys`, which is true on WC 11 but unverified at the project's WC 7.0 minimum. |
| `localStorage` | The value is a server-authoritative selection; the server must be able to read it (§8 save, order meta, the re-check). Client storage cannot be trusted for that and adds a second source of truth. |
| A framework-coined session key | Both known plugins already own theirs, and both are release-blocking installed-site contracts. Gotcha `session-key-vs-order-meta-prefix`. |
| Keying the locality on the city field's value | Our own `applyAddressReplacement()` overwrites that field with the selected point's locality, so the key would change under us; and the field's `value` is not required to be human-readable — a plugin may legitimately put a carrier code or FIAS id there (`buildProviderConfig()`'s own docblock; the fixture ships `billing_state` `value: '77'`). |
| Storing the full point in the session | A second, staleable copy of data the listing already returns and WooCommerce already persists. |

## 8. Verification

- **Unit** — map read/write/overwrite/eviction; no scope → zero writes; the filter answers only
  for its own field id; non-pickup method → empty; `type_for_method() === null` → the
  most-recent fallback; a refused selection stores nothing. Any number asserted (the cap, the
  eviction count) must be mutated against a neighbouring value before it is trusted — gotcha
  `advancing-the-whole-interval-does-not-pin-a-delay`.
- **Integration** — the value round-trips through a real `WC_Checkout::get_value()`.
- **Rig** — Москва → СПб → Москва with a different point in each, and one locality holding both
  a ПВЗ and a постамат while the shipping method switches between them. Measured by probe, not
  by screenshot timing (s65 handoff trap 9): read the field's `.value` and listen on
  `document.body`.

## 9. Open items

- `Pickup_Handler`'s constructor is already an over-long positional list (#170). The scope
  arrives as one nullable argument; if #170 is taken first, it should land inside whatever
  shape that card produces.
- `type_for_method()` receives the method id **after** `Checkout_Handler::normalize_method_id()`
  — the instance suffix is stripped, so a plugin never has to parse `method:instance` itself.

## Related

- Cards: #176 (this), #178 (question answered here), #143 (gotcha becomes accurate again),
  #170 (constructor shape), #175/#181 map-state persistence (out of scope)
- `docs-internal/gotchas/session-key-vs-order-meta-prefix.md`
- `docs-internal/gotchas/per-viewport-cache-is-unbounded-by-construction.md`
- `docs-internal/specs/2026-08-06-sp5-pickup-selection-mechanism-design.md`
- Reference implementations: `plugins-reference/woocommerce-edostavka/includes/functions.php`
  (`wc_edostavka_set_delivery_point_data()`, `wc_edostavka_get_delivery_point_data()`,
  `wc_edostavka_reset_delivery_point_data()`),
  `plugins-reference/woocommerce-yandex-delivery/includes/functions.php:316`
