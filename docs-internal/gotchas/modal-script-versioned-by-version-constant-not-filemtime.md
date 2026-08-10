# `woodev-modal.js` is versioned by `self::VERSION`, so editing it never busts the browser cache

**Namespace:** `[build/assets-version]`
**Found:** s62 (2026-08-10), during the rig verification of #238.

## What happened

The #238 rig pass opened the pickup picker and asked the live session for its modal state:

```js
window.WoodevPickupMount.getSession( 'carrier_pickup_point' ).modal.isOpen()
// TypeError: s.modal.isOpen is not a function
```

`isOpen()` had been added to `woodev-modal.js` on the branch the rig was serving, and the file
on disk demonstrably contained it:

```js
{ protoHasIsOpen: "undefined",            // what the running page had
  fetched: { hasIsOpenInFile: true } }    // what a no-store fetch of the same URL returned
```

The browser was running a **cached copy from a previous session**. A reload with
`ignoreCache: true` fixed it instantly and `protoHasIsOpen` became `"function"`.

## Root cause

`Woodev_Plugin::frontend_enqueue_scripts()` registers the script with the raw constant:

```php
wp_register_script(
    'woodev-modal',
    $this->get_framework_assets_url() . '/js/frontend/woodev-modal.js',
    [],
    self::VERSION          // ← never changes between releases
);
```

Three lines below, the modal's **stylesheet** — the other half of the same shared
`woodev-modal` handle — is versioned by `filemtime()`, and the comment there states the rule
correctly:

> Versioned by the file's own `filemtime()`, not `self::VERSION`: a CSS-only tweak must bust
> the browser cache on its own

The rule was written down for the CSS and not applied to the JS sitting immediately above it.

Everything else in the framework goes through `Woodev_Plugin::get_assets_version()`, which
returns `time()` under `SCRIPT_DEBUG`/`WP_DEBUG` and the version otherwise — so every other
asset busts in development. This one file does not.

## Why it bites twice

- **On the rig / in development:** any edit to `woodev-modal.js` is invisible to a browser that
  has loaded the page before. Nothing errors, nothing warns — the page simply runs the old file.
  Verification then reports a defect that does not exist, or (worse) reports a fix as working
  when the browser is running the old code that happened to behave the same way.
- **In production:** the framework's `VERSION` only moves on a release. A plugin shipping a
  patched `woodev-modal.js` without bumping `Woodev_Plugin::VERSION` serves the stale script to
  every returning visitor.

## ❌ Wrong

```php
wp_register_script( 'woodev-modal', $url . '/js/frontend/woodev-modal.js', [], self::VERSION );
```

## ✅ Correct

```php
wp_register_script( 'woodev-modal', $url . '/js/frontend/woodev-modal.js', [], $this->get_assets_version() );
```

`get_assets_version()` already carries the debug-mode `time()` branch, so this both fixes the
development trap and keeps the release behaviour identical for an unchanged file.

## How to notice it next time

A runtime symbol missing while the file on disk has it is the tell. Confirm in one call before
suspecting the code:

```js
await ( await fetch( scriptUrl, { cache: 'no-store' } ) ).text()  // what is on disk
typeof window.WoodevModal.prototype.isOpen                        // what is running
```

If those disagree, reload with cache ignored — do not start debugging the source.

## Related

- [wp-scripts-css-enqueue-version-by-mtime.md](wp-scripts-css-enqueue-version-by-mtime.md) — the
  same class of bug for `style-index.css`, and where the `filemtime()` rule came from.
- [rig-serves-the-working-tree-branch-switch-reverts-fixes.md](rig-serves-the-working-tree-branch-switch-reverts-fixes.md)
  — the other reason the rig can be running code you did not expect.
- [chrome-devtools-png-cache-stale] (s105, woodev-theme) — the same cache trap for a re-generated
  PNG at an unchanged `file://` URL.
