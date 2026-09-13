# Gotcha index — [admin-ui/*] Admin pages / React UI

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [admin-ui/tables] **A formatted price is TWO words to the browser — `wc_price()` separates thousands with a SPACE, so a narrow column breaks «3 980,00 ₽» across lines. Hid until the fixture had real totals.** → [a-formatted-price-is-two-words-and-wraps](../gotchas/a-formatted-price-is-two-words-and-wraps.md) (s133)
- [admin-ui/vendor-css] **Grepping a vendor stylesheet is an INCOMPLETE measurement — the `.order-status` rule copied that way missed `white-space: nowrap` and our label wrapped. Read `getComputedStyle()` off their real element.** → [a-grep-of-a-vendor-stylesheet-is-an-incomplete-measurement](../gotchas/a-grep-of-a-vendor-stylesheet-is-an-incomplete-measurement.md) (s133)
- [admin-ui/calendar] **`react-dates` renders `.CalendarMonth` at a FIXED 300px inside a 320px popover, so `padding: 16px` on the wrapper overflows it and the grid reads as slid right.** → [react-dates-renders-a-fixed-300px-month-so-a-padded-wrapper-overflows-it](../gotchas/react-dates-renders-a-fixed-300px-month-so-a-padded-wrapper-overflows-it.md) (s132)
- [admin-ui/notices] **A DELAYED admin notice renders into the HTML with `display:none` and is unhidden by inline jQuery — grepping the markup for its text proves nothing about whether anyone sees it, and the PHP suite cannot tell.** → [a-delayed-admin-notice-renders-hidden-and-may-never-be-revealed](../gotchas/a-delayed-admin-notice-renders-hidden-and-may-never-be-revealed.md) (s105)
- [admin-ui/license-page] **the v2 license page only enqueues the React bundle CSS — server-rendered sections need their styles in style.scss.** → [license-page-css-bundle-only](../gotchas/license-page-css-bundle-only.md) (s14)
- [admin-ui/esc-url-raw-for-js] **Use `esc_url_raw` (not `esc_url`) for URLs handed to JS / REST.** → [esc-url-raw-for-js-consumed-urls](../gotchas/esc-url-raw-for-js-consumed-urls.md) (s20)
- [admin-ui/wp-nonce-url-esc-html] **`wp_nonce_url()` HTML-encodes `&` → breaks a URL consumed by JS/JSON.** → [wp-nonce-url-esc-html-breaks-js-urls](../gotchas/wp-nonce-url-esc-html-breaks-js-urls.md) (s24)
- [admin-ui/filters] **WooCommerce gives `FilterPicker` a FIXED 430px, so two of them eat an 860px row exactly and a third control wraps. Our stylesheet sets no width — the cause is invisible from it. Bound it inside your own container.** → [woocommerce-gives-its-filter-picker-a-fixed-430px](../gotchas/woocommerce-gives-its-filter-picker-a-fixed-430px.md) (s128)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
