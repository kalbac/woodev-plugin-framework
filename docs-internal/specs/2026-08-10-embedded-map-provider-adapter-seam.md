# Spec — #251: make the embedded (carrier-widget) seam reachable, and prove it with a real carrier

> Written s63 (10.08.2026). Every claim in §1 is a MEASUREMENT taken on the rig
> (`http://localhost:8973`) against the live Почта России widget, not a reading of vendor
> docs. The operator warned that `widget.pochta.ru/docs` / `cms.pochta.ru/docs` are stale;
> they were deliberately not consulted before the measurements.

## 1. Measurements — the evidence this spec rests on

Probe pages were served from the rig's own docroot so the parent origin was a real
`http://localhost:8973`, and the iframes were built with the framework's VERBATIM posture
(`sandbox="allow-scripts allow-same-origin allow-forms allow-popups"`,
`referrerpolicy="no-referrer"`), copied from `buildIframe()` in
`map-provider-embedded.js`.

| # | Question | Measured answer |
|---|---|---|
| M1 | Is `https://widget.pochta.ru/map/` framable from an arbitrary origin? | **Yes.** `curl -I` shows no `X-Frame-Options` and no CSP `frame-ancestors`. |
| M2 | Does the framework's `sandbox` posture break the widget? | **No.** Sandboxed and unsandboxed frames behaved identically: both loaded, both rendered the full live point set for Moscow, both were fully interactive. |
| M3 | What is the carrier's handshake? | frame → parent `{ isMapLoad: true }`; parent → frame `{ postData: { siteId, accountId, accountType, weight, sumoc, startZip, startAddress, url, dimensions, orderLines } }`; frame → parent `{ pvzData: {...} }`. The carrier's own loader (`widget.pochta.ru/map/widget/widget.js`, 1.2 KB) does nothing else. |
| M4 | Does the widget render without `postData`? | **No.** It stays blank until the parent answers `isMapLoad`. So an `embedUrl` pointed straight at the widget with no outbound message yields a permanently empty modal. |
| M5 | Does it accept `postData` from a foreign parent origin? | **Yes.** It never checks `event.origin`; the carrier posts and listens with `"*"` in both directions. |
| M6 | Do the framework's two trust checks hold against it? | **Yes, both.** `event.origin === 'https://widget.pochta.ru'` exactly (no trailing slash, no port), and `event.source === iframe.contentWindow` for every message including the selection. |
| M7 | What does a selection actually contain? | Postamat: `{"id":43213,"mailType":"ONLINE_PARCEL","pvzType":"postamat","indexTo":"918872","cashOfDelivery":22094,"deliveryDescription":{"description":"1 день","values":{...}},"regionTo":"г. Москва","areaTo":"р-н Коммунарка","cityTo":"п. Воскресенское","addressTo":"27","weight":"1000","location":null,"boxSize":"l","sumoc":"1000"}`. Post office: same shape, `pvzType:"russian_post"`, `areaTo:null`, no `boxSize`. |
| M8 | **Does a selection carry coordinates or a name?** | **No.** Neither `lat`/`lng` nor `name` appears in either type's payload. |
| M9 | Does the carrier guard its own double-confirm? | Partially — after a selection it sets its own «Забрать здесь» button `disabled` for that point. |

Two further facts were read out of the framework's own source (not measured, but verified
by reading, and they are what makes M8 actionable):

- `pickup-mount.js` contains **zero** reads of `.lat`/`.lng` (`grep -c` → 0). The two in
  `pickup-panels.js` are unreachable under `ownsChrome`, where `panels === null`.
- The confirmation round trip is `realDataSource.selectPoint( { pointId, fieldId } )` — the
  **id only**. The server may answer with `result.point`, a CORRECTED point that supersedes
  the client's entirely (`finishSelection()`).

## 2. Decision

**Adapter over iframe.** The provider keeps building the `<iframe>` and keeps owning the
`origin` + `event.source` gate; the carrier's own protocol is translated by two optional,
plugin-supplied hooks.

**Rejected: script mode** (provider calls a plugin global instead of building an iframe, and
the carrier's own loader mounts the widget). Its only advantage was surviving a sandbox
incompatibility — M2 disproves that this exists. Its cost is decisive: the trust gate would
move out of the framework into the carrier's shim, which posts to `"*"` and validates
nothing (M5). We would be strictly less safe than the framework already is, to buy nothing.

**Rejected: a plugin-hosted https bridge page.** M1–M6 make it unnecessary — no hosting, no
public page, no extra origin to trust.

## 3. Framework change A — two optional adapter hooks

### 3.1 PHP — `Embedded_Map_Provider`

Two new optional constructor parameters, both defaulting to `null`, both carried verbatim
into `get_js_config()`:

```php
public function __construct(
    string $embed_url,
    string $expected_origin,
    ?string $init_adapter = null,
    ?string $select_adapter = null
)
```

`get_js_config()` gains `initAdapter` and `selectAdapter`. Each is a **dotted global path**
(e.g. `'WoodevPochtaEmbed.toPoint'`), not a callable — the value crosses into the browser as
JSON.

### 3.2 JS — `map-provider-embedded.js`

Resolve a dotted path by walking `window` on `.` (never `eval`, never `new Function`); a path
that does not resolve to a function is treated as absent.

`handleMessage()` order, AFTER the existing origin + source gate (unchanged, and still the
only trust boundary):

1. The framework's own envelope (`{source:'woodev-pickup-embedded', type:'select', point}`) —
   handled exactly as today. Adapters never see it.
2. `initAdapter( data )` → returns a payload or `null`. Non-null is posted into the frame
   with `iframe.contentWindow.postMessage( payload, config.expectedOrigin )` — **the expected
   origin as `targetOrigin`, never `"*"`**. Return.
3. `selectAdapter( data )` → returns a raw point payload or `null`. Non-null goes through
   `normalizePoint()` and then the existing emit path (`select` on success, `error` on
   rejection). Return.

Rules:

- An adapter that **throws** must not break the picker. `initAdapter` throwing is swallowed
  (the message bus is shared). `selectAdapter` throwing emits `error` — the message already
  proved it came from the trusted frame, so a failure there is a real, reportable fault.
- An empty `expectedOrigin` already rejects every inbound message; it must ALSO suppress the
  outbound post in step 2, since there would be no safe `targetOrigin`.
- Adapters run strictly after the gate. **They never widen the trust boundary** — they only
  translate messages already proven to come from this instance's own iframe at the expected
  origin. Say this in the docblock; it is the question a reviewer will ask.

## 4. Framework change B — `lat`/`lng` become optional on this path

Justified by M8 + the two source facts in §1: a real carrier embed does not send
coordinates, and on the `ownsChrome` path nothing in the framework consumes them.

In `normalizePoint()`:

- `lat`/`lng` move from **required** to **optional-but-validated**.
- Present → must be numeric and in range (`lat` ∈ [-90,90], `lng` ∈ [-180,180]). Junk is
  still REJECTED, never coerced — that rule does not change.
- Absent → omitted from the emitted point. No `0.0` fallback, ever.
- **Exactly one of the pair present → reject.** A half-coordinate is a bug in the adapter,
  not a partial datum.
- **An explicit `null` counts as ABSENT, not as a present-but-invalid value.** The spec was
  underspecified here and an adversarial review read it the other way, so the decision is
  recorded with its evidence: (a) `Pickup_Point::from_array()` tests presence with `isset()`,
  which is already `false` for `null`, and this file's stated alignment is with that method's
  optional-field handling; (b) the live Почта payload literally sends `"areaTo": null` and
  `"location": null` (§1 M7) — `null` is the carrier's own way of writing "no value", not
  junk; (c) the half-coordinate rule still bites, because `{ lat: null, lng: 55.7 }` reads as
  lat-absent + lng-present and is rejected.
- `name` stays REQUIRED. Почта sends none, but a name is domain knowledge the plugin can
  build (`Почтомат №918872` from `pvzType` + `indexTo`), and it is what the checkout field
  and trigger label display. The framework must not invent it.

#201 (merged as `ccf35c1`) already removed both "field-for-field mirror" claims and replaced
them with an explicit **required-field divergence** paragraph that names this issue as its
owner and says "until #251 lands, requiring `lat`/`lng`/`name` here means this function
rejects that real payload". Both paragraphs (file docblock ~line 78, `normalizePoint()`
~line 362) must now be brought up to date — they describe a state this change ends. Do not
leave a docblock pointing at #251 as future work once #251 has landed.

`Pickup_Point::from_array()` itself is **NOT** changed — the REST path feeds our own map,
which genuinely needs coordinates.

## 5. Fixture — `WOODEV_TEST_PICKUP_EMBEDDED`

In `tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php`, in the
same idiom as `WOODEV_TEST_PICKUP_LIVE_YANDEX` / `LIVE_POCHTA` (declared ~lines 25–62,
resolved ~441–447, provider constructed ~481–487):

- `WOODEV_TEST_PICKUP_EMBEDDED` (bool, default `false`). When truthy it wins over every other
  switch and constructs `Embedded_Map_Provider` instead of `Yandex_Map_Provider`. Document the
  precedence in the same style as the existing block — the existing constants document theirs.
- The point source becomes irrelevant under this switch (the embed fetches its own points);
  say so explicitly rather than leaving a dead-looking branch.
- `WOODEV_TEST_POCHTA_ACCOUNT_ID` / `WOODEV_TEST_POCHTA_ACCOUNT_TYPE` — credentials. **Never
  committed**; they live in the container's wp-config via `wp config set`, exactly like the
  Yandex token. The fixture reads them with a `defined()` guard and degrades to an empty
  string.
- `WOODEV_TEST_PICKUP_SELECTION_CLOSE` / `WOODEV_TEST_PICKUP_SELECTION_REFRESH_CHECKOUT` —
  the card asks for this: the stock config is `selection: { close: true, refreshCheckout:
  false }`, which makes the "modal stays open after a selection" path unreachable from a
  fixture (in s62 it had to be patched live in the browser).

A fixture-owned adapter script implements the Почта translation — this is DOMAIN knowledge and
must not leak into the framework:

```js
window.WoodevPochtaEmbed = {
    onReady: function ( data ) {
        if ( ! data || ! data.isMapLoad ) { return null; }
        return { postData: { accountId: …, accountType: …, weight: …, sumoc: …,
                             startZip: …, startAddress: …, url: window.location.href } };
    },
    toPoint: function ( data ) {
        if ( ! data || ! data.pvzData ) { return null; }
        var p = data.pvzData;
        return {
            id:      String( p.id ),
            name:    ( 'postamat' === p.pvzType ? 'Почтомат №' : 'Отделение №' ) + p.indexTo,
            address: [ p.regionTo, p.areaTo, p.cityTo, p.location, p.addressTo ]
                        .filter( Boolean ).join( ', ' ),
            type:    { code: p.pvzType, label: … },
            postal_code: String( p.indexTo )
            // no lat/lng — the carrier sends none (M8)
        };
    }
};
```

The address composition mirrors the operator's own production plugin
(`plugins-reference/woodev-russian-post/includes/classes/ajax.php`, `set_point()`), which
builds it from exactly `regionTo, areaTo, cityTo, location, addressTo` through
`array_filter()`. Follow that, do not invent a different order.

## 6. Rig verification — the five checks the card asks for

Run each on the rig with `WOODEV_TEST_PICKUP_EMBEDDED` on, and record the OBSERVED result,
not "should work":

1. **No-panels layout** — `panels === null`, `mapHost === modal.getContainer()`; the modal's
   size/loading/error branches take the no-panels path.
2. **`refresh()` under `ownsChrome` is a hard no-op** — i.e. the #232/#238 cart-change verdict
   invalidation does not reach embedded pickers at all. Confirm this is deliberate and record
   it; do not "fix" it here.
3. **The #238 echo gap stays harmless** — `refreshCheckout()` gets `panels === null`, no waiter
   is bound, the echo is not suppressed. Safe precisely because `refresh()` is a no-op. The
   comment saying so is already in `pickup-mount.js`; verify it still holds.
4. **Double confirmation** — nothing in the framework damps it under `ownsChrome` (no card, no
   lock). M9 says the carrier disables its own button; check with a live double click whether
   that is enough, and record what actually happens.
5. **Failure paths** — an invalid `embedUrl` must produce an error state with a retry;
   `IFRAME_LOAD_TIMEOUT_MS` (10 s) must fire for a host that accepts and never answers.

## 6a. Rig verification — OBSERVED results (10.08.2026)

Run on `feat/251-embedded-adapter-seam` with `WOODEV_TEST_PICKUP_EMBEDDED` on, against the
live carrier. Every row is an observed artefact, not an absence of complaints.

| # | Observed |
|---|---|
| 1 | `panelsPresent: 0`; the `<iframe>` is the ONLY child of `.woodev-modal__body`, with `sandbox="allow-scripts allow-same-origin allow-forms allow-popups"` and `referrerpolicy="no-referrer"`. The live widget rendered inside our modal. |
| 2 | On `update_checkout`: WooCommerce answered (`/?wc-ajax=update_order_review` fired), `woodev/v1` calls = **0**, and the iframe's `contentWindow` was the SAME object afterwards — `refresh()` is a hard no-op and the frame is not rebuilt. Confirmed deliberate; unchanged. |
| 3 | Same observation — `refreshCheckout()` gets `panels === null`, no waiter is bound, nothing is suppressed. Harmless only because 2 holds. |
| 4 | Two selections back to back produced **2** `select_requested` and **1** `select_resolved` (the LATER one). The framework does not damp a second confirmation under `ownsChrome` — the documented, deliberate consequence — but the token guard discards the stale answer. Separately: the carrier disables its own «Забрать здесь» after a selection, so its own UI damps the same-point case. |
| 5 | Non-https `embedUrl` → no iframe, error + «Повторить» immediately. Unresponsive https host → error + «Повторить» at **10 159 ms** (the 10 s `IFRAME_LOAD_TIMEOUT_MS`), iframe removed from the DOM. |

Full success path, after the post-review fixes: point `35482`, address
`г. Москва, ул. Тверская 9 стр. 5` (no duplicated locality), **no `lat`/`lng` on the emitted
point**, server verdict `allowed: true`, field written to `35482`, modal closed, trigger
relabelled to «Выбрать другой пункт выдачи».

The 404 that this path returned BEFORE the fixture stub is worth keeping in mind: the
confirmation round trip is server-authoritative and always calls
`Point_Source::fetch_details()` regardless of which map provider drew the picker. A real
embedded plugin therefore MUST implement a genuine carrier details lookup — the embed being
self-sufficient for DISPLAY does not make it self-sufficient for CONFIRMATION.

## 7. Known gaps, deliberately not closed here

- A carrier that loads successfully but serves an error page is still invisible to us
  (`onload` fires regardless). Pre-existing, documented in the file, unchanged.
- `cashOfDelivery` / `deliveryDescription` in `pvzData` mean the widget prices the shipment
  itself. Out of scope; noted because it will matter when a real plugin is built on this.

## 8. Related

- Issue #251 (this), #201 (normalizer parity, landed first), #158 (fixture is too poor to
  exercise the type filter), #148 (verdict staleness — does not apply under `ownsChrome`).
