# Settings — the typed API, the page, the wizard

Source of truth: `woodev/settings-api/` (`Woodev_Abstract_Settings`, `Woodev_Setting`, `Woodev_Control`),
`woodev/settings-page/` (`Settings_Page_Registry`, `Settings_Provider`, `Settings_Section`,
`Composite_Settings_Handler`, `Field_Schema`, the two connection interfaces), `woodev/setup/`
(`Setup_Wizard`, `Step`), `woodev/rest-api/controllers/class-rest-api-settings-page.php`.
Rules: `docs-internal/AGENT-RULES.md` → Rule 8 (where settings live) and Rules 10a–10c (copy).
Worked example for every control type: `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php`
(`Woodev_Test_Settings`, `Woodev_Test_Plugin::get_settings_providers()`, `Woodev_Test_Setup_Wizard`).
`ADR`: `docs-internal/adr/008-conditional-fields-operator-set.md` (`show_if`).

## Where settings live

**On `Woodev → Настройки`** — the neutral admin page `Settings_Page_Registry::PAGE_SLUG`
(`woodev-settings`), served over REST (`woodev/v1/settings…`) and rendered by a React surface. Never on
WooCommerce → Settings → Integrations by default, and never on a self-built top-level page. The
Integrations tab remains available as a deliberate EXCEPTION with its own seam
(`Shipping_Plugin::get_integration_handler()`, storage `woocommerce_{plugin_id}_settings`); the boundary
between default and exception is the operator's call, so bring the concrete case to him instead of
deciding it (Rule 8). Which sections a SHIPPING plugin must have is in
[../woodev-shipping-plugin/SKILL.md](../woodev-shipping-plugin/SKILL.md) — a carrier does not override
`get_settings_providers()` the way a plain plugin does.

## Contribute a tab

Override `Woodev_Plugin::get_settings_providers(): array` (public; default `[]`) to return
`\Woodev\Framework\Settings\Settings_Provider[]` — one tab each. `Woodev_Plugin::init_settings_page()`
already registers the plugin with `Settings_Page_Registry`.

```php
public function get_settings_providers(): array {
    return [
        \Woodev\Framework\Settings\Settings_Provider::create_with_sections(
            'my-tab',                       // tab id (blank → the handler id)
            'My plugin',                    // tab label
            $this->get_settings_handler(),  // a Woodev_Abstract_Settings subclass
            [ 'legacy_page' => 'wc-settings&tab=integration&section=my' ],  // optional args
            \Woodev\Framework\Settings\Settings_Section::create( 'general', 'General', [ 'api_key', 'mode' ], 'Optional description.' ),
            \Woodev\Framework\Settings\Settings_Section::create_connection( 'api', 'Connection', [ 'login', 'password' ], 'Test connection' )
        ),
    ];
}
```

- Prefer `create_with_sections()` over `create()`: a non-`Settings_Section` argument is a `TypeError`
  at your call site; `create()` stores the array verbatim and `get_sections()` DROPS a wrong-typed entry
  silently — you see a missing section, not an error.
- Optional `$args`: `capability`, `legacy_option_key` (a migration source, carried on the provider),
  `legacy_page` (admin query string redirected to the new tab), `supports` (flags).
- **Capability:** `Settings_Page_Registry::resolve_capability()` — your explicit `capability`, else
  `manage_woocommerce` for a WooCommerce plugin, else `manage_options`.
- **Section kinds are exclusive:** `create()` (labelled fields), `create_connection()` (credentials +
  one action button; needs an action label; the setting-id list may be empty for a handshake block),
  `create_tools()` (registry-backed action block over the tab's data, takes
  `Shipping_Tool` descriptors — a shipping concern). A `create()` section with an EMPTY id list declares
  zero fields. But a handler-level `get_settings( [] )` means ALL — gotcha `section-empty-setting-ids-renders-all-fields`.
- **Two providers with the same id:** the registry keeps the first and reports the duplicate through
  `_doing_it_wrong()`.
- Keep the handler in a property and return the SAME instance every time (the fixture caches it) — the
  handler registers and loads its settings in the constructor.

## Write the handler

Subclass `Woodev_Abstract_Settings` (global class, `woodev/settings-api/abstract-class-settings.php`).
The only abstract method is `protected function register_settings()`; the constructor takes the owner
id (`$this->get_id()` of the plugin) and runs `register_settings()` then `load_settings()`.

```php
protected function register_settings() {
    $this->register_setting( 'api_key', \Woodev_Setting::TYPE_STRING, [
        'name'      => 'API key',
        'default'   => '',
        'required'  => true,
        'sensitive' => true,
        'show_if'   => [ 'setting' => 'mode', 'value' => 'live' ],
    ] );
    $this->register_control( 'api_key', \Woodev_Control::TYPE_PASSWORD, [ 'tooltip' => '…' ] );
}
```

**Storage:** one WordPress option per setting, named `woodev_{id}_{setting_id}`
(`Woodev_Abstract_Settings::get_option_name_prefix()`, never the plugin id's underscored form). These
names are an installed-site data contract the moment you ship: choose setting ids once.

`register_setting( $id, $type, $args )` — `$type` is one of the `Woodev_Setting::TYPE_*` constants:
`string`, `url`, `email`, `integer`, `float`, `boolean`. Args (all optional): `name`, `description`,
`is_multi`, `options` (enum: `key => label`), `default`, `sensitive`, `constant_name`, `required`,
`validate` (callable), `validate_message`, `show_if`. A duplicate id or invalid type is reported with
`_doing_it_wrong()` and the setting is NOT registered — watch `WP_DEBUG`.

`register_control( $setting_id, $type, $args )` — `$type` is a `Woodev_Control::TYPE_*` constant:
`text`, `textarea`, `number`, `email`, `tel`, `url`, `password`, `date`, `checkbox`, `radio`, `select`,
`file`, `color`, `range`, `toggle`, `richtext`, `multiselect`, and `location-picker` (read the constant's
docblock before using it). Args: `name`, `description`, `options`, `min`, `max`, `step`, `native_bounds`,
`tooltip`, `placeholder`, `country`, `disabled` + `disabled_reason`. A control type that does not fit
the setting's type is rejected with `_doing_it_wrong()`; `toggle`/`checkbox` fit a scalar boolean only.

Rules that bit us:

- **A setting without a control never renders.** `'name'` declares storage, not display;
  `register_control()` is the only proof it is on screen. Gotcha `a-registered-setting-without-a-control-never-renders`.
- **`description` is rendered as RAW HTML** on the React surface (so links work). It must be a
  developer-authored string; NEVER interpolate a stored value, API response or provider error into it —
  that is an XSS. State a runtime condition through the control's `disabled_reason`, which stays escaped.
- **Secrets:** `sensitive => true` masks the value in the UI; a `constant_name`-backed field is masked even
  when the constant is undefined (gotcha `mask-constant-backed-field-even-when-constant-undefined`). The
  "do not overwrite a stored secret with an empty submit" guard is CLIENT-side only (gotcha
  `settings-sensitive-secret-empty-skip-is-client-side`) — do not rely on the server to keep it.
- **`Woodev_Setting::get_value()` returns a cached property.** An `update_option()` mid-request is
  invisible to it (gotcha `woodev-setting-get-value-is-cached-not-a-live-option-read`). Write through
  `update_value()` / `save()` on the handler, or read the option directly.
- **Validation:** enums validate by key or value; numbers are coerced; HTML is sanitised (gotcha
  `settings-api-control-save-path-pitfalls`). Format validators must guard non-string input (gotcha
  `format-validator-null-strlen-deprecation`). A custom `validate` callable receives the value and returns
  `bool`; its `validate_message` is shown on failure. The client mirrors the shared rules; a callable has
  no JS twin, so the field is marked server-validated and the server stays the authority.
- **Conditional fields (`show_if`):** a flat group `[ 'setting' => id, 'operator' => '=', 'value' => v ]`,
  or `'relation' => 'AND'|'OR'` plus a list. Operators: `=`, `!=`, `in`, `not_in` — nothing else; an unknown
  operator hides the field. `in`/`not_in` take a plain list. Compare to a select/radio KEY and to a
  boolean for a toggle. Write "show when mode is live" as `= 'live'`, not `!= 'test'` (the latter also
  shows while unset). A hidden field is neither validated nor stored (`filter_visible_values()`). `show_if`
  may also be a callable `[ $this, 'method' ]` returning the group per field id (the fixture does this).
- **Disabled, never hidden:** where an option is unavailable, `set_disabled`/`'disabled' => true` with a
  reason, rather than hiding it (see `docs-internal/wiki/architecture.md`, «Shipping settings»).

## Connection blocks

A `create_connection()` section gets a "test" button. Make the HANDLER implement
`\Woodev_Settings_Connection_Test` (`test_connection( string $connection_id, array $values ):
\Woodev_Connection_Result`) and, to show a status line, `\Woodev_Settings_Connection_Status`
(`get_connection_status( string $connection_id ): ?\Woodev_Connection_Result`). Build results with
`\Woodev_Connection_Result::success( $message )` / `::failure( $message )`. The POST route is
`woodev/v1/settings/{provider}/connection/{connection}/test` and the posted `$values` are the form's
unsaved values merged with the stored ones for the block's declared setting ids (so an untouched,
masked secret still reaches your test). The plugin owns all auth behaviour; the framework only transports
the result. A throw from your test becomes a generic 500 error message — catch and return `failure()`.

## The setup wizard (opt-in)

`Woodev_Plugin::build_setup_wizard_handler()` returns `null` by default. To opt in, return an instance of
a `\Woodev\Framework\Setup\Setup_Wizard` subclass (constructor takes the plugin; it wires its own hooks
when it has steps). Implement `protected function register_steps(): void` using:

- `register_step( $id, $label, array $setting_ids, ?callable $on_save = null, $description = '' )` — a
  settings step whose fields come from the PLUGIN'S settings handler (`get_settings_handler()`); `on_save`
  must be idempotent;
- `register_content_step( $id, $label, $content, $description = '' )` — markup or callback, no fields.

Override `get_finish_actions()` (next-step cards: heading, title, description, actionLabel, url) and
`get_header_image_url()` per plugin. The completion option is `woodev_{id}_setup_wizard_complete`; the
page slug is `woodev-{id}-setup`. For WooCommerce plugins use `Woocommerce_Setup_Wizard`
(`woodev/setup/class-woocommerce-setup-wizard.php`). `show_if` works in wizard steps too. The wizard and
the settings page share the field schema, so a field validated on one is validated on the other.

## Merchant-facing copy (operator rules)

- **Label:** short essence, one line, usually 2 words (3 if short). A checkbox already says "on/off", so
  no leading «Разрешить…»/«Включить…».
- **`tooltip`** (on `register_control()`) is the default home of the explanation; **`description`** is the
  inline slot and earns its place when the text must be unmissable, copyable or carry a link. Both may
  coexist on one option.
- **No jargon:** «чекаут» → «форма оформления заказа»; «фреймворк» never appears — say «плагин».
- Language: admin strings may have a Russian msgid; storefront strings an English msgid (see
  [services.md](services.md)).

## Related

- [SKILL.md](SKILL.md), [plugin-class.md](plugin-class.md), [lifecycle.md](lifecycle.md),
  [traps.md](traps.md)
