# Deleting a framework file has a tail: `includes()`, the class map, and fixture bootstraps

**Namespace:** `[framework/autoload]` · **Discovered:** s45 (2026-07-31), four times on one branch

## The trap

The SP-5 plan said "delete these 9 files, nothing references them". The grep that backed that claim
was run against `use` statements and class names — and it was right about those. It missed the
wiring, because **the wiring does not mention the class**, it mentions the path:

```php
require_once $path . '/map/class-leaflet-map-provider.php';
```

A `require_once` on a file that no longer exists is a **fatal on every real vendored boot**. The
framework ships without Composer autoload in production, so `Shipping_Plugin::includes()` plus the
generated `woodev/class-map.php` are the only wiring there is.

It bit four times in one branch, in three different locations:

1. `includes()` — three requires for the June pickup cycle (found by the task brief)
2. `includes()` again — a second require for `class-pickup-point-filter.php` in a different block,
   which the brief did not know about; leaving it fataled three fixture-loading tests
3. `includes()` a third time — the require for the deleted abstract pickup-points controller
4. **`tests/_fixtures/woodev-yandex-pilot-plugin/woodev-yandex-pilot-plugin.php`** — an entirely
   independent pair of requires in a fixture bootstrap, nowhere near `includes()`

## ✅ The checklist for deleting any framework file

1. Grep for the **class name** (`use`, type hints, `@see`, `instanceof`, `new`).
2. Grep for the **file path** — `require`, `require_once`, `include` — across `woodev/` **and**
   `tests/_fixtures/`, and expect more than one hit per file.
3. Grep for **asset handles** if it is JS/CSS (`wp_enqueue_script`, `wp_register_style`).
4. Regenerate the class map (`php bin/generate-class-map.php`) and confirm
   `git diff --exit-code woodev/class-map.php` is clean on a **second** run.
5. Run the **full** unit suite, not a targeted subset. The three fixture-loading tests
   (`RealisticShippingFixtureTest`, `EdostavkaPilotFixtureTest`, `YandexPilotFixtureTest`) actually
   execute `includes()`, and they are what catches a dangling require. A targeted run does not.

## The deeper problem

Half the shipping tree is hand-listed in `includes()`; the newest classes (§8 and all of SP-5) are
resolved by the s27 runtime class-map autoloader and appear in no `includes()` at all. There is no
written rule for which files belong where, so the manual list will keep drifting and this trap will
keep firing. Tracked as issue #138 — decide whether `includes()` should exist at all.

## Related

- [[framework-classmap-autoload-vendored-boot]] — the class map is the only resolver in production
- [[dispatcher-files-unwired-in-includes]] — the inverse: a new file never wired in
- [[box-packer-interface-unwired-in-includes]] — the same inverse, release-blocking, caught on a live boot
