# gotcha: the v2 license page only enqueues the React bundle CSS — server-rendered sections need their styles in style.scss

**Namespace:** `[admin-ui/license-page]`
**Discovered:** s14 (2026-06-14, OB-2)

## The trap

`Woodev_Admin_Pages::load_licenses_page_scripts()` enqueues exactly **two**
stylesheets on the "Woodev → Лицензии" page:

1. `wp-components` (native @wordpress/components styles)
2. `woodev-license-app` → `woodev/assets/build/license-page/style-index.css`
   (the compiled React bundle CSS, built from `src/license-page/style.scss`)

It does **NOT** enqueue the legacy `woodev/assets/css/admin/woodev-license-page.css`
— that file is enqueued **nowhere** (`rg "woodev-license-page" woodev --glob '*.php'`
returns no hits). It is dead pre-React CSS (mostly `.wrap-licenses .form-table`
rules for the Settings-API form that clean-break removed in 2.0.0).

The page also renders a **server-side** section below the React mount:
`license_page()` → `get_settings_section()` → `include html-settings-section.php`
(the Shop / Docs / Support / Telegram quick-links blocks, classes
`.woodev-settings-documentation .woodev-admin-block` etc.).

**Because that section's only stylesheet (the legacy file) is never enqueued, the
blocks rendered completely unstyled** — raw stacked rows. That was the visible
"криво-косо" in OB-2.

## The rule

Anything rendered on the license page — **including PHP-rendered markup like
`html-settings-section.php`** — must have its CSS in `src/license-page/style.scss`,
because `style-index.css` (built from it) is the only framework stylesheet loaded
on that page. The bundle CSS is global on the page, so it styles the server-rendered
DOM just fine. Do **not** revive the legacy `woodev-license-page.css` by enqueuing it
(it would drag in dead form rules + an off-theme gradient); port what you need into
`style.scss` and `npm run build`.

Also: `license_page()` must wrap its output in the standard WP admin
`<div class="wrap"><h1>…</h1><hr class="wp-header-end">…</div>` — without `.wrap`
the content has no admin gutter and sits flush against the menu.

## Related

- [[build-artifacts-eol-lf-windows-parity]] — rebuilding the bundle on Windows
- `woodev/admin/class-admin-pages.php` → `license_page()` / `load_licenses_page_scripts()`
- `src/license-page/style.scss` (source) → `woodev/assets/build/license-page/style-index.css` (built)
- `woodev/admin/pages/views/html-settings-section.php` (the server-rendered section)
