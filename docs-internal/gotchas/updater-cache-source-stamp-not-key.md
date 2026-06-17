# Isolating a cache by source without changing a frozen option key — stamp metadata inside the value

> Namespace: `[php/*]` (caching / frozen-contract pattern). Discovered s18 (OB-3 F10, 2026-06-17).

## Problem

`Woodev_Plugin_Updater`'s version cache key is `'woodev_' . md5( serialize( slug . license . beta ) )`
— it does **not** include the licensing endpoint (`woodev_license_base_url`). So if the
endpoint is switched (e.g. staging ↔ prod store), a value cached against store A could be
served for up to 3h while pointing at store B (stale cross-store data).

The obvious fix — fold the endpoint into the cache key — is **forbidden**: the cache
**option key is a frozen installed-site contract** (ADR-005 "never break" list). Changing
it orphans every existing cached option and is a contract break.

## Pattern: stamp + validate source metadata *inside the value*

Keep the key byte-identical; record the source endpoint in the option **value** and
reject a mismatch on read:

```php
// set_version_info_cache()
$data = array(
    'timeout' => strtotime( '+3 hours', time() ),
    'value'   => wp_json_encode( $value ),
    'source'  => $this->api_url,   // <-- stamp
);

// get_cached_version_info(), right after the timeout-expiry check
if ( ! isset( $cache['source'] ) || $cache['source'] !== $this->api_url ) {
    return false;   // different endpoint OR old unstamped cache => miss
}
```

- `$this->api_url` = `trailingslashit( $this->api_handler->get_url() )`, set once in the
  constructor — both write and read use the same value, so the compare is symmetric.
- Old caches lacking `source` become a **one-time miss** (refresh), harmless.
- The option **value shape is internal** (read only by this class) — adding a sibling key
  is free; only the **key** is the contract.

## General rule

When you need to invalidate/partition a cache that is keyed by a **frozen** option name,
don't change the key — **stamp the discriminator into the value and validate it on read**.
Same move applies to any transient/option whose name is a data contract.

## Related

- `docs-internal/reviews/ob3-plugin-updater-review-2026-06-14.md` — F10 (and F3, the still-blocked key-isolation finding).
- `woodev/licensing/updater/class-plugin-updater.php` `set_version_info_cache()` / `get_cached_version_info()`.
- [[in-plugin-update-message-arg-shape]] — sibling OB-3 Step 4 finding (F8).
- CLAUDE.md "Backward Compatibility — clean-break policy" → installed-site data contracts (never break).
