# `wc_admin_register_page()` ignores `order`, and its neighbours declare no `position`

**Discovered:** s132 (12.09.2026), placing «Заказы доставки» after «Orders» for #834.

## The trap

Placing a page in WooCommerce's submenu looks like a one-key change. Both plausible keys are dead
ends, and each fails differently.

**`order` is a decoy.** Their own docblock advertises it —
`@type int order Navigation item order` — and their Homescreen passes `'order' => 0`. But
`PageController::register_page()` never reads it: it is absent from that method's `$defaults`, and
what reaches `add_submenu_page()` is `position`. Copying `order` from their source therefore
changes nothing, silently.

**`position` works but is not stable.** It is an INDEX into `$submenu['woocommerce']`, and
WooCommerce's own entries — `Orders`, `Customers`, `Coupons`, `Reports`, `Settings`, `Status`,
`Extensions` — pass **no position at all** (`includes/admin/class-wc-admin-menus.php`): every one
of them is appended, so their indices are an accident of registration order. A number derived from
today's arrangement holds until they add or reorder one entry, and then moves your page somewhere
arbitrary — with no error.

## ✅ Correct

Resolve the place by the NEIGHBOUR'S SLUG, which they cannot renumber, on a late `admin_menu`:

```php
add_action( 'admin_menu', [ $this, 'move_menu_item_after_orders' ], 99 );

public function move_menu_item_after_orders(): void {
    global $submenu;

    if ( empty( $submenu['woocommerce'] ) || ! is_array( $submenu['woocommerce'] ) ) {
        return;
    }
    // pull our entry out by its own slug, re-insert it directly after the orders entry,
    // and write back a re-indexed array
}
```

⚠ **Accept both spellings of that neighbour.** HPOS gives `wc-orders`; the legacy post store gives
`edit.php?post_type=shop_order`. The rig runs HPOS, so the legacy branch has no witness there and
needs a unit test.

⚠ **Fail soft on every arm** — no menu, no entry of ours, no «Orders» found — and leave the submenu
*exactly* as found. Assert that unchangedness in tests rather than merely asserting no crash: a
menu in the wrong order is a blemish, a menu this method mangled is a support ticket.

## How to check it

The rendered submenu is the only honest witness, because the indices are not knowable from source:

```js
[ ...document.querySelectorAll( '#adminmenu .wp-submenu' ) ]
    .map( ( s ) => [ ...s.querySelectorAll( 'li a' ) ].map( ( a ) => a.textContent.trim() ) )
    .find( ( l ) => l.some( ( t ) => /Заказы доставки/.test( t ) ) );
```

## Related

- [a-dom-read-cannot-answer-a-question-about-server-rendered-markup](a-dom-read-cannot-answer-a-question-about-server-rendered-markup.md) — the other `wc_admin_register_page()` trap, from the same card
- [a-hook-registered-from-a-per-plugin-object-fires-once-per-plugin](a-hook-registered-from-a-per-plugin-object-fires-once-per-plugin.md) — the other way admin wiring misfires with several plugins present
