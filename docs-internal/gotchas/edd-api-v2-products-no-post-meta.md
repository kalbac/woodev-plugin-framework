# woodev.ru `edd-api/v2` products payload is fixed EDD fields — no post meta

**Topic:** `[licensing/*]` · Discovered s21 (2026-06-18), OB-7 plugins-page polish.

## Problem

The public storefront API `https://woodev.ru/edd-api/v2/products/` returns a **fixed** EDD
structure per product: `info` (`id, slug, title, create_date, modified_date, status, link,
permalink, content, excerpt, thumbnail, thumbnails{small,medium}, category, tags, image,
purchase_link, price`), `pricing` (`amount`, a **string**), and `licensing`. That's it.

**Arbitrary post meta is NOT included** — `_product_icon`, `_coming_soon`, a numeric `rating`,
etc. are absent, and passing `?fields=meta` is ignored (no effect). So you **cannot** filter
coming-soon products, show a custom product icon, or display a rating from this endpoint as-is.
There is also no client-side signal for coming-soon: discontinued items (Беру.ру, GOODS.ru) still
return `status: "publish"` and a normal `purchase_link`.

Note `rating` is likewise absent — the old server-rendered plugins view called
`wp_star_rating( ['rating'=>$addon->rating] )` on a field that never arrives, so the stars were
**dead code** (removed in OB-7).

## ✅ Correct

Two-part pattern:

1. **Framework side — make the normalizer forward-compatible.** Consume the fields *when present*
   so no framework change is needed once the API exposes them:
   ```php
   $thumbnail = $info->_product_icon ?? $info->thumbnails->small ?? $info->thumbnail ?? '';
   if ( ! empty( $info->_coming_soon ) || ! empty( $raw->coming_soon ) ) { return null; }
   ```
2. **Store side — extend the API on woodev.ru** (a mu-plugin / theme snippet, separate repo):
   ```php
   add_filter( 'edd_api_products_product', function ( $data, $info ) {
       $id = $info->ID;
       $data['info']['_product_icon'] = (string) get_post_meta( $id, '_product_icon', true );
       $data['info']['_coming_soon']  = (bool)   get_post_meta( $id, '_coming_soon', true );
       $data['info']['rating']        = (float)  get_post_meta( $id, '_product_rating', true );
       return $data;
   }, 10, 2 );
   ```
   (Confirm the exact meta key names + filter signature against the live EDD version — the keys
   above are assumed and must be verified in `woodev-core` / on woodev.ru.)

## Related

- `woodev/rest-api/controllers/class-rest-api-extensions.php` — `normalize_product()` (forward-compatible reads)
- `docs-internal/specs/2026-06-18-plugins-page-ob7-redesign-design.md` §8a — the store-side dependency
- [[edd-sl-get-version-serialized-sections]] — another EDD wire-shape gotcha
