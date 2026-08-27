# #599 Worker C — audit of `apply_filters()` return values

Scope: every `apply_filters()` call in `woodev/` **except** `woodev/payment-gateway/` and
`woodev/shipping-method/` (those belong to the other two workers). READ-ONLY — no source file
touched, no git command run, no gate run.

Serena MCP was available and used (`activate_project` verified against
`D:\Projects\woodev_framework`; `find_symbol` / `find_referencing_symbols` / `search_for_pattern`
used throughout — no raw `Read` on `.php` files).

Enumeration command (from the brief) reproduced by `search_for_pattern` restricted to `woodev/`
excluding the two other workers' directories: **49 sites**, matching the brief's breakdown exactly
(utilities 11 · api 8 · licensing 6 · admin 5 · account 5 · root 5 · settings-api 3 · rest-api 2 ·
handlers 2 · setup 1 · http 1).

## Table

| file:line | hook name | return goes to | verdict | note |
|---|---|---|---|---|
| woodev/class-helper.php:690 | `woodev_queued_js` | `echo` of inline `<script>` block, in a hook fired on **every** front-end AND admin footer | FATAL | Only crashes on an object without `__toString()` (array/scalar just print oddly). Hooked on both `wp_print_footer_scripts` and `admin_print_footer_scripts` — includes checkout. |
| woodev/class-helper.php:946 | `woocommerce_is_rest_api_request` | `(bool)` cast, returned | GUARDED | Cast handles any input type. |
| woodev/class-lifecycle.php:292 | `woodev_{plugin_id}_milestone_message` | untyped `$message` param → stored/echoed via `Admin_Notice_Handler::add_admin_notice()` | HARMLESS | Untyped sink, admin-only notice; worst case a malformed/empty notice. |
| woodev/class-woodev-hook-deprecator.php:112 | `woodev_plugin_framework_show_deprecated_hook_notices` | `if (...)` condition | HARMLESS | Truthy check only; WP_DEBUG-gated dev-only code path. |
| woodev/class-woodev-plugin-dependencies.php:292 | `woodev_{plugin_id}_scripts_optimization_plugins` | `(array)` cast, then `foreach ($plugins as $filename => $plugin_name)` | GUARDED | Cast prevents a crash; garbage values just don't match `is_plugin_active()`. |
| woodev/account/class-account-connection.php:49 (`api_base()`) | `woodev_account_api_url` | `untrailingslashit()`, method declares `: string` | FATAL | `untrailingslashit()` calls `rtrim()`/similar on the raw value with no type check first; an array/object return throws before the `: string` return-type check even runs. |
| woodev/account/class-account-connection.php:101 (`authorize_url()`) | `woodev_account_authorize_url` | `untrailingslashit()`, method declares `: string` | FATAL | Same shape as the row above; default value is `$this->api_base()`, itself already vulnerable. |
| woodev/account/class-account-connection.php:338 | `woodev_account_request_timeout` | `(int)` cast | GUARDED | — |
| woodev/account/class-account-installer.php:106 (`is_trusted_package_url()`) | `woodev_account_api_url` (3rd independent call site) | `wp_parse_url( untrailingslashit( apply_filters(...) ) )` inside a **security** check (which store host a plugin package may be downloaded from) | FATAL | Same `untrailingslashit()` crash. This one is the most consequential of the three `woodev_account_api_url` sites: it fatals inside the trusted-host gate for "Install extension", not just a display helper. |
| woodev/account/class-account-installer.php:128 | `woodev_account_install_allowed_hosts` | `(array)` cast, then `array_filter(array_map('strtolower', array_map('strval', ...)))` | GUARDED | Every element forced through `strval()`/`strtolower()` before the exact-match `in_array()`; cannot be type-confused into a crash or a bypass. |
| woodev/admin/class-admin-pages.php:38 | `woodev_show_extensions_page` | `if (...)` condition | HARMLESS | — |
| woodev/admin/class-admin-pages.php:237 | `woodev_extensions_account_enabled` | `if ( ! ... )` condition | HARMLESS | Intentional feature toggle (hides the account-connect admin UI section) — disabling it IS the filter's purpose, not a validation bug. |
| woodev/admin/class-admin-pages.php:308 | `woodev_account_api_url` (2nd independent call site) | string concatenation: `apply_filters(...) . '/my-account/'` | FATAL | Array/object return → `TypeError: Unsupported operand types` on the `.` operator. Fires while building admin page data (`get_account_data`-style array), i.e. every load of the WP admin extensions page. |
| woodev/admin/class-admin-pages.php:330 | `woodev_extensions_account_enabled` (2nd call site, same hook as row above at 237) | `(bool)` cast | GUARDED | Same hook filtered twice independently — once guarded (here), once not (line 237, though the unguarded one is a plain `if()` so it's harmless anyway). |
| woodev/admin/class-ui-kit-gallery-page.php:53 | `woodev_ui_kit_gallery` | `(bool)` cast | GUARDED | — |
| woodev/api/abstract-cacheable-api-base.php:93 | `woodev_plugin_{plugin_id}_api_request_is_cacheable` | `(bool)` cast | GUARDED | — |
| woodev/api/abstract-cacheable-api-base.php:102 | `woodev_plugin_{plugin_id}_api_request_cache_lifetime` | `(int)` cast | GUARDED | — |
| woodev/api/class-api-base.php:318 (`get_request_uri()`) | `woodev_{api_id}_api_request_uri` | no cast → returned directly → `do_remote_request($uri, ...)` → `wp_safe_remote_request($uri,...)` → WP core's `wp_http_validate_url()` → `wp_parse_url()` → PHP's `parse_url()` | FATAL | `parse_url()` throws `TypeError` on a non-string arg since PHP 8 (not suppressible by `@`). This is the URL used for the **actual outbound API request** (licensing check, account call, whatever subclass this is) — not just logging. Most reachable of the two `_api_request_uri` sites. |
| woodev/api/class-api-base.php:394 (`get_sanitized_request_uri()`) | `woodev_{api_id}_api_request_uri` (same hook, 2nd independent call site) | no cast → `$uri` passed to `self::redact_secret_query_string( string $text, ... )` — typed param | FATAL | Direct `TypeError` on the typed parameter. This is the logging-path sibling of the s100 `redact_secret_log_text` bug — exact same shape, same file, not yet fixed. |
| woodev/api/class-api-base.php:761 (`redact_secret_log_text()`) | `woodev_api_log_text_secret_param_names` | validated: non-array dropped, each member checked `is_string()` + non-empty after canonicalization, empty result falls back to the default secret-name list | GUARDED | **This is the s100 reference fix** — drop-invalid-then-fall-back-to-safe-default. Used as the bar for every other site in this table. |
| woodev/api/class-api-base.php:1102 | `woodev_sl_api_request_verify_ssl` | stored as `'sslverify'` in the HTTP request args array | HARMLESS | Deliberate on/off toggle (default `true`); a filter turning it off is the intended use of the hook, not an unvalidated-return bug. |
| woodev/api/class-api-base.php:1110 (`get_request_args()`) | `woodev_{api_id}_http_request_args` | no cast → returned → consumed by `wp_safe_remote_request()` → WP core's `wp_parse_args()` | WRONG-DATA | `wp_parse_args()` degrades a non-array gracefully (treats it as empty/parsed-string, doesn't throw) but silently drops `sslverify`/`headers`/`body`/etc. — the live HTTP request goes out with defaults instead of the intended args. No crash, corrupted request. |
| woodev/api/class-api-base.php:1581 | `woodev_{plugin_id}_api_is_tls_1_2_available` | `(bool)` cast | GUARDED | — |
| woodev/handlers/class-translation-handler.php:108 | `plugin_locale` | no cast → string-concatenated into a `.mo` file path, then `date_i18n`-style consumer | FATAL | **Not woodev-specific** — this reproduces WP core's own `load_plugin_textdomain()` shape verbatim (WP core itself does this unguarded). Fixing it here alone doesn't fix the systemic issue; low actionability. |
| woodev/handlers/script-handler.php:155 | `wc_{id}_js_args` | no cast → `wp_json_encode($args)` embedded in inline `<script>` | WRONG-DATA | `wp_json_encode()` never throws for a wrong type; a non-array return just produces malformed/unexpected JS on the page (breaks that JS handler's init), not a PHP fatal. |
| woodev/http/trait-rest-rate-limit.php:410 (`get_client_ip()`) | `woodev_rest_rate_limit_client_ip` | `is_string($client) ? (self::valid_ip($client) ?? $edge) : $edge` | GUARDED | **Meets the reference bar.** Type-checked AND semantically validated (real IP), with a safe non-forgeable fallback (`$edge`). Docblock explicitly states the contract and matches the code. |
| woodev/licensing/class-license-authority-claims.php:222 (`get_public_key()`) | `woodev_license_authority_pubkey` | `(string)` cast | GUARDED | Close to the reference bar: cast prevents array/scalar crashes, and an empty/garbage key fails every signature verification closed (license required) per the docblock — safe-default direction is correct. Caveat: an **object** return without `__toString()` still fatals the `(string)` cast itself (rare, not covered by the cast). |
| woodev/licensing/class-license-command-dispatcher.php:709 (`get_public_key()`, duplicate) | `woodev_license_authority_pubkey` (same hook, independent call site) | `(string)` cast, identical logic | GUARDED | Byte-for-byte duplicate of the row above in a different class — not a bug, but worth collapsing into one shared helper (both already carry `@since 2.0.0`). |
| woodev/licensing/class-license-messages.php:109 (`get_date_format()`) | `woocommerce_date_format` | no cast → passed as `$format` into WP core's `date_i18n()` | FATAL | `date_i18n()` internally does `strlen($format)`-style operations that throw `TypeError` on array/object. **Not woodev-specific** — mirrors WC core's own `wc_date_format()` unguarded pattern exactly. |
| woodev/licensing/api/class-licensing-api-request.php:150 (`print_r()`) | `woocommerce_print_r_alternatives` | no cast → `foreach ($alternatives as $alternative)` | WRONG-DATA | `foreach` over a non-iterable is a PHP 8 **warning**, not fatal — loop body just doesn't run, function falls through to its own no-alternative-found path. Debug/log-formatting helper only. Also a straight copy of WC core's own filter/pattern. |
| woodev/licensing/api/class-licensing-api.php:53 (`is_debug_enabled()`) | `woodev_enable_license_logging` | no cast → returned directly, method declares **`: bool`** | FATAL | Weak-mode return-type coercion only accepts scalars; an array/object/`null` return from the filter throws `TypeError` on the `return` statement itself, before `broadcast_request()`'s `if()` ever runs. |
| woodev/licensing/api/class-licensing-api.php:90 (`get_url()`) | `woodev_license_base_url` | no cast → returned directly, method declares **`: string`** | FATAL | **Single most severe finding in this slice.** `get_url()` is called from `Woodev_Licensing_API::__construct()` (`$this->request_uri = $this->get_url()`) and from `Woodev_Plugin_Updater::__construct()` (`trailingslashit($this->api_handler->get_url())`) — i.e. on **every construction of a licensing API client or the plugin updater**, which happens routinely (license checks, `wp-admin` update screens). Same shape as the `Location_Service::resolve_geoip_default()` bug from s100: typed return, filter piped straight through, no cast, no validation. |
| woodev/rest-api/class-plugin-rest-api.php:74 | `wc_{plugin_id}_rest_api_system_status_data` | no cast → `$response->data[...] = $data` → later JSON-serialized by the REST framework | WRONG-DATA | `json_encode` never throws for odd PHP types; worst case is a malformed System Status REST payload. Admin/API-consumer-only surface. |
| woodev/rest-api/controllers/class-rest-api-extensions.php:271 (`store_base()`) | `woodev_extensions_store_url` | `untrailingslashit()`, method declares `: string` | FATAL | Same `untrailingslashit()` crash shape as the three `woodev_account_api_url` sites above. Feeds the "Extensions" catalog REST proxy. |
| woodev/settings-api/abstract-class-settings.php:652 (`get_setting_types()`) | `woodev_{id}_settings_api_setting_types` | no cast → `in_array( $type, $this->get_setting_types(), true )` in `register_setting()` | FATAL | `in_array()`'s 2nd argument is type-checked in PHP 8; a non-array return throws `TypeError` inside `register_setting()`, which typically runs during plugin bootstrap/settings registration. |
| woodev/settings-api/abstract-class-settings.php:689 (`get_control_types()`) | `woodev_{id}_settings_api_control_types` | same shape, consumed in `register_control()` | FATAL | Same as above, different registration path. |
| woodev/settings-api/abstract-class-settings.php:707 (`get_setting_control_types()`) | `woodev_{id}_settings_api_setting_control_types` | `! empty($x) && ! in_array($type, $x, true)` in `register_control()` | FATAL | The `!empty()` guard only skips an empty/falsy return; any non-empty non-array (e.g. a non-empty string) still reaches `in_array()` and throws. |
| woodev/setup/class-setup-wizard.php:83 (`build_steps()`) | `woodev_{id}_setup_wizard_steps` | no cast → `array_filter($steps, fn($step) => $step instanceof Step && $step->is_visible())` | FATAL | `array_filter()`'s first argument must be an array — throws on a non-array top-level return. Mixed case worth noting: the **element-level** filtering (`instanceof Step` check) is already well-guarded against garbage entries inside a valid array; only a wrong top-level type crashes. Admin-only setup wizard, low blast radius. |
| woodev/utilities/class-woodev-async-request.php:117 (`get_request_args()`) | `https_local_ssl_verify` | stored as `'sslverify'` | HARMLESS | Reproduces the well-known `WP_Async_Request`/WP-Background-Processing library's own pattern verbatim — deliberate toggle, not a woodev-introduced gap. |
| woodev/utilities/class-woodev-background-job-handler.php:218 (`lock_process()`) | `{identifier}_queue_lock_time` | no cast → 3rd arg of `set_transient()` | WRONG-DATA | `set_transient()` doesn't type-check its expiration arg strictly; a bad value just produces a wrong/ineffective lock duration, no crash. |
| woodev/utilities/class-woodev-background-job-handler.php:262 (`memory_exceeded()`) | `{identifier}_memory_exceeded` | no cast → returned → consumed via `if ($this->memory_exceeded())` | HARMLESS | Truthy-check consumer, no crash. Documented override point (letting a plugin force/skip the memory guard) — disabling it IS the intended use, same reasoning as the ssl-verify toggles above. |
| woodev/utilities/class-woodev-background-job-handler.php:305 (`time_exceeded()`) | `{identifier}_default_time_limit` | no cast → `$this->start_time + apply_filters(...)` (arithmetic `+`) | FATAL | PHP 8 throws `TypeError: Unsupported operand types` for `int + array`/`int + non-numeric-string`. Runs inside the background-job processing loop (cron/async) — fails **later and out of sight**, exactly the utilities risk the brief called out. |
| woodev/utilities/class-woodev-background-job-handler.php:317 (`time_exceeded()`, final return) | `{identifier}_time_exceeded` | no cast → returned → consumed via `while (! $this->time_exceeded() ...)` | HARMLESS | Truthy-check consumer; same by-design-override reasoning as `_memory_exceeded`. |
| woodev/utilities/class-woodev-background-job-handler.php:352 (`create_job()`) | `{identifier}_new_job_attrs` | no cast → 2nd arg of `wp_parse_args( array(must-have-keys), $attrs )` | GUARDED (incidental) | The call is `wp_parse_args($hardcoded_array, $filtered_attrs)` — note the ARGUMENT ORDER: the hardcoded must-have keys (`id`, `created_at`, `created_by`, `status`) are `$args`, and the filtered value is `$defaults`. `wp_parse_args()`'s merge (`array_merge($defaults, $args)`) means the hardcoded keys always win on collision, and a non-array `$attrs` is simply ignored (`is_array($defaults) && $defaults` guard inside `wp_parse_args`) rather than crashing. Safe, but by accident of argument order rather than deliberate validation — flagging so it isn't miscredited as a designed guard if this code is ever refactored. |
| woodev/utilities/class-woodev-background-job-handler.php:455 (`get_job()`) | `{identifier}_returned_job` | no cast → returned as `stdClass\|object\|null` → `process_job()` does `$job->status`, `isset($job->{$data_key})`, then **`throw new Exception(...)`** if the data key is missing | FATAL | If the filter returns e.g. `null` or a malformed object, `process_job()` throws an uncaught `Exception` (declared `@throws Exception`, not caught in `handle()`) — PHP fatal in the admin-ajax/cron request handling the job. Same "fails later, out of sight" utilities risk. |
| woodev/utilities/class-woodev-background-job-handler.php:534 (`get_job()`, 2nd call site — duplicated logic, not the same call, same hook) | `{identifier}_returned_job` | same shape as row above | FATAL | Same hook filtered independently a second time in what looks like a duplicated/legacy code path in the same file; same crash shape. |
| woodev/utilities/class-woodev-background-job-handler.php:855 (`schedule_cron_healthcheck()`) | `{identifier}_cron_interval` | no cast → `MINUTE_IN_SECONDS * $interval` (arithmetic `*`), registered on WP core's `cron_schedules` filter | FATAL | Highest blast radius of the utilities findings: `schedule_cron_healthcheck()` is itself a callback on WP core's own `cron_schedules` filter, which WordPress core calls broadly (any code calling `wp_get_schedules()`, cron admin screens, `wp_next_scheduled()` paths that enumerate schedules). A crash here can affect **site-wide cron scheduling**, not just this job handler's own queue. |
| woodev/utilities/class-woodev-job-batch-handler.php:78 (`render_js()`) | `{identifier}_batch_handler_js_args` | no cast → `wp_json_encode($args)` | WRONG-DATA | Same shape as `script-handler.php:155` — malformed inline JS, not a PHP crash. |
| woodev/utilities/class-woodev-job-batch-handler.php:239 | `{identifier}_batch_handler_items_per_batch` | `absint()` | GUARDED | `absint()` never throws regardless of input type. |

## Worth acting on

Ranked FATAL first (no DISABLES verdicts found in this slice — see Counts below for why).

1. **`woodev/licensing/api/class-licensing-api.php:90` (`get_url()`, hook `woodev_license_base_url`)** — the single most severe finding here: a `: string`-typed method returning `apply_filters()` unguarded, constructed on every licensing-API/updater instantiation. Fix: `return is_string( $url = apply_filters(...) ) && '' !== trim( $url ) ? $url : $this->api_url;` (fall back to the constructor default, never to an empty/garbage URL).
2. **`woodev/licensing/api/class-licensing-api.php:53` (`is_debug_enabled()`, hook `woodev_enable_license_logging`)** — same pattern, `: bool`-typed. Fix: wrap in `(bool)`.
3. **The `woodev_account_api_url` / `woodev_account_authorize_url` / `woodev_extensions_store_url` family** (`account/class-account-connection.php:49,101`, `account/class-account-installer.php:106`, `admin/class-admin-pages.php:308`, `rest-api/controllers/class-rest-api-extensions.php:271` — 6 rows, effectively 2 distinct crash shapes repeated 6 times because each call site filters independently instead of going through one validated helper). Fix: introduce one `Woodev_Helper::filtered_url( string $hook, string $default, ...$extra_args ): string` that validates `is_string()` + non-empty + (ideally) `wp_http_validate_url()` before calling `untrailingslashit()`, and route all 6 sites through it. This single helper would close 6 of the 20 FATAL rows in this table at once, including the security-relevant `is_trusted_package_url()` one.
4. **`woodev/api/class-api-base.php:318` and `:394` (`woodev_{api_id}_api_request_uri`)** — the URI actually used both for the live outbound API call (:318) and for the logged/redacted version (:394). Fix: validate `is_string()` after each `apply_filters()` call, falling back to the pre-filter `$uri` on failure — matching the pattern already used correctly two functions below it (`redact_secret_log_text()`, line 761).
5. **`woodev/settings-api/abstract-class-settings.php:652,689,707`** — three `in_array()`-typed sinks in the Settings API's own type/control registration. Fix: `(array) apply_filters(...)` at each of the three sites (matches the existing `(array)` pattern used in `class-woodev-plugin-dependencies.php:292`).
6. **`woodev/utilities/class-woodev-background-job-handler.php:455,534` (`{identifier}_returned_job`)** and **`:305`/`:855`** (arithmetic on filtered numeric values) — background/cron path, fails invisibly. Fix for `_returned_job`: `is_object($filtered) ? $filtered : $job` (fall back to the DB-sourced job). Fix for the two arithmetic sites: cast with `(int)` before the `+`/`*` operation, same as `absint()` is already used at `class-woodev-job-batch-handler.php:239`.
7. **`woodev/setup/class-setup-wizard.php:83`** — `(array) apply_filters(...)` before `array_filter()`, matching the plugin-dependencies pattern.
8. Lower priority, flagged for completeness but **not woodev-specific** (they reproduce WP core's / WooCommerce core's own unguarded filter usage verbatim, so a local fix doesn't address the systemic pattern): `handlers/class-translation-handler.php:108` (`plugin_locale`) and `licensing/class-license-messages.php:109` (`woocommerce_date_format`).

## Counts

Sites examined: **49** — matches the brief's stated count for this slice exactly (49 `apply_filters()` calls; root 5, account 5, admin 5, api 8, handlers 2, http 1, licensing 6, rest-api 2, settings-api 3, setup 1, utilities 11).

| Verdict | Count |
|---|---|
| FATAL | 20 |
| DISABLES | 0 |
| WRONG-DATA | 6 |
| GUARDED | 15 |
| HARMLESS | 8 |
| UNKNOWN | 0 |
| **Total** | **49** |

**No `DISABLES` verdicts in this slice.** Several sites LOOK like candidates at first glance
(`woodev_sl_api_request_verify_ssl`, `https_local_ssl_verify`, `{identifier}_memory_exceeded`,
`{identifier}_time_exceeded`, `woodev_extensions_account_enabled`) because a type-valid return
can trivially turn a check off — but in every one of those cases turning it off **is the
documented, intended purpose of the hook** (an explicit override point), not an unvalidated-return
bug in the sense the brief is hunting for (a `[ '' ]`-shaped "looks fine, silently disables"
defect). I did not find a licensing/activation-state filter in this slice whose SAFE type-valid
return silently disables validation the way the s100 `[ '' ]` case did — the two licensing pubkey
sites (`class-license-authority-claims.php:222`, `class-license-command-dispatcher.php:709`) are
in fact the closest thing to a second reference implementation: garbage/empty pubkey fails closed
(license required), not open.

## Where I could not reach a firm conclusion

None marked `UNKNOWN`. Every site's downstream sink was traced to either a typed consumer
(→ FATAL), a validating cast/check (→ GUARDED), a type-tolerant consumer (→ WRONG-DATA/HARMLESS),
or a plain boolean condition (→ HARMLESS), using `find_referencing_symbols` to confirm the actual
call sites rather than assuming from the docblock alone.

## Corrections to the brief

- The brief's per-directory counts (`utilities 11 · api 8 · licensing 6 · admin 5 · account 5 ·
  woodev/*.php (root) 5 · settings-api 3 · rest-api 2 · handlers 2 · setup 1 · http 1`) are
  **exactly correct** — verified independently via `search_for_pattern`, no discrepancy.
- One nuance the brief doesn't call out: three of the "5 account" / "5 admin" sites and both
  "settings-api" duplicate hooks are the *same hook name* filtered at multiple independent call
  sites (`woodev_account_api_url` ×3, `woodev_license_authority_pubkey` ×2,
  `woodev_extensions_account_enabled` ×2, `{identifier}_returned_job` ×2). Each occurrence is a
  separate `apply_filters()` call with its own validation (or lack of it), so I kept them as
  separate table rows per the brief's "one row per site" instruction — but a fix to one occurrence
  does NOT fix its siblings, which is exactly why `woodev_account_api_url` shows up as FATAL twice
  and GUARDED nowhere despite being "the same filter."
