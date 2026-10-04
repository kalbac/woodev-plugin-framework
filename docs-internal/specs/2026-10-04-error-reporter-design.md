# Framework error reporter (PHP) — design

> Card #130. The s52 checklist (card body) fixed the idea; the operator's s150 decisions (card
> comments, 04.10.2026) fixed the receiver, consent, scope and anonymity. This spec turns them into
> classes. JS half → #1081, receiver server → #1082 (neither is built here).

## What this is

A small module of the framework — no third-party SDK, no vendor dependency — that sends an
anonymised report to a **self-hosted GlitchTip** (Sentry envelope protocol, by DSN) when OUR code
fails on a merchant's site: a PHP fatal, an uncaught exception, or a manual `capture()` from a
critical path. It works **without a server**: no DSN means the reporter is off and nothing is hooked.

## D1. Two switches, both required

| Switch | Where | Default |
|---|---|---|
| Receiver DSN | constant `WOODEV_ERROR_REPORTING_DSN`, then filter `woodev_error_reporting_dsn` | none — **no built-in DSN** |
| Merchant consent | option `woodev_error_reporting_enabled` (`yes`/`no`, autoloaded) | `no` |

`Consent::is_active()` = DSN parses AND consent is `yes`. The checkbox «Отправлять отчёты об
ошибках» sits on Woodev → Лицензии, under the card grid, written through
`GET|POST /woodev/v1/error-reporting` (`manage_options`, body `{enabled: bool}`, answer
`{enabled, available}`). It is rendered **only when `available`** (a DSN is configured): a checkbox
that changes nothing is not offered. Flip that in `error-reporting-toggle.tsx` if the operator wants
it always visible. Help text names what leaves the site and says no customer data and no address.

## D2. Install exactly once, by the winning copy

`Framework_Resolver::load_plugins()` ends with `install_error_reporter()` — after the loop (the
autoloader already points at the winner; every registered plugin is known) and before
`woodev_plugins_loaded`. `Error_Reporter::install()` sets a static guard first, so any later call is
a no-op. It always registers the consent route (otherwise the box could never be ticked); it hooks
`set_exception_handler` and `register_shutdown_function` **only if `is_active()`** — a site that did
not opt in keeps PHP's handlers untouched. Any failure is swallowed.

## D3. Scope: only OUR errors

`Plugin_Scope` is built from the resolver registry: every loader definition's `get_plugin_file()` →
`dirname` is a root; `plugin_id` and `plugin_version` come from the same definition. **No
`/plugins/woodev-*/` pattern** — our plugins are not all so named (`woocommerce-edostavka`), and the
framework is vendored inside each, so `<plugin>/vendor/woodev/…` is under the plugin root and counts
as that plugin. An event is built only when ANY frame (throw site or caller) lies under a root; the
innermost match owns it. A single-file plugin (root = the plugins dir itself) is refused as a root.
A manual `capture( $e, $plugin_id )` attributes to that registered plugin even with a foreign stack.

## D4. What is caught

| Source | Mechanism |
|---|---|
| Fatal | shutdown + `error_get_last()`, types `E_ERROR E_PARSE E_CORE_ERROR E_COMPILE_ERROR E_RECOVERABLE_ERROR` |
| Uncaught exception | `set_exception_handler`, **chained** to the previous handler; with none, `restore_exception_handler()` and rethrow so PHP's own fatal still happens |
| Manual | `Error_Reporter::capture( Throwable, ?string $plugin_id ): bool` |

No `set_error_handler` — warnings and notices are never touched. An «Uncaught …» fatal that our own
handler already reported is skipped at shutdown. A fatal's stack text is parsed for file paths only,
so the scope filter sees the whole stack.

## D5. Anonymity

The event has **no** `request`, `user`, cookies or arguments (`getTrace()` is read without `args`).
Site = first 16 hex of `sha256( 'woodev-error-reporter:v1:' . home_url )` as `server_name` and tag
`site` — pseudonymous, not anonymous: the salt is public and a known address can be hashed to
compare. Paths: under the plugins dir → `plugins/<dir>/…`, under ABSPATH → relative, else
`[external]/<basename>`. Message and function names: site address and host → `[site]`, e-mails →
`[email]`, paths relativised, 500 bytes max. Tags: `plugin`, `plugin_version`, `framework_version`,
`wp_version`, `wc_version`, `php_version`, `site`; `release` = `<plugin_id>@<plugin_version>`.
Filter `woodev_error_reporting_event` may edit the event or return `false` to drop it.

## D6. Rate limit and transport

`Rate_Limiter`: signature = `sha1( type | throw-site file | line | md5( message ) )`; a signature is
sent at most once per `woodev_error_reporting_dedupe_hours` (default 6, transient
`woodev_er_sig_*`), and a site at most `woodev_error_reporting_daily_cap` (default 20, transient
`woodev_er_day_YYYYMMDD`, UTC) per day. `Transport`: `wp_remote_post` to
`<scheme>://<host>[:port][/prefix]/api/<project>/envelope/`, header `X-Sentry-Auth: Sentry
sentry_version=7, sentry_client=woodev-error-reporter/1.0.0, sentry_key=<public key>`, content type
`application/x-sentry-envelope`, body = envelope header, item header (`type`, byte `length`), event
JSON, newline-delimited. `blocking=false`, timeout 3, no redirects; a failure is a silent `false`.
A DSN secret is parsed away and never sent.

## Classes (`woodev/error-reporting/`, `Woodev\Framework\Error_Reporting`)

`Error_Reporter` (install / capture / handlers) · `Consent` (option, DSN, state) · `Plugin_Scope` ·
`Event_Builder` · `Rate_Limiter` · `Transport` · `Dsn` · `Consent_Rest_Controller` (via
`Woodev_REST_V1_Registrar`). Hooks: `woodev_error_reporting_dsn`, `…_event`, `…_dedupe_hours`,
`…_daily_cap`.

## What is NOT done

- **JS** (`window.onerror`, `woodev_pickup_error` and the explicit domain-event list) → #1081.
- **The receiver**: GlitchTip on the VPS, the DSN itself, the retention policy → #1082. Until a DSN
  is defined the checkbox is hidden and nothing is sent.
- Previous exceptions in a chain, breadcrumbs, release health, source maps.

## Related

- [AGENTS.md](../../AGENTS.md) — conventions; the new option and routes are data contracts.
- [adr/005-platform-v2-clean-break-policy.md](../adr/005-platform-v2-clean-break-policy.md) — what may be broken.
- [wiki/architecture.md](../wiki/architecture.md) — where the resolver sits.
- Card #130 comments (s150 decisions) · #1081 (JS half) · #1082 (receiver).
