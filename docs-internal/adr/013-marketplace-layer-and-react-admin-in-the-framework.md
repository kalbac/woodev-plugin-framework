# ADR-013: Marketplace layer and PHP-driven React admin in the framework

**Status:** accepted direction (operator decisions 2026-11-10, s224); API design round 2 proposed
for review, implementation pending

**Date:** 2026-11-10

## Context

Framework issue #1675 records the operator's decisions made during the Ozon Seller v2 port:
all stand-alone marketplace admin pages use React with PHP-owned definitions and strings
([Rule 9](../AGENT-RULES.md)); embedded WooCommerce product tabs and order metaboxes stay
classic. Each marketplace has its own top-level menu, including its settings. Shared
marketplace services and the reusable admin infrastructure belong in the framework now.
Analytics is deferred; reserve a slot only.

The Ozon specification §7.1 had provisionally kept `Woodev\Marketplace\*` inside the plugin
(option B). The operator selects option A: move it into the framework. The specification's
§8 classic screens and its Woodev → Настройки placement are superseded; its domain
requirements and validation rules remain relevant.

Round 1 of this design (PR #1676) composed hand-wired registries from a plain
`Woocommerce_Plugin`, kept the `Woodev\Marketplace\*` namespace and planned to move the
shipping orders page onto the new table. The operator's comment on PR #1676 (2026-11-10)
overrules all three: a marketplace plugin extends a framework base class, the namespace is
`Woodev\Framework\Marketplace\*`, and delivery is not touched at all. A second operator
decision (2026-11-10, s225) widens the module: `Woodev\Framework\Marketplace` holds **all**
logic that is not specific to one marketplace — accounts, sync/queue/governor, links,
mappings, jobs, records table, menu/pages/settings, diagnostics, lifecycle — modelled on the
Shipping and Payment modules. The plugin keeps only its marketplace adapter, API client and
marketplace-specific rules. The R1 critic report's findings are applied on top of both.

Measured on Ozon `origin/main` `5d00c10`: `includes/marketplace/` is only the portable domain
layer (85 files). Most of the generic machinery lives outside it — `jobs/`, `stock/`,
`settings/`, `diagnostics/`, `lifecycle/`, `orders/`, `admin/` and the store/queue halves of
`ozon/` — typed against Ozon concretes such as `Ozon_Account_Store` instead of the layer's
interfaces. The layer's own `Marketplace_Adapter` contract exists but nothing implements it.

The shipping orders page («Заказы доставки») demonstrates WooCommerce Admin page registration,
paged tables, filters, row actions, confirmation dialogs, edit locks and export notices. It
is the **reference** for the new records table, not its source: its JS also owns columns,
statuses and copy, and it serves six live delivery plugins. The existing
`src/components/records-table.tsx` is an editable JSON settings control without server
paging or bulk actions; it is not the list-page component either.

## Decision

1. **Namespace and placement.** The marketplace domain layer moves to `woodev/marketplace/`
   as `Woodev\Framework\Marketplace\<Folder>\*`. Class-map Rule 1 already maps the
   `Marketplace` segment to `woodev/marketplace/`; no alias and no extra naming rule are
   added. No second autoloader, no runtime Composer dependency.
2. **Domain independence.** The domain folders (today's `includes/marketplace/` set) stay
   independent of plugin classes, other framework modules and WordPress APIs, with the
   existing explicit `WC_Product`, `WC_Order` and `wpdb` allowances. The ported dependency
   scanner covers exactly those folders and allows the same-layer prefix
   `Woodev\Framework\Marketplace\`. The infrastructure folders this decision adds (stores,
   jobs, settings, admin, the base class) use WordPress and are outside its scope; they
   still never import a concrete marketplace. Marketplace semantics found inside the donor
   layer — the `field:KEY` price source, the price-send cadence, the article length and
   normalisation, the export rule set, the stock formula — leave it as adapter-supplied
   policy; the framework keeps the mechanics.
3. **Module scope, Shipping/Payment pattern.** The framework module owns every
   marketplace-neutral part: domain values and contracts, persistence (schema, stores,
   lifecycle, uninstall), the account claim and run model, schedulers and job runners, the
   inbound-event intake and drain, stock/price/export/link/order/label flows, settings
   sections, diagnostics, the embedded product tab and order metabox, and every
   stand-alone page. A plugin implements `Marketplace_Adapter` (the existing contract,
   extended where a moved flow needs a marketplace answer) plus its API client, wire
   mappers and marketplace-only rules. A marketplace rule found inside a generic class
   during the move becomes an adapter method; it is never kept as a hard-coded branch.
4. **Base class.** Every marketplace plugin extends
   `\Woodev\Framework\Marketplace\Marketplace_Plugin extends \Woodev\Framework\Woocommerce_Plugin`,
   as carriers extend `Shipping_Plugin` and gateways extend `Woodev_Payment_Gateway_Plugin`.
   The plugin declares data and its adapter (`get_marketplace_adapter()`, as carriers
   supply `get_api()`); the base class owns the top-level `wc_admin_register_page()` root without `parent`, the children, the
   page manifest and client routing, asset loading, provider registration and the
   «Настройки» child. Plugins never call the registries themselves.
5. **Settings placement by type.** `Settings_Page_Registry` places the settings providers of
   a `Marketplace_Plugin` under that plugin's «Настройки» page, decided by `instanceof`
   exactly where it already tests `instanceof Woocommerce_Plugin`. Every other provider
   stays on Woodev → Настройки unchanged. The one URL builder becomes placement-aware.
   No mount object, no mount API.
6. **Generic records engine.** Reusable list infrastructure lives in
   `Woodev\Framework\Admin\Records\*` and `src/admin-records/`, separate from the settings
   control. PHP declares columns, filters, sort keys, actions, statuses, every string and
   the activity projection; TypeScript renders the declarations and owns navigation,
   selection, request lifetimes and accessible interaction. It is not marketplace-specific.
7. **Delivery is not touched.** «Заказы доставки» stays where and as it is. The records
   engine copies and adapts mechanics with the shipping page as a reference. No card in
   this plan changes `src/shipping-orders-page/**` or `woodev/shipping-method/**`; there
   is no shipping-adoption card. One shared shell for both pages, if ever wanted, is a
   separate tech-debt decision.
8. **Bulk mutations.** Every marketplace bulk mutation has a read-only preview and explicit
   confirmation. Apply replans on the server from current state and returns the actual
   counts; there is no server-side preview receipt. Durable work stays in existing
   task/queue services; a visual row lock is not concurrency control.
9. **Activity at its real granularity.** Row locks come only from row-scoped sources (link
   state, task items, in-flight revisions). A marketplace account claim is an account-level
   lease and is shown as an account-level line; it blocks only actions that themselves take
   that claim synchronously. Progress is indeterminate when the server cannot measure it.
10. **Analytics.** Page definitions carry `analytics: null`. It produces no tab, blank card,
    route, query or JS dependency.
11. **Multi-account stays.** Accounts are framework-owned; records definitions carry an
    explicit account context; writes never default to an implicit first account.

The detailed PHP API, wire contract, Ozon migration and ordered implementation cards are in
[the design](../wiki/marketplace-admin.md). Names there describe proposed APIs, not available
runtime classes. No production code is changed by this ADR.

## Alternatives Considered

| Alternative | Assessment |
|---|---|
| Move only `includes/marketplace/` and keep jobs, stores, settings and screens in the plugin (round 1) | Rejected by the operator (s225): the second marketplace would re-implement queues, accounts, settings and every screen. |
| Keep the common layer and React shell inside Ozon until another marketplace arrives | Rejected by the operator's explicit choice of framework ownership now. |
| Plain `Woocommerce_Plugin` plus hand-composed registries (round 1) | Rejected by the operator: each plugin could wire menu, settings and providers in its own order. A base class registers once, the same way for every marketplace. |
| Keep the `Woodev\Marketplace\*` namespace with a naming exception (round 1) | Rejected by the operator. It also needed generator work Rule 1 makes unnecessary. |
| Move «Заказы доставки» onto the new shell, or extract primitives out of it (round 1 F5/F8) | Rejected by the operator: six live delivery plugins would change in a routine framework release for no marketplace benefit. |
| Use classic stand-alone lists or WooCommerce's settings tabs | Rejected by #1675; classic UI remains only inside existing WooCommerce screens. |
| Copy the shipping application into every marketplace plugin | Duplicates request races, selection, dialogs and copy per plugin; violates Rule 9. One framework copy is adapted instead. |
| Generalize the JSON `records-table` settings field | Its unsaved whole-field edit model conflicts with independently persisted paged records. |
| Introduce DataViews as the table stack | Not selected: the `TableCard`/modal stack already runs in production here; verified against minimum and current WC inside F2/F5. |
| Server-side preview receipt bound to user and request (round 1) | Rejected (KISS): the real planner replans on apply; the only guarded case is an idempotent account-wide linking job whose scope the preview already states. |
| Account claim as a per-row lock (round 1) | Rejected: the claim is one lease per account taken by every background run; per row it would lock the whole catalogue most of the time. |
| Build analytics with the new shell | Outside the operator's scope; nullable slot only. |

## Consequences

- Future marketplace plugins (Wildberries next) get accounts, queues, sync, settings, pages
  and diagnostics by extending one class and implementing one adapter; they write no
  registration, routing, scheduling or persistence code.
- The framework gains no Ozon client, Ozon wire format or Ozon rule, and no remote side
  effect on page reads; it creates marketplace tables and jobs only for an active
  `Marketplace_Plugin`.
- The framework carries a second table application next to the shipping one. The
  duplication is the accepted price of leaving delivery untouched.
- The Ozon plugin shrinks to its adapter, API client, wire mappers, marketplace-only rules
  and its v1 migration. It renames about 300 files' references, deletes everything the
  framework now owns and bumps its framework floor wave by wave. No data migration: no
  class name is persisted, and Ozon v2 has not been released, so its v2 table columns and
  stored enum values may still take neutral names.
- The framework takes on a large, stateful module (tables, Action Scheduler jobs, a public
  REST intake route per marketplace). Each transfer card moves behaviour with its tests and
  changes no semantics beyond retyping to contracts.
- Settings placement becomes type-driven: generated links and the legacy redirect resolve
  per provider. Storage keys and provider/action permissions do not move.
- Per-row progress adds a bounded local-state read projection. It cannot infer completion
  from HTTP acceptance or expire an unresolved remote operation because a lease elapsed.

## Related

- [Marketplace admin design](../wiki/marketplace-admin.md) — API and implementation plan
- [Agent rules](../AGENT-RULES.md) — Rule 9 and installed-site contracts
- [Architecture](../wiki/architecture.md) — current subsystems and settings model
- [ADR-002](002-plugin-type-inheritance-with-metadata-bridge.md) — plugin types are base classes
- [ADR-005](005-platform-v2-clean-break-policy.md) — internal changes versus stored contracts
- [ADR-007](007-react-admin-stack-wordpress-scripts.md) — WordPress React and build stack
- [ADR-012](012-shipping-includes-stays-authoritative.md) — shipping include-list obligations
