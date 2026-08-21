# select2/selectWoo reference study — location field renderer (`ajax-select2` mode)

> Written 21.08.2026 in response to the operator's rig pass (issues #447–#450). Reference-first
> study, **no framework code changed**. Every claim below carries its source path; anything I could
> not verify in a real file is marked **UNVERIFIED**.
>
> Sources consulted, in the order the brief named them:
> (a) Context7 `/select2/select2` docs, cross-checked against the real selectWoo build we ship —
>     `D:/Projects/wordpress/woocommerce/assets/js/selectWoo/selectWoo.full.js` (not vendored in
>     this repo; select2/selectWoo has no npm package here — see `location-select-modes.js`'s own
>     "SELECT2 IS OPTIONAL AT RUNTIME" docblock section).
> (b) WooCommerce's own JS at `D:/Projects/wordpress/woocommerce`.
> (c) **CORRECTED mid-task by the coordinator**: the operator's "CDEK plugin" and "our own mature
>     plugin with a select2 adapter" are the SAME plugin — `woocommerce-edostavka` (its store
>     listing name; CDEK is the carrier it integrates). The authoritative copy is the one vendored
>     in **this worktree**, `plugins-reference/woocommerce-edostavka/`, not the separate working
>     checkout at `D:/Projects/wordpress/woocommerce-edostavka` (consulted only to confirm the
>     `plugins-reference` copy isn't stale relative to it — see §4).
>
> Our code under study: `woodev/shipping-method/assets/js/frontend/location-select-modes.js`
> (`buildSelectField`/`ensureSelect2`/`attachAjaxSelect2`) and `resolveModeRenderer()` in
> `location-cascade.js`.

---

## 1. Which selectWoo we actually get at runtime

**selectWoo 1.0.10**, WooCommerce's own maintained fork of select2, built on the **select2 4.x**
architecture (module system, `AjaxAdapter`, decorator-based data adapters — not the select2 3.x
`query`/`initSelection` API).

Evidence:

```
/*!
 * SelectWoo 1.0.10
 * https://github.com/woocommerce/selectWoo
 *
 * Released under the MIT license
 * https://github.com/woocommerce/selectWoo/blob/master/LICENSE.md
 */
```
— `D:/Projects/wordpress/woocommerce/assets/js/selectWoo/selectWoo.full.js:1-7`

Registered as the `selectWoo` script handle and enqueued as a hard dependency wherever WooCommerce
enhances a select, e.g.:

```php
'selectWoo' => array(
    'src' => self::get_asset_url( 'assets/js/selectWoo/selectWoo.full' . $suffix . '.js' ),
```
— `D:/Projects/wordpress/woocommerce/includes/class-wc-frontend-scripts.php:201-202`

4.x-architecture confirmation — the AJAX data adapter is `select2/data/ajax`'s `AjaxAdapter`, with
`processResults`, `minimumInputLength` as its own composable data-adapter decorator, and
`this._request.abort()` — none of which exist in the select2 3.x line (which used
`query`/`initSelection` instead):

```
S2.define('select2/data/ajax',[
  './array', '../utils', 'jquery'
], function (ArrayAdapter, Utils, $) {
```
— `selectWoo.full.js:3519`

```
S2.define('select2/data/minimumInputLength',[
```
— `selectWoo.full.js:3876`

The one `version: '3.1.12'` string in the file (`selectWoo.full.js:6347`) is **not** selectWoo's
own version — it belongs to the bundled `jquery-mousewheel` plugin appended to the same file. Do
not cite it as the selectWoo/select2 version; the header comment at line 2 is authoritative.

`woocommerce-edostavka` (both the `plugins-reference` copy and the working checkout) ships its own
fallback copies of selectWoo — `1.0.6` in `includes/class-wc-edostavka-checkout.php:84` and
`1.0.9-wc.{WC version}` in `includes/class-wc-edostavka-related-checkout-fields.php:42` — both
gated on `! wp_script_is( 'selectWoo', 'registered' )`, i.e. they only load if WooCommerce's own
`selectWoo` handle isn't already registered. On any real WooCommerce-active store, WooCommerce's
own 1.0.10 wins. The version drift between the plugin's two fallback registrations (1.0.6 vs
1.0.9-wc.X) is itself worth noting as a small inconsistency in that plugin, not something to
reproduce.

**Consulted, not guessed**: Context7 `/select2/select2` (the upstream project selectWoo forks) for
the documented `ajax`/`minimumInputLength` contract, cross-checked line-by-line against the actual
`selectWoo.full.js` shipped here — both agree; no divergence found between the two on the API
surface this task touches.

---

## 2. The canonical shape for a remote-backed select

### 2.1 Seeding an existing value

select2's own docs are explicit that `.val()` **does not work** for an ajax-backed select before
first search — the fix is to append a real, pre-selected `<option>` before initializing:

```javascript
// create the option and append to Select2
var option = new Option(data.full_name, data.id, true, true);
studentSelect.append(option).trigger('change');
```
— Context7 `/select2/select2`, "Preselect Option in AJAX Select2"
(`docs/programmatic-control/add-select-clear-items.md`)

`woocommerce-edostavka`'s adapter does the simpler variant of the same pattern — a locality value
IS its own display text, so no separate lookup is needed, and it seeds the option **before**
`.selectWoo()` runs rather than trigger `change` after:

```javascript
value = $element.val() || settings.defaultValue,
...
if( value ) {
    $element.append(
        $( '<option />' )
            .prop( 'value', value )
            .text( value )
    );
}
$element.selectWoo( { ... } );
```
— `plugins-reference/woocommerce-edostavka/assets/js/frontend/city-select.js:69,90-98`

**Our code seeds nothing.** `buildSelectField()` moves `id`/`name`/`className` onto the new
`<select>` but never `input.value`, and the `ajax: true` branch explicitly skips the
initial-population call:

```javascript
if ( strategy.ajax ) {
    // select2's own `ajax.transport` (wired above) drives population per keystroke —
    // nothing to pre-fetch.
    ensureSelect2();
} else {
    strategy.fetchEntries( '' ).then( ... )
}
```
— `location-select-modes.js:357-375`

This is issue **#447**: the reasoning in that comment is correct for a fresh field with no value,
but wrong for one being re-rendered with an existing value — exactly the case a page reload or a
sibling-field re-render produces.

### 2.2 minimumInputLength

Documented as a **data-adapter decorator** that gates the query BEFORE it reaches the ajax adapter
at all — no request is even attempted below the threshold:

```javascript
MinimumInputLength.prototype.query = function (decorated, params, callback) {
  params.term = params.term || '';
  if (params.term.length < this.minimumInputLength) {
    this.trigger('results:message', { message: 'inputTooShort', ... });
    return;
  }
  decorated.call(this, params, callback);
};
```
— `selectWoo.full.js:3885-3902`

WooCommerce's own ajax selects always set this explicitly — never rely on the (undocumented-by-us)
default:

```javascript
minimumInputLength: $( this ).data( 'minimum_input_length' ) ? ... : '3',   // product search
minimumInputLength: $( this ).data( 'minimum_input_length' ) ? ... : '1',   // customer search
```
— `D:/Projects/wordpress/woocommerce/assets/js/admin/wc-enhanced-select.js:123,207`

`woocommerce-edostavka`'s city field passes `minimumInputLength: 0` from the checkout wiring layer
(`checkout-city-select.js:37`) but `2` as the adapter's own default when no override is given
(`city-select.js:58`) — i.e. the plugin's baseline is 2, and the checkout page deliberately
overrides it to 0 for its own reasons (city search is cheap/cached server-side per the plugin's own
`cache: true`; **UNVERIFIED** why 0 specifically was chosen there — not stated in either file).

**Our code sets no `minimumInputLength` at all** — `config = { width: '100%' }` plus an optional
`ajax` block only (`location-select-modes.js:331-352`). That is issue **#449**'s first cause: with
no floor, select2's ajax adapter queries on focus with `term: ''`.

### 2.3 `ajax.delay` — debounce is select2's own, not the transport's

```javascript
if (this.ajaxOptions.delay && params.term != null) {
  if (this._queryTimeout) { window.clearTimeout(this._queryTimeout); }
  this._queryTimeout = window.setTimeout(request, this.ajaxOptions.delay);
} else {
  request();
}
```
— `selectWoo.full.js:3616-3624`, inside `AjaxAdapter.prototype.query`

This answers the brief's question directly: **`delay` is applied by select2 itself**, before the
transport is ever invoked — the transport does not need its own debounce logic, and adding one
there would be redundant with (or could conflict with) select2's own `_queryTimeout`.

WooCommerce's own baseline is `250` ms almost everywhere, with one deliberate outlier:

```javascript
delay: 250,   // product search, page search, category search, taxonomy-term search, attribute search
delay: 1000,  // customer search — more expensive query, slower typing tolerance accepted
```
— `wc-enhanced-select.js:130,173,214,279,322,366`

`woocommerce-edostavka`'s city adapter also uses `250` (`city-select.js:108`).

**Our code sets no `ajax.delay`** — same `config = { width: '100%' }` gap as above. This is issue
**#449**'s second cause: a request fires on every keystroke.

### 2.4 Aborting a superseded request — the documented transport contract

select2's own docs are explicit about the return contract:

```javascript
// You can use a custom AJAX transport function if you do not want to use the
// default one provided by jQuery.
//
// @param params  The object containing the parameters used to generate the request.
// @param success A callback function that takes `data`, the results from the request.
// @param failure A callback function that indicates that the request could not be completed.
// @returns An object that has an `abort` function that can be called to abort
//   the request if needed.
transport: function(params, success, failure) {
    var $request = $.ajax(params);
    $request.then(success);
    $request.fail(failure);
    return $request;
}
```
— Context7 `/select2/select2`, `docs/data-sources/ajax.md`

The real `AjaxAdapter.prototype.query` in our shipped build enforces exactly that contract at the
call site — it stores whatever the transport returns, and on the NEXT query call, aborts it if (and
only if) it looks abortable:

```javascript
if (this._request != null) {
  // JSONP requests cannot always be aborted
  if ('function' === typeof this._request.abort) {
    this._request.abort();
  }
  this._request = null;
}
...
function request () {
  var $request = options.transport(options, function (data) { ... }, function () { ... });
  self._request = $request;
}
```
— `selectWoo.full.js:3564-3571, 3585-3613`

The stock (default) transport is literally the exact snippet the docs show — a jQuery `$.ajax()`
call, whose return value (a jqXHR) already has `.abort()`:

```javascript
transport: function (params, success, failure) {
  var $request = $.ajax(params);
  $request.then(success);
  $request.fail(failure);
  return $request;
}
```
— `selectWoo.full.js:3543-3550` (`AjaxAdapter.prototype._applyDefaults`'s own default)

**This is why WooCommerce's own ajax selects never define a custom `transport` at all** —
`wc-product-search`, `wc-customer-search`, `wc-category-search`, `wc-taxonomy-term-search`,
`wc-attribute-search` (`wc-enhanced-select.js`, five call sites) all configure only
`url`/`dataType`/`delay`/`data()`/`processResults()`/`cache`, leaving `transport` at its default.
The stock transport already satisfies the abort contract because jQuery's own AJAX call returns an
abortable jqXHR.

`woocommerce-edostavka`'s city adapter DOES write a custom transport (because it needs to abort a
PREVIOUS in-flight request itself, tracked in a closure variable, before jQuery's own single-request
tracking would):

```javascript
transport: function( xhr_params, success, failure ) {
    if ( xhr ) {
        xhr.abort();
    }
    xhr = $.ajax( xhr_params );
    xhr.then( success );
    xhr.fail( failure );
    return xhr;
},
```
— `city-select.js:117-128`

Two things happen here that matter: (1) it **returns** the jqXHR, satisfying the contract select2
checks for; (2) it belt-and-suspenders aborts the previous request itself inside the closure, on
top of select2's own `.abort()` call on the OLD returned object — redundant but harmless, since
`xhr.abort()` on an already-settled request is a no-op.

**Our `transport` returns nothing:**

```javascript
config.ajax = {
    transport: function( params, success, failure ) {
        var term = params && params.data && params.data.term ? params.data.term : '';
        strategy.fetchEntries( term ).then( function( entries ) {
            applyEntries( entries, false );
            success( { results: ... } );
        }, failure );
    },
};
```
— `location-select-modes.js:333-352` — no `return` statement at all.

`self._request` therefore becomes `undefined` after every call. On the NEXT keystroke,
`this._request != null` is `false` (`undefined != null` is `false` in JS), so select2's own abort
branch **never runs** — not because the object lacks `.abort()`, but because select2 never even
tries. This is issue **#449**'s third and most damaging cause, and the direct explanation for the
"last-arrived-wins" flicker the operator observed: every one of the six in-flight requests for
"Москва" eventually resolves and calls `success()`, each one repainting the results list.

---

## 3. Defect-by-defect table

| Issue | What the reference does | What we do | Smallest change that closes the gap |
|---|---|---|---|
| **#447** — field renders empty, loses saved value | select2 docs: append a pre-selected `new Option(text, id, true, true)` before init (§2.1). eDostavka: append `<option value=X>X</option>` before `.selectWoo()` when `$element.val()` is non-empty (`city-select.js:69,90-98`). | `buildSelectField()` never reads `input.value`; the `ajax: true` branch skips population entirely (`location-select-modes.js:357-362`). | In `buildSelectField()`, before `ensureSelect2()` on the ajax path: if `input.value` is non-empty, create one `<option>` (`value`/`textContent` = the input's current value) and append it to `select` — same shape `applyEntries()` already builds — so the native `<select>` (and select2 once it inits) starts with a selected, non-empty option. No fetch needed; the label the field already carried IS the display text (matches `fieldValueFor()`'s own derivation — nothing but its own value round-trips). |
| **#448** — address inherits the settlement axis mode | N/A — this is a WOODEV cascade bug, not a select2 concern. WooCommerce's own registry pattern (`registry['ajax-select2']`, bare key, `location-select-modes.js:482`) is what selectWoo/select2 itself has nothing to say about; the defect is in `resolveModeRenderer()`'s per-level lookup, not in how select2 is driven. | `resolveModeRenderer()` uses a binary ternary — `region` vs. everything else — so `address` silently reuses the settlement axis (`location-cascade.js:588-601`). | Per the card's own "What to do": make the level→axis mapping explicit (`region`→region axis, `settlement`→settlement axis, everything else→`null`/no mode renderer, falling through to the baseline typeahead) instead of a binary fallback. Out of scope for this select2 study beyond confirming it is NOT a selectWoo contract issue. |
| **#449** — searches on empty input, no debounce, un-abortable requests | select2: `minimumInputLength` gates the query before it reaches the ajax adapter (§2.2); `ajax.delay` is select2's own debounce, applied in `AjaxAdapter.query` (§2.3); the documented transport contract requires returning an abortable object, and the stock transport (a plain `$.ajax()` call) already satisfies it (§2.4). WooCommerce: sets `minimumInputLength` (1–3) and `delay: 250`/`1000` on every ajax select, using the STOCK transport, no custom one (`wc-enhanced-select.js`). eDostavka: sets `minimumInputLength` (2, overridden to 0 for its own checkout use), `delay: 250`, and a custom transport that DOES `return xhr` (`city-select.js:100,108,117-128`). | `config = { width: '100%' }` plus `ajax.transport` only — no `minimumInputLength`, no `delay`, and the custom `transport` has no `return` statement at all (`location-select-modes.js:331-352`). | Three independent, additive fixes in `ensureSelect2()`: (1) add `config.minimumInputLength` (value TBD by the operator — WC's own range is 1–3, eDostavka's own city default is 2); (2) add `config.ajax.delay` (WC's own baseline is 250ms; eDostavka's city field also uses 250ms — use 250 unless the operator wants the provider's own rate limits to dictate otherwise); (3) `return` the transport's underlying request object — since `strategy.fetchEntries()` wraps `options.fetch()` (a `fetch()`-based promise, not a jqXHR), the transport needs to construct an `AbortController`, pass its `signal` through to `options.fetch()` (which does not currently accept one — a `location-cascade.js` change, out of scope here), and return `{ abort: function() { controller.abort(); } }` so select2's own `'function' === typeof this._request.abort` check passes and its automatic supersede-abort actually fires. |
| **#450** — zero test coverage, jsdom has no select2 | eDostavka ships no test coverage of its own for this file either (**UNVERIFIED** — not searched exhaustively; out of this study's scope since the brief asks about select2/WC, not eDostavka's test suite). select2 itself is a real, if heavy, dependency — its own project does not offer a documented lightweight fake for unit tests (**UNVERIFIED** from the docs pulled). | `ensureSelect2()`'s guard (`'function' !== typeof $select.select2`) always takes the "select2 absent" branch in jsdom, so `config` — including the three items above — is never executed by any test (`location-select-modes.js:324-329`, confirmed by the card's own analysis). | This is a test-infrastructure decision for the operator (the card's own "Variants" section, options 1–2 — a fake `jQuery.fn.select2` stub recording the passed config, and/or extracting a pure `configFor(strategy)` function testable without any select2 at all). Nothing in select2's own docs changes this recommendation; noting only that whichever fake is built must model the SAME contract points found in §2 (`abort()`-having transport return, `delay`, `minimumInputLength`) or it will pass tests without actually pinning the fix for #449. |

---

## 4. The eDostavka adapter — what it is, what it solves, reuse verdict

**What it is**: `$.WCEdostavkaSelectCity` (`plugins-reference/woocommerce-edostavka/assets/js/frontend/city-select.js`), wired into the checkout by `checkout-city-select.js` (localized via
`wp_localize_script( 'wc-edostavka-checkout-city-select', 'edostavka_checkout_params', ... )` —
`includes/class-wc-edostavka-checkout.php:151` — confirming `checkout-city-select.js`, not
`related-checkout-fields.js`, is the live consumer of `city-select.js` on the checkout page).
Registered against `selectWoo` explicitly (`includes/class-wc-edostavka-checkout.php:106-109`).

It solves the exact problem shape the brief names — **a remote source whose records are not plain
`{id, text}` pairs** — through three mechanisms our renderer has none of:

1. **A custom AMD `resultsAdapter`.** It registers a decorator via select2/selectWoo's own module
   system (`$.fn.selectWoo.amd.define('edostavka/resultsAdapter', ['select2/utils', 'select2/results'], ...)`,
   `city-select.js:6-45`) that overrides `setClasses()` to mark the currently-selected result by
   matching `customerLocation.city_code` against each candidate's `item.code` — i.e. it can tell
   two results with different display text but the same underlying entity apart, and vice versa.
   This is the single most reusable idea for us: our `dataByKey` map (`location-select-modes.js:256-297`)
   already carries the full record per option key, but nothing compares it against a currently-known
   "confirmed" identity the way this adapter's `setClasses()` does — worth studying if a future pass
   needs to visually distinguish "this option is the customer's actual pick" from "this option
   merely matches the typed text."
2. **Rich `processResults`/`templateResult`/`templateSelection`.** The custom data shape
   (`term.code`, `term.city`, `term.region`, `term.sub_region`, `term.country`) is mapped onto
   select2's `{id, text}` minimum inside `processResults` (`city-select.js:129-146`), then rendered
   with ancestor context (`"Жуковский (Московская обл.)"`) via `templateResult`
   (`city-select.js:153-178`) while `templateSelection` strips that back down to the bare value
   (`city-select.js:150-152`). This is precisely the value/label split
   `location-cascade.js`'s own `fieldValueFor()` already does server-side-derivation-wise
   (region/settlement fields get the bare component name, never the label with ancestors —
   `location-cascade.js:621-672`) — the two solve the SAME problem, our own version further
   upstream in the pipeline (deriving the right VALUE once) rather than at render time (deriving
   the right DISPLAY once); they are not in conflict, but a future author should not assume one
   makes the other unnecessary — `templateResult`'s job is exclusively about the DROPDOWN LIST
   entries, is never persisted, and is orthogonal to what `fieldValueFor()` writes into the field.
3. **Working value seeding + working transport abort** — see §2.1 and §2.4 above; this is the part
   that most directly should be adopted as the model for our fix.

**What it does NOT solve, and is not a template for**: it talks to its own bespoke AJAX action
(`action: 'edostavka_get_location_cities'`, `city-select.js:111`) and its own customer-location
persistence endpoint (`edostavka_set_customer_location`, `checkout-city-select.js:105`) — there is
no `/location/suggest`/`/select` REST contract underneath it, no D8 persist-then-trigger route, no
backwards-fill, no per-level scoping via `within`. It is also written against the **legacy,
pre-namespace Woodev framework** — the vendored copy at
`plugins-reference/woocommerce-edostavka/woodev/class-plugin.php:20` declares
`const VERSION = '1.3.3'`, and its classes use the old `Woodev_*` global-prefix convention
(e.g. `Woodev_Helper`, `plugins-reference/woocommerce-edostavka/woodev/class-helper.php:9`), not the
namespaced/PSR-4 classes `location-cascade.js`'s own PHP counterparts use — but this JS file itself
never calls into the vendored `woodev/` PHP layer at all; the version note matters only as
provenance ("written pre-clean-break"), not as a coupling risk.

**Verdict: adapt, not adopt as-is.** The plugin cannot be dropped in — it owns its own transport
endpoint and its own customer-location model, neither of which exists in our framework's location
provider contract. But three concrete techniques should be replicated directly, because they are
the literal fix for #447 and #449 and the operator's framing ("possibly still useful with custom
data") is correct about the mechanism, if not the whole file:

- The pre-select-before-init seeding pattern (§2.1) — copy the SHAPE, not the code (ours has no
  separate "current customer location" object to reconcile against; a bare seeded `<option>` from
  `input.value` is sufficient for us).
- The `return xhr` + pre-abort transport pattern (§2.4) — direct model for the fix, adapted to
  `fetch()`/`AbortController` instead of jQuery's `$.ajax()`, since our `options.fetch()` primitive
  is not jQuery-based.
- The `resultsAdapter` decorator technique (§ above) — not needed to close any of the four current
  issues, but the right reference IF a future card asks for "show which result is the customer's
  current pick" in the dropdown itself.

I did not check the `D:/Projects/wordpress/woocommerce-edostavka` working checkout for whether it
has diverged from the `plugins-reference` copy beyond a version bump — the coordinator's correction
said to prefer `plugins-reference` and only compare if needed, and nothing in this study depended on
which of the two is newer.

---

## 5. Where the references disagree, or don't fully agree with each other

- **select2's docs vs. WooCommerce's own practice on `transport`**: the docs present a custom
  `transport` as an available option; WooCommerce's own code never uses one anywhere in
  `wc-enhanced-select.js` (5 ajax selects, all stock transport) or `country-select.js` (not ajax at
  all — see below). **WooCommerce's practice does not contradict the docs** — it simply never needed
  the escape hatch, because its remote data always goes through jQuery's own `$.ajax()`, which
  already returns an abortable jqXHR. **This governs us only partially**: our `options.fetch()` is
  a `fetch()` wrapper, not `$.ajax()`, so we cannot follow WooCommerce's "just use the stock
  transport" path — we are forced onto the documented custom-transport contract that WooCommerce
  itself never exercises. eDostavka's own custom transport (§2.4) is the closer precedent for us
  specifically because it, too, needed a non-stock request mechanism at the time it was written
  (though it ended up choosing `$.ajax()` anyway) — its RETURN-THE-ABORTABLE-OBJECT discipline is
  what carries over, not its literal jQuery call.
- **WooCommerce's checkout country/state select (`country-select.js`) is not ajax-backed at all** —
  worth flagging since the brief specifically asked about a possible conflict with our field on the
  same form. It replaces the whole `<option>` set locally from an already-localized `states_json`
  blob (`country-select.js:83-150`) and re-selects the previous value synchronously
  (`$statebox.val( value ).trigger( 'change' )`, `country-select.js:152`) — there is no `ajax`
  config, no `minimumInputLength`, no `transport` anywhere in this file. It is the closest analogue
  to our `related-list` (non-ajax) mode, not `ajax-select2`. **No conflict found** between it and
  our ajax-select2 mode at the select2-configuration level; if a conflict exists it would be at the
  DOM/id level (two mechanisms racing to own `#shipping_state`), which is outside what this select2
  study can confirm — **UNVERIFIED** beyond "the two files never touch the same select2 `ajax`
  config."
- **eDostavka's own two internal version strings for its selectWoo fallback disagree with each
  other** (`1.0.6` vs `1.0.9-wc.X` — §1) — noted as a pre-existing minor inconsistency in that
  plugin, not a disagreement between our references that this study needs to resolve; both are
  fallbacks that never fire against a real WooCommerce install.
- No disagreement found between select2's documented `minimumInputLength`/`delay`/transport contract
  and the actual `selectWoo.full.js` behaviour — the shipped build matches the docs exactly at every
  point checked in §2.

---

## Related

- Issues [#447](https://github.com/kalbac/woodev-plugin-framework/issues/447),
  [#448](https://github.com/kalbac/woodev-plugin-framework/issues/448),
  [#449](https://github.com/kalbac/woodev-plugin-framework/issues/449),
  [#450](https://github.com/kalbac/woodev-plugin-framework/issues/450) — the defects this study
  exists to explain.
- `woodev/shipping-method/assets/js/frontend/location-select-modes.js` — the code under study.
- `woodev/shipping-method/assets/js/frontend/location-cascade.js` — `resolveModeRenderer()` (#448).
- `docs-internal/research/2026-08-11-locality-field-brainstorm-brief.md` — prior research on the
  same location-field layer (region/settlement/address terminology, provider contract).
