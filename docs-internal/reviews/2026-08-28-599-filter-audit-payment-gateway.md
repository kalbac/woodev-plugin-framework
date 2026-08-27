# #599 audit — Worker A — `woodev/payment-gateway/`

Read-only audit. No source files, git commands, or gates were touched. Serena was active
(`activate_project` on `D:/Projects/woodev_framework`, verified via `find_symbol` reporting
paths under `woodev\payment-gateway\...`) and used for all `.php` reads except a handful of
short `admin/views/*.php` templates (view templates, not source logic — read via `Read`, which
is appropriate for them).

**Coverage**: 70/70 `apply_filters()` calls in `woodev/payment-gateway/` examined (matches
`grep -rn "apply_filters(" --include='*.php' woodev/payment-gateway/ | wc -l` = 70).

## Systemic note (applies to many HARMLESS rows below, stated once here instead of 30 times)

Every filter whose return is echoed, concatenated (`.`/`.=`), or passed through
`esc_html()`/`esc_attr()`/`esc_url()`/`sprintf('%s', ...)` shares one latent risk: if a plugin
returns an **object without `__toString()`**, PHP raises "Object of class X could not be
converted to string" — a real fatal. An **array** return in the same spot is not fatal, just a
Warning + the literal string "Array". Since virtually every string-typed filter in this codebase
has this same exposure and none of them guard it, I did not re-flag it per-row (it would be 30
near-duplicate findings); it is one systemic gap, not a "worth acting on" per-line item. It is
listed once in `## Worth acting on`.

## Table

| file:line | hook name | return goes to | verdict | note |
|---|---|---|---|---|
| admin/class-payment-gateway-admin-payment-token-editor.php:496 | `..._token_editor_validate_token_data` | `save()`: `if ($data = ...)` truthy-only check, then `build_token()`→`new Woodev_Payment_Gateway_Payment_Token($token,$data)` (untyped `$data` param) | WRONG-DATA | Docblock says "array or false"; code only checks truthiness, not shape. A truthy non-array (e.g. `1`, a string) is accepted and reaches the persisted token constructor, which does `isset($data['type'])`/`unset($data['account_number'])` — corrupts saved admin-edited payment token data. Admin-only path (wp-admin profile save). |
| admin/...token-editor.php:597 | `..._token_editor_title` | `display()`→view `html-user-payment-token-editor.php`: `esc_html($title)` | HARMLESS | scalar echo, see systemic note |
| admin/...token-editor.php:624 | `..._token_editor_columns` | `display()`→view: `foreach($columns...)` (safe) AND `count($columns)` (twice, for `colspan`) | **FATAL** | `count()` on a non-Countable/array throws `TypeError` since PHP 8.0. Breaks the wp-admin user-profile page render (token editor table) for any user viewing a profile with a supported gateway. |
| admin/...token-editor.php:698 | `..._token_editor_fields` | a) `display_tokens()`→view (foreach, safe); b) `ajax_get_blank_token()`: `array_fill_keys(array_keys($fields), '')` | **FATAL** | `array_keys()` on non-array throws `TypeError` (internal function, strict on type regardless of `strict_types`). Fatals the `wp_ajax_..._admin_get_blank_payment_token` AJAX handler (admin, "add token" button). |
| admin/...token-editor.php:766 | `..._token_editor_actions` | `display()`→view: `!empty($actions)` then `foreach` | HARMLESS | `!empty()` and `foreach` both tolerate any type without crashing; worst case the actions column silently disappears |
| admin/...token-editor.php:787 | `..._token_editor_token_actions` | `display_tokens()`→view: `foreach($actions...)` | HARMLESS | foreach tolerates any type, no crash |
| admin/class-payment-gateway-admin-user-handler.php:203 | `wc_payment_gateway_admin_user_profile_title` | `add_profile_section()`→view: `esc_html($section_title)` | HARMLESS | scalar echo, see systemic note |
| admin/...user-handler.php:219 | `wc_payment_gateway_admin_user_profile_description` | `add_profile_section()`→view: `!empty()` then `wp_kses_post()` | HARMLESS | defensive `!empty()` gate first; systemic note otherwise |
| admin/...user-handler.php:341 | `wc_payment_gateway_..._display_user_profile` | `if (! $this->is_supported() ...)` in two callers | HARMLESS | bool into `if`, PHP truthiness coercion, cannot crash |
| api/class-payment-gateway-api-response-message-helper.php:155 | `wc_payment_gateway_transaction_response_user_message` | `get_user_messages()`: `implode(' ', $messages)`; and (if a concrete `get_user_message()` implementation delegates here — see note) → `handlers/abstract-payment-handler.php:138`: `new Woodev_Payment_Gateway_Exception($response->get_user_message())` | **FATAL** (chain, one hop unverified) | `Woodev_Payment_Gateway_Exception extends Woodev_Plugin_Exception extends Exception` — PHP 8's native `Exception::__construct(string $message = "", ...)` is typed; a non-string (array/object) `$message` throws `TypeError`. **Caveat**: this framework only declares the `get_user_message()` *interface* (`api/interface-payment-gateway-api-response.php:82`); concrete implementations live in downstream gateway plugins outside this repo, so whether they delegate to this exact helper is inferred from the class name and doc `@see`, not directly observed. The `abstract-payment-handler.php:138` consumer (`new Woodev_Payment_Gateway_Exception($response->get_user_message())`) is directly observed and is real regardless of which class backs `get_user_message()`. |
| class-payment-gateway-direct.php:99 | `..._validate_credit_card_fields` | `validate_credit_card_fields()`→`validate_fields()` return, ultimately consumed by WC core as a boolean checkout gate | HARMLESS | bool into `if`/return, no crash; WC core coerces truthiness |
| class-payment-gateway-direct.php:240 | `..._process_payment` | `if ( is_array( $result = apply_filters(...) ) ) { return $result; }` | **GUARDED** | explicit `is_array()` check before treating the value as the final result array; anything else falls through to normal processing |
| class-payment-gateway-direct.php:281 | `wc_{id}_held_order_status` | `if ( $order->has_status( $held_order_status ) )` in `process_payment()` | **DISABLES** | No cast/validation. `has_status()` (WC core, not vendored here so not directly re-verified, but this is well-documented WC API) compares against real order-status strings; any value that doesn't match one — wrong type, typo, empty string — silently fails the check, so a held/fraud-review order goes straight to `payment_complete()` instead of being held. Checkout payment-approval path. |
| class-payment-gateway-direct.php:518 | `..._get_order` | `get_order()` return, consumed at `process_payment()`:245 as `$order->get_user_id()` (immediate method call) | **FATAL — verified** | Directly confirmed: `direct.php:245` calls `->get_user_id()` on whatever `get_order()` returned with zero type check. A non-object return is `Fatal error: Call to a member function get_user_id() on <type>`, on the core checkout `process_payment()` path. |
| class-payment-gateway-direct.php:593 | `..._credit_card_transaction_approved_order_note` | `$order->add_order_note($message)` | HARMLESS | scalar concat/echo pattern, see systemic note; `add_order_note()` is WC core, not independently verified here |
| class-payment-gateway-direct.php:810 | `..._add_payment_method_transaction_result` | `add_payment_method()`: `Woodev_Helper::wc_add_notice($result['message'], $result['success'] ? ...)` — **no `is_array()` guard**, unlike the sibling filter at line 240 | WRONG-DATA | Array-offset access on a non-array is a PHP Warning (not fatal) returning null, so `wc_add_notice(null, ...)` — degraded UX (empty/wrong notice on the add-payment-method page), not a crash. Contrast with line 240's `is_array()` guard shows the fix pattern already exists in this same file two methods away. |
| class-payment-gateway-direct.php:890 | `..._get_order_for_add_payment_method` | returned to `add_payment_method()`'s `get_order_for_add_payment_method()` caller, same shape as :518 | **FATAL** (pattern match, not individually traced) | Same `get_order()`-family shape as the verified :518 finding; not separately traced to a consumer call site, flagged on pattern consistency |
| class-payment-gateway-helper.php:154 | `wc_payment_gateway_loan_type_to_name` | returned from static helper, consumed wherever callers echo/concatenate a loan type name | HARMLESS | scalar echo pattern, see systemic note |
| class-payment-gateway-helper.php:198 | `wc_payment_gateway_payment_type_to_name` | same as above; also feeds `esc_html()` calls in `class-payment-gateway-direct.php:do_credit_card_transaction()` | HARMLESS | scalar echo pattern, see systemic note |
| class-payment-gateway-hosted.php:305 | `..._auto_post_form_args` | `return (array) apply_filters(...)` | **GUARDED** | explicit `(array)` cast neutralizes any crash risk; a garbage-but-array value would still corrupt the form args used in `render_auto_post_form()` (e.g. missing `message`/`button_text` keys → PHP notices when read, not fatal) |
| class-payment-gateway-hosted.php:475 | `..._get_order` | `get_order()` return, consumed at hosted.php:126/181/436 as `$order->method()` calls | **FATAL — verified** | Same verified pattern as direct.php:518: immediate method calls on the return with no type check |
| class-payment-gateway-my-payment-methods.php:299 | `..._my_payment_methods_table_method_actions` | `array_merge( $new_actions, $item['actions'], $custom_actions )` | **FATAL** | `array_merge()` throws `TypeError` on a non-array argument (PHP 8). Breaks the My Account → Payment Methods table render (frontend, customer-facing). |
| ...my-payment-methods.php:344 | `..._my_payment_methods_table_headers` | `if ( array_key_exists( 'expiry', $columns ) )` | **FATAL** | `array_key_exists()` throws `TypeError` on a non-array 2nd argument. Same customer-facing account page. |
| ...my-payment-methods.php:607 | `..._no_payment_methods_text` | `'<p>' . apply_filters(...) . '</p>'` | HARMLESS | concatenation, see systemic note |
| ...my-payment-methods.php:617 | `..._my_payment_methods_no_payment_methods_html` | returned from `get_no_payment_methods_html()` | HARMLESS | not independently traced to final echo site, but shape (string builder) matches systemic note pattern |
| ...my-payment-methods.php:639 | `..._my_payment_methods_table_method_title` | `esc_html($title)` then further concatenated | HARMLESS | scalar echo, systemic note |
| ...my-payment-methods.php:656 | `..._my_payment_methods_table_method_title_html` | a) `echo $this->get_payment_method_title_html($token)` in `add_payment_method_title()`; b) `wp_send_json_success(['title' => ...])` in the AJAX save handler | WRONG-DATA | Path (a) is the systemic echo risk; path (b) JSON-encodes fine regardless of type (arrays/objects don't crash `wp_json_encode`) but silently breaks the JS that expects a string `title`, degrading the "save nickname" AJAX UX |
| ...my-payment-methods.php:680 | `..._my_payment_methods_table_method_default_html` | `echo` in `add_payment_method_default()` | HARMLESS | scalar echo, systemic note |
| ...my-payment-methods.php:716 | `..._my_payment_methods_table_details_html` | `echo` in `add_payment_method_details()` | HARMLESS | scalar echo, systemic note |
| class-payment-gateway-payment-form.php:187 | `..._payment_form_tokenization_allowed` | `if ($this->tokenization_allowed() && is_user_logged_in())` | HARMLESS | bool into `if`, no crash |
| ...payment-form.php:216 | `..._payment_form_tokenization_forced` | `if/if` boolean checks in `get_save_payment_method_checkbox_html()` | HARMLESS | bool into `if`; see prose note below on why this and the payment-tokens-handler equivalent are NOT rated DISABLES despite gating subscription tokenization |
| ...payment-form.php:259 | `..._payment_form_default_payment_form_fields` | `render_payment_fields()`: `foreach ($this->get_payment_fields() as $field)` | WRONG-DATA | `foreach` on non-array is a Warning, not fatal — loop is silently skipped, so the checkout payment fields (card number, expiry, CSC) don't render. No crash, but a broken checkout form is a real, checkout-critical UX failure. |
| ...payment-form.php:342 | `..._payment_form_default_credit_card_fields` | feeds into :259's `get_payment_fields()` | WRONG-DATA | same consequence as :259 (they're the same rendering chain) |
| ...payment-form.php:375 | `..._payment_form_description` | `echo` in `render_payment_form_description()` | HARMLESS | scalar echo, systemic note |
| ...payment-form.php:412 | `..._payment_form_saved_payment_methods_html` | `echo` in `render_saved_payment_methods()` | HARMLESS | scalar echo, systemic note |
| ...payment-form.php:435 | `..._manage_payment_methods_text` | `wp_kses_post(apply_filters(...))` inline, then `sprintf('%s', ...)` | HARMLESS | scalar coercion, systemic note |
| ...payment-form.php:446 | `..._payment_form_manage_payment_methods_button_html` | `$html .= ...` | HARMLESS | concatenation, systemic note |
| ...payment-form.php:486 | `..._payment_form_payment_method_html` | `$html .= ...` in `get_saved_payment_methods_html()` loop | HARMLESS | concatenation, systemic note |
| ...payment-form.php:545 | `..._payment_form_payment_method_title` | `$html .= ...` in `get_saved_payment_method_html()` | HARMLESS | concatenation, systemic note |
| ...payment-form.php:580 | `..._payment_form_new_payment_method_input_html` | `$html .= ...` | HARMLESS | concatenation, systemic note |
| ...payment-form.php:613 | `..._default_tokenize_payment_method_checkbox_to_checked` | `$checked ? 'checked="checked" ' : ''` | HARMLESS | bool into ternary, no crash |
| ...payment-form.php:626 | `..._tokenize_payment_method_text` | `sprintf('%s', ..., apply_filters(...))` | HARMLESS | scalar coercion via sprintf, systemic note |
| ...payment-form.php:639 | `..._payment_form_save_payment_method_checkbox_html` | `echo $this->get_save_payment_method_checkbox_html() . '...'` | HARMLESS | concatenation, systemic note |
| class-payment-gateway.php:280 | `wc_{id}_icon` | `$this->icon` (untyped property), later read in `get_icon()` and echoed as an `<img src>` via `esc_url()` | HARMLESS | scalar coercion, systemic note |
| class-payment-gateway.php:470 | `woodev_payment_gateway_payment_form_js_localized_script_params` | `localize_script()`→`wp_localize_script()`, which does `(array) $l10n` internally (WP core) | **GUARDED** | WP core's own `(array)` cast on the `$l10n` param neutralizes crash risk — verified by reading `localize_script()` at class-payment-gateway.php:589-608, `wp_localize_script`'s behavior taken as well-known WP core API (not vendored in this repo) |
| class-payment-gateway.php:510 | `wc_payment_gateway_{id}_javascript_url` | `wp_enqueue_script($handle, $js_url, ...)` | WRONG-DATA | `wp_enqueue_script()` is defensive in practice (registers whatever string-like value it's given); a bad type produces a broken/missing script tag, not a crash |
| class-payment-gateway.php:530 | `wc_payment_gateway_{id}_css_url` | `wp_enqueue_style($handle, $css_url, ...)` | WRONG-DATA | same as :510 for styles |
| class-payment-gateway.php:548 | `wc_gateway_{id}_js_localize_script_params` | `localize_script()`→`wp_localize_script()`, same `(array)` cast as :470 | **GUARDED** | same reasoning as :470 |
| class-payment-gateway.php:677 | `wc_payment_gateway_{id}_order_button_text` | property used for the checkout "Place Order" button text | HARMLESS | scalar echo, systemic note |
| class-payment-gateway.php:964 | `wc_payment_gateway_{id}_form_fields` | `$this->form_fields`, iterated by `WC_Settings_API`/WC core admin-settings rendering (not vendored here) | WRONG-DATA | `foreach`-style consumption by WC core is Warning-only on a bad type, not fatal; breaks the gateway's wp-admin settings screen |
| class-payment-gateway.php:1262 | `wc_gateway_{id}_is_available` | boolean gate WC core uses to decide whether the gateway shows at checkout | **DISABLES** (flagged per brief's explicit instruction) | Structurally identical to the other HARMLESS bool-into-`if` filters (PHP truthiness means it can't crash), but this is explicitly the "is a gateway AVAILABLE" gate the brief calls out as top-priority. A type-valid-but-wrong return (e.g. a non-empty array, always truthy regardless of contents) would make an intentionally-unavailable gateway show up at checkout. I flag it because the brief asks me to, while being honest that mechanically it is the same shape as every other boolean filter I rated HARMLESS — see prose note. |
| class-payment-gateway.php:1314 | `woocommerce_gateway_icon` (WC core hook name, re-emitted) | echoed by WC core in the checkout payment-method list | HARMLESS | scalar echo, systemic note; consumer is WC core, not vendored here |
| class-payment-gateway.php:1342 | `wc_payment_gateway_{plugin_id}_use_svg` | `... ? '.svg' : '.png'` | HARMLESS | bool into ternary |
| class-payment-gateway.php:1415 | `..._get_order_base` | inside base `get_order()`, whose result flows to Direct/Hosted's own `get_order()` overrides and ultimately the verified `->get_user_id()`/etc. call sites at direct.php:245, hosted.php:126/181/436 | **FATAL** (same verified chain as :518/:475) | This is literally the same `get_order()` call chain as the two independently-verified findings above — `get_order_base()` is what the base class's `get_order()` returns before Direct/Hosted layer their own filters on top |
| class-payment-gateway.php:1522 | `..._get_order_for_capture` | `handlers/capture.php:105`: `$order = $this->get_gateway()->get_order_for_capture(...)`, then line 120: `$this->is_order_ready_for_capture( $order )` — **typed parameter `WC_Order $order`** (verified at `handlers/capture.php:300`) | **FATAL — verified** | Directly confirmed: a non-`WC_Order` return hits a hard-typed parameter a few lines later and throws `TypeError`. Capture path (admin "Capture Charge" button and/or automatic capture-on-paid-status). |
| class-payment-gateway.php:1654 | `..._get_order_for_refund` | not independently traced to a caller inside this repo slice (no in-repo caller found — likely called by refund-flow code I didn't locate, or by downstream gateway subclasses) | **FATAL** (pattern match, UNKNOWN caller — see honesty note) | Same `get_order()`-family shape/docblock (`@return WC_Order`) as the three verified findings, but I could not find its caller within `woodev/payment-gateway/` to directly confirm the crash the way I did for :518/:475/:1522. Flagging on pattern consistency, not direct proof — this is the one "get_order" family site where I am NOT fully confident, and I want that gap visible rather than asserting it as verified. |
| class-payment-gateway.php:2243 | `..._credit_card_transaction_approved_order_note` | `$order->add_order_note($message)` (duplicate of direct.php:593, different call site) | HARMLESS | scalar concat, systemic note |
| class-payment-gateway.php:2303 | `..._loans_transaction_approved_order_note` | `$order->add_order_note($message)` | HARMLESS | scalar concat, systemic note |
| class-payment-gateway.php:2330 | `wc_payment_gateway_{id}_held_order_status` (deprecated variant) | feeds :2339 below, then `$order->has_status($order_status)` | **DISABLES** | first half of the same two-filter chain as :2339 |
| class-payment-gateway.php:2339 | `wc_{id}_held_order_status` | `if (! $order->has_status($order_status)) { update_status(...) } else { add_order_note(...) }` | **DISABLES** | Same reasoning as direct.php:281 and abstract-payment-handler.php:364/373: no cast, any value that isn't a real order status silently defeats the "hold for review" mechanism and the order proceeds as if approved. This exact call site was NOT protected by the `(string)` cast that `abstract-payment-handler.php::get_held_order_status()` uses — this is a second, independent implementation of the same "held order status" concept inside `class-payment-gateway.php` itself, and it lacks the cast its sibling has. |
| class-payment-gateway.php:2740 | `wc_{id}_perform_credit_card_charge` | bool, returned to caller which uses it in `if`/ternary decisions (charge vs. authorization) | HARMLESS | bool into `if`, no crash — see prose note on why this isn't rated DISABLES despite being approval-adjacent |
| class-payment-gateway.php:2764 | `wc_{id}_perform_credit_card_authorization` | same shape as :2740 | HARMLESS | bool into `if` |
| class-payment-gateway.php:2782 | `wc_{id}_partial_capture_enabled` | bool into `if` in capture-flow code | HARMLESS | bool into `if` |
| class-payment-gateway.php:2798 | `wc_{id}_paid_capture_enabled` | bool into `if` | HARMLESS | bool into `if` |
| class-payment-gateway.php:2900 | `wc_{id}_available_card_types` | `get_available_card_types()`, consumed at :2858 `array_keys($this->get_available_card_types())` and :2861 `'options' => $this->get_available_card_types()` inside `init_form_fields()` | **FATAL** | `array_keys()` on non-array throws `TypeError` (PHP 8, internal function). Happens inside `init_form_fields()`, which runs as part of gateway construction/settings init — breaks the admin settings screen for that gateway (and potentially earlier, if `init_form_fields()` runs on every request, not just admin — not independently confirmed which). |
| handlers/abstract-payment-handler.php:364 | `wc_payment_gateway_{id}_held_order_status` (deprecated) | feeds :373, then `(string) $status` cast at the end of `get_held_order_status()` | **DISABLES** | The `(string)` cast prevents a crash (an array cast to string just becomes the literal "Array", no fatal) but does NOT validate the result is a real order status — so the hold mechanism is silently defeated exactly as in direct.php:281 and class-payment-gateway.php:2339. This is the ONE held-order-status implementation in this slice that at least attempts type safety (the cast), yet it's still defeatable on meaning — a clean illustration of the brief's "type valid, DISABLES anyway" pattern. |
| handlers/abstract-payment-handler.php:373 | `wc_{id}_held_order_status` | same `(string)` cast, same consumer (`mark_order_as_paid()`'s `$order->has_status(...)`, `mark_order_as_held()`'s `$order->has_status(...)`) | **DISABLES** | second half of the same chain as :364 |
| payment-tokens/class-payment-gateway-payment-tokens-handler.php:612 | `..._tokenization_forced` | bool, consumed in `payment-form.php`'s `&&` boolean expression | HARMLESS | bool into boolean expression, no crash — see prose note |
| ...payment-tokens-handler.php:700 | `..._payment_tokens_transient_key` | the `$transient_key` used for `get_transient()`/`set_transient()`/`delete_transient()` calls that cache a **specific user's** payment tokens | **DISABLES** | The method's OWN docblock explicitly warns: *"Setting an incorrect or invalid transient key (e.g. not keyed to the current user or environment) can result in unexpected and difficult to debug situations involving tokens... filter responsibly!"* — i.e. the docblock already predicts this exact danger (this answers Q4: yes, the contract is documented), but there is zero enforcement in code. A plugin returning a type-valid string that doesn't vary by `$user_id` (e.g. ignoring the 2nd filter argument) would make `get_tokens()`'s transient cache **serve one user's cached saved-card tokens to a different user** — a real cross-user data-isolation defeat, not a crash. This is the most severe DISABLES in this slice because it's a data-isolation/privacy issue, not just a business-logic one. |
| rest-api/class-payment-gateway-plugin-rest-api.php:47 | `wc_{gateway_id}_rest_api_system_status_data` | `$data['gateways'][$id] = $gateway_data;`, returned as part of the WC REST API System Status report | HARMLESS | plain array assignment, no crash regardless of type; worst case is a malformed entry in an admin debugging/support report, no security or checkout consequence |

## Counts

Examined: **70 / 70** apply_filters() sites (matches the grep count for this directory).

| Verdict | Count |
|---|---|
| FATAL | 12 |
| DISABLES | 8 |
| WRONG-DATA | 8 |
| GUARDED | 4 |
| HARMLESS | 38 |
| UNKNOWN | 0 |

(FATAL 12 = admin-token-editor:624, admin-token-editor:698, api-message-helper:155 [hedged],
direct:518, direct:890 [pattern], hosted:475, my-payment-methods:299, my-payment-methods:344,
class-payment-gateway:1415, class-payment-gateway:1522, class-payment-gateway:1654 [pattern,
unverified caller], class-payment-gateway:2900.
DISABLES 8 = direct:281, class-payment-gateway:1262 [flagged per brief], class-payment-gateway:2330,
class-payment-gateway:2339, abstract-payment-handler:364, abstract-payment-handler:373,
payment-tokens-handler:700, [the my-payment-methods:656 WRONG-DATA is NOT counted here].
WRONG-DATA 8 = admin-token-editor:496, direct:810, my-payment-methods:656, payment-form:259,
payment-form:342, class-payment-gateway:510, class-payment-gateway:530, class-payment-gateway:964.
GUARDED 4 = direct:240, hosted:305, class-payment-gateway:470, class-payment-gateway:548.)

## Worth acting on

Ranked FATAL and DISABLES first, as instructed.

1. **`class-payment-gateway.php:1522` `..._get_order_for_capture` → FATAL, verified.** Add an
   `is_array`-style guard: `if ( ! ($filtered = apply_filters(...)) instanceof WC_Order ) { return $order; }` (degrade to the unfiltered order) instead of returning the filter's raw value.
2. **`class-payment-gateway-direct.php:518` and `class-payment-gateway-hosted.php:475`
   `..._get_order` → FATAL, verified** (checkout `process_payment()`/redirect-back path — the
   single highest-traffic path in this whole slice). Same fix: validate `instanceof WC_Order`
   before returning, degrade to the pre-filter `$order` otherwise.
3. **`class-payment-gateway.php:1415` `..._get_order_base` → FATAL** (same call chain as #2,
   one layer lower). Same fix, applied once here covers the shared root cause for #2 as well
   since Direct/Hosted's `get_order()` both call `parent::get_order()` first.
4. **`payment-tokens/class-payment-gateway-payment-tokens-handler.php:700`
   `..._payment_tokens_transient_key` → DISABLES, cross-user data isolation.** Add a guard that
   the returned key still incorporates `$user_id` (e.g. `str_contains($key, (string) $user_id)`)
   or simply document-and-ignore non-conforming returns; this is the most consequential single
   finding in the slice because it can leak one user's saved payment methods to another.
5. **`handlers/abstract-payment-handler.php:364/373` and `class-payment-gateway.php:2330/2339`
   and `class-payment-gateway-direct.php:281` — the "held order status" filter, 5 sites, 2
   independent implementations → DISABLES.** Validate the returned status against
   `wc_get_order_statuses()` (or a fixed allow-list) before using it in `has_status()`; fall back
   to the default `'on-hold'` on an unrecognized value instead of silently letting a bad value
   make the hold check always fail.
6. **`class-payment-gateway.php:2900` `..._available_card_types` → FATAL.** Wrap the two
   `array_keys($this->get_available_card_types())` call sites (or the method itself) with an
   `is_array()` check, defaulting to `$this->available_card_types` unfiltered on failure.
7. **`my-payment-methods.php:299` and `:344` → FATAL (customer-facing My Account page).** Add
   `is_array()` guards before `array_merge()`/`array_key_exists()`, mirroring the pattern already
   used correctly at `class-payment-gateway-direct.php:240`.
8. **`admin/class-payment-gateway-admin-payment-token-editor.php:624` and `:698` → FATAL
   (wp-admin).** Guard `get_columns()`/`get_fields()` results with `is_array()` before the
   `count()`/`array_keys()` calls that consume them.
9. **`class-payment-gateway.php:1262` `wc_gateway_{id}_is_available` → DISABLES (flagged per
   brief).** No crash risk, but given the brief explicitly names "whether a gateway is
   available" as top-priority, worth a `(bool)` cast at minimum: `return (bool) apply_filters(...)`
   — cheap, removes the truthy-non-empty-array edge case entirely.
10. **Systemic**: every string-returning filter that's echoed/concatenated is exposed to the
    "object without `__toString()` fatals the page" class of bug. Not worth 30 individual guards,
    but a single project-wide helper like `Woodev_Helper::to_safe_string($value, $default = '')`
    used at the highest-traffic sites (checkout-rendering ones specifically: payment-form.php's
    HTML builders) would close this cheaply. Lowest priority of this list — no concrete incident
    like the s100 ones has been observed for this class of bug in this slice, unlike the others.

## A note on the "DISABLES vs HARMLESS" line I drew for boolean filters

The brief's own verdict list states plainly: "HARMLESS — the return goes somewhere that cannot
hurt (... a bool into an if ...)". Taken literally, that would make **every** boolean filter in
this slice HARMLESS, including `is_available`, `tokenization_forced`,
`perform_credit_card_charge/authorization`, `partial_capture_enabled`, etc. — none of them can
crash, since PHP's `if` coerces any type via truthiness.

I did not apply that literally everywhere. I drew the DISABLES line at filters whose return is
compared against a **small fixed set of exact values** (order-status strings, a transient-key
must-contain-the-user-id invariant) rather than consumed as a pure boolean — because in that
shape, almost *any* wrong-but-type-valid return (a typo, an ignored parameter, a value copied
from the wrong branch) silently produces "no match" and defeats the mechanism, which is a much
larger and easier-to-hit failure surface than "a plugin author deliberately returns the wrong
boolean." I kept pure boolean gates as HARMLESS per the brief's literal rule, with the single
exception of `is_available` (class-payment-gateway.php:1262), which I flagged as DISABLES anyway
because the brief explicitly names "whether a gateway is AVAILABLE" as one of the three
top-priority patterns to hunt for. I want this reasoning visible rather than silently picking one
interpretation, since it's a judgment call that changes counts materially (if you count all
boolean-into-if filters as DISABLES instead, the DISABLES count would be ~15 instead of 8).

## Where this brief was wrong or needed correction

- None of the brief's factual claims about this directory were wrong. The size estimate ("70 of
  162", "the largest slice") checked out exactly (70/70 verified by grep). `class-payment-gateway.php`
  is indeed the densest file (22 of the 70 sites) and is 3,542+ lines as stated.
- One gap in the brief's own methodology, not a factual error: the four questions focus on
  *type* mismatches reaching typed sinks. The single most severe finding in this slice
  (`payment-tokens-handler.php:700`, the transient-key cross-user leak) is a **type-valid**
  string that defeats a data-isolation invariant that has nothing to do with PHP types at all —
  it's a pure logic/contract violation the type system can never catch. Worth keeping in mind for
  the next audit of this shape: "does the return value's *content*, not just its type, matter for
  identity/isolation?" is a fifth question worth asking explicitly.
