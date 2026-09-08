# `addHistoryListener` fires BEFORE the URL changes, so `getQuery()` inside it reads the PREVIOUS one

> Namespace: `woocommerce/*` — added session 128 (2026-09-09). Found on the rig, after the
> defect had survived 1831 green jest tests, a Fable critic and a full CI matrix.

## The trap

A `wc-admin` page whose filters are URL-driven reads the query back in the history listener:

```jsx
navigation.addHistoryListener( () => {
    const query = navigation.getQuery();   // ← the OLD query
    setFilters( readFilters( query ) );
} );
```

The address bar updates, and the table does not. On the rig: pressing «Filter» wrote
`delivery_status_is=pending` into the URL while the table went on showing every order,
`Готово к выдаче` rows included. The page is not ignoring the filter — it is applying the
**previous** one, permanently one navigation behind.

## Root cause

`addHistoryListener` monkey-patches `window.history.pushState` and dispatches its event
**before** delegating to the real one (`packages/js/navigation/src/index.js:134`):

```js
history.pushState = function ( state ) {
    const pushStateEvent = new CustomEvent( 'pushstate', { state } );
    window.dispatchEvent( pushStateEvent );        // listeners run HERE
    return pushState.apply( history, arguments );  // the URL changes AFTER
};
```

So any listener that touches `window.location` — directly or through `getQuery()` — runs
while the old URL is still current.

## ✅ Correct — the shape WooCommerce itself uses

Its own `useQuery()` hook (same file, `:216`) is not decoration; it is the workaround:

```jsx
const [ locationChanged, setLocationChanged ] = useState( false );

useEffect( () => addHistoryListener( () => setLocationChanged( true ) ), [] );

useEffect( () => {
    if ( ! locationChanged ) {
        return;
    }
    const query = getQuery();   // runs in a LATER effect — the URL has settled
    …
    setLocationChanged( false );
}, [ locationChanged ] );
```

The listener only raises a flag. React runs the effect after the current call stack unwinds,
by which time the real `pushState` has executed.

## How to tell this apart from "the filter is not read at all"

Two candidates produce the identical symptom, and they need separating by measurement rather
than by reasoning:

| test | if the READER is broken | if the LISTENER is the problem |
|---|---|---|
| load `?…&delivery_status_is=in_transit` **fresh** | still unfiltered | filters correctly |

On the rig the fresh load fetched `delivery_status=pending` and reported «0 Заказов», which
ruled the reader out in one step.

## Why the tests could not catch it

The page's test double for navigation did this:

```js
fakeQuery = query;                                  // ❌ URL first
historyListeners.forEach( ( l ) => l() );           //    listeners second
```

— the **opposite** of the browser, which makes the defect impossible to express. Correcting
the fake to fire listeners first, still seeing the old query, turned three existing tests red
against the old listener without any new assertions.

**The transferable half:** a test double for someone else's runtime encodes a CLAIM about it.
Get the ordering wrong and the suite certifies a page that cannot work. The same session had
the same failure twice — see the Related gotcha, where the option shape was asserted in the
broken form.

## Related

- [a-filter-option-keyed-key-instead-of-value-disables-the-filter-button](a-filter-option-keyed-key-instead-of-value-disables-the-filter-button.md) — the other s128 defect whose tests asserted the fiction
- [a-hand-written-d-ts-for-a-runtime-global-is-an-assertion-not-a-check](a-hand-written-d-ts-for-a-runtime-global-is-an-assertion-not-a-check.md)
- [a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there](a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md) — why the probe polls the summary instead of counting rows straight after a navigation
