# Task D — CDEK v2 (2.3.0.4 / framework 2.0.7) next to Russian Post v1 (1.2.6.0 / framework 1.3.3)

> Part of the [Russian Post v2 fact base](README.md) (s191, 21.10.2026). Copied from the research run with credentials, account ids and
> local paths replaced by `<redacted>`. Raw response directories (`f-raw/`, `g-raw/`, `/private/tmp/...`) are NOT in the repo, so evidence-file
> references below point at files that exist only on the research machine. **Where [v-verify.md](v-verify.md) contradicts this report, v-verify wins** — see the README section «Contradictions resolved».

Method: read-only code analysis (no rig, nothing installed). Read 21.10.2026.
Paths: `RP` = `plugins-reference/woodev-russian-post/`, `CDEK` = `<local woocommerce-edostavka checkout>/` (branch v2, HEAD `dad3b9b`).
The framework repo's own `woodev/bootstrap.php` differs from the CDEK-bundled copy by one line (`FeaturesUtil` `class_exists(..., false)`, line 208), so CDEK's copy is cited.

## 0. Short answer

- **They cannot both run, and nothing fatals.** The framework ships an explicit guard for exactly this "v1 plugin next to v2 plugin" case, in both directions. Which plugin survives is decided by **which plugin file WordPress loads first** (alphabetical `dir/file` in `active_plugins`).
- Direction A (CDEK loads first, the likely one): CDEK runs, **Russian Post is quarantined** — its shipping methods vanish from checkout, with only a red admin notice (admin screens only).
- Direction B (RP loads first): RP runs, **CDEK 2.3.0.4 stays dormant** — red admin notice naming RP as the blocker.
- Either way the notice tells the merchant to "update the old plugin", but **no newer Russian Post exists**, so a merchant who has both and wants CDEK v2 loses Russian Post until RP v2 is released. That is the operator's concern, and it is confirmed.
- The 2.0.x guards are covered by unit tests with **stubbed bootstraps**; no test or rig run loads a *real* v1 plugin beside a real v2 plugin. A live measurement is described in §7.

## 1. Multi-version bootstrap

### 1.1 How the loader picks a framework

| Step | v1 copy (RP, 1.3.3) | v2 copy (CDEK, 2.0.7) |
|---|---|---|
| First plugin file WP loads defines the singleton | `RP/woodev/bootstrap.php:5` `if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) )` — first copy wins | `CDEK/woodev/loader.php:65` `class_exists( 'Woodev_Plugin_Bootstrap', false )`; `CDEK/woodev/bootstrap.php` has the same guard |
| Entry file registers | `RP/woodev-russian-post.php:31` `Woodev_Plugin_Bootstrap::instance()->register_plugin( '1.3.3', name, __FILE__, 'init_wc_russian_post_shipping_init', [minimum_wc/wp, 'backwards_compatible' => '1.2.1'] )` | `CDEK/woocommerce-edostavka.php:28` requires `woodev/loader.php`; `:44` `Woodev_Loader::register( FILE, [plugin_id, download_id, framework_version '2.0.7', backwards_compatible '2.0.0', main_class, callback, ...] )` |
| Registration method | `RP/woodev/bootstrap.php:66` `register_plugin(...)` | `CDEK/woodev/bootstrap.php:87` `register_loader_definition()`; `:126` `register_plugin( ...$args )` is a tombstone only |
| Pick the framework at `plugins_loaded` | `:76` `load_plugins()`: `:78` `usort` by version desc, `:84-88` requires `class-plugin.php` from the highest | `class-framework-resolver.php:246` `load_plugins()`, `:253` `usort`, `:261-291` autoloader registered against the winner, then `class-plugin.php` required |
| Refuse older plugin | `:90` `backwards_compatible` of the winner vs each plugin version, → `incompatible_framework_plugins`, notice `:179` (`render_update_notices`) | `resolver:294-295` same rule |

Key fact: the **bootstrap class is whichever copy loads first** (v1 or v2); the framework *classes* come from the highest declared version among plugins that register *to that bootstrap*. A v1 bootstrap never sees a v2 definition and a v2 bootstrap never runs a v1 callback.

### 1.2 Existing guard for "plugin needing an older major" — YES, both directions

| Direction | Condition | Code | Behaviour |
|---|---|---|---|
| **A: v2 bootstrap won**, v1 plugin calls `register_plugin()` | CDEK file earlier in load order than RP | `CDEK/woodev/bootstrap.php:126-141` tombstone (variadic, untyped, extracts name from arg 1, path from arg 2); notice `:148-185` | RP callback is **never invoked**; RP recorded as "legacy"; admin notice (only `is_admin() && !DOING_AJAX`, hooked once): «Следующие плагины устарели и поэтому не были запущены: <RP name>. Обновите их до последней версии.» (`:178`) |
| **B: v1 bootstrap won**, v2 plugin calls `Woodev_Loader::register()` | RP file earlier in load order than CDEK | `CDEK/woodev/loader.php:74` `method_exists( $instance, 'register_loader_definition' )` is false → `record_dormant_plugin` `:100`, returns false | CDEK **does not initialise** (callback never called); notice `render_dormant_notice` `:126-186`: «Плагин <CDEK> не запущен: на сайте активен плагин <RP> с устаревшей версией, который блокирует его запуск. Обновите <RP> до последней версии.» (`:165`); RP name resolved via reflection on the winning bootstrap file + `get_plugins()` (`resolve_conflicting_plugin_name :234`), generic wording if undeterminable (`:178`) |

- Both guards are "site-availability armor, not a deprecation shim" (ADR-005; `AGENT-RULES.md:173`; `FUTURE-BACKLOG.md` B-1 row, "DONE s7, PR #27"; OB-1/#104 added the dormant notice in 2.0.2).
- `docs-internal/FUTURE-BACKLOG.md:31-34` (OB-1) still says "no notice" in direction B — **stale**, the notice exists (`loader.php:100-186`, test `LoaderDormantNoticeTest`).
- Neither notice has an "ignore / keep both" path. `render_update_notices` of v1 (`RP/woodev/bootstrap.php:179`) and the `?woodev_framework_deactivate_newer=yes` link (v1 `:150-160`) only exist for v1-vs-v1 skew; they are not involved here because the plugins are on different bootstraps.

### 1.3 Which direction will merchants hit?

WordPress loads `active_plugins` in stored order, and core stores it sorted (`sort()` on activation) by `dir/file`. CDEK is `woocommerce-edostavka/woocommerce-edostavka.php`. The RP directory name on shops is **UNVERIFIED** (the reference copy sits in `woodev-russian-post/`; text domain is `woocommerce-russian-post`):

| RP folder | Sorts vs `woocommerce-edostavka` | Direction |
|---|---|---|
| `woocommerce-russian-post` | after (`e` < `r`) | A — CDEK v2 wins, RP quarantined |
| `woodev-russian-post` | after (`wooc` < `wood`) | A |
| `russian-post…` / anything below `woocommerce-e…` | before | B — RP wins, CDEK dormant |

Multisite network-active plugins load before site-active ones, and `WOODEV_FRAMEWORK_DIR` (`loader.php:55`, rig-only) would change which bootstrap file is used — both inferred from WP core, not tested. If the loaded bootstrap is v2, RP's top-level code is harmless: `RP/woodev-russian-post.php:27` guard, `:31` tombstone call, `:40` function declaration never called.

Nothing in the file order is stable across a *manual* re-sort, plugin rename, or a symlinked install, so the merchant can see either result.

## 2. v1 APIs Russian Post 1.2.6.0 uses that break on v2

Applies only to the hypothetical "v2 framework loads RP callback" path — which the loader **never takes** (§1.2). Listed so a port knows the delta. Verified by grepping `woodev/` of the repo (v2 2.0.7).

### 2.1 Would fatal (hard breaks)

| # | RP code | v2 reality | Result |
|---|---|---|---|
| 1 | `RP/woodev-russian-post.php:662` `protected function init_setup_wizard_handler()` (no return type), calls `parent::init_setup_wizard_handler()` `:663` | `woodev/class-plugin.php:440` `protected function init_setup_wizard_handler(): void` | **Fatal at class declaration** ("Declaration … must be compatible … : void"), before anything runs |
| 2 | `RP/includes/admin/setup-wizard.php:11` `extends \Woodev_Plugin_Setup_Wizard`; also `woodev-russian-post.php:665` | No such class in v2 (only `Woodev\Framework\Setup\Setup_Wizard`, `woodev/setup/class-setup-wizard.php`; no `class_alias` anywhere in `woodev/`) | Fatal "Class not found" |
| 3 | `RP/woodev-russian-post.php:70` `parent::__construct( $id, $version, [ 'text_domain', 'supported_features' ] )` | v2 ctor `class-plugin.php:114` takes `text_domain`, `dependencies` only; supported features/download id/blocks come from the **loader definition** (`:1370`, `:1529`, `:1570`) which a v1 registration never creates | No fatal; `get_download_id()` falls back to RP's own override (`:502` → 8772), `get_supported_features()` returns `[]` unless overridden (RP doesn't) — HPOS/blocks declarations from `supported_features` would be lost. Inferred |
| 4 | `Woodev_Helper::get_post()` — `includes/admin/setup-wizard.php:222-224,323` (4 calls) | exists (`class-helper.php:701`) | OK (listed because it is easy to assume removed) |

### 2.2 Still present in v2 with the same global name (no break found)

`Woodev_Plugin` (abstract, `get_file`/`get_plugin_name` still the only abstracts), `Woodev_Plugin_Exception`, `Woodev_API_Exception`, `Woodev_Helper::{str_starts_with,str_ends_with,list_array_items,array_insert_after,get_posted_value,get_requested_value,get_wc_version,get_wc_log_file_url,str_truncate,str_convert,get_post}`, `Woodev_Order_Compatibility::{get_orders_table,get_orders_meta_table,get_order_screen_id,get_order_id_for_order_edit_screen}`, `Woodev_Plugin_Compatibility::is_hpos_enabled`, `Woodev_Lifecycle` (`$upgrade_versions` + `upgrade_to_*` convention intact, `class-lifecycle.php:16,275`), `Woodev_Cacheable_API_Base`/`Woodev_API_Base` (same two abstracts: `get_new_request`, `get_plugin`), `Woodev_API_JSON_Request/Response`, `Woodev_Cacheable_Request_Trait`, `Woodev_Packer` (+ `Single_Box`, `Box_Implementation`, `Item_Implementation`, `Packed_Box`, `Packer_Exception`), `Woodev_Admin_Message_Handler`, `Woodev_Abstract_Shipping_Method` not used by RP (RP has its own `Abstract_Shipping_Method`).
Signature compatibility of RP's other overrides (`get_download_id(): int`, `get_documentation_url(): ?string`, `is_plugin_settings(): bool`, `get_deprecated_hooks(): array`, `log()`, `get_api_log_message(): string`, `init_lifecycle_handler()`) against v2 parents is fine — v2 parents are untyped there. NOT checked: every `Woodev_Packer`/`Woodev_API_Base` protected property RP reads; the v2 `Woodev_Plugin::includes()` is `private` (`class-plugin.php:736`) while RP declares a public `includes()` (`woodev-russian-post.php:117`) — legal PHP, but the v2 ctor then calls its own private one, not RP's.

Conclusion for item 2: a literal "RP on framework v2" fatals on declaration (#1, #2); the removal is by design (ADR-005 clean break), and the loader keeps it from happening.

## 3. Shared runtime collisions (besides the framework)

Moot in practice — both can't run (§1) — but this is the list if both ever did, e.g. via a future shim:

| Surface | RP | CDEK v2 | Collision? |
|---|---|---|---|
| Global functions | `wc_russian_post_*`, `init_wc_russian_post_shipping_init` (`includes/functions.php`, main file) | `woocommerce_edostavka_init`, namespaced `Woodev\Edostavka\…` | No |
| Classes | `Woodev\Russian_Post\…`, `WD_Russian_Post_Shipping` | `Woodev\Edostavka\…` | No |
| WC shipping method ids | `wc_russian_post_courier`, `wc_russian_post_postal` (`shipping-method-*.php:12`); integration id `wc_russian_post` | `edostavka`, `edostavka_courier`, `edostavka_pickup`, `edostavka_postamat` (`CDEK/includes/class-plugin.php:72`) | No |
| Admin page slug | `wc_russian_post_orders` under `woocommerce` (`RP/includes/admin/admin.php:24,121`) | CDEK uses the framework's shipping orders page registry (`woodev/shipping-method/admin/orders/class-orders-registry.php`) | No |
| Settings | `woocommerce_wc_russian_post_settings` on WC **Integrations** tab (`integration.php:17`) | framework settings page | No collision (note: project rule says settings live on woodev-settings; RP is the older WC Integrations style) |
| Script/style handles | `wc-russian-post-*`, `wc_russian_post_map`, `jquery-suggestions` (`checkout.php:42,68`), `woodev-jquery-confirm`, `woodev-suggestions-plugin` | CDEK/framework use `woodev-*` handles (`woodev-instance-field-conditions`, `woodev-settings-page`, `woodev-setup-wizard`…) — no `jquery-suggestions`/`woodev-jquery-confirm`/Yandex-Maps grep hit in CDEK `includes/` | `woodev-jquery-confirm`, `woodev-admin-script` could clash across v1/v2 framework assets (versions differ) — inferred, UNVERIFIED |
| JS globals | `wc_russian_post_checkout_params`, `wc_russian_post_map_params`, `wc_russian_post_address_suggestions_params` | none shared; RP uses `widget.pochta.ru` (`checkout.php:71`), no ymaps | No |
| Checkout fields | RP adds no checkout fields; it hooks `woocommerce_cart_shipping_packages` (`checkout.php:20`), `woocommerce_review_order_after_shipping` button (`:15`) | v2 takes over locality/pickup fields via framework | RP assumes classic checkout (`'blocks' => false`, main file `:73-76`) |
| `woocommerce_cart_shipping_packages` priorities | 20 (RP), 25 (bridge `integration-edostavka.php:22`) | framework hooks | Not measured |
| Cron/AS | `wc_russian_post_orders_update` | own | No |

## 4. The `integration-edostavka.php` bridge when CDEK is v2

The bridge is **dead on v2** even if RP could load:

- Loaded only if `Integrations::__construct` (`RP/includes/classes/integrations.php:22`) sees `is_plugin_active('woocommerce-edostavka.php')` **and** `defined('WC_CDEK_SHIPPING_VERSION')` ≥ 2.2. CDEK v2 defines `WOODEV_EDOSTAVKA_VERSION` and `WC_CDEK_SHIPPING_DEVELOPER_KEY` (`woocommerce-edostavka.php:22-26`) but **no `WC_CDEK_SHIPPING_VERSION`** (grep of CDEK php: none). So `get_edostavka_instance()` returns null and every `wc_russian_post_shipping()->get_integrations_instance()->get_edostavka_instance()` branch takes the "no CDEK" path (`checkout.php:97,214`, `ajax.php:66`, `functions.php:58-81`).
- Even past the gate it calls CDEK-v1-only symbols: `wc_edostavka_shipping()` (`integration-edostavka.php:151`), `wc_edostavka_get_location_postcodes` (`:70`), `wc_edostavka_get_location_cities` / `_get_locale` (`ajax.php:72-82`), `wc_edostavka_set_customer_location` (`checkout.php:118`), `wc_edostavka_has_fee_payments` (`:90`), `get_edostavka_plugin()->get_api()->get_location_cities()`/`->get_customer_handler()` (`:115,130`). None exists in CDEK v2 `includes/`.
- Hooks it sets on CDEK: `wc_edostavka_checkout_hidden_or_remove_state_field/postcode_field` — CDEK v2 lists both as **removed** (`CDEK/includes/class-plugin.php:1229-1230`, replacement `null`).
- Merchant effect of the bridge going quiet (if RP ever ran beside v2): RP would stop prefilling postcode from CDEK's city, would run its own DaData suggestions and its own state/postcode fields (so the shop would get two address UIs), and CDEK's "suggestions are taken over by CDEK plugin" lock on RP settings (`integration-edostavka.php:32-56`) would not apply. Inferred; not reachable because of §1.
- Bug noticed in passing (not related to v2): `set_customer_location` reads `$data['action']` before `$data` exists (`:97-101`) and `$data['city'] || $data['settlement']` yields a bool (`:109`).

## 5. What is already measured / documented

| Source | What it says |
|---|---|
| `docs-internal/FUTURE-BACKLOG.md` B-1 row, OB-1 (`:31`) | B-1 mixed-fleet armor DONE s7 (PR #27); OB-1 text is stale (notice shipped as #104 in 2.0.2) |
| `docs-internal/AGENT-RULES.md:173`, `wiki/architecture.md:14`, ADR-004/005 | `register_plugin()` is a v1 tombstone, clean break, no adapter |
| `docs-internal/gotchas/framework-classmap-autoload-vendored-boot.md` ("B-2") | newer plugin never breaks against older rendezvous winner; older plugin on newer framework is refused via `backwards_compatible` |
| `tests/unit/MixedFleetBootstrapGateTest.php:93` (direction A, v2 stays dormant) and `:339` (direction B, legacy `register_plugin` quarantined, garbage args `:611`), `tests/unit/LoaderDormantNoticeTest.php:48,172` | stubbed-bootstrap unit coverage of both guards and notices |
| `tests/e2e/coexistence/04-framework-versions.spec.js` | rig: two **v2** copies with different framework versions (CDEK + Yandex) — the newer runs, both work. **No real v1 plugin is ever loaded.** |
| `gh issue` #1179 (CLOSED), #1194 (OPEN, research) | #1179: release condition "two v2 plugins compatible with each other" — says nothing about v1 plugins; the only RP line is «по Почте РФ перед переделкой нужно обсудить объём» |
| `docs-internal/CURRENT-STATE.md`, `next-session-prompt.md` | no mention of "v2 CDEK + v1 Russian Post"; no card for it found in `gh issue list --search "почта OR russian OR mixed OR dormant"` |
| Memory `no-shop-runs-v2-yet.md` | nothing on EDD is on v2 yet (CDEK 2.3.0.4 is the first v2 publication, s190) — so the real-shop effect begins only when merchants update |

## 6. Verdict table

Evidence: **READ** = read in code path; **TEST** = also covered by an existing stubbed unit test; **INF** = inferred.

| # | Scenario | What the merchant sees | Evidence |
|---|---|---|---|
| 1 | CDEK v2 + RP v1, CDEK loads first (RP folder sorts after `woocommerce-edostavka`) | Site up. CDEK works. RP **disabled silently on the front**: its methods are not registered, so zone entries for «Почта России» disappear from checkout; no tracking emails/auto-export/webhooks. Admin screens: red notice «Следующие плагины устарели и поэтому не были запущены: Russian Post EMS integration with Woocommerce. Обновите их…» (not shown on AJAX/front) | READ + TEST (`bootstrap.php:126-185`, `MixedFleetBootstrapGateTest:339`) |
| 2 | CDEK v2 + RP v1, RP loads first | Site up. RP works as before. **CDEK 2.3.0.4 does nothing** (no shipping methods, no settings page); red notice «Плагин СДЭК… не запущен: на сайте активен плагин Russian Post… с устаревшей версией, который блокирует его запуск. Обновите Russian Post…» | READ + TEST (`loader.php:74-186`, `MixedFleetBootstrapGateTest:93`, `LoaderDormantNoticeTest:48`) |
| 3 | Merchant follows the notice | There is **no newer RP**: cannot resolve in direction B except by deactivating RP (loses Russian Post) or rolling CDEK back to 2.2.x (v1). In A the same: RP stays off until RP v2 | READ (no RP v2 exists; `FUTURE-BACKLOG`/#1179) |
| 4 | Update CDEK from v1 to 2.3.0.4 in place while RP v1 is active | Request after update behaves as #1 or #2 depending on folder order; no fatal expected from either guard (both explicitly avoid framework classes and untyped variadic signature). WP "plugin update" does not reactivate, so no activation-time checks run | READ; INF for the update flow |
| 5 | CDEK v2 + RP v1 + `WOODEV_FRAMEWORK_DIR` defined (rig/custom) | Bootstrap file is taken from that dir for every plugin → direction decided by that copy's version, not load order | READ (`loader.php:55`); INF effect |
| 6 | Hypothetical: RP forced onto v2 framework (no guard) | Fatal at class declaration (`init_setup_wizard_handler()` missing `: void`) then missing `Woodev_Plugin_Setup_Wizard` | READ (§2.1) |
| 7 | CDEK v2 + RP v1, bridge (`integration-edostavka.php`) | Not loaded in #1/#2 anyway; if it were, it stays off (no `WC_CDEK_SHIPPING_VERSION`, no `wc_edostavka_*` functions) | READ |
| 8 | CDEK v1 (2.2.x) + RP v1 (status quo) | Works today: both on framework v1, highest declared version wins (`bootstrap.php:78-90`); bridge active (`WC_CDEK_SHIPPING_VERSION ≥ 2.2`) | READ; the "works" part is operator-reported, not re-measured |

## 7. Questions a live measurement would settle (steps only — nothing run)

1. **RP's real folder name on shops** → check the EDD zip / a shop's `wp plugin list` (UNVERIFIED now). It decides direction A vs B for most merchants.
2. **Real v1+v2 behaviour** (rig, throw-away site, plugins copied inside the container only, as `04-framework-versions.spec.js` does): (a) activate `woocommerce-edostavka` 2.3.0.4 and the RP zip under its real folder name; `wp eval 'echo get_class(Woodev_Plugin_Bootstrap::instance()), Woodev_Plugin::VERSION ?? "";'` and note which plugin's `register_*` ran; (b) `curl` the admin dashboard and capture the notice text; (c) `curl` the shop checkout with a «Почта» zone instance and record which rates appear; (d) repeat with RP folder renamed to `0-russian-post` (forces direction B); (e) run WP-CLI `plugin update`-style swap CDEK 2.2.x → 2.3.0.4 with RP active and watch `debug.log` for fatals. Expected from this analysis: no fatals, outcomes #1 and #2 above.
3. **Does a shop show the notice to the right people** (`manage_options` only? `loader.php`/`bootstrap.php` echo without a capability check, only `is_admin()`), and how long a merchant stays unaware in direction A (front-end silent).

## 8. Skipped / not checked

- RP folder slug on real shops (UNVERIFIED); WP's `active_plugins` sort behaviour taken from WP core knowledge, not re-read in this session.
- Not run: any PHP, test, or rig. Existing unit tests were read, not executed.
- Did not read every RP caller of v1 internals (`Woodev_Packer` properties, `Woodev_API_Base` protected members); §2 lists the certain breaks only.
- Did not check RP webhooks (`includes/classes/webhooks.php`) beyond noting they stop with the plugin; whether Pochta's callbacks start returning 404 is INF.
- No external URLs were consulted.

## Related

- [README.md](README.md) — consolidated fact base and the resolved contradictions.
- [brainstorm-agenda.ru.md](brainstorm-agenda.ru.md) — the operator's brainstorm agenda built on these reports.
