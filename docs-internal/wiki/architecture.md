# Framework architecture — subsystems, base classes, seams

> Compiled reference. Last compiled: 2026-08-16 (extracted from `CLAUDE.md`, which is now an
> entry point rather than a reference).
>
> Read this when you need to know **where a responsibility lives**. It is not loaded at session
> start — open it when the task touches a subsystem you have not worked on.

## Bootstrap & multi-version loading (`woodev/bootstrap.php`)

`Woodev_Plugin_Bootstrap` (singleton) is the entry point — **never instantiate it directly**.

v2 plugins register via `Woodev_Loader::register( __FILE__, [...] )` (or
`register_loader_definition()` directly). `register_plugin()` survives only as a v1 **tombstone**
that quarantines legacy callers and never registers.

Every loader definition MUST set:

- `framework_version` — the framework version the plugin bundles (mapped internally to `version`,
  which is why this line said `version` until s135; the validator requires `framework_version`)
- `backwards_compatible` — the oldest framework version it is compatible with (not validated, but
  without it a too-old plugin is never quarantined — `AGENT-RULES.md` → Rule 3)

On `plugins_loaded` the resolver loads the **highest** registered framework version for the whole
fleet, then initialises every compatible plugin. Plugins whose framework, WC or WP version is
incompatible are deactivated with an admin notice.

**Plugin type is declared solely by what the plugin class `extends`** — never by a flag or a
capabilities array (those were removed in s27).

Full contract: `AGENT-RULES.md` → Rule 3. Decisions: `adr/001`, `adr/003`, `adr/004`.

## Base plugin class (`woodev/class-plugin.php`)

`Woodev_Plugin` is the abstract base every plugin extends. Concrete plugins must implement:

- `get_file()` — return `__FILE__`
- `get_plugin_name()` — return the localized plugin name
- `get_download_id()` — return the EDD/store download id

The constructor auto-initialises the base subsystems and registers WP hooks (`Woocommerce_Plugin`'s constructor adds the WooCommerce-only ones, e.g. Blocks); plugins override
the `init_*` methods to supply their own implementations. `__construct()` is an ordered list of
`init_*_handler()`/`load_*` calls ending with `add_hooks()`, which wires only base-owned hooks.

`VERSION` lives here — and **raising it on `main` publishes a release** (#285).

## Subsystems (base constructor, or the platform base that owns them)

| Class | Purpose |
|---|---|
| `Woodev_Plugin_Dependencies` | PHP extension/function/setting dependency checking |
| `Woodev_Admin_Message_Handler` | Flash messages persisted across requests |
| `Woodev_Admin_Notice_Handler` | Dismissible WP admin notices |
| `Woodev_Plugins_License` | License key storage and validation |
| `Woodev_Plugin_Updater` | Pulls plugin updates from the Woodev store |
| `Woodev_Hook_Deprecator` | Fires `_doing_it_wrong` for deprecated hooks |
| `Woodev_Lifecycle` | Install/upgrade routines and milestone notices |
| `Woodev_REST_API` | Registers plugin REST API routes |
| `Woodev_Blocks_Handler` | Declares WC Cart/Checkout block compatibility |
| `Woodev\Framework\Setup\Setup_Wizard` | Admin onboarding wizard — neutral React-driven, opt-in via `get_setup_wizard_handler()` (WC wrapper: `Woocommerce_Setup_Wizard`) |
| `Woodev_Admin_Pages` | Plugin settings page registration |
| `Woodev_Plugin_Compatibility` | WP/WC version helpers |
| `Woodev_Order_Compatibility` | HPOS-compatible order data access |
| `Woodev_Script_Handler` | Script/style enqueueing (abstract, `woodev/handlers/script-handler.php`) |
| `Woodev_License_Messages` | License admin messages |
| `Woodev_Notes_Helper` | WC Admin inbox notes |
| `Woodev\Framework\Error_Reporting\Error_Reporter` | Opt-in, anonymised PHP error reports of OUR plugins to a Sentry-compatible receiver — installed once by the winning copy from `Framework_Resolver::load_plugins()` before any plugin code runs; requests only enqueue, `Dispatcher` sends from WP-Cron, no exception text is sent; spec `specs/2026-10-04-error-reporter-design.md` (#130) |

## Plugin variants

- **`Woodev_Payment_Gateway_Plugin`** (`woodev/payment-gateway/class-payment-gateway-plugin.php`) — a payment-gateway plugin declares its type by extending this class. Manages one or more `Woodev_Payment_Gateway` instances. Order/user/token admin UI lives in `woodev/payment-gateway/admin/`; gateway-specific REST endpoints in `woodev/payment-gateway/api/`.
- **`Woodev\Framework\Shipping\Shipping_Plugin`** (`woodev/shipping-method/class-shipping-plugin.php`) — a shipping plugin declares its type by extending this class. PSR-4 namespaced (`Woodev\Framework\Shipping\`).

## API layer (`woodev/api/`)

`Woodev_API_Base` handles HTTP communication. Extend one of:

- `Woodev_Abstract_API_JSON_Request` / `Woodev_Abstract_API_JSON_Response`
- `Woodev_Abstract_API_XML_Request` / `Woodev_Abstract_API_XML_Response`
- `Woodev_Abstract_Cacheable_API_Base` — transient-based request caching via `Cacheable_Request_Trait`

Requests/responses must implement `Woodev_API_Request` / `Woodev_API_Response`. Requests are logged
automatically via the `woodev_{plugin_id}_api_request_performed` action.

## Settings API (`woodev/settings-api/`)

`Woodev_Abstract_Settings` provides a WooCommerce-style settings page. Settings are defined as
`Woodev_Setting` objects registered through `Woodev_Register_Settings`.

Note: `Woodev_Setting::get_value()` returns a **cached** property — an `update_option()` mid-request
is invisible to it (gotcha `woodev-setting-get-value-is-cached-not-a-live-option-read`).

## Settings page — sections, actions and groups (`woodev/settings-page/`)

A `Settings_Provider` returns `Settings_Section`s; the registry serialises each into the schema the React
page (`src/settings-page/`) renders. An ORDINARY section is a list of setting ids plus, optionally,
`with_actions( Shipping_Tool[] )` (buttons under the fields, run through the tool REST route scoped to the tab).

**Groups (`Settings_Group`, `Settings_Section::with_groups()`, s165).** Related fields and actions can sit in ONE
titled card:

```php
Settings_Section::create( 'export', 'Отправка', [ 'token', 'mode', 'other' ] )
	->with_actions( [ $sync, $hooks_on, $hooks_off ] )
	->with_groups( [
		Settings_Group::create( 'hooks', 'Вебхуки', 'Уведомления о статусах.' )
			->with_fields( [ 'token', 'mode' ] )
			->with_actions( [ 'hooks_on', 'hooks_off' ] )   // ids of actions declared on the section
			->with_notice( 'Сайт доступен только локально — вебхуки не придут.' ),
	] );
```

- A group only NAMES members by id. Fields stay in the section's field map (values, validation, `show_if`,
  tooltips, Save are untouched) and actions stay in the section's flat action list (the REST run route is
  unchanged). Ids the section does not declare are ignored; an id an earlier group already took is not reused; a
  group left with no member is omitted (`Settings_Page_Registry::build_groups()`).
- Payload: the entry gains `groups: [ { id, title, description (kses), notice, fields: string[], actions: string[] } ]`
  — only when at least one group resolves. `fields` / `actions` stay complete and unchanged, so an ungrouped
  section serialises byte-for-byte as before.
- **Rendering.** Order is the section's field order: a group sits where its FIRST VISIBLE field is declared (a group
  with no visible field comes after every field; one whose members are all hidden by `show_if` renders nothing).
  A card shows title, description, its fields, then its actions as buttons in ONE row, then the notice (plus the
  distinct `status_text` of disabled actions, each once), then ONE result line — the last clicked button's.
  A selector-backed action (`Shipping_Tool::selector`) keeps its select inside the card — shared `ToolSelector` /
  `toolArgs()` from `tools-block.js`, so the run sends the same named arg as a `ToolCard` — and a changed selection
  clears the shared result. Box presets (`box_preset` fields) named by a group render as ONE table inside that
  group's card, after its ordinary fields (scalar save keys and complete rows preserved); ungrouped presets stay in the
  shared table below the fields.
  Ungrouped fields/actions render as before (`ToolsBlock` cards, below the fields). `GroupCard` is
  `src/settings-page/group-card.tsx`; not to be confused with `.woodev-field__option-group`, the inner card of ONE toggle.
- **Save button.** `sectionHasSaveButton()` (`app.js`): no «Сохранить» for a tools block or an ordinary section with
  no fields (an actions-only section used to show a dead one); a connection block keeps it as before.
- UI Kit gallery (`src/ui-kit-gallery`) shows a grouped-fields card and a grouped-actions card.
- **«Выгрузка заказов» is laid out as cards (s165).** `Shipping_Plugin::build_export_section()` gives EVERY exporting
  carrier the same cards, in order: **«Автоэкспорт»** (`auto_export_orders` toggle + `export_statuses`; the card
  description is the former toggle subtitle), **«Этикетки»** (the carrier's own fields of
  `get_export_section_setting_ids()`, e.g. CDEK's «Формат этикеток»), **«Статусы доставки»** (`status_delivered` — moved
  here from auto-export — and, for a carrier with a cron hook, the «Обновить статусы сейчас» button), then the carrier's
  own cards. Card ids `auto-export` / `labels` / `delivery-status`. A card with no member is not drawn («Этикетки» is
  absent for a carrier without label fields). The section lists its fields card by card (auto-export, carrier fields,
  delivered status), because a card renders where its FIRST field is declared. One «Сохранить» sits under all cards.
  «Дополнительно» is the framework's own bare section again (logging, hide-on-cart): no cards, no carrier extension.
- **A carrier adds to «Выгрузка заказов» (s165).** `Shipping_Plugin::get_export_section_extension()` (protected,
  default `[]`) returns `['actions' => Shipping_Tool[], 'groups' => Settings_Group[], 'description' => string]`; the
  framework appends the groups after its own cards, the actions after the refresh button, and the description after the
  section description. Wrong keys / types / elements are dropped with `_doing_it_wrong()`; a group id equal to a
  framework card id or an earlier group, and an action id already used on the tab (another section, the framework's
  refresh button, an earlier entry), is dropped the same way. The REST `run_tool` route still answers 409
  `woodev_settings_ambiguous_tool` if an id ever repeats across the tab's sections. The seam is skipped silently for a
  carrier that does not export orders. (It replaced `get_advanced_section_extension()` in the same PR — v2 clean break.)

## Shipping settings — the «Доставка» tab (`woodev/shipping-method/settings/`)

One tab on `Woodev → Настройки`, registered by `Shipping\Settings\Shipping_Settings_Tab`, holding
three sections — **«Локация» / «Поля» / «Карта»**. Each section keeps its own
`Woodev_Abstract_Settings` handler (`Location_Settings`, `Checkout_Field_Settings`,
`Pickup_Map_Settings`); `Composite_Settings_Handler` presents the three to the settings page as one.

**Section visibility is derived, never declared** — there is no `supported_features` key for it: the
tab exists when any active `Shipping_Plugin` does, «Локация» when some plugin needs a location
provider, «Поля» always while the tab exists, «Карта» when some plugin supplies a `Pickup_Handler`.

**Option namespaces are per-handler and never encode the tab id** — `woodev_location_*`,
`woodev_checkout_fields_*`, `woodev_pickup_map_*`. The tab's own id moved from `location` to
`shipping` without renaming a single stored key: option names are an installed-site data contract
(`adr/005-platform-v2-clean-break-policy.md`).

Two rules govern every option on this tab:

- **An unavailable option is disabled with a reason, never hidden.** `Woodev_Control::set_disabled()`
  → `Field_Schema` → the React field. Where only one VALUE is unavailable, the option list is
  narrowed instead and the reason appended to the description.
- **A stored value that is no longer allowed clamps on READ, and is never rewritten**
  (`Checkout_Field_Settings::effective()`, `Location_Provider_Registry::get_field_mode_region()`/
  `get_field_mode_settlement()`), so the merchant's original choice comes back the moment it
  becomes valid again.

### The two-instrument rule

`Checkout_Field_Policy` reaches the real checkout through exactly two seams, and which one a
setting uses decides which checkout it can reach:

| | Instrument A — `woocommerce_get_country_locale` | Instrument B — late `woocommerce_checkout_fields` |
|---|---|---|
| Controls | `priority` (order), `hidden`, `required` | presence (`unset`) |
| Reaches | classic **and** block checkout | classic only |
| Used by | field-order preset, `region_field=remove`, `postcode_field=remove` | the same two `remove` values, structurally |

Anything that must reach the block checkout has to travel through A — the block checkout never sees
`woocommerce_checkout_fields` at all (gotcha
`block-checkout-reads-country-locale-not-checkout-fields`). `address_field=hide_for_pickup`,
`postcode_field=hide_for_pickup` and `country_field=hide` are therefore **classic-only and
JS-driven**: PHP only publishes their effective values (and the pickup method ids) into the checkout
config, and `checkout-field-classic.js` acts on them.

**The block checkout's own adapter** (SP-11: the locality chooser, the pickup button, the
`woodev-shipping` Store API namespace) keeps its contracts in
[the SP-11 spec](../specs/2026-10-04-sp11-block-checkout-design.md) — «C-2a server transport
contract», «C-2b client contract», the C-3 rules — and what was actually measured to hold, with the
boundaries that are NOT supported yet, in its «Supported WooCommerce surface (C-4 measured)». Read
that section before promising a store anything about the block checkout: `hide_for_pickup` above
is one of the boundaries.

**Third-party field managers:** the late filter runs after everyone else has had their say, so the
framework can see the FINAL assembled fields, re-assert the settlement field it owns (present +
required), leave every other field alone, and record a note the tab shows.

### The `address_suggestions` gate

The «Подсказки адреса» switch is enforced in ONE place — `Location_Service::provider_for_level()`
forces `null` for the `address` level while the switch is off, before the chain is walked. Every
derived question (`get_levels_for_country()`, `get_level_owners_for_country()`,
`is_country_supported()`, the REST `/suggest` route) therefore agrees without re-checking it.
Whether the control should be OFFERED at all is a different question — the capability, not the
runtime answer — and is asked through `Location_Service::is_level_servable()`, which deliberately
bypasses both that gate and the resolution filter.

### Where a location provider's credentials live — two legitimate models

A `Location_Provider` chooses ONE of these, and the choice is visible to the merchant:

| Model | `get_settings_fields()` | `is_configured()` |
|---|---|---|
| **Own credentials** — the provider is the only thing using this key | declares its fields | inherited: `false` iff a declared `required` field is empty |
| **Carrier's credentials** — the key is shared by every call to that carrier's API | returns `[]` | **MUST be overridden** to answer from the carrier's own settings |

The second model exists because a carrier's API keys are not a location-layer concern: CDEK's
client id and secret authenticate every CDEK request, so they belong to the carrier's own settings
screen and the location provider merely reads them. The reference implementation is the test
fixture `Woodev_Test_CDEK_Location_Provider` + `Woodev_Test_CDEK_Integration`.

Two rules bind both models:

- **Overriding `is_configured()` is mandatory in the second model.** The inherited implementation
  derives its answer from declared `required` fields, so a provider with no fields reports
  `true` — it would claim to be configured while holding no credentials at all.
- **Never read credentials through the settings handler — use `get_option()` directly.** The
  handler only knows the fields it registered, but `is_configured()` is called on a provider even
  while ANOTHER provider is active (the D15 fallback chain asks the bundled provider whether it can
  still serve `address`). Going through the handler throws `Setting … does not exist`.

**Field ids are globally unique across providers.** Every provider's fields share the
`woodev_location_*` option namespace, and since #375 the settings surface registers EVERY
registered provider's fields at once — each hidden behind a `show_if` condition on
`active_provider`, so the form reacts to the select without a save round-trip. Two providers
declaring the same field id would silently overwrite one definition with the other; the registry
keeps the first registration and reports the conflict through `_doing_it_wrong()`.

The bundled DaData provider's own fields carry a WIDER condition: they are visible when DaData is
active **or** when the active provider cannot serve `address` for the store's country — because
then DaData is the only thing the fallback chain can still use for addresses, and its keys must be
reachable to enter. That predicate is deliberately country-scoped (a provider can serve `address`
in one country and not another), resolved through `Location_Service::resolve_default_country()`.

## Licensing (`woodev/licensing/`)

License validation has its own API layer (`woodev/licensing/api/`) for talking to the Woodev store.
The updater (`woodev/licensing/updater/`) drives the plugin update mechanism.

## Lifecycle & upgrades (`woodev/class-lifecycle.php`)

Override `Woodev_Lifecycle` per plugin: define the `$upgrade_versions` array and add methods named
`upgrade_to_X_Y_Z()`. Install/upgrade events are stored in the DB (last 30). Milestone notices
prompt users for reviews after key actions.

## Box packer (`woodev/box-packer/`)

Self-contained shipping box-packing algorithm. Implement `Woodev_Packer_Item_Interface` and
`Woodev_Packer_Box_Interface`; use a `Woodev_Abstract_Packer` subclass (`Woodev_Packer_Single_Box`,
`Woodev_Packer_Separately`, `Woodev_Packer_Virtual_Box`).

`Woodev_Packer_Dispatcher::pack()` routes four algorithms — `virtual`, `separately`, `single` and `boxes`
(`Woodev_Packer_Boxes`, #1138). Shipping methods combine enabled **store boxes** («Доставка» →
«Упаковка», `Boxes_Settings`, option `woodev_boxes_boxes`, in store units) with enabled presets of
only their own carrier (`Shipping_Plugin::get_box_presets()`, `Packaging_Settings`). Carrier presets
use fixed cm/kg units. Selection maximises packed units to reduce parcels; store boxes win on equal
fill, followed by the existing smallest-volume tie-break. The WC dispatcher alone reads the store
list when none is passed. Its optional fourth argument chooses `single` or `separately` for leftovers.
Every `Woodev_Packer_Package_Result` reports source item allocation (`get_items()`: cart-item key /
order-item id, product id, quantity), box id, and origin (`store`, `carrier`, or empty). Export
retains the same item-allocation contract.

`Packaging` converts store box rows to packer cm/kg and preserves carrier cm/kg declarations. It
computes per-parcel amount/percentage costs and exposes carrier-priced packed boxes as id/count
pairs for the carrier's quote request.
`Shipping_Method::calculate_rate()` adds store/fixed/merchant box surcharges once after the quote;
carrier-priced presets are never charged again there. Carrier defaults and instance overrides
cover `packing_algorithm` and `unpacked_algorithm`; stored legacy algorithms remain readable.
The rate-cache context includes box settings, leftovers and per-line contents values.

## Additional carrier services (`Carrier_Service`, #1145)

A carrier declares its «Дополнительные услуги» by overriding `Shipping_Method::declare_services()` and
returning `Carrier_Service` objects (code, merchant-facing name, optional parameter name + source
`declared_value` / `custom`, optional `selectable = false` for a service the carrier adds by itself).
Declaring the list IS declaring support: nothing declared means no control, no resolver output and an
unchanged rate-cache identity. The instance option `services` (v1 CDEK's key) is a `multiselect` rendered
only when a selectable service exists; its stored shape is a plain list of codes — parameters are
computed, never stored. The carrier reads the SAME resolver on both ends, the way insurance does:
`resolve_services_for_package( $package, $packed )` for the rate request and
`resolve_services_for_order( $order, $items, $packed )` for the export, each returning
`[ code, name, parameter ]` entries. A parameter that needs the carrier's own arithmetic (the number of
`CARTON_BOX_*` boxes from the packed parcels) goes through `resolve_service_parameter()`; the filter
`woodev_shipping_resolved_services` can add or drop entries. `get_rate_cache_context()` keys the resolved
list (codes AND computed values) for a method that declared services.

**What was quoted is what gets billed — the quote snapshot.** `calculate_rate()` adds the resolved services
to the rate as flat rate meta under `Shipping_Method::META_QUOTED_SERVICES` (`_woodev_quoted_services`, a
NEW data contract — never renamed), a JSON string
`{"version":1,"services":[{"code","name","parameter"}, …]}`. It rides the existing rate-meta →
order-shipping-line path (WooCommerce's checkout copies `meta_data` onto the line; the admin order wizard's
`apply_shipping_line()` does the same on a re-quote) and is cached with the rate. `resolve_services_for_order()`
reads it from the order's shipping line of this method (`get_quoted_services()`): the snapshot WINS over the
current instance settings, so editing the zone method after an order was placed changes neither its codes
nor its parameters. Policy: a quote of «no service» is stored as an EMPTY list (an answer, not an absence);
an order with no usable snapshot (placed before #1145, unknown `version`, bad JSON) falls back to resolving
from the current settings; the snapshot is refreshed only when the shipping line is replaced by a new
quote (admin re-quote) — nothing else rewrites it; a split shipment keeps the snapshot's codes and frozen
parameters but values a `declared_value` service from its own lines. **Known limitation — first line only:**
an order with several shipping lines of one method (multi-package checkout) is read from the FIRST matching
line (`Shipping_Helper::get_order_shipping_item()`); the framework has no association between a shipment /
export and its shipping line (no line id, no package index — the export is handed product lines), and every
other per-line datum takes the first line too, so per-package custom service parameters of the second and
later packages are exported from the first quote. The admin order wizard writes exactly one line, so it is not
affected; closing the gap needs a framework-wide line↔shipment link, not a local fix. A carrier's custom
`resolve_service_parameter()` that reads anything beyond the package, the instance settings and the
packing settings must add it to the cache key through its `get_rate_cache_context()` override.

## Utilities (`woodev/utilities/`)

- `Woodev_Async_Request` — WP async (non-blocking) HTTP requests
- `Woodev_Background_Job_Handler` — WP background processing queue
- `Woodev_Job_Batch_Handler` — batch job processing with admin UI
- `Woodev_String_Conversion` — Cyrillic-to-Latin transliteration

## Test fixtures

`tests/_fixtures/` ships **eight** plugins used by both suites: `woodev-test-plugin`,
`woodev-test-payment-gateway`, `woodev-test-shipping-method`, `woodev-edostavka-pilot-plugin`,
`woodev-realistic-payment-plugin`, `woodev-realistic-shipping-plugin`,
`woodev-yandex-pilot-plugin`, `woodev-entry-path-fixture` (the v2 entry path's in-repo consumer,
added #763). `tests/_fixtures/dadata/` is JSON response data, not a plugin — count the directories
carrying a `Plugin Name:` header.

Base classes: `tests/unit/TestCase.php` (Brain Monkey) and `tests/integration/TestCase.php` (WP
test scaffolding).

## P6 gate evidence

> Moved here from `CURRENT-STATE.md` in s91 — it labelled itself reference and pointed at this file.

Base `Woodev_Plugin` is platform-neutral (**zero** WC/HPOS-named methods; enforced by
`PlatformNeutralBaseHasNoWcMethodTest`, `PlatformNeutralRestApiTest`, `BootstrapRegistrationTest`)
and not a god-object (`woodev/class-plugin.php` — ~1,274 lines / 74 methods at the P6 gate; 1,668 lines by s135).

## Checkout location layer — the contract facts that outlive their cards

> Moved out of `CURRENT-STATE.md` in s119 (#778). Each of these was learned by closing a card, and
> each keeps biting after that card is history — which is why they belong in a reference rather than
> in a state file that is supposed to hold only what is true right now.

**`null` from `resolve_key()` means exactly one thing:** "asked, answered, does not know this key".
D6 deletes the row on it. Every *other* failure THROWS — so a `null` must never be used as a general
error signal, and a caught throw must never be flattened into one.

**`compose( ...parse( $key ) )` is NOT the identity for a DERIVED key.** A test pins this. Code that
round-trips a key through parse/compose to "normalise" it will silently rewrite derived keys into
something else.

**`set_label()` applies only to fields WooCommerce does not define itself.** For a native field,
`address-i18n.js` rewrites the rendered `<label>` AFTER render, so a server-side label never sticks.
Gotcha `wc-address-i18n-reshows-fields-with-an-inline-display-block`.

**A §8 adapter of ours can look exactly like a third party misbehaving** (#466/#471). Guard on
OWNERSHIP, never on a name heuristic. Gotcha
`the-classic-adapter-reverts-a-select-the-location-cascade-owns`.

**The layer REPORTS a builder conflict, it does not throw** — 17 `_doing_it_wrong()` against a
single `throw`, and that throw is a failed lookup. A location field's `takeover_condition` is
dropped and reported (#474, s113).

**The «required» rule is implemented TWICE** — server-side `validate()` and the browser's
`refreshGate()` — so fixing one leaves the other. Gotcha
`the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other`.

Related invariants, each surviving its own card: `validate()` enforces a takeover field's `required`
only when its condition owns the field AND WooCommerce rendered it (#708); ask
`Location_Record::is_within()`, never `ancestors()` raw, because it is reflexive and a settlement
that IS its own region publishes no ancestors (#707); `is_pickup_shipping()` is the single source
for the other three declarations, resolved LAZILY (#709).

## Three subsystems carry an ENFORCED construction contract

Moved here from `CURRENT-STATE.md` in s139 — it is a framework guarantee, not session state.

A subclass that did not build its notices handler, its licence or its lifecycle gets
`_doing_it_wrong()` under `WP_DEBUG` and the framework builds a default instead (#758/#759). The reason
it is enforced rather than documented: those three are dereferenced **17 / 13 / 2** times without a null
check.

## Shipping orders page — the contract facts that outlive their cards

Moved here from `CURRENT-STATE.md` in s139: they are reference, true regardless of which card is open.

- **It lives under the WooCommerce menu, inside WooCommerce's own React app** —
  `wc_admin_register_page()` + `TableCard`, at
  `admin.php?page=wc-admin&path=/woodev-shipping-orders`. It highlights its parent menu item
  **client-side**, from the `wpOpenMenu` property its `woocommerce_admin_pages_list` entry declares —
  WordPress never sees `path`. Design: §D1, §D7. The menu item is placed AFTER «Orders» by reordering
  `$submenu` on the neighbour's slug, never by position (gotcha
  `wc-admin-register-page-ignores-order-and-its-neighbours-declare-no-position`).
- **Route B covers `@woocommerce/{navigation,date,currency}` too** — they are absent from
  `node_modules` AND `package-lock.json`, so the page reads `window.wc.*` and declares
  `wc-components` / `wc-navigation` / `wc-admin-app` / `wc-date` / `wc-currency` by hand — **never
  `wc-settings`** (gotcha `declaring-wc-settings-as-a-script-dependency-silently-drops-the-bundle`).
- **Every filter is URL-driven**, and the query is read in a LATER effect, never inside the history
  listener (gotcha `addhistorylistener-fires-before-the-url-changes`). «All time» is the ABSENCE of
  the date parameter, never a value (gotcha `wc-date-throws-on-a-half-filled-custom-range`).
- **The action set is declared ONCE** (`Order_Actions::for_order()`) and serves TWO surfaces — the
  table column and the metabox; a label or tooltip is edited only there.
- **An extra action may declare INPUT FIELDS (#1180)** — a `fields` list on its `woodev_shipping_order_actions`
  entry, sanitised by `Order_Action_Fields::sanitize()` (four types only: `date`, `select`, `time_range`,
  `textarea`; deliberately no form engine). The values travel as `payload` — a REST body object on
  `/shipping/orders/{id}/actions/{action}`, `payload[<id>]` on the metabox's admin-post — and
  `Order_Actions::resolve_payload()` validates them against the declaration BEFORE the handler runs: the REST route
  answers **422** `woodev_shipping_orders_invalid_payload` with `data.errors = [ { field, code, message } ]`, the
  metabox flashes one notice. The handler gets the cleaned values as `array $payload` — the last argument of
  `Order_Actions::perform()` and the fifth of `woodev_shipping_perform_order_action`; only declared ids come out,
  every one present. Bulk skips an action with fields (no values to run with). The UI: the orders page opens a
  `@wordpress/components` `Modal` (`action-input-modal.tsx`), the metabox the vanilla `WoodevModal` shell
  (`order-metabox-actions.js`, fields as JSON in `data-fields`) — gotcha
  `the-modal-shell-handles-are-registered-on-the-storefront-hook-only`.
- **An action may declare its own ICON (s164)** — an `icon` string on the same `woodev_shipping_order_actions` entry,
  next to `fields`: a **Dashicons slug without the `dashicons-` prefix** (`'calendar-alt'`), validated by
  `Order_Actions::sanitize_icon()` (`^[a-z][a-z0-9-]*$`, anything else is dropped). Both surfaces draw
  `<span class="dashicons dashicons-{slug}">` — the orders page in the «Действия» column, the metabox from its PHP view —
  so one slug gives one glyph everywhere (`@wordpress/components`' `Dashicon` knows only a fraction of the set, which is
  why it is not used). No `icon` → the neutral `Order_Actions::FALLBACK_ICON` (`controls-play`), **never a gear**. The
  framework's own: export `upload`, update `update`, cancel `remove`, edit `edit`, waybill `media-document`,
  barcode `tag`. `destructive => true` additionally draws the button light red (`rgba( $error, 0.12 )`, `$error-text`,
  UI-kit tokens) on both surfaces.
- **Three small additions to the field / action contract (s164).** A field of ANY type may carry `help` — one plain
  sentence (tags stripped, whitespace collapsed, cut at `Order_Action_Fields::MAX_HELP_LENGTH` = 500), drawn under the input
  on both surfaces (`.woodev-action-form__help`); absent when none was declared, so a field keeps the shape it had. A
  **destructive** action may carry `confirm` — the sentence its confirmation asks («После отмены СДЭК может не принять
  новый вызов»); `Order_Actions::sanitize_actions()` keeps it for a destructive action only. The orders page
  (`confirmQuestion()`) and the metabox (`data-confirm`) ask it; without one they ask their generic question as before.
  The fifth field type, `orders`, is NOT part of a per-order action's vocabulary — see the toolbar bullet.
- **A parcel «handed to delivery» and «Оформить отказ» (#1204)** — two optional declarations on the carrier's
  `Abstract_Shipment_Handler`, no registration beyond the handler it already has:
  `get_handed_over_statuses(): string[]` (canonical `Delivery_Status` states from which the carrier no longer
  deletes the shipment; default `[]` = today's behaviour) and `supports_refusal(): bool` + `refuse( WC_Order ): Action_Result`
  (+ optional `get_refusable_statuses()`, defaulting to the handed-over set). `Order_Actions::is_handed_over()` is the
  one answer (exported, not in `CANCEL_RETIRED_STATUSES`, state declared): `Order_Automation::run_cancel()` sends NO
  request for such an order and writes a note («Посылка уже в пути…», pointing to the button only when
  `Order_Actions::can_refuse()`), and the order-edit screen enqueues `order-in-transit-warning.js` (plain script, text
  and watched status `wc-cancelled` from PHP) so picking «Отменён» shows a non-blocking inline warning. The action
  `Order_Actions::REFUSE` is `destructive` with a `confirm` that names the paid return, runs only from a click (the
  bulk route skips it, `run_cancel()` never calls `refuse()`), and `Order_Actions::perform()` writes the order note
  and clears the «не отменён у перевозчика» marker on success. «Отменить» stays on offer — a manual cancel is the
  merchant's call.
  **Two server-side guards on the refusal (round 1):** the request must carry the merchant's explicit yes —
  `Order_Actions::unconfirmed_reason()` is checked by BOTH entry points (REST `…/actions/refuse` sends `confirmed: true`
  → else 400 `woodev_shipping_orders_confirmation_required`; the metabox's admin-post form posts `confirmed=1` after its
  `data-confirm`), and a success is recorded by the framework in order meta `_woodev_shipment_refusal_requested`
  (value = the carrier order id, plus `…_at` = Unix time; `Carrier_Cancel::mark_refusal_requested()`). While the record
  matches the stored carrier id the action is no longer offered — a retry with a stale local status cannot reach the
  carrier twice — and the row carries `refusal_requested` («Отказ оформлен, ждём возврата»). Re-exporting changes the
  carrier id, so the record lapses by itself; a failure writes nothing, so a retry works. The adapter's `refuse()` keeps
  no bookkeeping of this.
- **Row flags (s164)** — small badges under the tracking number on the orders page and under the details table of the
  order's metabox. A carrier fills them through `woodev_shipping_order_row_flags( array $flags, \WC_Order $order,
  ?Orders_Provider $provider )` (starts `[]`); each flag is `[ 'label' => string, 'tone' => 'ok'|'warn'|'error'|'info'|'muted',
  'title' => string (tooltip, optional), 'icon' => string (Dashicons slug, optional) ]`. A flag WITH an `icon` is drawn
  icon-only: the glyph in its tone's colour (`warn` = the `$warn` design token) right after the tracking number in one
  nowrap line (`.woodev-orders-tracking-line`); the slug `warning` is drawn as the triangle-with-«!» SVG of
  `@wordpress/icons` (`error`, 18 px, `currentColor`), any other slug as the Dashicon of that name, the label as its accessible name and its tooltip (`title` when given, else the label); the metabox does the
  same beside its «Трек-номер» line, and falls back to an icon-only list item when the order has no tracking line. A flag
  without an icon stays a badge under the number. `Order_Row_Flags::sanitize()` drops malformed / empty / repeated labels,
  cuts a label at 60 characters, keeps at most 3 and runs `icon` through `Order_Actions::sanitize_icon()` (the same slug rule
  as action icons); the row carries them as `flags` (always present, `[]` when none). ⚠ The filter
  runs for EVERY row of every page and for every row rebuilt after an action — a callback reads meta and options only, never
  the carrier's API. Tones are the delivery badge's own five (a jest test pins server list = TS type = SCSS rules).
- **Toolbar actions (s164)** — a page-level button above the orders table that opens a dialog and runs ONE thing for SEVERAL
  orders («Вызвать курьера»: one intake per chosen order). `Toolbar_Actions` sanitises and performs; `Toolbar_Controller`
  maps it to HTTP. A carrier supplies four filters:

  | filter | answers | shape |
  |---|---|---|
  | `woodev_shipping_orders_toolbar_actions( array $actions )` | which buttons exist | `[ 'id' (a-z0-9_-), 'provider' (**required**: the owning carrier's id — an entry without it is dropped), 'label', 'title'?, 'icon'? (Dashicons slug), 'count'? (int), 'visible'? (bool) ]` — hidden when `count` is `0`, unless `visible => true` (keeps a «Заявки» tab reachable); **cheap**, runs on every page load and after every action |
  | `woodev_shipping_orders_toolbar_dialog( ?array $dialog, string $action_id )` | the dialog, asked for when it opens and again after every run | `[ 'title'?, 'description'?, 'tabs' => [ form tab, list tab, … ] ]` (≤ 4 tabs, **one** form tab) |
  | `woodev_shipping_perform_toolbar_action( Action_Result $result, string $action_id, \WC_Order $order, Orders_Provider $provider, array $payload )` | run the submitted form for ONE order, once per chosen order — only for orders of the action's `provider`: the framework compares the order's resolved carrier id first and answers a per-order failure on a mismatch, the handler is never called | `Action_Result::success( '', 'note'? )` / `::failure( 'reason' )`; `$payload` is the validated shared values WITHOUT the orders field |
  | `woodev_shipping_perform_toolbar_row_action( Action_Result $result, string $action_id, string $tab_id, string $row_id, string $row_action )` | a button of a list row | same `Action_Result`; the framework has already checked the button is on that row of the CURRENT dialog |

  A **form tab** is `[ 'id', 'type' => 'form', 'label', 'submit_label'?, 'description'?, 'fields' => [ … ] ]` — the
  #1180 field types plus **exactly one** `orders` field: `options` = `[ [ 'value' => order id, 'label' => '#1047 · Екатеринбург' ], … ]`
  (≤ 100), every option preselected unless a `default` list says otherwise; its payload value is a list of option values.
  A server-side value outside the options is refused whole (`invalid_option`), an empty selection is `required` whether or
  not the field says so. A **list tab** is `[ 'id', 'type' => 'list', 'label', 'columns' => [ [ 'id', 'label' ], … ],
  'rows' => [ [ 'id', 'cells' => [ column id => text ], 'actions' => [ [ 'action', 'label', 'title'?, 'destructive'?,
  'confirm'?, 'icon'? ], … ] ], … ], 'empty'? ]` (≤ 200 rows; a missing cell is `''`).

  Routes (all under `woodev/v1/shipping/orders/toolbar-actions`, `X-WP-Nonce`): `GET` → `{ actions }` (page capability);
  `GET /{id}` → `{ id, dialog }` (page capability); `POST /{id}` body `{ payload }` → `{ action, requested, succeeded, failed,
  results: [ { id, order_number, ok, message } ], messages: { success?, error? }, dialog }` — always 200 once the payload is
  valid, **422** `woodev_shipping_orders_invalid_payload` with `data.errors = [ { field, code, message } ]` otherwise
  (`edit_shop_orders`); `POST /{id}/rows` body `{ tab, row, action }` → `{ message, dialog }`, 400 when the CURRENT dialog does
  not offer that button, 502 with the carrier's reason on a failure. An unknown action or a carrier with no dialog is a 404.
  The `orders` option values must be positive decimal ids (`/^[1-9][0-9]*$/`); any other option is dropped. The dialog
  keeps ONE mutation in flight across its tabs (submit and row buttons, fields, close are all locked while one runs) and
  keeps the per-order summary on screen after EVERY run — a full success included — until the merchant closes it.
  Orders run **sequentially** inside one request (a carrier call each), so ten orders cost ten calls. The client multi-select
  is `@wordpress/components`' `FormTokenField` (`orders-select.tsx`; the token is the order's label, so typing a city finds it),
  not WooCommerce's `selectWoo`, which is a jQuery plugin bound to PHP-rendered `<select>` markup.
- **The metabox's buttons are ONE group in ONE row (s164):** with more than two actions they are icon-only (tooltip
  + `aria-label` carry the label), with one or two they carry their text. A carrier DOCUMENT (`Order_Actions::DOCUMENTS`:
  `waybill`, `barcode`) is never posted to admin-post — it is not in `for_order()`, so the gate refuses it; the button
  carries `data-document-url` (the documents REST route) + `data-rest-nonce` and `order-metabox-actions.js` fetches it
  like the orders page does. Gotcha `woodev-modal-css-resets-display-so-a-single-class-form-loses-to-it`.
- **Extra display lines (#1180):** `woodev_shipping_order_metabox_fields( $fields, $order, $provider )` extends the
  metabox list and `woodev_shipping_orders_preview_fields( $fields, $order, $provider )` the order preview opened from
  a row (`extra_fields` in the preview response). One line shape for both —
  `Order_Row_Builder::sanitize_display_fields()`: `label`, `value`, optional `url` (http/https), optional `tone`.
- **«New» is `is_exported=false`, derived from a NON-EMPTY `carrier_order_id`** (#860, settled and
  shipped). Every witness — the REST arg, the «Все / Новые» links, the carrier counts, the badge — reads
  it through the SAME `Orders_Query`, which is why their numbers agree by construction rather than by
  coincidence. Moved here from `CURRENT-STATE.md` in s139.
- **The carrier marker is a two-sided contract (#967, #710 I1b).** The page FINDS an order by the
  presence of `Orders_Provider::get_marker_meta_key()` (an `EXISTS` clause; `Orders_Id_Resolver`
  drives on the same key) and NAMES its carrier through `resolve_provider_for_order()`, which reads
  the VALUE — so the value must be a **non-empty scalar**: `''`/`false` list the order but leave its
  row and metabox ownerless, and an array raises «Array to string conversion» on every call (measured,
  #962 I0; `Order_Marker::is_valid_value()`). The framework never invents the value: the provider
  declares `marker_writer` (`fn( WC_Order, array $context ): void`, optional in `create()` so installed
  providers keep constructing; a provider without one is listed but never created/edited for).
  `Order_Marker` runs it from `Checkout_Handler::persist_values()` — the one core the classic
  checkout, the Store API checkout and the admin editor share — for the provider whose method id is on
  one of the order's shipping lines (the checkout handlers run for EVERY order, once per active
  plugin), saves the order's meta, and verifies the marker the way the page reads it; a broken writer
  is logged and never breaks the order.
- **The admin order wizard's server side is `Order_Editor` + three routes (#968, #710 I3).**
  `POST woodev/v1/shipping/orders` (create), `PUT …/orders/{id}` (update) and `GET …/orders/{id}/edit`
  (the prefill) are a thin transport (`Order_Editor_Controller`, gated `edit_shop_orders`) over one
  service. The **transport contract**: 401/403 from WordPress, 404 unknown order OR not a row of the
  page, 409 not editable, 422 `data.errors` = `[ { field, code, message } ]` (dotted request path), 201
  `{ id, number, message }` / 200 — declared by the service, not by REST arg schemas (a schema mismatch
  would add a 400 with a second error format). The request carries the chosen rate and point; the
  rates / points routes only PRODUCE them (`Order_Payload_Validator` documents the shape). **One
  writer:** the owning plugin's `Checkout_Handler::persist_values()` (managed fields + marker, with
  `refresh` on an edit) and `Pickup_Handler::persist_full_point()`; the three `checkout_*` hooks stay
  checkout-only, an admin save fires `woodev_shipping_{prefix}_admin_order_saved`. **Editable-state
  policy is ONE method** — `Order_Actions::is_editable()` / `not_editable_reason()` (exported, final
  status, finished delivery) — read by the row action and by the routes, and evaluated TWICE on an
  update (before validation, and again on a fresh read before the writes: the stale-row race). **No
  explicit «New order» trigger** — `set_status()` on a fresh order already sends it through
  WooCommerce's `pending_to_*_notification` (#962 I0, contradiction 1); an extra one double-sends.
- **A carrier registers its provider AND shipment handler on EVERY request (#1007, #1010).** Not only
  under `is_admin()`: `Order_Automation::handle_status_change()` and the Action Scheduler runners
  (auto-export, delayed retry, auto-cancel) act only for a carrier whose `Orders_Provider` and handler are
  registered in THAT request, and the requests that move orders are mostly not admin — a gateway's
  IPN/webhook (`payment_complete()` on the storefront), REST, WP-Cron. Register on `init` /
  `plugins_loaded` and pass the `Shipping_Plugin` to `register_provider()`; a provider without it has
  no auto-export (`_doing_it_wrong()` from the admin menu under `WP_DEBUG`). Registered only in admin, auto-export, retry and
  auto-cancel silently do nothing off the manager's screen. The same rule decides the «Выгрузка заказов» settings
  (#1014): `Orders_Registry::plugin_exports_orders()` is evaluated per request and the React settings page
  reads its schema over REST (not `is_admin()`), so a carrier registered only under `is_admin()` loses
  the «Выгрузка заказов» section from the UI.
- **The auto-export settings («Выгрузка заказов») live on the carrier's ONE tab of `woodev-settings`, not on the
  WooCommerce Integrations tab (#1007, #1010 round 3, #1014).** `Shipping_Plugin::get_settings_providers()`
  returns a single composite provider (`Composite_Settings_Handler`, tab id = the plugin id). A carrier does
  NOT override it and does not register a second provider under its plugin id (`Settings_Page_Registry::
  build_tabs()` keeps the first and reports the duplicate with `_doing_it_wrong()`): its own sections come
  from `get_tab_settings_providers()` (handler + sections of each returned `Settings_Provider`, merged ahead
  of the framework's sections — the order on a carrier tab is the carrier's own, «Упаковка» (`Packaging_Settings`, only when `uses_boxes()`), «Выгрузка заказов», «Дополнительно» (`Advanced_Settings`, always last); a setting-id clash is reported and the contribution left out; a connection section — handshake ones with no
  setting ids included — is served by the contributing handler). «Выгрузка заказов» (`Export_Settings`, options `woodev_{plugin id}_export_*`) is added
  only when `Orders_Registry::plugin_exports_orders()` — the plugin owns an `Orders_Provider` AND a shipment
  handler in that request — and a rates-only carrier still gets a tab, because «Дополнительно» is always there. The v1 keys
  (`auto_export_orders`, `export_statuses` in `woocommerce_{id}_settings`) are carried over once, on the
  first construction of the handler, and left in place.

## Subsystem phase status

> Moved out of `CURRENT-STATE.md` in s119 (#778) — a matrix that changes once every several
> sessions is reference, not state. The live programme stage stays in `CURRENT-STATE.md`.

| Phase | Code | Browser-verified | Notes |
|-------|------|------------------|-------|
| Framework Core | ✅ | ✅ | Bootstrap, Plugin base, Lifecycle — stable |
| Payment Gateway | ✅ | ✅ | `class-payment-gateway.php`: ~3,632 lines (whole tree ~13.9k); trait-extraction candidate (#117, held behind #639) |
| Shipping Method | ✅ | ✅ | PSR-4 namespaced |
| Licensing | ✅ | ✅ | EDD store integration; React license page on core `woodev/v1` REST |
| Settings API | ✅ | ✅ | Typed settings framework |
| Settings React page (SP-1) | ✅ | ✅ | `Woodev > Настройки`: registry + `woodev/v1/settings` REST + React surface on the UI-kit |
| Setup wizard (UK-3/4) | ✅ | ✅ | React wizard on the shared UI-kit (PR #99) |
| Box Packer | ✅ | ✅ | Shipping box-packing algorithm |
| REST API | ✅ | ✅ | Plugin REST routes |
| PHPStan | ✅ | — | Level 3, **no baseline** (`phpstan-baseline.neon` removed; do not reintroduce) |
| Documentation | ✅ | — | Two-tier: `docs/` (GH Pages) + `docs-internal/` (AI agents) |

## Related

- [v2-extension-point-pattern](v2-extension-point-pattern.md) — how a plugin hooks into these seams.
- [capability-gated-feature-seam](capability-gated-feature-seam.md) — the capability gating pattern.
- `adr/001-bootstrap-platform-aware-loader.md`, `adr/003-platform-v2-minimal-framework-resolver.md`, `adr/004-platform-v2-plugin-loader-api.md` — the loader decisions.
- `adr/005-platform-v2-clean-break-policy.md` — what may break and what may never break.
- `AGENT-RULES.md` → Rule 3 — the registration contract in full.
