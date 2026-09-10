# A DOM read cannot answer a question about server-rendered markup

**Discovered:** s130 (11.09.2026), measuring #834.

## The trap

`document.title` read from a live `wc-admin` page said the badge markup had NOT leaked into the
page title. The raw HTTP response said it had:

```text
DOM after load          "Заказы доставки ‹ woodev_framework — WooCommerce"        ← clean
server-rendered <title> "Заказы доставки &lt;span class=&quot;update-plugins …"   ← leaked
```

Both readings are true. WooCommerce's admin app rewrites `document.title` after it mounts, so
by the time a probe reads the DOM the server's answer has already been overwritten. The
question asked was "does `wc_admin_register_page()` put my HTML into the title tag" — a
question about what the SERVER produced — and the DOM is not a witness to it.

Had the first reading been trusted, the conclusion written on the card would have been
"`page_title` is not needed", which is the opposite of the truth: without it the browser tab
shows escaped markup until the app boots, and anything reading the document before hydration
(a bookmark, a crawler, a screen reader) sees it permanently.

## ❌ Wrong

```js
await page.goto( url );
const title = await page.title();          // whatever the app last set
expect( title ).not.toContain( 'span' );   // proves nothing about the server
```

## ✅ Correct

Capture the document response body and read the markup the server actually sent:

```js
let raw = null;
page.on( 'response', async ( r ) => {
    if ( r.url() === url && ! raw ) {
        raw = await r.text();
    }
} );
await page.goto( url );

const title = raw.match( /<title>([\s\S]*?)<\/title>/i )[ 1 ];
```

## The general rule

Before reading a value out of a live page, ask **who wrote it last**. On a `wc-admin` screen the
React app is a writer, not just a renderer — it rewrites the title, and it rewrites the query
string too (gotcha `wc-admin-re-adds-the-date-params-a-menu-link-does-not-carry`, s129, where a
full page load proved the opposite of what a click does). Anything that app touches has to be
measured either before it mounts, or from the response body, never from the settled DOM.

The inverse also holds and is why the DOM is still the right witness elsewhere: for the
«Статус данных» geometry (s130) the question was what the BROWSER lays out, and there the
response body would have been the useless reading.

## Related

- [wc-admin-re-adds-the-date-params-a-menu-link-does-not-carry](wc-admin-re-adds-the-date-params-a-menu-link-does-not-carry.md) — the same app rewriting the other thing a probe reads
- [addhistorylistener-fires-before-the-url-changes](addhistorylistener-fires-before-the-url-changes.md) — the ordering trap underneath it
- [a-probe-that-uses-the-production-accessor-creates-the-state-it-measures](a-probe-that-uses-the-production-accessor-creates-the-state-it-measures.md) — the other way a probe answers its own question
