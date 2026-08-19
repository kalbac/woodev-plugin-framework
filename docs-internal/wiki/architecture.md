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

- `version` — the framework version the plugin bundles
- `backwards_compatible` — the oldest framework version it is compatible with

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

The constructor auto-initialises all framework subsystems and registers WP hooks; plugins override
the `init_*` methods to supply their own implementations. `__construct()` is an ordered list of
`init_*_handler()`/`load_*` calls ending with `add_hooks()`, which wires only base-owned hooks.

`VERSION` lives here — and **raising it on `main` publishes a release** (#285).

## Subsystems (all initialised inside `Woodev_Plugin::__construct`)

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
| `Woodev_License_Store` | License key persistence |
| `Woodev_License_Messages` | License admin messages |
| `Script_Handler` | Script/style enqueueing |
| `Woodev_Notes_Helper` | WC Admin inbox notes |

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
  (`Checkout_Field_Settings::effective()`, `Location_Provider_Registry::get_field_mode()`), so the
  merchant's original choice comes back the moment it becomes valid again.

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

The «Подсказки для адреса» switch is enforced in ONE place — `Location_Service::provider_for_level()`
forces `null` for the `address` level while the switch is off, before the chain is walked. Every
derived question (`get_levels_for_country()`, `get_level_owners_for_country()`,
`is_country_supported()`, the REST `/suggest` route) therefore agrees without re-checking it.
Whether the control should be OFFERED at all is a different question — the capability, not the
runtime answer — and is asked through `Location_Service::is_level_servable()`, which deliberately
bypasses both that gate and the resolution filter.

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

`tests/_fixtures/` ships seven plugins used by both suites: `woodev-test-plugin`,
`woodev-test-payment-gateway`, `woodev-test-shipping-method`, `woodev-edostavka-pilot-plugin`,
`woodev-realistic-payment-plugin`, `woodev-realistic-shipping-plugin`,
`woodev-yandex-pilot-plugin`.

Base classes: `tests/unit/TestCase.php` (Brain Monkey) and `tests/integration/TestCase.php` (WP
test scaffolding).

## Related

- [[v2-extension-point-pattern]] — how a plugin hooks into these seams.
- [[capability-gated-feature-seam]] — the capability gating pattern.
- `adr/001-bootstrap-platform-aware-loader.md`, `adr/003-platform-v2-minimal-framework-resolver.md`, `adr/004-platform-v2-plugin-loader-api.md` — the loader decisions.
- `adr/005-platform-v2-clean-break-policy.md` — what may break and what may never break.
- `AGENT-RULES.md` → Rule 3 — the registration contract in full.
