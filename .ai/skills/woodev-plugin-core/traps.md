# Traps that already cost us time — for plugin authors

One line per trap; each links its gotcha file in the framework repository. Full map of every topic:
`docs-internal/GOTCHAS.md`. Only the traps that matter to someone WRITING A PLUGIN are listed; framework
internals (class-map wiring, build/CI, rig) are left out on purpose. Shipping-specific traps are in
[../woodev-shipping-plugin/SKILL.md](../woodev-shipping-plugin/SKILL.md) (its traps section).

## Loading and bootstrap (topic `bootstrap`)

- A loader definition may hold NO framework constant — the array is built before the autoloader exists;
  write `'woocommerce'`, not `PLATFORM_WOOCOMMERCE`. →
  `docs-internal/gotchas/a-loader-definition-cannot-use-a-framework-class-constant.md`
- Register at file level during inclusion; a registration from a hook at or after `plugins_loaded` is
  silently ignored. →
  `docs-internal/gotchas/plugin-registration-timing.md`
- `Woodev_Plugin_Bootstrap` is a singleton with a private constructor; `new` is a fatal. →
  `docs-internal/gotchas/singleton-instantiation.md`
- Any globally named class reachable from the bootstrap path is part of the multi-copy collision
  surface — guard it with `class_exists( …, false )`, and take specialised bases from the selected
  (highest) copy. →
  `docs-internal/gotchas/multiversion-early-class-guards.md`

## Plugin class and framework wiring (topics `framework`, `framework-wiring`)

- Return a plain, untranslated string from `get_plugin_name()` — the plugin constructor calls it on
  `plugins_loaded`, before `init`, and a `__()` there logs WordPress's «translation loaded too early» notice
  on every request. → `docs-internal/gotchas/get-plugin-name-runs-before-init-so-a-translated-name-warns.md`
- A hook registered as `[ $this, … ]` from an object every plugin builds fires once PER PLUGIN; use a
  static callback for site-wide hooks. →
  `docs-internal/gotchas/a-hook-registered-from-a-per-plugin-object-fires-once-per-plugin.md`
- A handler that self-registers its hook can silently disable a subclass override — preserve the override
  chain when you replace a handler. →
  `docs-internal/gotchas/handler-extraction-must-preserve-override-chain.md`
- A feature built on both sides with nothing calling it in the middle: after writing a handler/provider,
  prove something CALLS it (registered on the right request, not only under `is_admin()`). →
  `docs-internal/gotchas/built-on-both-sides-with-no-caller-in-the-middle.md`
- A module that writes into another module's field must announce the write, or the owner reads it as the
  user's. →
  `docs-internal/gotchas/a-module-that-writes-into-another-modules-field-must-announce-it.md`
- Redact secrets at the SINK (the logger / the sender), not at each call site — four sweeps by one spelling
  each declared it finished. →
  `docs-internal/gotchas/grep-the-sink-not-one-spelling-of-it.md`

## PHP and WordPress (topic `php`)

- Repointing a class at a stricter base fatals at DECLARATION (omitted return type, `final`, narrowed
  visibility) while mocked tests stay green — run `npm run probe:signature`. →
  `docs-internal/gotchas/a-stricter-base-class-fatals-on-signatures.md`
- A base constructor that ASSIGNS a property the subclass set before `parent::__construct()` discards it
  silently. →
  `docs-internal/gotchas/a-base-constructor-that-assigns-what-the-subclass-just-set.md`
- A cast is not a degradation: `absint()` on garbage is `0` and `0` stops background jobs; degrade a
  filter result to the PRE-FILTER value. →
  `docs-internal/gotchas/a-cast-is-not-a-degradation.md`
- Four stdlib traps that pass tests and fail in production (`is_numeric()` accepts `0.5`/`+1`/`1e3`, then
  `(int)` turns `0.5` into the deletion sentinel `0`). →
  `docs-internal/gotchas/php-stdlib-traps-that-survive-tests.md`
- User meta is shared by every site of a multisite network: customer data tied to one site (a provider's codes, a chosen
  locality) goes through `update_user_option()`/`get_user_option()`/`delete_user_option()` (blog-prefixed row), and
  `get_user_option()` falls back to the bare key, so after a forget leave a per-site tombstone row (never delete the
  shared bare row — another site owns it). →
  `docs-internal/gotchas/user-meta-is-network-wide-on-multisite-store-per-site-data-with-user-option.md`
- A `NOT IN` meta clause drops every row that has no such meta; only `NOT EXISTS` makes a LEFT JOIN. →
  `docs-internal/gotchas/a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all.md`
- `add_option()` is NOT an atomic lock: it checks existence, then upserts, so two contenders both «acquire» (found in the CDEK
  courier-status scan, edostavka PR #113). Claim with `INSERT IGNORE` + an owner token and a conditional UPDATE/DELETE
  (`woodev/error-reporting/class-dispatcher.php`), or `GET_LOCK` (`Order_Lock`). →
  `docs-internal/gotchas/wp-add-option-is-not-an-atomic-lock.md`
- **Reserve before send.** A call that costs quota (a carrier's daily limit, a paid lookup) must be counted BEFORE it is sent, and only
  sent when the reservation was really stored. One non-autoloaded option row per counter, changed ONLY by compare-and-swap
  (`UPDATE {$wpdb->options} SET option_value = <new> WHERE option_name = <n> AND BINARY option_value = <what you read>`, or
  `INSERT IGNORE` for a missing row): read the row straight from `$wpdb` (never the options cache), compute the new value, swap, and
  recompute when you lost (bounded retries); of N racers exactly one wins each value. FAIL CLOSED: a read or write that errors, or a row
  that stays contended, means «do not send», never «send anyway». Carry the day/window inside the record so it resets by itself. Worked
  example: `Russian_Post_Gate_Storage` (`woodev-russian-post` `v2`, verified under 8-way MariaDB concurrency); do not use `add_option()`/
  `update_option()` for this. → `docs-internal/gotchas/update-option-returns-false-on-an-unchanged-value-as-well-as-on-a-failed-write.md`
- `update_option()` returns `false` BOTH when the write failed AND when the value is unchanged. Treating false as failure blocks a
  repeated state forever; ignoring it lets an unpersisted change through. On false, `wp_cache_delete( $key, 'options' )`, re-read with
  `get_option( $key, null )` and compare with what you meant to store. →
  `docs-internal/gotchas/update-option-returns-false-on-an-unchanged-value-as-well-as-on-a-failed-write.md`
- A remaining-capacity bound as a raw float subtraction refuses the final cent (`10.00 - 9.99`). →
  `docs-internal/gotchas/a-remaining-capacity-computed-as-a-raw-float-refuses-the-final-cent.md`
- A sanitiser that leans on an optional extension is not one (`mb_substr()`; `ext-mbstring` is not a
  declared requirement). →
  `docs-internal/gotchas/a-sanitiser-that-leans-on-an-optional-extension.md`
- `parse_str()` loses information, so a subset match built on it widens into a false positive. →
  `docs-internal/gotchas/parse-str-loses-information-so-never-compare-queries-with-it.md`
- `admin_init` never fires for an admin page slug nobody registers (core 403s first); hook
  `admin_page_access_denied` for a legacy-URL redirect. →
  `docs-internal/gotchas/admin-init-never-fires-for-an-unregistered-admin-page-slug.md`
- `deactivate_plugins()` and the rest of `wp-admin/includes/plugin.php` are UNDEFINED on `plugins_loaded`;
  Brain Monkey defines them so unit tests stay green. →
  `docs-internal/gotchas/admin-only-wp-functions-are-undefined-on-plugins-loaded.md`
- `wp_remote_post( blocking => false )` still waits up to `timeout` on cURL — no network in request or
  shutdown paths; enqueue and send from cron. →
  `docs-internal/gotchas/wp-remote-post-blocking-false-is-not-asynchronous-on-curl.md`
- A base-class property initialised only by a subclass must be nullable: `get_blocks_handler()` is
  `?Woodev_Blocks_Handler` and is `null` for a pure-WordPress plugin (the old `TypeError` is resolved —
  null-check the getter). →
  `docs-internal/gotchas/blocks-handler-typed-property-trap.md`
- Do not declare `memory_limit` in `php_settings`: the dependency handler compares `-1` (unlimited) as a number
  and reports it as below the minimum (#1316).
- `Woodev_API_JSON_Response` already decodes the body (into stdClass) on construction; decoding `to_string()` a
  second time into an array doubles the memory of every large carrier response (~+45 MB on 2289 pickup points).
  Decode once in your response subclass and read that one representation everywhere — CDEK PR #118, Yandex #56 (#1269).
- `Woodev_Plugin::is_plugin_active()` compares BARE file names (it strips the folder from every `active_plugins`
  entry): passing `folder/file.php` never matches, and a test that mocks it stays green. Compare the full basename
  with `get_option( 'active_plugins' )` plus `active_sitewide_plugins` yourself. →
  `docs-internal/gotchas/woodev-plugin-is-plugin-active-matches-the-bare-filename-only.md`
- A REST argument whose `default` lies outside its own `minimum`/`maximum` rejects EVERY request that omits it
  (WordPress validates the default like a sent value); leave an optional argument without a default and treat
  `null` as absent. Unit tests that call the handler directly skip validation — prove a new route with
  `rest_do_request()`. →
  `docs-internal/gotchas/a-rest-arg-default-outside-its-own-minimum-rejects-every-request.md`
- WooCommerce caches the calculated rates per package hash in the session: a gate that HIDES a rate (a licence,
  a setting) and then flips back keeps serving the cached empty set until the cart cache is cleared, unless the
  gate's state is part of the package. →
  `docs-internal/gotchas/woocommerce-caches-rates-per-package-hash-so-a-rate-gate-must-change-the-package.md`
- Legacy `Woodev_*` classes vs namespaced `Woodev\Framework\*`: new code is namespaced; know which
  spelling a symbol has before you type it. →
  `docs-internal/gotchas/namespace-migration-legacy-psr4.md`

## Settings API (topic `settings-api`)

- A setting with a `'name'` label and no `register_control()` never renders. →
  `docs-internal/gotchas/a-registered-setting-without-a-control-never-renders.md`
- A section declaring no setting ids renders the WHOLE handler when read through `get_settings( [] )`
  (which means "all"). →
  `docs-internal/gotchas/section-empty-setting-ids-renders-all-fields.md`
- `Woodev_Setting::get_value()` is a cached property, not a live option read. →
  `docs-internal/gotchas/woodev-setting-get-value-is-cached-not-a-live-option-read.md`
- Save path: validate enums by key-or-value, coerce numbers, sanitise HTML. →
  `docs-internal/gotchas/settings-api-control-save-path-pitfalls.md`
- A `TYPE_STRING` enum map keyed by numeric strings (`[ 'none' => …, '0' => '0%', '5' => '5%' ]` — a VAT rate, a day
  count) used to be refused at registration with `_doing_it_wrong()` and rejected on save, because PHP casts `'5'`
  to `int 5` as an array key. Since framework #1288 the schema carries `{value, label}` pairs in declared order and
  validation compares the key as a string (`'05'` and the int `5` are still refused). Update the bundled framework
  before relying on it, and send the value as a string. → framework PR #1288; the user is Yandex's «Ставка НДС» (PR #91).
- A `constant_name`-backed field is masked even when the constant is undefined. →
  `docs-internal/gotchas/mask-constant-backed-field-even-when-constant-undefined.md`
- The "don't overwrite a stored secret with an empty submit" guard is client-side only. →
  `docs-internal/gotchas/settings-sensitive-secret-empty-skip-is-client-side.md`
- Format validators must guard non-string input (`is_email`/`strpos` on `null` is a PHP 8.1 deprecation). →
  `docs-internal/gotchas/format-validator-null-strlen-deprecation.md`
- For a string enum with numeric keys starting at zero, declare `options` as
  `[ [ 'value' => '0', 'label' => 'No' ], … ]`; PHP otherwise cannot distinguish the map from a flat list.
  Pairs work with every setting type and require string labels; malformed/duplicate pairs reject all values
  even with `WP_DEBUG` off. An unchanged `get_options()` → `set_options()` round trip preserves the pair
  declaration. → `docs-internal/gotchas/settings-api-control-save-path-pitfalls.md`; typed settings guide.

## Testing (topic `testing`)

- Define every WordPress time constant in a plugin's unit bootstrap — a framework class constant built on
  `MINUTE_IN_SECONDS` took Yandex's suite from 489 green to 21 errors while the framework's stayed green. →
  `docs-internal/gotchas/a-framework-class-constant-on-a-wp-time-constant-breaks-a-plugin-unit-bootstrap.md`
- PHPUnit 9 ignores `@runInSeparateProcess` on a CLASS docblock (method annotation, or
  `@runTestsInSeparateProcesses` on the class); only CI's reverse-order gate shows the leak. →
  `docs-internal/gotchas/phpunit-9-ignores-run-in-separate-process-on-a-class.md`
- A process test that exhausts `memory_limit` and expects exit 255 plus a shutdown-function log fails on the hosted runner's rebuilt
  php7.4/8.0/8.1 packages (they exit 1 and skip shutdown). Probe the engine in a child process first and skip explicitly with the measured
  reason; never loosen the assertion. →
  `docs-internal/gotchas/a-hosted-runner-php-build-exits-1-on-a-memory-limit-fatal-without-running-shutdown-functions.md`
- Three bugs passed green because the test mocked the very seam that was wrong (REST validation, `is_plugin_active`,
  WC note actions): when a test stubs the thing under suspicion, add one test that runs the real one. →
  `docs-internal/sessions/s170.md` (How the work went)

## HPOS and compatibility (topic `compat`)

- Never `get_post_meta()` on orders; use the `WC_Order` API or `Woodev_Order_Compatibility`. →
  `docs-internal/gotchas/hpos-order-meta-safety.md`
- A row rebuilt from the same `WC_Order` after an action is stale only on the legacy CPT store
  (`update_order_meta()` writes AROUND the object there). →
  `docs-internal/gotchas/a-row-rebuilt-after-an-action-is-stale-only-on-the-legacy-cpt-store.md`
- CPT order-meta writes left WC's cached order meta stale before WC 10. →
  `docs-internal/gotchas/cpt-order-meta-writes-leave-wc-meta-cache-stale-before-wc-10.md`

## Lifecycle (topic `lifecycle`)

- Install vs upgrade is a version comparison on `woodev_{id}_version`; delete or hand-edit that option and
  the install routine re-runs (or every upgrade does). →
  `docs-internal/gotchas/lifecycle-install-upgrade-detection.md`

## Licensing (topic `licensing`)

- `is_need_license()` is presentation, `is_license_required()` is enforcement; never gate features on the
  first. →
  `docs-internal/gotchas/license-need-vs-required.md`
- Plugin ids starting with `woodev` get a doubled prefix in the license-key option — compute it before
  choosing an id for a migrated plugin. →
  `docs-internal/gotchas/license-key-option-double-prefix.md`
- EDD reports activation failures through `error`, not `license`, and only for TOKEN errors. →
  `docs-internal/gotchas/edd-error-field-vs-license-status.md`
- A single-plugin site cannot render its own remote-deactivation banner (accepted by design). →
  `docs-internal/gotchas/single-plugin-site-cannot-render-its-own-deactivation-banner.md`
- EDD `get_version` returns `sections`/`banners`/`icons` as PHP-serialised STRINGS. →
  `docs-internal/gotchas/edd-sl-get-version-serialized-sections.md`

## Internationalisation (topic `i18n`)

- Load shared translations before building notices; defer constructor labels and resolve persisted
  milestone keys at display time. `load_plugin_textdomain()` alone cannot merge an already-loaded
  external pack on WP 6.7+. See [services.md](services.md#internationalisation) and
  `docs-internal/gotchas/an-external-language-pack-needs-an-explicit-bundled-fallback.md` (#1361).

- A plugin with storefront strings and NO `languages/` directory renders every one in English (Yandex shipped so
  until the operator's pre-release pass): ship `{domain}-ru_RU.po/.mo` and a storefront-translation unit test from
  the first storefront string. → [services.md](services.md#internationalisation)
- An English msgid WITHOUT a catalogue entry is a storefront regression, and a gate that reads only the
  `.po` cannot see it. →
  `docs-internal/gotchas/rule-1-has-two-halves-an-english-msgid-alone-is-a-regression.md`
- A built bundle's `__()` is answered by handle-named JSON from `wp_set_script_translations()`, never by
  the `.mo`. →
  `docs-internal/gotchas/js-translations-are-handle-named-json-files.md`
- `_n()` with a Russian msgid is right in PHP once all three `msgstr` forms exist; it cannot be fixed in JS. →
  `docs-internal/gotchas/russian-source-i18n-plural-n.md`
- `__( 'half.' . ' half.' )` is one msgid to gettext and zero to a single-literal scanner. →
  `docs-internal/gotchas/a-concatenated-msgid-is-invisible-to-a-single-literal-scanner.md`
- Classify a string by its RENDER PATH, not by its file's directory. →
  `docs-internal/gotchas/classify-an-i18n-string-by-its-render-path-not-its-file-path.md`

## Related

- [SKILL.md](SKILL.md)
