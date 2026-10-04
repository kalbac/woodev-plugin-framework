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
  auto-cancel silently do nothing off the manager's screen. The same rule decides the «Выгрузка» settings
  (#1014): `Orders_Registry::plugin_exports_orders()` is evaluated per request and the React settings page
  reads its schema over REST (not `is_admin()`), so a carrier registered only under `is_admin()` loses
  the «Выгрузка» section from the UI.
- **The auto-export settings («Выгрузка») live on the carrier's ONE tab of `woodev-settings`, not on the
  WooCommerce Integrations tab (#1007, #1010 round 3, #1014).** `Shipping_Plugin::get_settings_providers()`
  returns a single composite provider (`Composite_Settings_Handler`, tab id = the plugin id). A carrier does
  NOT override it and does not register a second provider under its plugin id (`Settings_Page_Registry::
  build_tabs()` keeps the first and reports the duplicate with `_doing_it_wrong()`): its own sections come
  from `get_tab_settings_providers()` (handler + sections of each returned `Settings_Provider`, merged ahead
  of «Выгрузка»; a setting-id clash is reported and the contribution left out; a connection section — handshake ones with no
  setting ids included — is served by the contributing handler). «Выгрузка» (`Export_Settings`, options `woodev_{plugin id}_export_*`) is added
  only when `Orders_Registry::plugin_exports_orders()` — the plugin owns an `Orders_Provider` AND a shipment
  handler in that request — and a rates-only carrier with no sections of its own gets no tab. The v1 keys
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
