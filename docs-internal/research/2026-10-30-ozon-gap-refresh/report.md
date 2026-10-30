
# Ozon Delivery gap refresh against Framework 2.0.16

Read-only source review, 2026-10-30. Framework worktree HEAD `b07fe50b`; `woodev/` and `src/` have no diff against `v2.0.16`, and `woodev/class-plugin.php:20` declares 2.0.16. No repository edits, fetch/pull, commits, pushes, issue comments or board mutations were made.

Pointer roots: **F** = `/Users/maksimmartirosov/Projects/woodev-plugin-framework/.orca/worktrees/woodev-plugin-framework/research-ozon-gap`; **O** = `/Users/maksimmartirosov/Projects/woodev-ozon-delivery` (`acc9e9f`); **C** = `/Users/maksimmartirosov/Projects/woocommerce-edostavka` (`8e50442`); **Y** = `/Users/maksimmartirosov/Projects/woocommerce-yandex-delivery` (`fe3e96c`); **R** = `/Users/maksimmartirosov/Projects/woodev-russian-post` (`cb37923`). All pointers below are relative to these roots.

The SP-5 wait is obsolete: the rate, document, status, orders-page and Blocks subsystems now exist. The substantial Ozon work is its posting/return model and recipient-dependent quoting; that does not imply that a generic multi-shipment framework must precede all plugin development.

## 1. G-1…G-8 verdicts

### G-1 — Returns: **changed shape**

There is still no separate reverse-logistics entity with return number, placement, history and expiry, but the framework now offers refusal plus custom row actions and preview fields, so Ozon can own that model without forking export. The default successful cancel clears the carrier ID and writes an authoritative `cancelled` marker: Ozon must preserve posting/return identities separately and must not enter this success path merely because cancellation was accepted. CDEK implements paid refusal itself; Yandex maps return progress but does not supply Ozon's separate return entity.

Evidence: F/woodev/shipping-method/order/abstract-shipment-handler.php:790–845, :986–1035; F/woodev/shipping-method/order/class-shipment-cancellation.php:24–35; F/woodev/shipping-method/admin/orders/class-order-actions.php:279–304; F/woodev/shipping-method/admin/orders/class-order-row-builder.php:221–238; C/includes/orders/class-cdek-shipment-handler.php:274–292; Y/includes/tracking/class-yandex-delivery-status.php:60–69.

### G-2 — One order, many postings: **still open**

`export()` now returns `Action_Result`, but its carrier ID, extraction, stored-ID guard, tracking column, status and action gates still describe one aggregate shipment; arrays must not be put into those scalar slots. CDEK supports multiple `packages[]` and Yandex multiple `places[]` under one remote order/request, which solves packing but does not solve independent posting statuses/cancellations/labels. Ozon can store scalar `order_number` as the aggregate identity and retain its complete posting collection separately, provided it defines safe aggregate status/actions and exposes the individual postings through plugin extensions.

Evidence: F/woodev/shipping-method/order/abstract-shipment-handler.php:224–229, :327–357, :625–626, :1071; F/woodev/shipping-method/order/class-action-result.php:73–74, :174–175; F/woodev/shipping-method/admin/orders/class-order-row-builder.php:745–765; C/includes/orders/class-cdek-order-request.php:328–335; Y/includes/api/class-yandex-offer-body-builder.php:57–60. R/includes/order/class-russian-post-order-export.php:277–289 likewise constructs one postal shipment, not independently tracked child shipments.

### G-3 — Async approval/cancellation: **changed shape**

There is no generic approval-pending state or approval resolver, but export is now an ID/lock/reconciliation/retry lifecycle rather than the August diagram; documents have a real `pending` result and HTTP 202 polling. CDEK already retains the created UUID and runs bounded Action Scheduler verification under the export lock, distinguishing accepted, still pending and refused: Ozon can use this pattern for `posting/approve` without treating an accepted creation as an export failure or losing its IDs on `NotEnoughBalance`. Cancellation still needs plugin handling: Yandex deliberately throws on accepted-but-pending cancellation so the base cannot clear its request ID, then lets polling discover completion.

Evidence: F/woodev/shipping-method/order/abstract-shipment-handler.php:155–171, :355–401, :790–845; F/woodev/shipping-method/order/class-document-result.php:36–38; F/woodev/shipping-method/rest-api/class-document-controller.php:378–388; C/includes/orders/class-cdek-shipment-handler.php:441–511, :625–666; Y/includes/api/class-yandex-api-client.php:135–158. Document pending is not shipment approval pending.

### G-4 — Local pickup catalogue: **still open**

The framework has point-source/query/map/selection contracts, but no generic persistent carrier catalogue or cursor-resumable synchronizer; `Abstract_Bulk_Point_Source` is a lookup helper, not such a store. The production carriers own acquisition/caching: Yandex uses locality-scoped signed chunks, CDEK uses per-city/type/filter transients, and Russian Post queries a widget API through its own viewport/tile cache. Ozon should own its catalogue table and scheduled `list → info` sync, expose locality or viewport queries through `Point_Source`, and avoid loading all 45,194 points into a checkout request.

Evidence: F/woodev/shipping-method/pickup/interface-point-source.php:43–111; F/woodev/shipping-method/pickup/abstract-bulk-point-source.php:61–67, :95–104; Y/includes/pickup/class-yandex-pickup-point-source.php:18–21, :86–123, :180–192; C/includes/pickup/class-cdek-pickup-point-source.php:142–147; R/includes/pickup/class-russian-post-widget-point-source.php:17–36, :159–160; O/docs/03-RECON-REPORT.md:47–49 (F-19…F-21).

### G-5 — Out for delivery: **still open**

The nine canonical states are implemented, with an additional `unknown` fallback; `out_for_delivery` remains absent and arbitrary new status-map values resolve to `unknown`. This is not a first-release blocker: preserve Ozon `in_courier_service` and its raw label while mapping it to `in_transit`, as CDEK does for `TAKEN_BY_COURIER`. A distinct canonical filter/email/automation category would require a coordinated framework change, not just an extra plugin constant.

Evidence: F/woodev/shipping-method/order/class-delivery-status.php:40–75, :235–285; C/includes/tracking/class-cdek-delivery-status.php:40; Y/includes/tracking/class-yandex-delivery-status.php:47, :106.

### G-6 — Cookie transport: **closed**

The original claim remains non-blocking, but the instruction “plain framework client works unaided like `wp_remote_post()`” is unsafe: the base defaults to `redirection => 0`. Version 2.0.16 already has opt-in `follow_challenge_redirects()` with body-preserving same-origin replay and per-origin cookie hooks; Ozon must enable it, with persistence optional. The August rig proved the WordPress HTTP defaults, not the unconfigured framework client defaults.

Evidence: F/woodev/api/class-api-base.php:211–271, :2312–2327; O/docs/03-RECON-REPORT.md:33 (F-5); O/docs/01-API-TO-WOOCOMMERCE-MAP.md:19–26.

### G-7 — Coordinates in canonical location: **closed** (storage question)

`Location_Record` has nullable `lat`/`lon`, DaData maps `geo_lat`/`geo_lon`, and the customer store persists the complete normalized records. Ozon must still require a valid address-level coordinate pair for courier delivery: a city-centre coordinate is insufficient, not every provider returns coordinates, and the framework order snapshot saves only the settlement record with `raw` stripped. Persisting the validated courier address/coordinates for later export is therefore plugin work, not a missing coordinate field.

Evidence: F/woodev/shipping-method/location/class-location-record.php:547–559, :709–710; F/woodev/shipping-method/location/providers/class-dadata-provider.php:1273–1289; F/woodev/shipping-method/location/class-customer-location-store.php:1385–1405; F/woodev/shipping-method/checkout/class-checkout-handler.php:4030–4039.

### G-8 — Availability depends on phone: **changed shape**

Availability/rating/field-validation seams now exist on both checkout surfaces, but there is no phone-dependent-rate declaration, phone refresh transport or automatic phone cache identity; field `required` conditions do not implement carrier eligibility. Ozon must normalize and transport the current phone, rerate when it changes, key both WooCommerce's package cache and the framework quote cache on it, debounce/cache `check-client`, and revalidate before placement/export. This is mandatory even if the unknown-phone experiment finds `check-client` lenient: O/docs/wiki/ozon-delivery-api.md:199 explicitly requires `recipient.phone_number` in the quote itself.

Evidence: F/woodev/shipping-method/class-shipping-method.php:690–729, :1249–1259, :2255–2283; F/woodev/shipping-method/checkout/class-checkout-condition.php:35–62; F/woodev/shipping-method/checkout/class-checkout-handler.php:479–487; F/woodev/shipping-method/checkout/class-field.php:377–379. Existing analogous mechanism: F/woodev/shipping-method/class-fee-payments.php:94–97, :243–260, :317–345 handles payment-dependent rates; R/includes/shipping/trait-russian-post-rate-method.php:45–51 opts into it, but no carrier inspected implements Ozon-style phone eligibility.

## 2. New integration requirements visible in the shipped framework

1. **Shared admin wizard cannot quote Ozon from its current payload.** Its rate request omits the typed recipient phone, and the server calculator accepts only lines/destination/location/customer ID/payment/pickup; for a guest, no account lookup can recover that missing input. Supporting Ozon in this shared wizard requires passing the explicit phone through frontend → REST → package/cache (or a plugin-owned alternative), not reading the manager's storefront session. Evidence: F/src/shipping-orders-page/order-wizard/delivery-state.ts:58–74; F/woodev/shipping-method/rest-api/class-rates-controller.php:218–237; F/woodev/shipping-method/admin/orders/class-admin-rate-calculator.php:285–302, :327–329.

2. **Orders registration and marker are now real contracts.** Register the provider, shipment/tracking handlers and document source on every request, pass the plugin to `register_provider`, and supply a non-empty scalar marker writer so classic checkout, Blocks and admin-created orders appear with an owner. Export settings and automation depend on that registration; settings contributions belong in `get_tab_settings_providers()`. Evidence: F/woodev/shipping-method/admin/orders/class-orders-registry.php:244–261, :295–296, :902–915; F/woodev/shipping-method/order/class-order-marker.php:78–79, :141–158; F/woodev/shipping-method/class-shipping-plugin.php:1366, :1869; working donor Y/includes/orders/class-yandex-order-actions.php:51–81.

3. **Licensing affects live operations, not only updates.** A signed claim gates rates, export, cancel/refuse and carrier documents; existing tracking and status polling remain available, and custom lookup actions must explicitly declare `read_only` where appropriate. Provision Ozon's licensing integration before acceptance tests, and decide the expired-licence behavior of return-barcode retrieval within this established policy. Evidence: F/woodev/shipping-method/class-license-gate.php:15–27; F/woodev/shipping-method/class-shipping-method.php:679–687; F/woodev/shipping-method/admin/orders/class-order-actions.php:815–846; F/woodev/shipping-method/rest-api/class-document-controller.php:168–170.

4. **Blocks support exists; the phone spike must cover it now.** Reuse the fleet locality/pickup adapters and Store API validation rather than build another map/pickup adapter, but add Ozon's eligibility transport and test phone edits/selected-rate invalidation independently on classic and Blocks. Evidence: F/woodev/shipping-method/checkout/blocks/class-pickup-blocks.php:25–69; F/woodev/shipping-method/checkout/class-checkout-handler.php:483–487; payment-change donor F/src/checkout-blocks/payment-recalc.ts:69.

5. **Documents are order-scoped PDF/HTTPS results, not posting or account documents.** The generic route requires a non-empty aggregate carrier ID, so it cannot retrieve a return barcode after the default cancel clears that ID; it also has no posting parameter. An Ozon document source can aggregate labels where the measured API format permits it, while individual posting selection and account-level expiring return barcodes need plugin actions/routes or a deliberate framework extension. Evidence: F/woodev/shipping-method/order/interface-document-source.php:14–26; F/woodev/shipping-method/rest-api/class-document-controller.php:159–165, :394–422; F/woodev/shipping-method/order/abstract-shipment-handler.php:827–828; O/docs/wiki/ozon-delivery-api.md:316–323, :371–384.

6. **Scheduled status acquisition remains carrier-owned; canonical side effects are shared.** Reuse a plugin poller pattern (Yandex is pull-only), retain per-posting and return polling until their own terminal states, and publish only a truthful aggregate raw status to the provider's status meta. The watcher then emits canonical events/emails and delivered-order updates; marking one delivered posting as aggregate `delivered` could complete the whole WooCommerce order prematurely. Evidence: Y/includes/tracking/class-yandex-status-poller.php:69–79, :212–247; R/includes/tracking/class-russian-post-tracking-poller.php:137–139; F/woodev/shipping-method/order/class-delivery-status-watcher.php:76–84; F/woodev/shipping-method/order/class-delivery-status-events.php:55–73; F/woodev/shipping-method/order/class-delivered-order-status.php:75–102; F/woodev/shipping-method/email/class-delivery-status-emails.php:42.

7. **Shipment facts now cover costs, delivery date, issues and courier, once per order/provider.** Use them for an aggregate estimate/cost or delivery problem, but keep Ozon's per-posting promised-versus-actual dates and return placements separately: repeated child updates would overwrite the same aggregate fact slots. Evidence: F/woodev/shipping-method/order/class-shipment-facts.php:137–158, :176–194; F/woodev/shipping-method/order/class-shipment-facts-events.php:52–87; Y/includes/tracking/class-yandex-status-poller.php:299–304.

8. **Pickup schedules now have a weekly structure, still unsuitable for Ozon's dated window.** Keep the dated schedule in the Ozon catalogue and supply fresh date-labelled `work_time` text, which the point model explicitly permits; do not invent recurring weekly hours. A native calendar-aware schedule UI would require framework work, but accurate text needs none. Evidence: F/woodev/shipping-method/pickup/class-pickup-schedule.php:20–33, :97–110; F/woodev/shipping-method/pickup/class-pickup-point.php:279–293; O/docs/wiki/ozon-delivery-api.md:297.

9. **Setup wizard and error reporting are available, optional integrations.** The wizard factory defaults to null (Yandex enables its migration wizard only for v1 data), so a brand-new Ozon plugin does not need a migration wizard; a credential/method/catalogue onboarding wizard is a product choice. Error reporting needs an Ozon receiver DSN plus merchant consent, while carrier trace IDs and expected business failures still belong in the plugin's sanitized API diagnostics. Evidence: F/woodev/class-plugin.php:428–455; Y/includes/class-plugin.php:652–653; F/woodev/error-reporting/class-consent.php:15–22; F/woodev/error-reporting/class-error-reporter.php:420–431.

## 3. First-release boundary and ownership

**Framework must change if these shared behaviors are required:**

- **Admin create/edit wizard parity:** carry the recipient phone into the shared quote path (new item 1); current generic payload cannot produce a valid guest Ozon quote.
- **Native independent posting rows/status filters/tracking/actions:** introduce child shipment identity across the affected framework consumers (G-2); merely changing `export()`'s return type is insufficient.
- **Native `out_for_delivery`, calendar schedules, or return documents without a live aggregate ID:** extend the specific shared contract/UI (G-5 and new items 5/8). These are conditional integration requirements, not reasons to defer the whole plugin.

**Plugin can implement on 2.0.16:**

- **Release blockers G-1/G-2/G-3:** durable posting/return storage; safe mixed-state aggregation; async approve/cancel resolution; per-posting documents/history; preservation of IDs after cancellation; restart-safe jobs and stable idempotency keys. CDEK proves plugin-owned async settlement, and the shared custom-action/preview seams can host Ozon's management operations; no inspected donor supplies Ozon's complete independent-posting model.
- **Release blocker G-4:** a resumable, staged catalogue sync and last-good catalogue serving through `Point_Source`; dated schedules/restrictions remain plugin data.
- **Release blocker G-8:** phone flow/cache/revalidation for both storefront checkouts; **courier-only blocker adjacent to G-7:** validated address-level geocoding, structured address and persistent export snapshot. Pickup-only scope avoids courier geocoding, but still requires the phone.
- **Routine integration:** opt into challenge redirects, implement token/error-envelope mapping and rate aggregation, register the orders marker/provider, and wire licensing/polling/documents/settings; no framework cookie jar or generic catalogue project is necessary first.

A first release using plugin-managed posting/return controls and without the shared admin order-creation wizard has no demonstrated mandatory framework patch from G-1…G-8. That is an architectural route supported by existing seams, not a tested Ozon implementation; if “full native multi-posting UI plus admin creation” is part of release scope, the two framework changes above become prerequisites.

**External acceptance evidence still missing:** O/docs/03-RECON-REPORT.md:18–20 and :77–82 leave write behavior, label content type, idempotency semantics and genuinely unregistered-phone response unmeasured. Do not convert the successful read-only recon into evidence for production approve/cancel/return behavior.

## 4. Stale August references to flag, without editing

| Ozon document pointer | Current correction |
|---|---|
| O/docs/02-FRAMEWORK-V2-GAP.md:16–30; O/docs/05-IMPLEMENTATION-ROADMAP.md:5–6, :42–43; O/docs/CURRENT-STATE.md:18, :56; O/docs/AGENT-RULES.md:11 | SP-6…SP-11 are no longer future/unimplemented blockers; shipped source now includes all those subsystems. |
| O/docs/02-FRAMEWORK-V2-GAP.md:82–85 | `Abstract_Shipment_Handler::export()` returns `Action_Result`, not `string`; its stored/extracted identity is still scalar (F/woodev/shipping-method/order/abstract-shipment-handler.php:224, :1071). |
| O/docs/01-API-TO-WOOCOMMERCE-MAP.md:132–147; O/docs/02-FRAMEWORK-V2-GAP.md:97–108 | The old `not_created → created → label_ready → handed_over / export_failed` diagram is not the shipped handler API; export state, document pending and delivery state are separate implemented mechanisms. |
| O/docs/02-FRAMEWORK-V2-GAP.md:149–151 | “No status constants; SP-8 has not started” is false; nine canonical constants plus `UNKNOWN` exist (F/woodev/shipping-method/order/class-delivery-status.php:40–75). The missing out-for-delivery state itself remains true. |
| O/docs/02-FRAMEWORK-V2-GAP.md:43–44; O/docs/01-API-TO-WOOCOMMERCE-MAP.md:146–147 | `binary_content / remote_url / local_generated` are design terms, not the current source API: implement `Document_Source` returning `Document_Result::binary/url/pending/failed` (F/woodev/shipping-method/order/class-document-result.php:28–42). |
| O/docs/01-API-TO-WOOCOMMERCE-MAP.md:19–26; O/docs/02-FRAMEWORK-V2-GAP.md:158–167; O/docs/05-IMPLEMENTATION-ROADMAP.md:58 | Plain `wp_remote_post()` recon does not establish framework defaults; explicitly enable the existing challenge-redirect seam (G-6). |
| O/docs/01-API-TO-WOOCOMMERCE-MAP.md:119–123; O/docs/02-FRAMEWORK-V2-GAP.md:169–179, :201–202; O/docs/05-IMPLEMENTATION-ROADMAP.md:47 | Coordinates in `Location_Record` are no longer an unread question; address precision and order snapshot are the actual remaining tasks (G-7). |
| O/docs/05-IMPLEMENTATION-ROADMAP.md:90, :98–99, :119, :123 | Blocks, documents, orders page and licence gate are shipped integration surfaces; reference their concrete contracts rather than SP/S3 future phase slots. |
| O/docs/02-FRAMEWORK-V2-GAP.md:5, :206–207; O/docs/AGENT-RULES.md:52; O/docs/CURRENT-STATE.md:56 | `woodev_framework` / `D:\Projects\woodev_framework` are old checkout-location references, not the current Mac repo name/path; the spec file itself still exists under F/docs-internal/specs/. |
| O/docs/02-FRAMEWORK-V2-GAP.md:54–66; O/docs/01-API-TO-WOOCOMMERCE-MAP.md:177–187 | “Reverse logistics deferred” still describes the missing entity, but omits the now-shipped refusal/custom-action seams and cancellation's destructive ID cleanup. |

`Woodev_Lifecycle` at O/docs/05-IMPLEMENTATION-ROADMAP.md:55 is still a valid legacy core class name; do not mechanically namespace it just because the shipping classes are now `Woodev\Framework\Shipping\*`. The §19 field examples remain design vocabulary: the concrete `Shipping_Order_Handler` maps plugin-supplied logical names to plugin-owned keys and accepts mixed values (F/woodev/shipping-method/order/class-shipping-order-handler.php:58–96); the scalar restrictions come from shipment/UI consumers, not a universal ban on extra collection meta.

GitHub readback: [#417](https://github.com/kalbac/woodev-plugin-framework/issues/417), [#418](https://github.com/kalbac/woodev-plugin-framework/issues/418), and [#419](https://github.com/kalbac/woodev-plugin-framework/issues/419) are all **OPEN**, with **no comments** at review time. Their descriptions still speak about pre-SP-7/SP-8 design; their open status is not evidence that the listed work remains entirely absent.

Not verified: no live Ozon API or checkout/admin browser runs, credentials, write operations, tests or remote branch synchronization; API facts above are the August local recon/reference, not fresh carrier validation.
Not exhaustively read: all donor subsystems/tests and every internal gotcha; this review followed the relevant lifecycle, quote, pickup, status, document and UI consumers only.
