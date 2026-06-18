# Testing an admin capability gate (403) on the rig: WooCommerce blocks subscribers from wp-admin — use an EDITOR

**Tag:** `[testing/wc-admin-access-403]` · added s19 (2026-06-18)

## What

To browser-verify that a capability-gated `admin_init` endpoint returns **403** for
a non-privileged user (e.g. the updater's `show_changelog()`, gated on
`current_user_can( 'update_plugins' )`), the obvious move is to log in as a
**subscriber**. It does NOT work: WooCommerce's "prevent admin access" redirects
customers/subscribers to **My Account** *before* `admin_init` fires — so the gated
code never runs and you get a redirect, not a 403.

## Fix

Use a user whose role **can** reach wp-admin but **lacks** the target capability —
an **editor** is ideal: editors have `edit_posts` (so WC allows admin access) but
not `update_plugins`. Then the gated endpoint runs and correctly `wp_die()`s 403.

```bash
docker exec <cli> wp user set-role <user> editor
# verify: user_can($u,'update_plugins') === false, but admin is reachable
```

Confirm the real status with a same-origin `fetch()` in the browser console
(`r.status === 403`), not just the rendered "Error" wp_die page.

## Why it matters

A subscriber test gives a misleading PASS-shaped result (a redirect to a normal
page), masking whether the 403 gate actually works. The WC redirect is invisible
unless you notice the URL changed to `/?page_id=…` (My Account).

## Related

- [[wp-safe-remote-request-local-rig]] — other two-stack rig traps.
- `woodev/licensing/updater/class-plugin-updater.php` — `show_changelog()` (OB-3 F9).
