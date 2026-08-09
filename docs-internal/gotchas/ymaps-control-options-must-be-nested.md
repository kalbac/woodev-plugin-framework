# ymaps control options must be nested under `options` — a flat object is silently ignored

**Discovered:** s50 (2026-08-03), diagnosing the pickup map's search box.

## What happened

`Yandex_Map_Provider`'s search control was built like this:

```js
// ❌ WRONG — every one of these four keys is discarded
this.searchControl = new this.ymaps.control.SearchControl( {
    provider: { geocode: fn },
    layout: inertLayout,
    resultsLayout: inertLayout,
    noPlacemark: true,
} );
```

Nothing threw. Nothing warned. The control simply rendered ymaps' **default** chrome — an English
"Address or place" box with a yellow Search button — and, far worse, ran ymaps' **default geocoder**,
which searches the whole planet. A customer whose cart shipped to Moscow could search «Цветной
бульвар» and be offered results in Tolyatti, Orenburg and Chelyabinsk.

The symptom reads as "the search feature was never implemented". It was; its configuration went into
a bag ymaps never opens.

## Why

Every `ymaps.control.*` constructor takes exactly three top-level keys:

```
{ data, options, state }
```

- `data` — values the control's layout renders,
- `options` — everything that configures behaviour and appearance,
- `state` — mutable values, watchable with `ymaps.Monitor`.

Anything else at the root is not validated and not reported. It is dropped.

```js
// ✅ RIGHT
this.searchControl = new this.ymaps.control.SearchControl( {
    options: {
        provider: { geocode: fn },
        layout: ourLayout,
        noPlacemark: true,
        float: 'none',
        position: { left: '16px', right: 'auto', top: '16px' },
    },
    state: { filters: { PVZ: true, POSTAMAT: true } },
} );
```

Both live references get this right and are the fastest way to check the shape:
`plugins-reference/woocommerce-yandex-delivery/assets/js/frontend/wc-yandex-delivery-widget-map.js`
(`new ymaps.control.SearchControl({ options: {...} })`, and the same for `ListBox`), and the Russian
Post widget bundle.

## How to catch it

A unit test asserting the constructor argument's **shape** catches it without a browser:

```js
const args = ymaps.control.SearchControl.mock.calls[ 0 ][ 0 ];

expect( args.options.provider ).toBeDefined();
expect( args.provider ).toBeUndefined();   // the assertion that actually matters
```

Asserting only "we passed a provider" passes on the broken version. Assert that the *root* is clean.

## The wider lesson

This is the second defect on this branch whose cause was "we handed a library a shape it does not
read" — the first was [[ymaps-objectmanager-properties-are-plain]]. Neither throws. When a ymaps
feature behaves like it was never configured, suspect the shape before suspecting the feature.

## Related

- [[ymaps-objectmanager-properties-are-plain]] — the same class of silent shape mismatch
- [[ymaps-html-icon-layout-needs-iconshape]] — found in the same session
- [[ymaps-camera-moves-are-async]]
- `docs-internal/archive/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` — V-6, and why the control
  is kept rather than replaced
