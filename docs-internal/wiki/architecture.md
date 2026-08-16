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
