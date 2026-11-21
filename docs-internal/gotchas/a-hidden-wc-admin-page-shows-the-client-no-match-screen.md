# A wc-admin page hidden from the PHP registrations shows WooCommerce's client «not allowed» screen and the bundle never loads

**Namespace:** `[php/wp-admin]` · **Discovered:** s240 (21.11.2026, #1897 / PR #1913)

## What happens

Opening an old bookmark of a wc-admin page that the framework no longer lists (Diagnostics with logging off) showed WooCommerce's own English «Sorry, you are not allowed…» screen with HTTP 200. A fix hooked on `admin_page_access_denied` never ran.

## Root cause

wc-admin routes are matched on the client: an unknown path renders the client no-match screen, the server answers 200, and `admin_page_access_denied` never fires. On a cold load `wc_admin_is_registered_page()` is false for an unregistered path, so a collector that enqueues only on registered pages skips the bundle too — the page cannot even render our own explanation.

## Fix

- ❌ Hook `admin_page_access_denied`, or rely on the page being absent.
- ✅ Register the path server-side as a hidden page (`parent` `''`, via `Admin_Collector::build_registrations`) so the bundle is enqueued, and render the explanation in the React page with a link to the settings.

## Related

- [admin-init-never-fires-for-an-unregistered-admin-page-slug](admin-init-never-fires-for-an-unregistered-admin-page-slug.md) — the classic-admin twin of the same trap
- [s240 session](../sessions/s240.md)
