# ADR-013: Marketplace layer and PHP-driven React admin in the framework

**Status:** accepted direction; API design proposed for review, implementation pending

**Date:** 2026-11-10

## Context

Framework issue #1675 records the operator's decisions made during the Ozon Seller v2 port:
all stand-alone marketplace admin pages use React with PHP-owned definitions and strings;
embedded WooCommerce product tabs and order metaboxes stay classic. Each marketplace has its
own top-level menu, including its settings. Shared marketplace services and the reusable
admin infrastructure belong in the framework now. Analytics is deferred; reserve a slot only.

The Ozon specification §7.1 had provisionally kept `Woodev\Marketplace\*` inside the plugin
(option B). The operator now selects option A: move it into the framework. The old specification
§8 classic screens and Woodev settings placement are superseded by this decision; its domain
requirements and validation rules remain relevant.

The shipping orders page already demonstrates WooCommerce Admin page registration, paged
tables, filters, row actions, confirmation dialogs, edit locks and export notices. It also
contains shipping-specific rendering and JS-owned columns, statuses and copy. Extracting its
whole application would export those assumptions to marketplace plugins. The existing
`src/components/records-table.tsx` is instead an editable JSON settings control without server
paging or bulk actions; it is not the proposed list-page component.

## Decision

1. Move the existing marketplace domain layer to `woodev/marketplace/`, retaining the
   `Woodev\Marketplace\*` namespace. This is a deliberate exception to the usual
   `Woodev\Framework\*` naming rule, preserving the existing adapter contracts. Extend the
   generated runtime class map, its naming checks and development classmap coverage. Do not
   ship a second marketplace autoloader or depend on Composer at runtime.
2. Keep the domain layer independent of plugin classes, framework services and WordPress
   APIs, with its existing explicit `WC_Product`, `WC_Order` and `wpdb` allowances. Move its
   dependency scanner and portable contract tests with it. Database table names, credentials,
   options, scheduling and marketplace-specific transports remain plugin composition inputs.
3. Add reusable UI infrastructure in `Woodev\Framework\Admin\Records\*` and
   `src/admin-records/`, separate from the existing settings control. PHP declares columns,
   filters, sort keys, actions, action fields, statuses, every application string and the
   lock/progress projection. Generic TypeScript renders those declarations and manages
   navigation, selection, request lifetimes and accessible interactions. It never derives
   marketplace eligibility, state transitions or messages from raw domain codes.
4. Register marketplace menus/pages through a framework `Marketplace_Page_Registry` in the
   framework admin namespace. Its root uses `wc_admin_register_page()` without `parent`;
   children use the root's registered **id**. A PHP-produced page manifest drives the existing
   `woocommerce_admin_pages_list` client extension point. No plugin-specific routing bundle
   is needed for an ordinary records page.
5. Add explicit settings mounts to `Settings_Page_Registry`. Marketplace settings reuse the
   existing providers, controls, validators and REST handlers under their own wc-admin menu
   page. The default Woodev mount and all existing provider storage remain unchanged. A
   provider has one presentation mount; placement is not an authorization mechanism.
6. Reuse shipping's proven mechanical pieces incrementally through a shipping adapter.
   Preserve all shipping URLs, REST contracts, extension hooks, capabilities, documents,
   wizard behavior, export heartbeat notice and existing analytics. Do not require updates
   to the six live delivery plugins. Marketplace progress and lock projection are additions;
   the page-wide shipping export count is not a per-row progress source.
7. Every marketplace bulk mutation has a read-only preview and explicit confirmation. The
   server replans and enforces eligibility/claims on apply. Durable work stays in existing
   task/queue services; a visual row lock is not concurrency control. Display indeterminate
   progress when the server cannot measure a percentage.
8. Reserve `analytics: null` on marketplace page definitions. It produces no tab, blank
   card, routes, metrics query or JS analytics dependency. This does not remove shipping's
   shipped analytics.

The detailed PHP API, wire contract, shipping extraction boundary, Ozon migration and ordered
implementation cards are in [the design](../wiki/marketplace-admin.md). Names there describe
proposed APIs, not available runtime classes. No production code is changed by this ADR.

## Alternatives Considered

| Alternative | Assessment |
|---|---|
| Keep the common layer and React shell inside Ozon until another marketplace arrives | Rejected by the operator's explicit choice of framework ownership now. |
| Use classic stand-alone lists or WooCommerce's settings tabs | Rejected by #1675; classic UI remains appropriate only inside existing WooCommerce screens. |
| Copy the shipping application into every plugin | Duplicates request races, selection, dialogs and copy; violates Rule 9 for reusable JS. |
| Generalize the existing JSON `records-table` settings field | Its unsaved whole-field edit model conflicts with independently persisted paged records; keep both components separate. |
| Introduce DataViews as a replacement table stack | Not selected: no new dependency is required to extract the existing shipping mechanics; no claim of DataViews feature suitability is made without reference/compatibility work. |
| Move shipping's query/action/analytics classes into the generic table | Rejected: generic records need no order, shipment, carrier or analytics concepts. |
| Rename the extracted layer into `Woodev\Framework\Marketplace` | Adds churn across the in-flight port without changing ownership or dependency direction; retain the existing namespace and enforce its directory rule explicitly. |
| Build analytics with the new shell | Outside the operator's scope; reserve the nullable extension point only. |

## Consequences

- Future marketplace plugins reuse one PHP-driven application rather than adopting a
  shipping-specific page or developing their own JS business layer.
- The framework gains a domain module and generic admin contracts, but no Ozon client,
  account schema, webhook endpoint, scheduled job or remote side effect on page reads.
- Extraction needs vendored-runtime boot tests in addition to Composer-backed unit tests.
  The Ozon plugin must remove its duplicate layer and bump its loader compatibility floor
  to the first released framework version containing the contracts before switching over.
- Shipping extraction is gated by behavior preservation and a six-plugin contract matrix;
  ordinary third-party shipping integrations receive no new required methods.
- Settings placement becomes explicit, including generated links, deep-link redirects and
  mount-scoped list schemas. Storage keys and provider/action permissions do not move.
- Per-row progress adds a bounded local-state read projection. It cannot infer completion
  from HTTP acceptance or expire an unresolved remote operation because a lease elapsed.
- UI taste, unverified supported-version behavior and presentation of nested product rows
  remain explicit open questions in the design. They do not reopen the operator's decisions.

## Related

- [Marketplace admin design](../wiki/marketplace-admin.md) — API and implementation plan
- [Agent rules](../AGENT-RULES.md) — Rule 9 and installed-site contracts
- [Architecture](../wiki/architecture.md) — current subsystems and settings model
- [ADR-005](005-platform-v2-clean-break-policy.md) — internal changes versus stored contracts
- [ADR-007](007-react-admin-stack-wordpress-scripts.md) — WordPress React and build stack
- [ADR-012](012-shipping-includes-stays-authoritative.md) — shipping include-list obligations
