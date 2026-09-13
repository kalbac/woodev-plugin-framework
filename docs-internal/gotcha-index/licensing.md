# Gotcha index — [licensing/*] License/EDD store

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [licensing/two-layer] **`is_need_license()` (presentation) vs `is_license_required()` (enforcement).** → [license-need-vs-required](../gotchas/license-need-vs-required.md)
- [licensing/remote-deactivation] **A single-plugin site cannot render its own deactivation banner — accepted by design.** → [single-plugin-site-cannot-render-its-own-deactivation-banner](../gotchas/single-plugin-site-cannot-render-its-own-deactivation-banner.md) (s12)
- [licensing/edd-sl-get-version-payload] **EDD SL `get_version` returns `sections`/`banners`/`icons` as PHP-serialized STRINGS.** → [edd-sl-get-version-serialized-sections](../gotchas/edd-sl-get-version-serialized-sections.md) (s19)
- [licensing/edd-error-vs-license] **EDD reports activation failures via `error`, not `license` — but only TOKEN errors.** → [edd-error-field-vs-license-status](../gotchas/edd-error-field-vs-license-status.md) (s20)
- [licensing/edd-api-no-meta] **woodev.ru `edd-api/v2` products payload is fixed EDD fields — no post meta.** → [edd-api-v2-products-no-post-meta](../gotchas/edd-api-v2-products-no-post-meta.md) (s21)
- [licensing/edd-sl-package-vs-purchase-url] **EDD SL `package_download` token is DOMAIN-bound; account-install must use the purchase link.** → [edd-sl-package-download-domain-bound](../gotchas/edd-sl-package-download-domain-bound.md) (s26)
- [licensing/option-keys] **License-key option double-prefix for plugin ids starting with `woodev`.** → [license-key-option-double-prefix](../gotchas/license-key-option-double-prefix.md) (s11)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
