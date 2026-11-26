# Marketplace module and PHP-driven React admin

> Design for framework #1675, round 2 (2026-11-10), kept with what the implementation added. Decisions:
> [ADR-013](../adr/013-marketplace-layer-and-react-admin-in-the-framework.md).
> **State (25.11.2026):** the module is implemented (first shipped in framework 2.0.29, completed over 2.1.x); Ozon Seller
> 2.0.0 is released on it.
> Where a design section says «proposed» or «will», the «as built» sections and the code win. Where the text allows a
> change **because Ozon Seller v2 is unreleased**, that no longer holds: table, column and option names and stored enum
> values are installed-site data contracts ([ADR-005](../adr/005-platform-v2-clean-break-policy.md)).

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
| `admin/product/`, `admin/order/` | `Admin\Product`, `Admin\Order` | embedded classic product tab; read-only marketplace order screen (`Order_Screen`, `Order_Details_Box`, #1879); the «Источник» filter of the WooCommerce orders list (`Order_Source_Filter`, #1880: views, dropdown, per-user choice in user meta, default shop-only; one singleton for every marketplace plugin, HPOS and posts list) | F11 |
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
| `orders/` `Order_Query`, `Wc_Order_Store`, `Order_Meta_Contract`; `Cancel_Ajax`, `Cancel_Modal` | framework | A marketplace order (its `created_via` = `Order_Meta_Contract::created_via()`) opens read-only on both order screens: `Order_Screen` removes the editing boxes, unhooks WC's savers and answers `wc_order_is_editable` false; `Order_Details_Box` (one box, on top) shows the posting, statuses, dates, amounts and the actions — the cancel button and the «Этикетка» button (`Label_Button`: the `label` action and the activity read of the «Заказы» table, then a download of the PDF; off for a finished posting and, when the adapter implements the optional `Label_Readiness::label_readiness( status ): ?string`, for a status it is not ready for — one rule, `Label_Availability`, also refuses the table's `label` action before any remote call). **Write actions are gated (s243, ozon#129)** by one rule, `Posting_Action_Gate`, on both screens: «Отменить» and «Этикетки» are OFF with a Russian reason (the «Заказы доставки» mechanism: a row action stays with `enabled:false` + `reason`; the metabox button is disabled with a tooltip) when the account cannot write — the plugin's existing `Marketplace_Plugin::account_write_gate()`, also read by the write jobs — or when the adapter implements the optional `Posting_Allowed_Actions::allowed_posting_actions( WC_Order ): ?array` (ids `cancel`/`label`; null = «not said», adapter without it = today's behaviour) and the list lacks the action; the provider and `Cancel_Ajax` re-check server-side before any remote call, so no label request for an unreleased posting reaches the marketplace. A cancel of a finished posting is still left out of the row. Reasons and the remote cancel come from the adapter (`Capability::CANCEL_ORDER`). `ozon/orders/` posting mapper/source/refresher/catch-up/canceller/reasons/meta stay plugin. |
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
`bucket_specs`) stays the single object a plugin hands the framework. `order_source` and `card_exporter` are
deprecated: nothing calls them and a new adapter may throw ([#2009](#wording-and-the-catalogue-of-a-second-marketplace-2009)). Moved flows need more
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

### The WooCommerce status follows a real posting transition (#1920)

`Registry_Order_Writer` gives an order its mapped WooCommerce status (`Status_Map` + the account's «Статус нового заказа») only
when the order is new (or still provisional) or when the posting's raw marketplace status differs from the last one the order
registry row saw. The row is read before the write records the new status, so `status_raw` is the last-seen value; an empty
`status_raw` counts as a change. Otherwise the writer hands `Order_Store::update()` the order's current status, which leaves it
alone: a status the merchant set by hand lives until the next real transition. Delivered and cancelled are transitions like
any other, so they reach the order when they are new, and a repeated identical status after a manual edit is left alone. The
rule is the same on HPOS and CPT (it reads the registry, not order meta) and for grouped orders (it compares the folded raw status
the row stores). It is marketplace-neutral: adapters add nothing.

## Persistence as built (F8, #1686)

What the code settled in `woodev/marketplace/data/` (`Woodev\Framework\Marketplace\Data`), where the plan above left it open:

- **One table set per marketplace.** `Schema( $marketplace_id )` names every table
  `{$wpdb->prefix}woodev_{marketplace id}_{table}` (17 tables); the id is the adapter's `id()`, a lower-case word of at most 16
  characters. No column, enum value or code names a marketplace (`NeutralityTest` reads the folder with the tokenizer).
  The plugin's option family stays `woodev_{plugin id}_` (`Uninstaller::option_prefix()`); the option-backed stores
  (`Link_Progress_Store`, `Link_Report_Store`, and the per-account record of `Wpdb_Card_Store`) take it as a constructor argument,
  and `Uninstaller::run()` removes the tables, the pinned-article product meta, the option family and its transients by a `LIKE`.
  It needs no loaded plugin; the plugin passes its own extra tables (Ozon: the four v1 tables). The ten-minute cards snapshot in a
  transient (`Cards_Snapshot_Store`) is gone since 2.1.13: the list is the `cards` table (see «Stored cards list» below).
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
- **A saved warehouse the marketplace no longer offers (#1801).** The adapter keeps only available warehouses in the cached list
  (`warehouses:{account}`). A saved id missing from the list is «unavailable» (`Warehouse_Options::is_unavailable()`) only when the list
  is a CURRENT one: non-empty and fetched (the cache's `fetched:` twin, written only by a successful refresh) within
  `Warehouse_Options::FRESH_FOR` — 8 days, the weekly catalogue refresh plus a day. An absent, empty or stale list (the refresh keeps
  failing, the old rows stay readable) blocks and flags nothing. The account select keeps such an id and labels it «#id (Склад
  отключён в маркетплейсе — выберите другой)». `Stock_Warehouse::for_link()` (link's own, account default, oversize alike) resolves it
  to none, so the flusher, the intents and the readback see «no warehouse» and nothing is sent; `Account_Status` (given the
  `Warehouse_Options`) shows ONE line in the account's status naming the ids, not one message per product. The current list is read
  once per account per request and dropped when anything writes the catalogue cache (`Wpdb_Catalog_Cache::writes()`), so a refresh in
  the same process is seen. Sends resume by themselves once another warehouse is picked or the id is listed again — no stored flag.
- **Notification types are switches.** The adapter declares them in `Push_Protocol::webhook_types()` (`Webhook_Type`: id = the
  decoder's event type, label, purpose, cabinet location, default). The framework registers one toggle per type
  (`woodev_{plugin id}_webhook_{type id}`), explains them in the first card, and `Notification_Intake` stores a switched-off type
  as `ignored` and answers 200 (a 4xx would count against the URL in the marketplace's health check).
- **A marketplace that probes the URL with wrong pushes (2.1.18, ozon#35).** Ozon checks a URL by sending a push of a type that
  does not exist (`{"message_type":"TYPE_WRONG_PUSH"}`) and a body that is not JSON, and expects an ERROR status; answering 200 to
  everything reads as «the endpoint accepts anything» (`WRONG_RESULT_FIELD`). The adapter's `Push_Protocol` can also implement the
  optional `Intake\Rejects_Inbound_Events::rejection( Inbound_Event ): ?array{status:int,reason:string}` (detected with `instanceof`, like
  `Names_Event_Types`). `Notification_Intake::ingest()` asks it for every event the decoder returned, **records the event anyway** (an
  event the decoder did not already mark gets the reason as its `ignore_reason`, so it is never worked and no drain is queued — the
  diagnostics keep the row), and then answers `status` with `Push_Protocol::error_body( reason, message )` instead of the success
  body. `Intake_Response::REASON_INVALID_JSON` («body is not valid JSON») and `REASON_UNKNOWN_TYPE` («unknown notification type») are
  the reasons the framework has text for; any other reason gets a generic text. A status outside 4xx–5xx becomes 400. It applies to
  BOTH routes, because both end in `ingest()`: the REST token route — including `Notifications_Controller::pre_dispatch()`, which
  hands a malformed body to the intake before core's `400 rest_invalid_json` — and `handle_tokenless()` (after its sender check).
  A failed store still answers 500 (the marketplace retries). **Without the contract nothing changes: every decoded event is 200.**
  Reject only what the marketplace documents as wrong — a 4xx counts against the URL's availability, so a documented type the plugin
  does not handle, or one whose switch is off, stays 200. An event the decoder dropped (`null`, a ping) is not asked about.
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
- **Left for F15:** the donor's `Logging::REFUSAL`, `hint()` and `set_enabled()` were not ported; the diagnostics page needs them. (`set_enabled()` and the page's switch were removed again in #1883: the switch lives only in the settings, «Дополнительно».)

### Settings screen layout as built (#1730)

The screen is five tabs on the plugin's own page, not one tab with a row of sections (operator decision 12.11.2026):

- **Tabs.** «Подключение» · «Параметры аккаунта» · «Значения по умолчанию» · «Уведомления» · «Дополнительно» (the last two renamed from «Параметры» / «Общие» in 2.1.13, titles only: ids, slugs and section ids are unchanged; the prose below still says «Общие» for the defaults tab). `Settings_Module::handler_for()` gives the
  handler a `Settings_Tab` per tab (a thin view over the provider that owns the fields) and `Settings_Handler::get_settings_providers()`
  lists one `Settings_Provider` per tab, all over the same handler, so a field keeps one owner and a save reaches it through whichever tab
  shows it. The first tab keeps the handler's id (`{plugin id}`, so the old address opens the page) and the `legacy_page`; the others are
  `{plugin id}-{slug}`. «Общие» exists only when the adapter has a price rule or exchange switch to default; «Уведомления» only with
  `PUSH_NOTIFICATIONS`. The page heading is `Page::get_heading()` («Настройки {plugin name}») over a menu entry that stays «Настройки»; it is also the last crumb of
  the manifest, which is what the wc-admin header shows (`Menu::build_manifest()`).
- **«Подключение»** is ONE white wrapper card (the settings card; since #1885) holding a card per account (`Settings_Section::with_card()`, `src/settings-page/accounts-view.tsx`). The wrapper's header carries the tab's description on the left and «+ Добавить аккаунт» on the right; with no account the wrapper says «Пока нет ни одного аккаунта. Добавьте первый…». A card shows an **on/off switch** (it saves at once through the page's `onToggleAccount`: one `saveTab` of just that field, then the schema is read again; staged edits of open forms are untouched), the title, «Client-Id · description» (`identifierLine()`), the stored status line, «Редактировать» (opens the section's own connection block, the existing fields and «Проверить подключение») and «Удалить». «Удалить» is offered for EVERY account when the screen has a deletion route (`canDelete()` = `delete_url`); an enabled account is switched off by the dialog itself (see «Account deletion as built» below), so the old «сначала выключите» hint is gone — `delete_hint` now carries only «Удаление аккаунта недоступно.» for a screen without the route. An open form says the stored connection message once (under «Проверить подключение»; the header drops it), opening «Добавить аккаунт» collapses an open edit form (and the other way round), and Esc closes the open form. This is
  the one part the PHP-driven renderer cannot express: one section holds one connection block, and a card needs buttons and an expand.
- **The account's «Описание»** (since #1885; the v1 store field) is an optional text field after «Название», registered by `Accounts_Sections` as `account_{id|new}_description`. It lives in the account's `settings` JSON under `description` — additive, no column and no migration: an account saved before it existed has none, `clean_description()` stores plain text (tags stripped, spaces collapsed, at most 200 characters) and an empty value removes the key. The card schema carries it as `description`.
- **Tab descriptions** (since #1885). `Settings_Tab` takes an optional `$description` (escaped HTML) → the provider arg `description` → `Settings_Provider::get_description()` → the tab schema's `description` key, present only for a tab that has one. The settings app draws it through `tab-description.tsx` (inside the wrapper's header on «Подключение», above the cards on «Параметры аккаунта», above the content on a plain tab). The texts are `Accounts_Sections::connection_description()` / `parameters_description()`. The cabinet link in the connection text comes from the OPTIONAL `Settings\Credentials_Cabinet_Link::credentials_url()` that a plugin's `Settings_Descriptor` may implement (Ozon: `https://seller.ozon.ru/app/settings/api-keys`); without it the sentence names the cabinet without a link.
- **«Параметры»** is one section per account, **stacked** one after another under the account's name with its identifier in muted text
  (`Settings_Section::stacked()`, `src/settings-page/stacked-view.tsx`; no sub-tabs). With none, a notice «Подключите аккаунт на вкладке „Подключение“».
  **«Уведомления»** is stacked too (`Settings_Tab` with `$stacked = true`): the five switches, then a block per account with its address
  and «Зарегистрировать адрес».
  Stock, warehouse, oversize warehouse and the price direction are always the account's own.
- **«Общие» and the switch.** `Effective_Settings` is the ONE resolver of `price_rule`, `orders_enabled` and `import_enabled`. Two groups
  («Цены и наценка», «Заказы и карточки») each have a «как в Общих / свои» select on the account (`override_prices`, `override_exchange`),
  stored as `settings.overrides.{prices|exchange}` in the account's JSON (true = own); the rule fields show only while «свои» (`show_if`),
  and a hidden field is never validated nor saved. **No flag means «свои»**: an account that existed before the defaults, or one a plugin's
  migration created, behaves exactly as before; only an account created on the screen gets `false`. The defaults are ONE option,
  `woodev_{adapter id}_account_defaults`, keyed by the **adapter** id because every runtime reader holds an `Account`, which knows its
  adapter and not its plugin (`Uninstaller::run()` deletes it explicitly: the plugin-id family misses it). Switching a group to «свои» **copies the
  «Общие» values in force into the account** (so «свои» starts from what the account was using, not the factory values), and while an
  account follows «Общие» its fields show those values. Switching to «как в Общих» leaves the account's own values untouched.
- **Upgrade.** `Defaults_Migration` fills the defaults from the FIRST account (lowest id) on the first handler build that finds an account and marks
  `_seeded`; it writes nothing to accounts. A shop with no account yet is NOT marked (a v1 upgrade builds the handler before the plugin's
  own migration creates the accounts); the first default the merchant saves on «Общие» marks it too, so a later fill never overwrites it. Saving a price default queues the recompute of every account that follows it
  (`account_price_rules_changed`), as an account's own change does.
- **Readers routed through the resolver (framework).** `Export_Price_Policy::rule()`, `Posting_Jobs::wants_postings()`,
  `Cancel_Ajax::require_usable()`, `Site_Orders_Cancel::refusal()`, and `Notification_Account_Reader` (an optional `Effective_Settings`; `Flow_Wiring` and
  `Settings_Module` pass it). A reader that still reads `Account::setting( 'price_rule' | 'orders_enabled' | 'import_enabled' )` sees the
  account's own value, which is only wrong for an account switched to «как в Общих».

### «Инструменты» and «Диагностика» as built (#1883)

- **One wrapper, cards inside.** Both pages are ONE white `Card.woodev-settings__card` with an intro sentence on top (`.woodev-settings__section-desc`; «Инструменты» gets `messages.intro` from `Tools_Controller::messages()`, «Диагностика» from `Diagnostics_Controller::messages()`) and each tool or section a `.woodev-group` card inside it — the look of Woodev → Настройки → Доставка → «Инструменты» (the same wrapper rule as the tables). The column spacing is the wrapper body's `gap` (`src/settings-page/style.scss`).
- **«Диагностика» exists only while «Логирование» is on.** `Marketplace_Plugin::build_menu()` leaves the page with slug `Page::DIAGNOSTICS_SLUG` out of the menu while `logging()->is_enabled()` is false — no menu item and no page to open by URL, whoever lists it in `get_marketplace_pages()`. The page has no «Включить логирование» button and the `POST …/diagnostics/logging` route, `Logging::set_enabled()` and the switch messages are gone; the switch is the settings' «Дополнительно». The menu is built once per request, so the item appears after the next page load. Ages on the page («4 дня назад», «обновлено 1 день назад») come from `Admin\Shared\Russian_Age::ago()` (the words and the Russian plural rule are ours): `human_time_diff()` follows the site locale and printed «4 days назад» on an en_US site. A page left open after logging was switched off says so and shows only the operational sections; `Logging::REFUSAL` (the report's 403) points at the settings only.

### Settings and diagnostics polish as built (s237, 2.1.13)

- **The marketplace look hangs on one class.** The settings body embedded in a marketplace page (it receives `bootstrap.placement`) carries
  `woodev-settings--marketplace`; the Woodev page of the delivery and payment plugins mounts the same body without it and is unchanged. Under
  the class: the label column is 280 px (`--woodev-label-col`, 200 px elsewhere), ⓘ is #757575 and aligned with the first label line, a switch's
  explanation is a grey line under its label, «Очистить сохранённое» is a link, and «Сохранить» with nothing to save is the neutral secondary
  button (disabled, 7:1 text) with a grey «Нет изменений» beside it, turning primary on the first change. The diagnostics view carries it too.
- **A switch's explanation is its `description`, not its `tooltip`** (`Field_Kit::add_toggle()`, the notification and advanced switches): one rule for every
  switch: up to 100 characters stays a grey line, a longer one is folded into a «Подробнее» spoiler by PHP (`Field_Kit::explanation_html()`). Fields
  outside a group card are indented past a card's border and padding, so a switch keeps one x on every tab.
- **Two additive `Settings_Section` features** (shared registry, opt-in, schema unchanged for a section that does not ask): `with_copy_field( label, value )`
  → `copy_field`, drawn by `copy-field.tsx` as a read-only field with «Копировать» (the notification address of an account); and
  `with_advanced_actions( ids, label )` → `advanced: true` on those actions plus `advanced_actions_label`, drawn by `ToolsRow` as a collapsed
  «Дополнительно» block with each tool's `desc` as its one-line explanation («Обновить типы событий», «Возобновить уведомления»).
- **Notifications card.** The state is two sentences (`Admin\Shared\Notification_State_Text`, shared with the diagnostics page): «Связь не проверялась.
  Последнее уведомление: отмена отправления, 3 дня назад.» An event type is named by the adapter: its optional `Intake\Names_Event_Types::event_names()` (every type the decoder handles, including those
  with no switch — the `Push_Protocol` implements it next to `webhook_types()`), else its switch label (`Intake\Event_Names`; a type nobody named stays as
  the decoder gave it). «Нужные события» lists the switches that are on (a stock switch is left out for an account in stock mode `off`),
  no longer the adapter's `wanted_descriptions()` (kept in `Push_Admin`, unused by this card). An address inside a cabinet-location sentence becomes a link
  with the site's name as text; an adapter that registers the address itself gets ONE sentence instead of the location lines.
- **Diagnostics.** The notification address stays masked (the card is shared with support): label «Адрес для уведомлений», value in monospace, then a link
  «Скопировать адрес — в настройках уведомлений» to the Notifications tab (`Report_Section` presentation `monospace` / `link`, no Copy button). The marketplace's own message of a **healthy** key is no longer a line (it repeated the key date, currency and role and carried a day
  count frozen at the last check); a failing key keeps it. «Ключ действует до» shows `date (дней: N)` counted at render from the stored expiry, like the
  settings status. Values start with a capital. The notification address stays masked there (the full one, with a «Копировать» field, is on the
  notifications tab).
- **Price example** (the account's price card footnote) is «Например: на сайте товар за 1 000 ₽ (было 1 200 ₽) → на {marketplace} цена …, старая цена …», amounts through
  `wc_price()`. «Минимальная цена, %» says its default in the label («по умолчанию не отправляется»: an empty field sends no minimum price) and has no placeholder number.

### Account deletion as built (#1733)

`woodev/marketplace/deletion/` (`Woodev\Framework\Marketplace\Deletion`), wired by `Deletion_Module::register()` from `Marketplace_Plugin::register_marketplace_flows()`:

- **Flow.** «Удалить» on an account card (switched on or off) opens a dialog (`src/settings-page/account-delete-flow.tsx`): the preview (`GET …/accounts/{id}/deletion`: counts of links, tasks, postings and documents, the sentence that products and WooCommerce orders stay, the warnings, and for an account that is still ON the `enabled_note` «Аккаунт включён. Выключить и удалить? …» — the button then reads «Выключить и удалить»),
  confirmation by typing the account's identifier (Client-Id) (`POST` with `{confirm}`, 202), then progress (the dialog polls the same `GET`) and the result. `manage_woocommerce` (the menu capability) plus the REST cookie nonce; the rules live in `Account_Deletion`
  (unknown account 404, wrong confirmation 400; an enabled account is **switched off by `start()` before the job is queued**, after the confirmation is checked, because the job refuses an enabled account). The job checks the switch again, and the card refuses to switch an account on while its deletion runs.
- **Job.** `Account_Deletion_Job` (`woodev_{plugin id}_delete_account( account_id, attempt )`, Action Scheduler) takes the account claim through `Job_Gate::claim()` (not `check()`: that ends silently for a disabled account), renews it after every batch and runs ordered steps of
  `function ( int $account_id, int $limit ): int` until each removes nothing: `queue` (`Account_Queue_Cleaner`: pending actions of the plugin's group whose first argument is the account id), `sync_state`, `links` (+ `link_ids`), `tasks` (+ `task_items`), `postings`, `documents`, `throttle`, `api_errors`,
  `cards` (the stored list's rows) before `options` (progress and report options, the cards list record, `catalog_cache` keys ending `:{account id}`), `product_meta` (`_woodev_{marketplace}_offer_id_{account id}`), `events`, then the adapter's `plugin` step. **The account row goes last**, `Wpdb_Account_Store::delete()`, fenced by the claim and by the switch being off.
  Nothing is remembered between runs: every step deletes what is left, so a resume is a re-run. A run stops after 20 s and queues itself; a thrown error retries three times, then the progress says `failed`.
- **Stores.** Each owner of an account-keyed table has `delete_account( $account_id, $limit )` (bounded `DELETE … LIMIT` through `Ledger\Account_Rows`); links, tasks, postings and documents also have `count_for_account()` for the preview.
- **Adapter capability.** An adapter that implements `Deletion\Account_Data_Cleaner` (like `Order_Canceller`) is asked for `erase_account_data( Account, $limit )` (its own data outside the framework's tables, in batches) and, once before the row goes, `unsubscribe_notifications( Account )`;
  when that returns false (or throws) the merchant is told to remove the notification address in the marketplace cabinet.
- **Progress** is one option per account under the plugin's option family (`account_deletion_{id}`); at the end it becomes a one-day transient, because the account row is gone. A `running` record that has not moved for 15 minutes reads as `failed`.

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
| `row_actions` / `bulk_actions` | Registered ids with label, tone, capability, optional input-field schema, `effect` (`read`/`mutate`), interaction (`link`, `submit`, `dialog`, `download`), `scope` (`selection`/`account`) and the scope sentence. Mutating selection actions always preview then confirm; an account-wide one may declare `skip_preview` (see «Tables in the style of «Заказы доставки»»). |
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
scope in the preview — the whole shop, or one category with its subcategories, on both tables
(`where` / `category` fields, read by `Site_Products_Bulk::link_category()`; the category is a searchable server-listed select, checked to be a
`product_cat` term, 422 on the `category` field otherwise); it is idempotent, so a grown count between preview and apply is
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

> **Historical (recorded 25.11.2026): the plan as written before the work.** Ozon Seller v2.0.0 has been
> released (s244); the future tense below is the plan, not the current state — see `CURRENT-STATE.md`.

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

## Admin UI as built (#1776)

Design: [the UI spec](../specs/2026-11-15-marketplace-admin-ui.md) (decided 15.11.2026). Every marketplace page now looks like the
delivery plugins' pages («Заказы доставки», the Woodev settings). Three cards built it: C1 tables, C3 settings/tools/diagnostics,
C2 tabs and the mapping/profile forms. Delivery code was not touched (invariant B3); the new shared pieces are new files.

- **The settings stylesheet is already on every marketplace page.** `Settings_Page_Registry::enqueue_bundle()` enqueues
  `woodev-settings-page` (`style-index.css` + `wp-components`) on every wc-admin screen once a marketplace plugin places a
  provider, and a marketplace plugin always does. So a marketplace view gets the settings look by markup alone: a `.woodev-settings`
  root (the label-left grid `.woodev-settings .woodev-field`), `Card.woodev-settings__card`, `.woodev-group` / `__title` / `__desc`
  for titled blocks, `.woodev-tabs` for folder tabs. Write the markup, not a second stylesheet. A view's own stylesheet only holds
  what the settings one does not draw (e.g. `views/mappings/style.scss`) and lands in the marketplace bundle's `style-index.css`,
  which `Admin_Collector` enqueues. `GroupCard` is not imported by marketplace views: it pulls the settings REST client in.
- **Shared components** (`src/components/`, prop-driven, every string a prop so Rule 9 holds; modelled on the delivery page,
  never importing it): `bulk-action-select.tsx`, `count-links.tsx`, `period-picker/` and `_table-chrome.scss` (C1);
  `folder-tabs.tsx` (C2). A follow-up card, on the operator's word, moves «Заказы доставки» onto them and deletes the private copies.
- **`FolderTabs`** is a controlled tab bar that draws `TabPanel`'s own markup (`div.woodev-tabs > div.components-tab-panel__tabs[role=tablist]
  > button.components-tab-panel__tabs-item[role=tab]`, `is-active`, `aria-selected`) so the folder look of `_wp-recolor.scss` applies unchanged.
  It is controlled because the records page keeps the active table in the URL: an uncontrolled `TabPanel` would show the old tab after
  Back/Forward. The records page uses it for its table switch (`switchPatch` unchanged), the mappings page for its two tabs.
- **Records tables (C1).** A `select` filter may declare `'views' => true` (one per definition, only on a filter whose options partition
  the rows); `CountLinks` draws it from the provider's `facets` («Все (120) | Не связан (80)») and «Все» writes what the select's «Любое»
  writes, so the URL semantics do not change. The bulk select and the search sit in the `TableCard` header (`actions`, `hasSearch`, footer
  `summary`). `EmptyCard` (`src/admin-records/states.tsx`) is the empty result: WooCommerce 7.0 has no `emptyMessage`, so the shell draws
  a card with the same header, keeping the search editable. A `date_range` filter is a `PeriodPicker`; the URL stays `from~to`.
- **Settings, tools, diagnostics (C3).** A stacked tab's card heading is inside the card (`woodev-settings__card-title`);
  `Settings_Group::with_footnote()` puts the price example after the fields; `Settings_Section` has an `actions_layout` flag
  (`ACTIONS_ROW`, serialised only when set) that `SectionView` draws with `ToolsRow` (one primary button, the rest secondary); the
  diagnostics lines are `dl.woodev-facts`.
- **Mappings and profiles (C2).** The page is two folder tabs. Each shows a **records-engine table** (since #1898, framework 2.1.13: `Mapping_List_Provider` / `Profile_List_Provider` with their
  definitions, registered by `Mappings_Module::register_lists()`; `views/mappings/mapping-lists.tsx` mounts `RecordsTable`). The section
  description is the definition's `description` message; «Добавить…» (`renderHeaderActions`) and «Создать первое…» (`renderEmptyActions`)
  are the page's two additive extension seams; the name and «Редактировать» are real links that the page follows in place; «Удалить» is
  the page's own shared dialog and REST route — the engine runs no action of these tables. «Свойства профиля» carries the values
  (`Profile_Properties`: «Габариты: 20×15×2 см · Вес: 300 г · НДС: 20%»). Opening a record, or «Добавить…»,
  **replaces the list with the form**; «← Все сопоставления» / «← Все профили» brings it back. The place is in the URL — `tab=profiles`,
  `mapping=new|<id>`, `profile=new|<id>` (`views/mappings/route.ts`, through `wc.navigation`) — so Back, Forward and a bookmark work.
  Saving a new record or deleting one rewrites the history entry (`wc.navigation.getHistory().replace`, pushing where the runtime lacks it), so Back
  never lands on an empty «new» form or a deleted id.
  A form is a settings card (`FormCard`) of `FieldRow`s inside titled `Group`s (`views/mappings/form-parts.tsx`). Why not a form under
  the list: the mapping form holds the attribute table, which needs the full width, and the profile form is ~20 rows long.
  Store categories are a `SelectField multi` (search + checklist popover). A category another mapping holds stays in the list with
  «— Уже входит в сопоставление: <name>»; the editor drops its id from the draft, so ticking it changes nothing (the server's
  «one category, one mapping» check stays). The size row is three 80 px boxes with `×` and the unit select in one `FieldRow` whose control column is widened to 540 px (the settings
  column, 425 px, cannot hold it on one line); the default
  unit option names the shop's unit («как в магазине (см)», read by PHP in `View_Config::shop_unit_label()`). VAT moved into «Цена».
  Every new word is a `View_Config` message; `chooseMapping`, `chooseProfile` and `unitDefault` are gone.

### «Заказы» and «Товары» tables after the s237 polish (framework 2.1.13)

What the UI audit of 18.11.2026 changed in the records shell and the two pages (behaviour, not a redesign):

- **Posting status is one Russian badge.** `Admin\Shared\Posting_Status_Text` maps the marketplace's status code («awaiting_packaging»,
  «delivered») to a word and a tone; an unknown code is «Другой статус», never the code. An adapter that already names its statuses
  implements the optional `Admin\Shared\Posting_Status_Names` (`posting_status_name()`, empty string for a code it does not name):
  its word wins, the framework map is the fallback, the tone stays the framework's. The «Заказы» status cell is that one badge (the
  WooCommerce status stays in the order); an order with no marketplace status shows the WooCommerce badge.
- **Row actions are one nowrap group.** `RowActions` draws the buttons in `.woodev-records__buttons`; a descriptor may carry
  `short_label` («Открыть» for «Открыть заказ»), which is shown while the full label stays the accessible name and tooltip. The
  actions column is an ordinary last column (a sticky one lay over its neighbour whenever the table was wider than its card), so a wide
  table scrolls, bar always drawn, and no column sits under another. A finished posting is simply not offered
  «Отменить отправление» (no disabled button, no sentence). The «Этикетки» column is gone: the label action hands over a stored document at once.
- **Every column has a class** (`woodev-records__col-<id>`, `TableCard`'s `cellClassName`) and `style.scss` sizes by it: numbers, dates,
  badges and the posting number never wrap, the name, article and state have a floor. Cells pad 12 px a side (WooCommerce's 24 px lost 380 px).
  The scrolling card always draws its scrollbar, and WooCommerce's edge fade over the last column is off (it made «Изменить товар» look disabled).
  Dates are `d.m.Y H:i` in the site's timezone.
- **«Товары сайта».** Article: the site SKU, plus the marketplace offer id only when it differs (one clipped line each, full value in the line's `title`). The table is laid out as «Tables in the style of «Заказы доставки» (#1861)» below.
- **Bulk select.** An action descriptor may declare `group`; `BulkActionSelect` draws one `<optgroup>` per group («Выгрузка», «Синхронизация»,
  «Связи и архив») and «Выбрано: N» (`selected_count`) beside the button; its place is kept (the element is drawn empty) while nothing is
  ticked, so the buttons after it do not jump. The account-wide buttons («Связать по артикулу», «Обновить список»)
  sit at the top of the page since #1861, where «Заказы доставки» has «Создать заказ». The column-menu button is named from `columns_menu`.
- **Card heading and summary.** The card title is drawn, like «Заказы доставки» (#1861; it had been visually hidden). A long `summary` keeps its first sentence and folds the rest into a `<details>`. The footer word is pluralised per
  list (`summary_label` in the list response) by `Russian_Plural::pick()` — the Russian rule whatever the site's locale (`_n()` follows the
  locale's rule, and a site not set to Russian printed «4 заказов»). The counted view row is not drawn while every count is zero.
- **«Карточки {marketplace}».** The tab takes the name from the adapter's `settings_descriptor()->marketplace_name()`. Before the first
  load the table says once «Список карточек ещё не загружен. Нажмите «Обновить список».»; the list is never loaded on open (that calls the marketplace).
- **«Связать по артикулу» dialog.** The searchable category field is one control: a labelled box with the field's `placeholder`
  («Начните вводить название категории») and the server's list under it; picking writes the label into the box, typing drops the pick.
  Field labels are sentence case (`.woodev-records__fields`); the first button is `preview_label` («Проверить связи»), the confirm is «Связать».

### Polish s237 (framework 2.1.13): selects, lists and the product tab

- **One select look.** `PageBody` wraps every marketplace view in `.woodev-marketplace-view`; `src/components/_select-look.scss`
  (included by `src/marketplace-admin/style.scss`) draws WordPress's `SelectControl` and `SearchControl` in that wrapper as the settings'
  own select (`.woodev-select__trigger`): 40px, 1px `$line` border, 8px radius, 14px text, 18px muted chevron. It is scoped to the wrapper,
  never to `.woodev-settings`, which the delivery plugins share. The empty choice of a marketplace select reads «Не выбрано» (the mapping
  editor passes it to `SelectField`; the profile select, the account warehouse select); the shared `SelectField` / `SearchSelectField`
  default stays «Выберите…», because the delivery plugins' screens use them too.
- **Lists.** The tab above a list names it, so the card draws no heading (`TableCard` still needs a `title`: it is the caption; the
  stylesheet hides the visible heading). A count column shows `0` (a number cell is written as a string: a falsy `display` draws nothing)
  and is as narrow as its heading. The marketplace category search is a `SearchControl` (magnifier).
- **Product tab markup.** Every row is `form-field` markup printed by `Panel_View::row()` — **including the adapter's own groups**
  (`Extension_Group` / `Extension_Field`, e.g. Ozon's «Купить на Ozon»): a plugin never prints its own row, so it inherits the label column
  and the first-line alignment and needs no hook. The label column is 190px (`product-tab.css`); WooCommerce gives a label a 40px line
  height, which spaces a two-line label 40px apart, so the stylesheet resets it. The «?» of a row is inside the label in the markup but
  **absolutely positioned** at the label column's right edge (one column for the whole tab), centred on the label's lines through
  `--woodev-mp-label-top`. Notes use `.woodev-mp-note`, never WooCommerce's `.description` (it clears the floated label and drops below it).
  No row is a spoiler any more (`details` is gone from the tab).
- **State badge and its dialog (2.1.13).** `state_badge()` prints the card state with a drawn mark: a check when the validator found nothing,
  an orange badge (a `<button data-woodev-mp-dialog>`) when something is wrong. The mark stands to the RIGHT of the words (#1876): the check, or the
  `warning` triangle with an exclamation mark (a CSS mask). Hover says «Есть проблемы (N) — нажмите, чтобы посмотреть». The badge pulses three
  times and stops when the «Ozon» TAB is opened (not on page load; `product-tab.js` adds `.is-pulsing`, the keyframes live in `product-tab.css`
  under `prefers-reduced-motion: no-preference`, so a visitor who asked for less motion gets none); the badges of the variations pulse when the
  Variations tab loads and when a variation is opened. «Something» = validator errors and warnings, the
  marketplace's last answer, and (only while «Логирование» is on) the sync reasons; they were the separate «Проверка» / «Ответ» / «Почему не
  синхронизируется» rows, now gone from the product and the variation. The list travels in a `<template>` that follows the badge; one native
  `<dialog class="woodev-mp-dialog">` is built by `product-tab.js` on first use and filled from the template (DOM calls, no `innerHTML`), so a
  variation WooCommerce loads later needs no request. A problem fixed on the product itself has no link (the sentence names the field); the
  others open the mappings / account page in a new window (the product form keeps what was typed). The «?» in the block's corner uses the same
  mechanism for the help.
- **Account select (2.1.13).** One account: no account name on the tab. Two or more: `account_switch()` prints a select that the script moves
  into the «Product data» header (right of Virtual / Downloadable) and shows only while the marketplace tab is open; every account's block
  stays in the form (so «Обновить» saves each) and only the chosen one is shown. The default is the account used last in this browser
  (`localStorage`), else the first enabled account whose saved connection check is fine (as Ozon Seller 1.1.7's «first connected store»), else the first
  enabled one (`Product_Tab::default_account_id()`): it is a view preference, so there is no server write.
- **Article line.** While the article may still change (`Link_Settings_Writer::article_is_editable()`), the row reads «<article> изменить»
  and the input appears on click, with a clickable «отменить» beside it that puts the saved article back and shows the line again
  (`Panel_View::article_control()`: `.woodev-mp-article` line, `.woodev-mp-article__editor` holding the input and «отменить»; the input keeps
  posting while the editor is hidden). An already sent article is plain text with a hint. The tab only follows that rule — see #1860 for lifting it.
- **Variation header.** `woocommerce_variation_header` → `Product_Tab::render_variation_header()`: the same `state_badge()` (check, or orange
  warning with the problems dialog) as inside the opened variation. One `.woodev-mp-header-state` slot per marketplace (an inline flex box: the
  name and the badge centred on one line, a gap between, 16px to the right): another marketplace's plugin prints its own slot after it. The header and the panel are printed in one request, so `Product_Tab::variation_blocks()`
  reads each variation's blocks once. The dialog trigger listens in the capture phase: the header is an `<h3>` that WooCommerce toggles on click.
- **Buttons.** One WordPress `.button-group` in one row (`.woodev-mp-buttons__row`, built by `Panel_View::button_group()` for the block, a
  variation's panel and a row of the variants table); each is an inline SVG icon plus its words (`.woodev-mp-btn__text`), centred on each other
  with a gap. Up to three buttons keep their words; more than three (`Panel_View::LABELLED_MAX`) get `--many` and show only
  the icons, and so does a group in a table row (`--icons`). Even three drop their words when they do not fit: `product-tab.js` measures the
  group with its words (`.is-measuring`) against the width its line has (a `ResizeObserver` on `.woodev-mp-buttons`) and sets `.is-compact`
  (measured, not a fixed breakpoint: the fit depends on the font and the screen; at 1280 three buttons need ~463px of the ~490px a product
  panel gives, at 1024 they do not fit). The name is in `aria-label` and in the tooltip.
  **The tooltip is WooCommerce's (`class="tips"` + `data-tip`, tipTip), never `title`:** a native `title` shows after a delay or not at all in some
  hosts of the product screen (the operator saw nothing on hover), and WooCommerce draws `.tips` on load and again after the variations load.
  «Архивировать» and «Снять связь» ask twice: the question appears in the result line and the button turns red for five seconds. The
  category-wide «Связать по артикулу» is not one of the icons (it works on many products): it is a text button (`.woodev-mp-link-op`, the same
  `data-woodev-mp-op` handler, which in the dialog acts on the block the dialog was opened from) inside the help dialog.
  The marks (check, warning, ✗) are CSS masks, not characters.
- **Variants table (#1877).** On a variable product the Ozon tab shows its variants as a records-style table (`Panel_View::variation_table()`):
  tick column, picture (the WooCommerce placeholder when none), name, state, article, stock and price as «сайт / маркетплейс» (the «?» in the
  heading explains it; «(будет отправлен)» is the tooltip of the article's «?»), and «Действия» (an icon group per row: update/export, archive,
  unlink, as on a simple product). Above it «Выберите действие» + «Применить» offers the same nine actions as the Variations tab's «Bulk actions»
  (`Variation_Bulk_Actions::ACTIONS`). The buttons of a row, the buttons inside an opened variation and «Применить» all post to ONE endpoint,
  `Variation_Bulk_Actions::handle_ajax()` (`woodev_{id}_variations`), which runs `Site_Products_Bulk::apply()` through the same `run()` as the
  Variations tab: same rights, licence, limit and notice. The posted variation ids are cut to the product's own children. The table is a table while it fits: the same observer measures it and sets
  `.is-stacked` when it would scroll sideways (1024px), and every variant becomes a card of two lines (tick, picture, name, state, actions; then
  article, stock, price with their `data-label`s) — WordPress's own list-table pattern on a narrow screen.

### Tables in the style of «Заказы доставки» (#1861, framework 2.1.13)

The two tables of «Товары» (and, through the shared shell, «Заказы») follow the delivery page's look; delivery code was not touched. What the shell and the definitions say now:

- **Top of the page.** The account-wide buttons (`scope = account`: «Связать по артикулу», «Обновить список») are a row above the filters (`AccountActions`, `.woodev-records__account-actions.is-page-top`), where delivery has «Создать заказ»; each tab shows its own set. A blocked one says why in its `title` and a hidden sentence, never as text beside it: that text once sat inside the toolbar row and pushed the search aside. An action id in the `empty_action` message is drawn as a button inside the empty table (the cards table offers «Обновить список» there).
- **Filters.** The selects and the period picker are the delivery pickers (WooCommerce's dropdown button: 50px, 1px `#757575` frame, 4px corners, 13px semibold). A `boolean` filter is a `ToggleControl` on its own row under them (`.woodev-records__switches`), like «Расширенные фильтры».
- **Card header.** [title] [select | «Применить»] [search] [⋮], one row on a 1024px screen with the sidebar open: the bulk select is 198px wide (the whole «Выберите действие» shows) and the search shrinks to 160px.
- **Columns.** The checkbox column is 40px with equal padding. The name column is 180px, wraps to two lines and ends in «…» (full text in `title`), and is **sticky on the left** together with the checkbox (and expand) column: their `left` offsets add up (`.has-expand` on the root says the expand column exists). WooCommerce paints the sorted column and the row-header cell grey, which would show through a pinned cell, so the pinned cells are opaque white and draw the row line themselves (the table collapses borders, and a pinned cell does not take its border along). The row header is the first `required` column, so a photo column in front of the name does not take its place. «Артикул» (130px) and «На сайте» (120px) are fixed width; cells pad 10px a side. Both products tables fit 1024px with the WP sidebar collapsed without a scrollbar (measured: 863–901px of content in a 908px card); the sticky name column matters below that and with the sidebar open. The expand toggle of a variable product is a chevron icon button (`dashicons-arrow-right-alt2` / `-down-alt2`, title «Показать варианты»), not a text glyph. A column may carry `help`: a «?» beside the heading with a tooltip.
- **State.** `badge` cells have the delivery look (2.5em line, 13px side padding, 4px corners, the WooCommerce status colours per tone, a class per tone and no inline colours) and an optional `tooltip` (lines separated by a line break, ≤ 600 characters, a focusable badge). «Товары сайта»: one badge, its tooltip lists what is synchronised, the warehouse and the error; a variable product has one badge per state group and the error summary in the first one's tooltip. «Карточки»: one badge, its tooltip holds the marketplace's reason, the import note (with the logging-gated tail) and the marketplace numbers (`product_id: …`), which are no column any more.
- **Stock and price** are one text, `{site} / {marketplace}`, «—» for a missing side; the headings are «Остатки» / «Цены» with `help`.
- **Actions.** An action descriptor may carry `icon` (a Dashicons slug): the row button is then icon-only, named by its label (tooltip), and a row of icon buttons is one group (`.is-group`) with the delivery colours per tone (green main, red destructive, grey). A card a product already carries has the one action `open_product` («Открыть товар», a link) and nothing disabled; the repeated «Карточка уже на сайте» sentences are gone.
- **Cards table.** Columns: photo, name (a link to the card in the seller's cabinet when the adapter implements `Remote_Card_Address::remote_card_url( $remote_ids )`, else text), article, state, price, stock, «На сайте», actions. `Remote_Cards_Cells` reads `image_url`, `price` and `stock` from the snapshot card and shows «—» / nothing when a card has none, so the columns fill in when the store provides them (#1863). The table opens on «Все»: a default of «Нет на сайте» answered «В кабинете нет ни одной карточки» for a cabinet whose cards were all on the site.
- **Account actions that run on the click.** An account-wide bulk action may declare `skip_preview` (the parser allows it for `scope = account`, never for a selection action): `isQuiet()` in `use-action-flow.ts` = account scope, no preview, no visible field. «Обновить список» is one: no dialog, no «Выбрано / Принято» counters (they mean nothing without a selection); the button is busy while the request is out and the account line above the table shows the progress. A refusal or an error still opens the dialog with the reason. «Связать по артикулу» keeps its dialog (#1862).
- **Marketplace numbers in the tooltip** read «ID товара», «SKU», «Модель» (`Remote_Cards_Cells::id_names()`), the product tab's words; an identifier with no name there keeps the adapter's own.
- **Refresh notice.** «Данные о ходе работы устарели» came and went by itself: while nothing runs the server asks for the next read after `max_refresh_ms` (a minute), the grace `stale_after_ms` (30 s) was counted from the last read, so the table was «stale» for half of every quiet minute. The grace is now counted from the moment the next read is due (`staleAfter()`), and a hidden tab does not go stale.

## «Заказы маркетплейсов» — the shared orders page (framework 2.1.13, #1878)

One framework page under the WooCommerce menu, `admin.php?page=wc-admin&path=/woodev-marketplace-orders`, for the orders of every
active marketplace plugin (operator review s238, LIST.md §4.1). It **hosts** each plugin's own `orders` records table
(`woodev/v1/admin/records/{plugin id}/orders`, `Site_Orders_Module`), one marketplace at a time; it owns no table, provider or route.

- **Server.** `Admin\Pages\Orders\Marketplace_Orders_Page` builds the arguments; `Admin_Collector` calls WooCommerce. It is
  registered when the user may open at least one plugin's «Заказы» page (capability = the first such page's), and placed after
  «Заказы доставки», else after WooCommerce's «Заказы», by the neighbour's slug (`place_orders_page`, `admin_menu` priority 100 —
  after the delivery page's own move at 99). The page it takes over is the framework's default `orders` page (`serves()`: slug
  `orders`, kind `records`, table `orders`); a plugin that redefined it keeps its own.
- **The plugin's «Заказы» stays** (Ozon's `get_marketplace_pages()`, its `legacy_page` and its tests are unchanged): its submenu
  entry becomes the link `admin.php?page=wc-admin&path=%2Fwoodev-marketplace-orders&marketplace={plugin id}`,
  `get_marketplace_page_url( 'orders', $query )` and the v1 redirect lead there (`Admin_Collector::get_page_url()`), and its
  route forwards in the browser (`movedTo` on its manifest entry → `MovedPage`, history `replace`, the old route's arguments kept).
- **Client** (`src/marketplace-admin/orders-page/`, the marketplace bundle; manifest key `ordersPage`). URL arguments: `marketplace`
  (a plugin id; one the page does not have — a deactivated plugin, lost access — shows the first marketplace and is rewritten to
  it with the switch's clearing BEFORE its table mounts, so a stale link's account and filters never reach another plugin's table:
  `stalePresetPatch()`) and `tab=analytics` (absent = «Заказы»); a switch of either drops every
  argument of the table that was left. The marketplace select leads the filter row (`RecordsTable` prop `leadingFilter`) and is
  not drawn with one marketplace — the account select's rule. The help links are the plugin's «Заказы» page's. No merged «all
  marketplaces» list: accounts, actions and statuses are per marketplace, the same reason the table shows one account at a time.
- **«Аналитика»** (#1891, first version: card items 1–6 and 8; not assembly speed, not a marketplace comparison) — see the next
  section.
- **Engine additions (generic, optional).** A filter may declare `advanced` (not the search, not the counted view row): it is
  drawn only under the «Расширенные фильтры» switch (message `advanced_filters`), which is the URL argument `advanced_filters=1`
  and never part of the list request; a link that sets such a filter opens the row; switching off puts every advanced filter back
  to its default. A list read may carry `data_status` = `{ items: [ { label, value } ] (1–3), title }` (`Row_Normalizer::data_status()`),
  drawn at the end of the filter row under the message `data_status` — the delivery block's shape.
- **Orders table.** Icons: open `visibility`, labels `media-document`, cancel `remove`. Advanced filters: «Статус на маркетплейсе»
  (`posting_status`, `options_source`: its options are list facets — the stored `status_raw` codes under the account's other
  filters, with counts, named by `Posting_Status_Text`; two codes named alike keep the code in brackets) and «Статус заказа».
  «Статус данных»: «Последний опрос» = the last `poll_postings` ATTEMPT that ended without an error
  (`Jobs\Posting_Poll_Times`, option `woodev_{plugin id}_postings_polled`, written by `Posting_Jobs::poll()` since 2.1.13, so
  «Ни разу» until the first poll after the update; a skipped or failed poll writes nothing), «Следующий опрос» = the account's
  next poll in Action Scheduler («Сейчас» while it runs). None for an adapter without a posting handler. ⚠ It is NOT a «read
  to the end» certificate: `Posting_Handler::catch_up()` is void, and Ozon's catch-up stops inside its 20 s budget with a window
  or cursor left (first 30-day catch-up, recovery after an outage) and returns normally — so a partial run is recorded too, and
  the label and tooltip say «опрос», never «обновлено» (critic of PR #1890). Certifying completion needs an optional completion
  signal from the handler — a contract this card did not add.

## «Аналитика» of «Заказы маркетплейсов» (framework 2.1.13, #1891)

The tab's figures for one marketplace plugin, in the look of the delivery «Аналитика» (tiles with the previous period, a chart, tables;
filters marketplace + account + WooCommerce's `DateRangeFilterPicker`). Delivery code is not touched; the tab imports only its pure
`date-range.ts` / `format.ts` and `Analytics_Aggregator::days_in_range()` / `pick_interval()`.

- **Route.** `GET woodev/v1/admin/marketplace/{plugin id}/orders/analytics` (`Orders_Analytics_Controller`, registered by
  `Site_Orders_Module::register()` with the plugin's «Заказы» page capability). `after`/`before` (≤ 800 days), optional
  `compare_after`/`compare_before`, `interval`, `account` (`all` or an ENABLED account of the marketplace, else 400
  `…_unknown_account`, on which the tab drops the argument), `refresh`. The answer is a transient for 300 s keyed by the request,
  the accounts, the user and the storage (`SCHEMA_VERSION` in the key).
- **SQL** (`Orders_Analytics_Query`): grouped statements over `postings AS pg` joined to the order on either storage
  (`Site_Orders_Storage`, which now also gives `total_expression()`, `created_via_expression()`, `id_column()`, `orders_from()`);
  days are site days through one fixed offset (`DATE(DATE_ADD(date, INTERVAL offset SECOND))` — the shops run on Russian time
  zones without daylight saving). Population = live orders minus failed and checkout drafts; `wc-pending` stays (a merchant may pick
  it as the new-order status). A cancellation = `wc-cancelled` OR a posting in the adapter's rejected statuses (`not_accepted`
  keeps its order «В обработке»); cancelled, rejected and refunded orders bring no revenue, units or products. Products and units
  read `woocommerce_order_items` / `…_itemmeta` (`_product_id` 0 = sold without a shop product, grouped by item name). The shop
  share is every shop order of the period (pending only when the marketplace made it, never one with a rejected posting) vs those
  whose `created_via` is the adapter's `order_meta()->created_via()`.
- **Raw codes belong to the adapter.** Optional `Admin\Shared\Posting_Status_Groups`: `waiting_posting_statuses()` (packing,
  handing over) and `rejected_posting_statuses()`, read by `Posting_Status_Sets`. Without the contract nothing is guessed: the
  answer has `classified: false` and `stuck: null` (the tab draws no stuck block, never «Зависших отправлений нет.»), only
  `wc-cancelled` orders are cancellations and the footnote says so (`footnoteOrders`). Explicit empty lists are believed. The Ozon
  adapter implements it in its own repo (ozon#118: waiting `awaiting_packaging`, `awaiting_deliver`; rejected `cancelled`,
  `not_accepted`, `cancelled_from_split_pending`).
- **Now, not the period.** Statuses = every posting of a live order (finished ones too) by `status_raw` and account; each count
  links to the «Заказы» tab with `account` + `filter_posting_status` and «Состояние» left at «Все» — the same rows. Stuck = open
  postings in the waiting statuses first seen more than `STUCK_HOURS` (24) ago; filter
  `woodev_marketplace_stuck_posting_hours( $hours, $plugin_id )`; the 20 oldest are listed.
- **Tab.** The tiles (and so the shop share) show for an empty period too; the chart keys its series by account id (a shared name
  gets «(#id)»).
- **Words** are PHP's (`Orders_Analytics_Controller::messages()`, manifest `ordersPage.analytics`); the stuck sentence comes with
  the answer, since the threshold is per plugin.

## Stored cards list (framework 2.1.13, #1863)

The «Карточки» tab reads the cabinet's cards from the `cards` table (`Data\Wpdb_Card_Store`, schema revision 2), kept for good, not
a transient. One lean row per card: article and its hash `card_key` (the row id's key, the primary key with the account), name,
picture URL, state flags, the marketplace's status text, price and stock (null = unknown), `remote_ids` JSON, group, `position` (the
marketplace's order) and `seen_run`. Every screen read is SQL (page, «На сайте» by a `LEFT JOIN` on `links`, search by `LIKE` on
article and name, counts); nothing loads the whole list. Measured on 10 000 / 50 000 cards before the choice: the card's comment.

- **Refresh.** «Обновить список», a daily recurring run (`refresh_cards_daily`, only accounts whose list was loaded once) and the
  continuation of a long refresh all run `refresh_cards`. A resolver that is a `Flows\Card_List_Source` is read one page per call;
  each page is upserted (batches of `Wpdb_Card_Store::BATCH` = 1000) and the cursor kept in `Link_Progress_Store` after it; a run
  stops after `Link_Jobs::PAGES_PER_RUN` pages and queues its continuation. A resolver without it is read whole by `snapshot()`.
- **Deleted cards.** `begin_refresh()` takes a generation; rows are stamped with it; `finish_refresh()` sweeps `seen_run <> run`
  only after the last page, so the screen never empties mid-refresh. A failed refresh keeps the last list and its time. The
  generation is saved in the cursor and reused only when a run goes on from that cursor; a traversal started over (give-up, expired
  cursor) gets a new one above both counters, or the abandoned pages' cards would survive its sweep. `finish_refresh()` refuses a
  generation not above the last finished one, fences its sweep on the account claim (`Claim_Fence`), and the job renews the claim
  after every read before it writes; the batch upsert itself is check-then-write, not SQL-fenced.
- **Notifications.** For a `Card_List_Source`, `Jobs\Card_List_Event_Handler` wraps the handler of each `card_event_types()` type:
  it queues `refresh_card_rows( account, [article] )` (only when the account has a list; deduplicated against **pending** actions
  only, so a read already running gets one follow-up behind it) and hands the event on. The job reads
  `list_cards()` and upserts under `current_run()`, so a refresh running meanwhile does not sweep the row; a card the answer lacks
  is left to the next full refresh.
- **Screen data.** `messages.refreshed_at` («Список обновлён ДД.ММ.ГГГГ в ЧЧ:ММ») and the summary line.
  The cells (picture `image_url`, price, stock, the cabinet link through the adapter's `Remote_Card_Address`) are #1867's
  `Remote_Cards_Cells`; the store's card carries those keys.

## Card importer (framework 2.1.6)

A marketplace card becomes a WooCommerce product through one job, started two ways. The design
is [the importer spec](../specs/2026-11-14-marketplace-card-importer.md); this section says where
the pieces live and what is easy to get wrong. The adapter implements the optional
`Flows\Card_Importer` and reports `Capability::IMPORT_PRODUCTS`; with either missing,
`Flow_Wiring::import()` registers no job, no handler and no hook, and the cards tab shows no
«Импортировать». An importer whose `import_event_types()` is empty (the marketplace announces no
cards) also gets no «Импортировать новые карточки автоматически» switch (#2009).

| Piece | Class | Does |
|---|---|---|
| Trigger: notification | `Jobs\Card_Event_Handler` | registered by `Flow_Wiring::intake()` for each `import_event_types()` **before** the plugin's `woodev_{plugin id}_event_handlers` filter, so a plugin can replace it. Only queues; no remote call |
| Trigger: cards tab | `Jobs\Import_Scheduler` | the bulk and row action queue chunks of ten articles |
| Job | `Jobs\Import_Jobs` | `import_cards( account_id, offer_ids[≤10], attempt, put_offs )` through `Account_Job` (waits are counted apart from attempts and stop at `Account_Job::MAX_PUT_OFFS`, #1835): one `read_cards()` for the batch, then one `Card_Import` per card |
| One card | `Flows\Card_Import` | the five rows of the spec's §2.6, over `Product_Writer` and `Card_Linker` |
| Groups | `Flows\Group_Assembler` | the cards of one marketplace group → one variable product (spec §2.6.1): marks the group's product, converts it in place, starts a variation |
| Run clock | `Jobs\Import_Budget` | `Import_Jobs::BUDGET_SECONDS` (20), checked before every card and image |

What to remember:

- **The claim is extended before every card and every image**; a lost claim throws `Claim_Lost`
  and the whole batch retries. The budget stops a run only after it has made progress — a card
  finished or a **new** image stored (`Product_Writer`'s `$stored` callback) — then requeues the
  current card and the rest with the attempt unchanged. A cached or failed image is no progress,
  so a slow `read_cards()` cannot make the requeue repeat for ever, and a card with many images
  is cut mid-card and resumed by its marker.
- **A variable parent with the card's SKU is never linked**: `Card_Linker` refuses a parent only
  when it already has a row, so `Card_Import` refuses it first and notes why. A variation is
  linked with its real parent id.
- **Four cut points are safe** because the writer's first save stores the SKU, the marker and the
  categories together: a cut before it leaves nothing, a cut between the saves is resumed by the
  marker, a cut before the link is linked by its SKU, a refused link is retried.
- **Notes**: a card's note (`Link_Report_Store::set_import_note()`) has a fixed Russian `text`
  and a technical `detail`; the give-up path never puts an exception message into `text`.
  `Remote_Cards_Rows` applies the «Логирование» switch to `detail` when it draws the row.
- **Groups become one variable product** (`Card_Content::group()`: `key`, `size`, `aspects`). The
  first card of a group is written as a simple product by the writer, whose **first save also
  stores the group's mark** (`start()`'s `$prepare` → `Group_Assembler::mark()`), so a sibling
  that arrives next finds the product instead of making a second one. A group of several cards,
  or a second card arriving later, converts that product **in place** — it keeps its id and its
  orders, its card becomes the first variation, its offer row is moved
  (`Wpdb_Link_Store::move_offer()`) and the product gets a `parent` row with no article. Further
  cards become variations: a first save with the SKU and the marker, then
  `Product_Writer::complete()`.
- **Every characteristic of the card becomes a product attribute** (#1949). The adapter lists them in
  `Card_Content::characteristics()` (`id` optional, `name`, `values` — a dictionary one gives its labels); the
  framework writes them through `Card_Attributes` — product-level, visible, not for variations — on a simple
  product (`Product_Writer::complete()`) and on a variable parent (`Group_Assembler::offer()`), adding only what
  the product lacks, so a re-run or a resumed run duplicates nothing. The aspects of the group stay variation
  attributes. `Import_Mapping` links the same list (`Card_Attributes::of()`), also for a group of one: by the
  characteristic's field id when it carries one, else by name; a dictionary one only when the adapter implements
  `Dictionary_Label_Lookup`. The adapter decides what is listed: what the profile or a core product field already
  carries (name, weight, seller code) is left out. «Already has» is decided by the attribute's name (case
  ignored), never by WordPress's lossy slug alone: two names that share a slug (punctuation, the 200-character
  cut of the URL-encoded name) get a prefixed second name (`[field id] …`) in the writer and the mapping alike
  (`Card_Attributes::of( $content, $product )`; `Import_Mapping::ensure()` is given the product), a merchant's
  attribute on the slug is left alone, and a global `pa_…` attribute of the same name is the same attribute.
  A variation exports the model's characteristics from its parent: `Source_Resolver` falls back to the parent's
  non-variation attribute (never to the parent's list of aspect choices).
- **Convert first, link last** (`Group_Assembler::place()`). Row 1 ends the import of a linked
  card and the cards tab does not offer it again, so a card linked before its conversion could be
  left broken with no retry reaching it. Converted first, a card whose run was cut is still not
  linked and its own retry finishes the job. Row 1 calls `heal()` for the one case left: a
  conversion another card's run left open on the linked card's product.
- **Orders made before a conversion** name the product and no variation; `Legacy_Order_Lines`
  resolves such a line to the first variation on `woocommerce_order_item_product`, or paying,
  cancelling and refunding the old order would stop moving the card's stock.
- **A variable product is not a simple one with a new type.** WooCommerce reads a variable
  product's own price as empty and recomputes its stock status from its variations, so the
  conversion reads the price, the stock and the measures from the **simple** object and keeps
  them in the product's meta in the same save that changes the type. A variation is `publish`
  or `private`, never `draft` (WooCommerce does not list a draft variation): the writer's final
  save stores `private` for a variation that is not published, and
  `Group_Assembler::sells_at_once()` decides it **before** the writer runs — a variation that
  joins a published product while «Сразу публиковать…» is off is never stored switched on.
- **Only the importer's own products are assembled.** A product the merchant linked by its
  article has no group mark and is never converted; its sibling cards make a product of their
  own. A linked card is never moved when its group changes on the marketplace.
- **A group whose first product is half-made is not built upon**: the sibling card is skipped
  with a note naming the article to import first. This is what a burst of notifications for one
  new group can leave when the first card's run was cut by the budget.
- **The preview counts products, not cards, and makes the job's decision**:
  `Remote_Cards_Import_Plan` asks the same `Group_Assembler::key_of()` and the same group state
  (`NONE` / `BUILDING` / `READY`) as the job. A card of a half-made group is left out of the
  preview, unless the card that resumes the group is selected too — then that one is queued first. An adapter
  that imports groups puts `group` (`key`, `size`) into its **snapshot** cards too — without it
  the preview counts every card as a product, while the job still assembles them.

## Plugin fields on «Общие» and the product tab (framework 2.1.7)

A plugin sometimes has one setting the framework cannot know (Ozon's «Купить на Ozon» button: a shop-wide choice and a per-product
choice). The framework gives it one optional adapter interface, `Settings\Settings_Extension`, in the pattern of `Flows\Card_Importer`:
the framework checks `instanceof`, and an adapter that does not implement it boots and prints exactly what it printed before
(`Settings_Module::handler_for()` and `Embedded_Screens` pass `null`). Nothing in delivery code is touched (B3).

```php
final class Ozon_Adapter implements Marketplace_Adapter, Settings_Extension {
	public function default_groups(): array { // «Общие»: one card per group.
		return [
			new Extension_Group( 'buy_button', __( 'Кнопка «Купить на Ozon»', 'my-plugin' ), [
				Extension_Field::select( 'buy_button', __( 'Показывать кнопку', 'my-plugin' ), [
					'never'  => __( 'Нигде', 'my-plugin' ),
					'always' => __( 'Всегда', 'my-plugin' ),
					'stock'  => __( 'Только при наличии на складе', 'my-plugin' ),
				], 'never' ),
			] ),
		];
	}

	public function product_groups(): array { // The product tab: selects only.
		return [
			new Extension_Group( 'buy_button', __( 'Кнопка «Купить на Ozon»', 'my-plugin' ), [
				Extension_Field::select( 'buy_button', __( 'Показывать кнопку', 'my-plugin' ), [
					''       => __( 'Как в общих настройках', 'my-plugin' ), // The empty value stores nothing.
					'never'  => __( 'Нигде', 'my-plugin' ),
					'always' => __( 'Всегда', 'my-plugin' ),
				] ),
			] ),
		];
	}
}

// At runtime, on the storefront (no remote call):
$mode = Effective_Settings::for_adapter( 'ozon' )->product_extra( $product, 'buy_button', 'never' );
```

What is settled:

- **One description of a field, drawn twice.** `Extension_Field` (`select()` or `toggle()`; id `[a-z0-9_]+`) and `Extension_Group` are plain
  value objects. «Общие» registers them through `Field_Kit` and a `Settings_Group` card, so they are drawn, validated and saved by the
  existing settings machinery (a select refuses a value it does not list; there is no second renderer). The product tab prints the same
  field with `Panel_View::row()`.
- **«Общие».** `Defaults_Section` takes an optional `Settings_Extension` and delegates to `Extension_Defaults`. Setting ids are
  `defaults_ext_{field id}`; the cards follow «Цены и наценка» / «Заказы и карточки». The values live in the **same defaults option**
  (`woodev_{adapter id}_account_defaults`) in a sub-array `extra`, so a field of an adapter can never shadow a key of the framework, and
  saving one does not set `_seeded` (that mark belongs to the price/exchange defaults, which an adapter's field must not block). An adapter
  with nothing else to default still gets the tab. Read with `Effective_Settings::extra( $field_id, $default )`.
- **Product tab.** `Product_Extension` (built by `Embedded_Screens::product_tab()`, passed to `Product_Tab`) hands `Panel_View` an
  `extension` key of the screen and saves the posted `woodev_{id}_ext[{field id}]` (`Screen_Names::extension_key()`). The group is printed
  **once, after the account blocks**, only when the tab has accounts.
- **Why product meta and not the link row.** The setting is «for this product»; a product linked to two accounts has two link rows and
  would have two answers, and a product with no link row has none (`Link_Settings_Writer` creates a row only for settings that need one).
  The meta is `_woodev_{adapter id}_ext_{field id}` (`Extension_Field::product_meta_key()`), written through the product's CRUD
  (`update_meta_data()` + `save_meta_data()`). A field that is per account would need the link row and a later card.
- **Variations.** The same select is printed in each variation's panel (`woodev_{id}_ext_variation[LOOP][field id]`,
  `Screen_Names::extension_variation_key()`), saved under the parent's `edit_product` like the rest of the variation form. A variation left
  on the empty option follows its parent, then «Общие»: `product_extra()` walks variation → parent → shop-wide → `$default`.
- **Selects only on the tab.** A switch has no «as in the shop settings» state; the empty-value option is that state, and choosing it deletes
  the meta. `Effective_Settings::product_extra( $product, $field_id, $default )` returns the product's own value, else the shop-wide
  `extra()` of the **same field id**, else `$default`.
- **Guards.** The same nonce field and `edit_product` as the account blocks (`Admin_Guard::check_request()` with no account); a value that
  is not an option is refused with a sentence on the next screen and nothing is written. A field the form did not post keeps its value.
- **Removal.** The values are not part of the account-deletion step `product_meta` (they are shop data, not account data); the uninstaller
  deletes them with the chosen articles (`_woodev_{adapter id}_ext_%`) when «Удалять данные при удалении плагина» is on.
- **Readers do not normalise.** `extra()` and `product_extra()` return what is stored; the screens show an option the adapter has dropped as the
  default, the readers do not — check the value against your own options.

## Bulk runs: «все по фильтру» for «Товары сайта» (framework 2.1.8, #1795)

An action ticked on «Выбрать все … по фильтру» never processes products in the request. `Site_Products_Provider::apply_matching()` stores a
**run** (`Bulk_Run`, one row per account in `{prefix}woodev_{marketplace id}_bulk_runs`) and queues its first tick
(`woodev_{plugin id}_bulk_run( account_id, run_id )`); the table keeps no list of ids, only the action, its fields and the validated filter.
Spec: [Marketplace bulk operations](../specs/2026-11-16-marketplace-bulk-operations.md) §2.3–§2.6.

| Piece | Where | What it owns |
|---|---|---|
| `Bulk_Run_Store` | `data/` | the row; a start replaces only a terminal row; a tick writes `tick` first and every save and the terminal write carry it; `cancel` is written only by the stop |
| `Bulk_Run_Lock` (`Bulk_Run_Locking`) | `jobs/` | `GET_LOCK( name, 0 )` per account, scoped by database and table; `is_held()` is `IS_USED_LOCK( name ) = CONNECTION_ID()` |
| `Bulk_Run_Runner` | `jobs/` | start, tick (budget = `Import_Jobs::BUDGET_SECONDS`), stop, recovery (the line's read and a 15-minute sweep `bulk_run( 0, '' )`) |
| `Bulk_Run_Worker` → `Site_Products_Run_Worker` | `jobs/`, `admin/pages/products/` | the work of one part: the `{ parent, child }` cursor, the licence / pause / capability gates, `Site_Products_Bulk::run_part()` |
| `Bulk_Run_Report` | `jobs/` | 49 reasons + «Другие причины», counts conserved, stored JSON under 64 KB |
| `Site_Products_Run_Line` | `admin/pages/products/` | the account line text, the preview sentences |

**The lost-connection fence.** `wpdb::query()` reconnects on error 2006 and re-runs the query on a new connection that holds no
lock, so the named lock alone is not a fence. A tick therefore (1) writes its token right after the lock, (2) fences every save with it,
(3) asks `is_held()` before every product write (`Site_Products_Bulk::guard()` throws `Bulk_Run_Lost`, which extends `\Exception` so the
`\RuntimeException` catches around the writes cannot swallow it). The loops of `Export_Service::enqueue()` and `Link_Detacher::detach()` take the
guard as an optional argument and ask it per product, and before the export's rollback writes — one check per batch would leave their writes unfenced. Proven on two real connections in
`tests/integration/Marketplace/BulkRunTwoConnectionsTest.php` (needs MySQL; the unit suite scripts the lock).

**The cursor.** `{ parent, child }`, both product ids ascending; `child > 0` means «a variable product is being walked». Parents are read
by id under the list's own `WHERE` (`Site_Products_Reader::ids_after()`), so an action that moves a product out of the filter cannot make
the run repeat it. Part sizes are constants of `Site_Products_Run_Worker` (export 100, detach 200, the rest 500).

**D1.** Switching sync on (`sync_stock_on`, `sync_price_on`) now records the first send of every created card it reached, in the ticked mode
and in a run alike (`Site_Products_Bulk::record_first_send()`), as the product tab does. A part interrupted after the flags changed is finished by its retry: created cards a switch-on finds already on get their first send
recorded too, and an intent that cannot be stored throws instead of counting as done.

**Rig phase.** The 10 000-product scale test is generated and measured by `tests/integration/fixtures/bulk-run/` (see its README); the
coordinator runs it, the PR carries the numbers.

## Export of grouped cards and marketplaces without a task number (#1999)

Card WF2 of the Wildberries design (`2.1.21`). Three **optional** interfaces under `flows/`; an adapter that implements none behaves
as before (Ozon needs no change). They are separate interfaces, not new arguments, because a released plugin implements the old ones
and runs against the newest framework copy
([gotcha](../gotchas/a-method-added-to-an-adapter-interface-fatals-released-plugins.md)).

| Interface | Implemented by | What it changes |
|---|---|---|
| `Export_Grouping` | the adapter's `Export_Facts` | `export_group( $link, $product )` names a group; `Export_Scheduler` never splits it (a group over 100 links is one larger batch), and an export part of a bulk run takes whole products |
| `Article_Keyed_Lifecycle` | the adapter's `Card_Lifecycle` | an empty `submit()` answer means «accepted»; the task is read by `read_import_by_articles()`; a re-send first asks `find_cards()`; a re-send refused as `Remote_Error::ARTICLE_TAKEN` waits for the card |
| `Import_Deadlines` | the adapter's `Card_Lifecycle` | `task_deadline()` and `card_deadline()` replace 24 h and 3 h |

**A group** is offers of one WooCommerce product. A group spanning several products is kept together only when they are queued in one
call. Offers the validator refuses are not waited for.

**Accepted without a task number.** The task row keeps `remote_task_id` NULL and `result.by_articles`
(`Async_Task::is_accepted()`), so the recovery sweep polls it instead of queueing its links again.

**No second create.** Before a card is sent, the job looks it up when all of these hold: the adapter is article-keyed, an earlier
request carried the link (`Task_Ledger::sent_offer_id()`), and the link has no remote identifier. `find_cards()` reads the card list
only (an error-list entry is no card). A card found there is not sent: its link moves to `offer_validated` with the identifiers
returned and `read_cards()` takes over; the data of this second export is sent by the next one. A failed lookup sends nothing.

**The card list lags.** A card the marketplace accepted may not be listed at retry time, so it is sent again and the marketplace
refuses the article. The adapter's classifier answers `Remote_Error::ARTICLE_TAKEN` for that refusal; the job then fails nothing it
may have created: the task stays open (`result.refusal`, `Async_Task::refusal()`), the poll asks `find_cards()` only — never the
error list, which echoes the refusal — and links the card when it appears. At the task deadline the link fails with the
marketplace's words. The other links of the refused request are queued again; a first send refused this way fails at once. An
adapter that classifies the refusal as plain `FAIL` loses this: WP4 must map it.

`Card_Lifecycle::card_id_name()` must differ between links: for sizes sharing a card it is the size's identifier.

## A size inside a card as the sellable unit (#2000)

Card WF3 of the Wildberries design (`2.1.23`). One **optional** adapter interface, `Flows\Sellable_Units`; an adapter without it
behaves as before (Ozon needs no change: article = SKU, matched after `Article_Policy::normalise()`, written by the import).
Separate interface for the same reason as above
([gotcha](../gotchas/a-method-added-to-an-adapter-interface-fatals-released-plugins.md)).

| Method | Contract |
|---|---|
| `match_keys( $card )` | ordered `Match_Key::barcode()` / `Match_Key::sku()`; the same keys in the same order for every card, empty values where one does not apply. Values are compared as raw strings |
| `shown_article( $offer_id, $card = [] )` | what «Артикул» shows: the **current** article and the unit beside it (`Shown_Article`); `$card` is the stored list's row, `[]` when there is none |
| `import_sku( $card )` | the SKU candidate the import writes, `''` for none |

**The unit card.** Every card of `Link_Resolver`, `Card_List_Source` and `Card_Importer::read_cards()` also carries `barcodes`
(all of them, byte for byte) and `unit` (`card`: the cabinet card the unit is listed under; `article`: its current seller
article, searched by the cards list; anything else the keys need). `Card_Outcome` takes the barcodes of a created card.

**The key.** `Links\Unit_Key::encode( article, unit, qualifier = '' )`, escaped, frozen at linking; over 191 characters it is
refused before any write. A renamed article keeps the key: the adapter resolves a card by its remote identifiers
(`Wpdb_Link_Store::by_remote_id()`) and answers with the stored key; a new card that takes a freed article gets the qualifier.

**Matching** (`Unit_Matcher`): the first rank at which any product matches decides. One product → pair; several, or one product
claimed by two cards → a conflict stored per link run (`Link_Report_Store::conflicts()`) and said at the card. Products with a
link, and products the importer is making for another unit, are no candidates. No near matches.

**Import** (`Unit_Products`): the unit's product is the one carrying the pending mark `_woodev_{id}_import_unit` (first save →
link); else the one the importer made for it (`_woodev_{id}_unit`, kept for good, so «Снять связь» + import does not make a
second product); else the single match. Marks are `{account}:{unit key}`; another account's pending product is nobody's
candidate. A taken candidate SKU leaves the SKU empty; `_woodev_{id}_sku_conflict` keeps it, and the sentence
shows in the card's note, the product tab and the «Товары сайта» tooltip while the product has no SKU. Group conversion moves
the marks and the GTIN to the first variation.

**Barcodes** go to `link_barcodes` (schema revision 5; one barcode names one unit of an account). `Catalog\Gtin` lets only a real
GTIN into WooCommerce's field — restricted-circulation numbers (every barcode Wildberries generates) are excluded.

**One weight and size per card.** A marketplace that takes dimensions per card adds `Catalog\Rules\Card_Dimensions_Rule` to its
`export_rules()` with a `function ( $product, $mapping ): string` naming the card of a variation. Sibling variations of one card
whose weight, length, width or height differ (own value, else parent's, else the profile fallback) are **all refused**, with the
differing names — nothing is picked or averaged (the D7 pattern for prices). Ozon's rule list does not hold it.

**Cards tab.** A cabinet card with several units is a parent row (the records engine's children, as «Товары сайта»); filters,
pages and counts go by card, «Импортировать» on the parent takes all its units, and the filter-wide import counts cards.

## Wording and the catalogue of a second marketplace (#2009)

Card WF11 of the Wildberries design (`2.1.23`). Ozon Seller 2.0.0 needs no change: everything is optional and the defaults are what
it saw before.

| Seam | Who answers | Without it |
|---|---|---|
| `Settings\Posting_Wording::posting_noun()` | the adapter's settings descriptor | «отправление» (`Posting_Noun::shipment()`) |
| `Capability::CATALOG` | `supports()` | `IMPORT_PRODUCTS` means a catalogue too |

**The noun.** `Posting_Noun` carries seven forms; two include the preposition (`with_one`: «с» / «со», `about_many`: «о» / «об»).
It must be **neuter and inanimate** («отправление», «сборочное задание»): the sentences say «это», «оно», «отменено» and use `one()`
as the accusative. Read it with `Posting_Noun::for_descriptor()`; a class built without an adapter takes it as an optional last
constructor argument. A new sentence about a posting uses the noun — `WordingNeutralityTest` fails on a literal «отправлени…» or a
marketplace name in any string of `woodev/marketplace/`. The words of the shared «Аналитика» tab travel with each marketplace entry
of the `ordersPage` manifest.

**The catalogue.** Export, linking, the cabinet's cards and the catalogue refresh ask `Capability::has_catalog()`. Import
(`Flow_Wiring::import()`, the import settings, «Импортировать») still asks `IMPORT_PRODUCTS`.

**`order_source()` and `card_exporter()`** are `@deprecated`, not removed: released adapters implement them. A new adapter may throw
a `\LogicException` from both.

## Prices the marketplace decides on later (#1998)

Card WF1 of the Wildberries design (`2.1.25`). A pusher that answers `Push_Receipt::pending()` or `::duplicate()` needs nothing but
`Price_Pusher::settle()`; Ozon answers at once and is unchanged. Timing is the **optional** `Price\Price_Task_Schedule` on the
pusher (`price_task_deadline()`, `price_poll_interval()`; 1 h and 60 s without it).

**The task.** `Jobs\Price_Task_Opener` opens one ledger task of kind `price` per upload: the marketplace's task id, one item per
link, and the pending task's result payload plus `revisions` (link id → revision sent). `settle()` gets that stored task, so the
adapter keeps there whatever it needs to map the answers to links. A duplicate receipt joins the open task of the same id.

**Waiting.** `sync_state` (schema revision 6) holds `task_id`: the pair is not due while it is set, so a newer price waits for the
answer. `open_items()` lists card imports only — a price task locks nothing. Because the pair is pinned to the task, a task is
closed only when no item is pending **and** no pair waits for it; `Task_Ledger::items()` throws on a failed read (an empty answer
used to read as «finished»), and a poll that fails on our own tables is rescheduled on the ladder.

**Answers at once.** In a pending or duplicate receipt, an immediate failure without `retry_after` is as final as the task's would
be (`Jobs\Price_Refusal_Writer`, shared with the poll). A receipt that answers everything at once (Ozon) keeps the retry ladder.

**Outcomes** (`Jobs\Price_Poll_Runner`, on the shared `poll_task` hook, under the account claim):

| `settle()` says | Price | Reason code (`error_code`) and text |
|---|---|---|
| ok | accepted | — |
| failure with `retry_after` | sent again after the wait, no attempt counted | — |
| failure, code `Price_Refusal::QUARANTINED` | kept as `refused_payload`, not sent until the price changes | `price_quarantined`, «{marketplace} держит новую цену в карантине: она намного ниже текущей» |
| any other failure | the same | `price_refused`, the marketplace's words |
| nothing, deadline passed | sent once more; after a second expiry kept as refused | `price_expired` |

«Changes» is the whole payload: the same payload written again (a new revision from another shop state) is not sent, a different
one is — also the last accepted one, which is what releases a quarantine. The pair that expired is due whatever it changed to. The
reason shows on the product tab with or without «Логирование», in the sync reasons (`Sync_Reason::PRICE_*`) and in «Товары
сайта» (the state badge's tooltip, the parent's error count and the «only errors» filter; stored rows only, no remote call).
An expiry also ends an earlier refusal.

**«Отправить цены» on a held price** (`Site_Products_Bulk`, `Price_Intents::held()`) writes nothing and leaves the product out of
the plan with the reason and the advice — ticked rows, «all by filter» and the variations tab alike. A refusal quotes at most 110
characters of the marketplace's text, so the bulk run's 200-character reason keeps the advice; past 40 distinct reasons the
smallest fold into «Другие причины».

## A note on a card, at the product (#2017)

Card WF12 of the Wildberries design (`2.1.24`). A marketplace may accept a card and still say something about it (a verdict, an
error-list entry) that neither the link's error nor the state of the card carries. Ozon Seller 2.0.0 needs no change.

**The seam.** An **optional** `Admin\Shared\Link_Notes` beside the adapter: `link_notes( $account_id, $link_ids )` answers
`Link_Note`s by link id. A note is a translated plain-text sentence and a tone (`info`, `warning`, `error`; `warning` by default).
It is asked while screens are drawn, once for all the rows of a read, so it reads what the plugin stored — **never the
marketplace**. The plugin writes notes from its jobs, and drops one by no longer answering it.

| Screen | `info` | `warning`, `error` |
|---|---|---|
| Product tab, a product's or a variation's block | a grey line | a marked line; the state badge shows «!» and the dialog lists it |
| Product tab, the table of the variants | tooltip of the state badge | tooltip, and the badge turns orange / red |
| «Товары сайта», «Карточки на маркетплейсе» | first lines of the state badge's tooltip | the same, and the badge turns orange / red (`Link_Note_Badge`) |

A parent row (a variable product, a card with several sizes) says each distinct sentence once, at the badge of the state its member
is in. A cabinet card no product carries has no link and so no note.

## One price for several links: price groups (#2001)

Card WF4 of the Wildberries design (`2.1.25`), owner decision D7. One **optional** interface, `Price\Price_Grouping`, implemented
by the **price pusher** — not by the adapter ([gotcha](../gotchas/a-method-added-to-an-adapter-interface-fatals-released-plugins.md)).
A pusher without it keeps one group per link (Ozon needs no change).

| Method | Contract |
|---|---|
| `price_group_id_name()` | the remote identifier whose value ties links into one group (`nmID`); a link without a value stands alone |
| `allows_member_prices( $account, $members )` | asked about a group whose computed prices differ, or one holding a refused or repeatedly failed price; `false` = one price per group. Stored facts only, no request |

**Members** are the offers that hold the value, with a `created` card, `sync_price` on and a pending price
(`Wpdb_Link_Store::price_group_members()`). A link outside that set is neither compared nor sent, although a group-wide price
reaches it on the marketplace.

**Sending** (`Sync\Price_Groups`, used by `Price_Flush_Runner`). One due price takes the whole group into the call, absent
siblings included, also those already accepted. A group is never split between requests; one larger than `price_batch()` goes
whole, so the pusher splits it if its endpoint needs that. The pusher answers **every** line, or leaves the whole upload to one
price task. A group with a due price is decided in this order:

| Condition | What happens |
|---|---|
| computed prices (price, old price, minimum price) differ, one price per group | **held**: nothing is sent |
| a member waits for a price task | the due members are put off by the task's poll interval |
| one price per group, and a member's pending price is refused | the due members are turned down with the same `error_code` and text; nothing is sent |
| a price to send has a deadline or the minimum interval ahead | the due members are put off until then |
| otherwise | sent. With member prices allowed, refused and exhausted members are left out; with one price, an exhausted one goes with the group |

«Refused» is a pending price equal to `refused_payload` (refusal, quarantine, second expiry): not sent until it changes, as
outside a group ([#1998](#prices-the-marketplace-decides-on-later-1998)). «Exhausted» is a revision whose sends failed as often
as the price cadence allows — counted failures, a server error among them, so it never turns a sibling down.

**Held.** The rows get `error_code = Sync_Reason::PRICE_GROUP_DIFFERS` and its sentence in `last_error`;
`Price_Sync_Queue::due()` excludes the code. A row that waits for a price task, or that already says why its price is turned
down, is left as it is. No schema change beyond revision 6. Every price run first looks at the held rows again and frees the
groups that now agree, now allow member prices, or lost the link; no request is sent for that.

**Shown** through the price reason of #1998: the product tab («Новая цена», or the sync reasons list), the tooltip in the price
cell of the variants table (any price reason, encoded twice because it may quote the marketplace), «Товары сайта» (tooltip,
parent's error count, «only errors») and the count of held prices in the diagnostics report.

## Changing the article after the export (framework 2.1.13, #1860)

Before 2.1.13 the article (`offer_id`) was frozen once a card was sent. Operator decision 19.11.2026: it stays changeable, and the change goes to the marketplace by its own
offer-id update method, never as a side effect of a SKU edit (v1 renamed on every SKU change, synchronously, inside the product-save hook, with no uniqueness check and no
visible failure; the explicit, queued, checked, visible change here is the fix of that).

**Capability.** An optional contract, like `Product_Tab_Facts` / `Order_Canceller`: the adapter implements `Flows\Offer_Id_Renamer`
(`max_renames_per_request()`, `rename_offer_ids( Account, current => new, context ): array<current, Offer_Id_Rename_Outcome>`) **and** reports `Capability::RENAME_SKU`. `Marketplace_Adapter`
does not change. An adapter without either keeps the old frozen article, the old help text and no job (`Flow_Wiring::offer_id_renames()` hooks the job, `Embedded_Screens::product_tab()` switches the screen).
The adapter answers for every pair; a pair it does not answer for is treated as refused. A request that fails as a whole throws, and the adapter's `Error_Classifier` decides.

**State lives on the link row** (schema revision 3): `links.pending_offer_id` (the article the merchant asked for), `links.rename_error` (the marketplace's answer when it refused) and `links.rename_confirmed` (the marketplace already changed the card; only the shop's move is left),
kept apart from `last_error_*` because export and poll writes wipe those. `offer_id` itself changes in exactly one statement (`Wpdb_Link_Store::finish_offer_id_rename()`), so the unique
`(account_id, offer_id)` index is the arbiter of a clash and a row is never half-moved. The three columns are left out of `Link_Record::to_columns()`, so an insert works on a site whose tables
are not yet at revision 2.

| Step | Where | What happens |
|---|---|---|
| Edit | product tab / variation panel, «Артикул: … · изменить» | the input starts on the article the card has (or the one a change waits for) |
| Save | `Link_Settings_Writer` → `Offer_Id_Renames::request()` | the card must be known to the marketplace (`Link_Record::RENAMEABLE_STATES`); the article must be free in the account (no row holds it, no other row waits for it — a refused change frees it); the link keeps its old article, `pending_offer_id` is set, the job is queued. A save never waits for the marketplace |
| Job | `Rename_Offer_Ids_Runner` (`woodev_{plugin id}_rename_offer_ids`) | `Account_Job` skeleton (licence, pause, claim, the adapter's verdict), plus the plugin's write gate; reads the waiting rows, sends ≤ `max_renames_per_request()` pairs per request, up to 20 requests per run |
| Success | `finish_offer_id_rename()` + `Offer_Id_Renames::for_plugin()` closure | link article, waiting request and error move together; the article chosen on the product (`Pinned_Offer_Id`) follows, only if the product had one; one log line |
| Refusal | `fail_offer_id_rename()` | the old article stays linked; `rename_error` holds the marketplace's words; the badge gets the orange «!» and the problems dialog a «Смена артикула» section; the field's line says «не удалось сменить на …» |
| Take back | typing the card's current article | clears a waiting or refused change; typing the refused article again asks again |

Variations are rows like any other: each variation's panel posts its own `offer_id`, the parent's variations table notes «(меняется на …)» / «(не удалось сменить на …)». A detach or a reset of the
state clears the waiting change. A cleared field leaves a sent card's article alone («follow the SKU» has no meaning once the card is on the marketplace).

**One reservation rule.** A destination a change waits for belongs to that row until the marketplace refuses it (`Wpdb_Link_Store::offer_id_is_reserved()` / `owner_of_article()`). Every statement that assigns an article asks it: `insert_if_absent`, `insert_card`, `adopt_card`, `queue`; the export validator's «who holds this article» and `Card_Linker` use `owner_of_article()`. A check before the statement is not serialised against a concurrent request; the last guard is the unique index, whose clash after the marketplace confirmed is reported (`taken`), never half-applied.

**Failure paths.** A database error after the marketplace confirmed is not «the link is gone»: `finish_offer_id_rename()` answers `error`, the row keeps the destination with `rename_confirmed = 1` and a readable error, the request is never sent again and the job retries the move (5 minutes). A change whose card is queued/submitted (an update is being exported) keeps its visible state and the job comes back every 2 minutes; a card the marketplace refused turns the change into a failed one. A job that gives up for good (refused key, lapsed licence, put-off ceiling, a request that cannot be repeated) marks **every** waiting change of the account failed with the reason (`fail_waiting_offer_id_renames()`); saving the article again asks again.

**Known limits.** The framework checks uniqueness against the shop's own links only; a card of the same seller that is not linked to any product is the marketplace's answer to give (it arrives as the refusal text). The refusal text is the marketplace's, shown as it came. At most 25 stuck confirmed rows are looked at per batch.

## Bulk actions of the Variations tab (framework 2.1.13, #1859)

The «Bulk actions» select of a variable product's Variations tab gets one optgroup per enabled account, named after the marketplace
(`Screen_Names::name()`, the account's name added when there are several). `Variation_Bulk_Actions` (`admin/product/`) hooks
WooCommerce's own flow: `woocommerce_variable_product_bulk_edit_actions` prints the options, `woocommerce_bulk_edit_variations` runs
the chosen one. It is registered by `Embedded_Screens` for an adapter that has the product tab.

- **WooCommerce hands the hook EVERY variation of the product**, not a selection (`WC_AJAX::bulk_edit_variations()`), and fires it after
  its own handler for any action, then syncs the product and reloads page 1. The class therefore never ends the request, ignores any
  value that is not `woodev_{adapter id}_bulk_{account id}_{action}`, catches `\Throwable` (an exception would skip WooCommerce's
  `WC_Product_Variable::sync()`) and prints the answer instead of returning it.
- **The actions are the table's.** `Site_Products_Bulk::apply()` (built by `Site_Products_Module::bulk_for()`, lazily, only when an
  action is chosen) plans from the stored links and queues: export (`Export_Scheduler`), send prices / stock and the one-time price pull
  (intents and `Price_Scheduler`) are Action Scheduler work, the sync switches and unlinking write local data. No remote call in the
  request. Which actions an adapter cannot serve is `Site_Products_Module::withheld_actions()`, shared with the table's toolbar.
- **Nine of v1's eleven.** Taken: export, unlink, enable/disable price sync, send prices, load prices, enable/disable stock sync, send
  stock. Dropped: «Обновить SKU» (v2 writes the marketplace ids when an export finishes and when cards are linked or imported) and
  «Загрузить остатки» (the account's stock mode «Маркетплейс — главный» mirrors them continuously with a daily reconcile; a one-off
  overwrite of hundreds of variations' shop stock is a hazard). v2-only table actions stay in the table: warehouse choice needs a
  dialog, archive is destructive, linking by article is account-wide.
- **Rights and licence.** `edit_product` on the parent for every action; the ones that write to the marketplace (the table's
  `license_phrase()` is non-null) also need `manage_woocommerce` and a licence in force — the product tab's rule. A refusal is a notice,
  never an exception.
- **The notice.** The bulk request answers with nothing WooCommerce reads, so the handler prints
  `<!--woodev-bulk:{tone,text,url,link}-->` into the response (JSON with `JSON_HEX_TAG`, so the text cannot close the comment) and
  `variation-bulk.js` draws a WooCommerce `notice inline` first in `#variable_product_options_inner`, replacing the previous one. The
  text is the table's own sentence («Принято в работу: N товаров…»), the reasons for what was left out (three at most) and, for queued
  work, a link to the «Товары» page, where the «Состояние» column shows progress. A failed request draws a generic error.
- **Limit.** The table's `MAX_OFFERS` (500). A product with more variations is refused with a pointer to «Товары», whose bulk runs have
  no limit.

## Dialogs: one mechanism and one look (framework 2.1.13, #1875)

Every dialog of the marketplace admin is drawn by ONE stylesheet, `woodev/marketplace/assets/css/admin/woodev-dialog.css`:
a frame, a header (title and «✕» at the top right), a body (generous padding, 15 px) and an optional footer (a tinted bar with the
buttons on the right), told apart by lines. The class set is `.woodev-dialog`, `__header`, `__title`, `__close`, `__body`,
`__footer`. Two thin mechanisms build the same parts; a new dialog never gets its own CSS or its own `<dialog>`:

| Screen kind | Mechanism | Where |
|---|---|---|
| React (records actions, account deletion, every later screen) | `<WoodevDialog title onRequestClose footer>` — a `@wordpress/components` Modal with its own header hidden; the Modal gives the overlay, focus trap, focus return and Esc | `src/components/woodev-dialog.tsx`; the bundle's SCSS says `@use '../components/woodev-dialog';` once |
| Plain PHP (product tab, order metabox) | `window.WoodevDialog.build( { title, closeLabel, footer } )` for a dialog the script fills, `WoodevDialog.attach( el )` for a `<dialog class="woodev-dialog">` that PHP printed; the native `<dialog>` gives the backdrop, focus trap and Esc | `woodev/marketplace/assets/js/admin/woodev-dialog.js`; PHP enqueues both files with `Dialog_Assets::enqueue()` and lists the returned handle as a dependency |

Why not the framework's `WoodevModal` (`woodev/assets/js/frontend/woodev-modal.js`): it is the storefront shell (own backdrop,
hand-made focus trap, scroll-lock class, CSS reset for a map's content), enqueued as a standalone script; the admin dialogs get
the same behaviour from the native element with no JS of their own. The pickup map keeps `WoodevModal`; it is a delivery
screen and is not touched.

Height: the React frame is capped at `calc(100vh - 64px)` (`100dvh` where known) and its content inherits the cap, so a tall dialog (the account deletion preview at 1024×768) scrolls its BODY while the header and footer stay in view; the Modal's own cap is a percentage of the overlay and let such a dialog run past the screen The WP/WC build on the rig also puts a `.components-modal__children-container` (`display: block`, no limit) between the content and our parts, so that element joins the flex column with the same cap; verified in Chromium at 1024×768, 1024×600, 1280×720 and 375×667 (footer inside the viewport, body scrolls; a short dialog is unchanged). `tests/js/woodev-dialog-css.test.ts` reads the rules, jsdom has no layout.

Rules: the «✕» is an EMPTY button (the stylesheet draws it as a mask); a footer button and the «✕» may both be named «Закрыть»,
so a test that means the footer button asks for it inside `.woodev-dialog__footer` (`tests/js/dialog-parts.ts`); a request in
flight passes `dismissible={ false }` (no «✕», no Esc).

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

> **Historical (recorded 25.11.2026):** the filing briefs of the design round. The framework module
> shipped from 2.0.29 and the later «as built» sections above describe it; model names here are of that date.

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
| F15 — Tools and diagnostics views | M · Haiku 5.5 | F6, F7, F10 | `woodev/marketplace/admin/pages/{tools,diagnostics}/**`, `src/marketplace-admin/views/{tools,diagnostics}/**`. Donor: `includes/admin/class-tools-page.php`, `includes/diagnostics/class-diagnostics-page.php` (logic) | PHP-defined tools (preview/confirm, result, progress) and the diagnostics view with the report copy; claim-taking tools disabled while the account claim is held. Every tool works on an account — there is no site scope (operator decision 14.11.2026, #1759): the merchant chooses the account only when the plugin has two or more, a single account is used silently, none leaves the empty state; the server validates the account on every route. `remote` defaults to true; the donor v1-migration tool is an account tool with `remote=false` (it migrates v1 data into the chosen account). |

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
- [Marketplace card importer spec](../specs/2026-11-14-marketplace-card-importer.md) — the importer's design (I1–I7, O6)
- [Marketplace admin UI spec](../specs/2026-11-15-marketplace-admin-ui.md) — the delivery-look redesign (#1776), C1–C3
- [Marketplace bulk operations spec](../specs/2026-11-16-marketplace-bulk-operations.md) — «все по фильтру», sync on link, link by category (#1786)
- [Architecture](architecture.md) — current module and settings responsibilities
- [Agent rules](../AGENT-RULES.md) — Rule 9 and preservation rules
- [ADR-007](../adr/007-react-admin-stack-wordpress-scripts.md) — runtime/build conventions
- [ADR-012](../adr/012-shipping-includes-stays-authoritative.md) — shipping include list
- [Schema](../DOCS-SCHEMA.md) — internal documentation conventions
