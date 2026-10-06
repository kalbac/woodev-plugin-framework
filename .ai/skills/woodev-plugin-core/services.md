# Services every plugin gets — license, errors, REST, logging, notices, API layer, i18n

All of these are built by `Woodev_Plugin::__construct()` ([plugin-class.md](plugin-class.md)). This file
says what each one is for and what a plugin author must and must not do.

## Licensing and updates

Source of truth: `woodev/licensing/` (`Woodev_Plugins_License` in `class-plugin-license.php`,
`Woodev_Plugin_Updater` in `updater/class-plugin-updater.php`, `Woodev_License_Authority_Claims`),
`Woodev_Plugin::init_license_handler()`, `get_license_instance()`, `load_updater()`, `is_need_license()`.
Store: woodev.ru (EDD Software Licensing).

- **Identity is the loader definition's `download_id`** (the EDD product id). Never override
  `get_download_id()`. Updater identity and the license option names are installed-site data contracts
  ([lifecycle.md](lifecycle.md)).
- **Two layers, do not confuse them.** `Woodev_Plugin::is_need_license()` (default `true`) is a
  presentation hint: how the license page renders and whether "enter your license" nags appear. A plugin
  sold without a license overrides it to `false`. `Woodev_Plugins_License::is_license_required()` is
  ENFORCEMENT: true unless a verified, site-bound, unexpired server-signed claim says otherwise — a local
  flag can never switch it. Gotcha `docs-internal/gotchas/license-need-vs-required.md`.
- **The updater** is constructed on `init` in any admin, cron or WP-CLI context (`load_updater()`), with
  no license-key gate (it carries the signed claims for keyless products). Do not add your own updater
  and do not hook the store directly. EDD quirks that matter if you touch the API: gotchas
  `edd-error-field-vs-license-status`, `edd-sl-get-version-serialized-sections`,
  `license-key-option-double-prefix`; a single-plugin site cannot show its own remote-deactivation banner
  by design (`single-plugin-site-cannot-render-its-own-deactivation-banner`).
- The license page is the framework's React page; the weekly check runs from `Cron_Handler`
  (`woodev_weekly_scheduled_events`, rescheduled on activation). A plugin writes no license UI.
- A subclass must build the license object: if you override `init_license_handler()`, assign
  `$this->license`; the base enforces it (`enforce_license_handler_contract()`).

## Error reporting

Source of truth: `woodev/error-reporting/`, spec `docs-internal/specs/2026-10-04-error-reporter-design.md`.

- It is **opt-in and anonymised**: PHP errors raised from the files of registered Woodev plugins
  (`Plugin_Scope`) are queued and sent from WP-Cron to a Sentry-compatible receiver; no exception text is
  sent. The merchant's consent is the option `woodev_error_reporting_enabled`; there is NO built-in
  receiver — the DSN comes from the constant `WOODEV_ERROR_REPORTING_DSN`, then the filter
  `woodev_error_reporting_dsn`. The reporter is inert until a DSN exists.
- It is installed once by the winning framework copy before any plugin code runs, so the plugin builds no
  reporter. **But the framework ships no receiver, so a production plugin must contribute its DSN:**
  `Consent::get_dsn()` (`woodev/error-reporting/class-consent.php`) starts from the constant (empty when
  unset), applies the filter `woodev_error_reporting_dsn`, and returns `null` when no DSN results —
  `Consent::is_available()` is then false, nothing is queued and the merchant's consent checkbox is not
  offered at all. The project's per-plugin setup is to supply the DSN through that filter from each
  production plugin (take the value from the operator — never invent one, and keep server details out of the public repo):

  ```php
  add_filter(
      'woodev_error_reporting_dsn',
      static function ( $dsn ) {
          return is_string( $dsn ) && '' !== $dsn ? $dsn : 'https://<key>@<receiver-host>/<project-id>';
      }
  );
  ```

  Register it at file level in the entry file (before `plugins_loaded`, when the reporter is installed),
  keep an already-configured DSN (as above), and do not add a second reporter or your own phone-home.
  What a plugin author otherwise controls is the quality of its own error handling: throw/log with
  `Woodev_Plugin::log()`, never swallow a failure silently, never put secrets or customer data in a message.

## REST

- `init_rest_api_handler()` builds `Woodev_REST_API` (`woodev/rest-api/class-plugin-rest-api.php`): it
  extends WooCommerce's REST API (system-status data, the settings controller when your plugin has a
  `get_settings_handler()`). Override `init_rest_api_handler()` to add data or routes.
- The `woodev/v1` namespace is the FRAMEWORK's (`Woodev_REST_V1_Registrar::ROUTE_NAMESPACE`, settings,
  license, account, setup, shipping routes). Register your own plugin routes under your own namespace and
  treat that namespace and every route path as an installed-site data contract.
- REST requests are neither `is_admin()` nor WooCommerce-gated: anything a REST route or a webhook needs
  must be registered on `init` / `plugins_loaded`, not under `is_admin()`. Always set a real
  `permission_callback` (a capability for admin routes; a signature/secret check for webhooks).

## Logging

- `Woodev_Plugin::log( $message, $log_id = null )` is a no-op in the platform-neutral base.
  `Woocommerce_Plugin::log()` writes to the WooCommerce logger with the SOURCE = `$log_id`, default the
  plugin id; `logger()` returns the WC logger. The source name is a data contract (merchants and support
  look for it) — keep it stable.
- **API calls are logged for you:** `add_api_request_logging()` (called from `add_hooks()`) subscribes
  `log_api_request()` to the action `woodev_{plugin id}_api_request_performed`, which
  `Woodev_API_Base` fires for every request/response. Do not log request bodies yourself, and never let
  a token, secret or full card/phone/address reach a log line (gotcha `grep-the-sink-not-one-spelling-of-it`
  is the redaction lesson: redact at the SINK).
- Exception texts and log lines are for us, not the merchant: either language, `__()` not needed.

## Admin notices and flash messages

- `get_admin_notice_handler()` (`Woodev_Admin_Notice_Handler`, `woodev/class-admin-notice-handler.php`):
  `add_admin_notice( string $message, string $message_id, array $params = [] )`. `$message_id` must be
  unique per notice (dismissal is stored per user by id). Params: `dismissible` (default true),
  `always_show_on_settings` (default true), `notice_class` (default `updated`). Methods: `dismiss_notice`,
  `undismiss_notice`, `is_notice_dismissed`. A custom handler must still exist — see the enforced
  contract in [plugin-class.md](plugin-class.md).
- `get_message_handler()` (`Woodev_Admin_Message_Handler`) carries flash messages across redirects.
- The base renders queued notices on `admin_notices` and, for delayed ones, `admin_footer`
  (`add_admin_notices()` / `add_delayed_admin_notices()`): queue a notice before those hooks run.
- Notice text is merchant-facing: the copy rules in [settings.md](settings.md) apply (no «чекаут», no
  «фреймворк»).

## The API layer (outbound HTTP to a carrier, gateway, …)

Source: `woodev/api/`. Extend `Woodev_API_Base`; pick the JSON bases (`Woodev_API_JSON_Request` /
`Woodev_API_JSON_Response`) or the XML ones (`Woodev_API_XML_Request` / `Woodev_API_XML_Response`) — the
files are named `abstract-api-*.php`; requests/responses implement the interfaces `Woodev_API_Request` /
`Woodev_API_Response`. For cached reads use `Woodev_Cacheable_API_Base` (transient cache via
`Woodev_Cacheable_Request_Trait`). Errors: `Woodev_API_Exception`, `Woodev_API_Transport_Exception`,
`Woodev_API_Rate_Limit_Exception`. `Woodev_API_Request_Purpose` is an AMBIENT scope that sets the HTTP
timeout from what the call is FOR (a checkout rate call gives up in seconds, an order export waits
longer, a payment/licensing call keeps the minute): wrap a call a shopper is waiting for in
`Woodev_API_Request_Purpose::run_at_checkout()`; `Woodev_API_Base::get_request_timeout()` reads it. The
framework already marks the stretches it owns, so you rarely set it yourself.

- No network call in a request/shutdown path: `wp_remote_post( blocking => false )` still waits up to
  `timeout` on cURL. Enqueue and send from cron or a background job (gotcha
  `wp-remote-post-blocking-false-is-not-asynchronous-on-curl`).
- Use `Woodev_Async_Request`, `Woodev_Background_Job_Handler` (queue), `Woodev_Job_Batch_Handler` (batch
  with an admin UI) from `woodev/utilities/` for background work; their job ids and cron hooks are data
  contracts. `Woodev_String_Conversion` (file `woodev/class-string-conversation.php`, Cyrillic transliteration)
  and `Woodev_Helper` (`woodev/class-helper.php`) are the shared helpers — look there before writing one.

## Internationalisation

Rules: `AGENTS.md` → Conventions → «Translatable strings», gotchas in `docs-internal/gotcha-index/i18n.md`.

- **Pick the msgid language by who reads the string.** Storefront (anything a shopper sees, including
  emails and checkout) → ENGLISH msgid; the Russian arrives from your `.po`/`.mo`, because a shop's
  frontend may run an English locale though its admin never does. Admin screens → a Russian msgid is
  fine. Logs, exception texts, anything that never reaches a screen → either language and NOT wrapped in
  `__()`.
- **Text domain** is the `text_domain` you pass to `parent::__construct()`. `Translation_Handler` loads
  it from `WP_LANG_DIR/{domain}/` and `{plugin dir}/languages`, and loads the framework's own domain
  separately. Never use the framework's domain (`woodev-plugin-framework`) for your own strings.
- **An English msgid without a catalogue entry is a storefront regression** (the shopper sees English),
  and the framework's `lint:i18n` cannot catch it in your repo — run your own check (gotcha
  `rule-1-has-two-halves-an-english-msgid-alone-is-a-regression`).
- **Plurals:** `_n()` with a Russian msgid works in PHP once the catalogue entry carries all three
  `msgstr` forms; in JS it cannot be made right (gotcha `russian-source-i18n-plural-n`).
- **A concatenated msgid** (`__( 'a ' . 'b' )`) is one msgid to gettext and zero to a scanner (gotcha
  `a-concatenated-msgid-is-invisible-to-a-single-literal-scanner`) — one literal per call.
- **JS strings:** a built bundle's `__()` is answered by handle-named JSON from
  `wp_set_script_translations()`, never by the `.mo`. The framework's `Script_Translations::register(
  $plugin, $handle )` (`woodev/handlers/class-script-translations.php`) does it for the framework's own
  bundles, after `wp_enqueue_script()`. For YOUR bundles call `wp_set_script_translations()` with your
  own domain and ship the JSON (gotcha `js-translations-are-handle-named-json-files`).

## Related

- [SKILL.md](SKILL.md), [plugin-class.md](plugin-class.md), [traps.md](traps.md)
