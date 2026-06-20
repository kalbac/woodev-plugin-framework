# EDD SL `package_download` token is DOMAIN-bound; account-install must use the purchase link

**Topic:** `[licensing/*]` — License/EDD store · **Discovered:** s26 (2026-06-20, #8 install-from-connector)

## Root cause

EDD Software Licensing exposes **two different** download mechanisms, and they are NOT interchangeable:

1. **`EDD_SL_Package_Download::get_encoded_download_package_url( $download_id, $license_key, $url )`** → `woodev.ru/edd-sl/package_download/{base64 token}`. This is the **updater** mechanism. On download, `parse_url()` runs `check_license([ 'key', 'item_id', 'url' ])` and **only serves the file when the license is `valid` AND activated for the embedded `$url` (domain)** — `EDD_Software_Licensing::check_license()` returns `site_inactive` (→ "activate it first") when `! $license->is_site_active( $url )`. So a site that has not activated that license for its own domain CANNOT use this URL.
2. **`edd_get_download_file_url( $order_item, $email, $file_key, $download_id, $price_id )`** (EDD **core**) → `site_url/index.php?eddfile=order_id:download_id:filekey&ttl=…&token=…`. This is the **purchase-receipt** link: bound to the **order/customer**, NOT to a domain. It respects the per-file download limit + TTL, but needs no license activation.

For **account-based install** (the consumer site owns the product via the customer's purchase but has no license activated for its own domain), mechanism #1 fails with `site_inactive`. Use mechanism #2.

## ❌ Wrong (account-install via the updater token)

```php
// Consumer site has no activated license for its domain → site_inactive → install fails.
$url = edd_software_licensing()->get_encoded_download_package_url( $download_id, $license_key, $consumer_url );
```

## ✅ Correct (purchase link, ownership already verified by the connector)

```php
$order_item = Purchases::owned_order_item( $customer_id, $download_id ); // proves ownership
$url        = edd_get_download_file_url( $order_item, $order->email, $file_key, $download_id, $price_id );
```

## Bonus: bypassing the per-file limit for installs (tamper-proof)

Installs do not put the zip "in hand", so the connector bypasses EDD's per-file limit on a **signed** path only:
- Register a `woodev_install` marker in `edd_url_token_allowed_params` → it is folded into EDD's URL token. `edd_validate_url_token()` only folds allow-listed params **present** in the URL, so normal downloads' tokens are unchanged, and appending the marker to any other signed URL changes the recomputed token → invalid.
- Filter `edd_is_file_at_download_limit` → `false` when the marker is present. Safe because `edd_process_signed_download_url()` validates the token (returns early with `has_access=false` on an invalid token) **before** the limit check — so the bypass filter only ever sees a token-validated marker.
- Abuse is capped by an **account-scoped** rate limit (`customer_id`+`download_id`), not per-connection — so connecting one account on many sites cannot multiply the allowance.

## Related

- [[license-need-vs-required]] — server is the licensing authority, not the client
- [[edd-sl-get-version-serialized-sections]] — the updater (`get_version`) payload shape
- Spec: `docs-internal/specs/2026-06-20-account-install-from-connector-design.md`
