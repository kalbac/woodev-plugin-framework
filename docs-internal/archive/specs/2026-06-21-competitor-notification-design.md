> Archived s60 (2026-08-09): work shipped and merged; design executed.

# Spec — Competitor Notification module (v2 rework)

> Status: **DRAFT — design approved (s27), implementation deferred to s28** · Date: 2026-06-21 · `@since 2.0.2`
> Origin: s27 brainstorm. Promotes the v1 raw competitor-detection script into a clean, reusable v2 framework module.

---

## 1. Context & problem

A v1 framework class `Woodev_Competitor_Notification_Handler` (lives in shipped plugins' bundled framework, e.g. `woocommerce-yandex-delivery/woodev/handlers/competitor-notification.php`; subclass `Yandex_Delivery_Competitor` in `…/includes/class-plugin-competitor-notices.php`) detects rival plugins on the user's site and shows WC Admin notes — either recommending our alternative or warning about a conflict. It is **absent from the v2 framework** and is "very raw" (operator). The v2 framework already ships `Woodev_Notes_Helper` (`woodev/admin/class-notes-helper.php`) as substrate.

Weaknesses of the v1 script (verified by reading it):
1. **Domain knowledge baked into the abstract base** — `is_cdek_active()`/`is_russian_post_active()`/etc. + shipping-specific marketing copy live in the framework base (violates PLANS §1 "plugins keep only domain logic").
2. **Imperative, repetitive subclass** — same `if active → add note → else delete` repeated per competitor.
3. **Hits gotcha `is-enhanced-admin-available-always-true`** — gates WC-Admin code on `Woodev_Plugin_Compatibility::is_enhanced_admin_available()` (always true).
4. **Platform-coupled** — WC-Admin-Notes only (with a generic admin-notice fallback).

## 2. Decisions (s27 brainstorm, operator-approved)

1. **Both modes are first-class, via declarative rules** with a `mode` field: `recommend` (rival → suggest our alternative) and `conflict` (third-party rival → warn it may conflict, offer to deactivate it).
2. **Smart recommend action tied to the account/install ecosystem (s24–s26), with graceful degradation.** Because WC Admin note actions are plain links (not AJAX), this is a smart **link target**, not an in-note one-click install.
3. **Platform-neutral engine + pluggable renderers** — WC-Admin Notes when WooCommerce admin is present, plain admin-notices as fallback.
4. **Framework provides default i18n message templates** (per mode, parameterized by competitor/product name); the **competitor→product mapping stays in the plugin**. No central competitor registry (YAGNI).
5. **Respect dismissal; auto-delete the note when the competitor is deactivated; no forced re-surface** (v1's actioned→unactioned re-surfacing is dropped as too aggressive for cross-sell).

## 3. Architecture & components

New PSR-4 namespace `Woodev\Framework\Competitor\` (new code → namespaced per conventions).

- **`Competitor_Notification_Handler`** (abstract engine). A plugin extends it and implements `get_competitor_rules(): array`. The engine: iterate rules → detect → suppress → pick renderer → create/update/delete notes. Constructed with the owning `Woodev_Plugin`.
- **`Competitor_Rule`** (value object) — normalizes a raw rule array, validates `mode`, exposes typed accessors. Plugin declares plain arrays; the engine normalizes to VOs.
- **`Competitor_Notice_Renderer`** (interface) with two implementations:
  - **`WC_Admin_Notes_Renderer`** — used when `class_exists( \Automattic\WooCommerce\Admin\Notes\Note::class )` (this is the gotcha fix — NOT `is_enhanced_admin_available()`). Builds/saves a `Note` via `Woodev_Notes_Helper`.
  - **`Admin_Notice_Renderer`** — fallback via the plugin's `Woodev_Admin_Notice_Handler` (dismissible, keyed by note name).
- **Wiring:** `Woodev_Plugin::init_competitor_handler()` (opt-in, default off) — calls `get_competitor_notification_handler(): ?Competitor_Notification_Handler` which returns `null` by default; a plugin overrides it to return its subclass. Added to the `__construct()` `init_*_handler()` sequence (same opt-in pattern as `init_setup_wizard_handler()`). The engine is platform-neutral, so init lives in base `Woodev_Plugin`; the WC specifics are isolated in the renderer.

## 4. Rule model & detection / suppression

```php
protected function get_competitor_rules(): array {
    return [
        [ 'detect' => 'cdek.php',                 // string|string[]; note fires if ANY is active
          'mode'   => 'recommend',
          'our_download_id' => 42,                // for the smart link target (#8 / owned check)
          'our_url'         => 'https://woodev.ru/downloads/wc-edostavka-integration',
          'our_name'        => 'Интеграция СДЭК для WooCommerce',
          'our_plugin_file' => 'woocommerce-edostavka.php',  // suppression: skip if OUR equivalent active
          // optional overrides: 'title', 'content', 'image'
        ],
        [ 'detect' => 'yandex-go-delivery.php', 'mode' => 'conflict' ],  // text + action defaulted by mode
    ];
}
```

- **Detection:** `$this->get_plugin()->is_plugin_active( $slug )` for each `detect` slug (any-match).
- **Suppression (recommend):** if `our_plugin_file` is set and active → skip (user already has ours). If `our_plugin_file` omitted → degrade, still show.
- **Note name (auto):** `woodev-competitor-{mode}-{slug}` for dedup.
- **Auto-delete:** when no `detect` slug is active → delete the rule's note (by name/source). Runs on admin load.

## 5. Rendering & dismissal behavior (decision 5)

- Create the note **once**; the WC inbox owns dismissal. **No forced re-surface** (drop v1's actioned→unactioned bump).
- **Auto-delete** the note when the competitor is deactivated (detection re-runs on admin load).
- Renderer selection by `class_exists( Note::class )`; fallback `Admin_Notice_Renderer` is dismissible and keyed by note name so it is not re-shown after dismissal.
- Trigger on the relevant admin screen(s) (the v1 `maybe_add_note_on_screen` idea), minus the forced re-surface.

## 6. Smart recommend action (decision 2 — smart link target)

For `recommend` rules, the note's primary action URL is chosen at render time:
- **account connected (`Woodev_Account_Connection::is_connected()`) AND product owned (`Woodev_Account_Purchases`, by `our_download_id`)** → link to the in-admin catalog `admin_url( 'admin.php?page=woodev-extensions' )` (where the #8 React install button lives; optionally a query arg highlighting `our_download_id`).
- **otherwise** → link to `our_url` (product / buy page).
- **Graceful degradation:** if the account classes are unavailable or not connected → always `our_url`.
- **`conflict`** rules: primary action = "Отключить {plugin}" (nonce'd deactivate link, as v1 `get_deactivation_link()`) + a dismiss action.

> Exact owned-check method on `Woodev_Account_Purchases` and the highlight query-arg on the extensions page are pinned at plan time (the classes exist from s24–s26; this spec names the collaborators, the plan wires the exact calls).

## 7. Default templates (decision 4)

Framework ships i18n default message builders for each mode (mirroring v1's `get_recommendation_notice_content()` / `get_competitor_notice_content()`, parameterized by competitor + product name). A rule may override `title`/`content`. Russian source strings are intentional (CLAUDE.md i18n note). The competitor→product mapping is never in the framework — only in the plugin's `get_competitor_rules()`.

## 8. Testing

Unit (Brain Monkey + Mockery; no WP):
- detection any-match; recommend suppression when our plugin active / degrade when `our_plugin_file` omitted;
- renderer selection (WC vs fallback) by `class_exists( Note::class )` — explicitly covers the gotcha fix;
- rule normalization + `mode` validation (invalid mode rejected);
- auto-delete when competitor inactive;
- smart link target: connected+owned → extensions page; else → product URL; degraded (no account) → product URL;
- default template interpolation + per-rule override.

`composer check` green (currently 729 unit). After adding the new classes, run `php bin/generate-class-map.php` and commit the map (gotcha `framework-classmap-autoload-vendored-boot`; no Composer in shipped plugins).

## 9. Out of scope

- Migrating the yandex (or any) plugin's subclass onto the new API — done when that plugin is rewritten onto v2.
- A central framework competitor registry (decision 4: rejected).
- The install mechanics themselves (reuse the shipped #8 flow; not touched here).
- Setup Wizard (separate brainstorm — backlog OB-10).

## 10. Open items for the plan

1. Exact `Woodev_Account_Purchases` owned-by-download_id accessor + whether to add a highlight query-arg (`?highlight={id}`) to the extensions page React app.
2. Whether the fallback `Admin_Notice_Renderer` should reuse the existing dismissible-notice mechanism as-is or needs a per-competitor dismiss key.
3. Confirm the admin-screen trigger point (`admin_init` vs a wc-admin notes hook) and that it does not run on the front end.
