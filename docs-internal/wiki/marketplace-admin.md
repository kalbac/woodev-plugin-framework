# Marketplace layer and PHP-driven React admin

> Design for framework #1675, based on the operator's 2026-11-10 decisions.
> Proposed APIs below do not exist yet. Implementation is separate, after design review.

## Scope and reference baseline

All stand-alone marketplace pages are React: Настройки, Товары, Заказы, Сопоставление,
Инструменты, Диагностика. PHP owns definitions, permissions, business decisions and strings;
TypeScript owns reusable interaction. Product edit tabs, variation panels and order metaboxes
inside WooCommerce remain classic. Marketplace settings belong to the marketplace menu.
Shared marketplace settings may later have a Woodev tab, but none is introduced here.
Analytics is reserved only; no marketplace analytics UI, calculations or endpoints are built.

References inspected on 2026-11-10:

- Framework `dcfe58d7`: [agent rules](../AGENT-RULES.md) Rule 9,
  [architecture](architecture.md), [shipping application](../../src/shipping-orders-page/app.tsx),
  [page entry](../../src/shipping-orders-page/index.tsx),
  [registry](../../woodev/shipping-method/admin/orders/class-orders-registry.php),
  [orders REST controller](../../woodev/shipping-method/rest-api/class-orders-controller.php),
  [settings registry](../../woodev/settings-page/class-settings-page-registry.php) and shared components.
- Read-only Ozon source: specification `docs/spec/ozon-seller-v2-spec.md` §7–8, wiki and
  `includes/marketplace/`; merged products/mappings code read from that repository's local
  `origin/main`, `7abfd0e605078494717946e1c42bb4e848240782`, because its primary checkout
  `bb3b059` predates those screens. These are source provenance identifiers, not a promise
  that the private branch has stopped moving. No private operational data is reproduced here.
- The supplied local WooCommerce source path was absent. Upstream WooCommerce source was
  inspected instead: revision `20949b8c161e518123bd3afabf0f439c92786498`,
  `plugins/woocommerce/src/Admin/PageController.php::register_page()`,
  `docs/extensions/settings-and-config/working-with-woocommerce-admin-pages.md`,
  `packages/js/components/src/table/{README.md,types.ts}`. The source, rather than its older
  documented default capability, governs this design: always pass an explicit capability.
- Context7 and Serena were unavailable. Existing installed `@wordpress/components`
  `src/modal/{README.md,index.tsx,types.ts}` and upstream TableCard source were inspected as
  fallbacks. Retain the shipping stack and its existing WordPress modal integration; do not
  add `@wordpress/dataviews` or install/bundle `@woocommerce/components`. Context7 review
  and a supported-version smoke test remain implementation prerequisites (question Q3).

This document supersedes the Ozon spec's provisional §7.1 option B and §8 classic stand-alone
screens/default Woodev settings placement. It preserves their domain requirements.

## Ownership and dependency direction

```text
Ozon plugin composition, guards, stores, jobs, remote adapters
       |                       |
       v                       v
Woodev\Marketplace\*     Woodev\Framework\Admin\*
domain/queue primitives  pages + records + settings mounts
       |                       |
       v                       v
PHP / WC_Order /         PHP definitions + local REST projections
WC_Product / wpdb              |
                               v
                       generic framework React bundle
```

No generic table class imports marketplace, shipping or Ozon domain classes. No marketplace
domain class imports admin infrastructure. A marketplace plugin continues to extend
`Woodev\Framework\Woocommerce_Plugin` and composes the registries; a new plugin base class is
unnecessary. Ozon-specific serializers, card states, endpoints, labels flow, schema migrations,
table prefixes, credentials and scheduling stay in the plugin.

## Marketplace module placement

Move existing `includes/marketplace/<folder>/` symbols to `woodev/marketplace/<folder>/`
without changing their `Woodev\Marketplace\<Folder>` FQCNs. This namespace exception is
deliberate, recorded in [ADR-013](../adr/013-marketplace-layer-and-react-admin-in-the-framework.md).
The inventory below reflects the merged donor revision, not only the earlier §7.2 sketches.
Each row covers the named folder; nested catalog rules retain `Catalog\Rules`.

| Folder | Symbols to transfer |
|---|---|
| `account` | `Account`, `Account_Store`, `Credential_Health`, `Health_Result`, `Key_Expiry`, `Secret_Cipher` |
| `capabilities` | `Capability`, `Marketplace_Adapter` |
| `governor` | `Bucket_Spec`, `Request_Governor`, `Token_Bucket_Governor`, `Throttle_Store`, `Throttle_Row`, `Wpdb_Throttle_Store` |
| `ledger` | `Async_Task`, `Task_Status`, `Item_Result`, `Claimed_Batch`, `Claimable` trait, `Task_Ledger`, `Wpdb_Task_Ledger`, `Inbound_Event`, `Inbound_Decoder`, `Event_Ledger`, `Wpdb_Event_Ledger` |
| `links` | `Product_Link`, `Link_Store` |
| `stock` | `Stock_Line`, `Stock_Pusher`, `Stock_Policy`, `Sync_State_Store`, `Sync_State_Queue` |
| `price` | `Money`, `Price_Rule`, `Price_Pair`, `Price_Calculator`, `Price_Line`, `Push_Receipt`, `Price_Pusher`, `Currency_Conversion`, `Price_Currency`, `Price_Intent`, `Price_Override`, `Price_Sync_Queue`, `Flush_Result` |
| `orders` | `Order_DTO`, `Order_Line`, `Order_Group`, `Status_Map`, `Pull_Result`, `Order_Source`, `Order_Writer`, `Order_Store`, `Order_Registry`, `Registry_Order_Writer`, `Order_Claim_Exception` |
| `catalog` | `Field_Definition`, `Catalog_Schema`, `Source`, `Source_Resolver`, `Mapping`, `Profile`, `Dictionary_Resolution`, `Offer_Id`, `Offer_Id_Likeness`, `Validation_Context`, `Validation_Rule`, `Validation_Report`, `Bulk_Validation_Report`, `Export_Validator`, `Card_Exporter` |
| `catalog/rules` | `Category_Exists_Rule`, `Category_Mapped_Rule`, `Currency_Rule`, `Dictionary_Values_Rule`, `Dimensions_Rule`, `Images_Rule`, `Offer_Id_Rule`, `Required_Fields_Rule`, `Selling_Price_Rule`, `Variation_Group_Rule` |
| `diagnostics` | `Report_Section`, `Support_Report`, `Why_Not_Syncing`, `Api_Error_Ring` |

Transfer behavior and tests first; do not redesign queues during relocation. Refresh this
inventory against the donor main at implementation time and account for every added symbol.
Constructors accepting database/table names, clocks, ciphers and callbacks keep those inputs;
the framework creates no plugin tables or cron hooks on load. Preserve ciphertext format,
claim fencing, money units, task statuses and idempotency. Audit any translation literals
separately: framework-owned translatable strings use the framework domain and catalogues,
while plugin-specific status labels stay supplied by Ozon PHP.

Wiring obligations:

- Run [the class-map generator](../../bin/generate-class-map.php). It already scans all of
  `woodev/` and includes classes, interfaces and traits; its namespace-directory validation
  currently applies only to `Woodev\Framework\*`. Add an explicit
  `Woodev\Marketplace\` → `woodev/marketplace/` naming rule and negative tests, rather than
  allowing this namespace to bypass directory validation.
- Add marketplace directories to `composer.json`'s development `autoload.classmap`, including
  nested rules. The WordPress `class-*.php` convention is not Composer PSR-4 file naming.
  Extend [ClassMapCompletenessTest](../../tests/unit/ClassMapCompletenessTest.php); verify a
  real vendored boot with Composer disabled and two plugins sharing the winning copy.
- Runtime remains [the selected framework autoloader](../../woodev/class-framework-autoloader.php).
  Example map entry: `Woodev\Marketplace\Price\Money` →
  `woodev/marketplace/price/class-money.php`. Do not add marketplace files to shipping's
  eager include list; [ADR-012](../adr/012-shipping-includes-stays-authoritative.md) remains
  scoped to shipping. Keep eager lists complete for any shipping file moved out of its tree.
- Port donor `LayerDependencyTest`, its tokenizer scanner and negative fixtures. Forbid Ozon
  symbols/string dependencies, plugin classes, framework imports, WordPress functions,
  constants and classes except the `ABSPATH` entry guard and explicit `WC_Product`,
  `WC_Order`, `wpdb` allowances. PHP standard types/functions and same-layer symbols are
  allowed. Comments mentioning a marketplace are not dependencies. Scan every actual file,
  including traits and new folders; never rely on an old fixed file-count threshold alone.
- Move the fake second-marketplace contract tests with the layer: grouped orders in all
  arrival orders, minor-unit money, asynchronous price acceptance/quarantine/duplicate tasks,
  nullable card task ids, poll-only orders and stock key resolution. Preserve the existing
  scanner's positive and negative controls, not just a green scan of the donor tree.

## PHP registration API

All signatures are PHP 7.4 sketches. Descriptor arrays below have validated documented shapes;
they are not unchecked arrays copied directly to JS. Public/protected implementation methods
need normal typed parameters, return types and PHPDoc. Pure descriptor builders are static.
Framework admin infrastructure uses `Woodev\Framework\Admin`; settings types stay in
`Woodev\Framework\Settings`.

```php
// Woodev\Framework\Admin
final class Marketplace_Page_Registry {
    public static function instance(): self;
    public function register_menu( \Woodev_Plugin $plugin, array $menu ): void;
    public function register_page( string $menu_id, array $page ): void;
    public function get_page_url( string $page_id, array $query = [] ): string;
}

// Woodev\Framework\Admin\Records
final class Records_Registry {
    public static function instance(): self;
    public function register( string $owner_id, Records_Provider $provider ): void;
}

interface Records_Provider {
    public function get_id(): string;
    public function get_definition(): array;
    public function authorize( string $operation, array $context, array $ids ): bool;
    public function query( array $context, array $query ): array;
    public function get_rows( array $context, array $ids ): array;
    public function preview( array $context, string $action, array $ids, array $payload ): array;
    public function apply( array $context, string $action, array $ids, array $payload ): array;
    public function get_activity( array $context, array $ids ): array;
}
```

`owner_id` is the registered plugin id; table ids are unique within that owner. Duplicate
registration is rejected with a developer diagnostic; a later plugin cannot replace another
owner's table. The registry stores the actual owner object for capability and asset resolution.
`authorize()` checks provider-specific context/object relationships; the controller also checks
registered page/action capabilities and WP REST authentication. Missing authorizers fail closed.
It returns `bool` so errors remain standard `WP_Error` responses at the controller boundary;
domain code does not depend on `WP_Error`.

Example composition from the plugin's admin initialization, before `admin_menu`:

```php
$pages = Marketplace_Page_Registry::instance();
$pages->register_menu( $this, [
    'id' => 'ozon-seller',
    'title' => __( 'OZON Seller', 'woocommerce-ozon-seller' ),
    'page_title' => __( 'OZON Seller', 'woocommerce-ozon-seller' ),
    'path' => '/woodev-ozon',
    'icon' => $this->get_menu_icon(),
    'capability' => 'manage_woocommerce',
    'landing_page' => 'ozon-products',
] );
Records_Registry::instance()->register( $this->get_id(), $products );
$pages->register_page( 'ozon-seller', [
    'id' => 'ozon-products',
    'title' => __( 'Товары', 'woocommerce-ozon-seller' ),
    'path' => '/woodev-ozon-products',
    'kind' => 'records',
    'tables' => [ 'site-products', 'remote-products' ],
    'capability' => 'manage_woocommerce',
    'analytics' => null,
] );
```

The root is registered first using `wc_admin_register_page()` with **no `parent` key**.
Pass its `icon`, optional integer `position`, explicit `capability` and plain `page_title`.
For children pass `parent => 'ozon-seller'`, the registered id, not its title or a guessed
WordPress menu slug: PageController resolves that id to `wc-admin&path=/woodev-ozon`.
The root route delegates to `landing_page`; avoid creating a seventh dashboard. Register
Настройки / Товары / Заказы / Сопоставление / Инструменты / Диагностика as children.
Normalize WordPress's automatic duplicate root submenu entry after child registration so
there are exactly six visible children; match only this registry's own root URL.
The exact icon artwork, landing page and display ordering are Q1, not decided by this sketch.

PHP emits a capability-filtered `pages` manifest containing id, path, breadcrumbs, owner,
kind, table ids or settings mount, and actual WP menu identity. Generic JS iterates that
manifest into `woocommerce_admin_pages_list`; PHP/JS paths must match byte-for-byte.
Support `kind = records | settings | custom`. A `custom` page names a registered plugin React
view (mappings editor, diagnostics, tools); this is a controlled component registry, never
JS source from PHP. Its PHP schema still owns fields, actions, status labels and all copy.

Enqueue on registered wc-admin screens so SPA navigation reaches sibling routes, following
shipping's existing pattern; loading only when the initial URL matches one child breaks
navigation. Use framework-owned versioned bundles and the winning framework's asset paths.
Register client components once even when several plugins share the module. Plugins provide
their custom-view handles explicitly; no Ozon imports enter the generic bundle. Show a
PHP-owned unavailable message if WooCommerce Admin/components are missing. No classic-page
fallback silently replaces the chosen React screens.

## Table definition and row model

`get_definition()` supplies a schema version, label, tab label, context fields, columns,
filters, sort keys, pagination limits/defaults, row actions, bulk actions, messages, activity
refresh policy and `analytics: null`. All user-facing strings include empty/error/loading
states, selection labels, button titles, modal titles, progress accessibility text and
confirmation text. Framework PHP provides common defaults, plugin PHP overrides domain
wording. Count-dependent sentences arrive preformatted/pluralized in responses. JS has no
Ozon/carrier code switches and no hardcoded fallback copy. Vendor controls retain their own
translated intrinsic accessibility strings; expose their label override props where available.

| Definition member | Shape / rule |
|---|---|
| `context` | Required account selector where relevant; `id`, label, type, allowed options/default. No implicit first account for writes. Options expose only authorized accounts. |
| `columns` | Ordered `id`, `label`, `cell_type`, optional `sort_key`, `required`, `visible`, alignment. Finite cell types: text, link, badge, number, date, stack, actions, progress. No arbitrary HTML or callable in JSON. |
| `filters` | Ordered `id`, label, type (`search`, `select`, `multi_select`, `boolean`, `date_range`), options or registered local options source, allowed operators/default. Omitted boolean differs from false. |
| `sorts` | Whitelisted keys and directions plus default; PHP maps keys to prepared datastore queries and adds a stable id tie-breaker. Client never submits SQL column names. |
| `pagination` | `default_per_page`, `per_page_options`, `max_per_page`, `max_selection`; reject overflow, never silently truncate. Selection initially covers the visible page only. |
| `row_actions` | Registered ids with label, icon, tone, capability, optional input-field schema, confirmation requirement, effect (`read` or `mutate`) and interaction (`link`, `submit`, `dialog`, `download`). Per-row availability comes from PHP. |
| `bulk_actions` | Same identifiers/schema principles, always preview then confirm for mutations, `scope = selection` or `scope = account`, server maximum and scope sentence. An account-wide linking job must say it affects the account, even if launched beside selected rows. |
| `activity` | Enabled source, `refresh_ms`, `max_refresh_ms`, `stale_after_ms`, authorized id limit. PHP determines policy; JS bounds requests and backs off on failure. |
| `analytics` | Exactly null in this implementation; no request, visible placeholder or hidden computation. Future non-null format needs a separate design/card. |

Example PHP fragment (labels use the owning domain in the plugin, framework defaults use its domain):

```php
return [
    'schema_version' => 1,
    'label' => __( 'Товары сайта', 'woocommerce-ozon-seller' ),
    'columns' => [
        [ 'id' => 'product', 'label' => __( 'Товар', 'woocommerce-ozon-seller' ),
          'cell_type' => 'link', 'sort_key' => 'title', 'required' => true ],
        [ 'id' => 'state', 'label' => __( 'Состояние', 'woocommerce-ozon-seller' ),
          'cell_type' => 'badge' ],
        [ 'id' => 'activity', 'label' => __( 'Ход работы', 'woocommerce-ozon-seller' ),
          'cell_type' => 'progress' ],
    ],
    'sorts' => [ 'allowed' => [ 'title' => [ 'asc', 'desc' ] ],
                 'default' => [ 'key' => 'title', 'direction' => 'asc' ] ],
    'analytics' => null,
];
```

Rows use opaque string ids, stable across sorting/paging and scoped by owner/table/context.
A product-account pair is not identified by WC product id alone. PHP resolves a row id back
to the canonical product/link/account before permission checks. Cell data follows the declared
type: text; text plus safe URL; label plus semantic badge tone; formatted value plus numeric
sort value; ISO date plus display/title text; or a bounded stack of these. Render text escaped;
allowlist URL protocols and validate output shape server-side. No raw credentials, claim tokens,
task payloads or remote errors containing sensitive request details reach this projection.

Interactive cells reference declared actions and input schemas, including a boolean toggle
or select if needed. Changing one submits the same guarded action path as a row button; it
does not optimistically persist a new domain value. PHP returns the authoritative replacement
row or a field error. For variations, use an optional bounded child-row read endpoint with
the same identity, permission and activity model; the presentation remains Q2.

## REST contract

Use the existing `Woodev_REST_V1_Registrar`. New generic routes live under
`woodev/v1/admin/records/{owner}/{table}` (`R` below). Registry lookup supplies the provider;
the request cannot name a PHP class, arbitrary URL or callback. The owner/table namespace
and schema version are distinct: `schema_version: 1` describes this payload.

| Route | Request | Successful response |
|---|---|---|
| `GET R/definition` | Explicit context when the definition needs it | `{schema_version, definition, context}` filtered for the user; no secrets or callbacks |
| `GET R` | `context[account_id]`, `page`, `per_page`, `search`, `sort`, `direction`, typed `filters` | `{rows, total, page, per_page, total_pages, facets, messages}`; totals/facets computed under the same authorized query |
| `GET R/{id}/children` | Context, bounded page/per_page; only if declared | Same page envelope, child rows scoped to the resolved parent |
| `POST R/actions/{action}/preview` | `{context, ids, payload}` | `{action, scope, requested, eligible, skipped, warnings, groups, messages, fields, confirm_label, can_apply, receipt:{id, expires_at}}`; no domain mutation, enqueue or remote call |
| `POST R/actions/{action}` | `{context, ids, payload, confirmed: true, preview_id}` for previewed actions | `{status, rows, removed_ids, requested, accepted, skipped, failed, groups, messages}`; 200 completed/partial, 202 durably queued |
| `POST R/activity` | `{context, ids}` of visible/expanded rows | `{rows, removed_ids, observed_at, refresh_after_ms, messages}` from local state; no remote calls |
| `GET R/actions/{action}/fields/{field}/options` | Context, row id if required, search/cursor | `{options, next_cursor}`; only declared fields and authorized local providers |

The preview may store only its short-lived confirmation receipt; it never changes domain
records. `preview_id` is that receipt's opaque id. A missing/expired/mismatched receipt on
a preview-required action is a 409 requesting a fresh preview. A simple non-destructive row
action may run without a preview only when its PHP definition explicitly allows that.
The single-row path uses `ids` with one member and the same planner/runner as bulk; the
definition declares which actions allow one or many. Download actions resolve only registered,
guarded resources, with fresh authorization at download time. Existing shipping document
routes remain as described in the extraction section; do not proxy arbitrary URLs.

`groups` hold server-built reason text, count, fix label/URL and bounded example title/URL
pairs, compatible with Ozon's `Bulk_Validation_Report`/`Products_Bulk` output. `messages`
can contain success, warning and error together. `accepted` means actually saved/queued,
not remote completion; the server sentence makes that distinction explicit.

Minimal TypeScript needed across this boundary:

```typescript
type RowActivity = {
  locked: boolean;
  reason: string;
  blocked_actions: string[];
  processes: Array<{
    id: string; // opaque observation identity, never a claim token
    state: 'queued' | 'running' | 'waiting' | 'failed' | 'completed';
    label: string;
    completed: number | null;
    total: number | null;
    percent: number | null;
    text: string;
  }>;
  observed_at: string;
};
type AdminRecord = {
  id: string;
  revision: string;
  cells: Record<string, unknown>; // narrowed by the finite cell schema at runtime
  actions: Array<{ id: string; enabled: boolean; reason: string }>;
  selectable: boolean;
  activity: RowActivity;
};
```

Validation and permission rules apply to reads, previews, options, activity and writes:

- Authenticate WP REST cookie requests using the `wp_rest` nonce / `X-WP-Nonce`. A nonce is
  not capability authorization. Require the registered page/action capability and the
  provider's object/context checks. An authenticated alternative transport must receive
  the same authorization, not a nonce-based bypass.
- Validate owner/table/action, positive bounded pagination, sort enums, declared filters,
  flat distinct id list, input types and length before any planner/query. Unknown fields
  and unsupported operators fail with 400; invalid action fields use 422 with field ids
  and PHP messages. Apply the declared maximum on the server; do not slice requests.
- Resolve each selected id within its declared account/table, check object capabilities
  (`edit_product`, including variation-parent rules; orders need both `edit_shop_orders`
  and `edit_shop_order`) and compare stored account identity. Account existence alone is
  insufficient. An account forged alongside another account's row must fail before writes.
  Unknown/inaccessible resources use a consistent 404 policy without disclosing existence;
  a known authorized action refused by current lock/state uses 409.
- Reuse Ozon `Admin_Guard`'s policy and account lookup; factor a transport-neutral check for
  REST. Do not call its current `require_ajax()` from REST: it reads the classic page nonce
  and exits through `wp_send_json_*`. Retain classic nonce adapters for embedded screens.
- Preview is advisory. Apply replans with the same service and rechecks locks immediately
  before each write/enqueue. Business skips (already synchronized, now locked, no valid
  payload) return grouped reasons; authorization/schema failure rejects before processing
  the batch. Genuine per-item failures are reported separately from intentional skips.
- Repeated confirms do not duplicate work: provider runners reuse the existing task/hash,
  intent revision or compare-and-set semantics. JS disables submit while pending, but
  correctness also holds for duplicate HTTP requests. Do not automatically retry mutations
  after a network timeout; refresh activity first and present the PHP recovery message.
- Errors are WP REST `{code, message, data:{status, errors?}}`. Show PHP messages without
  reconstructing them from codes. SQL/transport details belong in redacted diagnostics.
  Private authenticated responses are not shared-cacheable.

Changing payload, selection or account invalidates a preview and requires a fresh preview.
If apply finds changed eligibility, preserve the existing planner's skip semantics; if the
action would expand its scope or increase an account-wide affected set beyond the confirmed
plan, return 409 with a fresh preview and ask for confirmation again. The implementation must
bind confirmed scope/upper bound to the authenticated user and normalized request (short-lived
server receipt), never trust client-supplied counts. This receipt is not a reservation or lock.

## Row lock and progress source

`get_activity()` is a local, batched read projection for the ids on screen. Ozon's adapter
joins task items and task state, link state, sync-state revisions/in-flight snapshots and
account claim state; the table engine knows none of their field names. It must also project
work started by cron, webhook, another manager or a previous browser session. Reading the
page or activity must not claim a job, poll the marketplace API or queue new work.

| Source | Projection and enforcement |
|---|---|
| Export task/items | Queued/submitted/polling means active even with no current worker lease. Show the server's phase label per record, and measured item counts only where meaningful. Block conflicting mutations through the existing export/link service as well as UI. |
| Stock/price send | Pending and unresolved `in_flight_rev` identify the affected link/kind/warehouse; show each active process. An HTTP acceptance is not completion for asynchronous receipts. Use the existing account-fenced flusher and pending-revision rules. |
| Account claim | Unexpired account claim blocks the conflicting action family on affected rows; supply a PHP reason. Do not treat an unrelated read as a mutation or freeze another account. The provider owns the conflict matrix. |
| Shipping editor | Keep `Order_Edit_Lock` and its heartbeat renewal/release behavior; expose its current disabled-action reason through the shipping adapter. Do not substitute marketplace claims for the existing editor lock. |
| Browser request | Immediately mark that row/action pending; reconcile from the returned activity. This transient state ends on response/error and does not release a durable lock. |

`locked` with `blocked_actions` disables conflicting action controls and the selection checkbox
where a selected bulk action cannot operate. Keep read-only details/links available when
authorized. Explain the lock with visible/accessibly associated PHP text, not color alone.
Multiple processes can coexist: a single percentage must not hide another unresolved send.
When no meaningful total exists, `percent`, `completed`, `total` are null and the indicator
is indeterminate; never manufacture elapsed-time percentages. Failures show server text and
only server-authorized recovery actions.

Projection does not introduce a second mutex. Every actual mutation uses the domain's existing
conditional writes/claim fencing; add a common missing eligibility check in that service if
needed, including callers from embedded screens and jobs. Keep the stock/price domain's
ability to record newer intents during a send; blocking a conflicting manual UI action must
not discard product-save changes. A live claim is a temporary execution lease; a task awaiting
remote settlement remains active after claim expiry. A stale claim never authorizes the
browser to clear an in-flight snapshot. Recovery uses the existing guarded tool/service.

One bounded refresh loop per mounted table covers visible rows and expanded children, using
PHP-supplied cadence. Stop when hidden/unmounted, abort obsolete reads on query/context change,
and refresh on visibility/focus return. After failures back off to the declared maximum;
mark state stale with PHP copy and keep conflicting controls disabled until refreshed. A
table without fresh activity on first load cannot offer mutations. No permanent polling
starts for `analytics: null`.

Use request generation and per-row operation ownership from the shipping application: an
old request cannot replace rows after account/filter/page navigation, a completed older
operation cannot clear a newer row's pending state, and a stale activity request cannot
overwrite a row just returned by apply. Invalidate overlapping reads on mutation; accept
replacement rows only for the current context, then refresh totals/facets as needed. Selection
clears on view change; select-all affects eligible visible rows only. Rows removed by a
changed state/filter are reconciled without retaining hidden selected ids.

## Extraction from shipping without delivery-plugin behavior changes

The shipping table is not PHP-driven end to end today. `app.tsx` builds headers, base bulk
actions, confirmation fallbacks and status presentation in JS; `filters.ts` names shipping
query keys. PHP already supplies row actions, action fields, provider identities, flags and
many result messages. Extract mechanics while giving new marketplace consumers a fully
PHP-declared contract. Move shipping declarations to a shipping PHP adapter only with parity
tests; do not claim that re-exporting `OrdersPage` creates the generic component.

| Existing source | Shared extraction | Stays shipping-specific |
|---|---|---|
| `app.tsx`: loading, request generations, row operation owners, selection, `ActionsCell`, bulk/dialog plumbing | `src/admin-records/`: data lifecycle, schema cell renderers, action/selection/progress shell | Shipping cells, carrier toolbar, order preview, wizard entry, printing/document flow |
| `filters.ts`, period picker, table sort/paging callbacks | Schema-based URL codec and control orchestration; retain omission versus false | Shipping URL param names, period semantics, advanced all/any matching, carrier/new-order scope |
| `rest.ts`, action input/modal helpers | Authenticated transport/error presentation; generic input schema where genuinely neutral | Existing route/envelope types, carrier field options, document and toolbar adapters |
| `Orders_Registry`, `Orders_Provider`, `Orders_Query`, `Order_Row_Builder`, `Order_Actions` | A shipping `Records_Provider` adapter calls these services and supplies the neutral projection | Provider marker/meta contracts, carrier registration, query scoping, status mapping, export/cancel rules |
| `Order_Edit_Lock`, `Export_Queue_Notice`, `exports-in-progress.tsx` | Neutral disabled reason/progress presentation where applicable | Existing edit-lock ownership/heartbeat and page-wide export count/notice |
| `src/components/records-table.tsx`, `record-rows.ts` | No wholesale extraction: separate JSON settings control | Stored JSON format, whole-settings Save, country sorting and main-row invariant |
| `src/shipping-orders-page/analytics/**`, analytics PHP classes | None needed for marketplace | Existing analytics tab, WooCommerce report registration, queries/cache remain working |

Retain WooCommerce's runtime `TableCard` through the existing `wc-components` handle and
ambient type bridge. Keep WordPress's bundled React/element and framework modal shell;
do not add another React or WooCommerce bundle. The upstream `TableCard` row API does not
offer arbitrary row attributes in the inspected bridge: preserve the current scoped busy-row
marker/CSS technique until verified, and render progress in an accessible cell. Do not invent
an unsupported `rowProps` API. Reuse the modal focus/close behavior; never `window.confirm()`.
These are reuse recommendations, subject to Q3's reference/compatibility gate.

Staged cutover:

1. Establish characterization cases on the current shipping routes and UI; introduce the
   neutral primitives and a fixture records provider without altering the shipping entry.
2. Make the shipping adapter supply PHP definitions/strings and convert existing REST
   results into neutral rows in its internal data adapter. Existing public routes and
   JSON envelopes remain byte-compatible; do not route clients to a renamed endpoint.
3. Adopt the shared interaction primitives inside the shipping shell. Specialized shipping
   renderers remain an explicit internal slot, not marketplace schema types. Keep existing
   JS imports/entry registration paths until the bundle migration is verified.
4. Preserve `/woodev-shipping-orders`, carrier/scope/date/filter query params, legacy page
   redirects, `woodev/v1/shipping/orders` routes, all `woodev_shipping_*` hooks and current
   capabilities. Keep `[shipping] includes()` and generated build wiring consistent.
5. Verify all six live delivery plugins from their current release contracts: carrier counts
   follow filters; HPOS and CPT lists stay scoped; single/bulk export/update/cancel, edit-lock
   skips, wizard save, action fields, toolbar dialogs, asynchronous single/bulk documents,
   export heartbeat and both analytics entry points behave as before. Include third-party
   extra actions and absent optional sources. No plugin gets a new required method.

No marketplace analytics module is extracted as a side effect. Shipping's already shipped
analytics is explicitly preserved. If extraction cannot preserve one shipping interaction,
keep that interaction in the shipping shell and extract a smaller primitive.

## Settings mounts under plugin menus

Today `Settings_Page_Registry::register_page()` calls `add_submenu_page( 'woodev', … )`,
`get_tab_url()` always builds `admin.php?page=woodev-settings&tab=…`, and the React app mounts
in `#woodev-settings-app`. Schema/values arrive from `woodev/v1/settings`; providers declare
sections/actions and existing stores own saving. Change placement and hosting, not that model.

```php
// Woodev\Framework\Settings
final class Settings_Page_Mount {
    public static function marketplace( string $menu_id, string $page_id, string $path ): self;
}

// Additive methods on Settings_Page_Registry; existing methods remain valid.
public function mount_plugin( \Woodev_Plugin $plugin, Settings_Page_Mount $mount ): void;
public function get_mount_tabs( string $mount_id ): array;
public function get_provider_url( string $provider_id ): string;

Settings_Page_Registry::instance()->mount_plugin(
    $this,
    Settings_Page_Mount::marketplace( 'ozon-seller', 'ozon-settings', '/woodev-ozon-settings' )
);
```

Call after the base class's existing `register_plugin( $this )`, before menu registration.
The mount id is the registered page id. One placement per plugin's provider set; absent mount
means the existing `woodev` default. Providers are still registered once through the base;
`mount_plugin()` changes their placement metadata, not their identity or persistence. Registry
collection must be invalidated if a mount is attached after entries were cached. Reject a
mount owned by another plugin or repeated conflicting mounts. Do not duplicate Ozon tabs in
Woodev. The marketplace registry adds the settings child once, using the settings mount;
the default registry registers the classic Woodev wrapper only for default-mount providers.

Reuse the existing settings React body as an embeddable component for the wc-admin settings
route, with explicit bootstrap props (`mount`, `restRoot`, `nonce`, PHP string map) rather than
another hardcoded DOM id/global. Keep the existing standalone mount for default Woodev users.
Generic page routing chooses the settings component from the PHP manifest. For the new reusable
mount, move common UI strings into the PHP string map; preserve current fixed-page behavior
while eliminating JS-owned marketplace copy.

`GET /settings?mount=ozon-settings` returns only authorized providers placed there. Omitted
mount lists the default Woodev set. Existing provider detail/save/tool routes retain their
paths, provider identifiers and capability checks; mount cannot grant access. If supplied,
validate provider membership too. Keep provider/action authorization on every handler even
when a user knows a hidden tab id. Default mount capabilities still use the existing per-provider
WooCommerce/neutral resolution; marketplace parent visibility never grants settings writes.

Make `get_tab_url()` delegate to the placement-aware URL resolver; update its callers,
including `Woodev_Plugin::get_settings_url()`, required-settings notices, plugin action links,
tools and wizard links. Old default URLs continue to work. An old Ozon
`woodev-settings&tab=<provider>` deep link redirects to its mount with the tab/section intact,
only after capability checks; preserve existing unrelated legacy redirects. Option names,
account ids, encrypted keys, validation, secret masking and Save behavior are unchanged.

## Ozon Seller migration

1. Complete framework extraction and contract tests, release through the ordinary feature
   version process. Do not raise `VERSION` in this design PR. Record the first containing
   release before changing Ozon's bundled framework and loader compatibility floor. Remove
   Ozon autoloader handling and duplicate files for `Woodev\Marketplace`; classes must come
   only from the winning framework. Test mixed copies/activation order without Composer.
2. Replace classic `Admin_Menu` registration for stand-alone pages with the framework
   marketplace registry. Preserve legacy public admin slugs through guarded redirects to
   the canonical wc-admin paths. Keep embedded product tab/order metabox handlers and their
   nonce contracts. Redirects preserve account/tab/filter context where it has an equivalent.
3. Replace the classic `Products_Page`/`Site_Products_Table` presentation with a PHP records
   provider and the generic React table. Reuse `Site_Products_Reader` and
   `Site_Products_Filter` for account-scoped WC queries, variable-product matching, inherited
   stock and cached remote values. Add sort support in that query service where declared;
   do not client-sort a single fetched page as if it were the whole dataset.
4. Reuse `Products_Bulk::preview()` / `apply()`, export validation, `Bulk_Validation_Report`,
   existing export enqueue/seeder/link scheduler and warehouse validation. Translate their
   envelopes at the REST boundary; do not implement a JS planner. Add strict REST guards,
   process projection and confirmed-scope receipt. `link_by_article` remains account-wide
   and its preview must make that scope explicit; other selection actions keep their scope.
5. Keep `site` and `ozon` tab ids, default-to-site for unknown tabs, and the
   `woodev_ozon_seller_products_tabs` extension seam. Evolve tab objects to supply a records
   provider/schema instead of `render()`; do not keep classic HTML as a React tab body.
   Update the merged `Ozon_Cards_Tab`, queries/resolver/cache, import/link actions and any
   consumers of that filter in the same plugin card. A domain refresh is an explicit
   guarded job/action; a table GET never fetches the remote catalogue.
6. Keep classic AJAX action names where they are installed-site contracts or still have
   classic callers, using thin adapters to the same services. Remove the obsolete
   `products.js`, CSS and HTML rendering only once no caller needs them. This does not
   authorize deleting installed hook/action names during a UI rewrite.
7. Build W4-2 orders directly as a React records provider, not another `WP_List_Table`.
   Reuse the existing order registry/writer, posting store, label jobs/download guard and
   cancellation reasons; require HPOS/CPT parity. Labels remain a registered download/job
   interaction, not a generic JS remote call.
8. Convert mappings/profiles, diagnostics and tools to PHP-driven React custom views using
   existing PHP input parsers, query services and action runners. Reuse guards for every
   endpoint; preserve logging-off diagnostic visibility and redacted reports. Mount the
   existing settings providers beneath OZON Seller and update spec/wiki to match the new UI.

The classic products screen is replaced, not retained as an operator-facing alternative.
No migration step renames persistent option/meta/table keys, REST intake namespaces, cron
hooks, account/link/task identities or licence instances. New admin REST routes are additive.

## Verification and open questions

Implementation evidence includes PHP 7.4 syntax/static analysis, unit/contract tests, Jest
interaction tests, TypeScript checks, i18n gates, production bundle parity and real WC-admin
browser passes. Important cases: two accounts with the same product id, another manager's
claim, queued work after lease expiry, uncertain stock/price sends, duplicate confirms,
account change during a slow request, missing/failed activity, navigation Back/Forward,
partial bulk failures, expired preview receipt, no JS-only eligibility and no analytics calls.
Check keyboard access, disabled-action explanations, modal focus restoration, live progress
announcements and the filter row at laptop widths; retain the scoped vendor width correction
described in [the shipping filter gotcha](../gotchas/woocommerce-gives-its-filter-picker-a-fixed-430px.md).

- **OPEN QUESTION Q1 — menu presentation.** The operator has chosen the menu and six child
  screens, not supplied final artwork or a preferred landing screen/order. Recommendation:
  root opens Товары, use the order stated in #1675, and review the plugin's existing icon
  at WP sidebar size. Confirm these presentation choices with the operator; examples above
  do not settle them. Routing/menu-highlight behavior is mandatory regardless of the choice.
- **OPEN QUESTION Q2 — variable products in the React table.** Recommendation: preserve the
  current parent row with expandable variations, lazy bounded child pages and per-child
  activity. The generic contract supports children, but choose the precise row layout and
  progress placement with the operator before product UI work. Parent/child selection must
  normalize to distinct domain targets so a variation is never processed twice.
- **OPEN QUESTION Q3 — supported component versions.** The local WC donor path and Context7
  were unavailable, so upstream/installed sources support a reuse recommendation, not a
  supported-version guarantee. Recommendation: retain shipping's existing TableCard/modal
  stack, consult Context7 when available and test the declared minimum WP/WC plus current
  WC for top-level navigation/highlighting, SPA settings mount, intrinsic labels and progress
  accessibility. Do not raise minimum versions or add DataViews silently; any demonstrated
  incompatibility needs a separate decision with the measured failing case.

## Related

- [ADR-013](../adr/013-marketplace-layer-and-react-admin-in-the-framework.md) — ownership decision
- [Architecture](architecture.md) — current module and settings responsibilities
- [Agent rules](../AGENT-RULES.md) — Rule 9 and preservation rules
- [ADR-007](../adr/007-react-admin-stack-wordpress-scripts.md) — runtime/build conventions
- [ADR-012](../adr/012-shipping-includes-stays-authoritative.md) — include wiring during extraction
- [Schema](../DOCS-SCHEMA.md) — internal documentation conventions

## Implementation card plan

These are filing briefs for the coordinator, not newly filed or authorized implementation
cards. Check existing framework #1670 and related donor gap cards before creating duplicates;
reuse/update a settled matching card. S/M/L describe relative delivery size, not elapsed time.
Every code card carries normal framework/plugin checks, review and relevant author-skill updates.
No card implements marketplace analytics.

| Order / id | Size | Dependencies | Scope and acceptance |
|---|---|---|---|
| 1 · F1 — Transfer marketplace module | L | ADR review; reconcile #1670 | Transfer the full refreshed inventory and portable tests; enforce dependency/naming scanner, Composer and generated runtime maps; donor parity, PHP 7.4 and no-Composer/two-copy boot pass; no table, cipher or queue behavior changes. |
| 2 · F2 — Marketplace menu/page registry | M | ADR review; Q1 presentation before final UI | PHP manifest and wc-admin root/child registration, owner isolation, generic client routing and custom-view registration; own icon/menu, six children, correct highlight and direct/SPA navigation, explicit capabilities, no duplicate menu/bundle with two marketplace plugins. Resolve Q3's routing reference/version checks here. |
| 3 · F3 — Scoped settings mounts | M | F2 | Add mount API, embedded settings body and PHP strings; own-menu settings only, default Woodev providers unchanged, list/schema scoping and per-provider save/tool guards; generated and legacy links resolve correctly; secret masking/storage/Save unchanged. |
| 4 · F4 — Records PHP schema and REST | L | ADR review; F1 only for marketplace fixture | Implement finite descriptors, owner registry, authorization and strict query/action/option contracts; fake independent providers prove no shipping/marketplace coupling; forged account/id, invalid filters/sorts and oversized lists rejected before work. |
| 5 · F5 — Generic records React shell | L | F2, F4 | Extract mechanical table/filter/sort/page/request/selection primitives; render PHP cells/strings/actions, URL round trips, stale-read protection, error/empty states and keyboard access; no domain-code switches or new table dependency. Resolve Q3's TableCard/label checks. |
| 6 · F6 — Preview and action interactions | M | F4, F5 | Input schemas, row actions, bulk report/confirm and guarded downloads; confirmed-scope receipt, replan and duplicate-submit behavior; preview changes no domain state, account-wide scope explicit, partial results retain server reasons, changed scope requires new confirmation. |
| 7 · F7 — Activity/lock/progress projection | M | F4, F5; F1 for marketplace fixture | Batched local activity adapter, bounded refresh and row indicators; export queued/polling, stock/price in flight and account claim tested; unseen-origin work/reload works, stale state disables conflicts, read links remain available, no API polling or fake percentages. |
| 8 · F8 — Shipping adoption and parity | L | F5–F7 | Shipping PHP/internal REST adapter and primitive adoption; characterize and verify six current delivery-plugin contracts, HPOS/CPT, hooks/URLs, extra actions, fields, documents, wizard, edit locks, heartbeat and existing analytics; no required carrier API changes, map/eager-list/build/i18n gates green. |
| 9 · O1 — Adopt framework module/menu | M | F1, F2 and released containing framework version | Update bundle/compatibility floor, delete duplicate layer/autoload ownership, register pages and canonical/legacy links; mixed-copy activation works and persistent contracts untouched; coordinate with O2/O3 so root has no dead landing page. |
| 10 · O2 — React products, both tabs | L | O1, F4–F7, Q2 | Replace classic screen with generic providers; reuse reader/filter, bulk planner/report, export/seeder and remote-card resolver/import/link logic; evolve tab registry and callers, handle variations/locks/progress, prove cached list reads, guarded refresh and account-wide linking scope; remove obsolete presentation only. |
| 11 · O3 — W4-2 orders in React | L | O1, F4–F7; existing posting/label services | Implement original orders requirements with provider/PHP schema, filters, date/sort/page and per-row activity; label tasks/download, cancel reasons, banner and both order stores pass; no classic stand-alone orders screen. |
| 12 · O4 — React mappings and profiles | L | O1, F2; F4/F5 for list portions | Keep merged mapping/profile input and validation/query services; PHP fields/options/actions drive custom React editor and validator report, category uniqueness/inheritance and guarded writes remain; embedded product tab unaffected. |
| 13 · O5 — React diagnostics | M | O1, F2 | PHP operational/report projection and custom React view; logging-off exposes only allowed operational state, logging-on enables existing diagnostic sections, copy/report remains redacted, guard/expiry/licence/migration states covered. |
| 14 · O6 — React tools | M | O1, F2, F6/F7 interaction primitives | Reuse existing tools and job runners; PHP-defined account choice, preview/confirmation, result and progress; whole-catalogue jobs remain paged/background, overwrite/reset/migration actions guarded; no JS business rules. |
| 15 · O7 — Settings under OZON Seller | M | O1, F3 | Mount existing providers, update notice/wizard/tool links and spec/wiki; no duplicate Woodev tab, old tab links redirect, accounts/secrets/values and tests preserved; confirm Q1 artwork/ordering without inventing new settings. |

F1 and F2/F4 can start independently after review. F8 is the delivery-regression gate for
releasing the shipping cutover; O2/O3 can develop against reviewed generic contracts before
that release, but O1 ships only with a released containing framework. O4–O7 may proceed in
parallel once their listed foundations exist. Each plugin card must also update the superseded
spec sections/wiki for its screen and retain classic embedded-screen behavior.
