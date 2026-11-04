TRACKA — v1 tracking inventory and installed-site contracts

# Task A — v1 inventory of «WooCommerce номер отслеживания заказа» (1.3.0)

Read: 03.11.2026. Source: `/Users/maksimmartirosov/Projects/legacy-v1/woocommerce-order-tracking-number/` (all own PHP, JS, CSS, templates and `.po/.pot` read in full). Paths below are relative to that directory. The bundled `woodev/` is the OLD framework, **`Woodev_Plugin::VERSION = '1.3.3'`** (`woodev/class-plugin.php:19`, plugin registers `'1.3.3'` at `woocommerce-order-tracking-number.php:28`); it is cited only for persistent-key formats. Claims are **READ** (source inspection), **MEASURED** (grep/count run) or **UNVERIFIED** (reason given). No network, shop or carrier API was used; carrier URLs were not checked for liveness. The public product page was not fetched (see §5, question Q1).

## 1. Feature map

| Surface | v1 behaviour and evidence |
|---|---|
| Manual entry (order edit screen) | **READ:** a side meta box `wc-order-tracking-number` titled «Отслеживание посылки» (`includes/class-order-tracking-actions.php:169-174`) on the classic and HPOS order screen (`get_order_admin_screen()`, `includes/trait-order-util.php:75-81`). Fields: provider select (9 built-in + «Производная служба доставки»), custom provider name, tracking number, custom link, shipped date (`:247-304`). A live link preview is built client-side from the provider URL format (`:312-361`). |
| Entry paths | **READ:** (a) AJAX «Сохранить» → `wp_ajax_order_tracking_number_save_form` (`:42,435-455`); (b) plain order «Update» → `woocommerce_process_shop_order_meta` priority 0 (`:22,378-390`); (c) PHP API `wc_otn_add_tracking_number()` / `wc_otn_delete_tracking_number()` (`includes/functions.php:15-81`); (d) two carrier-plugin hooks: `wc_russian_post_order_tracking_number_changed` and `wc_edostavka_update_tracking_code` (`:45-50,665-683`). **MEASURED:** no REST route, no bulk action, no import/CSV, no shortcode, no `register_rest_route`, no `add_shortcode` in own code (grep over `includes/`, `woocommerce-order-tracking-number.php`). |
| Delete / refresh | **READ:** `wp_ajax_order_tracking_number_delete_item` and `…_get_items` (`:41,43,417-428,462-469`); JS `assets/admin.js:59-86,88-109`; `window.wc_order_tracking_number_items_refresh` is a global (`:114`). |
| Admin orders list column | **READ:** column `tracking_number` «Номер отслеживания» on both legacy `manage_shop_order_posts_columns` and HPOS `manage_woocommerce_page_wc-orders_columns` (`:33-39,101-164`); renders a `<ul>` of links, output via `wp_kses_post`. |
| Known carriers and URL building | **READ:** 9 providers in `get_providers()`, filter `wc_order_track_number_providers_list` (`:79-91`): Russian Post `pochta.ru/tracking?barcode=%1$s`, CDEK `cdek.ru/ru/tracking?order_id=%1$s`, PEC, Shiptor, SBER Logistics, Delovie Linii, DPD, Boxberry, TC Energy. URL = `sprintf(format, number, urlencode(normalized postcode), shipping country, order id)` (`:481-513`); postcode is shipping, else billing. Custom provider: free-text name + free-text link, no templating (`:490-493`). **UNVERIFIED:** whether any of these 9 URLs still work (no network allowed). |
| Carrier status fetching | **MEASURED:** none. No HTTP call, no `wp_remote_*`, no cron, no Action Scheduler in own code. v1 only stores a number and builds a link; it never learns the delivery status. |
| Emails | **READ:** (1) own customer email `WC_Order_Tracking_Number_Email`, id `order_tracking_number`, title «Уведомление номера отслеживания доставки», registered via `woocommerce_email_classes` (`woocommerce-order-tracking-number.php:61,86-92`; `includes/class-order-tracking-email.php:9-13`). Sent **only manually**: order action «Отправить номер отслеживания» (`class-order-tracking-actions.php:24-28,392-410`). Settings: enabled, subject, heading, message body, email type (`class-order-tracking-email.php:129-171`). Placeholders `{order_number}`, `{date}`, `{tracking_code}` (`:24-26,73-75`); `{date}` is the SEND date, not the ship date. Default subject «[Site] Ваш заказ передан в курьерскую службу», default body «Ваш заказ {order_number} был отправлен курьерской службой {date}. {tracking_code}» (`:15-17`). (2) a tracking table appended to every WC email at `woocommerce_email_before_order_table` priority 0, except the refund email (`class-order-tracking-actions.php:31,184-194`). |
| My Account / order view | **READ:** `woocommerce_view_order` renders `templates/myaccount/view-order.php`: table `shop_table my_account_tracking`, columns provider, number, date, «Follow shipping» button (`:30,199-201`; template `:8-41`). No separate tracking page, no guest/order-tracking-form integration. |
| Order note / throttle | **READ:** after sending, an order note «Уведомление с номером отслеживания было отправлено покупателю.» is added (`class-order-tracking-email.php:81`); meta `_tracking_number_last_send` is meant to throttle repeat sends (`class-order-tracking-actions.php:398-410`, see red flag R3). |
| Statuses | **MEASURED:** no custom order status and no `woocommerce_order_status_*` hook. Adding a number does not change the order status. |
| Settings | **READ:** the plugin has no settings page of its own. Only (a) the email's WC settings under WooCommerce → Settings → Emails (`class-order-tracking-email.php:129-171`) and (b) the framework's licence screen (from the bundled framework, `woodev/licensing/class-plugin-license-settings.php`). No settings class in own code. |
| Licence / updater | **READ:** EDD item id **2653** (`woocommerce-order-tracking-number.php:110-112`), sales page `https://woodev.ru/downloads/shipment-tracking/`, support `https://woodev.ru/support/` (`:118-124`). Licence is a hard gate: with no valid licence `save_tracking_items()` silently returns (`class-order-tracking-actions.php:599-601`) and the email does not send (`class-order-tracking-email.php:78`). Updater from the bundled framework (`woodev/plugin-updater/class-plugin-updater.php`). |
| Lifecycle / upgrades | **READ:** `upgrade_to_1_2_0` moves the pre-framework licence key `woocommerce_nomer_otslezhivaniya_zakaza_license_key` into the framework key option, verifies it, deletes the legacy key and `…_license` (`includes/class-lifecycle.php:11-28`). No install routine, no uninstall file: **MEASURED** no `uninstall.php`, no `register_uninstall_hook`; data survives deletion. |
| i18n | **READ:** text domain `woocommerce-tracking-number` (differs from the folder slug) (`woocommerce-order-tracking-number.php:51`). **MEASURED:** the `.pot` has 16 translatable strings (9 carrier names + 7 template strings, `languages/woocommerce-tracking-number.pot`). Every admin and email string (meta box, column, email class, order action) is a hard-coded Russian literal and is not translatable. |
| Blocks / HPOS flags | **READ:** declares `hpos => true` and `blocks.cart/checkout => true` (`woocommerce-order-tracking-number.php:52-58`). The plugin has no cart/checkout surface, so the blocks declaration is harmless noise. |

## 2. Installed-site contracts to preserve or migrate

### 2.1 Identity

| Key | Value | Evidence |
|---|---|---|
| Main file / folder slug | `woocommerce-order-tracking-number/woocommerce-order-tracking-number.php` | **READ** header `:1-15` |
| Plugin Name | `WooCommerce номер отслеживания заказа` | `:3`, `:107` |
| Text domain | `woocommerce-tracking-number` (not the slug) | `:51`; templates and `.po` |
| Framework plugin ID | `wc_order_track_number` (drives every framework option name) | `:49` |
| EDD item id | `2653` | `:110-112` |
| Constants / globals | `WD_ORDER_TRACKING_NUMBER_VERSION`, `WD_ORDER_TRACKING_NUMBER_FILE`; functions `wc_order_track_number()`, `init_wc_order_track_number_init()`, `wc_otn_add_tracking_number()`, `wc_otn_delete_tracking_number()`; classes `WC_Order_Tracking_Number`, `…_Actions`, `…_Email`, `WC_Order_Tracking_Lifecycle`, trait `Order_Tracking_Util` | `:21-22,34-142`; `includes/functions.php`; `includes/trait-order-util.php:12` |
| `backwards_compatible` | `'1.2.1'` passed to the bootstrap | `:31` |

### 2.2 Persistent data (key · type · written where · read where)

| Key | Type | Written | Read |
|---|---|---|---|
| order meta `_tracking_number_items` | **array of items** (WP-serialised) via `WC_Order::update_meta_data` → HPOS-safe, works on both storages. Item = `{tracking_provider, custom_tracking_provider, custom_tracking_link, tracking_number, date_shipped (int ts), tracking_id (md5 + microtime)}` | `class-order-tracking-actions.php:556-608` | `:641-663`; templates; list column; email |
| order meta `_tracking_number_last_send` | int (a timestamp `time()+60`) | `:406-407` | `:400` |
| option `woodev_wc_order_track_number_license_key` | string | framework settings + `includes/class-lifecycle.php:22` | `woodev/licensing/class-license-store.php:219` (`{plugin_id}_license_key`) |
| option `woodev_wc_order_track_number_license` | object (StdClass: key, status, expiry…) | `woodev/licensing/class-license-store.php:134,147` | same, `:200,235` |
| option `woodev_wc_order_track_number_beta_version`, `…_verify`, `…_deactivate` | framework | `woodev/licensing/class-plugin-license-settings.php:52-58,129,142` | framework |
| option `woodev_wc_order_track_number_version`, `…_is_active`, `…_milestone_*` | framework lifecycle | `woodev/class-lifecycle.php:130-175,381,528,563`; `woodev/class-plugin.php:1127-1129` | framework |
| option `woocommerce_order_tracking_number_settings` | array (WC email settings, key derived from email id `order_tracking_number`) | WC email settings UI (fields `class-order-tracking-email.php:129-171`: `enabled, subject, heading, tracking_message, email_type`) | `WC_Email::get_option`; `tracking_message` read at `:18` |
| legacy option `woocommerce_nomer_otslezhivaniya_zakaza_license_key` / `…_license` | string / object | deleted by `upgrade_to_1_2_0` (`includes/class-lifecycle.php:26-27`) | only the 1.2.0 migration |

**Two storage formats for `tracking_provider` coexist (READ).** The admin UI stores `wc_clean( $label )`, the **translated** carrier name (select values are `wc_clean( $provider )`, `:253`; `.po` maps `Russian Post → Почта РФ`, `CDEK → СДЭК`, `PEC → ПЭК`, `languages/…ru_RU.po:25,30,34`). The PHP API stores `sanitize_title( code )`, a slug (`includes/functions.php:27,43`). The reader accepts either (`:498`: `wc_clean( $provider ) === stored || sanitize_title( $provider ) === stored`). A v2 reader must therefore resolve BOTH an English label, a Russian label and a slug, and a provider list that is not locale-keyed will lose the name and link of existing orders. Custom providers are stored in `custom_tracking_provider` with `tracking_provider = ''`.

### 2.3 Hooks, filters, public API a merchant or another plugin may use

| Name | Kind | Where |
|---|---|---|
| `wc_order_track_number_providers_list` | filter (provider ⇒ URL format map) | `class-order-tracking-actions.php:80` |
| `send_order_tracking_number` | action `( $order_id )`, fired after EVERY tracking item is added; nothing in the plugin listens (**MEASURED** grep: 3 hits, none an `add_action` to it) | `:580` |
| `woocommerce_order_action_send_order_tracking_number` | order-action id `send_order_tracking_number` («Отправить номер отслеживания») | `:24-28,392-396` |
| `wc_otn_add_tracking_number( $order_id, $number, $provider, $date_shipped, $custom_url )`, `wc_otn_delete_tracking_number( $order_id, $number, $provider )`, `wc_order_track_number()->get_instance_actions()` (`get_tracking_items`, `add_tracking_item`, `get_formatted_tracking_item`, …) | public PHP API | `includes/functions.php`; `woocommerce-order-tracking-number.php:97-99,138-140` |
| inbound `wc_russian_post_order_tracking_number_changed( $number, $order_id )` | action consumed | `:45-48,665-673`. **Still fired by Russian Post v2.0.12** (`woodev-russian-post/includes/order/class-russian-post-order-export.php:482`), **MEASURED/READ** in the sibling repo. |
| inbound `wc_edostavka_update_tracking_code( $number, $wc_order )` | action consumed | `:50,675-683`. **CDEK v2.3.0.15 no longer fires it**: it is declared a removed hook with no replacement (`woocommerce-edostavka/includes/class-plugin.php:1276` and `:136` map it to `null`); **MEASURED** no `do_action` of it in the CDEK tree (grep excluding tests). So the v1 → CDEK v2 auto-fill is already dead. |
| Standard WC filters used: `woocommerce_email_classes`, `woocommerce_order_actions`, `woocommerce_view_order`, `woocommerce_email_before_order_table`, the four list-column hooks | core hooks | `:21-39`, `woocommerce-order-tracking-number.php:61` |

### 2.4 Surfaces with no persistent contract

| Item | Value |
|---|---|
| Email ID | `order_tracking_number` (class key `WC_Order_Tracking_Number_Email` in `WC()->mailer()->emails`, used at `class-order-tracking-actions.php:404`) |
| AJAX actions | `order_tracking_number_save_form` (nonce `create-tracking-number-item`), `order_tracking_number_delete_item` (`delete-tracking-number-item`), `order_tracking_number_get_items` (`get-tracking-number-item`), nonce field `security` (`:41-43,418,436,463`) |
| Admin element ids / handles | `wc-order-tracking-number`, `#tracking-items`, `#tracking-number-form`, `.tracking-item`, `.delete-tracking`, script handle `wc-shipment-tracking-js`, style handle `order_tracking_number_styles`, global `window.wc_order_tracking_number_items_refresh` (`:71,369`; `assets/admin.js:114`) |
| Theme-override templates | Resolved with the plugin template dir as base (`wc_order_track_number()->get_template_path()`): `emails/tracking-info.php`, `emails/plain/tracking-info.php`, `emails/admin-order-tracking-number.php`, `emails/plain/admin-order-tracking-number.php`, `myaccount/view-order.php`. **UNVERIFIED:** the exact theme-side override folder, because `get_template_path()` is defined in the old framework and I did not trace it; WC convention would be `{theme}/{path}/…` and the answer decides whether a merchant's existing theme override keeps working. Variable names handed to templates: `tracking_items` (formatted items), and for the email `order, email_heading, tracking_message, sent_to_admin, plain_text, email`. |
| CSS classes merchants may style | `shop_table shop_table_responsive my_account_tracking`, `tracking`, `tracking-provider`, `tracking-number`, `date-shipped`, `order-actions` (`templates/myaccount/view-order.php:12-37`; `templates/emails/tracking-info.php:11-31`) |
| Cron | none of its own. The old framework schedules `woodev_weekly_scheduled_events` (weekly licence check) on `wp` (`woodev/class-plugin.php:331-332,497`) |
| REST / shortcodes / blocks | none (**MEASURED**) |

## 3. Code red flags

| # | Sev. | Where and what goes wrong |
|---|---|---|
| R1 | High (product) | **Licence gate swallows data and lies to the UI.** `save_tracking_items()` returns silently without a valid licence (`class-order-tracking-actions.php:599-601`), but `add_tracking_item()` still returns the item (`:582`) and the AJAX handler prints it (`:449-451`). With an expired licence the admin sees the number "saved" and it is gone after reload. Same for the carrier hooks (`:665-683`). Under the framework's default licence-gate rule v2 must state this explicitly. |
| R2 | High (compat) | **Carrier hook handlers pass an incomplete array.** `add_russian_post_tracking_number()` / `add_cdek_tracking_number()` (`:665-683`) give `add_tracking_item()` only `tracking_provider` and `tracking_number`; it reads `$args['custom_tracking_provider']`, `['custom_tracking_link']`, `['date_shipped']` (`:560-563`) → undefined-index warnings, and `strtotime( null )` (deprecated on PHP 8.1) yields 0 so the date silently falls back to `time()` (`:565-567`). No de-duplication (`:575-576`): the Russian Post hook fires on each change of barcode, so the order can carry several rows including old barcodes. |
| R3 | Medium | **Send-throttle never throttles.** `$current_time = time() + 60` is stored as last-send (`:401,406`) and compared `$last_send < $current_time` (`:403`); at any later moment `time()+60` is greater than the stored value, so the guard is true except within the same second. Repeated clicks send repeated emails. |
| R4 | Medium (security) | **AJAX handlers check the nonce but no capability** and use unvalidated `$_POST['order_id']` (`:417-469`); a user who holds the nonce can add or delete tracking on ANY order id (`save_meta_box_ajax` writes via `wc_get_order( $order_id )`). The nonces are printed only in the admin meta box, so exposure is limited to users who can open an order screen; still `current_user_can( 'edit_shop_orders' )`/order check is missing. `meta_box_delete_tracking` never `die()`s (`:462-469`; `admin-ajax` appends `0`). |
| R5 | Medium | **Locale-bound provider key.** The select value and stored `tracking_provider` is the translated label (see §2.2). If a site changes locale, switches to a translated admin user or a provider label is edited in the filter, existing orders lose provider name and link (`:497-503`). `wc_otn_delete_tracking_number()` with a provider compares a slug with the stored label (`includes/functions.php:73`) and never matches items created in the UI. |
| R6 | Medium | **Tracking table is appended to every email, admin emails included.** `email_display()` ignores `$sent_to_admin` (`:184-194`), so the New order admin email shows it; and the plugin's own tracking email fires `woocommerce_email_order_details` (`templates/emails/admin-order-tracking-number.php:16`) which, as in WC core, runs the same before-table hook, so the customer sees the table in addition to `{tracking_code}`. **UNVERIFIED** for the second effect (WC core behaviour, not run here). |
| R7 | Medium | **Hard-coded Russian strings** everywhere outside the templates (see §1 i18n); an English or Ukrainian shop gets a Russian admin and Russian default emails. |
| R8 | Low | `sprintf()` with a provider-filtered format (`:513`) raises `ArgumentCountError`/`ValueError` on PHP 8 if a merchant's URL has `%5$s`, a bare `%`, or fewer than the 4 supplied arguments is expected; the filter is a public contract. |
| R9 | Low | Plain template `templates/emails/plain/tracking-info.php:9` prints the heading «Order details» instead of «Delivery info»; the HTML email's link cell always prints an anchor, even with an empty URL for a custom provider (`templates/emails/tracking-info.php:31`, unlike `view-order.php:34`). |
| R10 | Low | `<time datetime="d.m.Y">` is not a valid ISO datetime (`templates/myaccount/view-order.php:31`, `templates/emails/tracking-info.php:28`); `date()` uses the server timezone, `current_time( 'timestamp' )` is deprecated (`class-order-tracking-actions.php:303`; `includes/functions.php:17`). |
| R11 | Low | Admin script/style hygiene: the CSS is enqueued on every admin page via `admin_print_styles` with no screen check (`:20,70-72`); the JS is enqueued with no dependencies and no version (`:369`); `$( '#tracking_provider' ).selectedIndex = 0` is a no-op on a jQuery object, so the provider stays selected after saving (`assets/admin.js:44`); `date_shipped` is cleared to an empty string after save. |
| R12 | Low | `save_meta_box()` reads `$_POST['tracking_provider']`, `…custom_tracking_provider`, `…custom_tracking_link`, `…date_shipped` without `isset` (`:378-386`): notices if the form is partial (WC bulk/REST-triggered saves keep `tracking_number` empty so the branch is skipped; low). |
| R13 | Info | **HPOS: OK.** Order data goes only through `WC_Order` meta API (`:606,649`); list columns and meta box cover both storages. **Blocks: not applicable.** Cart/Checkout flags are declared with no surface behind them. **WC block-based email templates (WC 9.8+ email editor): UNVERIFIED**, not run. |
| R14 | Info | No uninstall cleanup; also no data export/erasure hook for tracking numbers (they are order meta, covered by WC's own order erasure). |

## 4. Size

**MEASURED** (`wc -l`), own code only, excluding `woodev/`:

| Group | Files | Lines |
|---|---|---|
| PHP | `woocommerce-order-tracking-number.php` 142, `includes/class-order-tracking-actions.php` 683, `class-order-tracking-email.php` 189, `trait-order-util.php` 96, `functions.php` 80, `class-lifecycle.php` 29 | **1 219** (6 files) |
| Templates | 5 files: `emails/tracking-info.php` 39, `emails/plain/tracking-info.php` 23, `emails/admin-order-tracking-number.php` 24, `emails/plain/admin-order-tracking-number.php` 27, `myaccount/view-order.php` 44 | **157** |
| JS / CSS | `assets/admin.js` 115, `assets/admin.css` 64 | **179** |
| i18n | `.pot` 99, `…ru_RU.po` 99, `.mo` | 198 lines text |
| PHP + templates total | 11 files | **1 376** |
| Bundled old framework | 102 PHP files in `woodev/` | not counted line-by-line (framework 1.3.3) |

The plugin is small; the real mass sits in the bundled framework. Almost all of its logic is in `class-order-tracking-actions.php` (683 lines), of which about 260 lines are the meta box HTML + inline JS.

## 5. Candidate fate per surface (not decisions)

| Surface | Candidate | Reasoning |
|---|---|---|
| `_tracking_number_items` meta format | **Keep, read-compatible forever** | Every installed order carries it; the v2 reader must accept label, translated label and slug providers (§2.2). New writes may stay in the same shape. |
| Plugin ID / EDD item 2653 / licence option keys | **Keep / migrate** | Without them the licence and the updater do not follow the site to v2. Pattern: the T-Bank rewrite and `upgrade_to_1_2_0`. |
| Manual meta box + AJAX | **Keep (rebuild on v2 framework admin)** | The core of the product; fix R4/R11 and remove inline JS. |
| Order list column | **Keep** | Cheap; HPOS + legacy both. |
| Provider table (9 carriers) | **Move to framework / carrier registry; merge with the carrier plugins** | The four carrier plugins already know their own tracking URL and number; a framework-level registry would leave v1's static list as the fallback for "other" carriers. Needs operator choice (Q2). |
| Russian Post / CDEK auto-fill hooks | **Replace** | CDEK v2 already dropped its hook (§2.3); Russian Post v2.0.12 still fires one. Better: tracking reads the number from the carrier plugin's own order data through a framework shipping-order contract. Needs Q3. |
| Own customer email + manual order action | **Keep; consider automatic send** | The email id and settings option must survive; auto-send on number change would be new behaviour (Q4). Fix R3. |
| Tracking table in every email | **Keep, restrict** | Skip admin emails, avoid duplication in the own email (R6). |
| My Account view-order table | **Keep** | The only buyer-facing surface; keep CSS classes (§2.4) and the theme override path (verify, §2.4). |
| PHP API `wc_otn_*` / `send_order_tracking_number` / provider filter | **Keep thin aliases** | Third-party code may use them; no way to find out from source (Q5). |
| Carrier status fetching | **New capability, not v1 data** | The carrier plugins already pull statuses; the tracking plugin could show them (Q6). |
| Hard-coded Russian strings | **Rebuild i18n** | Fix R7 as part of the rewrite. |
| Blocks / HPOS flags | **Keep HPOS; drop the empty blocks declaration** | No checkout surface. |
| Bundled old framework | **Drop** | Replaced by v2; the v1/v2 coexistence limit (issue #1389) is the reason this plugin is on the critical path. |

**Questions for the operator**

- Q1. Do you want the buyer-facing marketing promises on https://woodev.ru/downloads/shipment-tracking checked against this inventory? I did not fetch the page; a feature advertised there but absent from the code would be a contract too.
- Q2. Should the tracking plugin keep its own static carrier list, or take tracking links from a framework-level registry fed by the carrier plugins?
- Q3. Should the tracking plugin hard-depend on the carrier plugins (reads their data) or stay standalone and only accept hooks?
- Q4. Should the email be sent automatically when a number appears, or stay manual?
- Q5. Do you know of merchants or the support tickets that use `wc_otn_*`, `send_order_tracking_number` or theme overrides of the five templates? (not discoverable from the code)
- Q6. Should the delivery status from the carrier plugins be shown next to the number (new feature, scope)?
