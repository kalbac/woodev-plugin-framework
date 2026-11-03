# #1575 — buyer-facing tracking display for v2 carriers — design

Revision r2 (04.11.2026): applied review r1 — Q2 decided by the operator (every number its own link, §4 contract rewritten); Ozon `status_changed_at` is converted with `Ozon_Wire::parse_time()` (§2); Q1 stays open.

Status: draft for operator review, 04.11.2026 (s214). Card: kalbac/woodev-plugin-framework#1575. Related: kalbac/woodev-ozon-delivery#88.
Inputs: `research/2026-11-03-tracking-v2/c2-source-check.md` (§1–2), `…/d-v1-buyer-tracking.md` (§5), `specs/2026-11-04-tracking-v2-plugin-design.md` (§7). Framework APIs below were verified with Serena on the framework tree (paths relative to `woodev/`).

## 0. Binding decisions (card comments, 04.11.2026)

| # | Decision | Source |
|---|---|---|
| D1 | Number as a link plus ONE line «Текущий статус: <status>, <date>» from `Delivery_Status_Log`. No history table. | comment 3 |
| D2 | One shipment → identical for every carrier. Several (Ozon `_woodev_ozon_postings`) → one line per shipment «Отправление N из M: number-link, current status»; the «N из M» caption only when M>1. | comment 4 |
| D3 | ozon#88 (empty `{tracking_number}`/`{tracking_url}` in framework mails for Ozon) is fixed through the SAME number source, so My Account and the email agree; several shipments → all numbers. | comment 4, ozon#88 |
| D4 | Render on `woocommerce_order_details_after_order_table` (as v1): My Account order view and the thank-you page. Nothing printed when there is no number. Rig check of both pages. | comment 5 |
| D5 | Operator rule: a delivery with a tracking number always shows it — **no setting, no off switch**. | task brief |

## 1. Current state

- No buyer renderer exists anywhere: no framework or carrier code hooks `woocommerce_view_order`, `woocommerce_thankyou` or `woocommerce_order_details_*` (C2 §2, MEASURED). The only buyer tracking output is in emails.
- `Delivery_Status_Email::get_placeholders()` (`shipping-method/email/class-delivery-status-email.php`, private) reads the number ONLY from `Orders_Provider::get_tracking_meta_key()` and the link from `resolve_tracking_url()` or `get_tracking_url_template()`. Ozon declares neither → both placeholders empty. The extension point `apply_filters( 'woodev_shipping_delivery_email_placeholders', $placeholders, $order, $provider )` exists (@since 2.0.2).
- `Orders_Provider::resolve_tracking_url()` returns `null` unless BOTH `tracking_url_meta_key` and `tracking_meta_key` are declared and the meta is a valid http(s) URL.
- `Delivery_Status_Log::read( $order, $provider )` (`shipping-method/order/class-delivery-status-log.php`, @since 2.0.24) returns `[ [ state, unix time, raw code ], … ]`, oldest first, `[]` when empty. It is ORDER-level (one log per order and provider), not per shipment.
- Canonical states are string constants of `Delivery_Status` (`order/class-delivery-status.php`, e.g. `CREATED = 'created'`, `IN_TRANSIT = 'in_transit'`). The provider also has `get_status_labels()` (raw code → human label).
- v1 (D §5): all three plugins used the same hook, core table classes, number as a link, no `rel`, unescaped output, inline `<style>`, Yandex had a link without a number, nobody had several parcels.
- Yandex fact that shapes the seam: the tracking meta `_yandex_delivery_request_id` is an INTERNAL request id (also the carrier order id); v1 never showed it to the buyer. The buyer link is `_yandex_delivery_sharing_url`. Today `{tracking_number}` in a Yandex framework mail prints that internal id.

## 2. Data source — the seam

**One new optional provider argument and one value object.** Smallest thing that covers all four carriers:

```php
// Orders_Provider::create( …, [ 'shipments_resolver' => callable ] )   // optional, @since 2.0.25
// callable: fn( \WC_Order $order ): array   // list of Tracking_Shipment, [] when none
public function get_tracking_shipments( \WC_Order $order ): array;      // new public method on Orders_Provider
```

`Tracking_Shipment` — immutable value object (`Woodev\Framework\Shipping\Order\Tracking_Shipment`, own file, added to `class-map.php`), created with `Tracking_Shipment::create( string $number, ?string $url = null, ?string $status_label = null, ?int $status_time = null )`:

| Field | Meaning |
|---|---|
| `number` | Human tracking number; `''` allowed ONLY when `url` is set (Yandex). |
| `url` | Absolute http(s) URL or `null`; validated in `create()` with the same rule as `resolve_tracking_url()` (scheme http/https, `filter_var`). An invalid URL becomes `null`. |
| `status_label`, `status_time` | Optional PER-SHIPMENT current status (used only by multi-shipment carriers; see §3.4). |

`Orders_Provider::get_tracking_shipments()` behaviour:

1. If `shipments_resolver` is declared → call it; keep only `Tracking_Shipment` instances with a non-empty number or URL; a thrown `\Throwable` or a non-array result is treated as `[]` (logged once through the plugin logger — a buyer page must never fatal).
2. Otherwise **default from the existing declarations**: number = order meta at `get_tracking_meta_key()` (string-cast, trimmed); URL = `resolve_tracking_url()` ?? template with `{tracking}` → `rawurlencode( number )`; one shipment, or `[]` when the number is empty. This keeps CDEK and Russian Post at zero change.
3. Filter `woodev_shipping_order_tracking_shipments( array $shipments, \WC_Order $order, Orders_Provider $provider )` (@since 2.0.25) so a site or the tracking plugin can adjust the list.

Per carrier:

| Carrier | Source | Change |
|---|---|---|
| CDEK | default path (scalar `_wc_edostavka_tracking_code`, URL template) | none |
| Russian Post | default path (`_wc_russian_post_tracking_number`, template) | none |
| Yandex | declares `shipments_resolver` returning `[ Tracking_Shipment::create( '', <sharing_url> ) ]` when `_yandex_delivery_sharing_url` is valid, else `[]` — the request id is never a buyer number | tiny: one callable in `class-yandex-order-actions.php` provider |
| Ozon | declares `shipments_resolver` reading the CURRENT `_woodev_ozon_postings` rows (`Ozon_Export_Request::POSTINGS_META`; each row `posting_number`, `status`): one `Tracking_Shipment` per row with a non-empty `posting_number`, URL from the Ozon tracking page, `status_label` from the plugin's `Ozon_Status_Presentation::status_label()`-style map, `status_time`: the row's `status_changed_at` is an ISO-8601 string, so the resolver converts it first — `$time = Ozon_Wire::parse_time( $row['status_changed_at'] ?? null )` (`includes/api/class-ozon-wire.php`, `public static parse_time( $value ): ?\DateTimeImmutable`, null for absent/unreadable) and passes `null === $time ? null : $time->getTimestamp()` as the `int` `status_time`; never the raw string | Ozon plugin PR (§5) |

Cancelled / superseded Ozon postings ("generations" in `Ozon_Order_Panel::generations()`) are NOT current — the resolver reads only the current list, so a buyer never sees a cancelled number.

Ozon tracking URL: see open question Q1 (the repo declares none today; C2 §1).

Why not a filter only: a filter has no owner and no order of execution when two carrier plugins are active; a per-provider callable is registered once with the provider and resolved through `resolve_provider_for_order()`, the single source for "which provider owns this order".

## 3. Renderer

New class `Woodev\Framework\Shipping\Order\Buyer_Tracking` (`shipping-method/order/class-buyer-tracking.php`, `@since 2.0.25`). Singleton hooked from `Shipping_Plugin` the same way `Delivery_Status_Emails` is (guard so several carrier plugins hook it once).

### 3.1 Hook and gating

- `add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render' ], 10, 1 )` — core fires it from the order-details table, so it covers My Account → view order and the order-received page (core behaviour, UNVERIFIED here — rig check R1/R2 is the proof; if the thank-you page does not fire it, add `woocommerce_thankyou` with a once-per-request guard).
- `render( \WC_Order $order )`: `Orders_Registry::instance()->resolve_provider_for_order( $order )`; `null` → return; `$provider->get_tracking_shipments( $order )`; `[]` → print nothing (no heading, no wrapper). No order-status condition, no setting (D5).
- Core also passes a non-order object in rare cases (order-tracking form); guard with `$order instanceof \WC_Order`.

### 3.2 Template and override

`shipping-method/templates/order/tracking.php`, loaded with `wc_get_template( 'order/tracking.php', $args, 'woodev/shipping/', <framework templates dir> )`, so the theme override is `{theme}/woocommerce/woodev/shipping/order/tracking.php` (same mechanism the email template already uses; the folder is documented on the docs page). Args: `$order`, `$provider`, `$lines` (prepared array, §3.3). The template contains no logic beyond loops; no inline `<style>`, no JS.

### 3.3 Markup (core classes, escaped)

```html
<section class="woocommerce-order-tracking woodev-tracking">
  <h2 class="woocommerce-order-details__title">Отслеживание доставки</h2>
  <table class="woocommerce-table shop_table woodev-tracking__table">
    <tbody>
      <tr class="woodev-tracking__row">
        <th scope="row">Отправление 1 из 2</th>      <!-- th only when M > 1 -->
        <td>
          <a href="{esc_url}" target="_blank" rel="noopener">{esc_html number}</a>
          <div class="woodev-tracking__status">Текущий статус: {esc_html label}, {esc_html date}</div>
        </td>
      </tr>
    </tbody>
  </table>
</section>
```

- One shipment: the first cell is a `th` «Номер отслеживания»; M>1: `th` = «Отправление N из M» (D2). One markup, one template.
- Number and status through `esc_html`, URL through `esc_url`; `target="_blank"` always with `rel="noopener"`. Number without URL → plain escaped text. URL without number (Yandex) → link text «Отследить отправление».
- Strings (text domain of the framework; `__()` in the template or `Buyer_Tracking`, Russian in the framework `.po`): «Отслеживание доставки», «Номер отслеживания», «Отправление %1$d из %2$d», «Текущий статус: %1$s, %2$s», «Отследить отправление». Plural-free by construction.
- Date: `wp_date( wc_date_format(), $time )` — site timezone, WooCommerce date format, date only (D1 shows a date).

### 3.4 Status line (D1)

- **Single shipment:** `$entries = Delivery_Status_Log::read( $order, $provider )`; take the LAST entry (oldest-first array → `end()`); label = `$provider->get_status_labels()[ raw code ]` when the raw code (index 2) has a label, else the buyer label of the canonical state (index 0) from a small framework map `Delivery_Status::buyer_label( $state )` (new, @since 2.0.25, translatable: «Создано», «В пути», «Готово к выдаче», «Доставлено», «Возвращено», «Не доставлено» — values for every constant of `Delivery_Status`, unknown state → no line); date = entry time.
- **Several shipments (Ozon):** the status comes from the shipment itself (`status_label` / `status_time` of the value object) because the log is order-level and would show the same status on every line. If a shipment has no `status_label`, its line has no status text (never the order-level one).
- **Log empty** (nothing recorded yet, or carrier never changed state): print the number-link only, no status line, no «Текущий статус: —». A status without a date (time `0`) prints «Текущий статус: <label>».
- Freshness: the page prints stored meta only; no API call on view (v1 Yandex defect).

## 4. Emails (ozon#88, D3; operator decision on Q2, 04.11.2026)

Same seam, no second source. In `Delivery_Status_Email::get_placeholders()` replace the inline tracking/URL computation with `$provider->get_tracking_shipments( $order )`. The email keeps the list privately (`$this->shipments`) so `format_html()` can render links per shipment.

| Shipments | `{tracking_number}` | `{tracking_url}` |
|---|---|---|
| 0 | `''` | `''` |
| 1 | number | `url` — unchanged behaviour (`format_html()` makes the existing `<a>`) |
| M>1 | one line per shipment, same wording as My Account: «Отправление N из M: <number>» | `''` (every URL is inside its own line; a single-valued URL cannot serve M shipments) |

**Multi-shipment contract (M>1):**

- **HTML mail:** `format_html()` replaces `{tracking_number}` with the lines joined by `<br>`; each line is «Отправление N из M: » (escaped) + `<a href="esc_url(url)">esc_html(number)</a>`. A shipment without URL prints the escaped number as plain text; a link-only shipment prints «Отследить отправление» as the link text. `{tracking_url}` becomes an empty string, so the default body «Tracking number: {tracking_number}. {tracking_url}» reads as a list followed by a bare full stop — the default body text is reworded in W1 to «{tracking_number}» followed by «{tracking_url}» only on its own line, so no stray punctuation appears for any M.
- **Plain-text mail** (`get_content_plain()`): lines joined by newline, «Отправление N из M: <number> — <url>» (url omitted when absent); `{tracking_url}` empty.
- **Placeholder value seen by `woodev_shipping_delivery_email_placeholders`:** the plain-text form above, so a filter and a custom subject/heading see readable text; the HTML form is produced only inside `format_html()` from `$this->shipments`, never from the filtered string. A filter that replaces `{tracking_number}` therefore wins for plain text; for HTML the framework's per-shipment links apply only when the value is still the framework's own (otherwise the filtered value is escaped as before — no markup injected from a filter result).
- **Subject/heading** use the same plain-text value (numbers joined by «, » with no captions in the subject: «№1, №2»); captions only in the body.
- M=1 and M=0 are byte-identical to today for CDEK and Russian Post. Yandex changes on purpose (P3).
- Escaping: every number, caption and URL through `esc_html` / `esc_url`; no new markup from carrier data.

The `woodev_shipping_delivery_email_placeholders` filter stays and still runs after. Russian Post's own mail (`wc_russian_post_tracking_email`) is untouched.

## 5. What each carrier plugin must change; version

| Plugin | Change | Needs framework |
|---|---|---|
| CDEK, Russian Post | nothing (default path); optional: bump the required framework | ≥ 2.0.25 only to get the block, no code change |
| Yandex | `shipments_resolver` on its `Orders_Provider::create()` call (sharing link, no number) | ≥ 2.0.25 |
| Ozon | `shipments_resolver` + a tracking-link declaration; closes ozon#88 | ≥ 2.0.25 |

Minimum framework version = the release that ships this card (**2.0.25** assumed, after 2.0.24). A carrier that declares `shipments_resolver` on an older framework would have its unknown argument ignored by `create()`'s positional/array handling — verify in W1 whether `create()` rejects unknown keys; if it ignores them no guard is needed, else the plugin raises `framework_version` (its loader already pins `framework_version`, Ozon: 2.0.24 today).

The separate tracking plugin (spec `2026-11-04-tracking-v2-plugin-design.md` §7) keeps relying on the framework block for carrier-only orders and never prints a carrier number itself; this spec does not change that. Both blocks may appear on the same page for carrier+manual orders (§7 matrix there); order between them is judged on the rig.

## 6. Test plan

**Unit** (PHPUnit + Brain Monkey; `tests/**` is Serena-ignored → Read/Grep there):

- `TrackingShipmentTest` — URL validation (javascript:, empty, relative → `null`), number trim, number-or-URL rule.
- `OrdersProviderShipmentsTest` — default path (CDEK-like scalar, template URL, `rawurlencode`), empty number → `[]`, resolver result filtered, resolver throwing → `[]`, filter applied, Yandex-like link-only.
- `BuyerTrackingTest` — no provider → no output; no shipments → no output (empty string, no heading); 1 shipment → «Номер отслеживания», no «N из M»; 3 → «Отправление 2 из 3»; escaping of `<script>` in number/label; `rel="noopener"`; link-only; number-only; status from the log (last entry, raw label before canonical); empty log → no status line; per-shipment status for M>1, never the order-level one; date format via `wc_date_format`.
- `DeliveryStatusEmailPlaceholdersTest` (extend) — 0/1/M shipments; CDEK output identical to before; filter still applied; Ozon-like multi-number text and HTML.
- `DeliveryStatusBuyerLabelTest` — every `Delivery_Status` constant has a label; `ru_RU` catalogue coverage (existing i18n coverage test).

**Integration / rig** (coordinator, rig with the four v2 carriers; shots to the scratchpad, never the repo; fake phone before any shot):

- R1 My Account → view order, R2 order-received page, for: CDEK order with number, Russian Post, Yandex (link only), Ozon with 1 posting, Ozon with 2 postings, an order without number (nothing printed, no empty heading), a cancelled-and-recreated Ozon posting (only current shown).
- R3 `customer_shipment_created` mail for the Ozon 1- and 2-posting orders: numbers present and equal to R1/R2; a CDEK mail unchanged.
- R4 theme override of `order/tracking.php` is picked up; classic and block-based checkout thank-you both reach the hook (block checkout order-received uses the same template path — verify).
- R5 HPOS and legacy order storage.

## 7. Work breakdown

| Card | Repo | Scope | Acceptance |
|---|---|---|---|
| **W1 framework seam + renderer** | framework, PR → `main` | `Tracking_Shipment`, `Orders_Provider::get_tracking_shipments()` + `shipments_resolver` arg + filter, `Buyer_Tracking`, template, `Delivery_Status::buyer_label()`, class-map, hooking in `Shipping_Plugin`, email placeholders on the seam, `@since 2.0.25` everywhere, `.pot`/`ru_RU`, CHANGELOG, docs page `website/src/content/docs/shipping/buyer-tracking.md` (what the block shows, `shipments_resolver` example, template override path, the filter) | all §6 unit tests green, `npm run lint:docs`, phpcs, CI + a critic of another lineage; docs page present (part of done) |
| **W2 Ozon resolver** | woodev-ozon-delivery, PR → `main` | `shipments_resolver` over current postings, tracking link, per-posting status label; tests for 0/1/N postings and cancelled generations; closes ozon#88 | unit tests; rig R1–R3 for Ozon; `framework_version` raised if needed |
| **W3 Yandex resolver** | woocommerce-yandex-delivery | link-only resolver (tiny) | unit test; rig Yandex row; mail shows no request id |
| **W4 rig acceptance** | coordinator | §6 rig R1–R5 on the merged trees, one plugin at a time | recorded in the session note |

Order: W1 → (W2 ∥ W3) → W4. **W1 is NOT releasable alone** (critic R2, finding 3): on the default path the current Yandex provider would print the internal `_yandex_delivery_request_id` as the buyer number. Framework 2.0.25 and the Yandex release carrying the W3 resolver ship in the SAME wave, and the Yandex plugin requires 2.0.25 (operator decision 11: one release wave). Releases wait for the operator's rig look.

## 8. Open questions

- **Q1 (needs operator or a link check):** which public tracking URL does an Ozon posting number have (Ozon has a buyer tracking page by posting number)? C2 §1 found none declared. Link check (04.11.2026): no confirmed public pattern — the aggregator host `tracking.ozon-dostavka.ru` does not resolve and `ozon.ru/tracking` returns a bot challenge. Recommendation: confirm the format once in a rig browser; until confirmed the Ozon resolver returns the number WITHOUT a link (number-only is allowed by §2) — nothing is invented.

### Provisional (implemented unless he objects)

- P1 Ship as framework **2.0.25**; the minimum framework for Yandex/Ozon resolvers is 2.0.25.
- P2 Template override folder `woocommerce/woodev/shipping/order/tracking.php`.
- P3 Yandex framework mails stop printing the internal request id as `{tracking_number}` (empty) and carry the sharing link in `{tracking_url}`.
- P4 One framework block per order even when two carrier plugins are active (single hook registration, provider resolved per order).
- P5 Date only, not time, in the status line (site format `wc_date_format()`).
- P6 `Delivery_Status::buyer_label()` Russian wording as listed in §3.4; the operator may reword on the rig.
- P7 If core does not fire the hook on the thank-you page, add `woocommerce_thankyou` with a once-per-request guard (decided by rig R2, not re-asked).
