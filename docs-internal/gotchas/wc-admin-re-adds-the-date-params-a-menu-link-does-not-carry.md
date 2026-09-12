# [woocommerce/navigation] `wc-admin` re-adds the date params a menu link does not carry — and a full page load hides it

> Namespace: `woocommerce/*` — added session 129 (2026-09-09)

## The trap

A merchant picks a date preset, clicks the page's own item in the admin sidebar, and the preset is
still there. The sidebar link's `href` carries no query at all:

```text
admin.php?page=wc-admin&path=%2Fwoodev-shipping-orders
```

so the obvious conclusion — "the menu leads to a clean address, therefore the page must reset" — is
wrong, and **a probe that navigates with a full page load will confirm the wrong conclusion.**
Measured both ways on the same rig, same commit:

| how the page is reached | address afterwards |
|---|---|
| `page.goto()` — a real reload | clean, control shows the page default |
| clicking the sidebar item | **`…&period=month` is back** |

The second is what a person does. `window.__marker` planted before the click survives it, so there
was no reload: `wc-admin` intercepted the navigation and re-attached the parameters.

## Root cause

`@woocommerce/navigation` keeps a set of query args that are deliberately carried across in-app
navigation, and the date params are in it:

```js
window.wc.navigation.getPersistedQuery()   // => {"period":"month"}
```

It is a feature of Analytics, where moving between reports at a fixed period is the actual job. Our
page lives inside their React app (`wc_admin_register_page()`, spec §D1/§D7), so it inherits the
behaviour whole — including on links WE render.

WooCommerce's own screens behave identically, which is the control that proves it is not ours:
clicking Analytics → Revenue → Orders carries `period=month` through both hops, no reload.

## ❌ Wrong

```js
// "The menu href is clean, so this measures the menu path."
await page.goto( '/wp-admin/admin.php?page=wc-admin&path=%2Fwoodev-shipping-orders' );
// A reload drops the persisted query. You have measured a path no merchant takes.
```

## ✅ Correct

```js
await page.evaluate( () => { window.__probe = 'alive'; } );
await page.locator( '#adminmenu a[href*="woodev-shipping-orders"]' ).first().click( { force: true } );
// window.__probe still 'alive'  => no reload, this is the SPA route
// page.url() still carries &period=…  => the router re-added it
```

**Measuring anything URL-driven on this page means clicking, not `goto`.** The same applies to any
claim that a filter "resets" — the filters are URL-driven by design (§D11), so whatever the router
puts back is what the merchant sees.

If a page ever does need to ignore the persisted query, that is OUR code overriding THEIR
behaviour — a deliberate divergence from every neighbouring `wc-admin` screen, and a decision for
the operator (#838), not a bug fix.

## s132: what `getPersistedQuery()` does NOT do

Read straight out of the shipped bundle rather than inferred, because the s130 wording above
(«re-adds the date params») invites the stronger reading that it can INVENT one:

```js
getPersistedQuery = ( query = getQuery() ) => {
    const params = applyFilters( 'woocommerce_admin_persisted_queries',
        [ 'period', 'compare', 'before', 'after', 'interval', 'type' ] );
    return pick( query, params );
};
```

It is a `pick`. It carries forward only the keys the CURRENT query already has, and it manufactures
nothing — so **an absent `period` stays absent across navigation**, and a page whose default state
is «no date filter» does not need sentinel dates to defend itself. That is what let #855 express
«всё время» as the absence of the parameter rather than as a value (a value would have thrown; see
its own gotcha).

What it does do is carry an EXISTING period onto a link whose `href` has none — which is the s130
finding above, and still true.

## Related

- [addhistorylistener-fires-before-the-url-changes](addhistorylistener-fires-before-the-url-changes.md) — the other `wc-admin` navigation trap on this page; both are about trusting the URL at the wrong moment
- [a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there](a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md) — the same shape of mistake: the probe, not the page, produced the answer
- [a-hand-written-d-ts-for-a-runtime-global-is-an-assertion-not-a-check](a-hand-written-d-ts-for-a-runtime-global-is-an-assertion-not-a-check.md) — `window.wc.*` is someone else's runtime; measure it, do not assume it
