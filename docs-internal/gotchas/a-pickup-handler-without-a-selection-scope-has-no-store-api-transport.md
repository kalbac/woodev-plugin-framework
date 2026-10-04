# A `Pickup_Handler` built without a `Selection_Scope` has no Store API transport

**Namespace:** `[shipping/pickup]`
**Discovered:** 2026-10-04 (s151, #1089)

## The trap

The `Selection_Scope` (constructor argument 13) reads as optional — «no scope → no persistence»,
and the classic checkout really does work without one: the button shows, a point is chosen, the
order is validated; the only thing missing is the remembered point.

On the block checkout the scope is NOT optional. The whole Store API pickup transport (SP-11 D-3)
is built on it:

- `Pickup_Handler::owns_store_api_rate()` asks `selection_pair_for_method()`, which returns `null`
  without a scope — so the handler never owns ANY rate;
- `Store_Api_Pickup::owner()` therefore answers `owner: null` for the carrier's own pickup rate,
  and the Checkout block's button — which shows from that answer alone — renders nothing;
- `point_matches_pair()` and `selection()` refuse too, so a confirmation could not be kept anyway.

Meanwhile the checkout field layer still requires the point for that method. The shopper gets an
order that demands a pickup point and no control to choose one. Nothing logs, nothing warns: the
cart's `extensions['woodev-shipping']` simply says `{"pickup":{…:null},"owner":null}`.

Found on the rig: the second fixture carrier (`woodev-realistic-shipping-plugin`) was built with
`null` there, and its rate showed no button while the first carrier's did.

## Wrong

```php
new Pickup_Handler( $plugin_id, $field_id, $source, $map, $location, null, null, [], '#06aedd', '', true, false, null, $this );
//                                                                                                        ^^^^ no scope
```

## Correct

Give every carrier that has a pickup method a scope whose `type_for_method()` names that method. A
plugin in the Location Provider layer extends `Provider_Selection_Scope` and answers
`locality_for_point()` from the same layer `current_locality()` reads:

```php
class My_Selection_Scope extends \Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope {
	public function session_key(): string { return 'my_plugin_pickup_selection'; }
	public function locality_for_point( Pickup_Point $point ): string { return $this->current_locality(); }
	public function type_for_method( string $method_id ): ?string {
		return 'my_pickup_method' === $method_id ? Selection_Scope::TYPE_ANY : null;
	}
}
```

## What the framework does about it now (#1100)

No default scope: a scope owns the session key (never coined by the framework), the locality meaning
and the method→type map, and a handler knows only its plugin id and field id — not its carrier's
method ids. So the fault is made loud instead of repaired:

- `Pickup_Handler::register()` calls `_doing_it_wrong()` once per plugin id and hooks an `admin_notices`
  error naming the plugin (`render_missing_selection_scope_notice()`);
- `Store_Api_Pickup::validate_order()` refuses the order with `woodev_pickup_unavailable` when its shipping
  method is a framework pickup method that NO handler owns while at least one registered handler has no
  scope — the buyer gets a message instead of a silent dead end.

A classic-only plugin that never wired a scope gets the notice too; wire a scope (below) to clear it.

## How to see it

```php
$handler->owns_store_api_rate( 'my_pickup_method:5' ); // must be true
```

or, in the browser, `wp.data.select( 'wc/store/cart' ).getCartData().extensions['woodev-shipping'].owner`
with the pickup rate selected — it must name the plugin and the field.

## Related

- [is-checkout-is-false-on-a-page-that-only-carries-the-checkout-block](is-checkout-is-false-on-a-page-that-only-carries-the-checkout-block.md)
- [a-pickup-handler-built-without-its-plugin-silently-addresses-by-name](a-pickup-handler-built-without-its-plugin-silently-addresses-by-name.md)
- [built-on-both-sides-with-no-caller-in-the-middle](built-on-both-sides-with-no-caller-in-the-middle.md)
