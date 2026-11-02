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
- **A paid plugin is GATED on the server-signed claim (#1270, framework `8b56ae0a`).**
  `Woodev_Plugins_License::is_license_in_force()` answers from the verified, site-bound, unexpired claim
  alone — no claim, a tampered one, or a status other than `valid` is `false`; a plugin with no store product
  is never gated, and no option or flag switches it off. A shipping plugin gets the behaviour for free
  (checkout rates, export, cancel, documents, the carrier's own order actions — see
  [../woodev-shipping-plugin/references/traps.md](../woodev-shipping-plugin/references/traps.md)); nothing
  else is gated automatically, so a plugin of another type decides what to gate and reads the predicate. Consequences for an author: your
  integration fixtures need a TEST-SIGNED in-force claim (the framework's integration fixtures carry one),
  the weekly check is not the only refresh (a key without a claim triggers one throttled cron check; automatic
  calls to woodev.ru back off 15 m → 1 h → 6 h → 24 h in an atomic budget — never add your own polling),
  and a v1 plugin that still ships on a shop (CDEK 2.1.x) floods the server, which is why the budget exists.
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
  reporter. **The framework ships no receiver, so a production plugin must contribute its OWN DSN — one
  GlitchTip project per plugin (#1302, framework `2.0.2`).** `Consent::get_dsn( $plugin_id )`
  (`woodev/error-reporting/class-consent.php`) starts from the site-wide constant (the fallback for a plugin
  with no DSN of its own), applies the filter `woodev_error_reporting_dsn` with TWO arguments —
  `( $dsn, $plugin_id )` — and returns `null` when nothing results; the owner of an event is routed to its
  own DSN. Your callback answers **only for your own plugin id** and returns `$dsn` untouched for every
  other, or two plugins on one site overwrite each other. Take the DSN from the operator — never invent one,
  and keep server details out of the public repo. Register it at file level in the entry file (before
  `plugins_loaded`, when the reporter is installed), with priority and `accepted_args = 2`:

  ```php
  add_filter(
      'woodev_error_reporting_dsn',
      static function ( $dsn, $plugin_id = '' ) {
          return 'my-plugin-id' === $plugin_id ? 'https://<key>@<receiver-host>/<project-id>' : $dsn;
      },
      10,
      2
  );
  ```

  Worked examples: `woocommerce-edostavka.php` (CDEK, PR #109) and the Yandex entry file (PR #103); both pin
  the behaviour in `tests/unit/LoaderDefinitionTest.php` **in the plugin repo** (this repo has no such file). Facts that follow from the design: the merchant's
  consent stays ONE site-wide checkbox («Отправлять отчёты об ошибках», default off), offered when at least
  one registered plugin has a DSN; an event whose owner has no DSN is not queued; a throw inside the shared
  `woodev/` copy belongs to the first plugin-owned frame of the stack; dedupe and the daily budget (20 PHP +
  10 browser attempts) are kept PER OWNER. A one-argument callback written for the old single-DSN contract
  still works (it just gets `$dsn`). Do not add a second reporter or your own phone-home.
  **Test trap:** a unit test that `include`s the entry file in a subprocess with a stubbed `Woodev_Loader`
  must also stub `add_filter()` once the entry file registers this filter — CDEK's `LoaderDefinitionTest`
  defines a recording `add_filter()` and then calls the captured callback with its own id, another id and
  one argument, asserting only its own id gets the DSN.
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
  it from `WP_LANG_DIR/{domain}/` and `{plugin dir}/languages`. The resolver loads the framework's domain
  once at `init` priority 0, with explicit external-pack merging and a bundled fallback
  (`woodev/handlers/class-framework-translations.php`, #1361). Never use the framework's domain
  (`woodev-plugin-framework`) for your own strings. A JIT path registration alone cannot fill gaps in an
  already-loaded external pack; locale changes must rebuild override precedence.
- **An English msgid without a catalogue entry is a storefront regression** (the shopper sees English),
  and the framework's `lint:i18n` cannot catch it in your repo (gotcha
  `rule-1-has-two-halves-an-english-msgid-alone-is-a-regression`). **Ship the catalogue and a test for it
  from the FIRST storefront string:** `languages/{text-domain}-ru_RU.po` + a compiled `.mo` (build it with
  `wp i18n make-mo` in the rig container; commit both) and a `tests/unit/I18n/*StorefrontTranslationTest.php`
  that asserts every English msgid in your storefront code (including `_n()` forms) has a Russian entry, no
  entry is orphaned, the header is valid and the `.mo` exists. The Yandex plugin went to the operator's
  pre-release pass with NO `languages/` directory at all — every English storefront string rendered in
  English on a ru_RU shop (yandex PR #104, written after CDEK's `tests/unit/I18n/CdekStorefrontTranslationTest.php`).
  Strings that the FRAMEWORK renders from your data (a pickup point's `payment_methods`, `services`, type label)
  are storefront strings too — map carrier codes to translated customer labels in your mapper (see
  [../woodev-shipping-plugin/references/model-rates-location-pickup.md](../woodev-shipping-plugin/references/model-rates-location-pickup.md)).
- **Plurals:** `_n()` with a Russian msgid works in PHP once the catalogue entry carries all three
  `msgstr` forms; in JS it cannot be made right (gotcha `russian-source-i18n-plural-n`).
- **A concatenated msgid** (`__( 'a ' . 'b' )`) is one msgid to gettext and zero to a scanner (gotcha
  `a-concatenated-msgid-is-invisible-to-a-single-literal-scanner`) — one literal per call.
- **JS strings:** a built bundle's `__()` is answered by handle-named JSON from
  `wp_set_script_translations()`, never by the `.mo`. The framework's `Script_Translations::register(
  $plugin, $handle )` (`woodev/handlers/class-script-translations.php`) does it for the framework's own
  bundles, after `wp_enqueue_script()`. For YOUR bundles call `wp_set_script_translations()` with your
  own domain and ship the JSON (gotcha `js-translations-are-handle-named-json-files`).

## Hosted payment notifications

From framework 2.0.16, implement `Woodev\Framework\Payment_Gateway\Api\Pending_Notification_Response` on a notification
response and return true from `transaction_pending(): bool` for intermediate provider states. Responses using only the
original notification interface retain the old default (not pending); false approved/held/cancelled predicates still mean
a decline. The protected `do_transaction_pending()` acknowledgement leaves order status unchanged. Authenticate requests
in the existing validation seam: exceptions take the invalid-response path and are logged with secret redaction when debug
logging is enabled, without failing the order.

`get_order()` and `get_order_with_unique_transaction_ref()` only hydrate the current reference. The default hosted redirect,
hosted form and direct-payment entry points call protected `start_payment_attempt( WC_Order $order ): WC_Order` once when
starting a payment request. A custom entry point must do the same before building the provider request; hydration filters
run before the attempt counter advances. Existing `retry_count` meta and suffixes are preserved. Never call the helper
while handling a webhook. Source: `woodev/payment-gateway/class-payment-gateway-hosted.php` and the shared gateway base;
[trap](traps.md#hosted-payment-notifications).

## Related

- [SKILL.md](SKILL.md), [plugin-class.md](plugin-class.md), [traps.md](traps.md)
