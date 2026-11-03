TRACKD — v1 buyer-side tracking display

# Task D — how the v1 carrier plugins showed tracking to the BUYER

Date: 04.11.2026. Input for framework card kalbac/woodev-plugin-framework#1575. Read-only research: `git grep` / `git show` on branch `v1` of `woocommerce-edostavka` (plugin header 2.3.2) and `woodev-russian-post` (the copy in `plugins-reference/woodev-russian-post/` was diffed against `v1` for `checkout.php`, `tracking-email.php`, `html-order-tracking-history.php` — identical), plain `Read`/`grep` on `legacy-v1/woocommerce-yandex-delivery-v1`. No checkout, no network, no Serena, no running site.

Marks: **READ** = source inspected. **MEASURED** = grep result (presence/absence). **UNVERIFIED** = reason given. Path prefix `v1:` = git blob on branch `v1`; Yandex paths are relative to `legacy-v1/woocommerce-yandex-delivery-v1/`. WooCommerce core is not in any of these repos, so every statement about what core does (which pages fire which hook, template override lookup) is **UNVERIFIED** and flagged where it matters.

## 0. Summary

- All three v1 plugins show tracking to the buyer on the **same one surface**: a block printed by `woocommerce_order_details_after_order_table` (priority 10, i.e. no priority argument) — **READ** in each. That hook is core's order-details table, so by core design it appears on My Account → view order **and** the order-received (thank-you) page (**UNVERIFIED**, core absent). Nothing hooks `woocommerce_view_order` or `woocommerce_thankyou` — **MEASURED** (grep over all three).
- Plus **one own customer email** per plugin (CDEK `WC_CDEK_Tracking_Email`, Russian Post `Tracking_Email`, Yandex `WC_Yandex_Sharing_Url_Email`); no WC core email was modified. **MEASURED**: no use of `woocommerce_email_before_order_table` / `woocommerce_email_after_order_table` in any plugin; the three emails only call core's `woocommerce_email_order_details`, `_order_meta`, `_customer_details`.
- No plugin has a setting that toggles the buyer display itself. The only gates are code conditions (see per-plugin §«When»). The Russian Post status history is gated by the setting `enable_tracking` (default `no`) because that setting also decides whether the history is fetched at all.
- **No multi-parcel support in any v1 plugin**: one number per order, scalar meta. **MEASURED/READ**.
- CDEK and Russian Post show a *number with a carrier link*; Yandex shows **no number at all**, only a per-order sharing URL + live status history.

## 1. CDEK v1 (`woocommerce-edostavka`, branch `v1`)

### 1.1 Where

| Surface | Hook / class | Evidence |
|---|---|---|
| My Account view-order + thank-you block (see 0) | `add_action( 'woocommerce_order_details_after_order_table', [ $this, 'add_details_after_order_table' ] )` — priority 10 | **READ** `v1:includes/class-wc-edostavka-checkout.php:30` |
| …which prints the pickup-point table, then fires the plugin's own `do_action( 'wc_edostavka_after_details_order_table', $order )` | `v1:includes/class-wc-edostavka-checkout.php:~1018-1043` | **READ** |
| …and the tracking block hangs on that own action: `add_action( 'wc_edostavka_after_details_order_table', [ $this, 'add_delivery_order_statuses' ] )` | `v1:includes/class-wc-edostavka-checkout.php:31`, method at `:~1051-1060` | **READ** |
| Customer email, own class `WC_CDEK_Tracking_Email`, id `{method_id}_tracking` (title «CDEK - send tracking number», ru «СДЭК - отпавка номера отслеживания» [sic, typo in v1 .po]) | `v1:includes/emails/class-wc-edostavka-tracking-email.php`, registered `v1:woocommerce-edostavka.php:~255-270` through `woocommerce_email_classes` | **READ** |
| Own admin-only emails «delivered» / «not delivered» go to the admin (`customer_email = false`) | `v1:includes/emails/class-wc-edostavka-delivered-email.php` | **READ** — NOT buyer-facing |
| Thank-you page, order-tracking form page, WC core emails, order-list table in My Account | nothing | **MEASURED** (no `woocommerce_thankyou`, `woocommerce_view_order`, `woocommerce_email_*_table`, `woocommerce_my_account_my_orders_*` hook anywhere in `v1:includes`/root) |

Dead anchor: the email links to `{view_order_url}#wc-edostavka-tracking` (`class-wc-edostavka-tracking-email.php:61`, filter `woocommerce_edostavka_email_tracking_core_url`), but **no element with id `wc-edostavka-tracking` exists** in any template — **MEASURED** (`git grep` finds the string only in the email class). The link lands at the top of the page.

Also: the placeholder `{tracking_code_url}` is set to that anchor-link, but the default message text uses `{tracking_code}` and a hard-coded CDEK URL (below), so `{tracking_code_url}` is unused by default — **READ**.

### 1.2 What (page block) — `v1:templates/views/html-order-delivery-status.php`

Variables from the caller: `title` = «Delivery status of CDEK» (ru «Статус доставки СДЭК»), `statuses` = order meta `history_statuses`, `cdek_number` = `wc_edostavka_get_tracking_code( $order )` (meta `_wc_edostavka_tracking_code`). **READ**.

Exact markup:

```html
<h2>{title}</h2>
<table class="woocommerce-table shop_table wc-edostavka-table-point-details">
  <caption>                                  <!-- only if number non-empty -->
    <span>Tracking number: </span>           <!-- ru: «Номер отслеживания: » -->
    <a href="https://www.cdek.ru/ru/tracking?order_id={number}" target="_blank">{number}</a>
  </caption>
  <thead>                                    <!-- only if statuses non-empty -->
    <tr><th>Status</th><th>Date</th><th>Location</th></tr>   <!-- ru: Статус / Дата / Местоположение -->
  </thead>
  <tbody>
    <tr>
      <td scope="row">{status label}</td>
      <td>{date} at {time}</td>              <!-- ru: «%1$s в %2$s», wp_date(wc_date_format()) / wp_date(wc_time_format()), timezone wc_timezone_string() -->
      <td>{city}</td>                        <!-- esc_attr_e( $status->city ) -->
    </tr> …
  </tbody>
</table>
```

- URL format: `add_query_arg( 'order_id', $number, 'https://www.cdek.ru/ru/tracking' )`, `target="_blank"` **without** `rel` — **READ**. Number echoed unescaped (`<?php echo $cdek_number;?>`).
- Status label: if the uppercase code is a key of `wc_edostavka_get_order_statuses()` use that translated label (e.g. `DELIVERED` → «Доставлен»), else the raw `$status->name` from the API — **READ** (`v1:includes/functions.php:767-…`; filter `wc_edostavka_order_statuses`).
- Rows: only statuses whose code is in `wc_edostavka_get_status_keys_for_show()` are stored, newest first: `ACCEPTED, CREATED, DELIVERED, NOT_DELIVERED, INVALID, POSTOMAT_RECEIVED, TAKEN_BY_COURIER, RECEIVED_AT_SHIPMENT_WAREHOUSE, SENT_TO_RECIPIENT_CITY, ACCEPTED_AT_RECIPIENT_CITY_WAREHOUSE, ACCEPTED_IN_RECIPIENT_CITY` (filter `wc_edostavka_status_keys_for_show`, `v1:includes/functions.php:821-835`; sort/filter at `v1:includes/class-wc-edostavka-order.php:~517-537`) — **READ**. Buyer does NOT see transit steps.
- Carrier/tariff name: not shown (title says «СДЭК»). Delivery date: not shown. Status text of the *current* status as a separate label: not shown (the first history row is it).
- No inline `<style>` in this template (the pickup-point template does have one, §1.5).

### 1.3 What (pickup point, same hook) — `v1:templates/views/html-order-delivery-point.php`

Printed by `add_details_after_order_table` (before the status block) for each chosen point of type `PVZ`/`POSTAMAT` from order meta `chosen_delivery_point`. Title «Details of the chosen pick-up point / postamat» (ru «Информация о выбранном %s»), table class `woocommerce-table shop_table wc-edostavka-table-point-details`, rows: Name (`name [code]`), Address (link to `https://yandex.ru/maps/?whatshere[point]=lon,lat&whatshere[zoom]=16`), optional «Description of location», «Pickup points note», Phone(s), Office schedule (`<ul class="wc-edostavka-table-point-details__working-hours">`). Inline `<style>` block is printed inside the template. **READ**. Not tracking, but it is part of what a buyer sees next to it.

### 1.4 When

- Block only for orders where `$order->has_edostavka_shipping()` — any shipping line with `get_method_id() === wc_edostavka_shipping()->get_method_id()` — **READ** (`v1:includes/class-wc-edostavka-order.php:20-31`).
- **No order-status condition, no "exported" condition.** The template itself returns early only if both number and statuses are empty (`html-order-delivery-status.php:11-13`). **READ**.
- Number appears only after CDEK assigned it: `wc_edostavka_update_tracking_code()` is called from the status sync when the remote `cdek_number` differs from the stored one (`v1:includes/class-wc-edostavka-order.php:541-543`) — **READ**. So: number shows up on the page after the first successful sync, not at export time.
- Number removed on cancel: `cancel_action()` → `wc_edostavka_update_tracking_code( $this, …, true )` deletes `_wc_edostavka_tracking_code` (`class-wc-edostavka-order.php:625`) — **READ**. History meta is not cleared there (**UNVERIFIED** elsewhere).
- Email: sent from `wc_edostavka_update_tracking_code()` right after the meta is written (`v1:includes/functions.php:1043-1060`, trigger call at `:1057`). Gates in `WC_Edostavka_Email::trigger()`: `$this->is_enabled()` (WC email setting «Enable this email notification», core default **yes** — **UNVERIFIED**, core) and non-empty billing email (`v1:includes/emails/abstract-wc-edostavka-email.php:~57`). The sent-flag `tracking_number_email_send` is **written but never read** — **MEASURED** (grep) — so the email is re-sent on every number change, not deduplicated.
- No plugin setting toggles the block. Admin-editable email subject/heading/message text/additional content (fields added in `init_form_fields`, `abstract-wc-edostavka-email.php`) — **READ**.

### 1.5 Strings — email (ru, from `v1:languages/woocommerce-edostavka-ru_RU.po`) — **READ**

- Subject: «[{site_title}] - Заказ #{order_number} был передан в СДЭК.»
- Heading: «Вашему заказу #{order_number} был присвоен номер отслеживания.»
- Message (default, `get_default_message_text`): «Ваш заказ #{order_number} на сайте <a href="{site_url}" target="_blank">{site_title}</a> был передан <a hre="https://cdek.ru/" target="_blank">в курьерскую службу СДЭК</a> с номером отслеживания {tracking_code}. Вы можете следить за статусом отправления <a href="https://www.cdek.ru/ru/tracking?order_id={tracking_code}">на сайте СДЭК</a>.» — note the `hre=` typo in the v1 ru string (broken link attribute).
- Body line: «Детали вашего заказа.» then core order details/meta/customer details; additional content «Thanks for shopping with us…» default.
- Placeholders: `{order_number}`, `{data}`, `{order_date}`, `{tracking_code}`, `{tracking_code_url}`.
- Plain-text template exists: `v1:templates/emails/plain/tracking-code.php`.

### 1.6 Theme overrides — **READ** code, **UNVERIFIED** core lookup

- Page blocks are loaded with `wc_edostavka_shipping()->load_template( 'views/…' )` → woodev `load_template()` = `wc_get_template( $template, $args, '', plugin /templates/ )` (`v1:woodev/class-plugin.php:1352-1366`). With an empty `$path`, core looks in the theme under its default `woocommerce/` folder. Expected overrides: `{theme}/woocommerce/views/html-order-delivery-status.php` and `{theme}/woocommerce/views/html-order-delivery-point.php`.
- Emails: `wc_get_template_html( 'emails/tracking-code.php', …, '', $this->template_base )` with `template_base` = plugin `/templates/` (`abstract-wc-edostavka-email.php`). Override: `{theme}/woocommerce/emails/tracking-code.php` and `…/emails/plain/tracking-code.php`.
- Filters for content: `woocommerce_edostavka_email_tracking_core_url`, `wc_edostavka_order_statuses`, `wc_edostavka_status_keys_for_show`.

### 1.7 Multi-parcel — **READ**

None. `_wc_edostavka_tracking_code` is one scalar string (`sanitize_text_field`, `v1:includes/functions.php:1043-1053`); `chosen_delivery_point` is iterated as an array (loop in the pickup-point printer) but that is points, not parcels. A second number overwrites the first and sends a second email.

## 2. Russian Post v1 (`woodev-russian-post`, branch `v1`, 1.2.6.0)

### 2.1 Where

| Surface | Hook / class | Evidence |
|---|---|---|
| My Account view-order + thank-you block | `add_action( 'woocommerce_order_details_after_order_table', [ $this, 'add_details_after_order_table' ] )` — priority 10 | **READ** `v1:includes/classes/checkout.php:26`; method `:616-639` |
| Customer email, own class `Woodev\Russian_Post\Classes\Tracking_Email`, id `wc_russian_post_tracking_email` («Отправка номера отслеживания - Почта России») | `v1:includes/classes/tracking-email.php`; registered via `woocommerce_email_classes` `v1:woodev-russian-post.php:88` | **READ** |
| Thank-you page directly, order-tracking form page, core emails | nothing | **MEASURED** (same grep as CDEK) |

### 2.2 What

The method has **two mutually exclusive variants**, `v1:includes/classes/checkout.php:616-639`:

**Variant A — number only (no history yet or history disabled):** inside the pickup-point table, `v1:templates/views/html-order-delivery-point.php`, an extra row

```html
<tr><td scope="row">Трек-номер:</td><td>{tracking_link}</td></tr>
```

(label hard-coded Russian, not translatable; **READ** `html-order-delivery-point.php` lines of the `$tracking_link` block). `tracking_link` is passed only when `tracking_history` meta is empty AND the order has a number (`checkout.php:628`). The table: `<h2>` «Details of chosen {postamat|postal office}» (ru «Детали выбраного %s» [sic]), `<table class="woocommerce-table shop_table wc-russian-post-table-point-details">`, rows Name / Address (link to Yandex Maps) / Трек-номер / Pickup points note / Office schedule; inline `<style>` printed in the template. **READ**.

**Variant B — status history** (`tracking_history` meta non-empty): `v1:templates/views/html-order-tracking-history.php`:

```html
<h2>Tracking history</h2>                      <!-- ru «История отслеживания» -->
<table class="woocommerce-table shop_table wc-russian-post-table-point-details">
  <caption>
    <span class="wc-russian-post-table-point-details__package">{history[0].params.name}: {tracking_link}</span>
    <span class="wc-russian-post-table-point-details__currant-status">
      <mark title="Current delivery status">{order status name}</mark>     <!-- ru «Текущий статус доставки» -->
    </span>
  </caption>
  <thead><tr><th>Status</th><th>Date</th><th>Location</th></tr></thead>     <!-- ru: Статус / Дата / Локация -->
  <tbody>
    <tr><td scope="row">{status.type_name} {status.attr_name}</td>
        <td>{date} at {time}</td>                                            <!-- ru «%s в %s» -->
        <td>{origin.address}</td></tr> …
```

Inline `<style>` defines the grey pill `.wc-russian-post-table-point-details__currant-status` (+ `mark`). **READ**. Note the CSS class typo `currant` is part of the markup. `{history[0].params.name}` is the parcel/mail-item name as returned by the Russian Post tracking SOAP (`params.name`) — shown as the caption prefix (**READ**; actual value format **UNVERIFIED**, no API sample).

- Link format: `get_tracking_link()` → `<a href="https://www.pochta.ru/tracking?barcode={number}" target="_blank">{number}</a>`, filtered by `wc_russian_post_tracking_url` (`v1:includes/classes/order.php:121-129`) — **READ**. (Different from v2's template `https://www.pochta.ru/tracking#{tracking}`.)
- Current status text = `$order->get_order_status_name()` — the plugin's own order status (new/exported/accepted/dispatched/delivered/canceled), **not** the raw Post status. **READ**.
- Not shown: tariff, delivery date.

### 2.3 When

- Block requires `$order->is_russian_post()` (meta `_wc_russian_post_is_russian_post` set at checkout, or legacy detection) — **READ** `order.php:25-33`.
- **Variant A is further restricted to `method_type == 'postal'`** (pickup/postamat/office delivery): for **courier** Russian Post orders the buyer sees **no number at all on the page** (the whole pickup block is inside `if ( 'postal' == … )`). **READ** `checkout.php:620-631`. The history table (variant B) is outside that condition and shows for courier too.
- History appears only if setting `enable_tracking` = yes (checkbox, **default `no`**, `v1:includes/classes/integration.php:270-275`; section also needs PHP `soap` extension and tracking login/password) — the history is fetched and stored at order update: `order.php:341-343`. **READ**. Without it, only variant A (postal orders only).
- History cache/refresh: transient `wc_russian_post_tracking_history_{number}`, interval `apply_filters( 'wc_russian_post_tracking_update_interval', 12 * HOUR_IN_SECONDS )` (`order.php:415-440`). **READ**. The page prints stored meta; it never calls the API on view.
- Email gates (`tracking-email.php` `trigger()`): email enabled (core default, **UNVERIFIED**), billing email present, number non-empty, and `$tracking_number !== meta tracking_number_email_sent` — **deduplicated per number** and the sent flag stores the number itself. Triggered synchronously from `Order::set_tracking_number()` when the number changes (`order.php:131-149`), also fires `do_action( 'wc_russian_post_order_tracking_number_changed', $number, $order_id )`. **READ**.
- Number is set at export (`order.php:274`, Otpravka export type only) and on later update if the barcode differs (`order.php:332-338`; adds an order note «The barcode was changed from %s to %s»). **READ**.

### 2.4 Strings — email (ru) — **READ** `.po` and class

- Subject: «[{site_title}] - Заказ #{order_number} был передан в Почту России.» (EN source: `[{site_title}] - Order #{order_number} was exported to Russian Post.`; ru of this exact msgid not extracted — **UNVERIFIED** wording, EN is certain).
- Heading: «Вашему заказу #{order_number} был присвоен номер отслеживания.»
- Message EN source: `Your order #{order_number} on web-site <a href="{site_url}" target="_blank">{site_title}</a> has been exported to Russian Post with tracking number {tracking_number}. You can track your order status {tracking_url}.` (ru string for it not extracted — **UNVERIFIED**).
- Placeholders: `{order_number}`, `{order_date}`, `{status}`, `{tracking_number}`, `{tracking_url}` (the latter is the full `<a>` HTML from `get_tracking_link()`).
- Plain-text template exists: `v1:templates/emails/plain/tracking-number.php`.

### 2.5 Theme overrides — same mechanism as CDEK (**READ** code / **UNVERIFIED** core)

`{theme}/woocommerce/views/html-order-delivery-point.php`, `…/views/html-order-tracking-history.php`, `…/emails/tracking-number.php`, `…/emails/plain/tracking-number.php` (`wc_russian_post_shipping()->load_template( 'views/…' )`; email `template_base` = plugin templates dir, `tracking-email.php`). Filter: `wc_russian_post_tracking_url`.

### 2.6 Multi-parcel — **READ/MEASURED**

None. One scalar `_wc_russian_post_tracking_number`; `$tracking_history[0]['params']['name']` is the caption of a single mail item.

## 3. Yandex Delivery v1 (1.3.7)

### 3.1 Where

| Surface | Hook / class | Evidence |
|---|---|---|
| My Account view-order + thank-you block | `add_action( 'woocommerce_order_details_after_order_table', [ $this, 'add_details_after_order_table' ] )` — priority 10 | **READ** `includes/class-checkout.php:24`; method `:~815-860` |
| Customer email `WC_Yandex_Sharing_Url_Email`, id `wc_yandex_sharing_url_email` («Яндекс доставка - Ссылка с трекингом заказа») | `includes/class-sharing-url-email.php`, registered `woocommerce_email_classes` `woocommerce-yandex-delivery.php:81, 197-206` | **READ** |
| Anything else (thank-you hook, core emails) | nothing | **MEASURED** |

### 3.2 What

**No tracking number is shown or stored for the buyer.** The only carrier identifier shown is a per-order **sharing URL** (`_yandex_delivery_sharing_url`, from the API field `sharing_url`) as a link on a status history table. (`_yandex_delivery_request_id` is the internal request id and is never printed to the buyer — **MEASURED**: not referenced in `templates/`.)

Block: `templates/html-order-state-history.php` — **READ** (all Russian strings are hard-coded, no gettext):

```html
<h2>Статус заказа Яндекс доставки</h2>
<table class="woocommerce-table shop_table wc-yandex-table-state-history">
  <caption>                                   <!-- only if sharing_url non-empty -->
    <a href="{sharing_url}" target="new">Ссылка для отслеживания</a>
  </caption>
  <thead><tr><th>Статус</th><th>Описание</th><th>Дата</th><th>Доп.информация</th></tr></thead>
  <tbody>
    <tr>
      <td scope="row">{wc_yandex_delivery_get_order_status_name( status )}</td>   <!-- e.g. DELIVERY_DELIVERED → «Вручён клиенту», CANCELLED → «Отменён» (includes/functions.php:413-…) -->
      <td>{description}</td>
      <td>{timestamp_utc → site timezone, wc_date_format() . ' ' . wc_time_format()}</td>
      <td>{cancel reason via wc_yandex_delivery_get_cancel_reason()  |  <span class="na">—</span>}</td>
    </tr> …
```

- Pickup point block (separate, same hook, before the history): `templates/html-order-delivery-point.php`, `<h2>Информация о пункте выдачи</h2>`, rows Название / Адрес / Как добраться / Телефон / Расписание / Нерабочие дни — **READ** headings, fetched **live from the Yandex API** (`pickup_points_list`) on every page view, errors logged and swallowed (`class-checkout.php:~831-851`).
- History is also fetched **live from the API on every page view** (`WC_Yandex_Delivery_Order::get_order_history()` → `get_api()->get_state_history( request_id )`, `includes/class-order.php:436-447`); no cache, no stored copy. **READ**. If the API call throws, history is empty and the block is silently omitted.
- Not shown: number, carrier/tariff name, delivery date (`destination_interval_*` meta exists but is not printed).

### 3.3 When

- Order has a Yandex shipping line: `get_yandex_shipping_method()` (method id prefix match) — **READ** `class-order.php:22-31`.
- History block only when `$order->is_exported()` = request id non-empty (`class-order.php:139-141`) AND the live history is non-empty. The sharing link lives inside that block, so **no history → no link on the page** even if the meta exists. **READ**.
- Pickup block only when the shipping method instance `is_pickup()`.
- No plugin setting toggles the block. Email: enabled (core default) + billing email + non-empty `sharing_url`; **no sent-flag at all** — **MEASURED** (`sharing-url` class has no meta write); it is re-sent each time the URL meta changes (`wc_yandex_delivery_update_sharing_url`, `includes/functions.php:635-651`, called from `class-order.php:391-392` only when the value differs from the stored one) — **READ**.
- Order note «Ссылка для отслеживания заказа: {url}» is added when the URL is set (`functions.php:647`).

### 3.4 Strings — email (hard-coded ru) — **READ**

- Subject «[{site_title}] - Заказ #{order_number} был экспортирован в Яндекс доставку.»
- Heading «Вашему заказу #{order_number} была добавлена ссылка для отслеживания.»
- Message «Ваш заказ #{order_number} на сайте <a href="{site_url}" target="_blank">{site_title}</a> был успешно экспортирован в Яндекс доставку и ему была добавлена ссылка для отсл[еживания {sharing_url}.]» (cut by `grep` at the column limit; tail inferred from the heading/placeholder — **UNVERIFIED** last words).
- Body line «Информация о вашем заказе ниже:»; placeholders `{order_number}`, `{order_date}`, `{status}`, `{sharing_url}`. Plain template: `templates/emails/plain/sharing-url.php`.

### 3.5 Theme overrides — **READ** / **UNVERIFIED** core

`wc_yandex_shipping()->load_template( 'html-order-state-history.php' )` (no `views/` prefix; woodev `load_template` as in CDEK, `woodev/class-plugin.php:1488-1496`) → `{theme}/woocommerce/html-order-state-history.php`, `…/html-order-delivery-point.php`, `…/emails/sharing-url.php`, `…/emails/plain/sharing-url.php`.

### 3.6 Multi-parcel — **READ**

None. One request id, one sharing URL per order.

## 4. Comparison — surface × plugin

| Surface | CDEK v1 | Russian Post v1 | Yandex v1 |
|---|---|---|---|
| My Account → view order | Yes (`woocommerce_order_details_after_order_table`, p10) | Yes, same hook | Yes, same hook |
| Thank-you / order-received page | Same hook; **UNVERIFIED** (core) that it renders there | same | same |
| Order-tracking form page (`[woocommerce_order_tracking]`) | same hook; **UNVERIFIED** (core) | same | same |
| My Account orders LIST column / action button | No (**MEASURED**) | No | No |
| Own customer email | `WC_CDEK_Tracking_Email` | `Tracking_Email` | `WC_Yandex_Sharing_Url_Email` |
| WC core emails (processing/completed) modified | No | No | No |
| Plain-text email template | Yes | Yes | Yes |
| **Number shown** | Yes (caption) | Yes — postal (non-courier) orders always; courier orders only inside the history caption (needs `enable_tracking`) | **No number** |
| **Link** | `https://www.cdek.ru/ru/tracking?order_id={n}` | `https://www.pochta.ru/tracking?barcode={n}` (filter) | per-order `sharing_url` from API |
| Link `rel` | none | none | none (`target="new"`) |
| Carrier / tariff name | title only («СДЭК») | caption prefix = mail item name from API | title only («Яндекс доставки») |
| Status history | Yes, stored `history_statuses`, 11 whitelisted codes, newest first, cols Status/Date/Location | Yes, stored `tracking_history` (needs `enable_tracking`=yes, SOAP, login/password), cols Status/Date/Location + pill with plugin status | Yes, **live API call per page view**, cols Статус/Описание/Дата/Доп.информация |
| Pickup point block next to it | Yes (PVZ/POSTAMAT) | Yes (postal only) | Yes (pickup, live API) |
| Delivery date | No | No | No |
| Shown when | CDEK shipping line; no status or export condition | RP order; postal only for number; history needs setting | Yandex line + exported (request id) + live history non-empty |
| Setting toggling the display | none | `enable_tracking` (default `no`) for history only | none |
| Email dedupe | **none** (flag written, never read) | per-number flag `tracking_number_email_sent` | **none** |
| Theme override base | `{theme}/woocommerce/views/…` | `{theme}/woocommerce/views/…` | `{theme}/woocommerce/…` (no `views/`) |
| CSS classes | `wc-edostavka-table-point-details` | `wc-russian-post-table-point-details(__package, __currant-status)` | `wc-yandex-table-state-history` |
| Multi-parcel | No | No | No |
| Core-facing CSS | `woocommerce-table shop_table` on all three | | |

## 5. What a framework v2 renderer must reproduce

### 5.1 Common minimum (all three v1 plugins did this — not a product decision)

1. Print on the buyer's order screens through the single core hook `woocommerce_order_details_after_order_table` (priority 10; it is the surface the three plugins converge on). Whether to ALSO hook the thank-you page directly is **a product decision** (v1 never did).
2. Only for orders owned by a carrier plugin (marker = shipping line of the plugin; v2 equivalent: `Orders_Registry::resolve_provider_for_order()`), no order-status condition.
3. A block with an `<h2>` and a `woocommerce-table shop_table` table (core table classes, so themes style it), caption carrying the tracking line, optional body rows for history.
4. **Number as a link to the carrier's public tracking page** with `target="_blank"` (v1 omitted `rel`; add `rel="noopener"`), URL from the provider (`resolve_tracking_url`) — CDEK and Post both did exactly this.
5. Appears as soon as the number exists in order meta; disappears when the number is removed (no sent/shipped-status gate).
6. A customer email carrying the same number + link, sent once the number is assigned, with the shop's header/footer/order table (WC email classes), HTML + plain text, editable subject/heading/message with placeholders, and a "sent" guard. v2 already has `Delivery_Status_Email` with `{tracking_number}` / `{tracking_url}` for status changes — see c2-source-check §2; the v1 behaviour is a **dedicated "tracking number assigned" email**, which the v2 shipment-created email partially replaces. Whether v2 keeps a separate email is a **product decision** (outside this card's page scope).
7. Theme-overridable templates under the plugin's template tree via `wc_get_template` (override path `{theme}/woocommerce/…`).
8. Translatable strings; Russian labels «Номер отслеживания», «Статус», «Дата», «Локация/Местоположение», «Отследить».

### 5.2 Per-plugin extras (v1 plugins differ → each is a product decision)

| Item | v1 behaviour | Decision for v2 |
|---|---|---|
| Show **status history** table | CDEK (stored, whitelisted codes), Post (stored, setting-gated, default off), Yandex (live API) | Product decision. Framework has `Delivery_Status_Log::read()` (#1564, 2.0.24, **READ** in c2 §2) which is a stored, provider-agnostic source — it removes the "live API per page view" variant. Columns used in v1: status label, date+time (site timezone, `wc_date_format`/`wc_time_format`), location; Yandex added description + extra info. |
| History status whitelist | CDEK showed 11 of ~40 codes (filterable) | Product decision: buyer-safe subset vs all. |
| Link without a number | Yandex: only a per-order link (`tracking_url_meta_key`, `_yandex_delivery_sharing_url`) | Renderer must handle "link only, no number"; v2 Yandex already declares the same meta as both tracking meta and `tracking_url_meta_key` (c2 §1). |
| Current-status pill | Post only (`__currant-status`, plugin status name) | Product decision; low value if the history table is present. |
| Parcel/item name in caption | Post only (`params.name`) | Product decision; needs provider data v2 does not store. |
| Pickup-point block adjacent | all three | Out of scope for tracking; keep separate from this renderer. |
| Number inside the pickup table (not its own block) | Post variant A | Do **not** reproduce — it hid the number for courier orders (§2.3). |
| Dedicated email dedupe | Post per-number; CDEK/Yandex none | Do per-number dedupe (Post model), avoids CDEK/Yandex re-send on every change. |
| Setting to switch the buyer block off | none in v1 | Product decision; operator rule says "always show" (03.11.2026) → no off switch. |
| Delivery date row | none in v1 | Not a v1 feature; do not claim parity. |
| Multi-parcel (several numbers per order) | none in v1 | Not a v1 feature; Ozon v2 has several postings per order (`_woodev_ozon_postings`) so v2 needs it anyway — design question for the spec, no v1 precedent to copy. |

### 5.3 Defects of v1 NOT to reproduce

- Dead email anchor `#wc-edostavka-tracking` (CDEK).
- `hre=` typo in the CDEK ru message; hard-coded untranslatable «Трек-номер:» (Post) and all-Russian hard-coded Yandex strings.
- Unescaped output of number/status (`echo $cdek_number`, `echo $title`); `target` without `rel`.
- Inline `<style>` blocks printed on each render.
- Yandex live API call (pickup points + history) on every page view of an order; silent omission on failure.
- Post: number invisible for courier orders; history needs SOAP + extra credentials + opt-in.

## 6. Could not be established

- **How core behaves** — whether `woocommerce_order_details_after_order_table` fires on the thank-you page and on the order-tracking form page, the exact theme-override folder (`woocommerce/` default for an empty `$path`), and the default-enabled state of a `WC_Email` — are all core behaviours; core is not in these repos. **UNVERIFIED**.
- **Rendered output** — nothing was run; no v1 site/rig was inspected, so actual HTML in a browser (and e.g. real `params.name` for Russian Post, real Yandex `description`/`reason` values) is **UNVERIFIED**. No API samples were read.
- Russian text of two Russian Post email strings (subject, default message) and the tail of the Yandex default message (shell column cut) were not extracted; English sources/prefix are certain.
- Whether v1 ever fired its email twice in practice (CDEK/Yandex) — inferred from code only.
- Mobile / theme rendering of the tables — not looked at.
