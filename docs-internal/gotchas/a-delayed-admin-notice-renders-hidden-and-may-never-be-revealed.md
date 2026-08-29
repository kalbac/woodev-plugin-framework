# A delayed admin notice renders into the HTML with `display:none` — finding it in the markup does not mean anyone sees it

**Namespace:** `[admin-ui/*]`
**Found:** s105 (30.08.2026), rig-verifying PR #661 (card #410). Cause fixed by PR #663 (card #662).

## The trap

`Woodev_Admin_Notice_Handler` renders notices in **two passes**:

| pass | hook | what it emits |
|---|---|---|
| immediate | `admin_notices` (15) | the notice, visible, plus ONE placeholder `<div class="js-wc-{slug}-admin-notice-placeholder">` |
| delayed | `admin_footer` (15) | the notice with a literal `style="display:none;"`, at the very end of the document |

A delayed notice is then relocated and unhidden by one line of inline jQuery emitted by
`render_admin_notice_js()` on `admin_footer` (20):

```js
$( '.js-woodev-plugin-framework-admin-notice:hidden' )
  .insertAfter( '.js-wc-{slug}-admin-notice-placeholder' ).show();
```

So a `curl … | grep "<the notice text>"` returning **1 is not evidence the merchant sees anything**.
The notice is in the markup either way; whether it is *visible* depends on that jQuery finding its
placeholder. If the selector matches nothing, `insertAfter()` returns an empty collection, the
chained `.show()` applies to nothing, and the notice stays `display:none` forever — with no error,
no console warning, and a perfectly green PHP unit suite.

## Why it silently matched nothing (the s105 instance)

The placeholder slug and the JS slug came from **two different plugins**, because two independent
statics guard them:

- `$admin_notice_placeholder_rendered` — the placeholder is echoed by the FIRST handler to run on
  `admin_notices`, whether or not that plugin has notices;
- `$admin_notice_js_rendered` — the JS is emitted by the first handler whose `$admin_notices` is
  NON-empty (`render_admin_notice_js()` returns early on `empty( … )`).

On a site with ONE woodev plugin both resolve to the same plugin and everything works — which is why
this survived so long. In a fleet they routinely differ. Measured on the rig with three fixture
plugins: the JS pointed at `woodev-test-shipping-method`, the DOM only ever contained the
`woodev-test-plugin` placeholder, and BOTH notices — including the long-shipped
`debug-in-production` one — had `offsetHeight: 0`.

Fixed in PR #663 by recording the slug that actually echoed the placeholder and using that.

## ❌ Wrong — what "verified" looked like

```bash
curl -s -b cookies "http://localhost:8973/wp-admin/admin.php?page=woodev-settings" \
  | grep -c "Зафиксированная локация"      # -> 1, and it means nothing about visibility
```

## ✅ Correct — assert the rendered box, not the presence of the string

```js
// in the browser, via chrome-devtools MCP
const el = document.querySelector('[data-message-id="location-default-locality-stale"]');
getComputedStyle(el).display;   // must not be "none"
el.offsetHeight;                // must be > 0
```

And prove the cause rather than inferring it — re-run the framework's own line against the
placeholder that *does* exist and watch the heights go from `0` to `60`/`41`.

## How to notice

- **Any notice added through `add_delayed_admin_notices()` needs a browser check, not a markup
  check.** The PHP unit suite cannot see `display:none`; ours was green with 14 new tests while the
  feature was invisible.
- A non-dismissible notice has no `.notice-dismiss` button — that absence is a useful positive
  signal that `'dismissible' => false` took effect, and is visible in the same DOM probe.

## Related

- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — same family: the green suite was testing the wrong thing
- [rig-serves-the-working-tree-branch-switch-reverts-fixes](rig-serves-the-working-tree-branch-switch-reverts-fixes.md) — how to get a branch in front of the browser in the first place
