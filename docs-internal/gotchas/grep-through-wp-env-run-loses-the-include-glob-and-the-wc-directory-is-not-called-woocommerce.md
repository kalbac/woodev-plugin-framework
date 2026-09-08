# A `grep --include` through `wp-env run` finds nothing, and WooCommerce is not in `plugins/woocommerce`

> Namespace: `tooling/*` — added session 126 (2026-09-08).

## Two independent ways the same command lies, both silent

Searching WooCommerce's source inside the rig container is the natural way to check a vendor
contract. Two things make it return **zero hits on code that is definitely there**, and neither
prints an error — so the empty output reads as "this API does not exist", which is a conclusion, not
a failure.

### 1. The directory is `woocommerce.latest-stable`

`wp-env` provisions WooCommerce under its version-pinned name:

```
/var/www/html/wp-content/plugins/woocommerce.latest-stable/
```

There is no `plugins/woocommerce`. Every `grep -rn … plugins/woocommerce/…` silently searches
nothing, because `grep -r` on a non-existent path with `2>/dev/null` prints nothing at all.

### 2. `--include=*.php` is eaten before it reaches grep

The glob passes through the Bash tool, `npx`, and `docker exec` on Windows. By the time grep sees
it, the pattern no longer matches, and grep reports no hits rather than complaining.

Measured side by side, same session, same file:

| command | result |
|---|---|
| `grep -rc 'add_submenu_page' <exact file>` | `1` ✅ |
| `grep -rln 'wc_admin_register_page' <its directory> --include=*.php` | *(nothing)* ❌ |
| `grep -rn 'function wc_admin_register_page' <plugin root>` (no `--include`) | found it ✅ |

`-R` instead of `-r` changes nothing — the directories are real, not symlinks. Dropping `--include`
is what fixes it.

## What it cost

Two wrong conclusions inside one session, both stated out loud before being caught: that
`wpOpenMenu` does not exist in WooCommerce 11.1 (it does — it is how a `wc-admin` page highlights
its WP menu item), and that WooCommerce never touches `parent_file`.

## ✅ The habit that catches it

**Sanity-check the checker on a string you KNOW is there before believing an empty result.** One
extra grep for a known symbol in the same directory, with the same flags, converts a silent lie into
an obvious one:

```bash
# prove the search works at all, then trust its negative answer
grep -rn '<a symbol that certainly exists>' "$WCDIR" | head -3
grep -rn '<the thing you are actually looking for>' "$WCDIR" | head -3
```

## Related

- [the-bash-tools-quoted-heredoc-still-eats-backslashes](the-bash-tools-quoted-heredoc-still-eats-backslashes.md) — the same shell layers eating a backslash, and the same fix: stop passing it through them
- [wpenv-windows-gitbash-path-mangling](wpenv-windows-gitbash-path-mangling.md) — the `MSYS_NO_PATHCONV=1` half of running anything in these containers
- [a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all](a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all.md) — the vendor behaviour this session had to verify while the grep was lying about it
