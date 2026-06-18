# OB-7 — «Woodev → Плагины» page redesign (React + woodev.ru account scaffold)

> Design spec. Written s21 (2026-06-18). Approved by operator before implementation.
> Companion plan: `docs-internal/plans/2026-06-18-plugins-page-ob7-redesign.md` (written next).

## Goal

Replace the dated, English, server-rendered «Woodev → Плагины» add-on showcase with a
modern WP-React page in the **same design language as the redesigned license page**, fully
RU-localized, and lay the **scaffold + full spec** for a future woodev.ru account connection
(showing which plugins the user already purchased), modeled on WooCommerce's WC Helper.

## Scope decision (operator, s21)

- **Phase A — THIS session (build + rig-verify):** React redesign of the public add-on
  catalog (live `woodev.ru/edd-api/v2` data) behind a `woodev/v1` REST proxy, RU-localized,
  + an **account UI scaffold** (states: not-connected / connecting / connected / «Мои плагины»)
  gated behind a feature flag, with the connect action inert until Phase B.
- **Phase B — LATER (spec now, build later, cross-project):** the OAuth-style connection to
  woodev.ru. The framework ships the client + UI; the matching OAuth endpoints are shipped by a
  **dedicated plugin `woodev-account-connector` on woodev.ru** (operator decision s21 — an
  isolated auth provider, NOT baked into woodev_theme). The live handshake is enabled only once
  that connector plugin exists. Fully specced here (§7).

## Data contract preservation (clean-break policy)

- **Admin page slug `woodev-extensions` is RELEASE-BLOCKING — preserve byte-for-byte.** Menu
  registration `Woodev_Admin_Pages::extensions_menu()` keeps the slug + `manage_options` cap.
- The new account option key (`woodev_account_data`, §7) is **new** — no existing contract.
- Internal controller API (`Woodev_Admin_Plugins::get_sections/get_all_extension/
  get_extension_by_query/output`) is **internal → free to delete/replace** on v2 (operator
  chose clean-break). The old view `views/html-admin-page-plugins.php` and the dead
  `wp_star_rating` block are removed (`rating` is not in the live API).

## Grounding — what the live store actually returns (verified s21)

- `GET woodev.ru/edd-api/v2/categories` → `{categories:[{slug,label}]}` (labels are RU).
- `GET woodev.ru/edd-api/v2/products/?number=-1` → `{products:[{info, pricing, licensing}]}`:
  - `info`: `id, slug, title, excerpt, content, link, permalink, purchase_link, thumbnail,
    thumbnails{small,medium}, category:[{slug,name,...}], tags:[...], price`.
  - `pricing.amount` — **string** (e.g. `"12500"`); RUB.
  - `rating` is **NOT present** (old star-rating block was dead code).
- The stand (:8888) **can reach** woodev.ru → catalog is rig-verifiable this session.

## 1. Architecture (parity with license page)

REST-proxy + React on the existing `woodev/v1` namespace (same `@wordpress/scripts` toolchain):

- New route **`GET /woodev/v1/extensions`** (permission: `manage_options`, matching the page):
  server fetches categories + products from `edd-api/v2`, **normalizes** to a lean shape,
  caches in a transient. Network/secrets stay server-side; React makes one `apiFetch`.
- React app **`src/plugins-page/`** mounts into `extensions_page()`. Enqueue only the bundle's
  `style-index.css` + `wp-components`; wrap output in `.wrap` + `<h1>` (gotchas
  `license-page-css-bundle-only`, `wp-scripts-jsx-runtime-wp66` → classic JSX runtime +
  `createElement`/`Fragment` imports, `build-artifacts-eol-lf-windows-parity` → `.gitattributes`
  already pins `woodev/assets/build/** text eol=lf`).
- `extensions_menu()` / `load_plugins_page_scripts()` updated to enqueue the React bundle
  instead of the legacy `woodev-plugins-page.css`. Menu label localized to «Плагины».

### Normalized REST shape (lean — only what the UI needs)

```json
{
  "categories": [ { "slug": "woocommerce", "label": "Плагины для Woocommerce" } ],
  "products": [
    {
      "id": 127940,
      "slug": "wildberries-woocommerce",
      "title": "Интеграция Woocommerce с Wildberries",
      "excerpt": "…",
      "thumbnail": "https://…-300x225.jpg",
      "permalink": "https://woodev.ru/downloads/wildberries-woocommerce",
      "price": 12500,
      "free": false,
      "categories": ["woocommerce", "marketing"]
    }
  ],
  "stale": false
}
```

- `price` cast to int from `pricing.amount`; `free` = `price <= 0`.
- `thumbnail` = `thumbnails.medium` ?? `thumbnails.small` ?? `thumbnail`.
- `categories` = list of `info.category[].slug` (for client-side filtering).
- `permalink` = `info.permalink` ?? `info.link`, decorated with UTM **server-side** via the
  existing `generate_utm_url()` logic (kept as a static helper).
- `stale: true` when the store was unreachable and cached/empty data is served.

## 2. Data flow

```
Page load → React mount → apiFetch('/woodev/v1/extensions')
  → { categories, products, stale }
→ card grid; category chips + search filter CLIENT-SIDE (everything is already in the payload)
→ card / price button → product permalink (with UTM) in a new tab
```

## 3. Layout

```
Плагины Woodev
Расширьте возможности магазина плагинами Woodev.
[ Поиск плагинов… 🔍 ]
─ ACCOUNT (feature-flagged; Phase A = scaffold) ─
  ⓘ «Подключите аккаунт woodev.ru, чтобы видеть купленные плагины»  [ Подключить аккаунт ](disabled)
─ [ Все ] [ Для WooCommerce ] [ Маркетинг ] [ Оплата ] … ─   ← category chips
grid 3/2/1:  [img] Название · excerpt · [ Купить за 12 500 ₽ ] / [ Бесплатно ]
```

- Card: thumbnail, title, excerpt (`wp_kses_post` server-side), price button.
- Shared design tokens / accents with the license page (reuse SCSS variables / patterns).

## 4. RU localization

All UI text RU. **No `_n()`** (gotcha `russian-source-i18n-plural-n`) — count-neutral phrasing.
Strings: «Плагины Woodev», page description, «Поиск плагинов», «Все», «Купить за %s ₽»,
«Бесплатно», «Подключить аккаунт woodev.ru», «Мои плагины», «Ничего не найдено»,
«Не удалось загрузить каталог, попробуйте позже». Price formatted with thousands separator.

## 5. Error / empty handling

- Store unreachable (`WP_Error` / non-200) → REST returns cached data if present, else empty
  `products` + `stale: true`. React shows «Не удалось загрузить каталог, попробуйте позже»
  (mirrors license-page resilience).
- Empty search / filter → «Ничего не найдено».

## 6. Components (React, `src/plugins-page/`)

- `index.js` — mount.
- `app.js` — fetch via `apiFetch`, hold `{search, category}` state, render Account + Catalog.
- `account-panel.js` — connect CTA / connected summary / «Мои плагины» list. **Phase A:** scaffold
  with the connect button disabled (gated by the localized `accountEnabled` flag → false).
- `category-filter.js` — chips («Все» + categories).
- `search-box.js`.
- `extension-grid.js` / `extension-card.js`.
- `filter.js` — **pure** helpers: `filterProducts(products, {search, category})`, `formatPrice(int)`.
  Pure so the logic is reviewable in isolation (no JS test runner — verified via rig browser,
  consistent with the license page).

## 6a. PHP (`woodev/`)

- New REST controller (e.g. `Woodev\Framework\REST_API\Extensions_Controller` or a method on the
  existing core REST handler — follow the license page's `woodev/v1` registration pattern).
- A normalizer (pure, static, unit-tested): raw EDD product object → lean array (§1 shape).
- `Woodev_Admin_Plugins` reduced to: the remote fetch + transient cache + UTM helper + normalize
  (or fold into the controller). Old `output()`/view path removed.

## 7. Phase B — woodev.ru account connection (SPEC ONLY this session)

Mirror WooCommerce WC Helper (verified against real WC source s21), adapted to EDD/woodev.ru.

### Framework-side client (build later)

- Class `Woodev_Account_Connection` (legacy-prefixed, lives under `woodev/account/` or licensing).
- **Connect:** `GET /woodev/v1/account/connect` builds the authorize redirect: POST
  `woodev.ru/wp-json/woodev-account/v1/oauth/request_token` `{home_url, redirect_uri}` → `{secret}`;
  redirect to `…/oauth/authorize?home_url&redirect_uri&secret`. CSRF via WP nonce in `redirect_uri`.
- **Return:** store admin handler verifies nonce, exchanges `request_token` → POST
  `…/oauth/access_token` `{request_token, home_url}` → `{access_token, access_token_secret, site_id}`.
- **Storage:** option **`woodev_account_data`** (NEW key), sub-keys `auth`
  `{access_token, access_token_secret, site_id, url, user_id, updated}` and `auth_user_data`
  `{name, email}` (from authenticated `/oauth/me`).
- **Request signing:** OAuth-style HMAC-SHA256 — `hash_hmac('sha256', wp_json_encode({host,
  request_uri, method, body}), access_token_secret)`; headers `Authorization: Bearer <token>` +
  `X-Woodev-Signature: <sig>`.
- **Purchases:** authenticated `GET …/woodev-account/v1/purchases` → list of owned downloads;
  cache in transient (`woodev_account_purchases`, ~3h, short TTL on failure).
- **Disconnect:** authenticated POST `…/oauth/invalidate_token`, then clear `woodev_account_data`
  + flush the purchases transient.

### woodev.ru-side endpoints — dedicated plugin `woodev-account-connector` (separate project)

Implemented as a **standalone plugin `woodev-account-connector`** installed on woodev.ru
(operator decision s21 — isolated auth provider, not woodev_theme code). It registers
`/wp-json/woodev-account/v1/oauth/{request_token, authorize, access_token, me, invalidate_token}`
and `/purchases`, reading purchase data from EDD (which has none of these natively — custom work).
Tracked cross-project; the framework UI stays feature-flagged (`woodev_extensions_account_enabled`,
default false) until the connector plugin is live.

## 8. Testing

- **PHP unit (Brain Monkey):** normalizer (raw product → lean shape, missing fields, free vs
  paid, thumbnail fallback, category slugs); REST permission gate; transient cache hit/miss;
  store-error → `stale` fallback.
- **Integration:** `/woodev/v1/extensions` route registered + returns the shape (remote mocked).
- **JS:** no runner (parity with license page) → rig browser verification on :8888 (catalog
  renders from live woodev.ru, search/filter work, error/empty states, 0 console errors).

## 8a. Polish round (s21, operator feedback)

Visual refinement after the first Phase-A merge, to match the WC-extensions feel + woodev.ru brand:

- **Wide layout** — `.woodev-extensions-wrap` `max-width: none` (was 1180px); fills the admin column.
- **Grid 4 / 2 / 1** — `repeat(4,1fr)`; `≤1280px → 2`; `≤782px → 1`.
- **Compact cards** — modeled on the woodev.ru «Похожие плагины» block: small 52px square icon + 2-line-clamped title in a header row, 3-line-clamped excerpt, full-width accent price button pinned to the bottom (flex column). Thumbnail uses `thumbnails.small` (150×150) instead of the medium banner.
- **Brand styling** — woodev.ru accent **cyan `#00C9FD`** (verified in-browser: it's the site link color; site font is Onest, dark bg `#080C10`). Applied on the price button, active category chip, chip hover, search focus ring, and card hover (cyan border + lift + shadow). The page stays light to sit in the wp-admin chrome — only the accent is borrowed.

### woodev.ru-side dependency (#3 icon + #4 coming-soon) — NOT in the current API

Verified s21: the public `edd-api/v2` products payload does **not** expose `_product_icon` or
`_coming_soon` (the `info` object is fixed; `fields=meta` is ignored). So:

- **#3 product icon** — the normalizer already prefers `info._product_icon` when present, else
  `thumbnails.small`. Works today with the square thumbnail; upgrades automatically once the store
  API exposes the icon.
- **#4 hide coming-soon** — the normalizer already drops a product when `info._coming_soon` (or a
  top-level `coming_soon`) is truthy. **No client-side signal exists today** (discontinued items like
  Беру.ру/GOODS.ru still return `status: publish`), so they keep showing until woodev.ru exposes the
  flag. This is forward-compatible — the moment the API includes it, they vanish.

To enable both, woodev.ru must extend its EDD API product payload (a tiny mu-plugin / theme snippet
on the production site — separate from this framework repo):

```php
// On woodev.ru: expose the product icon + coming-soon flag in edd-api/v2 products.
add_filter( 'edd_api_products_product', function ( $product_data, $product_info ) {
    $id = $product_info->ID;
    $product_data['info']['_product_icon'] = (string) get_post_meta( $id, '_product_icon', true );
    $product_data['info']['_coming_soon']  = (bool) get_post_meta( $id, '_coming_soon', true );
    return $product_data;
}, 10, 2 );
```

(Filter name/shape to be confirmed against the woodev.ru EDD version when implemented.)

## 9. Out of scope (this session)

- Live woodev.ru handshake / real purchases (Phase B, needs store endpoints).
- OB-8 (Woodev marketplace tab on `plugin-install.php`).
- Touching public `docs/` (operator decision s13).
- Bumping `VERSION` (v2.0.1 unreleased) — new symbols get `@since 2.0.2`.
