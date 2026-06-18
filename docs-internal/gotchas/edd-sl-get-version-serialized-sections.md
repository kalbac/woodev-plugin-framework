# EDD SL `get_version` returns `sections`/`banners`/`icons` as PHP-serialized STRINGS

**Tag:** `[licensing/edd-sl-get-version-payload]` · added s19 (2026-06-18)

## What

The EDD Software Licensing store action `edd_action=get_version` (built by
`EDD_Software_Licensing::get_latest_version_remote()`) returns JSON in which
`sections`, `banners`, and `icons` are **PHP `serialize()`d strings**, not nested
JSON objects:

```json
{
  "new_version": "3.2.0",
  "sections": "a:2:{s:11:\"description\";s:18:\"<p>…</p>\";s:9:\"changelog\";s:16:\"<p>…</p>\";}",
  "banners":  "a:2:{s:4:\"high\";s:0:\"\";s:3:\"low\";s:0:\"\";}",
  "icons":    "a:2:{s:2:\"1x\";s:…:\"…128x128.jpg\";s:2:\"2x\";s:…:\"…256x256.jpg\";}",
  "contributors": { "kalbac": { … } },   // ← JSON object → stdClass
  "msg": "…", "author": "…", "php_version": "…", "wp_version": "…"
}
```

So after `json_decode`, `$response->sections` is the **serialized string** — that
is exactly why `Woodev_Plugin_Updater::get_version_from_remote()` calls
`maybe_unserialize( $response->sections )`, which yields a PHP **assoc array**
`['description'=>html, 'changelog'=>html]`. `contributors` arrives as a JSON
object → stdClass (handled by `plugins_api_filter()`'s `convert_object_to_array`).

## Why it matters

- `show_update_notification()` reads `…->sections->changelog` as an **object**;
  `plugins_api_filter()`/`show_changelog()` convert it to an **array** for WP core.
  The updater must normalize `sections` to a **consistent shape (stdClass)** on the
  fresh path so it matches the cache round-trip (`get_cached_version_info()`
  json-decodes the stored value into an object). See OB-3 F1 (s19, PR #63).
- **`plugin_latest_version` and `plugin_information` are the IDENTICAL payload** —
  there is only one store action (`get_version`); both WP contexts
  (`pre_set_site_transient_update_plugins` and `plugins_api`) consume the same
  response. (Resolves OB-3 review Open Question #1.)
- The `msg`/`author`/`contributors`/`php_version`/`wp_version` extras are added by
  the store's `edd_sl_license_response` filter (readme.php), not the base response.

## Capture method (rig)

`docker cp` a probe into the issuer container and `wp eval-file` calling
`edd_software_licensing()->get_latest_version_remote([ 'edd_action'=>'get_version',
'item_id'=>23, 'slug'=>…, 'version'=>'2.0.0', 'url'=>… ])`, then
`var_export()` + `wp_json_encode()` the result. SL-enabled downloads are those with
`_edd_sl_enabled=1` (+ `_edd_sl_version`, `_edd_sl_changelog` meta).

## Related

- [[updater-cache-source-stamp-not-key]] — same updater, F10 cache value-stamp.
- [[in-plugin-update-message-arg-shape]] — same updater, F8 hook arg shape.
- `woodev/licensing/updater/class-plugin-updater.php` — `get_version_from_remote()`.
- `docs-internal/reviews/ob3-plugin-updater-review-2026-06-14.md` — F1/F3 + Open Qs.
