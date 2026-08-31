# Card #695 — Shipping plans reconciliation

> Reviewed: 2026-08-31. Sources measured: `SHIPPING-PLANS.md` (§1–§20),
> `PLANS.md`, `docs-internal/specs/2026-06-25-shipping-module-decisions.md`,
> current `main`, Git history, and the open board cards named below.

Serena MCP was unavailable to this Codex worker. Source navigation therefore used targeted
ripgrep/line inspection, with Git history or tests as the second check for material claims.

## SHIPPING-PLANS.md

| § | Topic | Status | Diverges from code? | Card / PR | Remaining work |
| --- | --- | --- | --- | --- | --- |
| 1 | DRY shipping foundation | Partly built | No direct contradiction: `Shipping_Plugin` and shared seams exist, but the module is not a complete carrier-neutral foundation. | Shipping history includes `f96a7dc` (SP-5). | Carrier-facing contracts and a real-plugin validation remain. |
| 2 | Common versus domain matrix | Partly built | The matrix's common seams are unevenly implemented: pickup/location are substantial; rates, fulfillment, tracking, and emails are not. | No section-named card. | Treat as a historical decomposition, not a completion claim. |
| 3 | Existing UX patterns | Partly built | Map/PVZ code supports framework-owned Yandex and embedded providers (`map/class-yandex-map-provider.php:47`, `map/class-embedded-map-provider.php:75`), but cannot prove the stated three-plugin UX from this repository. | `5dd5fe4` implemented the embedded seam. | Validate against the pilot plugins. |
| 4 | Shipping-plugin declarations | Partly built | `Shipping_Plugin::supports()` and method support flags exist (`class-shipping-plugin.php:841-850`, `class-shipping-method.php:664-723`), but the decided `supports_cod`/auth/map/status declaration contract does not. | No section-named card. | First real plugin must settle and implement the listed carrier contract. |
| 5 | Authentication and secrets | Partly built | `sensitive` and `constant_name` are implemented (`settings-api/class-setting.php:56-198`; field schema masks them at `settings-page/class-field-schema.php:38-80`); there is no shipping auth lifecycle/refresh abstraction. | No section-named card. | Auth contract, refresh/invalidation, and optional tracking credential channel. |
| 6 | Rates and packing | Partly built | Base `calculate_shipping()`/`calculate_rate()` and packer hooks exist (`class-shipping-method.php:224-479`), but no `get_rate_cost()` or shared successful-rate cache was found. | No section-named card. | Cost/markup primitive, success-rate cache, and decided API-failure hook/policy. |
| 7 | Pickup points and maps | Built for the generic seam | No material contradiction: Yandex and embedded/iframe providers exist and current history records `5dd5fe4` and SP-5. | #251 was merged; SP-5 was delivered by `f96a7dc`. | Real carrier adoption remains outside this repository. |
| 8 | Checkout fields and state | Partly built | Classic checkout field, location, and pickup validation seams exist (`checkout/class-checkout-handler.php:42,196`; `pickup/class-pickup-handler.php:100,1774,2901`); the shared store plus Blocks adapter decision is not implemented. | Location/pickup series, including `a9a0aa6`. | A unified core and the Blocks adapter. |
| 9 | Shared services / DaData | Partly built | Current code is a location-provider registry with `Dadata_Provider` (`location/providers/class-dadata-provider.php:52`), not the decided general shared-services registry with service-owned tabs. | Location program PRs #286/#287. | General service interface, single service configuration, and future-service registration. |
| 10 | COD, insurance, declared value | Not started | No `supports_cod`, `supports_insurance`, `supports_declared_value`, or payment-gateway coordination hook occurs in shipping source. | No section-named card. | The stated support flags and method-to-gateway seam. |
| 11 | Block checkout | Not started | `handlers/blocks-handler.php:47-66` only reports plugin compatibility; it is not the required checkout-field/pickup Blocks adapter. | No section-named card. | Mandatory fast-follow adapter after the classic core. |
| 12 | Shipment export, labels, documents | Partly built, overtaken in data shape | `order/abstract-shipment-handler.php:58-208` supplies scalar export and `carrier_order_id`; #417 establishes that Ozon needs 1..100 postings per WC order, while #419 adds asynchronous approval. | #417 and #419 are OPEN. | Collection-capable shipment model, async state/polling decision, documents and admin action plumbing. |
| 13 | Tracking and canonical delivery statuses | Partly built, overtaken in scope | `order/abstract-tracking-handler.php:52` is only an abstract seam; no canonical status set/history implementation was found. #418 makes reverse logistics required for Ozon and #419 challenges the status set. | #418 and #419 are OPEN. | Canonical status/history model, return model, webhook/cron integration, and Ozon status decision. |
| 14 | Emails | Not started | No shipping `WC_Email` subclass or shipping status-email layer was found. | No section-named card. | Status-driven email base, placeholders, templates, and dedup. |
| 15 | Shared settings page | Built | Code follows the decision: `Settings_Page_Registry` is a singleton (`class-settings-page-registry.php:22`) and creates one submenu page (`:430`); section/tab providers are already supported. | Settings-page program, e.g. `75b4467` (#595). | Migrate real carrier settings and legacy URLs. |
| 16 | Shipping orders administration | Not started; decision invalidated | No generic React orders page exists. Its stated rationale contradicts §15 and the singleton implementation; #694 is the live decision fork. | #694 is OPEN. | Operator decision on page/scope before SP-10 implementation. |
| 17 | Warehouses / origin | Overtaken by code | The decision says no framework subsystem, yet generic `Pickup\\Warehouse`, `Warehouse_Admin`, and `Abstract_Warehouses_Controller` exist (`pickup/class-warehouse.php:34`; `admin/class-warehouse-admin.php:43`; `rest-api/abstract-warehouses-controller.php:37`). | No open card covers the contradiction. | Decide whether this unused generic warehouse scaffold is retained as an explicit exception or removed. |
| 18 | Multi-shipping and coordination | Partly built | `Shipping_Plugin::register_shipping_methods()` exists (`class-shipping-plugin.php:295`), and competitor notices exist, but no declared multi-carrier registry contract was found. | No section-named card. | Carrier registry contract; keep the Russian-Post GUID integration domain-only as decided. |
| 19 | Release-blocking data contracts | Partly built, overtaken in scalar assumption | `order/class-shipping-order-handler.php:41` maps canonical fields, while export writes a scalar `carrier_order_id` (`abstract-shipment-handler.php:169-208`). #417 proves that scalar shipment data cannot cover Ozon. | #417 is OPEN. | Collection-capable data contract and per-plugin lifecycle migrations. |
| 20 | Decision summary | Spent as an implementation-status signal | It accurately records s32 decisions, but its “all 14 closed” wording means decision gaps closed, not code implemented; §§10–14, §16, and §19 show why it cannot indicate delivery. | No section-named card. | Preserve only as dated decision index if the root document is retained. |

## Cross-reference errors

Every explicit section-to-section reference in `SHIPPING-PLANS.md` was checked against its
target. Correct references include §4→§8, §7→§3/§9, §8→§11, §9→§8, §11→§8,
§12→§4/§13, §13→§12/§14, §14→§13, §15→§8/§11/§16/§18, §17→§9, and §18→§9.

| Source → target | Source quote | Target fact | Verdict |
| --- | --- | --- | --- |
| §3 → §8 | “см. §8 (Dadata)” (`SHIPPING-PLANS.md:85`) | §8 does mention a Dadata re-initialisation in its checkout-history evidence, but the stated cross-plugin ownership workaround is explained by §9 (`:223`). | **Ambiguous, not counted as an error.** A precise link should target §9. |
| §5 → §17 | Russian-Post GUID registration “см. §17” (`:129`) | §17 is warehouses/origin (`:421`); the GUID/CMS-backend discussion is §18 (`:440-469`). | **Wrong.** Change target to §18. |
| §16 → §15 | “зеркалит §15: 1 провайдер = 1 страница” (`:415`) | §15 says one page with tabs and “1 tab = 1 provider” (`:390-393`); code has one registry submenu (`settings-page/class-settings-page-registry.php:430`). | **Wrong.** #694 is the required pre-implementation decision fork. |

The decisions spec repeats the §16 outcome as “SP-10 … per-carrier React page” at
`docs-internal/specs/2026-06-25-shipping-module-decisions.md:225`; it inherits the invalid
justification rather than independently establishing it.

## Live remnants without a card

| Candidate | Evidence | Proposed Russian title |
| --- | --- | --- |
| Warehouse scaffold conflicts with §17's YAGNI decision | Generic warehouse value object, admin handler, and REST controller remain at `pickup/class-warehouse.php:34`, `admin/class-warehouse-admin.php:43`, and `rest-api/abstract-warehouses-controller.php:37`; no open card names that contradiction. | `Shipping: решить судьбу framework warehouse scaffold, противоречащего §17 (удалить или оформить исключение)` |
| §10 payment/COD contract has no tracked implementation | Searches found no decided support flags or shipping-to-payment availability hook, although the decision is concrete. | `Shipping: реализовать контракт COD/страховки/объявленной ценности и шлюз координации с оплатой` |
| §14 status-email foundation has no tracked implementation | No shipping `WC_Email` subclass or email layer is present. | `Shipping: status-driven email base для отправлений — шаблоны, placeholders и дедупликация` |

These are candidates only. This pass did not create board items.

## Overtaken by reality

- **§12 and §19:** #417 is OPEN and documents an Ozon order with 1..100 postings, while
  `Abstract_Shipment_Handler::export()` returns/stores one string. #419 is OPEN and adds an
  asynchronous confirmation phase not represented by the planned state sequence.
- **§13:** #418 is OPEN and shows that Ozon returns are a distinct entity with distinct statuses
  and documents; #419 raises the missing courier-stage status. The planned canonical set is not
  yet code, so this is a design-entry condition, not a regression in a delivered feature.
- **§16:** #694 is OPEN. The decision was wrong from the day it cited §15, not merely stale;
  no implementation should proceed until the operator selects the replacement shape.

## PLANS.md

| Section | Status against code | Material discrepancy / remainder |
| --- | --- | --- |
| §1 Goals | Partly spent | The v2 clean-break program has progressed, but the document is a 2026-05 framing rather than current execution state. |
| §2 Problem and target model | Partly built | `Woocommerce_Plugin` exists (`woodev/class-woocommerce-plugin.php:18-80`) and loader definitions exist, but not every stated target is delivered. |
| §3 Modules | Mixed | Shipping’s description is overtaken by the extensive current location/pickup/map code; payment/licensing/API statements are high-level narratives, not a board-status source. |
| §4 Resources | Live reference list | No code claim to reconcile; external/reference paths require separate availability checks. |
| §5 Open questions | Partly spent | Plugin type via `extends` is implemented (`Woocommerce_Plugin`, commit `1aa4ec4`); bootstrap still exists at `woodev/bootstrap.php:12`, so its future remains open. |
| §6 Preferences | Live convention | React/WP component preference is consistent with current `src/` admin surfaces; no implementation checklist follows from it. |

## Material for the file-disposition fork

Measured sizes: `SHIPPING-PLANS.md` is **60,017 B** (20 sections), `PLANS.md` is
**11,720 B**, and the authoritative decisions spec is **19,137 B**. Of the 20 shipping-plan
sections, **2 are built, 13 partly built, 3 not started, and 2 overtaken**; §20 is spent as a
delivery signal but remains a dated decision index. The file therefore contains live material in
18 sections but no section can be treated as an up-to-date implementation ledger without the
board/code reconciliation above; the two demonstrated cross-reference errors are §5→§17 and
§16→§15. §3→§8 is an ambiguous pointer that should be made precise, but §8 does mention DaData.

## Related

- [Card #695](https://github.com/kalbac/woodev-plugin-framework/issues/695) — reconciliation request.
- [Card #694](https://github.com/kalbac/woodev-plugin-framework/issues/694) — SP-10 decision fork.
- [Shipping decisions spec](../specs/2026-06-25-shipping-module-decisions.md) — authoritative s32 decomposition.
