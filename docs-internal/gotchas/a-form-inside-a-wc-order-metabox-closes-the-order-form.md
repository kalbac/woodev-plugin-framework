# Gotcha: [woocommerce/order-metabox] — a `<form>` inside an order-edit metabox closes WooCommerce's order form, and the order stops saving
> Tags: woocommerce, hpos, metabox, html | Session: s145

## What happens
On the WooCommerce order edit screen (HPOS `wc-orders&action=edit`, and the CPT `post.php` screen) the
order status could not be changed: «Обновить» reloaded the page with the old status. Changing it through
`wp eval` worked. Every main-column field was affected, not just the status. The trap sat in `main` from
07.06.2026 (`47b5e1c8`) until #1012 (PR #1013); nobody saved an order while the carrier metabox showed
action buttons until the operator did, on the rig, 01.10.2026.

## Root cause
Every metabox renders INSIDE WooCommerce's order `<form>`. The carrier metabox wrapped each action button in
its own `<form action="admin-post.php">`. HTML forbids nested forms: the browser drops the inner opening
tag, and the inner `</form>` closes the OUTER order form. The side column (`#postbox-container-1`, where the
metabox lives) comes BEFORE the main column (`#postbox-container-2`, where `#order_status` is) in the DOM,
so the status select and everything after it ended up outside the form and were never submitted.

How it was found: fetch the edit screen HTML as admin and list the forms — `grep -n -o '<form[^>]*>\|</form>'`
showed the metabox's `</form>` at line 892 and `id="order_status"` at 951, after it.

## Fix
❌ wrong — any `<form>` in a metabox view:

```php
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( $nonce_action ); ?>
	<button type="submit">…</button>
</form>
```

✅ correct — a `type="button"` carrying its payload as data attributes; a small script builds a detached form
on `document.body` (outside the order form) at click time and submits it:

```php
<button type="button" data-post-url="…admin-post.php" data-post-action="…" data-order-id="…"
	data-nonce="<?php echo esc_attr( wp_create_nonce( $nonce_action ) ); ?>">…</button>
```

A test that parses the metabox HTML must assert it contains no `<form`; an integration test that renders the
metabox inside a form wrapper must find a sibling field still inside the form.

## Related
- [html-admin-order-metabox.php](../../woodev/shipping-method/admin/views/html-admin-order-metabox.php) — the view, now form-free
- [the-rig-runs-en-us-so-no-translation-ever-renders-there.md](the-rig-runs-en-us-so-no-translation-ever-renders-there.md) — another trap only a real save/render on the rig catches
