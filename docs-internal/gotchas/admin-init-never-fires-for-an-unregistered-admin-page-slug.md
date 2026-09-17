# `admin_init` never fires for an unregistered admin page slug

**Namespace:** `[php/wp-admin]`
**Found:** s137 (17.09.2026), SP-10 increment 5 (#820).

## The trap

The framework already had a working legacy-URL redirect —
`Settings_Page_Registry::maybe_redirect_legacy()`, hooked on `admin_init`. The obvious move for the
orders page's legacy slug (`admin.php?page=wc_edostavka_orders`) was to copy it. **It would never
have run.**

WordPress core, `wp-admin/admin.php` (WP 7.1 on the rig):

1. `:141-144` — `$plugin_page = plugin_basename( wp_unslash( $_GET['page'] ) )`;
2. `:163` — `require wp-admin/menu.php` → `wp-admin/includes/menu.php`;
3. `includes/menu.php:375-382` — `if ( ! user_can_access_admin_page() ) { do_action( 'admin_page_access_denied' ); wp_die( …, 403 ); }`;
   `user_can_access_admin_page()` returns `false` as soon as `$_registered_pages[ $hookname ]` is
   unset — i.e. for ANY slug nobody registered;
4. `:180` — `do_action( 'admin_init' )` — never reached for that slug.

The settings redirect works only because ITS legacy page (`wc-settings`) still exists. A redirect
for an orphaned v1 page slug, copied from it, is dead code that passes every unit test (they call
the method directly) and returns a plain 403 on the rig.

## ✅ What to do

- Hook a redirect for a page nobody registers onto **`admin_page_access_denied`** — it fires
  exactly for a page the request cannot reach, before the 403.
- Compare against **`global $plugin_page`**, not raw `$_GET['page']`: that is the
  `plugin_basename()`-normalised value core denied on, so `?page=%2Fslug%2F` matches too.
- A page that IS still registered (an active v1 plugin) never triggers the hook — the redirect
  does not hijack a live page.
- Prove it on the rig with an admin session (`curl` with a cookie jar is enough): the legacy URL
  must answer **302**, not 403. A unit test cannot show this.

## Related

- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — same shape: green in isolation, wrong where our code meets core
- `woodev/shipping-method/admin/orders/class-orders-registry.php` → `maybe_redirect_legacy_page()`
- [../specs/2026-09-07-sp10-orders-page-design.md](../specs/2026-09-07-sp10-orders-page-design.md) — §D1, Increments item 5
