# Installed-site contract strings are NOT mechanically derivable — the plugin must supply them

**Namespace:** `[shipping/contracts]`
**Discovered:** 2026-06-06 (autodev loop — recurred across order-handler, ajax-base, admin-bootstrap)

## The trap

When the framework needs an installed-site contract string (order-meta key, AJAX action name,
admin page slug, REST namespace, log source, cron hook), it is tempting to **derive** it from the
plugin id by a naming convention (`{plugin_id}_orders`, `wc-{dasherize(plugin_id)}-orders`,
`{plugin_id}_get_pickup_points`). This is wrong: the real strings on installed sites do **not**
follow one convention, and a derived value that differs by even one character orphans live data /
breaks a bookmarked URL / 404s a live AJAX endpoint.

Proof from the two S1 pilots (`.autodev/INVARIANTS.md`):

| Contract | edostavka | yandex | Derivation would give |
|----------|-----------|--------|-----------------------|
| Admin page slug | `wc_edostavka_orders` (underscores) | `wc-yandex-orders` (dashes) | `wc-edostavka-orders` ❌ both can't be one rule |
| AJAX action | `edostavka_get_deliverypoints` family | `get_yandex_delivery_shipment_points` family | `yandex_delivery_get_pickup_points` ❌ |
| Order-meta prefix | `_wc_edostavka_` | `_yandex_delivery_` | n/a — different shapes |

edostavka uses underscores, yandex uses dashes; the AJAX verbs differ in word order and wording;
the method ids (`yandex_delivery_express`) are not even the base of the action names
(`yandex_delivery`). No convention reproduces all of them.

## Correct pattern

The PLUGIN SUPPLIES each contract string as an explicit value (a constructor arg or a
logical-name → real-string MAP); the framework hardcodes and derives NONE. This is the same fix
applied to `Shipping_Order_Handler` (spec §4.3: plugin supplies the logical-field → real-meta-key
map). Apply it for every installed-site string a framework class touches.

## Why it matters / who else is at risk

These are all release-blocking "never break" contracts (CLAUDE.md). The pattern has already bitten
three S1 tasks (order-handler, ajax-base, admin-bootstrap). Pending tasks that derive the same
kinds of strings should be audited for it BEFORE the worker runs: `s1-p4-rest-pickup`,
`s1-p4-rest-warehouses`, `s1-p4-admin-order` (REST namespace / admin slug derivation), and any
task touching `ajax_actions`, `admin_page_slugs`, `rest`, `log_source`, or `cron` zones.

## The adversarial critic catches this well

The GPT-5.5 critic flagged all three with high confidence (0.96–0.98) by cross-referencing the
derived value against the INVARIANTS exact_strings. This is the critic working as designed — do
NOT recalibrate it away from these findings (contrast: the critic's *over-flagging* of new
additive hooks / unwired classes — see [[autodev-critic-overflag]] — which IS miscalibration).

## Related
- `.autodev/INVARIANTS.md` — `ajax_actions`, `admin_page_slugs`, `rest`, `log_source`, `cron`, `order_session_meta` exact_strings
- [[session-key-vs-order-meta-prefix]] — the order-handler instance of the same root
- [[autodev-critic-overflag]] — the critic's *real* miscalibration (distinct from this, which it gets right)
