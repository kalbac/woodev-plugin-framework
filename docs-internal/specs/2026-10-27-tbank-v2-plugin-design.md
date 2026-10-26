# T-Bank «Рассрочки и кредиты» v2 Plugin Design

**Date:** 2026-10-27 (s199, overnight)  
**Target:** release `2.0.0` of the existing plugin `woodev-vkredit` («Рассрочки и кредиты от Т-Банк», EDD product
`tinkoff-kupi-v-kredit`, download id `1849`, plugin/gateway id `wc_tinkoff_kvk`), written from scratch on Framework v2 in the
private repo `kalbac/woodev-vkredit`. The first payment plugin on the v2 framework.  
**Why now:** framework #1389 (ticket 17079) — a shop with T-Bank v1 cannot move to the v2 carriers, because v1 and v2 Woodev
plugins cannot run together and T-Bank has no v2 to update to. Every week without 2.0.0 keeps those shops on v1 carriers.  
**Evidence:** `v1:` means `/Users/maksimmartirosov/Projects/woodev-vkredit` at commit `533f5e3` (v1 1.7.0 as shipped; the same
tree is on branch `v1`). `v1fw:` is the old framework 1.3.3 bundled in that tree (`woodev/`). Framework paths are relative to
this repo. Bank facts cite the official docs at `https://forma.tbank.ru/docs/online/<page>`, read in
[d-docs.md](../research/2026-10-26-tbank-v2/d-docs.md) and **re-read for this spec on 26.10.2026 ~04:55 MSK** (pages
`settings/app-notifications`, `setup-types/api/methods`, `setup-types/api/testing-api`, `setup-types/api/auth`,
`settings/return-links`, `settings/auto-commit`). Labels: **READ** (code or official page), **MEASURED** (a probe was run),
**UNVERIFIED** (not established, reason given), **Provisional (coordinator may revisit)** — a gap in the inputs that this spec
had to close overnight without the operator; every one is listed again in §22.2.

## 0. How to read this spec

§1 lists the binding decisions; nothing below reopens them. §2 is the v1 inventory, §3 the installed-site contract (the part
that must not break), §4 the framework seam map. §§5–14 are the design per area, §15 the settings table, §16 every v1 defect
with its fix and its test, §§17–19 security, i18n and the worst-case traffic, §20 testing, §21 out of scope, §22 the registers
(UNVERIFIED, Provisional, operator items), §23 the implementation plan in waves.

**Three source notes the reader must know.**

1. **The API password is absent today** (decisions → Overnight scope). By the docs, `Create` and `CreateDemo` need no password;
   `Info`, `Commit`, `Cancel` need HTTP Basic `showcaseId:password` (`setup-types/api/auth`, READ). Every feature therefore has
   a with-password and a without-password behaviour, stated where it applies and collected in §6.6.
2. **The webhook retry schedule is not documented.** The webhook page says only «Ответ формата 2ХХ расценивается как удачно
   полученный вебхук. Если придет другой ответ — отправим вебхук повторно» (`settings/app-notifications` § Ответ, READ again
   26.10.2026). No count, interval or timeout exists in the docs; D12(Q4) expected one — it is not there. The design therefore
   treats a non-2xx answer as "the bank will try again at some unknown time" and never depends on a second delivery.
3. **The docs' own webhook example is invalid JSON** (a missing comma after `expected_overdue_at`, READ). Test fixtures are
   built from it with that comma restored and the defect noted in the fixture file.

## 1. Binding decisions

Source: [decisions.md](../research/2026-10-26-tbank-v2/decisions.md), walked with the operator on 26.10.2026 (s198). Where
any report disagrees, that file wins.

| ID | Decision (short) | Where it lands |
|---|---|---|
| D1 | Same EDD product, new private repo `kalbac/woodev-vkredit`, folder `woodev-vkredit`, main file `woodev-vkredit.php`, version 2.0.0 | §3.1, §23 W0 |
| D2 | Installed data unchanged: gateway id, settings key, order meta prefix, webhook URL format; no migration. The two `geteway` hooks are renamed WITHOUT aliases and announced; the five correctly named hooks stay | §3 |
| D3 | Classic checkout only in 2.0.0; the Blocks payment bridge (#1476) is the next task after release | §21 |
| D4 | Product-page informer stays; divisor 19 becomes a setting (default 19), filter kept, invalid divisor never divides, output escaped; promo codes as v1 + escaping + `default` fallback | §8.3, §8.4 |
| D5 | No automatic Cancel/Commit/refund calls; order page and gateway description explain refunding from the bank cabinet; cancellation history to be recovered from tickets before any cancel behaviour | §12.1, §22.3 |
| D6 | «Долями», T-Kassa, bank transfer out of 2.0.0 (#1474, #1475) | §21 |
| D7 | Settings stay on the WooCommerce gateway screen; no `woodev-settings` tab; the API password becomes a password-type field | §5, §15 |
| D8 | Licence not in force → gateway not offered, no NEW bank action; callbacks, viewing and settings always work — the carriers' `License_Gate` contract | §13 |
| D9 | Three-step wizard on `woodev/setup/`: ids + password; demo connection check on a button; live-mode checklist. Bank called only on a button | §14 |
| D10 | Payment module not trimmed; #639 stays frozen | §4 |
| D11 | Fix every v1 defect; window 3 000–500 000 ₽, «Принимать первоначальный взнос» raises the ceiling to 1 000 000 ₽; RUB only | §8.1, §16 |
| D12 | Bank cabinet takes the domain without a trailing slash in «Адрес сайта», «Success URL», «Fail URL»; old `forma.tinkoff.ru` host still works; item sum must equal the order sum to the kopeck BY CONSTRUCTION; design must not depend on auto-confirm | §6.1, §7, §14 |
| D13 | 2.0.0 is not a bare port; improvements walked with the operator | I1–I14 below |
| D14 | Webhooks are tested by SIMULATING the bank's requests from the docs | §20.2 |
| I1 | Kopeck-exact item list with clean lines | §7 |
| I2 | Calm generic buyer text; merchant reason + fix in the debug log and the wizard check only | §6.4 |
| I3 | Order panel «Заявка в Т-Банке» (manage_woocommerce), additive meta under `_wc_wc_tinkoff_kvk_` | §12.1 |
| I4 | «Обновить статус»: one `Info` per press, ≤ 1 / 30 s / order, nonce, licence-independent, no polling | §12.2 |
| I5 | Late `signed` on a cancelled order → `on-hold` + note + admin e-mail | §10.4 |
| I5a | Two settings + the `woocommerce_cancel_unpaid_order` veto with a limit (default 72 h) | §11 |
| I6 | Thank-you / «Мои заказы» state text, «Вернуться к заявке» (≤ 1/min, ≤ 10/order), customer note on `approved`, one safety `Info` on return (≤ 1 / 5 min, ≤ 10/order) | §12.3 |
| I7 | Bank refusal → `failed` | §10.3 |
| I8 | «The bank issues the fiscal receipt» hint in wizard, panel, docs | §12.4 |
| I9 | Per-programme min/max window on top of the global one | §8.2 |
| I10 | Short checkout text | §8.5 |
| I11 | Orders-list column — after 2.0.0 | §21 |
| I12–I14 | Not taken | §21 |

**Operator rules that bind every section** (project and memory rules): the checkout is fool-proof and the admin trusts the
merchant; merchant diagnostics only behind debug logging («Логирование» = the gateway's «Режим отладки» → «Сохранять в
журнал»); no automatic call to any server without a cap and a stated worst case (§19); no jargon in merchant or buyer copy
(never «чекаут», «фреймворк» — `AGENT-RULES.md` Rule 10c); RUB only; the licence gate is the default (D8).

## 2. v1 (1.7.0) inventory

Condensed from [a-v1-inventory.md](../research/2026-10-26-tbank-v2/a-v1-inventory.md) and re-read at `533f5e3` for this spec.

| # | Surface | v1 behaviour (file:line) | Fate in 2.0.0 |
|---:|---|---|---|
| 1 | Bootstrap | `Woodev_Plugin_Bootstrap::register_plugin( '1.3.3', … 'is_payment_gateway' => true, 'backwards_compatible' => '1.2.1' )` (`v1:woodev-vkredit.php:24-33`); class `Woodev_TCB extends Woodev_Payment_Gateway_Plugin` (`:44`), ids (`:53-59`), `currencies EUR, RUB, USD` (`:78`), download id 1849 (`:238-240`) | Rebuilt on `Woodev_Loader::register()`, §5.1 |
| 2 | Gateway | `WC_Gateway_Tinkoff_KVK extends Woodev_Payment_Gateway_Hosted` (`v1:includes/class-gateway.php:7`); features PRODUCTS + DETAILED_CUSTOMER_DECLINE_MESSAGES, type LOANS, two environments, `countries RU`, `currencies EUR, RUB, USD` (`:26-45`) | Same type and features; RUB only, §5.2 |
| 3 | Webhook route | Production: `wc-api=wc_gateway_tinkoff_kvk_ipn_<md5(json_encode([shop_id, showcase_id]))>` on `home_url('/')` (`:47-72`); test mode: the Hosted base's class-name URL `?wc-api=WC_Gateway_Tinkoff_KVK` (`v1fw:payment-gateway/class-payment-gateway-hosted.php:780-794`), so demo applications reported to a different address than the one in the cabinet; body from `php://input` (`:355-362`) | The md5 URL in both environments, §10.1 |
| 4 | Order on-hold payable | `woocommerce_valid_order_statuses_for_payment` adds `on-hold` for this gateway (`:74,89-95`) | Kept, §10.3 |
| 5 | Checkout fields | Promo radio/hidden input, raw promo name in HTML (`:98-143`); demo selectors for `manage_woocommerce` in test mode (`:145-178`) | Escaped, whitelisted, §8.2, §9.4 |
| 6 | Availability | Hidden on a dead `woocommerce_update_order_review` branch, below 3 000 ₽, then filter, then licence (`:181-200`) | Window + programmes + licence, §8.1 |
| 7 | Settings | `shop_id`, `showcase_id`, `password` (plain `text`), `enable_cooling`, `order_button_text`, `promo_codes`, `single_page_informer` (`:202-258`) + framework fields (`v1fw:payment-gateway/class-payment-gateway.php:845-940`) | All keys kept; password field masked; five keys added, §15 |
| 8 | Create | `POST https://forma.tinkoff.ru/api/partners/v2/orders/create` or `/create-demo` (`v1:includes/api/class-wc-tinkoff-api.php:18`, `v1:includes/api/class-wc-api-request.php:7-18`); body (`class-wc-tinkoff-api.php:34-139`); header and body `integrationType: woodev` (`:14,41`) | Rebuilt, §6, §7, §9 |
| 9 | Info | `GET …/{orderNumber}/info`, Basic `showcaseId` or `demo-showcaseId` : password (`:146-161`) | Kept, §6 |
| 10 | Webhook handling | `wc_get_order( $payload->id )` (`v1:includes/api/class-wc-api-ipn-response.php:48-65`); status from `Info` (`:150-170`); required fields + amount (`:268-300`); raw body to the log (`:302-308`) | Rebuilt, §10 |
| 11 | Order card + e-mails | Seven display keys through `wc_tinkoff_order_fields_for_display_data` (`v1:woodev-vkredit.php:118-160`), table on the order page for logged-in buyers (`:162-186`), customer e-mails (`:188-217`) | Kept and finally written, §12 |
| 12 | Product informer | `woocommerce_single_product_summary` p.25, `new WC_Gateway_Tinkoff_KVK()` per render, `floor( price / 19 )`, unescaped (`v1:includes/product.php:7-28`); unhooked button (`:11,30-39`) | Rebuilt, §8.3 |
| 13 | Promo table UI | Inline jQuery with `jQuery(window).load` and `.size()` (`v1:includes/html-promo-codes.php:74,78`) — both removed in jQuery 3, work only through jQuery Migrate | Rewritten, §15 |
| 14 | Lifecycle | `upgrade_to_1_4_0` moves the legacy licence key (`v1:includes/class-lifecycle.php:11-28`) | Ported, §5.4 |
| 15 | Blocks | None (`v1:woodev-vkredit.php:71-79`) | None in 2.0.0 (D3) |

**MEASURED at `533f5e3` (grep of `update_order_meta` in `v1:` and `v1fw:`):** none of the seven display keys
(`status_message`, `first_payment`, `credit_amount`, `credit_product`, `credit_term`, `monthly_payment`, `signing_type`) is ever
written as order meta. The README's "two have a writer" refers to `$order->payment->credit_amount` / `first_payment`
(`v1fw:payment-gateway/class-payment-gateway-hosted.php:443-445`) — in-memory properties that `add_transaction_data()`
(`v1fw:payment-gateway/class-payment-gateway.php:2020-2108`) never persists. So the v1 order card was empty on every order.
What v1 does persist: `trans_id` (= `loan_number`), `trans_date`, `environment`, `promo_code`, `retry_count`, and `_transaction_id`.

## 3. Installed-site compatibility contract (D2)

Everything here is pinned by contract tests in wave W0 (§23). Changing any row orphans data or breaks EDD updates on every
installed shop.

### 3.1 Identity

| Contract | Value | Evidence |
|---|---|---|
| Plugin folder / main file | `woodev-vkredit` / `woodev-vkredit.php` | D1 |
| Plugin id = gateway id | `wc_tinkoff_kvk` | `v1:woodev-vkredit.php:53,59` |
| EDD download id | `1849` | `v1:woodev-vkredit.php:238-240` |
| Framework option prefix (licence, version, lifecycle) | `woodev_wc_tinkoff_kvk_` (derived from the plugin id, so unchanged) | `v1fw:class-plugin.php` option-name helper; a-v1-inventory §2 |
| Legacy licence key (pre-1.4.0 sites) | `kupi_v_kredit_ot_tinkoff_license_key`, `kupi_v_kredit_ot_tinkoff_license` | `v1:includes/class-lifecycle.php:18-27` |
| Stored payment method on orders | `wc_tinkoff_kvk` | `v1:includes/class-gateway.php:27` |

### 3.2 Settings option `woocommerce_wc_tinkoff_kvk_settings`

Every v1 key keeps its name, type and stored value format. New keys are additive (absent = default).

| Key | Owner | v1 evidence | 2.0.0 |
|---|---|---|---|
| `enabled`, `title`, `description` | framework | `v1fw:…/class-payment-gateway.php:850-869` | kept (framework, same keys) |
| `enable_customer_decline_messages` | framework (feature) | `:895-901` | kept |
| `debug_mode` (`off`/`checkout`/`log`/`both`) | framework | `:904-918` | kept |
| `environment` (`production`/`test`) | framework | `:971-978`; `v1:includes/class-gateway.php:37-40` | kept |
| `shop_id`, `showcase_id` | plugin | `v1:includes/class-gateway.php:205-222` | kept |
| `password` | plugin | `:223-227` (`text`) | kept key, masked field (§15) |
| `enable_cooling` (`yes`/`no`) | plugin | `:228-234` | kept, same meaning (§10.3) |
| `order_button_text` | plugin | `:235-241` | kept |
| `promo_codes` — list of `{name, code, enabled}` | plugin | `:242-248,267-293` | kept; rows gain optional `min_amount`, `max_amount` (I9) |
| `single_page_informer` | plugin | `:249-254` | kept |
| new: `accept_down_payment`, `informer_divisor`, `prevent_auto_cancel`, `auto_cancel_hold_hours` | plugin | — | added (§15) |

### 3.3 Order meta (prefix `_wc_wc_tinkoff_kvk_`, from `get_order_meta_prefix()` = `'_wc_' . id . '_'`)

| Key | Written by v1? | 2.0.0 |
|---|---|---|
| `trans_id` (loan number), `trans_date`, `environment` | yes (framework `add_transaction_data`) | written the same way |
| `promo_code` | yes (`v1:includes/api/class-wc-tinkoff-api.php:59-62`) | written, value now whitelisted |
| `retry_count` | yes (framework `get_order_with_unique_transaction_ref`) | left alone, not used (§9.2) |
| `status_message`, `first_payment`, `credit_amount`, `credit_product`, `credit_term`, `monthly_payment`, `signing_type` | **never** (§2) | **now written** from `Info`/webhook data, same keys, same value meaning (`credit_product` = bank `product`, `credit_term` = `term`) |
| `_transaction_id` (WC core) | yes | same |
| new, additive: `status`, `committed`, `chosen_bank`, `transfer_amount`, `cooldown_until`, `expected_overdue_at`, `application_id`, `application_link`, `order_number` (one row per number sent, non-unique), `current_order_number`, `create_fingerprint`, `status_signature`, `info_checked_at`, `data_source` (`info`/`webhook`), `resume_count`, `return_info_count`, `late_signed_notified` | — | §9, §10, §12 |

The order lookup for old (v1-created) orders has no `order_number` row; §10.2 covers it.

### 3.4 Webhook URL and routes

| Route | v1 | 2.0.0 |
|---|---|---|
| `{home_url('/')}?wc-api=wc_gateway_tinkoff_kvk_ipn_{md5(json_encode([shop_id, showcase_id]))}` — sent per order as `webhookURL` and pasted by the merchant into the cabinet | production only (`v1:includes/class-gateway.php:47-72`) | **byte-identical format**, registered and sent in both environments (fixes D11 «callback registered in demo mode too»); the endpoints of up to five previous id pairs stay registered (§10.1) |
| `?wc-api=wc_gateway_tinkoff_kvk` (the Hosted base's class-name route, `woodev/payment-gateway/class-payment-gateway-hosted.php:37-58`) | test mode: the `webhookURL` of demo applications; production: removed only when the request spells the class name in its exact case (`v1fw:payment-gateway/class-payment-gateway-hosted.php:802-804`), but WooCommerce lowercases `wc-api`, so `?wc-api=wc_gateway_tinkoff_kvk` still reached the IPN handler — a second way in | **removed** (listed in §3.6); a demo application created by v1 before the update loses its callback (demo only, harmless) |
| `woocommerce_api_wc_tinkoff_kvk_process_payment`, `woocommerce_receipt_wc_tinkoff_kvk` (inherited hosted handlers) | inherited | not used by a redirect gateway (`use_form_post() = false`); not registered by the plugin |

A test computes the URL for fixed ids and compares it with the v1 formula character by character (W0).

### 3.5 Public hooks

| Hook (v1 file:line) | Kind | 2.0.0 |
|---|---|---|
| `wc_tinkoff_order_fields_for_display_data` (`v1:woodev-vkredit.php:119`) | filter, `array key => label` | **kept**; keys unchanged; default labels become translatable (§18) |
| `wc_tinkoff_api_create_request_params` (`v1:includes/api/class-wc-tinkoff-api.php:139`) | filter `( $params, $order, $gateway )` | **kept**, same arguments; the item-sum check runs AFTER it (§7.4) |
| `wc_tinkoff_ipn_require_response_data` (`v1:includes/api/class-wc-api-ipn-response.php:271`) | filter, list of required webhook fields | **kept**, applied to the webhook body before `Info` |
| `wc_gateway_wc_tinkoff_kvk_is_available` (`v1:includes/class-gateway.php:193`) | filter `( bool )` | **kept**, same position: after the amount window, before the licence check (the framework's own `is_available()` fires it once more inside `parent::is_available()`, `woodev/payment-gateway/class-payment-gateway.php:1226-1266` — as in v1) |
| `wc_payment_gateway_wc_tinkoff_kvk_form_fields` (`v1:includes/class-gateway.php:257`) | filter on the plugin's fields; the framework applies the same name to the full field list (`woodev/payment-gateway/class-payment-gateway.php:968`) | **kept**, both applications as in v1 |
| `wc_geteway_tinkoff_kvk_loans_rate` (`v1:includes/product.php:23`) | filter, divisor | **renamed** → `wc_gateway_tinkoff_kvk_loans_rate`, no alias (D2) |
| `wc_geteway_tinkoff_kvk_product_info` (`v1:includes/product.php:26`) | filter `( $content, $product )` | **renamed** → `wc_gateway_tinkoff_kvk_product_info`, no alias (D2) |

### 3.6 The removed / changed list for the release notes (as for CDEK, Russian Post, Yandex)

- **Renamed hooks:** `wc_geteway_tinkoff_kvk_loans_rate` → `wc_gateway_tinkoff_kvk_loans_rate`;
  `wc_geteway_tinkoff_kvk_product_info` → `wc_gateway_tinkoff_kvk_product_info`.
- **Removed PHP symbols** (custom code calling them breaks; clean-break policy ADR-005): `wc_tinkoff_kredit()`,
  `init_woocommerce_gateway_tinkoff_kredit()`, classes `Woodev_TCB`, `Woodev_TCB_Lifecycle`, `WC_Gateway_Tinkoff_KVK`,
  `WC_Gateway_Tinkoff_KVK_Product`, `WC_Tinkoff_API`, `WC_Tinkoff_API_Request`, `WC_Tinkoff_API_Response`,
  `WC_Gateway_Tinkoff_KVK_API_IPN_Response`; constants `WC_TINKOFF_KVK_GATEWAY_VERSION`, `WC_TINKOFF_KVK_GATEWAY_FILE`.
  **Provisional (coordinator may revisit):** no function alias for `wc_tinkoff_kredit()`, matching the carriers.
- **Removed route:** `?wc-api=wc_gateway_tinkoff_kvk`.
- **Changed behaviour of kept hooks:** `wc_tinkoff_ipn_require_response_data` now runs before the `Info` call;
  the inherited `wc_payment_gateway_wc_tinkoff_kvk_get_order` filter no longer fires on webhooks (the handler does not call the
  framework's `get_order()`, which writes `retry_count` on every call — gotcha
  [a-hosted-gateway-s-default-response-flow-fails-orders-it-does-not-understand](../gotchas/a-hosted-gateway-s-default-response-flow-fails-orders-it-does-not-understand.md)).
- **Changed order behaviour** (merchant-visible, for the notes): bank refusal → «Не удался» instead of «Отменён» (I7); an
  approved-but-unsigned application keeps the order «Ожидает оплаты» instead of «На удержании» (§10.3, Provisional); a late
  signature on a cancelled order → «На удержании» + e-mail (I5); the gateway is offered only for RUB and only in the amount
  window (D11).

## 4. Capability → framework seam map

| Capability | Framework seam (file) | Plugin work | Gap? |
|---|---|---|---|
| Bootstrap | `Woodev_Loader::register()` + literal definition (`woodev/loader.php`); class map autoload of every payment class (`woodev/class-map.php:271-291`); model `tests/_fixtures/woodev-realistic-payment-plugin/woodev-realistic-payment-plugin.php` | Main file, `Plugin` class | No |
| Gateway registration | `Woodev_Payment_Gateway_Plugin::__construct( $id, $version, $args )` with `gateways`, `currencies` (`woodev/payment-gateway/class-payment-gateway-plugin.php:64-110`) | One gateway | No |
| Hosted redirect | `Woodev_Payment_Gateway_Hosted::process_payment()` → `get_payment_url()` → abstract `get_hosted_pay_page_url()` (`class-payment-gateway-hosted.php:78-136,357`) | `Create` returns `link` | No |
| Notification handling | `handle_transaction_response_request()` (`:365-407`) and its default flow (`:496-552`) | **Overridden entirely** (§10): the default flow fails a pending order on any exception and on any status it does not recognise, and calls `get_order()` | No (plugin) — gotcha filed |
| Status transitions | `complete_payment()` (`class-payment-gateway.php:1439-1476`), `mark_order_as_held/failed/cancelled()` (`:2329,2447,2480`), `add_transaction_data()` | Used from the plugin's handler | No |
| Settings fields | `init_form_fields()` + `get_method_form_fields()` (`class-payment-gateway.php:867-968`) | Fields of §15 | No |
| Debug / log | `debug_log()`, `add_debug_message()` with redaction at the sink (`:3435-3486`) | Merchant reasons go through it | No |
| API base | `Woodev_API_Base`: Basic auth (`woodev/api/class-api-base.php:2883`), `get_secret_header_names()` (`:2654`, masks `Authorization`), `get_secret_param_names()` (`:1501`), body redaction by key at any depth (`:2473`, `:2815`), timeouts by purpose (`:2359`) | Add PII keys to the secret list; own timeouts | No |
| Licence | `Woodev\Framework\Shipping\License_Gate::is_in_force()`, `refusal()`, `request_claim_refresh()` (`woodev/shipping-method/class-license-gate.php`), autoloadable by any plugin through the class map (`woodev/class-map.php:94`) | Call it from the gateway | **Small — F1:** the class lives in the Shipping namespace; lift to a neutral namespace with an alias (not blocking) |
| Setup wizard | `Woocommerce_Setup_Wizard`, `register_step()` / `register_content_step()`, `Step_Action`, `Action_Outcome` (`woodev/setup/`); a settings step resolves its fields through `$plugin->get_settings_handler()` (`class-setup-wizard.php:643-700,771-779`) | A `Woodev_Abstract_Settings` subclass mapped onto the gateway option (§14.1) | No (plugin) |
| Blocks payment bridge | none | — | **G1 (#1476)**, after 2.0.0 (D3) |
| `woodev-settings` page | `Settings_Page_Registry` (providers default none, `woodev/class-plugin.php:1635-1650`) | none (D7) | Not needed |

## 5. Architecture

### 5.1 Entry point and loader definition

`woodev-vkredit.php` follows the Russian Post entry (`/Users/maksimmartirosov/Projects/woodev-russian-post/woodev-russian-post.php`):
header `Version: 2.0.0`, `Requires PHP: 7.4`, `Requires Plugins: woocommerce`, `Text Domain: woodev-vkredit`,
`Domain Path: /languages`; constants `WOODEV_VKREDIT_FILE`, `WOODEV_VKREDIT_VERSION`; `require_once __DIR__ . '/woodev/loader.php'`;
`Woodev_Loader::register()` with **literals only** (gotcha
[a-loader-definition-cannot-use-a-framework-class-constant](../gotchas/a-loader-definition-cannot-use-a-framework-class-constant.md)):
`plugin_id 'wc_tinkoff_kvk'`, `download_id 1849`, `plugin_name 'Рассрочки и кредиты от Т-Банк'`, `plugin_version '2.0.0'`,
`framework_version '2.0.14'` (moved together with CI's `WOODEV_FRAMEWORK_REF`), `backwards_compatible '2.0.0'`,
`platform 'woocommerce'`, requirements `php 7.4 / wordpress 6.4 / woocommerce 8.5.1` (the carriers' floor), `main_class
'Woodev\\Vkredit\\Plugin'`, `callback 'woodev_vkredit_init'`, `supported_features ['hpos' => true]`, no `type` (the only type
constant is `shipping`, `woodev/class-framework-plugin-loader-definition.php:26`). The GlitchTip DSN filter of the carriers is
**not** added in 2.0.0 (no project exists for this plugin; an operator item, §22.3).

### 5.2 Classes (namespace `Woodev\Vkredit`, PSR-4-style include autoloader as on Russian Post)

| Class (file under `includes/`) | Responsibility |
|---|---|
| `Plugin` (`class-plugin.php`) extends `\Woodev_Payment_Gateway_Plugin` | ids, `gateways => [ 'wc_tinkoff_kvk' => Gateway::class ]`, `currencies => ['RUB']`, download id, URLs (sales/support/settings as v1 `v1:woodev-vkredit.php:260-310`), lifecycle, wizard, settings handler for the wizard, hooks of the informer / order display |
| `Gateway` (`class-gateway.php`) extends `\Woodev_Payment_Gateway_Hosted` | settings fields, availability, checkout fields, `get_hosted_pay_page_url()`, `handle_transaction_response_request()` delegating to `Webhook\Handler`, webhook route registration, `payment_statuses` filter |
| `Api\Client` (`api/class-client.php`) extends `\Woodev_API_Base` | `create()`, `create_demo()`, `info()`; host; auth; timeouts; secret/PII names; error parsing (§6) |
| `Api\Request`, `Api\Response`, `Api\Application_State` | request serialisation with fixed float precision (§7.5); response DTOs; a typed view of an Info/webhook body |
| `Api\Error_Explainer` | bank error → merchant reason + fix (I2), §6.4 |
| `Order\Item_List_Builder` | the kopeck reconciliation (§7) |
| `Order\Order_Numbers` | the orderNumber sent per attempt and the lookup back (§9.2, §10.2) |
| `Order\Application_Meta` | read/write of every `_wc_wc_tinkoff_kvk_*` key (one place; contract-tested) |
| `Order\Status_Mapper` | bank state → WooCommerce action (§10.3) |
| `Order\Auto_Cancel_Veto` | the I5a filter (§11) |
| `Webhook\Handler` | verification, throttle, idempotency, dispatch (§10) |
| `Admin\Order_Panel` | the panel and «Обновить статус» (§12.1–12.2) |
| `Frontend\Order_Status_Notice`, `Frontend\Resume_Endpoint` | thank-you / «Мои заказы» state text, «Вернуться к заявке», safety Info (§12.3) |
| `Frontend\Product_Informer` | the product-page text (§8.3) |
| `Admin\Promo_Codes_Field` | the programmes table (no inline jQuery 1.x calls) |
| `Setup\Setup_Wizard` extends `Woocommerce_Setup_Wizard`, `Setup\Wizard_Settings` extends `\Woodev_Abstract_Settings` | §14 |
| `Lifecycle` extends `\Woodev_Lifecycle` | 1.4.0 licence carry, 2.0.0 upgrade marker (§5.4) |

### 5.3 v1 → v2 bootstrap coexistence (c-framework-map §4, #1389)

The first vendored `Woodev_Plugin_Bootstrap` that PHP loads wins (`class_exists` guard). WordPress loads active plugins in the
order of the `active_plugins` option, which is kept sorted by path, and `woodev-vkredit/…` sorts after
`woocommerce-edostavka/…`, `woocommerce-yandex-delivery/…` and `woodev-russian-post/…`. Consequences, for the release notes
and the support team:

| Shop has | After updating T-Bank to 2.0.0 |
|---|---|
| T-Bank only, or T-Bank + any v2 carrier | Works (all v2) |
| T-Bank 2.0.0 + any v1 Woodev plugin that sorts before it (e.g. Russian Post v1) | The v1 bootstrap wins; **T-Bank 2.0.0 stays dormant** and shows the framework's dormant notice (text fixed in #1473: it sends the merchant to «Woodev → Плагины»); the gateway disappears from checkout; **bank webhooks get HTTP 400** from WooCommerce (no `woocommerce_api_*` handler is registered), so the bank keeps retrying (§0 note 2) and the I4 button recovers the state once the shop runs v2 |
| T-Bank 2.0.0 + a v2 carrier + a v1 plugin | The v2 carrier sorts first → v2 wins → the v1 plugin is quarantined (tombstone), T-Bank 2.0.0 works |

Nothing in the plugin can change this; the merchant updates or disables the other v1 plugin. The rig acceptance runs the three
rows once (§20.4).

### 5.4 Lifecycle

- `upgrade_to_1_4_0` is ported verbatim in behaviour (a shop still below 1.4.0 that jumps to 2.0.0 would otherwise lose its
  licence key and, with D8, its checkout). **Provisional (coordinator may revisit):** keep it; ten lines.
- `upgrade_to_2_0_0`: no data change (D2). It records nothing but the framework's own version option. It does **not** start
  the wizard for a shop whose `shop_id` and `showcase_id` are set (§14.3).
- No `uninstall.php` (the tooling AGENTS.md: installed data survives).

## 6. Bank API client

### 6.1 Host — the old host stays the default

| Fact | Source |
|---|---|
| Both `https://forma.tinkoff.ru/api/partners/v2/orders/x/info` and `https://forma.tbank.ru/…` answered **401 without credentials**, no redirect | **MEASURED** 26.10.2026 ~04:55 MSK (an unauthenticated GET for a non-existent order; no application created) |
| `forma.tinkoff.ru` presents a certificate issued by «Russian Trusted Sub CA» (subject `*.tinkoff.ru`, valid to 22.06.2027); `curl` on this Mac fails verification (`ssl_verify_result=19`) | **MEASURED** the same minute |
| `forma.tbank.ru` timed out on three later attempts (30–40 s) the same night; its chain could not be read | **MEASURED** (flapping, as d-docs §0 and b-api-market recorded) |
| v1 has called `forma.tinkoff.ru` with `sslverify = true` (`v1fw:api/class-api-base.php:250`) on 800+ shops for years | READ + operator (D12 Q1/Q6) |
| The docs use `forma.tbank.ru` in every example | READ |

**Decision — Provisional (coordinator may revisit):** 2.0.0 calls `https://forma.tinkoff.ru/api/partners/v2/orders` (one class
constant), because it is the host proven on merchants' servers by v1, and because the only measurement of the TLS chain says
a client without the Russian Trusted Root CA cannot verify `forma.tinkoff.ru` from this network — which v1's field record
contradicts, so the chain a hosting server sees is evidently not the one this Mac sees (UNVERIFIED U2, card R2). Switching to
`forma.tbank.ru` is a one-constant change after R2 measures both hosts from a production hosting server. `sslverify` is never
turned off; a TLS failure (cURL error 60/77) is explained to the merchant (§6.4).

### 6.2 Endpoints and auth

| Call | HTTP | Path | Auth | Used by |
|---|---|---|---|---|
| `create` | POST | `/create` | none (ids in the body) | checkout, «Вернуться к заявке» (production) |
| `create_demo` | POST | `/create-demo` | none | checkout and resume in test mode; the wizard check |
| `info` | GET | `/{orderNumber}/info` (path segment `rawurlencode`d) | Basic `showcaseId:password` (production) or `demo-showcaseId:password` (test-mode orders) | webhook verification, I4, I6 safety check, wizard password check |
| `commit`, `cancel` | — | — | — | **not implemented** (D5) |

Rules: `Content-Type` and `Accept` `application/json`; header and body field `integrationType: woodev` kept from v1
(`v1:includes/api/class-wc-tinkoff-api.php:14,41`; undocumented, UNVERIFIED whether the bank uses it — harmless). The environment
of an Info call is the environment **stored on the order** (`environment` meta), not the current setting, so a merchant who
switches to live mode can still refresh a demo order and vice versa.

### 6.3 Timeouts and rate limits

- Purpose-based timeouts (`get_request_timeout()` override): `create` / `create_demo` **20 s** (the buyer waits; the framework's
  60 s default for payment APIs is too long), `info` **10 s**. **Provisional (coordinator may revisit).**
- HTTP 429 with `Retry-After` (≤ 25 req/s, `setup-types/api/methods`, READ): never retried inline. At checkout → the buyer's
  generic text; in a webhook → HTTP 503 so the bank re-delivers; in the admin → «Банк просит повторить позже (через N с)».

### 6.4 Error mapping (I2)

The buyer always sees one calm text (storefront msgid, English, translated): *"We could not start your T-Bank application.
Please try again a little later or choose another payment method."* — never the bank's text (v1 threw the bank's message and
its own HTML link to the settings at the buyer: `v1:includes/class-gateway.php:341-348`,
`v1:includes/api/class-wc-api-response.php:66-72`). The merchant's explanation is written to the debug log only (gateway debug
mode `log`/`both`) and is shown by the wizard check:

| Bank answer (matched by HTTP code, then by substring of the body's error text; unknown → shown as is, masked) | Merchant reason and fix (Russian, admin) |
|---|---|
| «не найдены настройки для комбинации shopId и showcaseId» | Проверьте идентификатор магазина и витрины в кабинете банка |
| «не найдено доступных кредитных продуктов» | Для магазина не включены продукты или программа (промокод) не подключена — уточните у менеджера банка |
| «некорректно указан домен» | В кабинете банка адрес сайта должен быть `https://ваш-домен` без косой черты в конце; ссылки возврата — с того же домена |
| «На этом сайте недоступно внесение первоначального взноса» | Сумма больше 500 000 ₽: включите «Принимать первоначальный взнос» в кабинете банка и в настройках плагина, или не продавайте такие заказы в кредит |
| «недопустимое значение суммы» / 400 on `sum` | Сумма вне лимита банка (3 000–500 000 ₽, у рассрочки — после скидки) или неверный формат |
| «заказ с таким номером уже существует» | Состав заказа изменился после создания заявки; плагин создаст новую заявку с новым номером при следующей попытке (§9.2) |
| 401 on `info` | Пароль API не подходит — задайте новый в кабинете («Магазины» → «Настройки API») |
| 403 on `info` | Заявка принадлежит другой витрине — проверьте идентификатор витрины |
| 422 | Действие недоступно в текущем состоянии заявки |
| cURL error 60 / 77 | Сервер сайта не доверяет сертификату банка — обратитесь к хостингу (нужны корневые сертификаты Минцифры) |
| timeout / 5xx | Банк не ответил вовремя — повторите позже |

The six texts come from the bank's help page `tbank.ru/business/help/sales/loans/how-to-integrate/solve-problems/` (READ in
[improvements.ru.md](../research/2026-10-26-tbank-v2/improvements.ru.md) §2); they are not a contract, so matching is by
substring with the masked raw text as the fallback. No extra bank call is ever made to explain an error.

### 6.5 Logging and secrets

`get_secret_header_names()` keeps the framework list (it masks `Authorization`, so the Basic token never reaches a log).
`get_secret_param_names()` adds the buyer's personal data the docs show in bodies — `lastName`, `firstName`, `middleName`,
`mobilePhone`, `email`, `first_name`, `last_name`, `middle_name`, `phone`, `password` — so the framework's body redaction
(by key, any depth, `woodev/api/class-api-base.php:2473,2815`) masks them in the request (`values.contact`) and in `Info`
responses, which the docs do not say are masked (d-docs §7). The webhook body is **never** logged raw (v1 logged it through
`to_string_safe()` = `print_r`, `v1:includes/api/class-wc-api-ipn-response.php:302-308`): the handler logs order number, status,
`demo`, `committed` and the decision only.

### 6.6 What works without the API password

| Feature | With password | Without password |
|---|---|---|
| Checkout `Create`, «Вернуться к заявке» | yes | yes |
| Webhook authenticity | `Info` re-query (authoritative); source IP advisory | **source IP in `91.194.226.0/23` is mandatory** (§10.1); the webhook body is the data |
| Status decisions | from `Info` | from the webhook body, with one restriction: a webhook `rejected` is **per bank** («Вебхук Rejected не означает, что по всей заявке пришел отказ», `settings/app-notifications`, READ), so without `Info` it only adds a note; the order is not failed (I7 needs «all banks») |
| I3 panel | `Info` data | webhook data (same field set, PII masked by the bank), marked «по уведомлению банка» |
| I4 «Обновить статус» | yes | button disabled with the reason «Нужен пароль API» |
| I6 safety `Info` on return | yes | skipped; the page shows the last known state |
| Wizard check | demo application + password check (`Info` on it with `demo-` login) | demo application only; the password row says «не указан — проверка статусов недоступна» |

## 7. Item list reconciled to the kopeck by construction (D12, I1)

### 7.1 Facts the algorithm stands on

- `sum` and every `price`: > 0, ≤ 1 000 000, at most two decimals, dot separator (help page, READ via d-docs §5).
- `quantity` is an **integer** (`Item` table, `setup-types/api/methods`, READ again); `price` is «Цена одной единицы товара».
- Whether the bank checks Σ`price` or Σ`price × quantity` is ambiguous in the primary docs (d-docs §5, UNVERIFIED U1). The
  reference reading (unit price × integer quantity) is backed by v1's field record: v1 has sent multi-quantity lines with a
  unit price for years (`v1:includes/api/class-wc-tinkoff-api.php:84-88`), and the failures support saw came from tax, promo
  codes and kopeck shipping (D12), not from every quantity > 1 — a Σ`price` rule would have rejected every such order.
- v1's mismatches, by code: per-unit price rounded from the line (3 × 33.33 = 99.99 for 100.00), taxes sent as separate items,
  shipping without its tax, a single negative fee silently skipped, zero-price lines sent
  (`v1:includes/api/class-wc-tinkoff-api.php:78-137`; improvements.ru.md §1).

### 7.2 Algorithm (integers in kopecks throughout; no float arithmetic)

1. **Target** `S` = the order total in kopecks, parsed from `wc_format_decimal( $order->get_total(), 2 )` as a string.
2. **Raw lines** with gross amounts `g` (kopecks, each component rounded half-up separately from its stored decimal string):
   product lines `g = line total + line total tax` (WooCommerce stores the line total after coupons and without tax in both
   tax modes, so this is what the buyer pays for the line), quantity `q` = item quantity; shipping lines `g = total + tax`,
   `q = 1`; fee lines `g = total + tax`, `q = 1`, may be negative.
3. **Drop** every line with `g = 0` (gifts, 100 % coupons, free shipping).
4. **Adjustment** `A = S − Σ g(positive lines)`. It carries the negative fees AND any residual WooCommerce rounding, gift-card or
   store-credit reduction that has no line of its own. If `A ≠ 0`, distribute `A` over the positive lines **in proportion to
   `g`, largest-remainder method**, with the floor `g' ≥ 1` kopeck for every line; a line that
   hits the floor is fixed there and the rest is redistributed over the others; if the floor cannot hold for every line
   (`S` smaller than the number of lines) the request is refused (unreachable at the 3 000 ₽ minimum, kept as a guard).
5. **Split** every line into at most two bank items: `u = intdiv( g', q )`, `r = g' − u·q`. `r = 0` → one item `(q, u)`;
   `r > 0` → `(q − 1, u)` and `(1, u + r)` (then `q ≥ 2` because `0 < r < q`). A **non-integer** quantity (sold by weight), or
   `g' < q` (a unit price below one kopeck, so `u = 0`) → one item `(1, g')`. The second item carries the same name. **Provisional (coordinator may revisit):** no suffix on the
   remainder item's name (improvements §1 left the wording to the operator).
6. **Clean** names: `wp_strip_all_tags`, `html_entity_decode( ENT_QUOTES | ENT_HTML5, 'UTF-8' )` (v1 sent `&quot;`/`&amp;`
   through `htmlentities`, improvements §1), collapse whitespace, `mb_substr( …, 0, 255 )` (the stricter of 255/256, d-docs §8.4),
   empty → "Item" (storefront msgid). Shipping item name: `sprintf( __( 'Shipping: %s' ), $method_name )`. `vendorCode` = SKU only
   when ≤ 64 characters, else omitted. `category` not sent (v1 parity).
7. **Assert** Σ(`price × quantity`) = `S`, every `price ≥ 1`, every `quantity ≥ 1` integer, every `price` and `S` ≤ 100 000 000
   kopecks. Any failure → **no bank call**; the debug log gets the line-by-line breakdown (names masked to 40 characters); the
   buyer gets the §6.4 text.

**Fallback mode for U1.** A class constant switches step 5 to "every line as `(1, g')`", which satisfies BOTH readings of the
sum rule at the cost of a less detailed bank receipt. Card R1 (§23) settles U1 with one password-free `create-demo` call; the
constant stays in the code as a one-line rollback either way.

### 7.3 Test table (unit, `Item_List_Builder`, each row an order fixture → expected items)

| # | Case | Expected |
|---:|---|---|
| T1 | qty 3, line 100.00 ₽ | `(2, 33.33)` + `(1, 33.34)`; Σ = 100.00 |
| T2 | qty 1, 12 345.67 ₽ | one item, exact |
| T3 | 10 % coupon over three lines with odd kopecks | lines from WC totals, Σ = total, no residual |
| T4 | fixed-cart coupon 500 ₽ spread by WC over lines | Σ = total |
| T5 | prices entered with tax, VAT 22 % | `g` includes tax; no «НДС» item |
| T6 | tax added on top, «round at subtotal» on, residual ±1 kopeck | `A = ±1` distributed; Σ = total |
| T7 | shipping 349.99 ₽ + tax | one shipping item incl. tax |
| T8 | free shipping 0 ₽ | no shipping item |
| T9 | positive fee 150.50 ₽ | fee item |
| T10 | negative fee −300 ₽ | no fee item; −300 distributed; every line ≥ 1 kopeck |
| T11 | zero-price gift line | dropped |
| T12 | 100 % coupon on one line | that line dropped; others exact |
| T13 | quantity 1.5 (weight) | `(1, line)` |
| T14 | gift card plugin lowers the total by 1 000 ₽ with no line | `A = −100 000` distributed |
| T15 | name with `"`, `&`, `<b>`, 300 characters | decoded, tags stripped, 255 characters |
| T16 | SKU of 80 characters | no `vendorCode` |
| T17 | `serialize_precision = 17` in php.ini | JSON prints `33.33`, not `33.329999999999998` (§7.5) |
| T18 | a `wc_tinkoff_api_create_request_params` filter that breaks the sum | no HTTP request; debug log names the filter |
| T19 | a tiny line and a large negative adjustment (floor case) | tiny line fixed at 1 kopeck; rest redistributed; Σ exact |
| T20 | fallback mode on | every item `quantity 1`; Σ exact |
| T21 | quantity 7, line 0.05 ₽ (unit price below a kopeck) | one item `(1, 0.05)`; Σ exact |

### 7.4 The kept filter

`wc_tinkoff_api_create_request_params` (§3.5) receives the finished body. Step 7 runs on its **output**, so custom code can
add fields but cannot send a non-reconciling list by accident.

### 7.5 JSON number precision

Prices are rendered from kopecks as decimal strings and encoded as JSON numbers; the serializer sets
`ini_set( 'serialize_precision', '-1' )` around `wp_json_encode()` and restores it. A host with `serialize_precision = 17`
would otherwise print `33.329999999999998`, which the bank rejects as more than two decimals (T17).

## 8. Availability, programmes, informer, checkout text

### 8.1 Amount window (D11)

On the cart / order total `T` (`get_order_total()`, which is the order total on the pay page): hidden when `T < 3 000 ₽` or
`T > ceiling`, ceiling = 500 000 ₽, or 1 000 000 ₽ when `accept_down_payment = yes` (the bank: amounts above 500 000 ₽ go
into the down payment only with the cabinet switch, d-docs §6). Currency RUB only (`currencies` on plugin and gateway); country
RU (v1). The dead `woocommerce_update_order_review` branch of v1 (`v1:includes/class-gateway.php:185-187`) is dropped. The
limits are bank rules, so they are constants, not settings; a developer filter `woodev_wc_tinkoff_kvk_amount_window` is the
only override.

Order of checks in `Gateway::is_available()`: `parent::is_available()` (enabled, configured, currency, country, fires
`wc_gateway_wc_tinkoff_kvk_is_available` once) → window → at least one programme fits (§8.2) → the kept filter
`wc_gateway_wc_tinkoff_kvk_is_available` again (v1 position) → **licence last** (§13), so no filter can re-enable an unlicensed
gateway.

### 8.2 Programmes (promo codes; D4, I9)

- Rows `{name, code, enabled, min_amount, max_amount}`; `min_amount`/`max_amount` optional (empty = no bound), validated as
  non-negative amounts; `code` trimmed, ≤ 64 characters (the stricter limit, d-docs §8.4), stored as plain text (v1 stored
  `esc_html( code )`, `v1:includes/class-gateway.php:285`, so `&` reached the bank as `&amp;` — fixed; existing stored values are
  decoded on read).
- A row is offered when enabled and `T` is inside its bounds. No enabled rows at all → the hidden input sends `default` (v1).
  Enabled rows exist but **none fits `T`** → the gateway is hidden for this cart. **Provisional (coordinator may revisit):**
  hide rather than fall back to `default`, because a merchant who set bounds said the programme is not for this amount, and the
  `default` credit is a different product.
- Output: one row → hidden input; several → radio list. Name `esc_html`, code `esc_attr`, id attributes from a sanitized key.
  The submitted code is accepted **only if it is one of the offered codes**; anything else → the first offered one (v1 sent
  any posted string, `v1:includes/api/class-wc-tinkoff-api.php:59-62`).

### 8.3 Product-page informer (D4)

- Hook and position as v1 (`woocommerce_single_product_summary`, 25). Shown only when the gateway is enabled, configured and
  the licence is in force (v1 showed it even for a disabled gateway and built a new gateway object per render,
  `v1:includes/product.php:18`); price `P = (float) $product->get_price()` inside the §8.1 window.
- Divisor: setting `informer_divisor` (default 19, the bank-recommended average — D4), then the renamed filter
  `wc_gateway_tinkoff_kvk_loans_rate`; any non-numeric, zero, negative or non-finite result → 19. Never divides by zero.
- `%payment_amount%` → `number_format_i18n( floor( P / divisor ) )`, escaped; the merchant text goes through `wp_kses_post()`;
  the renamed filter `wc_gateway_tinkoff_kvk_product_info` gets the result and its return is passed through `wp_kses_post()`
  again. Wrapper class `wc-geteway-tinkoff-kvk-product-info woocommerce-info` **kept** (themes may style it; CSS classes are not
  hooks).

### 8.4 `default` fallback

When a programme code is absent or empty, the request sends `promoCode: default` (the bank's documented default and its own
fallback for a wrong code, d-docs §5).

### 8.5 Checkout text (I10)

The gateway `description` (a standard, editable field) gets a new default — storefront msgid in English with the Russian in the
catalogue: *«Вы перейдёте на страницу Т-Банка и заполните заявку — это займёт несколько минут. Банки ответят в течение пары
минут, договор подписывается по СМС. Кредит или рассрочку оформляют покупателям от 21 до 65 лет с официальным стажем от 3
месяцев.»* (age and work terms from the bank's `about-loans/terms` page, improvements §10). Installed shops keep their stored
description. Programme labels show the merchant's names. Nothing else is printed (no monthly payment, I14).

## 9. Application lifecycle on our side

### 9.1 `get_hosted_pay_page_url( $order )`

1. Licence in force, else refuse (the gateway is hidden anyway; this guards the pay page).
2. Build the item list (§7); on failure → buyer text, no call.
3. Choose the order number (§9.2); store `order_number` row, `current_order_number`, `create_fingerprint`, `promo_code`,
   `environment`.
4. `create` or `create_demo` with: `shopId`, `showcaseId`, `sum`, `items`, `orderNumber`, `promoCode`, `webhookURL` (§3.4),
   `successURL` = `get_checkout_order_received_url()`, `failURL` = `get_checkout_payment_url()` (the buyer can pay another way —
   I7), `returnURL` = `get_cancel_order_url_raw()` (v1, `v1:includes/api/class-wc-tinkoff-api.php:43-46`), `values.contact`
   with **`lastName` = billing last name, `firstName` = billing first name** (the v1 swap, `:50-51`, fixed), `mobilePhone`,
   `email`; in test mode `demoFlow` (required by the docs; v1 sent it only when an admin picked it, so a non-admin test order
   had none — fixed with the default `sms`) and optional `initialStage`.
5. Store `application_id` and `application_link` from the response; return `link` (the framework redirects).

### 9.2 Order numbers

The framework's `unique_transaction_ref` is unusable: `get_order()` increments `retry_count` on every call
(`woodev/payment-gateway/class-payment-gateway.php:2005-2023`), webhook lookups included. The plugin owns the number:

- First attempt: `ltrim( $order->get_order_number(), '#' )` — v1's value, so v1 orders and their webhooks keep matching.
- A **new** number `{base}-{n}` (n = 2, 3, …; base shortened with a hash suffix if the result would exceed 64 characters) is
  minted only when the previous application is terminal (`rejected` / `canceled` in the stored status) **or** the order's
  `create_fingerprint` (S + item list hash) changed since the last `create` — the bank refuses a known number with a different
  composition, and returns the OLD application for a known number with the same composition (`setup-types/api/methods` § Create,
  READ), which after a refusal would hand the buyer the refused application again.
- Otherwise the stored number is reused, and the bank returns the same application and link — that is what «Вернуться к
  заявке» relies on (§12.3).

### 9.3 Demo selectors (test mode, `manage_woocommerce` only)

`demoFlow`: `sms`, `ses`, `appointment`, `appointment-reject`, `reject` (the documented enum; v1 lacked `ses`).
`initialStage`: the documented values `filling`, `wait-offer`, `offers-all`, `wait-offer-timeout`, `kvk-signing-sms`,
`kvk-issued`, `canceled`, `rejected` (v1's `canceled-by-partner` is not in the docs and is dropped). Posted values are
validated against these lists. Labels are admin strings (Russian msgids).

## 10. Webhook handler (D11, D14, I5, I7)

### 10.1 Route, intake and verification

- Registered in **both** environments: `woocommerce_api_wc_gateway_tinkoff_kvk_ipn_<hash>` for the current id pair and for up to
  five previous pairs (option `woodev_wc_tinkoff_kvk_webhook_endpoints`, appended when the ids change), so a callback for an
  application created before an id change still lands. The Hosted base's class-name route is removed (§3.4).
- Intake: POST only (anything else → 200, ignored); body from `php://input` capped at 64 KB; JSON object with a string `id`
  ≤ 64 characters, else → 200 + debug line «тело уведомления не распознано» (a non-2xx would make the bank resend garbage).
- Required fields: `wc_tinkoff_ipn_require_response_data` (default `id`, `status`, `committed`, `order_amount` — v1) present,
  else → 200, ignored, debug line.
- **Source address**: `REMOTE_ADDR` only (never `X-Forwarded-For`, which the sender controls) in `91.194.226.0/23`
  (`91.194.226.1`–`91.194.227.254`, `settings/app-notifications` § Подлинность, READ). With the password it is **advisory** —
  a request from outside is still verified by `Info` (shops behind a CDN or proxy see the proxy's address) and the debug log
  says so; without the password it is **mandatory** → outside the range: 403, no state change. A developer filter
  `woodev_wc_tinkoff_kvk_webhook_source_trusted( bool, $remote_addr )` lets a site behind a trusted proxy (and the rig, §20.2)
  supply its own verdict. **Provisional (coordinator may revisit):** advisory-with-password / mandatory-without.
- **Order lookup** (§10.2) happens before any bank call; not found or not paid with `wc_tinkoff_kvk` → 200, debug line, no
  `Info` (bounds the cost of a forged request, README §9).
- **Throttle** before `Info`: at most one `Info` per order number per 10 s (a held answer → HTTP 503 + `Retry-After: 10`, the
  bank re-delivers) and at most 120 webhook-driven `Info` calls per site per hour (excess → 503). **Provisional (coordinator
  may revisit).**
- **Verification**: with the password, `Info` for the payload's order number; its body is the state. `Info` failing (timeout,
  5xx, 429) → **503** so the bank re-delivers; `Info` 401/403 → 200, a debug line and an admin-visible order note «пароль API не
  подходит — статус не проверен» once per order, and the webhook body is used under the without-password rules if the source
  address is in the range. Without the password: the body is the state.
- **Amount**: `order_amount` must equal the `sum` we sent for this order number (kopecks); a mismatch → note «сумма в
  уведомлении не совпадает с заявкой», no status change, 200.
- Answer 200 for every handled or deliberately ignored request; 503 only for "try again later". The handler never lets an
  exception reach the framework's default catch, which would mark a pending order failed
  (`woodev/payment-gateway/class-payment-gateway-hosted.php:387-406`).

### 10.2 Order lookup by our own number (fixes D11 «order lookup by our own order number»)

In order: (1) `wc_get_orders( [ 'meta_key' => '_wc_wc_tinkoff_kvk_order_number', 'meta_value' => $id, 'limit' => 2 ] )` —
HPOS-safe, every number we ever sent; (2) for v1-era orders, `_order_number` meta (the key the common sequential-numbering
plugins write) with the same query; (3) `wc_get_order( (int) $id )` when `$id` is all digits AND that order's
`get_order_number()` equals `$id` (default numbering). More than one match → none (note + debug line); the order must have
payment method `wc_tinkoff_kvk`. v1 looked up `wc_get_order( $payload->id )` only (`v1:includes/api/class-wc-api-ipn-response.php:48-65`).

### 10.3 Status map

`Application_State` is built from `Info` (or from the webhook body without the password). «Cooling active» = `commit_cooldown.until`
in the future (`Info` has only `until`; the webhook adds `has_happened`, `settings/app-notifications`, READ).

| Bank state | Order now | Action |
|---|---|---|
| `new`, `inprogress` | any | meta only; no status change, no note (v1 sent unknown states into the failure path, `v1fw:payment-gateway/class-payment-gateway-hosted.php:539-546`) |
| `approved` | `pending` / `failed` | meta; order note «Банк одобрил заявку»; **customer note** with the application link (I6, once per application); status stays **`pending`**. **Provisional (coordinator may revisit):** v1 set `on-hold` and reduced stock; an approval is not a payment, an unsigned application gets no expiry webhook (d-docs §3), and a `pending` order stays under the I5a veto, so WooCommerce releases it after the limit instead of leaving it on hold forever |
| `signed`, cooling active, `enable_cooling = yes` | needs payment | `mark_order_as_held()` (`on-hold`, stock reduced — v1) with «не отгружайте до {until}» |
| `signed`, cooling over or `enable_cooling = no` | needs payment (`pending`, `failed`, `on-hold`) | `add_transaction_data()` + `complete_payment()` → `payment_complete()`; note «не подтверждена — подтвердите в кабинете банка в течение 14 дней» when `committed = false` |
| `signed` | `cancelled` | **I5:** `on-hold` (not `processing` — stock may have been released), order note, one admin e-mail (§10.4) |
| `signed` | `processing` / `completed` / `on-hold` with the same signature | nothing (idempotent) |
| `rejected` (from `Info`: all banks) | needs payment | `failed` with a calm note (I7); the buyer can pay another way on the pay page |
| `rejected` (webhook body only, no password) | any | note «отказ одного из банков», no status change |
| `canceled` | needs payment | `cancelled` (v1) |
| `canceled` | `processing` / `completed` | `on-hold` + note «покупатель отменил заявку после подписания — деньги не поступят, не отгружайте» + admin e-mail. **Provisional (coordinator may revisit)** |

Idempotency: every processed state stores `status_signature` = hash of (`status`, `committed`, cooling active, order number);
a delivery with the stored signature changes nothing and adds no note. Every write goes through `Application_Meta`
(the seven v1 display keys plus the additive keys of §3.3, money as decimal strings — v1's `intval()` truncation,
`v1:includes/api/class-wc-api-ipn-response.php:194-213`, is gone). A state for a **superseded** order number (§9.2) only writes
a note, except `signed`, which always goes through the table above (a buyer who signed an older application is never ignored).

### 10.4 The admin e-mail for I5 and the post-signature cancel

WooCommerce has no stock «order needs attention» e-mail. **Provisional (coordinator may revisit):** one plain message through
`WC()->mailer()` (WooCommerce's header/footer wrapper) to the shop's admin address, subject «Заказ №{n}: требуется ваше
решение», body naming what the bank reported and what to check; sent once per order (`late_signed_notified`). The wording is an
operator item (§22.3).

## 11. WooCommerce auto-cancel veto (I5a)

Facts (I5a, measured from WC source): `wc_cancel_unpaid_orders()` cancels only `pending` orders created via `checkout` /
`store-api`, only with stock management on and `woocommerce_hold_stock_minutes ≥ 1`; filter
`woocommerce_cancel_unpaid_order( bool $cancel, WC_Order $order )` decides per order.

- Settings: `prevent_auto_cancel` (checkbox, default **yes**), `auto_cancel_hold_hours` (integer 1–720, default **72**).
- The filter returns `false` when the setting is on, the order is paid with `wc_tinkoff_kvk`, it has a `current_order_number`,
  its stored bank state is not terminal (`new`, `inprogress`, `approved`, or none yet), and the order is younger than the limit.
  Past the limit, or after `expected_overdue_at` when known, WooCommerce cancels as usual; I5 stays the last safety net.
- The bank's application lifetime from the docs: `expected_overdue_at` in every body; the docs' example shows +14 days; an
  unconfirmed phone is deleted after 24 h (d-docs §3). **Provisional (coordinator may revisit):** keep 72 h as the default
  (holding stock for 14 days hurts the merchant more than a late signature, which I5 recovers); the field's tooltip says the
  bank keeps an application up to 14 days.
- Cost: no bank call; one filter on WooCommerce's own cron.

## 12. Merchant and buyer surfaces

### 12.1 Order panel «Заявка в Т-Банке» (I3)

A meta box on the order edit screen (HPOS and posts screens via `wc_get_page_screen_id( 'shop-order' )`), rendered only for
`manage_woocommerce`, only for orders paid with this gateway. It shows only what the bank returned (empty rows are omitted):
status in words (`new` «покупатель ещё не подтвердил телефон», `inprogress` «покупатель заполняет заявку или ждёт решения
банков», `approved` «одобрена — ждём подписания», `signed` «договор подписан», `canceled` «отменена покупателем», `rejected` «отказ всех банков»), product (кредит / рассрочка), bank, term, monthly payment, credit amount, down payment, signing type
(`sms` «по СМС», `ses` «Self ID», `bank` «на встрече с представителем банка» — v1 mapped everything but `sms` to «Личная
встреча», `v1:woodev-vkredit.php:148-149`), cooling end «не отгружайте до …», «не подтверждена — подтвердите в кабинете банка»
when `committed = false`, application and order numbers, the refund line «к возврату — сумма, которую банк перевёл магазину:
{transfer_amount}; комиссия за рассрочку не возвращается; возврат оформляется в кабинете банка» (D5), the data source and
time, and the fiscal hint (§12.4). All output escaped.

### 12.2 «Обновить статус» (I4)

A button in the panel → admin AJAX action `woodev_wc_tinkoff_kvk_refresh_status` with `check_ajax_referer` and
`current_user_can( 'manage_woocommerce' )`; one `Info` per press for the order's current number; at most one per 30 s per order
(transient; a second press inside the window answers «можно повторить через N с» without a call); the result runs through the
same `Status_Mapper` as a webhook. Works with the licence not in force (checking an existing application is not a new action,
D8/I4). Without the password the button is disabled with the reason. No scheduled polling anywhere.

### 12.3 Buyer side (I6)

- **Thank-you page** (`woocommerce_thankyou_wc_tinkoff_kvk`) and **«Мои заказы» → order** (`woocommerce_order_details_after_order_table`,
  v1's hook): a short state text by the stored status — filling / banks reviewing (up to two minutes) / approved, sign the
  contract / signed / refused, pay another way (with the pay link when the order is payable) — storefront msgids, escaped.
- **«Вернуться к заявке»** while not signed and not terminal: a link to `?wc-api=wc_tinkoff_kvk_resume&order={id}&key={order_key}`;
  the endpoint checks the order key (WooCommerce's guest authorisation), the licence (a re-`Create` is a new action: not in
  force → no link and the endpoint refuses), at most one per 60 s and ten per order (`resume_count`); it re-uses §9.2 (same
  number + same composition → the bank returns the same application) and redirects to the link. **Provisional (coordinator may
  revisit):** order key instead of a nonce, because the buyer may open it from an e-mail in another session.
- **Customer note on `approved`** with the application link (WooCommerce's standard «Заметка к заказу» e-mail), once per
  application.
- **Safety `Info` on return** to the thank-you page: with the password, when the stored state is not final, at most one per 5
  min and ten per order (`return_info_count`); licence-independent. No scheduled calls.
- The v1 order-details table and customer e-mail block (`v1:woodev-vkredit.php:162-217`) stay, through the kept filter; labels and
  values are escaped (v1 printed filter output raw).

### 12.4 Fiscal receipt hint (I8)

One sentence in the wizard checklist, the order panel and the merchant docs: «Чек формирует банк автоматически — не отправляйте
второй чек из кассового плагина по этому способу оплаты» (the bank's `how-to-integrate/api` help page, improvements §8).

## 13. Licence gate (D8)

`License_Gate::is_in_force( $plugin )` (no store product → `true`; else the signed claim) is the only input; there is no switch.

| Gated (licence not in force) | Never gated |
|---|---|
| the gateway at checkout (`is_available()` last check; `request_claim_refresh()` is called there, which only schedules a cron refresh) | webhooks and their `Info` re-query |
| `create` from the pay page (§9.1) and «Вернуться к заявке» | the order panel and «Обновить статус» |
| the product informer (it advertises a method that is hidden) | the thank-you / «Мои заказы» text and the safety `Info` |
| | settings screen, saving, the wizard and its demo check (§14) |

**Provisional (coordinator may revisit):** the wizard's demo check counts as part of the settings, not a new bank action
(CreateDemo creates nothing at the bank, `setup-types/api/testing-api`, READ). Refusals use `License_Gate::refusal()` wording.
Unlike shipping rates, gateway availability is evaluated on every checkout refresh, so the carriers' package-hash trick
(`track_license_state()`) is not needed.

## 14. Setup wizard (D9)

### 14.1 Settings for the wizard without a `woodev-settings` tab

A wizard settings step reads its fields from `$plugin->get_settings_handler()` (`woodev/setup/class-setup-wizard.php:771-779`).
`Setup\Wizard_Settings extends \Woodev_Abstract_Settings` registers `shop_id`, `showcase_id`, `password` (`sensitive`, so the
schema never emits its value — `woodev/settings-page/class-field-schema.php:58-77`), `environment` and `accept_down_payment`,
and overrides `load_settings()` / `save()` (both overridable, `woodev/settings-api/abstract-class-settings.php:41-50,564-588`)
to read and write the same keys **inside** `woocommerce_wc_tinkoff_kvk_settings` (booleans as `yes`/`no`). The plugin returns no
settings-page providers, so no tab appears (D7). An empty submitted password keeps the stored one (test).
**Provisional (coordinator may revisit)** — the mapping is new; the alternative is a custom React step (contract D4).

### 14.2 Steps

1. **«Подключение»** — shop id, showcase id, API password; validation callback: ids non-empty, ≤ 50 characters. Description:
   where to find them, and that the password is needed for status checks (a new one is set in «Магазины» → «Настройки API»).
2. **«Проверка подключения»** — a content step with the action «Создать тестовую заявку»: one `create_demo` with
   `sum 5 000 ₽`, one item «Тестовый товар», `promoCode default`, `demoFlow sms`, orderNumber `woodev-check-{8 hex of
   md5(home_url + showcase_id)}` — the **same number every time** for the same shop and showcase, so repeated presses return the
   same demo application instead of creating new ones (§9.2 rule). Then, with a password, one `info` on that number with the
   `demo-` login → «пароль API подходит» / «не подходит». Result lines use §6.4. Limits: one press per 60 s, ten per day.
   The link to the demo form is shown so the merchant can click through it (any SMS code works, `testing-api`).
3. **«Боевой режим»** — the environment switch plus a checklist: (a) in the cabinet, «Адрес сайта», «Success URL» and «Fail
   URL» hold the domain **without a trailing slash**, shown ready to copy (`scheme://host` of `home_url()`) — D12 Q2; (b) the
   site uses HTTPS (computed from `home_url()`; a red line if not — the bank does not support HTTP); (c) «Адрес для
   HTTP-нотификаций» with its tick, shown ready to copy (§3.4); (d) «Принимать первоначальный взнос» mirrors the cabinet switch;
   (e) auto-confirm: with it off, confirm signed applications in the cabinet within 14 days; (f) the fiscal hint (§12.4).

### 14.3 When it appears

New installs: the framework's standard first-run redirect / notice. A shop updated from v1 with `shop_id` and `showcase_id` set:
no redirect and no notice; the wizard is linked from the gateway settings screen («Мастер настройки»). No v1 migration screen
(D9). **Provisional (coordinator may revisit).**

## 15. Settings (WooCommerce → Настройки → Платежи → Т-Банк)

Label rules: `AGENT-RULES.md` Rule 10a/10b. Admin strings are Russian msgids; storefront defaults (title, description, button)
are English msgids translated in the catalogue (§18).

| Key | Type | Default | Label | Help (slot) |
|---|---|---|---|---|
| `enabled` | checkbox | no | framework «Включить / Выключить» | — |
| `title` | text | "T-Bank credit or instalments" → «Кредит или рассрочка от Т-Банка» | framework | framework tooltip |
| `description` | textarea | §8.5 text | framework | framework tooltip |
| `enable_customer_decline_messages` | checkbox | no | framework | framework |
| `debug_mode` | select | off | framework «Режим отладки» | description: «Не включайте без необходимости: в журнал записывается больше данных» (Rule 10b case 3) |
| `environment` | select | production | framework; options «Боевой режим» / «Тестовый режим» | tooltip |
| `shop_id` | text | '' | «Идентификатор магазина» | description with the cabinet link (case 1) |
| `showcase_id` | text | '' | «Идентификатор витрины» | description with the cabinet link |
| `password` | **secret** (custom `generate_secret_html` / `validate_secret_field`: a password input rendered EMPTY with «сохранён» in the placeholder; an empty submission keeps the stored value; a checkbox «Удалить пароль» clears it) | '' | «Пароль API» | description: why it is needed + where to set a new one. **Provisional (coordinator may revisit):** WooCommerce's stock `password` field prints the stored value into the page source |
| (display only, no key) | — | — | «Адрес для уведомлений» | description: the webhook URL to copy into the cabinet (case 2) |
| `enable_cooling` | checkbox | no (v1) | «Период охлаждения»; checkbox label «Учитывать период охлаждения» | tooltip (v1 text) |
| `accept_down_payment` | checkbox | no | «Первоначальный взнос»; checkbox label «Принимать первоначальный взнос» | tooltip: switch it on only when the same switch is on in the bank cabinet; then orders up to 1 000 000 ₽ are accepted |
| `order_button_text` | text | «Оформить заявку в Т-Банк» (v1) | «Текст на кнопке» | tooltip |
| `promo_codes` | custom table: name, code, enabled, from, to | [] | «Программы» | description: from the bank's personal manager; an empty list uses the basic credit |
| `single_page_informer` | textarea | '' | «Текст на странице товара» | description with `%payment_amount%` |
| `informer_divisor` | number ≥ 1 | 19 | «Делитель платежа» | tooltip: the monthly figure = price ÷ this number; 19 is the bank's average |
| `prevent_auto_cancel` | checkbox | yes | «Автоотмена заказов»; checkbox label «Не отменять, пока банк рассматривает заявку» | tooltip |
| `auto_cancel_hold_hours` | number 1–720 | 72 | «Срок ожидания банка, ч» | tooltip: after this the order is cancelled as usual; the bank keeps an application up to 14 days |

Refund instruction (D5) sits in the gateway's admin `method_description`: «Возврат по кредиту или рассрочке оформляется в кабинете
банка; плагин не отправляет возвраты автоматически.»

## 16. v1 defects → fix → proving test

| # | Defect (v1 evidence) | Fix | Test |
|---:|---|---|---|
| 1 | First/last name swapped (`v1:includes/api/class-wc-tinkoff-api.php:50-51`) | §9.1 | request body fixture asserts `fio.lastName` = billing last name |
| 2 | Webhook order looked up as a WC id (`v1:includes/api/class-wc-api-ipn-response.php:48-65`) | §10.2 | sequential-number order (`_order_number`), our meta, numeric fallback, ambiguous match |
| 3 | The md5 webhook route only in production; demo applications sent to the class-name URL (`v1:includes/class-gateway.php:47-72`, `v1fw:payment-gateway/class-payment-gateway-hosted.php:780-794`) | §10.1 | test-mode body carries the md5 URL; route registered in test mode; same URL string as v1 |
| 4 | Promo name raw in checkout HTML (`v1:includes/class-gateway.php:134`); informer unescaped (`v1:includes/product.php:26`) | §8.2, §8.3 | `<script>` in a promo name / informer text renders escaped |
| 5 | `intval` on money (`v1:includes/api/class-wc-api-ipn-response.php:194-213`) | §10.3 | 1234.99 stays 1234.99 in meta and panel |
| 6 | Raw webhook body in the log (`:302-308`) | §6.5 | log captured under debug: no name, phone, e-mail |
| 7 | Only a 3 000 ₽ floor (`v1:includes/class-gateway.php:189`) | §8.1 | 500 000.01 ₽ hidden; with down payment 1 000 000 ₽ shown, 1 000 000.01 hidden |
| 8 | Hard-coded divisor, divide by zero via filter (`v1:includes/product.php:23-24`) | §8.3 | divisor 0 / '' / -5 / NAN → 19 |
| 9 | EUR/USD declared (`v1:includes/class-gateway.php:42`, `v1:woodev-vkredit.php:78`) | §8.1 | USD store → gateway unavailable |
| 10 | Seven order-card keys never written (§2) | §10.3 | after a signed fixture every key present with the bank's value |
| 11 | No source check (README D11) | §10.1 | IP outside range without password → 403, no change; with password → verified by Info |
| 12 | Sum ≠ items (`v1:includes/api/class-wc-tinkoff-api.php:78-137`) | §7 | T1–T21 |
| 13 | Bank error text and an HTML settings link shown to buyers (`v1:includes/class-gateway.php:341-348`, `v1:includes/api/class-wc-api-response.php:66-72`) | §6.4 | buyer notice is the calm text; debug log has the reason |
| 14 | Any posted promo code sent (`v1:includes/api/class-wc-tinkoff-api.php:59-62`) | §8.2 | posted unknown code → first offered code |
| 15 | Promo code stored HTML-escaped (`v1:includes/class-gateway.php:285`) | §8.2 | `A&B` reaches the body as `A&B` |
| 16 | `demoFlow` missing for non-admin test orders (`v1:includes/api/class-wc-tinkoff-api.php:64-76`) | §9.1 | test-mode body always has `demoFlow` |
| 17 | Unknown / intermediate Info status → order failed (`v1fw:payment-gateway/class-payment-gateway-hosted.php:539-546` via `v1:includes/api/class-wc-api-ipn-response.php:83-93`) | §10.3 | `new` / `inprogress` change nothing; an `Info` error → 503, not failed |
| 18 | Cooling read from the webhook body, not Info (`v1:includes/api/class-wc-api-ipn-response.php:72-81`) | §10.3 | cooling from `until` vs now |
| 19 | Signing type map wrong for `ses`/`bank` (`v1:woodev-vkredit.php:148-149`) | §12.1 | three values, three labels |
| 20 | A gateway object constructed per product view (`v1:includes/product.php:18`) | §8.3 | informer reads the registered gateway instance |
| 21 | Promo table JS needs jQuery Migrate (`v1:includes/html-promo-codes.php:74,78`) | `Admin\Promo_Codes_Field` | rig: add/remove rows on current WP without Migrate |
| 22 | Class-name IPN route reachable in production through a lowercase `wc-api` (`v1fw:payment-gateway/class-payment-gateway-hosted.php:37-50,802-804`) | §3.4 | the route is not registered (any case) |
| 23 | A forged or malformed webhook could fail a pending order (default flow, `:387-406`) | §10.1 | malformed body / mismatched amount → 200, order unchanged |
| 24 | Order-details table prints filter output raw (`v1:woodev-vkredit.php:175`) | §12.3 | escaped |

## 17. Security

- Every output escaped at the sink (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` for merchant HTML); no raw filter output.
- Capability + nonce on every admin action (`manage_woocommerce`, `check_ajax_referer`); the resume endpoint authorises by the
  order key and is rate-limited.
- Secrets: the password never rendered back (§15), never logged (`Authorization` masked); PII keys masked (§6.5); the webhook body
  never logged raw.
- The webhook trusts `REMOTE_ADDR` only, verifies with `Info` when it can, bounds forged traffic (§10.1, §19).
- No `sslverify` override; JSON size cap on intake; order number validated before it becomes a URL path segment
  (`rawurlencode`, ≤ 64 characters).

## 18. i18n

Text domain **`woodev-vkredit`** (the folder slug; v1 had none). **Provisional (coordinator may revisit).** Render-path rule
(`AGENTS.md` → Translatable strings): storefront strings (title/description/button defaults, checkout notices, thank-you and
«Мои заказы» texts, item names «Item» / «Shipping: %s», the customer note, the order-details labels behind the kept filter) have
**English msgids** with Russian in `languages/woodev-vkredit-ru_RU.po` / `.mo`; admin strings are Russian msgids; logs need no
wrapper. `load_plugin_textdomain` from `languages/`. A coverage test reads the `.po` like
`woocommerce-yandex-delivery:tests/unit/I18n/YandexStorefrontTranslationTest.php` and fails on a storefront msgid without a
translation. The wizard's `__()` labels are built on `init` (the Russian Post wizard pattern).

## 19. Worst-case bank traffic per shop

| Channel | Bound | Why |
|---|---|---|
| `create` at checkout | one per buyer checkout attempt (buyer-driven) | v1 parity |
| `create` from «Вернуться к заявке» | ≤ 1/min, ≤ 10 per order | §12.3 |
| `create_demo` from the wizard | ≤ 1/min, ≤ 10/day; repeated presses hit the same demo application | §14.2 |
| `info` from webhooks | ≤ 1 per order per 10 s and ≤ **120 per hour** per site; forged requests for unknown orders cost nothing | §10.1 |
| `info` from «Обновить статус» | ≤ 1 per 30 s per order, human presses | §12.2 |
| `info` on buyer return | ≤ 1 per 5 min, ≤ 10 per order | §12.3 |
| `info` from the wizard | one per demo check | §14.2 |
| scheduled | **zero** | no cron, no Action Scheduler job calls the bank |
| woodev.ru | nothing new (the framework's licence/updater only) | — |

The bank's 25 req/s limit is out of reach of every bounded channel.

## 20. Testing

### 20.1 Unit (PHPUnit + Brain Monkey / Mockery, no network)

Client (recorded fixtures from the docs for `create`, `create-demo`, `info` in every state, and refusal shapes 400/401/403/422/429
with `Retry-After`/500/timeout/cURL 60; auth header per environment; timeouts; secret and PII masking; host constant);
`Item_List_Builder` T1–T21; `Order_Numbers` (first attempt = v1 value, new number on terminal state or changed fingerprint,
64-character cap, lookup chain); `Status_Mapper` table §10.3 row by row incl. idempotency and superseded numbers;
`Webhook\Handler` (method, size cap, malformed JSON, required-fields filter, IP range edges `91.194.225.255` / `91.194.226.0` /
`91.194.227.255` / `91.194.228.0`, advisory vs mandatory, throttle → 503, `Info` failure → 503, 401 → note, amount mismatch,
demo order); `Auto_Cancel_Veto` (each condition and the limit); licence gate matrix of §13; informer divisor/escaping; programmes
window and whitelist; settings secret field; the wizard settings mapping and both actions' limits; i18n catalogue coverage;
contract tests of §3 (option keys, meta keys, gateway id, webhook URL formula, hook names kept/renamed).

### 20.2 Simulating the bank (D14)

Webhooks cannot reach a local rig in live mode, so tests and the rig **simulate** the bank:

- `tests/fixtures/bank/webhook-{approved,rejected,canceled,signed-cooling,signed-cooled,signed-committed,demo}.json` built from
  the docs' webhook example (comma restored, §0 note 3) and field table; `info-{new,inprogress,approved,signed-cooling,signed,
  canceled,rejected}.json` from the `Info` table; masked personal data as the bank sends it.
- A test helper posts a fixture to the handler with a chosen `REMOTE_ADDR` and answers `Info` through `pre_http_request` from a
  fixture, so each §10.3 row runs end to end on a real `WC_Order` double.
- On the rig: a mu-plugin (rig only, never shipped) answers `forma.*` through `pre_http_request` from the same fixtures and returns
  `true` from `woodev_wc_tinkoff_kvk_webhook_source_trusted`; a script `scripts/simulate-webhook.sh <fixture> <order-number>`
  POSTs the fixture to the rig's webhook URL. The bank's headers are not documented (UNVERIFIED U5), so the handler depends on
  none (no `Content-Type` check).

### 20.3 Integration

Real entry load through `Woodev_Loader` (literals-only definition), gateway registration and settings round-trip on HPOS and posts
storage, classic checkout → `create` stubbed → redirect URL, the wizard REST save into the gateway option.

### 20.4 Rig (functional acceptance is the coordinator's; the operator glances at visuals)

Classic checkout with the gateway offered/hidden by amount and programme; demo application end to end with the **operator's live
ids** only through `create-demo` and only within the overnight scope (≤ 2 demo applications in total — card R1 may use one); the
simulated webhooks of §20.2 moving a real order through every row; panel and refresh (refresh stubbed without a password);
thank-you and «Мои заказы» texts; informer; the wizard; the three coexistence rows of §5.3.

### 20.5 Gates

`composer test`, `composer phpcs`, `composer phpstan`, `php -l`, local-ci (`-w .github/workflows/ci.yml`) as the merge evidence
with a Codex critic; `npm run lint:docs` in the framework repo for this spec.

## 21. Out of scope for 2.0.0

Cart/Checkout Blocks (D3, #1476 next); Commit/Cancel/refund calls and their buttons (D5); «Долями», T-Kassa, bank transfer, the
several-gateways line (D6, #1474, #1475); a product-card «Оформить кредит» button and a cart widget (D4 — a card to file); the
orders-list column (I11); programmes by category/product, analytics, bank-computed monthly payment (I12–I14); a `woodev-settings`
tab (D7); scheduled status polling of any kind; a GlitchTip project (§22.3).

## 22. Registers

### 22.1 UNVERIFIED

| # | Item | How to settle | Blocks release? |
|---|---|---|---|
| U1 | Sum rule Σ`price` vs Σ`price × quantity` | card R1: one `create-demo` with a 2-unit item (password-free, creates nothing at the bank) | No — fallback mode (§7.2) is one constant |
| U2 | Which TLS chain a hosting server sees for `forma.tinkoff.ru` and `forma.tbank.ru` | card R2: `curl -v` both hosts from a production hosting server (no request body) | No — the default host is field-proven |
| U3 | Exact error bodies (`errors[]` shape, sum-mismatch text) | the R1 call returns one; the explainer falls back to the masked raw text | No |
| U4 | Webhook retry count / interval / timeout | not in the docs; ask the bank's integration contact | No — design never depends on a re-delivery |
| U5 | Webhook request headers (`Content-Type`, user agent) | not in the docs; first real webhook on a shop with debug on | No — the handler reads none |
| U6 | Whether a demo webhook is delivered to a per-order `webhookURL` when the cabinet field is empty | rig with a public URL, or the bank contact | No |
| U7 | Whether the demo `Info` password equals the live one (the docs' example implies it) | wizard check on a shop with a password | No |
| U8 | Whether the `Info` body masks personal data | first `Info` with a password | No — masked in logs regardless |
| U9 | Whether `monthly_payment` / `credit_amount` exist before signing | first `approved` `Info` | No — the panel shows only what came |
| U10 | Whether `integrationType: woodev` is used by the bank | the bank contact | No |
| U11 | Whether the over-limit down-payment mechanism applies to instalments as well as credits (d-docs §6) | the bank contact | No — the switch is the merchant's |
| U12 | How the bank's fiscal receipt renders a split remainder item (§7.2 step 5) | first real signed order | No |

### 22.2 Provisional decisions (coordinator may revisit)

1. No function alias for `wc_tinkoff_kredit()` (§3.6).
2. Keep `upgrade_to_1_4_0` (§5.4).
3. API host `forma.tinkoff.ru` by default, switch after R2 (§6.1).
4. Timeouts: `create` 20 s, `info` 10 s (§6.3).
5. Remainder item without a name suffix (§7.2).
6. Programmes with bounds and none fitting → gateway hidden, no `default` fallback (§8.2).
7. Source IP advisory with the password, mandatory without; filter for trusted proxies (§10.1).
8. Webhook throttle: 1 `Info` / order / 10 s, 120 / site / hour, 503 beyond (§10.1).
9. `approved` keeps the order `pending` (v1: `on-hold`) (§10.3).
10. `canceled` after `processing` → `on-hold` + admin e-mail (§10.3).
11. I5 / post-signature-cancel admin e-mail as one plain WooCommerce-mailer message (§10.4).
12. I5a default stays 72 h (§11).
13. Resume link authorised by the order key, not a nonce (§12.3).
14. The wizard's demo check is not licence-gated (§13).
15. Wizard settings through a `Woodev_Abstract_Settings` subclass mapped onto the gateway option (§14.1).
16. No wizard redirect or notice for shops updated from v1 with ids set (§14.3).
17. Password as a custom secret field rendered empty (§15).
18. Text domain `woodev-vkredit` (§18).
19. `License_Gate` used from its Shipping namespace until framework card F1 lifts it (§4).

### 22.3 Operator items (each with the provisional choice in force)

| # | Question | Provisional choice |
|---|---|---|
| O1 | D5's cancellation history: what went wrong for merchants before? **This worker did not reach Support AI / FreeScout** (no access path from this machine was tried or configured in the brief). Release 1 makes no cancel calls, so nothing in 2.0.0 depends on it | none needed for 2.0.0; recover before any Cancel/Commit work |
| O2 | `approved` → keep `pending` (new) or `on-hold` (v1)? | `pending` (§10.3) |
| O3 | Wording of the I5 admin e-mail and of the split remainder item | §10.4 text; same name |
| O4 | A GlitchTip project for the plugin (the carriers have one each) | none in 2.0.0 |
| O5 | Default host once R2 is measured | `forma.tinkoff.ru` |
| O6 | Card to file: «Оформить кредит/рассрочку» button on the product card (D4) | after 2.0.0 |

## 23. Implementation plan in waves (repo `kalbac/woodev-vkredit`, one reviewable PR per wave into `main`)

Status found on 26.10.2026: `main` = `3095883` (v1 code + Orca/Serena files); branch `v1` keeps v1; branch
`kalbac/chore-v2-tooling-skeleton` (`d2c06bd`, **not merged**) adds `AGENTS.md`, `CLAUDE.md`, `composer.json/lock`,
`.github/workflows/ci.yml`, `.github/local-ci.Dockerfile`, `phpunit.xml.dist`, `phpcs.xml.dist`, `phpstan.neon.dist`,
`scripts/sync-framework.sh`, `tests/bootstrap.php`, `tests/phpstan-bootstrap.php`, `tests/unit/PluginHeaderTest.php`,
`docs-internal/` stubs, and removes the bundled v1 `woodev/` from git. Backlog board №13. Every brief names the worker's own
worktree path for Serena; `tests/**` is Serena-ignored there as here.

| Wave | Scope (files) | Acceptance tests | Depends on | Parallel? |
|---|---|---|---|---|
| **W0** skeleton | merge/finish the tooling branch; new `woodev-vkredit.php` (header 2.0.0, loader literals §5.1), `includes/class-autoloader.php`, `includes/class-plugin.php`, empty `includes/class-gateway.php`, `includes/class-lifecycle.php` (§5.4), `languages/woodev-vkredit-ru_RU.po/.mo` (header only), v1 `includes/**` removed from `main`; `PluginHeaderTest` → 2.0.0 | boot test through `Woodev_Loader`; contract tests §3.1–3.5 (ids, option keys, meta prefix, webhook URL formula vs v1, hook names); i18n coverage test (empty set passes); CI and local-ci green | — | alone |
| **W1** bank client | `includes/api/*` (`Client`, `Request`, `Response`, `Application_State`, `Error_Explainer`), `tests/fixtures/bank/*` | §20.1 client list; §6.4 table; masking | W0 | ∥ W2 |
| **W2** item list | `includes/order/class-item-list-builder.php` + its tests | T1–T21 | W0 | ∥ W1 |
| **R1** measurement (Haiku, detailed brief) | one `create-demo` with live ids, 2-unit item; record answer and any error body in `docs-internal/research` of the plugin repo | U1, U3 closed; ≤ 1 demo application | W1 | ∥ W2 |
| **R2** measurement | TLS of both hosts from a hosting server (no body) | U2 closed | — | any time |
| **W3** gateway + checkout | `includes/class-gateway.php`, `includes/order/class-order-numbers.php`, `includes/order/class-application-meta.php`, `includes/admin/class-promo-codes-field.php` | settings round-trip incl. secret field; availability (window, RUB, programmes, licence, kept filter order); checkout fields escaped and whitelisted; `create` body fixture (names, URLs, demoFlow); order-number rules | W1, W2 | alone |
| **W4** webhook + veto | `includes/webhook/class-handler.php`, `includes/order/class-status-mapper.php`, `includes/order/class-auto-cancel-veto.php` | §20.1 handler list; §10.3 table end to end with simulated webhooks (§20.2); veto conditions; I5 e-mail once | W3 | alone |
| **W5** admin panel | `includes/admin/class-order-panel.php` + view, AJAX refresh | panel rows from fixtures; capability/nonce; 30 s limit; no-password disabled; licence-independent | W4 | ∥ W6, W7 |
| **W6** buyer side + informer | `includes/frontend/*` (status notice, resume endpoint, product informer), order-details/e-mail block | state texts; resume limits + order key + licence; customer note once; safety Info limits; informer divisor/escaping/licence | W4 | ∥ W5, W7 |
| **W7** wizard | `includes/setup/*` | mapping onto the gateway option; empty password keeps stored; demo check limits and stable number; password check; checklist content | W1, W3 | ∥ W5, W6 |
| **W8** release prep | catalogue complete, `readme`/changelog with §3.6, merchant docs card, rig acceptance §20.4, coexistence rows §5.3 | coverage test with all storefront msgids; rig run recorded | W5, W6, W7 | alone |

Parallel waves own disjoint directories (W1 `includes/api/`, W2 `includes/order/class-item-list-builder.php`; W5 `includes/admin/`,
W6 `includes/frontend/`, W7 `includes/setup/`); the plugin class wiring (`includes/class-plugin.php`) is touched by W5–W7 and must be
merged in order (each wave adds one `init` line). Rounds: at most 2–3 per wave, then decompose. Release (framework pin, plugin
release, EDD upload) waits for the operator's word (decisions → Overnight scope).

## Related

- [T-Bank v2 decisions](../research/2026-10-26-tbank-v2/decisions.md) — the binding input.
- [T-Bank v2 fact base](../research/2026-10-26-tbank-v2/README.md), [v1 inventory](../research/2026-10-26-tbank-v2/a-v1-inventory.md),
  [bank docs](../research/2026-10-26-tbank-v2/d-docs.md), [framework map](../research/2026-10-26-tbank-v2/c-framework-map.md),
  [improvements](../research/2026-10-26-tbank-v2/improvements.ru.md), [API market](../research/2026-10-26-tbank-v2/b-api-market.md) —
  the reports behind the facts.
- [Russian Post v2 plugin design](2026-10-21-russian-post-v2-plugin-design.md) — the structure and depth model.
- [Setup wizard v2 contract](2026-10-08-setup-wizard-v2-contract.md) — the engine the wizard runs on.
- [Clean-break policy](../adr/005-platform-v2-clean-break-policy.md) — code may break, installed-site data may not.
- [A hosted gateway's default response flow fails orders it does not understand](../gotchas/a-hosted-gateway-s-default-response-flow-fails-orders-it-does-not-understand.md) — why §10 overrides the handler.
- [A loader definition cannot use a framework class constant](../gotchas/a-loader-definition-cannot-use-a-framework-class-constant.md) — §5.1.
