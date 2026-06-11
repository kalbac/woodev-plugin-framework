---
id: s6-p4-react-license-app
title: React license-page app (card grid, @wordpress/components, apiFetch actions, masked key + eye) + enqueue + mount, legacy form removed
phase: S3.2 license UI — React app + page render (spec §5.2/§5.3, plan task s6-p4)
type: feature
model: sonnet
touches_contract_zone: true
writes_guard: false
file_set:
  - src/license-page/index.js
  - src/license-page/app.js
  - src/license-page/license-card.js
  - src/license-page/style.scss
  - woodev/assets/build/license-page/
  - woodev/admin/class-admin-pages.php
  - tests/unit/LicensePageRenderTest.php
depends_on: [ s6-p2-rest-license-controller, s6-p3-wp-scripts-scaffold ]
contract_zones_touched: [ admin_page_slugs ]
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0, all unit tests pass)
  - "src/license-page: index.js wires wp.apiFetch middlewares (createRootURLMiddleware(window.woodevLicenses.restRoot) + createNonceMiddleware(window.woodevLicenses.restNonce)) and createRoot-renders <App/> into #woodev-licenses-app (bail silently if mount absent); app.js renders an intro paragraph (the old license_section_description text: account-page link https://woodev.ru/my-account) + an adaptive 2-4 column card grid over window.woodevLicenses.plugins; license-card.js renders one plugin per spec §5.2"
  - "Card anatomy: Card/CardHeader (plugin_name + status badge pill colored by message_variant)/CardBody (Notice status={message_variant} isDismissible=false with message; key field)/CardFooter (actions). Key field: when a key exists show it MASKED (all chars as bullets except last 4) with an eye Button (icon) toggling full reveal client-side only; TextControl for entering/replacing a key (readonly while is_valid, matching legacy UX); Verify Button variant=primary isBusy while the request runs; Deactivate Button isDestructive guarded by __experimentalConfirmDialog (or Modal fallback); ToggleControl for beta persisting on change via POST .../beta"
  - "Per-card state is replaced wholesale by each REST response (the returned get_state() array); request errors surface the WP_Error message in an error Notice — no silent catch"
  - "License-free card (is_need_license=false): ONLY an info Notice ('Лицензия для этого плагина не требуется.') + the beta ToggleControl; no key controls, no verify/deactivate"
  - "i18n: every user-facing JS string wrapped in __() from @wordpress/i18n with text domain woodev-plugin-framework, Russian source, NO _n()/plural forms (count-neutral phrasing) — gotcha i18n/russian-source-plural-n"
  - "PHP Woodev_Admin_Pages: license_page() echoes ONLY <div id=\"woodev-licenses-app\"></div> plus the existing get_settings_section() include (kept per spec §5.3); NO <form>, no settings_fields/do_settings_sections/submit_button; license_page_init()'s add_settings_section + license_section_description() removed (text moved into the React app)"
  - "load_licenses_page_scripts(): wp_enqueue_style('wp-components'); enqueue build/license-page/style-index.css (deps wp-components) + build/license-page/index.js with dependencies+version read from index.asset.php (file_exists fallback to plugin version + empty deps); wp_add_inline_script BEFORE: window.woodevLicenses = wp_json_encode of { restRoot: esc_url_raw( rest_url() ), restNonce: wp_create_nonce('wp_rest'), plugins: [ get_state() for each Woodev_Plugins_License::get_registered_instances() ] }; legacy woodev-license-page.css enqueue dropped"
  - "Admin slug contract preserved: page woodev-licenses under parent woodev, menu registration untouched"
  - "tests/unit/LicensePageRenderTest.php: license_page() output contains id=\"woodev-licenses-app\" and contains no '<form'; load_licenses_page_scripts() enqueues the build handle with deps/version from a fixture index.asset.php and inlines restRoot/restNonce/plugins JSON; B-7: both tests run with ZERO WooCommerce functions defined (Brain Monkey) -> no Error"
  - "Bundle REBUILT and committed (woodev/assets/build/license-page/*) matching the new src/ — npm run build, second run leaves git clean"
---

# Task

Implement plan task **s6-p4** of `docs-internal/platform-v2-s3-licensing-ui-plan.md`
(spec `docs-internal/platform-v2-s3-licensing-ui-spec.md` §5.2/§5.3 — design locked:
card grid, native `@wordpress/components`, masked key + eye, license-free card).

Lean maximally on `@wordpress/components` — no bespoke design system; `style.scss`
holds only the grid layout + status badge pill. Initial data comes from the localized
`window.woodevLicenses` (no fetch on mount); every action replaces that card's state
with the REST response.

REST endpoints (from s6-p2): `GET /woodev/v1/licenses/{plugin_id}`,
`POST .../verify {license_key}`, `POST .../deactivate`, `POST .../beta {enabled}` —
all return the `get_state()` array; errors are `WP_Error` JSON with `message`.
With the apiFetch root middleware, call paths as `/woodev/v1/licenses/...`.

For the PHP render tests, mind that `Woodev_Plugins_License::get_registered_instances()`
is a static registry — seed it via reflection (gotcha
`testing/reflection-setaccessible-version-guard`) or stub instances created through
`newInstanceWithoutConstructor`.

## What NOT to change
- REST controller / pure ops (s6-p1/p2 surfaces are fixed).
- ci.yml (s6-p5). Menu/slug registration in licenses_menu().
- The old `woodev/assets/css/admin/woodev-license-page.css` FILE may stay on disk
  (only its enqueue goes) — deleting it is optional polish, not required.

## Gotchas to honor
- `i18n/russian-source-plural-n`, `testing/reflection-setaccessible-version-guard`,
  `testing/brain-monkey-function-pollution`.
- Conventions: WPCS for PHP; JS follows @wordpress/scripts defaults (JSX, tabs per
  .editorconfig if present, otherwise wp-scripts lint defaults).

## Verification
- `npm run build` clean-parity; `./vendor/bin/phpunit tests/unit/LicensePageRenderTest.php`; full `composer check` green.
- Report: confirm no `_n()` and no English user-facing strings slipped into the JS.
