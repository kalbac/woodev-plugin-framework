# Vendored framework has no runtime autoloader — every `includes()` require must be complete

**Namespace:** `framework/includes-wiring`
**Discovered:** session 11 (2026-06-13), live e2e first real vendored boot

## The trap

`woodev/box-packer/class-item-implementation.php` declares
`class Woodev_Packer_Item_Implementation implements Woodev_Box_Packer_Item_With_Product`,
and it is `require_once`'d unconditionally in `Woodev_Plugin::includes()`. But the
interface file `box-packer/interfaces/interface-packer-item-with-product.php` was
**never required anywhere**. At runtime a vendored plugin has **no Composer
autoloader** — only the `require_once` chain in `includes()`. So the first real
v2 plugin boot fataled:

```
Fatal error: ... interface "Woodev_Box_Packer_Item_With_Product" not found
  in .../box-packer/class-item-implementation.php on line 7
```

= **WSOD on every real v2 plugin** (this was release-blocking; no prod plugin
shipped v2 yet, so it had never been hit).

## Why tests didn't catch it

Unit + integration runs load classes via the **Composer classmap**
(`composer.json` autoload), which resolves the interface on demand → the missing
`require_once` is invisible. Only a real install (no dev autoloader) exercises
the bare `includes()` chain.

## Rule

When adding a class that `implements`/`extends` another framework type, ensure
the parent's file is `require_once`'d **before** it in the correct `includes()`
(dependency order). Never rely on the classmap to prove load order — it masks
production fatals. Same class of bug as [[dispatcher-files-unwired-in-includes]].

Fixed: added `require_once .../interfaces/interface-packer-item-with-product.php`
right after `interface-packer-item.php` (parent before child) — `36209ee`, 2.0.1.

## Related
- [[dispatcher-files-unwired-in-includes]] — same "classmap masks unwired includes()" trap
- [[class-alias-phpstan-resolution]] — another classmap-vs-runtime divergence
