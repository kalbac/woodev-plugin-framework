# Gotcha: [shipping/location] — user meta is NETWORK-wide on multisite; per-site data goes through `*_user_option()`
> Tags: multisite, user-meta, location-provider | Session: s189

## What happens

On a multisite network users and their meta are shared by every site. The customer's chosen locality
(`Customer_Location_Store`) lived in plain user meta, so a choice made on the CDEK site appeared on the
Yandex site on the customer's first visit — and carried CDEK codes into a site whose provider is DaData.

## Root cause

`update_user_meta()` / `get_user_meta()` write one row per user, not per site. WooCommerce's own
billing/shipping address behaves the same, but OUR record carries provider-specific codes (CDEK city code,
DaData FIAS id), so on a site with another provider it is foreign.

## Fix

Store site-scoped customer data with `update_user_option()` / `get_user_option()` / `delete_user_option()`:
the row becomes `{$wpdb->get_blog_prefix()}woodev_customer_location` (`wp_woodev_…` on a single site,
`wp_2_woodev_…` on network site 2). On read, also discard a record whose provider this site does not serve.

Two traps inside the fix:

- `get_user_option()` FALLS BACK to the bare, unprefixed key when the prefixed one is absent. That is exactly
  the migration path (the legacy row is still honoured), but it also means `delete_user_option()` alone does
  not forget: the legacy row resurrects the chain. Delete the legacy row too when this site honours it.
- A chain may legitimately hold a record owned by the bundled fallback provider (D15), so "provider differs
  from the active one" is the wrong test; compare against {active provider, fallback provider}.

❌ Wrong:

```php
update_user_meta( $user_id, 'woodev_customer_location', $entry ); // shared by every site of the network
```

✅ Correct:

```php
update_user_option( $user_id, 'woodev_customer_location', $entry ); // blog-prefixed
```

## Related

- [a-level-served-can-come-from-the-fallback-not-the-active-provider](a-level-served-can-come-from-the-fallback-not-the-active-provider.md) — why the "served" set includes the fallback
- [a-logged-in-customer-s-wc-session-outranks-the-profile](a-logged-in-customer-s-wc-session-outranks-the-profile.md) — the session mirror of the same record
