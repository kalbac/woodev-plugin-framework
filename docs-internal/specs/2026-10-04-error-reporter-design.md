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
it always visible. The help text lists exactly what is sent (D5) and says no message texts, no customer data, no address.

## D2. Install exactly once, by the winning copy, before plugin code

`Framework_Resolver::load_plugins()` calls `install_error_reporter()` in the loop's first pass, right
after the autoloader is registered against the winner and **before any plugin is invoked** — every
registered plugin is already known, and an uncaught exception or fatal in a plugin's constructor or
main-class include is still covered (round 1 installed after the loop and missed exactly that).
`Error_Reporter::install()` sets a static guard first, so any later call is a no-op. It always
registers the consent route and the cron callback (a withdrawn consent must still let the cron run
clear the queue); it hooks `set_exception_handler` and `register_shutdown_function` **only if
`is_active()`** — a site that did not opt in keeps PHP's handlers untouched. Failures are swallowed.

## D3. Scope: only OUR errors

`Plugin_Scope` is built from the resolver registry: every loader definition's `get_plugin_file()` →
`dirname` is a root; `plugin_id` and `plugin_version` come from the same definition. **No
`/plugins/woodev-*/` pattern** — our plugins are not all so named (`woocommerce-edostavka`), and the
framework is vendored inside each, so `<plugin>/vendor/woodev/…` is under the plugin root and counts
as that plugin. An event is built only when ANY frame of the **full** trace lies under a root (matching
runs before the 50-frame cut); the innermost match owns it. `capture( $e, $plugin_id )` attributes to
that registered plugin even with a foreign stack. **Limit:** a single-file plugin (its file sits in
the plugins dir itself) is refused as a root — it would claim every neighbour — so its errors are dropped.

## D4. What is caught

| Source | Mechanism |
|---|---|
| Fatal | shutdown + `error_get_last()`, types `E_ERROR E_PARSE E_CORE_ERROR E_COMPILE_ERROR E_RECOVERABLE_ERROR` |
| Uncaught exception | `set_exception_handler`, **chained** to the previous handler; with none the exception is **thrown again, without `restore_exception_handler()`** |
| Manual | `Error_Reporter::capture( Throwable, ?string $plugin_id ): bool` — queued or not, never sent inline |

The rethrow is safe because PHP clears the user handler while it runs one: the new throw ends the request
with PHP's own «Uncaught …» fatal and exit 255, handler called once (measured on 8.5.7; the round-1
`restore_exception_handler()` made PHP call the handler again — stack exhaustion, even for foreign
errors). A static re-entry guard stays as a belt. An «Uncaught» fatal at the same `file:line` as an
exception our handler saw is not queued twice; a later handler that replaces ours is still caught
through the shutdown fatal. No `set_error_handler` — warnings and notices are never touched.

## D5. Anonymity — no free text from an exception

**Operator decision (s150, r2): no exception message ever leaves the site.** Names, phones, addresses,
SQL values and tokens cannot be scrubbed out of free text, so none is sent. The event carries: exception
**class**, integer **code** (`mechanism.data.code`), throw-site `file:line`, and the call stack as
relative paths plus class/function names (anonymous-class names cut at their NUL path; closure names
reduced to `{closure}`; nothing but identifier characters kept). Fatals from `error_get_last()`: the
engine's own message is sent as PHP wrote it (it names code, not data) with absolute paths relativised
and 500 bytes max — **except «Uncaught …» fatals**, cut to `Uncaught <ExceptionClass>`; their textual
stack is parsed back from the LAST `Stack trace:` marker (a fake marker inside a message cannot inject
frames) for paths and function names only. No `request`, `user`, cookies or arguments. Site =
first 16 hex of `sha256( 'woodev-error-reporter:v1:' . home_url )` as `server_name` and tag `site` —
pseudonymous, not anonymous (public salt). Paths: `plugins/<dir>/…`, ABSPATH-relative, else
`[external]/<basename>`. Tags: `plugin`, `plugin_version`, `framework_version`, `wp_version`,
`wc_version`, `php_version`, `site`; `release` = `<plugin_id>@<plugin_version>`. Filter
`woodev_error_reporting_event` may edit the event or return `false` to drop it (applied at enqueue).

## D6. Deferred sending: queue, cron, lock, limits

The failing request only **enqueues** — no network, ever. `Event_Queue`: option
`woodev_error_reporting_queue` (autoload **no**), at most 20 events, oldest dropped; an event whose
signature is already pending is not stored again; one request queues at most 5. Enqueue schedules the
single cron event `woodev_error_reporting_dispatch` (+60 s) if none waits. `Dispatcher::run()` (the cron
callback): re-checks consent and DSN **now** (off → clears the queue), takes the lock row
`woodev_error_reporting_lock` by an atomic `INSERT IGNORE` (stale after 300 s, taken over by
compare-and-set), then for each event applies `Rate_Limiter` — signature `sha1( type | throw-site file |
line | md5( engine message ) )`, once per `woodev_error_reporting_dedupe_hours` (default 6, transient
`woodev_er_sig_*`), at most `woodev_error_reporting_daily_cap` per UTC day (default 20, transient
`woodev_er_day_YYYYMMDD`) — and posts it. Dedupe + cap live only here, serialised by the lock. A failed
post stops the batch; the whole batch leaves the queue (silent drop). Withdrawing consent also clears it.

**Lock ownership (r3).** The lock row's value is `<unix time>|<random token>`. Only the run that wrote it
releases it — a conditional `DELETE … WHERE option_value = <its value>` — and before **each** post the run
verifies that the row still carries its value and that 300 s have not passed on its own clock; if not (a
paused run whose lock was taken over) it stops and removes from the queue only the events it handled, the
rest belong to the new owner. **Consent is re-read before each post**, past the object cache
(`wp_cache_delete` of `alloptions`, `notoptions` and the option itself), so a withdrawal made by another
request mid-drain stops the batch and clears the queue.

**Cron liveness (r3).** A queued event must always have a cron event waiting. A run refused by a held lock
has consumed its own cron event, so it schedules another (+60 s, only while the queue is non-empty and none
waits; the retries stop when the holder finishes or its lock goes stale after 300 s). A run that finishes
with events still queued does the same, and so does an enqueue of an event identical to one already
queued (it is coalesced, but the cron is re-checked). The re-check sits in the drain's `finally`, so a
drain that THREW (a filter or HTTP hook) still leaves a cron behind for what it kept.

**A duplicate post is accepted, a duplicate event is not (operator, s150).** The ownership check before
each post is not a perfect fence: a run paused past the 300 s TTL between that check and the post can
send an event its successor also sends. Closing that window on the client is not possible; it does not
need to be — `event_id` is assigned when the event is BUILT (before it is queued) and travels in the
envelope header and the event, and the Sentry protocol (GlitchTip included) drops a second event with
the same id. Worst case: one redundant request, never a doubled report.

**WP-Cron dependency.** Sending needs a working WP-Cron. With `DISABLE_WP_CRON` and no external scheduler
hitting `wp-cron.php`, nothing is ever sent: the queue stays at its 20-event bound (oldest dropped) and
nothing leaves the site. That is accepted as best effort — a merchant who disables WP-Cron has to run
it from the system scheduler.

**Out of memory (r3).** After an «Allowed memory size» fatal the heap is full, and the shutdown handler
would die with a second fatal that no `catch` can intercept. `install()` therefore holds a 256 KB string
(`Error_Reporter::MEMORY_RESERVE`) and the shutdown handler frees it before anything else. The event for
such a fatal is the ordinary one for an engine message: a single frame, no stack parsing. Covered by a
subprocess test that fills the heap to under 16 KB before the failing allocation.
`Transport`: `wp_remote_post` to `<scheme>://<host>[:port][/prefix]/api/<project>/envelope/`, header
`X-Sentry-Auth: Sentry sentry_version=7, sentry_client=woodev-error-reporter/1.0.0, sentry_key=<key>`,
`application/x-sentry-envelope`, newline-delimited envelope; **blocking, timeout 3**, no redirects —
legal because it only runs in cron (`blocking=false` does not make cURL asynchronous). A DSN secret is
parsed away. Queue read-modify-write is not atomic: two simultaneous failures may lose one — accepted.

## Classes (`woodev/error-reporting/`, `Woodev\Framework\Error_Reporting`)

`Error_Reporter` · `Consent` · `Plugin_Scope` · `Event_Builder` · `Event_Queue` · `Dispatcher` ·
`Rate_Limiter` · `Transport` · `Dsn` · `Consent_Rest_Controller`. Hooks: `woodev_error_reporting_dsn`,
`…_event`, `…_dedupe_hours`, `…_daily_cap`, cron `woodev_error_reporting_dispatch`.

## What is NOT done

- **JS** (`window.onerror`, `woodev_pickup_error`, the domain-event list) → #1081.
- **The receiver**: GlitchTip on the VPS, the DSN, retention → #1082. Until a DSN is defined the
  checkbox is hidden and nothing is queued or sent.
- Previous exceptions in a chain, breadcrumbs, release health, source maps.
- Not verified live: WP recovery mode (a drop-in that exits can pre-empt the shutdown handler), real ingest.

## Related

- [AGENTS.md](../../AGENTS.md) — conventions; the new option and routes are data contracts.
- [adr/005-platform-v2-clean-break-policy.md](../adr/005-platform-v2-clean-break-policy.md) — what may be broken.
- [wiki/architecture.md](../wiki/architecture.md) — where the resolver sits.
- Card #130 comments (s150 decisions) · #1081 (JS half) · #1082 (receiver).
