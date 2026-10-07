# Gotcha: [woocommerce/admin-js] — A searchable select in a shipping-method MODAL stays a plain `<select>`
> Tags: woocommerce/admin, admin-ui | Session: s158

## What happens

A carrier method's instance setting rendered as WooCommerce's AJAX `wc-product-search` /
`wc-enhanced-select` works on the instance PAGE but is an unusable plain select inside the
shipping-zone modal.

## Root cause

`wc-enhanced-select.js` initialises those controls on page load and on the `wc-enhanced-select-init`
event only. The zone screen inserts the method form later through `WCBackboneModal`, and its
`wc_backbone_modal_loaded` handler does not trigger that event (WC source cited in the s158 critic
report `c7b`). A method opens in the modal only when it supports `instance-settings-modal`; Woodev
carriers currently open as a page, so the modal path is latent.

## Fix

✅ The framework adds, once, on `page=wc-settings&tab=shipping`, an inline script:
`jQuery( document.body ).on( 'wc_backbone_modal_loaded', () => jQuery( document.body ).trigger( 'wc-enhanced-select-init' ) );`
(`Shipping_Plugin::enqueue_packing_settings_script()`). Do not write per-plugin JS for it.

## Related

- [../gotcha-index/woocommerce.md](../gotcha-index/woocommerce.md)
