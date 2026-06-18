# Design Spec — «Woodev → Лицензии» page UI/UX redesign

> Status: **APPROVED** (operator, 2026-06-18, s19 brainstorm). Implementation deferred to s20.
> Scope: frontend React (`src/license-page/`) + SCSS + a small additive backend field. No installed-site data contract changes.
> This is the authoritative design. s20 should: (1) let the operator skim this, (2) invoke `writing-plans` to produce the implementation plan, (3) execute TDD + build + browser-verify on the rig + Codex inline critic.

## Goal

Redesign the license-management admin page (`admin.php?page=woodev-licenses`, slug `woodev-licenses`) for better UI and UX. The functionality (activate / deactivate / beta toggle / status messages) stays; the presentation, the key control, the action-button logic, and the responsive layout are reworked.

## Current problems (s19 analysis)

- Looks like default WP-admin; no visual hierarchy or brand character.
- One narrow card (min 360px) lost in a wide empty area.
- Key shown twice: masked display row **and** the same key in an editable input below — confusing.
- Mixed languages: server status messages are English (`Woodev_License_Messages`), UI chrome is Russian.
- Status communicated only by a small badge; no card-level color signal.
- Beta toggle cramped next to «Активировать».
- No dedicated «Продлить» action (renew link buried in the message text).
- Quick-links block competes visually with the license card.
- Intro is plain text.

## Backend facts the design is grounded in (verified s19)

- `Woodev_Plugins_License::get_state()` returns: `plugin_id, plugin_name, license_key, status, status_label, message` (kses-sanitized HTML), `message_variant` (success|warning|error|info), `expires` (raw: `'lifetime'`|date string|timestamp|''|null), `is_valid, is_active, is_need_license, beta_enabled`.
- `activate($key)` (REST `POST /woodev/v1/licenses/{id}/verify`) **saves the key to the option BEFORE the store request** — any key (invalid/expired/foreign) is persisted; the resulting status reflects the outcome.
- `deactivate()` (REST `…/deactivate`) deletes the license-data object but **does NOT delete the key option** — so "deactivate to clear the field" does not currently work.
- Raw EDD status tokens: `'' , valid, expired, site_inactive, no_activations_left, invalid, key_mismatch, item_name_mismatch, invalid_item_id, missing, missing_url, disabled, revoked, license_not_activable`.
- `is_valid === true` **only** when status is `valid` (and key present, license required).
- `Woodev_License_Messages::get_message()` already produces the contextual CTAs in `message`: `expired`→«renew», `no_activations_left`→«view upgrades» (= "increase limit"), `site_inactive`→inactive notice, expiring-soon→«renew». Renewal URL: `https://woodev.ru/checkout/?edd_license_key=…&download_id=…`.
- REST endpoints (woodev/v1): `GET /licenses/{id}`, `POST …/verify` (activate), `POST …/deactivate`, `POST …/beta`. The "Проверить" re-validation **reuses `/verify`** with the stored key.

## Approved layout

- **License cards:** responsive flex/grid — **3 per row** (large) → **2** (medium) → **1** (small).
- **Intro** (`.woodev-licenses-intro`): styled as an info-notice (background / border / left accent, like `.notice`), full content width (wide-width) — not plain text.
- **Quick-links** (`.woodev-settings-documentation`): compact cards modeled on `woodev.ru` `.card.card-compact` ("Сейчас в разработке" block) — **icon on the LEFT** (large, vertically centered), right column stacked **title → short description → CTA**. **Equal height** (tallest card drives the row). **4 per row** (large) → **2** (medium) → **1** (small).
- Each license card has a **left color accent bar** by status (green / yellow / red / grey).

## License card anatomy

Header: plugin name + version + status badge (with expiry where relevant); left accent bar by status.
Body: **single key control** as a form-group (no separate key-display row).
Footer: contextual action buttons (left) + **Бета** toggle (pinned right).

### Key control (form-group)

```
[ input ──────────────────────────── ][ 👁 ][ Проверить ]
```

- **No saved key:** `input` editable + placeholder «Укажите ваш ключ»; `👁` and `Проверить` **disabled**.
- **Saved key:** `input` masked `XXXX…XXXX` (first 4 + last 4 visible, middle masked), **read-only**; `👁` reveals the full key (client-side only, no request); `Проверить` re-validates the stored key via `/verify` and refreshes the card (for the "renewed but expiry still stale" case).
- `👁` and `Проверить` visually attach to the input as one group (buttons read as a continuation of the field).

### Beta toggle

- Pinned to the right edge of the footer. Label simply **«Бета»** + a tooltip on hover: «Разрешает устанавливать бета-версии плагина». Default OFF.

## State machine (on real EDD status tokens)

| Group | Statuses | Badge (variant) | Key field | 👁 / Проверить | Action buttons |
|---|---|---|---|---|---|
| **A. No key** | `''` | «Не активирована» (grey/info) | editable, placeholder | off / off | `[Активировать]` disabled → enabled once input non-empty |
| **B. Active** | `valid`, expiry > 1 mo or `lifetime` | «Активна · до DD.MM.YY / Бессрочно» (green) | masked, read-only | on / on | `[Продлить]` · `[Деактивировать]` (red) |
| **B′. Expiring soon** | `valid`, expiry < 1 mo | «Истекает DD.MM» (yellow) | masked, read-only | on / on | `[Продлить]` · `[Деактивировать]` |
| **C. Expired** | `expired` | «Истекла · DD.MM.YY» (red) | masked, read-only | on / on | `[Продлить]` (accent) · `[Деактивировать]` |
| **D. Binding / limit** | `site_inactive`, `no_activations_left` | «Активна на другом сайте» / «Лимит исчерпан» (red) | masked, read-only | on / on | `[Активировать]` (try to claim a slot) + CTA from `message` («увеличить лимит» / upgrades) |
| **E. Bad key** | `invalid, key_mismatch, item_name_mismatch, invalid_item_id, missing, missing_url, license_not_activable` | «Неверный ключ» (red) | **editable** (correct it) | off / off | `[Активировать]` (retry with corrected key) |
| **F. Revoked** | `disabled, revoked` | «Ключ отозван» (red) | masked, read-only | on / on | `message` only; no `[Активировать]` |
| **S0. No license required** | `is_need_license === false` | «Лицензия не требуется» (info) | hidden | — | only Бета toggle (keep current behavior) |

**Editability principle (resolved):** field is **editable** only when the key itself is suspect (groups A, E); **masked + read-only** when the key is genuine and the problem is term / site / slots / revocation (B–D, F).

## Resolved decisions

1. **Editability** — per the principle above (editable in A and E only). Approved.
2. **Changing a saved key** — (a) in group E the field stays editable, so the user corrects the key in place; (b) optionally add an «Изменить ключ» affordance that clears the field where needed. `deactivate()` backend behavior is **unchanged** (still keeps the key option). Approved (a + optional b).
3. **«Продлить» button** — add an additive **`renewal_url`** field to `get_state()` (single source of truth, server-side) and point the button at it. Approved. (Reuse `Woodev_License_Messages::get_renewal_link()` logic / extract a shared helper.)

## Backend changes (minimal, additive)

- Add `renewal_url` to `get_state()` (built from the same checkout URL + `edd_license_key` + `download_id` as `get_renewal_link()`). Additive field — safe; covered by a PHP unit test.
- (Optional) add `upgrades_url` if the «увеличить лимит» CTA should be a dedicated button rather than rely on the `message` link — decide during planning.
- No change to REST routes, `activate()`, `deactivate()`, the cache, or any option key.

## Localization (related, in-scope)

- `Woodev_License_Messages` source strings are English while the UI is Russian. Localize them to Russian (the framework's i18n convention — Russian is the source language). Keep the embedded CTA links.

## Files in scope

- `src/license-page/app.js`, `license-card.js`, `index.js`, `style.scss` (+ rebuild `woodev/assets/build/**`).
- `woodev/licensing/class-plugin-license.php` (`get_state()` → `renewal_url`).
- `woodev/licensing/class-license-messages.php` (RU localization; maybe extract renewal-URL helper).
- Server-rendered quick-links view `woodev/admin/pages/views/html-settings-section.php` (compact-card markup) + its SCSS.
- Tests: PHP unit for `renewal_url` in `get_state()`; JS — no harness currently (note in plan); browser-verify on the rig.

## Out of scope

- No change to license enforcement logic, REST routes, cache keys, or any installed-site data contract.
- No multisite-specific work.
- F8 multisite custom update-row verification (separate, deferred).

## Verification plan (s20)

- TDD for the backend `renewal_url` addition; `composer check` green.
- `npm run build`; commit both `src/` and `woodev/assets/build/` (build-parity CI — gotcha `build-artifacts-eol-lf-windows-parity`).
- Browser-verify each state on the two-stack rig (issuer :8090 / stand :8888); the stand already points `woodev_license_base_url` at the issuer. Drive different statuses by activating different issuer downloads / expiry data.
- Codex inline-bundle critic before PR; merge explicitly after confirmed-green CI.

## Related

- `docs-internal/gotchas/license-page-css-bundle-only.md` — the page enqueues only `style-index.css` + `wp-components`; server-rendered sections must put styles in `src/license-page/style.scss`, and `license_page()` must wrap output in `.wrap` + `<h1>`.
- `docs-internal/gotchas/wp-scripts-jsx-runtime-wp66.md` — classic JSX runtime (babel.config.js); import `createElement`/`Fragment` in every JSX file.
- `docs-internal/gotchas/build-artifacts-eol-lf-windows-parity.md` — commit both src and build; `.gitattributes` pins LF.
- `docs-internal/gotchas/russian-source-i18n-plural-n.md` — Russian is the source language; avoid `_n()`.
- `woodev/licensing/class-plugin-license.php`, `class-license-messages.php`, `api/class-rest-api-license.php`.
