# Framework error reporter (PHP) — design

> Card #130. The s52 checklist (card body) fixed the idea; the operator's s150 decisions (card
> comments, 04.10.2026) fixed the receiver, consent, scope and anonymity. This spec turns them into
> classes. JS half → #1081 (D7 below), receiver server → #1082 (not built here).

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

## D7. The browser half (#1081): JavaScript errors and domain events

**Operator decisions (s151):** the browser POSTs to a public REST route of OUR site, never to the
receiver (the DSN does not reach the browser); PHP validates, anonymises and puts the event into the
SAME `Event_Queue`, so consent, anonymity and anti-spam stay in one place (D1, D5, D6). **No
`error.message` from JS, ever** — same rule as PHP.

- **Script and route.** `woodev/assets/js/frontend/woodev-error-reporter.js` — plain ES5, no build step
  (Rule 9; the `frontend/` directory is out of the TypeScript scope), enqueued in the `<head>` on
  `wp_enqueue_scripts` **only when `Consent::is_active()`** (D1; otherwise nothing is enqueued and
  nothing is hooked). Config global `woodevErrorReporting = { endpoint, nonce, bases }`. Route
  `POST woodev/v1/error-reporting/browser` — the framework's one namespace, a sibling of the consent
  route, registered always (so a withdrawn consent answers 403, not 404).
- **Only OUR scripts.** `bases` = the asset base URL of every registered plugin
  (`plugins_url( '', <plugin file> )`, the framework vendored inside a plugin is under it), built by
  `Plugin_Scope` from the same registry as the PHP roots (a single-file plugin is refused for the same
  reason). The browser listens with `addEventListener( 'error' | 'unhandledrejection' )` — it never
  replaces `window.onerror` — and keeps only frames whose URL starts with a base (scheme ignored, the
  authority lowercased on BOTH sides, the path case-sensitive); an event with no such frame is dropped.
  **The server repeats the check and goes further** (`Plugin_Scope::locate_url()`, see «Trusted identity»
  below): a frame outside every base, or inside one but not a real script, is dropped; an event left with
  no frame answers `200 {queued:false}`; the innermost owned frame owns the event.
- **What leaves.** Exception **type** — one of the standard JS error names (`Error`, `TypeError`,
  `RangeError`, `ReferenceError`, `SyntaxError`, `EvalError`, `URIError`, `AggregateError`), anything else
  is exported as `Error` — and frames of OUR scripts: `filename`, `lineno`, `colno`, innermost first,
  ≤ 30. **No function name** (it is free text and not needed). The raw `stack` string is never sent.
  Cross-origin «Script error.» carries no URL and is dropped. Event: `platform: javascript`, the same
  tags/`release`/`server_name` as D5 plus `source: browser`, `mechanism.type` `onerror` |
  `onunhandledrejection`.
- **Trusted identity — the binding rule.** *No free text from the browser ever reaches the queue or
  GlitchTip.* Syntax validation does not prove anonymity (a name or a phone number is a valid token), so
  every exported value is a **number**, a **constant**, or a value from a **set the server holds**; a
  value outside its set is replaced by a constant or the whole report is dropped (fail closed):
  - *frame URL* → accepted only when its path under a registered plugin's base, percent-decoded segment
    by segment BEFORE any check (an empty, `.`, `..`, separator-carrying or control-character segment
    refuses it), names an EXISTING `.js` file that `realpath`-resolves inside that plugin's directory;
    exported is the path the filesystem reports, `plugins/<dir>/<real path>` — never the client's spelling;
  - *error type* → the closed list above, else `Error`;
  - *pickup `pluginId`* → a registered plugin; *`fieldId`* → a field id a pickup handler declared for that
    plugin through the `woodev_error_reporting_pickup_fields` filter (`Pickup_Handler::register()` adds
    its own), else the report is dropped; *`code`* → `Browser_Event_Builder::PICKUP_CODES`, the codes the
    pickup providers emit (defined once, in PHP), else `unknown`;
  - *stack parsing in the browser* fails closed: Chrome's `Name: message` header must be matched against
    the error's CURRENT name and message and removed whole, a header-less (Firefox/Safari) stack must be
    frames only — otherwise the stack is not parsed and only the `ErrorEvent` file:line:col is used. The
    parser is defence in depth, not the guarantee: the server exports a frame only as a verified path plus
    two numbers.
- **Domain events.** One list, one rule: a `CustomEvent` on `document.body`, **only the fields named
  here** are read. First entry: `woodev_pickup_error {fieldId, code, message}` (D-14 of the pickup
  design) → `fieldId` + `code` + `pluginId` (added to the event detail and to the pickup config by this
  card — the browser does not know the owner otherwise); `message` is never read. All three must match
  `^[A-Za-z0-9_.-]{1,64}$` (shape only) and then pass the closed sets of «Trusted identity» above. Event:
  `exception.type = woodev_pickup_error`, `value = <pluginId>:<fieldId>:<code>` (tokens, so the shared
  signature of D6 tells plugins and fields apart), no frames.
- **Guests and the nonce.** The pickup map is on the storefront, so the route is public
  (`permission_callback` = reporting active + a valid `wp_rest` nonce in `X-WP-Nonce`). `wp_rest` nonces
  exist for logged-out visitors too (user id 0) and core checks them in `rest_cookie_check_errors()`
  before any callback. A guest nonce is **not an authentication boundary** — anyone can read it from a
  page — it only keeps blind cross-site POSTs out; the real protection is the schema, the rate limit, the
  re-check and the queue bounds. A page served from a full-page cache carries a stale nonce after 12–24 h:
  core answers 403 and the report is lost — accepted, best effort (D6).
- **Limits.** Body ≤ 8 KB (413), JSON object only, strict schema checked in the controller (the unit-testable
  place: known keys only, exact types, `maxLength`, ≤ 30 frames, token patterns; an unknown key — a
  `message`, say — is refused with 400, not silently stripped), 10 requests per client per minute through the
  shared `Rest_Rate_Limit_Trait` (the counter key is `md5( ip )` inside a transient or object-cache entry —
  no raw address is stored; own key prefix, own budget; the trait's forwarding-header handling is used as it
  stands — a fairness hint bounded by its coarse connection-address bucket, and the caps below bound the
  rest). The browser itself sends ≤ 5 reports per page view and one per signature. Order: permission (inactive → 403, bad nonce → 403) → rate limit
  (429) → size (413) → schema (400) → build (200). Dedupe is D6's: `event_id` is assigned on the server
  when the event is built and travels to the receiver; `Event_Queue::push()` coalesces by the shared
  signature (type, throw-site frame, md5 of value), the dispatcher's `Rate_Limiter` applies the
  6 h/daily-cap rules. **Browser events cannot starve PHP events:** (1) in the queue they hold at most
  `Event_Queue::BROWSER_LIMIT` (10) of the 20 slots and are *refused* — never evict anything — when their
  share or the queue is full, while a PHP event arriving at a full queue evicts the oldest browser event
  first; (2) at send time they spend a daily budget of their own (`woodev_er_day_js_*`, default 10,
  filter `woodev_error_reporting_browser_daily_cap`), the PHP budget is untouched; (3) a site-wide intake
  cap — 30 browser reports queued per UTC hour, whoever sent them (`Rate_Limiter::allow_browser_intake()`,
  filter `woodev_error_reporting_browser_hourly_cap`) — is checked before enqueue, counting only reports
  that are ours. The answer is `200 {queued:bool}` — a foreign or duplicate report is not an error for the browser.

## Classes (`woodev/error-reporting/`, `Woodev\Framework\Error_Reporting`)

`Error_Reporter` · `Consent` · `Plugin_Scope` · `Event_Builder` · `Event_Queue` · `Dispatcher` ·
`Rate_Limiter` · `Transport` · `Dsn` · `Consent_Rest_Controller` · (D7) `Browser_Event_Builder` ·
`Browser_Rest_Controller` · `Browser_Script`. Hooks: `woodev_error_reporting_dsn`,
`…_event`, `…_dedupe_hours`, `…_daily_cap`, cron `woodev_error_reporting_dispatch`.

## What is NOT done

- **Further domain events** (D7 defines the list's shape; only `woodev_pickup_error` is wired) and
  admin-side JS (the script is storefront-only).
- **The receiver**: GlitchTip on the VPS, the DSN, retention → #1082. Until a DSN is defined the
  checkbox is hidden and nothing is queued or sent.
- Previous exceptions in a chain, breadcrumbs, release health, source maps.
- Not verified live: WP recovery mode (a drop-in that exits can pre-empt the shutdown handler), real ingest.

## Related

- [AGENTS.md](../../AGENTS.md) — conventions; the new option and routes are data contracts.
- [adr/005-platform-v2-clean-break-policy.md](../adr/005-platform-v2-clean-break-policy.md) — what may be broken.
- [wiki/architecture.md](../wiki/architecture.md) — where the resolver sits.
- Card #130 comments (s150 decisions) · #1081 (JS half) · #1082 (receiver).
