# gotcha: enqueue the wp-scripts `style-index.css` with its OWN filemtime, not the JS bundle's asset-hash version

**Namespace:** `[build/css-enqueue-version]`
**Discovered:** s31 (2026-06-23)

## Symptom

A `@wordpress/scripts` entry emits two artifacts: `index.js` (+ `index.asset.php`
carrying a content `version` hash) and a separate `style-index.css` extracted
from the imported SCSS. If you enqueue the stylesheet using the JS bundle's hash:

```php
$asset = require $build_dir . '/index.asset.php';
wp_enqueue_script( 'handle', "$url/index.js", $asset['dependencies'], $asset['version'] );
wp_enqueue_style( 'handle', "$url/style-index.css", [ 'wp-components' ], $asset['version'] ); // ❌
```

then a **SCSS-only rebuild does not change `$asset['version']`** (the hash in
`index.asset.php` is derived from the JS module graph, not the CSS). The browser
keeps serving the stale cached CSS — your style changes look "not applied" even
though the file on disk is correct. This wastes a lot of rig-debugging time
because the markup/JS update fine while the CSS silently lags.

## The fix (applied s31)

Version the stylesheet by its OWN file modification time:

```php
$style_path    = $this->plugin->get_framework_path() . '/assets/build/setup-wizard/style-index.css';
$style_version = file_exists( $style_path ) ? (string) filemtime( $style_path ) : $asset['version'];
wp_enqueue_style( 'handle', "$url/style-index.css", [ 'wp-components' ], $style_version ); // ✅
```

Now any rebuild that touches the CSS bumps the query-string version and busts the
cache; a JS-only rebuild that leaves the CSS untouched keeps the same version.

## Note

This is about CACHE-BUSTING at runtime, independent of the "Assets build parity"
CI check (which compares committed artifacts byte-for-byte — see
[build-artifacts-eol-lf-windows-parity](build-artifacts-eol-lf-windows-parity.md)).
Both matter: pin EOL to LF for parity, and version CSS by mtime for cache-busting.

## Related

- [gotchas/build-artifacts-eol-lf-windows-parity.md](build-artifacts-eol-lf-windows-parity.md) — LF pin for build-parity
- [gotchas/wp-scripts-jsx-runtime-wp66.md](wp-scripts-jsx-runtime-wp66.md) — classic JSX runtime for WP 6.3+
