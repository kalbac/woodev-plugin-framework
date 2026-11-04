# «Отслеживание доставки» 2.0.0 on Framework v2 — design

**Date:** 2026-11-04 (s214). **Status:** draft for the coordinator and a Codex critic.
**Target:** release `2.0.0` of the existing plugin «WooCommerce номер отслеживания заказа» (folder and main file
`woocommerce-order-tracking-number`, plugin id `wc_order_track_number`, EDD item `2653`, price 3 000 ₽) in the private repo
`kalbac/woocommerce-order-tracking-number` (empty, created 04.11.2026), rewritten on Framework v2. Cards go to framework
board №6 (no separate board).
**Why it is small:** the plugin stores a number and builds a link; it never asks a carrier anything (decision 4). It is very
likely the last major update of this plugin (decision 10), so every section prefers the smallest design that keeps the
installed sites working.

## Revision

Critic round 1 (Codex, spec-review-r1.md) applied 04.11.2026: auto-send no longer skips a whole carrier-owned order (§4.3, §7, §3.4, §8, P2, P7).

**Evidence labels:** **READ** (code or document), **UNVERIFIED** (reason given), **Provisional (coordinator may revisit)** —
a gap in the inputs this spec closed itself; all of them are listed again in §10.2.
**Inputs, cited as:** `A §n` = [a-v1-inventory.md](../research/2026-11-03-tracking-v2/a-v1-inventory.md); `C2 §n` =
[c2-source-check.md](../research/2026-11-03-tracking-v2/c2-source-check.md) (authoritative; `c-framework-map.md` is
superseded where C2 corrects it); `D §n` = [d-v1-buyer-tracking.md](../research/2026-11-03-tracking-v2/d-v1-buyer-tracking.md);
`E` = [e-carrier-links.md](../research/2026-11-03-tracking-v2/e-carrier-links.md); `Agenda` =
[brainstorm-agenda.ru.md](../research/2026-11-03-tracking-v2/brainstorm-agenda.ru.md). `v1:` = the plain directory
`/Users/maksimmartirosov/Projects/legacy-v1/woocommerce-order-tracking-number/` (1.3.0). Shape reference:
[2026-10-27-tbank-v2-plugin-design.md](2026-10-27-tbank-v2-plugin-design.md).

## 0. How to read this spec

§1 maps v1 to v2 and fixes the installed-site contract. §2 is the data model (the part that must not break). §3 admin, §4 buyer
surfaces, §5 settings, §6 the carrier list, §7 the coexistence matrix with the framework card **#1575**, §8 tests, §9 the waves,
§10 open questions and the Provisional register.

**#1575 is a separate framework change** (the framework shows tracking to the buyer — My Account order view, maybe the
thank-you page — for orders of our four v2 carriers). This plugin must NOT print carrier tracking for carrier-owned orders and
must behave correctly whether #1575 has shipped or not (§7).

### 0.1 Binding decisions (Agenda → «Решения»; nothing below reopens them)

| # | Decision (operator, 03–04.11.2026) | Lands in |
|---|---|---|
| 1 | Manual number for any carrier + one place for the tracking of OUR carriers; the buyer view of OUR carriers' tracking is framework work (#1575); this plugin does not duplicate it — its zone is manual numbers and its own email | §2.3, §4, §7 |
| 2 | Own tracking table is not inserted into `customer_shipment_*`, `wc_russian_post_tracking_email` or admin emails; in other order emails — manual numbers only | §4.2 |
| 3 | Own email goes automatically when a NEW manual number is added (setting «Отправлять при добавлении номера», default on); each number once; the order action stays for a resend; for orders of our carriers the framework sends the email | §4.3 |
| 4 | No statuses and no carrier calls in the plugin; a number is added by hand or "pulled" from our plugins | §2.3 |
| 5 | Carrier list refreshed after a browser check of every URL; dead ones leave the choice for NEW orders, old orders keep name and old URL format; Yandex Delivery and Ozon added; own carriers in settings (name + link template); manual field in the order stays; filter `wc_order_track_number_providers_list` kept | §6 |
| 6 | Without a licence saved numbers are always shown (account, emails); adding a number and sending an email are impossible — a clear message and a renewal link instead of the form; numbers of our carriers do not depend on this plugin's licence | §3.5 |
| 7 | Kept byte for byte: `_tracking_number_items` (read both label and code), email id `order_tracking_number` and its settings, templates and theme overrides, CSS classes, folder/file, plugin id `wc_order_track_number`, licence and EDD 2653, `wc_otn_*` functions, provider-list filter, text domain `woocommerce-tracking-number` | §1.2 |
| 8 | Settings on `woodev-settings`: own carriers, auto-send switch, licence; email subject/heading/text stay in WooCommerce → Email (link «Настроить текст письма»); no output switches — a number is always shown | §5 |
| 9 | Version 2.0.0, price 3 000 ₽ | header |
| 10 | No migration screen, no wizard, no blog/Telegram; docs only on the product card (one doc page at most); probably the last major update; no separate board | §9 |

Operator rules that bind every section (project and memory rules): the licence gate is the default for every plugin; no
invented safeguards (KISS — ask «which real case?» before adding protection); merchant diagnostics only behind debug logging;
copy is for non-IT merchants (never «фреймворк», «чекаут»); every string translatable, text domain unchanged.

## 1. v1 → v2 surface map

### 1.1 Keep / change / drop

| Surface (A §1) | v2 | Note |
|---|---|---|
| Manual entry: meta box, AJAX save/delete/get, plain «Update» save | **Keep, rebuild** | Same ids and action names; capability + nonce + per-order check (R4); no inline JS (R11); §3 |
| PHP API `wc_otn_add_tracking_number`, `wc_otn_delete_tracking_number`, `get_instance_actions()` methods, action `send_order_tracking_number` | **Keep** | Behaviour fixes only: provider matching (R5), refusal without a licence is explicit (R1), §2.2 |
| Orders list column `tracking_number` | **Keep** | Classic and HPOS; also shows the number pulled from our carrier (§3.3) |
| Order action «Отправить номер отслеживания» (`send_order_tracking_number`) | **Keep** | Resend; the broken throttle is dropped (R3, §4.3) |
| Own email `order_tracking_number` | **Keep + automatic send** | §4.3 |
| Tracking table in WC emails (`woocommerce_email_before_order_table`, priority 0) | **Change** | Skip list and manual-only rule (decision 2, R6), §4.2 |
| My Account order view table (`woocommerce_view_order`) | **Keep** | Manual numbers only on carrier-owned orders (§4.1) |
| Built-in provider list (9) | **Change** | Data file refreshed after a browser check; old entries kept read-only for old orders (§6) |
| «Custom provider» fields in the order (name + free link) | **Keep** | Also the storage shape for the settings' own carriers (§6.3) |
| Inbound hooks `wc_russian_post_order_tracking_number_changed`, `wc_edostavka_update_tracking_code` | **Drop** | CDEK's is dead (A §2.3, C2 §1); Russian Post's number is now read straight from its order meta (§2.3) — copying it would create the duplicate that decision 2 forbids. Release-note item |
| Bundled old framework, `upgrade_to_1_2_0` legacy-key carry | **Replace / keep** | Framework v2 loader; the licence carry is ten lines, ported (Provisional P11) |
| Hard-coded Russian strings (R7) | **Change** | English msgids + complete `ru_RU` catalogue, same text domain |
| Empty `blocks` feature flag | **Drop** | `supported_features: hpos` only (A §1) |
| Carrier status fetching, statuses, migration screen, wizard, REST, shortcodes | **Not built** | Decisions 4 and 10 |

### 1.2 Installed-site contract (A §2, preserved exactly)

| Group | Kept value |
|---|---|
| Identity | folder/main file `woocommerce-order-tracking-number/woocommerce-order-tracking-number.php`; `Plugin Name: WooCommerce номер отслеживания заказа`; text domain `woocommerce-tracking-number`; plugin id `wc_order_track_number`; EDD item `2653`; sales page `https://woodev.ru/downloads/shipment-tracking/`, support `https://woodev.ru/support/` |
| Globals | constants `WD_ORDER_TRACKING_NUMBER_VERSION`, `WD_ORDER_TRACKING_NUMBER_FILE`; functions `wc_order_track_number()`, `wc_otn_add_tracking_number()`, `wc_otn_delete_tracking_number()`; classes `WC_Order_Tracking_Number`, `WC_Order_Tracking_Number_Actions`, `WC_Order_Tracking_Number_Email` (key in `WC()->mailer()->emails`), `WC_Order_Tracking_Lifecycle`. The internal init function `init_wc_order_track_number_init()` may be renamed (callback of the v2 loader) — nothing external can call it before the plugin loads |
| Order meta | `_tracking_number_items` (array of items, §2.1); `_tracking_number_last_send` is no longer written or read (§2.2) |
| Options | `woodev_wc_order_track_number_license_key`, `…_license`, `…_beta_version`, `…_verify`, `…_deactivate`, `…_version`, `…_is_active`, `…_milestone_*` (framework-derived from the plugin id — kept by keeping the id); `woocommerce_order_tracking_number_settings` (keys `enabled`, `subject`, `heading`, `tracking_message`, `email_type`) |
| Hooks and API | filter `wc_order_track_number_providers_list`; action `send_order_tracking_number( $order_id )` fired after every added item; order-action id `send_order_tracking_number`; `get_instance_actions()` methods `get_tracking_items`, `get_tracking_item`, `add_tracking_item`, `delete_tracking_item`, `get_formatted_tracking_item`, `get_providers` (signatures of A §2.3) |
| Email | id `order_tracking_number`; title «Уведомление номера отслеживания доставки»; placeholders `{order_number}`, `{date}` (the SEND date), `{tracking_code}`; default subject `[Site] Ваш заказ передан в курьерскую службу`; default body «Ваш заказ {order_number} был отправлен курьерской службой {date}. {tracking_code}» |
| AJAX | actions `order_tracking_number_save_form` (nonce action `create-tracking-number-item`), `order_tracking_number_delete_item` (`delete-tracking-number-item`), `order_tracking_number_get_items` (`get-tracking-number-item`); nonce field `security` |
| Admin elements | meta box id `wc-order-tracking-number` titled «Отслеживание посылки» (side); `#tracking-items`, `#tracking-number-form`, `.tracking-item`, `.delete-tracking`; script handle `wc-shipment-tracking-js`; style handle `order_tracking_number_styles`; global `window.wc_order_tracking_number_items_refresh` |
| Templates (relative names under `{plugin}/templates/`) | `emails/tracking-info.php`, `emails/plain/tracking-info.php`, `emails/admin-order-tracking-number.php`, `emails/plain/admin-order-tracking-number.php`, `myaccount/view-order.php`; variables `tracking_items` and, for the email, `order, email_heading, tracking_message, sent_to_admin, plain_text, email`; item keys read by templates: `formatted_tracking_provider`, `formatted_tracking_link`, `tracking_number`, `date_shipped` (int timestamp), `tracking_id`, `custom_tracking_*`, `tracking_provider` |
| CSS classes | `shop_table shop_table_responsive my_account_tracking`, `tracking`, `tracking-provider`, `tracking-number`, `date-shipped`, `order-actions` |

**Theme override path — resolved (closes A §2.4 UNVERIFIED).** READ: every v1 call is
`wc_get_template( $name, $args, '', wc_order_track_number()->get_template_path() )` (`v1:includes/class-order-tracking-actions.php:190-200`;
the email sets `$this->template_base` to the same path, `v1:includes/class-order-tracking-email.php:22`) and the old framework's
`get_template_path()` returns `get_plugin_path() . '/templates'` (`v1:woodev/class-plugin.php:1333-1339`). The third argument
(`$template_path`) is the empty string, so WooCommerce falls back to its own theme folder: a merchant's override lives at
**`{theme}/woocommerce/<relative name above>`** (e.g. `{theme}/woocommerce/myaccount/view-order.php`). That last step is WC core
behaviour — **UNVERIFIED** here (core not in the repo); W3 proves it on the rig with a test theme override of each of the five
files, and v2 must call `wc_get_template` with the same empty third argument so the lookup does not move.

## 2. Data model

### 2.1 `_tracking_number_items` — read both, write one

Item shape (A §2.2): `{ tracking_provider, custom_tracking_provider, custom_tracking_link, tracking_number, date_shipped (int ts),
tracking_id (md5 + microtime) }`. **The shape is not extended**; unknown keys written by anyone else are preserved on rewrite.

**Reading — a provider is resolved from the stored `tracking_provider` by this order, first hit wins:** (1) the provider `id`
(slug, as the PHP API wrote it: `sanitize_title( code )`); (2) the v1 English key as shipped (`Russian Post`, `CDEK`, …);
(3) the v1 Russian label as the admin UI wrote it (`Почта РФ`, `СДЭК`, `ПЭК`, … from `languages/woocommerce-tracking-number-ru_RU.po`);
(4) a label in the merchant-filtered list (`wc_order_track_number_providers_list`). The match is on the normalised string
(`wc_clean`, case-insensitive, whitespace-trimmed) and NOT on the active locale, so a site that switches locale keeps names and
links (R5). `tracking_provider === ''` means a custom item: name and link come from `custom_tracking_*` and nothing else
(link empty → no anchor, R9). An item whose provider matches nothing keeps its stored string as the name and has no link
(never an exception).

**Writing.** A built-in provider is stored by **`id` (slug)** — the PHP-API format — instead of the translated label the v1 UI
wrote (R5 fix). A custom or own carrier is stored as a custom item (§6.3). `date_shipped` is an int timestamp from
`current_time( 'timestamp', true )`-equivalent UTC handling (R10); `tracking_id` is generated as in v1. Duplicate rule (R2):
adding a number that already exists on the order with the same provider is a no-op that returns the existing item. All writes
go through `WC_Order::update_meta_data` + `save()` (HPOS-safe, A §2.2).

**Return values.** `add_tracking_item()` returns the item array on success and **`false` when nothing was saved** (no licence, not
a valid order, empty number) — v1 returned the item even when `save_tracking_items()` had silently refused (R1). `wc_otn_add_tracking_number()`
stays void. Provisional P8.

### 2.2 The per-number «auto-sent» marker (replaces `_tracking_number_last_send`)

New order meta **`_tracking_number_emailed`**: an array `tracking_id => unix timestamp` (UTC). Rules:

- Written by the auto-sender **before** the send (claim-then-send, as the framework's `_woodev_delivery_email_*` markers, C2 §2), so a double submit (AJAX save + order «Update» in one request, a retry) cannot send twice.
- Removed key when the item is deleted (kept compact); a marker whose item is gone is ignored.
- The resend order action does not read it: it is an explicit act and sends every manual item.
- `_tracking_number_last_send` (A §2.2, R3) is neither written nor read; existing values are left on the order.
- Existing items on upgraded shops have no marker and **never get one by reading** — auto-send runs only from an add path (§4.3), so no backfill and no mail storm after the update.

Why a separate meta and not a field in the item: it keeps the contract of §1.2 byte-identical (decision 7) and a third-party
reader of `_tracking_number_items` never sees a changed shape. Provisional P5.

### 2.3 Numbers "pulled" from our carriers — read live, never copied

`Carrier_Tracking_Reader::for_order( $order ): ?array` returns `[ provider_label, number, url ]` or `null`:

```php
$registry = '\Woodev\Framework\Shipping\Admin\Orders\Orders_Registry';
$provider = class_exists( $registry ) ? $registry::instance()->resolve_provider_for_order( $order ) : null; // C2 §2, §4
$key      = $provider ? $provider->get_tracking_meta_key() : null;
$number   = $key ? (string) \Woodev_Order_Compatibility::get_order_meta( $order, $key ) : '';
$url      = $provider ? $provider->resolve_tracking_url( $order ) : null;
```

- **Carrier ownership = the provider marker** (`resolve_provider_for_order() !== null`), not "a tracking number exists" (C2 §4, correction 9): the number can arrive after the marker (CDEK cash on delivery) or not exist as a key (Ozon).
- **Not copied into `_tracking_number_items`.** Reasons: the number changes at the carrier plugin's side (Russian Post re-issues the barcode, CDEK numbers COD orders late) and a copy goes stale or duplicates (R2); nothing in the four plugins fires a number event except Russian Post's export-only hook (C2 §1), so a copy cannot be kept in sync without polling — which decision 4 forbids. The cost: the pulled number lives in the carrier plugin's meta and is shown through the reader in the admin (§3) and nowhere to the buyer. Provisional P1.
- **Ozon has no `tracking_meta_key`** (C2 §1, correction 2): `$number` is empty; the admin shows the carrier label and the line «Номер смотрите в панели заказа Ozon Доставки» without a number.
- Yandex's "number" is the request id and its useful link is the per-order sharing URL; the reader returns whatever `resolve_tracking_url()` gives and does not interpret it.
- Everything is guarded: framework shipping classes absent, no carrier plugin active, registry empty → `null`. The provider list of the registry is filled by the carrier plugins, so the reader is called lazily (render time, never on `init`) — whether providers are registered before `admin_init`/`wp` is **UNVERIFIED** (C2 did not trace `Orders_Registry` registration timing); W1 checks it on the rig.
- **Same number typed by hand.** On a carrier-owned order a manual item whose `tracking_number` equals the pulled number (trimmed, case-insensitive) is the same parcel: it is hidden on buyer surfaces (the framework shows it) and not auto-mailed — this is the only auto-send exclusion tied to carrier ownership. Provisional P2.

## 3. Admin

### 3.1 Order meta box (A §1)

Kept: id `wc-order-tracking-number`, title, side context, classic and HPOS order screens (screen id from the HPOS-aware
helper, as `v1:includes/trait-order-util.php:75-81`). Content, top to bottom:

1. **Pulled row** (only when `Carrier_Tracking_Reader::for_order()` is not null): read-only line `«Передано плагином {label}: {number} — ссылка»`, no edit/delete, plus for Ozon the §2.3 line. Never offers a form for the carrier's number.
2. **Manual items** (`#tracking-items`, `.tracking-item`, `.delete-tracking`) with a small «письмо отправлено {date}» note from the §2.2 marker.
3. **Form** (`#tracking-number-form`): provider select — built-in active providers, then the merchant's own carriers (§5), then «Производная служба доставки» (custom: name + link fields shown as in v1); tracking number; shipped date; live link preview from the chosen provider's format. No field for the old "postcode/country/order id" arguments (v1 never used them in any built-in format, E «Input correction»).

Assets: `assets/js/admin.js` (handle `wc-shipment-tracking-js`, dependency `jquery`, version = plugin version, global
`window.wc_order_tracking_number_items_refresh` kept) and `assets/css/admin.css` (handle `order_tracking_number_styles`),
enqueued **only on the order edit screens** (R11); the post-save form reset is fixed (`.val('')`/`prop('selectedIndex', 0)`).

### 3.2 Saving and AJAX

Three paths, one writer (`Items_Store`): the AJAX save, the plain order «Update» (`woocommerce_process_shop_order_meta`, priority 0 —
kept) and the PHP API. Each path: (1) `check_ajax_referer`/nonce with the v1 action names of §1.2; (2) **`current_user_can( 'edit_shop_orders' )`**
and the order must load with `wc_get_order( absint( $_POST['order_id'] ) )` (R4); (3) every `$_POST` read through `isset` + `wp_unslash` +
`wc_clean`/`esc_url_raw` (R12); (4) the licence gate (§3.5); (5) the number is trimmed, 1–128 characters, no control characters — nothing else
is invented. Responses: JSON `{ success, data: { html } }` or `wp_send_json_error( [ 'message' => … ], 403|400 )`; every handler ends in
`wp_send_json_*` (R4 "never `die()`s"). After a successful add, `do_action( 'send_order_tracking_number', $order_id )` fires as in v1 and the
internal `wc_order_track_number_item_added( $order, $item )` is what the auto-sender listens to (§4.3).

### 3.3 Orders list column

Key `tracking_number`, header «Номер отслеживания», both hooks pairs (legacy `manage_shop_order_posts_columns`/`…_custom_column`,
HPOS `manage_woocommerce_page_wc-orders_columns`/`…_custom_column`). Output: a `<ul>` of linked manual items (`wp_kses_post`, as v1), then the
pulled number of our carrier as the last entry (text + link when `$url`). Admin-only, so showing both is not the duplicate that decision 2
forbids. Provisional P9.

### 3.4 Order action

`send_order_tracking_number` («Отправить номер отслеживания») is registered through `woocommerce_order_actions` **only** when the order has at least
one manual item and the licence is in force. It sends the own email for **all manual items** of the order, adds the v1 order note «Уведомление
с номером отслеживания было отправлено покупателю.», and has **no throttle** (R3: v1's guard never worked; an admin pressing a button twice
is a deliberate act and each press leaves a note — no invented safeguard). On an order of our carrier it sends the manual items other than the pulled number
(§7, Provisional P7).

### 3.5 Licence-off state (decision 6, R1)

`License_Gate::is_in_force( wc_order_track_number() )` (`Woodev\Framework\Shipping\License_Gate`, autoloadable through the class map for any
plugin, C2 §2; the shipping namespace is the known wart F1 of the T-Bank spec — Provisional P12). Not in force:

| Surface | Behaviour |
|---|---|
| Meta box | The items list stays visible; the form is replaced by a notice «Лицензия плагина не действует — добавить номер нельзя. Продлить лицензию» linking to `License_Gate::page_url()`/the renewal URL of the framework licence screen; **delete stays allowed** (it adds no new action; Provisional P10) |
| AJAX save, plain «Update» save, `wc_otn_add_tracking_number()` | Nothing is written; AJAX answers 403 with the same message; the PHP path returns `false` and logs once to the plugin log (debug mode only) |
| Order action, auto-send | Not offered / not run |
| Buyer account table, emails with the table, own email already sent | **Unaffected** — saved numbers are always shown |
| Numbers of our carriers | Independent of this plugin's licence (the reader does not call the gate) |

## 4. Buyer surfaces

### 4.1 My Account order view

`woocommerce_view_order`, priority 10 (kept), template `myaccount/view-order.php` with `tracking_items` = formatted **manual** items only;
the template prints nothing when the list is empty. On a carrier-owned order the items equal to the pulled number are removed (§2.3).
The plugin never prints a pulled carrier number. Not on the thank-you page (v1 never did; #1575 may). Template body fixes (R9, R10): valid
`datetime="Y-m-d"` from `wp_date`, `target="_blank" rel="noopener"`, no inline `style=` (a few lines go to a frontend CSS file only if the markup needs them —
none required), same classes (§1.2). Merchants' old theme overrides keep rendering because the variables and item keys do not change.

### 4.2 Tracking table in WooCommerce emails (decision 2, R6)

Hook `woocommerce_email_before_order_table`, priority 0 (kept), callback `Email_Table_Policy::render( $order, $sent_to_admin, $plain_text, $email )`.
The table is **skipped** when any of these holds, checked in this order:

1. `$sent_to_admin` is true (fixes the admin "New order" email — R6).
2. `$email` is a `WC_Email_Customer_Refunded_Order` (v1 behaviour).
3. `$email->id` is `order_tracking_number` (the own email carries the numbers in `{tracking_code}`; the table would double them — R6 second effect, UNVERIFIED in core but harmless to guard).
4. `$email->id` starts with `customer_shipment_` (framework `customer_shipment_created|pickup|delivered|exception`, C2 §2) or equals `wc_russian_post_tracking_email` (C2 §1).

Otherwise it prints `emails/tracking-info.php` / `emails/plain/tracking-info.php` with the **manual** items (carrier-owned orders: after the §2.3 dedupe). The id list is
one class constant. Plain-text heading fixed to «Delivery info» (R9).

### 4.3 Own email and the automatic send (decision 3)

`WC_Order_Tracking_Number_Email` keeps id, option key, fields (`enabled, subject, heading, tracking_message, email_type`), placeholders, template names
(`emails/admin-order-tracking-number.php` + plain) and default texts — now translatable (R7). Its description text is updated: the email goes
automatically when a number is added (if the setting is on) and by the button. `trigger( $order_id, $items = null )` takes an optional item subset
(default: all manual items) so the auto-send mails only the new number.

**Auto-sender** (`Auto_Sender`), listening on `wc_order_track_number_item_added`, sends when ALL of: the plugin setting «Отправлять при добавлении номера» is on (default **on**,
§5); the licence is in force; the WC email `order_tracking_number` is enabled; the order has a valid billing email; the item is **not the pulled carrier number** (on a carrier-owned order the framework already mails that one, decision 3; any OTHER new manual number on such an order is mailed like on any order); the item has no
`_tracking_number_emailed` marker. It claims the marker, then sends. One request that adds several numbers sends **one** email listing exactly those
numbers (Provisional P6). Nothing is sent on read, on plugin update, or for items that existed before 2.0.0. The own email therefore never
duplicates a framework email: only the item equal to the pulled number is excluded, and it lists manual numbers only (the pulled one is never in it).

## 5. Settings page (`woodev-settings`, decision 8)

The plugin overrides `get_settings_providers()` (C2 §3) and returns one `Settings_Provider` (built with `Settings_Provider::create()`; handler =
a `\Woodev_Abstract_Settings` subclass `Tracking_Settings`; no legacy option to import — v1 had no settings, A §1). Capability `manage_woocommerce`.
The base class is `\Woodev\Framework\Woocommerce_Plugin` (confirmed, C2 §3); the only complete provider fixture extends `\Woodev_Plugin`, so W2 starts by
checking that the pattern works on `Woocommerce_Plugin` and which field types exist.

| Section | Field | Type | Default | Notes |
|---|---|---|---|---|
| Службы доставки | `own_carriers` | list of rows `{ id, name, link_template }` | empty | Name required; template must contain `{tracking}` (also `{order_id}` allowed) **or** be a plain link (form-only carrier, §6.4); `id` generated once (`own-` + short random), stable across renames. If the settings API has no repeater field type, fall back to a textarea with one `Название | https://…{tracking}` per line (Provisional P4) |
| Письмо покупателю | `auto_send` | checkbox | **on** | «Отправлять при добавлении номера». Option stored under the settings handler's own key (no v1 key to collide with) |
| Письмо покупателю | (link) | static | — | «Настроить текст письма» → `admin.php?page=wc-settings&tab=email&section=wc_order_tracking_number_email` (lower-cased class name — the WC convention; W3 verifies on the rig) |
| Лицензия | licence key/status | framework licence section | — | Whatever the framework provides for a plugin with a download id (A §1 "Licence"); no custom licence UI. If the framework shows it on its own page, this section is a link to it |

No "show in account/emails" switches (decision 8). No wizard (decision 10).

## 6. Carrier provider model

### 6.1 Data

`includes/data/providers.php` returns a list of provider records (plain arrays):

```php
[ 'id' => 'russian-post', 'label' => 'Почта РФ', 'url' => 'https://www.pochta.ru/tracking?barcode=%1$s',
  'status' => 'active',                 // active | legacy
  'aliases' => [ 'Russian Post', 'Почта РФ' ] ]   // every stored string v1 could have written (§2.1 steps 2-3)
```

- `status: active` — offered in the select for new orders. `status: legacy` — **never offered**, still resolved when reading (name + the old URL format, so old orders look exactly as before); a legacy record keeps its old `url` even when the site is dead — a dead link on an old order is the merchant's old data, not a crash.
- `url` is the v1 format: `sprintf( $format, $number, urlencode( $postcode ), $country, $order_id )`; only `%1$s` is used in every built-in format (E "Input correction"), the other three arguments stay supplied so a merchant filter that uses them keeps working. The call is wrapped so that a bad merchant format (`%5$s`, bare `%`) yields an empty link and a debug-log line instead of `ArgumentCountError` (R8).
- **Filter `wc_order_track_number_providers_list`** keeps its v1 contract: `array label => url-format`, applied to the list of ACTIVE providers; labels added through it behave as v1 (selectable, stored as the label, matched by §2.1 step 4). The catalogue builds that map from the data file, applies the filter, and uses the result.
- `get_providers()` (public, §1.2) returns that same map.

### 6.2 The final list is data, decided by the link check (decision 5)

The 9 v1 records are `legacy` or `active` according to the check below; **this spec does not decide them**. Operator remarks (04.11.2026), which the check starts from: Shiptor is long gone; SberLogistics is ex-Shiptor and may be gone; Boxberry is now Yandex (drop); PEC and TC Energy uncertain — verify. E's table is the starting point (E: only Russian Post and CDEK answered in a sandbox and both are SPA pages; PEC, Shiptor, SberLogistics, TC Energy are **UNVERIFIED** — unreachable from the sandbox; Delovie linii answers 401 to a GET; DPD answers a stub; Boxberry's old URL no longer takes the number).

**Link check task (W4a, coordinator's browser, not a worker's sandbox).** For each of the 9 v1 URLs and each candidate (Yandex Delivery, Ozon `tracking.ozon-dostavka.ru`, others with a public number-in-URL page): open in a real browser with a real number where one exists (Russian Post, CDEK, Yandex and Ozon from our test accounts; for others a syntactically valid number is enough to see whether the page takes the number or returns "page gone"). Verdict per record, written to `docs-internal/research/` of the plugin repo as a table (URL, date, what the page showed, verdict):

| Observed | Verdict |
|---|---|
| Page opens and takes the number (result or a "not found" for a fake number) | `active` |
| Page opens but ignores the number (a form) | `active` as **form-only** (§6.4) |
| Dead, redirected to an unrelated page, company gone | `legacy` |
| Cannot be established twice | `legacy` (old orders unaffected); revisit on a merchant ticket |

The result replaces `providers.php` statuses and adds Yandex Delivery and Ozon; nothing else in the code depends on the outcome.

### 6.3 Own carriers → stored as a custom item

Choosing an own carrier in the meta box does **not** invent a new item shape: at add time the plugin builds the link from the template (`{tracking}` → `rawurlencode( $number )`) and writes a normal **custom item** (`tracking_provider = ''`, `custom_tracking_provider = name`, `custom_tracking_link = built link`). Consequences: read-compat is automatic (§2.1); renaming or deleting the own carrier later does not change or break old orders (a snapshot, like every v1 custom item); editing the template affects only new numbers. Provisional P3.

### 6.4 Form-only carriers

A record or own carrier whose `url` has no `%1$s`/`{tracking}` is a plain link to the carrier's tracking page; the buyer's button opens it and the number is shown beside it. Allowed for own carriers and for `active` built-ins that need it. Provisional P13.

## 7. Coexistence matrix

Cases: **carrier** = the order is owned by one of our four carriers (`resolve_provider_for_order() !== null`); **manual** = at least one manual item that is not equal to the pulled number. "FW email" = framework `customer_shipment_*` or `wc_russian_post_tracking_email`. Licence in force unless stated.

| Order | #1575 | My Account order view | FW emails | Other customer emails | Own email | Admin (meta box, column) |
|---|---|---|---|---|---|---|
| manual only | not shipped | our table | n/a | our table (manual) | **auto on add**, button | manual items |
| manual only | shipped | our table | n/a | our table | same | same |
| carrier only | not shipped | **nothing** (unchanged from today — no v2 carrier prints tracking there, C2 §2) | number via placeholders | nothing from us | none; the framework mail carries the number | pulled row + column entry |
| carrier only | shipped | framework block only | same | nothing from us | none | same |
| carrier + manual (different number) | not shipped | our table (manual) | no table from us | our table (manual only) | **auto on add** (the distinct manual number only; FW mails the carrier's); button resends manual items | pulled row + manual items |
| carrier + manual | shipped | framework block (carrier) **and** our table (manual) — two different numbers, no overlap | no table from us | manual only | same | same |
| carrier + manual (**same** number) | either | framework block only if shipped; our table hides the duplicate | no table | nothing from us | none | pulled row; the manual item stays visible with a «совпадает с номером службы» note |
| any, licence off | either | saved numbers still shown | same | saved numbers still shown | none (cannot send) | form replaced by the notice (§3.5) |

Behaviour in both #1575 states: the plugin's code does not detect #1575 and has no fallback renderer — it never prints a carrier number (Provisional P1). The only gap is the one that exists today and is the framework's job: until #1575 ships, a buyer of a carrier-only order sees the number in framework emails but not in My Account. **Sequencing recommendation:** ship #1575 before or in the same wave as 2.0.0 (open question Q1). The two changes touch different code (framework templates vs this plugin) and cannot conflict; the account page can show two blocks (hooks `woocommerce_view_order` here, `woocommerce_order_details_after_order_table` in the carriers' v1 pattern, D §0) — their relative order is **UNVERIFIED** and is judged by the operator's eye on the rig.

## 8. Test plan

**Unit** (PHPUnit + Brain Monkey, no network; names are test classes in `tests/unit/`):

- `ContractTest` — every row of §1.2: ids, option keys, meta keys, hook/filter/action names, AJAX actions and nonce actions, template relative names, CSS classes in the shipped templates, class and function names exist; header values (`Plugin Name`, text domain, version 2.0.0).
- `ProviderResolutionTest` — §2.1 table: slug, English key, Russian label, filtered label, locale switch (same result under `en_US` and `ru_RU`), unknown string, custom item, empty link, legacy record; `sprintf` bad format (R8).
- `ItemsStoreTest` — add writes the slug, duplicate no-op, delete, re-index, unknown keys preserved, `false` on no licence (R1); `wc_otn_delete_tracking_number()` with a provider matches items the UI wrote (R5).
- `CarrierTrackingReaderTest` — fixtures with a fake `Orders_Registry` provider: CDEK/Post scalar, Yandex request id + sharing URL, Ozon (marker, no key), no registry class, marker without number, same-number dedupe (§2.3).
- `EmailTablePolicyTest` — every skip rule of §4.2, plain and HTML, carrier-owned manual-only output.
- `AutoSenderTest` — each condition of §4.3 off in turn; claim-before-send; double trigger sends once; two numbers in one request → one mail; no send on read; carrier-owned order: a distinct new manual number IS mailed (own email lists only it), a number equal to the pulled one is NOT; marker removed on delete.
- `AjaxHandlersTest` — capability, nonce, missing `order_id`, order not found, licence off, response always terminated.
- `LicenceGateStateTest` — the §3.5 matrix.
- `SettingsTest` — own-carrier validation (name required, template), stable `id`, auto-send default on.
- `I18nCoverageTest` — every `__()`/`_e()` msgid is in the `.pot` and has a `ru_RU` translation.

**Integration** (WP test suite or rig-run scripts): `OrderMetaStorageTest` — items round-trip on classic and HPOS; `EmailRenderTest` — own email and `customer_shipment_created` rendered through WooCommerce with a manual item: table present only where §4.2 allows.

**Rig checks (coordinator, on a rig site with the four v2 carriers; screenshots to the scratchpad, never the repo; a fake phone number on the rig before any shot — memory rule):**

1. Fresh install of the release ZIP; upgrade over v1 1.3.0 with orders carrying UI-written (Russian label) and API-written (slug) items: names and links unchanged, licence kept, no email sent on update.
2. Theme override of each of the five templates at `{theme}/woocommerce/…` is picked up (§1.2).
3. The §7 matrix, row by row, with real orders of CDEK, Yandex, Russian Post and Ozon; #1575 not shipped (and shipped if it is merged).
4. Auto-send: one mail per new number; a second «Update» sends nothing; the button resends; WC → Email disabled → nothing.
5. Licence off: §3.5 behaviour on the order page, AJAX, API.
6. Bootstrap coexistence with a v1 Woodev plugin (T-Bank spec §5.3 table, same loader rule).
7. Block-based WC email templates (WC 9.8+ editor): the table and the own email still render (A R13 UNVERIFIED).
8. Link check results applied; the select lists exactly the `active` records.

## 9. Work breakdown (cards on framework board №6; code in `kalbac/woocommerce-order-tracking-number`)

One reviewable PR per wave into the plugin repo's `main`. Every brief carries the worker's own worktree path for Serena, says that `tests/**` is Serena-ignored, and that this repo's PHP (outside `tests/`) is read through Serena. Style: PHP 7.4+, readable, layout sniffs, no clever one-liners (memory: green phpcs is not readable code). Releasing and the EDD upload wait for the operator's word per wave.

| Wave | Scope (files) | Acceptance | Depends on | Parallel? |
|---|---|---|---|---|
| **W0** skeleton + data layer | main file with the v2 loader literals (`plugin_id wc_order_track_number`, `download_id 2653`, `plugin_version 2.0.0`, `framework_version` = current released tag, `supported_features hpos`), entry class `WC_Order_Tracking_Number extends Woocommerce_Plugin` (+ the global function/constants of §1.2), `WC_Order_Tracking_Lifecycle` (licence carry), `Provider_Catalog` + `data/providers.php` (v1's 9 as shipped, all `active` until W4a), `Items_Store`, facade `WC_Order_Tracking_Number_Actions`, `includes/functions.php` (`wc_otn_*`), CI/phpcs/phpstan skeleton, release-ZIP script (model: `woodev-russian-post`, tbank W8) | `ContractTest`, `ProviderResolutionTest`, `ItemsStoreTest`, `I18nCoverageTest` (empty-set pass), CI + local-ci green | — | alone |
| **W1** admin | `includes/admin/*` (meta box, AJAX, list column, order action, licence-off state), `assets/js/admin.js`, `assets/css/admin.css`, `Carrier_Tracking_Reader` | `AjaxHandlersTest`, `CarrierTrackingReaderTest`, `LicenceGateStateTest` (admin half), `OrderMetaStorageTest`; registry-timing question answered on the rig | W0 | ∥ W2, W3 |
| **W2** settings | `includes/settings/*` (`Tracking_Settings`, `get_settings_providers()`), own-carrier storage and §6.3 link building, select population | `SettingsTest`; field-type check recorded (repeater or textarea fallback) | W0 | ∥ W1, W3 |
| **W3** buyer side | `includes/frontend/*` (view-order, `Email_Table_Policy`), `WC_Order_Tracking_Number_Email` (translatable, `trigger( $id, $items )`), `Auto_Sender`, the five templates | `EmailTablePolicyTest`, `AutoSenderTest`, `EmailRenderTest`; template override on the rig | W0 (reads `auto_send` default from a constant until W2 lands) | ∥ W1, W2 |
| **W4** release prep | **W4a link check** (coordinator, §6.2) → `providers.php` final; catalogue audit; `readme`/changelog (§1.1 drops: the two inbound hooks, `_tracking_number_last_send`; the new auto-send default — Q3); product-card text; at most one docs page; rig checks 1–8 | all rig checks recorded; ZIP installs on a clean site; `.mo` coverage | W1, W2, W3 | alone |

**Shared files.** Only the plugin wiring (`includes/class-plugin.php`, one `init` line per wave) and the `.po/.mo` catalogue are touched by more than one wave. W1–W3 are *written* in parallel (own directories) and *merged serially* W1 → W2 → W3: each later wave rebases, re-applies its additive wiring line and its own comment-headed block in the `.po`, rebuilds the `.mo`, then runs its gates. Rounds: 2–3 per wave, then decompose.

## 10. Open questions and Provisional register

### 10.1 Open questions for the operator

- **Q1 — sequencing with #1575.** Should 2.0.0 wait for #1575 (framework shows tracking in My Account for carrier orders), or ship first? Recommendation: ship #1575 first or together; otherwise the update brings no account-page tracking for carrier-only orders, which the product page will have promised (§7).
- **Q2 — what did «дропшиппинг» on the product card mean?** The card lists it, the code has no such feature (A §1, Agenda facts). Recommendation: it was the custom-carrier field; keep the word out of the new card, or tell us the intended meaning before the card text is written.
- **Q3 — automatic send on shops upgrading from v1.** Decision 3 sets the default on; v1 never sent automatically, so the first number a merchant adds after the update mails the buyer. Recommendation: keep on (decision 3), put it as the first line of the changelog and the product card; the alternative is off-by-default for shops that already have the plugin (the setting is absent, so the lifecycle would write `auto_send = no` when it finds the old version option).

### 10.2 Provisional (coordinator may revisit)

| # | Gap closed by this spec | Where |
|---|---|---|
| P1 | Carrier numbers are read live and never copied; no fallback renderer whether or not #1575 shipped; the plugin does not detect #1575 | §2.3, §7 |
| P2 | A manual number equal to the pulled number is hidden on buyer surfaces and not auto-mailed; any other new manual number on a carrier-owned order is auto-mailed | §2.3 |
| P3 | Own carriers are snapshotted into a custom item at add time | §6.3 |
| P4 | Repeater field if the settings API has one, else a one-per-line textarea; settings provider on `Woocommerce_Plugin` unproven | §5 |
| P5 | Auto-sent marker in separate meta `_tracking_number_emailed`, item shape unchanged | §2.2 |
| P6 | One email per request listing the numbers added in it | §4.3 |
| P7 | The resend action also works on carrier-owned orders and sends manual items other than the pulled number | §3.4 |
| P8 | `add_tracking_item()` returns `false` on refusal; built-ins are written as slugs | §2.1 |
| P9 | The admin list column also shows the pulled carrier number | §3.3 |
| P10 | Deleting a manual number stays allowed without a licence | §3.5 |
| P11 | `upgrade_to_1_2_0` licence-key carry is ported | §1.1 |
| P12 | Licence gate is the shipping-namespace `License_Gate` (T-Bank F1 not yet lifted) | §3.5 |
| P13 | Form-only carriers (link without the number) are allowed, built-in and own | §6.4 |
| P14 | The two inbound carrier hooks are dropped (announced in the release notes) | §1.1 |
| P15 | No send throttle on the resend action | §3.4 |

## Related

- [2026-10-27-tbank-v2-plugin-design.md](2026-10-27-tbank-v2-plugin-design.md) — shape and loader/licence/wave conventions followed here.
- [brainstorm-agenda.ru.md](../research/2026-11-03-tracking-v2/brainstorm-agenda.ru.md) — the binding decisions.
- [a-v1-inventory.md](../research/2026-11-03-tracking-v2/a-v1-inventory.md), [c2-source-check.md](../research/2026-11-03-tracking-v2/c2-source-check.md), [d-v1-buyer-tracking.md](../research/2026-11-03-tracking-v2/d-v1-buyer-tracking.md), [e-carrier-links.md](../research/2026-11-03-tracking-v2/e-carrier-links.md) — inputs.
