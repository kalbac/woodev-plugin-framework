# Marketplace module and PHP-driven React admin

> Design for framework #1675, round 2 (2026-11-10). Decisions: [ADR-013](../adr/013-marketplace-layer-and-react-admin-in-the-framework.md).
> Proposed APIs below do not exist yet. Implementation is separate, after design review.

## Scope and reference baseline

Every stand-alone marketplace page is React and PHP-driven ([Rule 9](../AGENT-RULES.md)):
Настройки, Товары, Заказы, Сопоставление, Инструменты, Диагностика. PHP owns definitions,
permissions, business decisions and strings; TypeScript owns reusable interaction. Screens
embedded in WooCommerce's own UI — the product edit tab, the variation panel, the order
metabox — stay classic. Marketplace settings live under the marketplace's own menu.
Analytics is a reserved slot only.

Binding operator decisions this round implements (PR #1676 comments, 2026-11-10):

| # | Decision |
|---|---|
| B1 | `\Woodev\Framework\Marketplace\Marketplace_Plugin extends \Woodev\Framework\Woocommerce_Plugin`, like `Shipping_Plugin` / `Woodev_Payment_Gateway_Plugin`; the base class owns menu, pages and settings registration through `wc_admin_register_page()` without `parent`. |
| B2 | Namespace `Woodev\Framework\Marketplace\*`. |
| B3 | Delivery is not touched: «Заказы доставки» stays where and as it is; the records table is a new component that only takes it as a reference. No card changes `src/shipping-orders-page/**` or `woodev/shipping-method/**`. |
| B4 | Menu icon = the Ozon Seller v1 icon; landing page «Товары»; order Настройки / Товары / Заказы / Сопоставление / Инструменты / Диагностика — superseded by #1704: Настройки first only while the plugin is not configured, last once it is. Variable products = parent row with expandable variations, each with its own state and progress. Q3 as the R1 critic ruled. |
| B5 | Analytics: reserved slot only. Multi-account is not dropped. |
| B6 | (s225) The module holds **all** logic that is not specific to one marketplace — accounts, sync/queue/governor, links, mappings, records table, menu/pages/settings, diagnostics, lifecycle — built like the Shipping and Payment modules. The plugin keeps only its adapter, API client and marketplace-specific rules. |

References read for this round:

- Framework `1935ca99` through Serena (activated on this worktree): `Woocommerce_Plugin`,
  `Shipping_Plugin` (constructor, abstract `get_shipping_method_classes()` / `get_api()`,
  `get_settings_url()`), `Woodev_Payment_Gateway_Plugin`, `Woodev_Plugin::init_settings_page()`
  and `get_settings_url()`, `Settings_Page_Registry` (`get_tab_url()`, `collect_entries()`,
  `register_page()`, `maybe_redirect_legacy()`), `Orders_Registry::register_page()` /
  `build_page_args()` and the [shipping orders page](../../src/shipping-orders-page/app.tsx)
  file set (reference only).
- Ozon Seller, read-only: `origin/main` `5d00c10` (four merges after the R1 critic's
  `7abfd0e`; the marketplace layer is unchanged) and the released tag `v1.1.7`.
- WooCommerce `PageController::register_page()`: with no `parent` it calls `add_menu_page()`
  with `icon` and `position` (verified by the operator and by the R1 critic). Context7 was not
  consulted this round; Q3's version gate below covers what it would have answered.

This page supersedes the Ozon spec's §7.1 option B and its §8 classic stand-alone screens and
Woodev → Настройки placement. Their domain requirements stay.

## Module shape — the Shipping and Payment pattern

| | Shipping | Payment | Marketplace (this design) |
|---|---|---|---|
| Base plugin class | `Woodev\Framework\Shipping\Shipping_Plugin` | `Woodev_Payment_Gateway_Plugin` | `Woodev\Framework\Marketplace\Marketplace_Plugin` |
| What the plugin implements | `get_shipping_method_classes()`, `get_api()`, carrier `Shipping_Method` subclasses, `Orders_Provider` | gateway subclasses, API request/response classes | `get_marketplace_adapter()` returning a `Marketplace_Adapter`, `get_marketplace_menu()`, page list, API client, wire mappers |
| What the framework owns | checkout, pickup, export, documents, orders page, settings sections, REST | checkout flow, tokens, admin handlers, REST | accounts, persistence, claim/run model, jobs, inbound intake, sync flows, settings, diagnostics, embedded screens, every stand-alone page |
| Folder | `woodev/shipping-method/` | `woodev/payment-gateway/` | `woodev/marketplace/` |

```text
Ozon plugin:  Plugin extends Marketplace_Plugin
              Ozon_Adapter implements Marketplace_Adapter
              Ozon_Client (Woodev_API_Base), mappers, Ozon-only rules, v1 migration
                         |
                         v
woodev/marketplace/  Marketplace_Plugin ── pages/menu/settings/assets (admin/)
                     infrastructure: stores, schema, jobs, intake, settings, diagnostics
                         |                     |
                         v                     v
                     domain folders        Woodev\Framework\Admin\Records\*  (generic,
                     (pure PHP + WC_*/wpdb) src/admin-records/                not marketplace)
```

Infrastructure classes depend on the domain contracts and on WordPress; they never import a
concrete marketplace. The records engine knows neither marketplace nor shipping field names.

### Framework folder layout

The card file sets below use these folders. Domain folders keep the donor's names; the
infrastructure folders are new and sit outside the dependency scanner's scope.

| Folder (`woodev/marketplace/…`) | Namespace (`Woodev\Framework\Marketplace\…`) | Holds | Card |
|---|---|---|---|
| `account/`, `capabilities/`, `catalog/` (+ `rules/`), `diagnostics/`, `governor/`, `ledger/`, `links/`, `orders/`, `price/`, `stock/` | same segment names | domain values and contracts (scanned) | F1 |
| `class-marketplace-plugin.php`, `admin/` (collector, manifest, legacy redirects) | root, `Admin` | base class and page registration | F2 |
| `data/` | `Data` | schema, lifecycle, uninstaller, `Wpdb_*` stores, persistence exception | F8 |
| `jobs/` | `Jobs` | run model, licence gate, schedulers, runners, event drain and handler registry | F9a, F9b |
| `intake/` | `Intake` | inbound notification intake and its REST controller | F9a |
| `sync/` | `Sync` | stock and price flushers, intents, readback, accounting, refresh guard | F9a |
| `flows/` | `Flows` | export service, link writing/detaching/matching, labels, order query/store | F9b |
| `settings/` | `Settings` | settings handler and sections | F10 |
| `admin/guard/`, `admin/notices/`, `admin/diagnostics/`, `admin/shared/` | `Admin\…` | admin guard, key-expiry notices, diagnostics report, code-to-sentence mappers shared by several screens | F10 |
| `admin/product/`, `admin/order/` | `Admin\Product`, `Admin\Order` | embedded classic product tab and order metabox | F11 |
| `admin/pages/{products,orders,mappings,tools,diagnostics}/` | `Admin\Pages\…` | records providers and custom-view endpoints | F12a–F15 |

Client code: `src/marketplace-admin/` (routing bundle, built to
`woodev/assets/build/marketplace-admin/` by a new `package.json` build step) and
`src/admin-records/` (the generic table library it imports). PHP tests go to
`tests/unit/Marketplace/<Folder>/` and `tests/unit/Admin/Records/`, JS tests to
`tests/js/marketplace-*.test.ts(x)` and `tests/js/admin-records-*.test.ts(x)`. Every new PHP
file appears in `woodev/class-map.php` (regenerated) and `composer.json`'s development
classmap.

## Classification of the Ozon plugin (`5d00c10`)

Rule for every move: retype from the Ozon concrete (`Ozon_Account_Store`, `Ozon_Link_Store`,
`Ozon_Error`, `Ozon_Client`) to the framework contract; every marketplace rule found inside a
generic class becomes an adapter method (example: `Ozon_Export_Service::queue()` skips
virtual and downloadable products because "Ozon sells goods that ship" — that is an adapter
eligibility answer, not a framework constant). No behaviour changes beyond that.

| Ozon source | Goes to | Notes |
|---|---|---|
| `includes/marketplace/**` (85 files, all folders) | framework, domain folders | Move + rename (F1), **minus the marketplace semantics listed in "Marketplace semantics in the domain layer"**: those become adapter-supplied policy, the mechanics stay. |
| `jobs/` run model: `Account_Run`, `Claim_Lost`, `Job_Gate`; `ozon/account/` `Claim_Fence`, `First_Remote_Write`, `Account_Pause` | framework | Pause trigger = adapter error classification. The v1 write gate in `Job_Gate::allows_writes()` becomes a plugin-supplied gate (see v1 migration). |
| `jobs/` schedulers and runners: export batch/run, poll task, link jobs, flush sync, price flush/recompute/refresh, refresh catalog, posting jobs, drain events, handler registry, `Event_Handler`, noop handler | framework | Runners call the adapter for the remote half (card exporter, card lifecycle reader, link resolver, pushers, readers, catalog refresher, order source). `Tree_Changed_Handler` is Ozon's event type: plugin. |
| `lifecycle/` `Schema`, `Lifecycle`, `Uninstaller`, `Schema_Exception`; `class-persistence-exception.php` | framework | One table set per marketplace plugin, prefix `woodev_{marketplace id}_` (Ozon keeps `woodev_ozon_`). `V1_Migration` stays plugin. |
| `ozon/account/Ozon_Account_Store`, `ozon/links/` stores and values (`Ozon_Link_Store`, `Link_Record`, `Link_Detacher`, `Card_Linker`, `Link_Progress_Store`, `Link_Report_Store`, `Pinned_Offer_Id`, `Shop_Sku_Source`) | framework `Wpdb_*` stores | Column and enum names become neutral where they are Ozon-named (`client_id`, `api_key_enc`, `to_ozon`, stock mode `ozon`). Allowed: v2 is unreleased. |
| `ozon/catalog/` stores and generic logic: `Mapping_Store`, `Profile_Store`, `Profile_Sources`, `Product_Field_Catalog`, `Category_Ancestry`, `Mapped_Pairs`, `Export_Context(_Builder)`, `Export_Price_Policy`, `Link_Context_Resolver`, `Cards_Snapshot_Store`, `Catalog_Cache`, `Card_Created_Seeder`, `Card_Matcher`, `Ozon_Export_Service` (as `Export_Service`), `Refresh_Result`, `Link_Resolve_Exception` | framework | Ozon card shape stays out: `Ozon_Card_Builder`, `Ozon_Card_Lifecycle`, `Ozon_Catalog_Schema`, `Ozon_Validation_Context`, `Ozon_Link_Resolver`, `Catalog_Fetcher`, `Catalog_Refresher`, `Category_Tree`, `Card_Images` stay plugin behind adapter contracts. |
| `stock/**`, `ozon/price/` `Price_Intents`, `Price_Refresh_Guard`, `Price_Refresher`, `Refresh_Result` | framework | `Ozon_Stock_Pusher`, `Ozon_Price_Pusher`, `Ozon_Price_Reader` stay plugin. |
| `ozon/labels/` `Label_Store`, `Label_Download`, `Label_Jobs`, exceptions | framework | `Label_Service`'s remote calls become an adapter label provider (`Capability::GET_LABEL`). |
| `ozon/notifications/` intake side: `Ozon_Notification_Intake` (as `Notification_Intake`), `Intake_Response`, `Push_Rate_Limiter`, `Notification_Url`, `Notification_Account(_Reader)`, `Last_Notification_Reader`, `Registration_Refresher`, `Account_Read_Exception`; `rest/Notifications_Controller` | framework | Route `woodev/v1/marketplace/{marketplace id}/notifications` or the existing path, decided in F9a; the decoder (`Ozon_Event_Decoder`), push types, source-IP policy, `Ozon_Notifications` registration API and `Notification_Entry` stay plugin. `compat/Legacy_Webhook_Route` (v1 URL) stays plugin. |
| `orders/` `Order_Query`, `Wc_Order_Store`, `Order_Meta_Contract`; `Cancel_Ajax`, `Cancel_Modal` | framework | The cancel metabox is an embedded classic screen; reasons and the remote cancel come from the adapter (`Capability::CANCEL_ORDER`). `ozon/orders/` posting mapper/source/refresher/catch-up/canceller/reasons/meta stay plugin. |
| `settings/**` | framework | Accounts sections render adapter-declared credential fields; the notifications section renders only when the adapter supports push. |
| `diagnostics/**` | framework | Ozon endpoint facts in `Diagnostics_Page` become an adapter `Report_Section` (already in the contract). |
| `admin/` `Admin_Guard`, `Key_Expiry_Notices`; `admin/product/**`; `admin/products/` reader, filter, bulk planner, state badge, remote-cards query/tab logic; `admin/mappings/` inputs, check, products, AJAX logic | framework | Re-expressed as records providers, REST handlers and custom views; the classic HTML/`WP_List_Table` classes are deleted, not moved. `Ozon_Category_Finder` becomes a framework search over `Catalog_Schema` unless the adapter needs a remote search. |
| `admin/` `Admin_Menu`, `Admin_Page`, `Admin_List_Table`, `Placeholder_Tab`, `Products_Tab`, all views and `products.js` | deleted (O4; `Admin_Menu` hangs the classic pages under the new root from O1 until then) | Replaced by the base class and the React pages. |
| `licensing/License_Gate` | framework | The licence gate is the default for every plugin; jobs consult it through the base class. |
| `class-*-module.php` wiring | framework base class | The base wires the flows from the adapter, as `Shipping_Plugin::includes()` / `add_hooks()` do; the plugin overrides factories only. |
| `ozon/api/**`, `Ozon_Client_Factory`, `Ozon_Credential_Health`, `ozon/orders/**`, the Ozon-shaped catalog classes above | plugin | The adapter half. |
| `migration/**`, `lifecycle/V1_Migration`, `compat/**` | plugin | v1 data is Ozon's; the migration writes through the framework stores and supplies the write gate. |

The table is a classification, not a file list: each transfer card refreshes it against the
donor `main` it starts from and accounts for every added symbol.

## Adapter contract

The existing `Marketplace_Adapter` (`id`, `supports`, `credential_health`, `stock_pusher`,
`price_pusher`, `order_source`, `catalog_schema`, `card_exporter`, `inbound_decoder`,
`bucket_specs`) stays the single object a plugin hands the framework. Moved flows need more
answers; each is added in the card that moves its consumer, nullable where a capability may
be absent (the pattern `price_pusher(): ?Price_Pusher` already uses):

| Consumer that moves | Adapter answer it needs |
|---|---|
| Accounts store and settings | credential field definitions (added in F8 as `credential_fields()`); their labels and validation and the display name of the external id (F10) |
| Run model | error classification (auth refused → pause, throttled, retryable, permanent) |
| Export / poll runners | card lifecycle reader for import results; export eligibility of a product |
| Link jobs, remote-cards tab | link resolver (remote card listing by article/page) |
| Catalog refresh | catalog refresher |
| Price refresh | price reader (`from_marketplace` direction) |
| Labels, cancel | label provider, cancel reasons and canceller |
| Notifications | registrar, source policy, push type list |
| Orders | order meta contract (already an interface in the plugin) |
| Price calculation and its validation (F1) | price sources the profile may name beyond the neutral ones, with their validation — Ozon's `field:KEY` «цена для Ozon в поле товара» |
| Price send queue (F1) | sync cadence: minimum interval between accepted sends of one product, attempt cap |
| Article (F1) | article policy: maximum length, normalisation, fallback for a product without SKU |
| Export validator (F1) | the rule set and its order (the framework ships the rule classes) |
| Stock push (F1) | which shop-truth formula is in force (`wc_only` or `wc_plus_reserved`) |
| Every page | merchant-facing marketplace name and menu icon |

Adapter methods return domain contracts, never arrays shaped for one screen. Strings the
adapter supplies are already translated in the plugin's text domain.

## `Marketplace_Plugin` base class

PHP 7.4 sketch. Like `Shipping_Plugin`, the constructor calls the parent, then its own
`includes()` / `add_hooks()`; registration methods are private.

```php
namespace Woodev\Framework\Marketplace;

abstract class Marketplace_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

    /** The plugin's adapter (as carriers supply get_api()). */
    abstract public function get_marketplace_adapter(): Capabilities\Marketplace_Adapter;

    /** Menu root: id, title, icon, position, capability. */
    abstract protected function get_marketplace_menu(): array;

    /** Pages after «Настройки», in menu order. */
    protected function get_marketplace_pages(): array { /* the framework's five pages */ }

    /** Plugin React views for `custom` pages: handle => script handle. None by default. */
    protected function get_marketplace_views(): array { return []; }

    final public function get_marketplace_page_url( string $page_id, array $query = [] ): string;

    /** Its own «Настройки» page; overrides Woodev_Plugin::get_settings_url(). */
    public function get_settings_url( $plugin_id = null ): string;
}
```

Ozon then declares data only:

```php
protected function get_marketplace_menu(): array {
    return [
        'id'         => 'ozon-seller',
        'title'      => __( 'OZON Seller', 'woocommerce-ozon-seller' ),
        'icon'       => $this->get_plugin_url() . '/assets/img/icon_square.svg', // v1 icon, restored from v1.1.7
        'position'   => '55.63',                                               // v1 position
        'capability' => 'manage_woocommerce',
    ];
}
```

`get_marketplace_pages()` defaults to the framework's five pages — `products`, `orders`,
`mappings`, `tools`, `diagnostics` — each a `records` or `custom` descriptor whose providers
the framework builds from the adapter. A plugin overrides it only to drop a page its
marketplace cannot support (by capability) or to add one. Descriptor keys: `slug`, `title`,
`kind` (`records` with table ids, or `custom` with a view handle), optional `capability`,
optional `legacy_page`, and `analytics => null`.

What the base owns, once per request and in one order for every marketplace:

1. **Root.** `wc_admin_register_page()` with **no `parent`**, the menu's `icon`, `position`,
   explicit `capability` and a plain `page_title`. Its `path` is `/{menu id}`: a container with
   its own slug that no page shares, the way WooCommerce's `woocommerce` root is a container
   (#1704). The root is never a destination; WordPress links it to the first entry of its
   submenu. No seventh dashboard page.
2. **Children**, `parent` = the root's registered id, registered in menu order. The order
   depends on `Marketplace_Plugin::is_configured()`: **not configured** → «Настройки» first (the
   root opens the settings, an onboarding), then Товары / Заказы / Сопоставление / Инструменты /
   Диагностика; **configured** → Товары / Заказы / Сопоставление / Инструменты / Диагностика /
   «Настройки» last, and the root opens «Товары». «Настройки» exists only when
   `get_settings_providers()` is non-empty. Paths are built by the base as `/{menu id}/{slug}`,
   so PHP and the JS manifest cannot disagree.
3. **Duplicate root submenu.** WordPress adds the root as its own first submenu entry as soon as
   the first child is added. The base **removes** it, the WooCommerce way
   (`WC_Admin_Menus::menu_order_count()` → `unset( $submenu['woocommerce'][0] )`): on
   `admin_menu` priority 99, after every registration and before core reads the first entry for
   the root's link (`wp-admin/includes/menu.php`), `Admin_Collector::clean_up_submenus()` drops
   the entry that carries the root's slug. Nothing is hidden by CSS and no `$submenu` entry
   carries a marker class.
4. **Icon sizing.** An image-URL icon needs the 18 px rule v1 printed for
   `.toplevel_page_…` (v1 `load_menu_styles()`); the base prints it for its own root only.
5. **Manifest and routing.** PHP emits a capability-filtered page manifest (id, path,
   breadcrumbs, kind, table ids or view handle, settings flag). One framework bundle reads it
   and adds the routes through `woocommerce_admin_pages_list`. Plugin views register through
   an `@wordpress/hooks` filter of that bundle; PHP never sends JS source.
6. **Assets.** The framework bundle is enqueued on every registered wc-admin screen of the
   menu (SPA navigation reaches siblings), from the winning framework copy, once even when two
   marketplace plugins are active — an internal static collector, like
   `Orders_Registry::instance()`. Plugin view scripts are enqueued alongside, by handle.
7. **Providers.** Records providers of the declared pages are registered with the records
   engine under the plugin id when REST routes initialise.
8. **Legacy URLs.** Only released v1.1.7 URLs: a page descriptor's `legacy_page` (for Ozon
   `page=ozon-seller` and `page=ozon-seller-products` → Товары, `page=ozon-seller-orders` →
   Заказы) is matched with the same parsed-argument semantics as
   `Settings_Page_Registry::maybe_redirect_legacy()`, after the capability check. The v1
   settings link `wc-settings&tab=ozon-seller` is redirected by the existing settings mechanism
   (`Settings_Provider::get_legacy_page()`) to the placement-aware tab URL. Unreleased v2 slugs
   (`woodev-ozon-*`, the Woodev settings tab) get no redirect.
9. **Missing wc-admin.** If WooCommerce Admin is unavailable the base shows a PHP-owned
   notice; no classic fallback replaces the React screens.

### `Marketplace_Plugin` as built (F2, #1680)

What the code settled, where the sketch above left it open:

- **Files.** `woodev/marketplace/class-marketplace-plugin.php`; `woodev/marketplace/admin/`
  `class-page.php` (a checked page descriptor), `class-menu.php` (one plugin's menu: the
  `wc_admin_register_page()` arguments, paths, manifest, CSS, legacy matching — it never calls
  WooCommerce, so it is unit-testable) and `class-admin-collector.php` (the per-request
  singleton that registers every menu and enqueues the single bundle); client code in
  `src/marketplace-admin/`, built to `woodev/assets/build/marketplace-admin/`.
- **Duplicate root entry is removed (#1704).** The copy WordPress inserts carries the root's own
  slug (`wc-admin&path=/{menu id}`, a container no page shares), so `Menu::remove_root_entry()`
  finds it by slug and drops it (`unset`, keys kept, as WooCommerce does) at `admin_menu`
  priority 99. The root's link is then the first real page (`_wp_menu_output()`). This replaced
  the s225 hidden entry (class `woodev-marketplace-root-entry` + a `display:none` rule) and the
  s224 «Настройки always first» order.
- **`is_configured()` decides the order.** `Marketplace_Plugin::is_configured()` is true when at
  least one account of the adapter has the identifier and every secret that
  `Marketplace_Adapter::credential_fields()` declares (the merchant's switch and the last health
  check do not count). It reads the F8 accounts table through `get_account_store()` (secrets
  opened with the `secure_auth` salt; a plugin with another key overrides that one method), makes
  no remote call and is not memoised, because the base class reads it again on `admin_footer`
  after a save. The menu is built once per request, and a plugin without a settings page does
  not read the table at all. The base's "not configured" notice links to `get_settings_url()`,
  the settings page.
- **Icon rule** is keyed by the root `<li>` id (`toplevel_page_wc-admin-path--<menu id>`),
  the same id the bundle passes as `wpOpenMenu`, because WordPress puts the raw slug into the
  class attribute and a class selector cannot name it.
- **Bundle on every wc-admin screen.** wc-admin navigates between its pages without a reload,
  so a bundle loaded only on the menu's own screens would leave a client-side visit from
  WooCommerce's own pages with no route (the delivery orders page loads the same way).
- **Descriptors.** `records` pages carry `tables` (default: the page slug), `custom` pages a
  `view`; `legacy_page` takes one v1 URL or a list. The default five are products and orders
  (`records`), mappings, tools and diagnostics (`custom`). The analytics slot must be `null`;
  any other value is refused.
- **Page bodies** register through the `woodev.marketplace.page_view` filter
  `( null, page, menu ) → component`; until one does, a page shows its PHP title. The records
  table (F5) and the framework views (F14, F15) use the same filter as a plugin's view.
- **Position.** WooCommerce rounds `position` to an integer (`intval( round() )`), so v1's
  `55.63` registers as `56`; WordPress resolves a collision with another menu on its own.
- **Legacy redirects** run from `admin_page_access_denied` (an orphaned v1 slug is denied
  before `admin_init`), after the capability check.

## Domain layer placement

Move `includes/marketplace/<folder>/` to `woodev/marketplace/<folder>/` under
`Woodev\Framework\Marketplace\<Folder>\*`; nested catalog rules keep `Catalog\Rules`.

- Class map: [the generator](../../bin/generate-class-map.php) Rule 1 maps the segment after
  `Woodev\Framework\` to the same-named directory unless aliased; `Marketplace` needs no alias.
  Example: `Woodev\Framework\Marketplace\Price\Money` → `woodev/marketplace/price/class-money.php`.
  Add the directories to `composer.json`'s development classmap; extend
  [ClassMapCompletenessTest](../../tests/unit/ClassMapCompletenessTest.php); verify a vendored
  boot with Composer disabled and two plugins sharing the winning copy. Runtime stays
  [the framework autoloader](../../woodev/class-framework-autoloader.php). Marketplace files
  never join shipping's eager list ([ADR-012](../adr/012-shipping-includes-stays-authoritative.md)).
- Port the donor `LayerDependencyTest`, its tokenizer scanner and negative fixtures, scoped to
  the domain folders. Allowed: PHP standard symbols, the `ABSPATH` guard, `WC_Product`,
  `WC_Order`, `wpdb`, and the same-layer prefix `Woodev\Framework\Marketplace\`. Forbidden:
  every other `Woodev\Framework\*` import, plugin classes, WordPress functions/constants/
  classes, any concrete marketplace. Scan every file of those folders.
- Move the fake second-marketplace contract tests with the layer (grouped orders in all
  arrival orders, minor-unit money, asynchronous price acceptance/quarantine/duplicates,
  nullable card task ids, poll-only orders, stock key resolution) with their positive and
  negative controls.
- One additive ledger read for the activity projection: open task items for a set of link
  ids within one account (today `Task_Ledger` offers `items( int $task_id )` only). It is
  added in F1 with its `Wpdb_Task_Ledger` implementation and tests; nothing else about queues
  changes in F1.
- Preserve ciphertext format, claim fencing, money units, task statuses and idempotency.

### Marketplace semantics in the domain layer

A re-scan of the donor's `includes/marketplace/**` (`5d00c10`: every `ozon` mention, every
numeric or string constant, every merchant-facing string) found these rules that are Ozon's,
not the layer's. F1 moves the mechanics and turns each rule into an adapter-supplied value
object, constructor input or interface; the Ozon values move to the plugin's adapter in O1
(the adapter's `includes/ozon/` half). Behaviour for Ozon is unchanged.

| # | Donor file | What is Ozon's | Framework keeps | Adapter supplies (Ozon value) |
|---|---|---|---|---|
| D1 | `price/class-price-currency.php`, `catalog/rules/class-currency-rule.php` | `field:KEY` means "the field holds **the price for Ozon** in the cabinet currency"; the rule maths is described as "what Ozon shows"; `Currency_Rule` accepts `field:KEY` and checks the product field/meta value | currency-rule parsing for the neutral rules (empty, `fixed:CODE`, `rate:N`), integer conversion, `Money` arithmetic; a `Price_Source` contract the calculator asks for a base price when the rule is not neutral; `Currency_Rule` validates only empty, `fixed:` and `rate:` and passes any other rule to the adapter's `Price_Source`; with no source for it, the rule reports the profile rule as unsupported | a `Price_Source` that resolves `field:KEY` from the product field or meta, **validates** it (accepts the `field:` form, checks that the field or meta holds a usable price — today's `Currency_Rule` branch), and the label of that choice («Взять цену из поля товара») |
| D2 | `price/class-price-sync-queue.php` | `MIN_INTERVAL = 360` s (assumed Ozon limit of 10 updates per product per hour) and `MAX_ATTEMPTS = 5` | due/begin/accept/reject/defer, claim fencing, revision snapshots; interval and cap read from a `Sync_Cadence` value given to the constructor | `Sync_Cadence( min_interval: 360, max_attempts: 5 )` for prices |
| D3 | `stock/class-sync-state-queue.php` | `MAX_ATTEMPTS = 5` as a constant | the same queue mechanics, cap from the same `Sync_Cadence` | `Sync_Cadence` for stock (Ozon: no minimum interval, cap 5) |
| D4 | `catalog/class-offer-id.php` | `MAX_LENGTH = 50` (Ozon `offer_id`), spaces → `-`, fallback `wc-{product_id}` | freezing the article once sent, the normalise-then-validate single source | an `Article_Policy` (Ozon: 50 characters, the current normalisation and fallback) |
| D5 | `catalog/class-export-validator.php` | the fixed rule list, including `Selling_Price_Rule`'s "old price above price" (Ozon's answer «Old price is less than price», measurement G3) and `Variation_Group_Rule`'s model grouping (Ozon measurement 11) | every rule class and the runner; `SCOPE_MAPPING` keeps its two neutral rules | the full-scope rule list and order (Ozon: today's ten rules) |
| D6 | `stock/class-stock-policy.php` | `SITE_FORMULA` constant tied to Ozon's posting-reservation measurement plan | both formulas | the formula in force (Ozon: `wc_only` until its pilot measurement) |
| D7 | `diagnostics/class-why-not-syncing.php` | not Ozon-specific, but its merchant sentences are hard-coded Russian without translation (the layer may not call `__()`) | reason **codes** and their parameters | — one shared mapper, `woodev/marketplace/admin/shared/class-sync-reason-text.php` (F10), turns codes into translated sentences in the framework text domain; both the diagnostics page (F10) and the embedded product tab (F11; donor `admin/product/class-panel-reader.php` calls `Why_Not_Syncing`) use it |

Wording only, fixed during the F1 move with no behaviour change: docblocks that say "Ozon's
text" / "Prices Ozon accepted" (`price/class-flush-result.php`, `price/class-price-sync-queue.php`,
`stock/class-sync-state-queue.php`) and the `from_ozon` value named in `Flush_Result`
(neutral direction values arrive with F8); the `Source_Resolver` docblock that defines the
`field` vocabulary through v1's `wc_ozon_get_product_default_fields()` — the keys are neutral
WooCommerce product fields and stay, the v1 reference moves to the Ozon migration. Examples
that name Ozon and Wildberries side by side (account credentials, bucket specs, order
grouping, remote ids, push receipts) are illustrations of a neutral contract and stay.
Checked and neutral: governor timings (`MAX_ATTEMPTS`, `CONTENTION_WAIT`, `DISABLED_SECONDS`;
the bucket numbers come from `bucket_specs()`), ledger and order-registry lease TTLs, the
event ledger's payload caps, `Images_Rule`, `Dimensions_Rule`, `Currency_Rule`,
`Validation_Context`'s optional `marketplace_name`.

## Persistence as built (F8, #1686)

What the code settled in `woodev/marketplace/data/` (`Woodev\Framework\Marketplace\Data`), where the plan above left it open:

- **One table set per marketplace.** `Schema( $marketplace_id )` names every table
  `{$wpdb->prefix}woodev_{marketplace id}_{table}` (15 tables); the id is the adapter's `id()`, a lower-case word of at most 16
  characters. No column, enum value or code names a marketplace (`NeutralityTest` reads the folder with the tokenizer).
  The plugin's option family stays `woodev_{plugin id}_` (`Uninstaller::option_prefix()`); the option- and transient-backed stores
  (`Link_Progress_Store`, `Link_Report_Store`, `Cards_Snapshot_Store`) take it as a constructor argument, and `Uninstaller::run()`
  removes the tables, the pinned-article product meta, the option family and its transients — the ones in `wp_options` by a `LIKE`,
  and each listed account's snapshot, refresh mark and error through `delete_transient()` (`Cards_Snapshot_Store::clear()`), because a
  persistent object cache holds a transient nowhere else. It needs no loaded plugin; the plugin passes its own extra tables (Ozon:
  the four v1 tables).
- **Neutral names.** Accounts: `external_id` (unique), `secret_enc`, `secret_fingerprint`; stock mode `site` / `marketplace`; price
  direction `to_marketplace` / `from_marketplace` / `off`. Links: the remote-id bag of `Product_Link` is kept
  whole in `link_ids` (link, account, name, value, type `i`/`s`), whatever the adapter names its identifiers (`product_id`, `nmID`, …)
  and whether a value is an integer or a string; `by_remote_id()` is one indexed read by account, name and value, compared byte for byte (`utf8mb4_bin` on both columns, so `shop-A` and `shop-a` are two identifiers whatever the site's collation). «Not known yet» is
  no row (null, '', 0 and '0' are skipped), a card with any identifier has a frozen article, and detaching deletes the rows.
  Mappings keep the adapter's category descriptor as one JSON `category` (what `Mapping::remote_category()` returns), so a
  one-id and a two-id marketplace are stored alike. Documents: `external_keys`. `offer_id` is `varchar(191)` (the widest policy an
  adapter may declare, D4), not Ozon's 50.
- **Credential fields come from the adapter.** `Marketplace_Adapter::credential_fields()` returns an `Account\Credential_Fields`
  (a domain value: one identifier, one or more secrets); `Wpdb_Account_Store::for_adapter()` builds the store from it. The secrets
  are stored as one encrypted JSON object in `secret_enc`; credentials of an `Account` are keyed by the adapter's field names, plus
  `secrets_unreadable` / `secrets_unreadable_enc` when the salts changed. The declaration holds field names only: F10 renders the
  same declaration and adds labels and validation where the settings section needs them.
- **The quota snapshot is the adapter's.** `set_health_for_credentials()` keeps `Health_Result::extra()['limits']` as given (with
  the identifier and the time); Ozon's filtering of `daily_create` / `daily_update` / `total` moves into the Ozon adapter.
- **Lifecycle.** `Data\Lifecycle extends \Woodev_Lifecycle` creates the tables on install and re-syncs them on every upgrade
  (`dbDelta`); plugin-specific work (a data migration) hooks the `woodev_{plugin id}_installed` / `_updated` actions the parent
  fires after the tables exist. It resolves its `Schema` from `Marketplace_Plugin::get_marketplace_adapter()->id()` when none is
  passed. **The base class installs it**: `Marketplace_Plugin::init_lifecycle_handler()` returns `new Lifecycle( $this )`, so a
  marketplace plugin writes nothing for its tables.
- **`Claim_Fence` lives here.** `Wpdb_Link_Store` fences its writes with the account row's claim (`Data\Claim_Fence`: the accounts
  table and the token), so the value is part of the persistence layer and F9a reuses it instead of moving the donor class again.
- **Stores that already exist.** Throttle, API errors, events, tasks, sync state and postings have their `Wpdb_*` classes in the
  domain folders (F1); F8 supplies their tables. `Wpdb_Label_Store` is the only store F8 adds for `documents`.
- **Two test layers.** The stores' SQL runs on SQLite over the real columns (`Sqlite_Tables` translates the schema's own
  statements). What only MySQL/MariaDB can show — `dbDelta` on a second install and on a table that lost a column, the
  `INSERT … SELECT` and correlated `EXISTS` claim fences, InnoDB transactions of the label store, escaped `LIKE` in the uninstaller,
  secrets absent from `wp_options` — is in `tests/integration/Marketplace/PersistenceTest.php`, which CI's integration workflow
  runs. That test creates its tables before the WordPress test case opens its transaction (DDL commits) and drops them after it.

## Settings placement

Today `Settings_Page_Registry::register_page()` adds Woodev → Настройки,
`get_tab_url()` always builds `admin.php?page=woodev-settings&tab=…`, and the React app mounts
in `#woodev-settings-app`, fed by `woodev/v1/settings`. Change placement only:

- `collect_entries()` already tests `$plugin instanceof Woocommerce_Plugin`; it also records
  `placement` = the plugin's settings page id when `$plugin instanceof Marketplace_Plugin`.
- The Woodev page (`register_page()`, `has_providers()`, `get_page_capability()`, its tab
  list) counts only default-placement entries; a site with only marketplace plugins gets no
  Woodev → Настройки entry from this registry.
- `get_tab_url()` stays the one builder and becomes placement-aware: a marketplace provider's
  tab URL is its «Настройки» page with `tab=<id>`. Default-placement URLs are byte-identical
  to today's, so `Shipping_Plugin::get_settings_url()` and every delivery link are unchanged
  without touching shipping files. `maybe_redirect_legacy()` targets the same builder and
  also treats the marketplace settings route as a destination for its loop guard.
- `GET woodev/v1/settings?placement=<page id>` lists that page's providers; omitted lists
  the default set. Provider detail/save/tool routes, ids and capability checks are unchanged;
  placement never grants access.
- The settings React body becomes embeddable with bootstrap props (`placement`, `restRoot`,
  `nonce`). Its fixed chrome strings stay where they are (Rule 9 exempts the fixed settings
  UI; moving them would risk blank labels on the delivery plugins' settings page).
- Ozon's `is_plugin_settings()` and `Key_Expiry_Notices` link move with the framework
  settings code; option names, account ids, encrypted keys, masking and Save are unchanged.

### Settings placement as built (F3, #1681)

What the code settled, where the text above left it open:

- **The placement is the owning plugin's id**, not a separate "settings page id": the registry already holds plugins by id,
  the manifest already carries it as `pluginId`, and a marketplace plugin has exactly one «Настройки» page. `collect_entries()`
  adds `placement` (plugin id, or `null`) to every entry; `GET woodev/v1/settings?placement=<plugin id>` lists that plugin's
  tabs, an unknown placement lists nothing and never falls back to the Woodev page. The route's controller is
  `woodev/rest-api/controllers/class-rest-api-settings-page.php` (there is no `settings-page/rest-api/` folder).
- **`get_tab_url()` asks marketplace plugins only** whether they own a tab, so a delivery link costs nothing new and cannot
  start a provider-builds-a-link loop. The marketplace answer is `Marketplace_Plugin::get_settings_tab_url()`, the settings
  page URL with `tab=<id>` (`''` when the plugin's menu was refused — never the Woodev URL). `Marketplace_Plugin::get_settings_url()`
  opens that page on the first tab, the same URL the builder gives it.
- **Guards.** `GET` is gated by the requested page's capability (`get_page_capability( $placement )`); save, connection test,
  search and tool routes keep gating by the targeted provider's own capability. A placement names a list, never a provider.
- **Legacy redirect.** `maybe_redirect_legacy()` targets the builder, treats `page=wc-admin` as a destination (second loop
  guard) and leaves a request alone when the plugin has no page to land on.
- **Client.** `src/settings-page/` gains `bootstrap.ts` (the REST client reads `placement`, `restRoot`, `nonce` from the embedding
  props, else from `window.woodevSettings`) and `embedded-view.ts`, which answers the `woodev.marketplace.page_view` filter for
  `settings` pages. The registry enqueues the same bundle on every wc-admin screen when a marketplace plugin places a provider,
  after `wc-admin-app`. The Woodev page mounts with no props and requests the bare REST root as before.
- **Bundle.** `src/settings-page/` changed, so `woodev/assets/build/settings-page/` is rebuilt (the bundle now depends on
  `wp-hooks` and registers a filter).

### Settings sections and diagnostics as built (F10, #1689)

What the code settled, where the text above left it open:

- **The merchant decides no network detail (#1729, operator 12.11.2026).** The marketplace's sender addresses are the adapter's
  constant, `Push_Protocol::default_ip_allowlist()`; a developer extends them with `woodev_{adapter id}_push_ip_allowlist`, and
  declares the reverse proxies of a shop with `woodev_{adapter id}_push_trusted_proxies` (both through `Sender_Network`, which
  also answers «where did this request come from» and «is that the marketplace»; the decoder no longer judges the address, the
  intake stamps `Inbound_Event::ip_trusted`). The old `ip_allowlist` / `trusted_proxies` settings and
  `Notifications_Section::option_names()` are gone. **The address refuses a request on one route only**: the legacy URL without
  a token (`Notification_Intake::handle_tokenless()`, a plugin's own route). On the framework's route the 32-character token in the
  path is the credential and the address is only recorded, so a shop behind a proxy loses nothing there. Proxy detection reuses
  WooCommerce: `WC_Geolocation::get_ip_address()` differing from `REMOTE_ADDR`; the notice shows in the notifications card (#1735) only while a proxy is detected **and** the
  shop received a request on the tokenless URL within the last 14 days (`Tokenless_Seen`, the option
  `woodev_{plugin id}_tokenless_seen_at`, noted by `Notification_Intake::handle_tokenless()` **before** the sender check, at most
  once an hour — behind a proxy that check refuses the request and the journal stores nothing); it needs no
  «Логирование» and asks the merchant to replace the address in the cabinet with the tokenised one.
- **Oversize warehouse and stock mode `off` (#1734).** `Stock_Warehouse` gives an oversize product its link's warehouse, else the
  account's `settings.oversize_warehouse_id`, else none — never the default warehouse. The select lists warehouses with the cache's
  neutral `oversize` flag first, labelled «(КГТ)» (`Warehouse_Options::for_account( $id, true )`; the adapter maps its own word). An adapter that declares
  `Capability::OVERSIZE_WAREHOUSE` gets the «Склад для крупногабаритных товаров» select in the account parameters. `Stock_Mode::OFF`
  (`stock_mode = 'off'`): no stock push except the zero that precedes an archive, no readback or mirroring, and the intake drops a `Webhook_Type` declared as the stock one
  (`$stock = true`) as `ignored`; an adapter's `Push_Admin` leaves that type out of the wanted subscriptions for an account whose
  `Notification_Account::stock_mode()` is `Stock_Mode::OFF`.
- **Notification types are switches.** The adapter declares them in `Push_Protocol::webhook_types()` (`Webhook_Type`: id = the
  decoder's event type, label, purpose, cabinet location, default). The framework registers one toggle per type
  (`woodev_{plugin id}_webhook_{type id}`), explains them in the first card, and `Notification_Intake` stores a switched-off type
  as `ignored` and answers 200 (a 4xx would count against the URL in the marketplace's health check).
- **One seam per kind of answer.** `Settings_Descriptor` holds copy and field specs only. Remote push actions are
  `Marketplace_Adapter::push_admin( $account_id ): ?Push_Admin` (the `price_pusher()` pattern), so F11 and F15 reach them
  without a UI-copy object in between.
- **«Удалять данные при удалении плагина»** (`advanced_delete_data_on_uninstall`, off by default, operator 12.11.2026):
  `Data\Uninstaller::run()` returns without touching anything unless that option is `yes`, so a plugin's `uninstall.php`
  keeps calling it unconditionally. A plugin that drops tables of its own outside `run()` gates them with
  `Uninstaller::deletes_data( $plugin_id )`.
- **Price rules.** An account's markup or price direction saved on the settings screen fires
  `woodev_{plugin id}_account_price_rules_changed`; `Flow_Wiring` listens and queues the recompute of that account.
- **Logging.** `Marketplace_Plugin` follows `Shipping_Plugin`: `log_error()` writes at ERROR whatever «Логирование» says,
  `log_debug()` and `log_api_request()` only while it is on. The text goes through `Report_Redactor::redact_text()`, which
  hands `name=value` pairs to `Woodev_API_Base::redact_secret_log_text()` and adds the shapes that scan does not read (JSON
  pairs, `Bearer` schemes, header lines, `print_r()` dumps, the webhook token path).
- **Queue health** asks Action Scheduler through its public API (`as_get_scheduled_actions()`, the store's
  `query_actions()`), scoped to the plugin's group; the report counts offers (`kind = offer`) by their real card states.
- **Two ways to compose a settings tab.** Shipping composes its tab from `Woodev_Abstract_Settings` children with
  `Composite_Settings_Handler`; marketplace has its own `Settings_Handler` with `Settings_Section_Provider` and
  `Table_Backed_Settings` (the donor's shape, allowed by «`settings/**` → framework»). Option-backed values use the base
  class's type conversion in both. A later card may unify the two.
- **Left for F15:** the donor's `Logging::REFUSAL`, `hint()` and `set_enabled()` were not ported; the diagnostics page needs them.

### Settings screen layout as built (#1730)

The screen is five tabs on the plugin's own page, not one tab with a row of sections (operator decision 12.11.2026):

- **Tabs.** «Подключение» · «Параметры» · «Общие» · «Уведомления» · «Дополнительно». `Settings_Module::handler_for()` gives the
  handler a `Settings_Tab` per tab (a thin view over the provider that owns the fields) and `Settings_Handler::get_settings_providers()`
  lists one `Settings_Provider` per tab, all over the same handler, so a field keeps one owner and a save reaches it through whichever tab
  shows it. The first tab keeps the handler's id (`{plugin id}`, so the old address opens the page) and the `legacy_page`; the others are
  `{plugin id}-{slug}`. «Общие» exists only when the adapter has a price rule or exchange switch to default; «Уведомления» only with
  `PUSH_NOTIFICATIONS`. The page heading is `Page::get_heading()` («Настройки {plugin name}») over a menu entry that stays «Настройки».
- **«Подключение»** draws a card per account (`Settings_Section::with_card()`, `src/settings-page/accounts-view.js`): title, identifier, stored
  status, «Редактировать» (opens the section's own connection block, the existing fields and «Проверить подключение»), «Выключить» (saves
  `enabled` at once) and «Удалить» (**disabled** with a hint; deleting is #1733). No account: one big «Добавить аккаунт» button. This is
  the one part the PHP-driven renderer cannot express: one section holds one connection block, and a card needs buttons and an expand.
- **«Параметры»** is one section per account (sub-tabs by account title); with none, a notice «Подключите аккаунт на вкладке „Подключение“».
  Stock, warehouse, oversize warehouse and the price direction are always the account's own.
- **«Общие» and the switch.** `Effective_Settings` is the ONE resolver of `price_rule`, `orders_enabled` and `import_enabled`. Two groups
  («Цены и наценка», «Заказы и карточки») each have a «как в Общих / свои» select on the account (`override_prices`, `override_exchange`),
  stored as `settings.overrides.{prices|exchange}` in the account's JSON (true = own); the rule fields show only while «свои» (`show_if`),
  and a hidden field is never validated nor saved. **No flag means «свои»**: an account that existed before the defaults, or one a plugin's
  migration created, behaves exactly as before; only an account created on the screen gets `false`. The defaults are ONE option,
  `woodev_{adapter id}_account_defaults`, keyed by the **adapter** id because every runtime reader holds an `Account`, which knows its
  adapter and not its plugin (`Uninstaller::run()` deletes it explicitly: the plugin-id family misses it). Switching a group back to «свои»
  keeps the account's own values, which were never overwritten.
- **Upgrade.** `Defaults_Migration` fills the defaults from the FIRST account (lowest id) on the first run of the settings screen and marks
  `_seeded`; it writes nothing to accounts. Saving a price default queues the recompute of every account that follows it
  (`account_price_rules_changed`), as an account's own change does.
- **Readers routed through the resolver (framework).** `Export_Price_Policy::rule()`, `Posting_Jobs::wants_postings()`,
  `Cancel_Ajax::require_usable()`, and `Notification_Account_Reader` (an optional `Effective_Settings`; `Flow_Wiring` and
  `Settings_Module` pass it). A reader that still reads `Account::setting( 'price_rule' | 'orders_enabled' | 'import_enabled' )` sees the
  account's own value, which is only wrong for an account switched to «как в Общих».

## Records engine

Generic, in `Woodev\Framework\Admin\Records\*` and `src/admin-records/`; usable by any
plugin, registered by the marketplace base for its pages.

```php
// Woodev\Framework\Admin\Records — the base class is the only caller in this plan.
interface Records_Provider {
    public function get_id(): string;
    public function get_definition( array $context ): array;
    public function authorize( string $operation, array $context, array $ids ): bool;
    public function query( array $context, array $query ): array;
    public function get_children( array $context, string $parent_id, array $query ): array;
    public function preview( array $context, string $action, array $ids, array $payload ): array;
    public function apply( array $context, string $action, array $ids, array $payload ): array;
    public function get_activity( array $context, array $ids ): array;
}
```

Owner = plugin id; table ids are unique per owner; a duplicate is a developer diagnostic and
never replaces another owner's table. `authorize()` returns `bool` so errors stay `WP_Error`
at the controller; missing authorisation fails closed.

F4 adds one thing the sketch did not carry: the options route needs a provider answer, so a
provider that declares an action field with `options_source` also implements the optional
`Provides_Field_Options::get_field_options()`; a field without that contract answers 404.
`Records_Definition` rejects every key outside this page's vocabularies, and the F4 controller
serves definition, list, children and options only — `preview`/`apply` (F6), `activity` and
`account_activity` (F7) arrive with their cards through the same registry.

### Definition

| Member | Shape / rule |
|---|---|
| `context` | Account selector where relevant: id, label, options limited to authorised accounts, default. No implicit first account for writes. |
| `columns` | Ordered `id`, `label`, `cell_type`, optional `sort_key`, `required`, `visible`, alignment. Finite cell types: text, link, badge, number, date, stack, actions, progress. No HTML or callables. |
| `filters` | Ordered `id`, label, type (`search`, `select`, `multi_select`, `boolean`, `date_range`), options or a registered local options source, default. Omitted boolean differs from false. |
| `sorts` | Whitelisted keys and directions plus default; PHP maps keys to prepared queries with a stable id tie-breaker. |
| `pagination` | `default_per_page`, `per_page_options`, `max_per_page`, `max_selection`; overflow is rejected, never truncated. |
| `row_actions` / `bulk_actions` | Registered ids with label, tone, capability, optional input-field schema, `effect` (`read`/`mutate`), interaction (`link`, `submit`, `dialog`, `download`), `scope` (`selection`/`account`) and the scope sentence. Mutating bulk actions always preview then confirm. |
| `children` | Optional: rows may expand into child rows (variations) read through `get_children()`, paged and bounded. |
| `activity` | `refresh_ms`, `max_refresh_ms`, `stale_after_ms`, id limit. |
| `messages` | Every application string: empty/error/loading, selection, buttons, modal titles, progress text. Count sentences arrive pluralised from PHP. |
| `analytics` | Exactly `null`: no request, placeholder or computation. |

### Rows

Opaque string ids, stable across sort/page, scoped by owner/table/context; a product-account
pair is never identified by the WC product id alone. PHP resolves an id back to the canonical
product/link/account before permission checks. Cells follow the declared type and are
escaped; URLs are protocol-allowlisted. No credentials, claim tokens, task payloads or
sensitive remote errors reach a row.

**Variable products (B4).** A variable product is a parent row with an expander; its
variations are child rows read lazily through `get_children()`, each with its own state cell,
actions and activity. Selecting a parent selects its eligible children; the server normalises
parent/child selection to distinct domain targets so a variation is never processed twice.

```typescript
type RowActivity = {
  locked: boolean;
  reason: string;
  blocked_actions: string[];
  processes: Array<{
    id: string; // opaque observation id, never a claim token
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
  cells: Record<string, unknown>;
  actions: Array<{ id: string; enabled: boolean; reason: string }>;
  selectable: boolean;
  has_children: boolean;
  activity: RowActivity;
};
```

### REST

Through the existing `Woodev_REST_V1_Registrar`, routes under
`woodev/v1/admin/records/{owner}/{table}` (`R`). The request never names a class, URL or
callback.

| Route | Request | Response |
|---|---|---|
| `GET R/definition` | context | `{schema_version, definition, context}` filtered for the user |
| `GET R` | `context[account_id]`, `page`, `per_page`, `search`, `sort`, `direction`, typed `filters` | `{rows, total, page, per_page, total_pages, facets, account_activity, messages}` |
| `GET R/{id}/children` | context, page, per_page | same envelope, children of the resolved parent |
| `POST R/actions/{action}/preview` | `{context, ids, payload}` | `{action, scope, requested, eligible, skipped, warnings, groups, messages, fields, confirm_label, can_apply}`; no mutation, enqueue or remote call |
| `POST R/actions/{action}` | `{context, ids, payload, confirmed: true}` | `{status, rows, removed_ids, requested, accepted, skipped, failed, groups, messages}`; 200 done/partial, 202 queued |
| `POST R/activity` | `{context, ids}` of visible/expanded rows | `{rows, removed_ids, account_activity, observed_at, refresh_after_ms, messages}` from local state only |
| `GET R/actions/{action}/fields/{field}/options` | context, row id if needed, search, cursor | `{options, next_cursor}` from declared local sources |

**Apply replans; there is no preview receipt.** Apply rebuilds the plan from current state
with the same planner as preview (Ozon's `Products_Bulk::preview()` / `apply()` already
work this way) and returns the actual counts and groups. Changed eligibility shows up as
grouped skips in the response. The account-wide «Связать по артикулу» job states its account
scope in the preview; it is idempotent, so a grown count between preview and apply is
harmless. A simple non-destructive row action may skip the preview only when its PHP
definition says so; one row uses the same path with one id.

`groups` carry server-built reason text, count, fix label/URL and bounded examples
(compatible with `Bulk_Validation_Report`). `accepted` means saved or queued, never remote
completion; the server sentence says so.

### Validation and permissions

- WP REST cookie auth with the `wp_rest` nonce; a nonce is not authorisation. Require the
  page/action capability plus the provider's object/context check.
- Validate owner/table/action, bounded pagination, sort enums, declared filters, a flat
  distinct id list and input types before any query or planner. Unknown fields → 400;
  invalid action fields → 422 with field ids and PHP messages; the declared maximum is
  enforced on the server.
- Resolve each id within its declared account/table; check object capabilities
  (`edit_product` with variation-parent rules; orders need `edit_shop_orders` and
  `edit_shop_order`); compare stored account identity. A forged account beside another
  account's row fails before writes. Unknown/inaccessible → consistent 404; a known
  authorised action refused by current state → 409 with the PHP reason.
- The framework `Admin_Guard` (moved from Ozon) gets a transport-neutral check for REST; its
  `require_ajax()` (classic nonce, exits through `wp_send_json_*`) stays for embedded screens.
- Repeated confirms never duplicate work: runners reuse task hashes, intent revisions and
  compare-and-set. JS disables submit while pending; mutations are never retried after a
  timeout — activity is refreshed and the PHP recovery message shown.
- Errors are WP REST `{code, message, data:{status, errors?}}`; JS shows PHP messages and
  never rebuilds them from codes. Responses are private and not shared-cacheable.

## Activity, locks and progress

`get_activity()` is a local, batched read for the ids on screen. It also shows work started
by cron, a push, another manager or an earlier session. Reading never claims a job, calls the
marketplace or queues work.

| Source | Granularity | Projection |
|---|---|---|
| Link state (`В очереди` / `Отправлен` / `Создаётся`, Ozon `State_Badge`) | row | Locks the actions that conflict with the pending export; state label from PHP. |
| Task items (export/import tasks) | row | Queued/submitted/polling is active even with no live lease; measured counts only where meaningful. Read through the additive ledger query (F1). |
| Stock/price `in_flight_rev` and pending revisions | row (link/kind/warehouse) | One process per active send; HTTP acceptance is not completion for asynchronous receipts. |
| Account claim (`Claimable::acquire()`, TTL 120 s, renewed while a run is alive) | **account** | `account_activity`: one PHP line on the page («по аккаунту идёт фоновая работа») and the list of account actions that themselves take the claim synchronously (for example «Сбросить зависшие задачи», migration re-run), which are disabled. No row lock and no 409 on apply: product actions only set flags or seed intents and are fenced inside the runners. |
| Browser request | row | Pending from submit until response; never releases a durable lock. |

Locked rows disable only the blocked actions and the checkbox for an action they cannot take;
read-only details stay available. The reason is visible text, not colour alone. Several
processes can coexist; one percentage never hides another send. No measurable total → null
counts and an indeterminate indicator; no elapsed-time percentages. A stale claim never
authorises the browser to clear an in-flight snapshot; recovery goes through the guarded tool.

One bounded refresh loop per mounted table covers visible rows and expanded children at the
PHP cadence; it stops when hidden, aborts obsolete reads on context change, backs off on
failure, and marks state stale with PHP copy (conflicting controls stay disabled). Request
generations and per-row operation ownership (the technique in the shipping app's
`app.tsx`) keep an old response from overwriting newer rows.

## Delivery page: reference, not source

The records engine is written new, using the shipping page as a worked example of the
mechanics it needs. Nothing is imported from or changed in it.

| Look at (read-only) | For |
|---|---|
| `src/shipping-orders-page/app.tsx` | request generations, row operation owners, selection, bulk/dialog plumbing |
| `src/shipping-orders-page/filters.ts`, `period-picker.tsx` | URL codec and filter orchestration, omission versus false |
| `src/shipping-orders-page/rest.ts`, `action-input*.ts(x)` | authenticated transport, error display, action input modal |
| `woodev/shipping-method/admin/orders/class-orders-registry.php` | wc-admin page registration, `title`/`page_title` split, enqueue on wc-admin screens |
| `exports-in-progress.tsx`, `Order_Edit_Lock` | progress and disabled-reason presentation |
| [the filter-width gotcha](../gotchas/woocommerce-gives-its-filter-picker-a-fixed-430px.md) | the same scoped vendor correction for the new filter row |

Invariants for every card in this plan:

- No file under `src/shipping-orders-page/` or `woodev/shipping-method/` changes. A card's
  acceptance includes `git diff --name-only origin/main...HEAD -- src/shipping-orders-page woodev/shipping-method`
  printing nothing.
- A shared component the delivery page imports (`src/components/**`) is used as is; if it needs
  a change, that change is a separate card with its own delivery check.
- Q3 ruling: keep WooCommerce's runtime `TableCard` through the existing `wc-components`
  handle and the WordPress modal; no DataViews, no bundled `@woocommerce/components`, no
  `window.confirm()`. F2 and F5 verify against the declared minimum and the current WC.

## Ozon Seller migration

Ozon v2 has not been released (only `v1.1.7` is tagged), so intermediate states on its `main`
are fine. **No Ozon Seller release ships between O1 and O4**: v2.0.0 ships only after O4 (and
O5's acceptance), so merchants never see O1's interim classic pages under the new root — that
interim exists only on the rig. Each O-card needs a released framework
version containing the F-cards it consumes; it bumps the bundled framework and the loader
floor to that version.

1. **O1 — adopt.** `Plugin extends Marketplace_Plugin`; rewrite `Woodev\Marketplace\` →
   `Woodev\Framework\Marketplace\` in `includes/` and `tests/` (about 300 files on `5d00c10`)
   as one mechanical commit; delete `includes/marketplace/` and the autoloader prefix
   (`class-autoloader.php`); hang the classic pages under the new root as an interim (deleted
   in O4, so the rig stays usable); move the D1–D6 Ozon policies into the adapter; restore
   `assets/img/icon_square.svg` from `v1.1.7`; declare the menu and v1 legacy pages; start an
   `Ozon_Adapter` that implements the contract from existing pieces. Settings move under the
   menu automatically (the old O7). No data migration: no class name is persisted.
2. **O2 — switch to framework persistence and jobs.** Delete the plugin copies of everything
   F8/F9a/F9b moved; keep only the adapter halves (client factory, error classification, card
   builder/lifecycle, link resolver, catalog fetcher/refresher, pushers, readers, posting
   source/mapper/refresher/catch-up/canceller, label calls, push decoder/types/IP policy/
   registration). Retarget the v1 migration to the framework stores and supply its write gate.
3. **O3 — switch settings, diagnostics and embedded screens.** Delete plugin settings
   sections, diagnostics classes, the product tab and the cancel modal; supply credential
   fields, push registration specifics and report sections through the adapter.
4. **O4 — switch the stand-alone pages.** Delete the classic products/mappings/tools/
   diagnostics pages, their AJAX handlers, views, `products.js`, CSS and the
   `woodev_ozon_seller_products_tabs` filter (all unreleased v2). Supply only Ozon-specific
   cells, wording and search sources the framework pages ask the adapter for.
5. **O5 — specification, wiki and acceptance.** Update the Ozon spec §7–8 and wiki to the new
   architecture; full v2 acceptance on the rig (two accounts, variations, export/link/stock/
   price/orders/labels, v1 migration from a `v1.1.7` dataset).

Nothing renames installed v1 contracts: the v1 webhook route, v1 tables read by the migration,
v1 option names and the v1 admin URLs above keep working. New admin REST routes are additive.

## Verification

Implementation evidence: PHP 7.4 syntax/static analysis, unit and contract tests, Jest
interaction tests, TypeScript checks, i18n gates, production bundle parity, and real wc-admin
browser passes. Cases: two accounts with the same product id, another manager's claim, queued
work after lease expiry, uncertain stock/price sends, duplicate confirms, account change during
a slow request, missing/failed activity, Back/Forward, partial bulk failures, expandable
variations with independent progress, no JS-only eligibility and no analytics calls. Keyboard
access, disabled-action explanations, modal focus restoration, live progress announcements
and the filter row at laptop widths. For every F-card: the delivery-untouched diff above is
empty, and the Woodev → Настройки page of a delivery plugin renders as before.

Open questions Q1–Q3 of round 1 are settled: Q1 and Q2 by the operator (B4), Q3 as the critic
ruled (the version gate sits in F2/F5).

## Implementation card plan

Filing briefs for the coordinator, not filed cards. S/M/L are relative size. Model tiers follow
the project rule (Haiku 5.5 for bounded work with a detailed brief, Sonnet 5.5 for routine
work against this plan, Fable 5 for the hardest correctness work, one Fable worker at a time).
Every card gets a critic of another lineage and carries the delivery-untouched invariant. No
card implements analytics.

### Framework

"Files" names the framework paths a card may create or change, then the donor inventory it
moves (Ozon `includes/…`, read-only, `5d00c10` — refreshed at card start). Every card may
also touch its own tests (paths in "Framework folder layout"), `woodev/class-map.php`,
`composer.json`'s development classmap and, for client cards, the `package.json` build step;
nothing else. Acceptance includes `git diff --name-only` staying inside the card's set.

| Id | Size · tier | Depends on | Files | Scope · acceptance |
|---|---|---|---|---|
| F1 — Domain layer transfer | L · Sonnet 5.5 | review of this design; reconcile #1670 | `woodev/marketplace/{account,capabilities,catalog,diagnostics,governor,ledger,links,orders,price,stock}/**`; `tests/unit/Marketplace/**` (scanner, fixtures, contract tests). Donor: `includes/marketplace/**`, its tests in `tests/unit/Marketplace/`, `tests/unit/Architecture/` (scanner) and the per-folder unit tests that cover layer classes | Move the refreshed inventory as `Woodev\Framework\Marketplace\*`; apply D1–D7 ("Marketplace semantics in the domain layer") with fake-adapter values in tests; add the one ledger read (open items for link ids, by account). Accept: donor parity tests green with the Ozon values passed in, scanner positive/negative controls, no `ozon` literal outside docblock examples, no-Composer and two-copy boot, PHP 7.4. |
| F2 — `Marketplace_Plugin` and pages | L · Sonnet 5.5 | F1 | `woodev/marketplace/class-marketplace-plugin.php`, `woodev/marketplace/admin/*.php` (top level only), `src/marketplace-admin/**`, `woodev/assets/build/marketplace-admin/` (build output only), `woodev/languages/woodev-plugin-framework.pot` and `woodev/languages/woodev-plugin-framework-ru_RU.po` (the page msgids), this wiki page. Donor: `includes/admin/class-admin-menu.php` (reference for the v1/v2 menu) | Root without `parent` with icon/position (a container slug), six children in the B4 order by `is_configured()` (Настройки first until configured, last after), the root opens the first child, duplicate root entry removed the WooCommerce way (#1704), correct highlight, icon sizing, direct and SPA navigation, two marketplace plugins → one bundle and no menu clash, v1 legacy URLs redirect, Q3 routing checks on minimum and current WC. |
| F3 — Settings placement | M · Sonnet 5.5 | F2 | `woodev/settings-page/class-settings-page-registry.php`, `woodev/rest-api/controllers/class-rest-api-settings-page.php` (list scoping), `src/settings-page/**` (embeddable body, bootstrap props), `get_settings_url()` in `woodev/marketplace/class-marketplace-plugin.php`, the built `woodev/assets/build/settings-page/` | Marketplace providers appear only under their menu; default-placement URLs byte-identical; Woodev page unchanged for delivery plugins; `placement` list scoping; per-provider save/tool guards; legacy redirect lands on the placement-aware URL; no string moves. |
| F4 — Records PHP schema and REST | L · Sonnet 5.5 | review | `woodev/admin/records/*.php` except the action and activity classes of F6/F7 | Finite descriptors, owner registry, children route, strict query/option contracts, no receipt. Accept: two fake independent providers, forged account/id, invalid filters/sorts, oversized lists rejected before work. |
| F5 — Records React shell | L · Fable 5 | F2, F4 | `src/admin-records/**` except `actions/` and `activity/` | Render PHP cells/strings/actions, URL round trips, expandable children, stale-read protection, error/empty states, keyboard access; no domain-code switches; Q3 TableCard/label checks. |
| F6 — Preview and action interactions | M · Sonnet 5.5 | F4, F5 | `src/admin-records/actions/**`, `woodev/admin/records/class-action-*.php` | Input schemas, row actions, bulk report/confirm, guarded downloads, duplicate-submit behaviour. Accept: preview changes nothing; account-wide scope stated; partial results keep server reasons; apply returns replanned counts. |
| F7 — Activity contract | M · Sonnet 5.5 | F4, F5 | `src/admin-records/activity/**`, `woodev/admin/records/class-activity-*.php` | `RowActivity` and `account_activity` with a fixture provider: locked, blocked actions, several processes, indeterminate, stale, back-off, unseen-origin work after reload, read links stay available. No marketplace source in this card. |
| F8 — Persistence | L · Sonnet 5.5 | F1, F2 | `woodev/marketplace/data/**`, `woodev/marketplace/account/class-credential-fields.php`, `woodev/marketplace/capabilities/interface-marketplace-adapter.php` (`credential_fields()` only), `woodev/marketplace/class-marketplace-plugin.php` (`init_lifecycle_handler()` only), `tests/integration/Marketplace/`. Donor: `includes/lifecycle/{class-schema,class-lifecycle,class-uninstaller,class-schema-exception}.php`, `includes/class-persistence-exception.php`, `includes/ozon/account/class-ozon-account-store.php`, `includes/ozon/links/{class-ozon-link-store,class-link-record,class-link-progress-store,class-link-report-store,class-pinned-offer-id}.php`, `includes/ozon/catalog/{class-mapping-store,class-profile-store,class-cards-snapshot-store,class-catalog-cache}.php`, `includes/ozon/labels/class-label-store.php` | Schema, lifecycle (install/upgrade as a `Woodev_Lifecycle` the base class installs through `init_lifecycle_handler()`), uninstall, `Wpdb_*` stores for accounts, links, mappings, profiles, snapshots, catalog cache, postings, documents, throttle, API errors; neutral column/enum names; credential fields from the adapter (`Marketplace_Adapter::credential_fields()`). Accept: per-plugin table prefix, encrypted secrets never in `wp_options`, store contract tests moved from Ozon green; the MySQL-only behaviour (second `dbDelta`, claim fences, transaction rollback) has integration tests. |
| F9a — Run model, intake, stock and price | L · Fable 5 | F8 | `woodev/marketplace/{jobs,intake,sync}/**` (run model, drain, stock/price parts only). Donor: `includes/jobs/{class-account-run,class-claim-lost,class-job-gate,class-drain-events-runner,class-drain-scheduler,class-event-handler-registry,interface-event-handler,class-noop-event-handler,class-flush-sync-runner,class-price-flush-runner,class-price-recompute-runner,class-price-refresh-runner,class-price-scheduler}.php`, `includes/ozon/account/{class-first-remote-write,class-account-pause}.php` (`class-claim-fence` moved with F8 as `Data\Claim_Fence`), `includes/licensing/class-license-gate.php`, `includes/stock/**`, `includes/ozon/price/{class-price-intents,class-price-refresh-guard,class-price-refresher,class-refresh-result}.php`, `includes/ozon/notifications/{class-ozon-notification-intake,class-intake-response,class-push-rate-limiter,class-notification-url,class-notification-account,class-notification-account-reader,class-last-notification-reader,class-registration-refresher,class-account-read-exception}.php`, `includes/rest/class-notifications-controller.php` | Account run, job gate, claim fence, pause, first-remote-write, licence gate; event intake REST route, drain, handler registry; stock intents/flusher/readback/accounting; price intents/flush/recompute/refresh; adapter error classification. Accept: Ozon's claim-fencing, idempotency and concurrency tests moved and green against a fake adapter; no remote call at request time. |
| F9b — Catalog, export, link, orders, labels flows | L · Sonnet 5.5 | F9a | `woodev/marketplace/flows/**`, `woodev/marketplace/jobs/**` (export, poll, link, catalog, posting, label runners and schedulers). Donor: `includes/jobs/{class-export-batch-runner,class-export-run,class-export-scheduler,class-poll-task-runner,class-link-jobs,class-link-scheduler,class-refresh-catalog-runner,class-refresh-catalog-scheduler,class-posting-jobs}.php`, `includes/ozon/catalog/{class-ozon-export-service,class-export-context,class-export-context-builder,class-export-price-policy,class-link-context-resolver,class-card-created-seeder,class-card-matcher,class-category-ancestry,class-mapped-pairs,class-profile-sources,class-product-field-catalog,class-refresh-result,class-link-resolve-exception}.php`, `includes/ozon/links/{class-card-linker,class-link-detacher,class-shop-sku-source}.php`, `includes/ozon/labels/{class-label-download,class-label-jobs,class-label-exception,class-label-access-exception}.php`, `includes/orders/{class-order-query,class-wc-order-store,interface-order-meta-contract}.php` | Export service and batch runner, poll runner, link jobs, catalog refresh, posting jobs, label jobs/download, order query/store. Accept: moved tests green against a fake adapter; every marketplace rule found is an adapter method. |
| F10 — Settings sections and diagnostics | M · Sonnet 5.5 | F3, F8, F9a | `woodev/marketplace/settings/**`, `woodev/marketplace/admin/{guard,notices,diagnostics}/**`, `woodev/marketplace/admin/shared/class-sync-reason-text.php` (the D7 mapper, also used by F11). Donor: `includes/settings/**`, `includes/diagnostics/**` (minus the Ozon endpoint facts), `includes/admin/{class-admin-guard,class-key-expiry-notices}.php` | Accounts/notifications/advanced sections, account status, key-expiry notices, transport-neutral `Admin_Guard`, diagnostics report builder/queue health/logging, translated D7 sentences; debug-only diagnostics stay behind «Логирование». Accept: settings render the fields `Marketplace_Adapter::credential_fields()` declares (F8 added the method and the account store); secrets masked; report redacted. |
| F11 — Embedded classic screens | M · Sonnet 5.5 | F8, F9b, F10 | `woodev/marketplace/admin/{product,order}/**` (uses F10's `woodev/marketplace/admin/shared/class-sync-reason-text.php`, does not change it), `woodev/marketplace/assets/{css,js}/admin/{product-tab,order-cancel}.*`. Donor: `includes/admin/product/**`, `includes/orders/{class-cancel-ajax,class-cancel-modal}.php`, `assets/{css,js}/admin/product-tab.*` | Product tab and variation panel, order cancel metabox, both order stores. Accept: classic, nonce-guarded, adapter-named; HPOS and CPT. |
| F12a — «Товары сайта» provider | L · Sonnet 5.5 | F5–F7, F9b | `woodev/marketplace/admin/pages/products/**` (site-products provider only). Donor: `includes/admin/products/{class-site-products-reader,class-site-products-filter,class-products-bulk,class-state-badge}.php` (logic; the HTML/table classes are not moved) | Site products as a records provider; variations as children; row locks from link state, task items, in-flight revisions; account line. Accept: B4 variation rows, 12 bulk actions with validation report, warehouse choice, no client-side sort of one page. |
| F12b — Remote cards tab | M · Sonnet 5.5 | F12a | `woodev/marketplace/admin/pages/products/` remote-cards provider files. Donor: `includes/admin/products/{class-ozon-cards-query,class-ozon-cards-tab}.php` (logic) | The marketplace-cards table (snapshot, guarded refresh job, import, «Связать с…» with a WC product search). Accept: a table GET never calls the marketplace; account-wide linking scope stated. Split from F12a because both tabs together exceed one worker's 2–3 rounds (R1 finding 9); it sits in the framework because the products page is generic now. |
| F13 — Orders provider | L · Sonnet 5.5 | F5–F7, F9b | `woodev/marketplace/admin/pages/orders/**`. Donor: none (W4-2 was never built); reuses F9b's order query/store | Orders records page: filters, date/sort/page, per-row activity, label download, cancel with reasons; HPOS and CPT. |
| F14 — Mappings and profiles view | L · Sonnet 5.5 | F2, F4, F8 | `woodev/marketplace/admin/pages/mappings/**`, `src/marketplace-admin/views/mappings/**`. Donor: `includes/admin/mappings/{class-mapping-input,class-profile-input,class-mapping-check,class-mapping-products,class-mappings-ajax,class-ozon-category-finder}.php` (logic; page/view classes are not moved) | Custom React view over the moved mapping/profile inputs and validators; category search over `Catalog_Schema`; uniqueness/inheritance and guarded writes; embedded product tab unaffected. |
| F15 — Tools and diagnostics views | M · Haiku 5.5 | F6, F7, F10 | `woodev/marketplace/admin/pages/{tools,diagnostics}/**`, `src/marketplace-admin/views/{tools,diagnostics}/**`. Donor: `includes/admin/class-tools-page.php`, `includes/diagnostics/class-diagnostics-page.php` (logic) | PHP-defined tools (account choice, preview/confirm, result, progress) and the diagnostics view with the report copy; claim-taking tools disabled while the account claim is held. Tools are `account` (default) or `site` scope; `remote` defaults to true; the donor v1-migration tool is `site` + `remote=false`. |

Parallelism: F1 and F4 start together; F2 → F3; F5 after F2+F4; F6/F7 after F5; F8 → F9a →
F9b; the page cards F12a–F15 after their listed foundations. Framework releases are cut at
natural seams (after F1–F3, after F8–F9b, after F10–F15) so the O-cards can follow.

### Ozon Seller

"Files" are paths in `woocommerce-ozon-seller`. Every O-card also bumps the bundled framework
copy and the loader floor to the release it consumes, and edits only the tests of the files it
lists. A deleted path must be gone in the diff; a kept path changes only as described.

| Id | Size · tier | Depends on | Files | Scope · acceptance |
|---|---|---|---|---|
| O1 — Adopt base and namespace | M · Sonnet 5.5 | released F1–F3 | `includes/class-plugin.php`, `includes/class-autoloader.php`, `includes/admin/class-admin-menu.php` (interim: its classic pages hang under the new root until O4), new `includes/ozon/adapter/**` (the `Ozon_Adapter` and the D1–D6 Ozon policies: price source, cadences, article policy, rule list, stock formula), `assets/img/icon_square.svg` (restored from `v1.1.7`); deleted: `includes/marketplace/**`, `tests/unit/Marketplace/**`; mechanical namespace rewrite in the named inventory `git grep -l 'Woodev\\Marketplace\\' -- includes tests` (no other edit in those files) | Migration step 1. Accept: menu with the v1 icon lands on Товары, settings under the menu, mixed-copy activation works, Ozon behaviour of D1–D6 unchanged (same prices, cadence, articles, validation results), no persistent contract changed; no Ozon Seller release is cut from O1 until O4 has merged (the interim classic pages are rig-only). |
| O2 — Persistence and jobs on the framework | L · Sonnet 5.5 | released F8–F9b, O1 | Deleted: every donor path listed in F8, F9a and F9b, and the matching tests under `tests/unit/{Jobs,Stock,Price,Links,Labels,Export,Orders,Licensing}/`; changed: `includes/class-{catalog,export,labels,link,notifications,orders,price,stock}-module.php` (become adapter factories or are deleted), `includes/lifecycle/class-v1-migration.php`, `includes/migration/**` (retarget to framework stores, supply the write gate), `includes/ozon/**` adapter halves (retype to framework contracts), `includes/ozon/adapter/**` | Step 2. Accept: plugin keeps only adapter halves; Ozon's job/store tests either moved to the framework or retargeted; v1 migration green from a `v1.1.7` dataset. |
| O3 — Settings, diagnostics, embedded screens | M · Sonnet 5.5 | released F10–F11, O2 | Deleted: donor paths of F10 and F11 (`includes/settings/**`, `includes/diagnostics/**` except the Ozon report section, `includes/admin/{class-admin-guard,class-key-expiry-notices}.php`, `includes/admin/product/**`, `includes/orders/class-cancel-{ajax,modal}.php`, `includes/class-product-tab-module.php`, `assets/{css,js}/admin/product-tab.*`) and their tests under `tests/unit/{Settings,Diagnostics,ProductTab,Admin}/`; changed: `includes/ozon/adapter/**` (credential fields, push registration specifics, report section) | Step 3. Accept: accounts settings with Ozon credential fields; push section; product tab and cancel metabox through the framework. |
| O4 — Stand-alone pages | M · Sonnet 5.5 | released F12a–F15, O3 | Deleted: `includes/admin/products/**`, `includes/admin/mappings/**`, `includes/admin/{class-tools-page,class-admin-list-table,interface-admin-page,class-admin-menu}.php`, `includes/diagnostics/class-diagnostics-page.php`, `assets/{css,js}/admin/{products,mappings}.*`, tests under `tests/unit/{Products,Admin}/` for them; changed: `includes/ozon/adapter/**` (Ozon cells, wording, search sources) | Step 4. Accept: no classic stand-alone screen left, the root shows exactly the six B4 children; only Ozon-specific cells/wording/search sources remain in the plugin. This is the first state an Ozon Seller release (v2.0.0, after O5) may ship from. |
| O5 — Spec, wiki, acceptance | M · Haiku 5.5 | O4 | `docs/spec/ozon-seller-v2-spec.md` §7–8 and the sections naming moved classes, `docs/wiki/**` pages describing moved code; no PHP/JS | Step 5. Accept: spec/wiki match the code; rig acceptance list passes. |

Changes from round 1: F8 (shipping adoption) removed; O7 folded into O1; O2a/O2b moved into
the framework as F12a/F12b (the split stays, for size); the old O3–O6 screens became framework
cards F13–F15 with Ozon-side switch-overs in O3/O4; F8–F11 are new transfer cards required by
B6.

## Related

- [ADR-013](../adr/013-marketplace-layer-and-react-admin-in-the-framework.md) — ownership decision
- [Architecture](architecture.md) — current module and settings responsibilities
- [Agent rules](../AGENT-RULES.md) — Rule 9 and preservation rules
- [ADR-007](../adr/007-react-admin-stack-wordpress-scripts.md) — runtime/build conventions
- [ADR-012](../adr/012-shipping-includes-stays-authoritative.md) — shipping include list
- [Schema](../DOCS-SCHEMA.md) — internal documentation conventions
