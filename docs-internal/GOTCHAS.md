# Gotchas — Woodev Plugin Framework

> **Topic map.** 323 atomic gotchas across 31 topics. At session start read THIS file, then open
> the topic indexes your task touches — each holds one line per gotcha, linking the detail file.
> `[tooling/*]`, `[testing/*]` and `[rig/*]` apply to almost every session.
> **Adding one:** create `gotchas/{slug}.md`, then add ONE line to the right `gotcha-index/{topic}.md`
> (format: `DOCS-SCHEMA.md`). Dedup first. Split out of a single 95 KB index in s135 (#554).

## Topics

| Topic | Entries | Covers |
|---|---|---|
| [`naming/*`](gotcha-index/naming.md) | 1 | Identifier conventions |
| [`php/*`](gotcha-index/php.md) | 18 | PHP / WordPress patterns |
| [`settings-api/*`](gotcha-index/settings-api.md) | 7 | Settings API |
| [`deprecation/*`](gotcha-index/deprecation.md) | 2 | Deprecation cycle |
| [`bootstrap/*`](gotcha-index/bootstrap.md) | 5 | Multi-version loading |
| [`compat/*`](gotcha-index/compat.md) | 2 | Backward compatibility, HPOS |
| [`lifecycle/*`](gotcha-index/lifecycle.md) | 1 | Install/upgrade routines |
| [`woocommerce/states`](gotcha-index/woocommerce-states.md) | 2 | The `woocommerce_states` table |
| [`woocommerce/*`](gotcha-index/woocommerce.md) | 17 | WooCommerce-specific · WooCommerce-specific (session) |
| [`framework/*`](gotcha-index/framework.md) | 6 | Framework internals |
| [`framework/contracts`](gotcha-index/framework-contracts.md) | 3 | What the framework guarantees to its consumers |
| [`shipping/location`](gotcha-index/shipping-location.md) | 12 | Location provider layer |
| [`rig/*`](gotcha-index/rig.md) | 15 | Local verification rig |
| [`framework/wiring`](gotcha-index/framework-wiring.md) | 4 | Responsibilities that moved |
| [`testing/*`](gotcha-index/testing.md) | 45 | Testing patterns |
| [`js/*`](gotcha-index/js.md) | 9 | JavaScript language traps |
| [`testing/js`](gotcha-index/testing-js.md) | 8 | JavaScript testing pitfalls |
| [`api/*`](gotcha-index/api.md) | 4 | API layer |
| [`licensing/*`](gotcha-index/licensing.md) | 7 | License/EDD store |
| [`build/*`](gotcha-index/build.md) | 17 | Build/CI/release |
| [`admin-ui/*`](gotcha-index/admin-ui.md) | 8 | Admin pages / React UI |
| [`admin-ui/modal`](gotcha-index/admin-ui-modal.md) | 3 | Framework modal shell |
| [`admin-ui/react-state`](gotcha-index/admin-ui-react-state.md) | 2 | React component state |
| [`box-packer/*`](gotcha-index/box-packer.md) | 2 | Box-packer algorithm (S2) |
| [`shipping/checkout`](gotcha-index/shipping-checkout.md) | 17 | Checkout field layer (§8) |
| [`shipping/pickup`](gotcha-index/shipping-pickup.md) | 26 | Pickup point picker / ymaps |
| [`shipping/*`](gotcha-index/shipping.md) | 5 | Shipping module (S1) |
| [`perf/*`](gotcha-index/perf.md) | 1 | Payload size and wire cost |
| [`i18n/*`](gotcha-index/i18n.md) | 8 | Localization |
| [`autodev/*`](gotcha-index/autodev.md) | 5 | Adversarial dev loop tooling |
| [`tooling/*`](gotcha-index/tooling.md) | 61 | Dev tooling, codex critic |

## Archive (resolved gotchas)
<!-- Resolved gotchas move here; keep for 2 sessions then remove -->

- [bootstrap/resolver-bootstrap-coupling] **RESOLVED.** `Framework_Resolver` no longer references
  `Woodev_Plugin_Bootstrap::instance()` at all — the notice renderers are injected
  (`$update_notice_renderer`, `$deactivation_notice_renderer`). Verified against the source at the
  s75 docs cleanup; original finding: `audit-2026-06-01.md` §H2.
