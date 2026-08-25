# gotcha: a select2 `language` callback that returns `undefined` renders a BLANK message — it does not fall back to English

**Namespace:** `[frontend/select2]`
**Discovered:** s93 (2026-08-25), issue #526 / PR #533 — found by the Codex critic, then re-measured

## The plausible-but-wrong assumption

When you override select2's `language` block to source strings from somewhere else, it is natural
to write a defensive callback:

```js
// ❌ WRONG — the "defensive" version that silently makes things worse
noResults: function() {
    return typeof params.i18n_no_matches === 'string' ? params.i18n_no_matches : undefined;
}
```

The reasoning is "if I have nothing to say, return `undefined` and select2 will use its own
default". **It will not.** The customer gets an empty message box where "No results found" used to
be — strictly worse than the untranslated English you were trying to avoid.

This was written into a docblock and a test as if it were a fact. It survived until a critic was
explicitly asked to attack that one claim.

## Why — the merge is one-way

`selectWoo.full.js` (WooCommerce's bundled select2 fork), measured in the rig container:

```js
// :2236
Translation.prototype.extend = function (translation) {
    this.dict = $.extend({}, translation.all(), this.dict);
};

// :4934-4940
var baseTranslation   = Translation.loadPath( this.defaults.amdLanguageBase + 'en' );
var customTranslation = new Translation( options.language );

customTranslation.extend( baseTranslation );   // $.extend({}, EN, ours) — OURS WINS
options.translations = customTranslation;
```

`$.extend({}, base, ours)` means **any key present in your `language` object permanently shadows
the English one.** Presence is what matters, not the value the callback returns. Select2 then calls
your function and renders whatever comes back — `undefined` included:

```js
// :856-861 — the message still fires, it just has nothing to show
if (data.results == null || data.results.length === 0) {
    if (this.$results.children().length === 0) {
        this.trigger('results:message', { message: 'noResults' });
    }
    return;
}
```

## ✅ Correct: omit the key

Absence is the only thing that lets select2's own default through.

```js
// ✅ Build the object incrementally; a key you cannot answer is never defined.
var language = {};

function addSimple( name, key ) {
    if ( 'string' !== typeof params[ key ] ) {
        return;                       // <- the whole fix
    }

    language[ name ] = function() {
        return params[ key ];
    };
}
```

**A plural pair needs BOTH msgids.** WooCommerce ships `…_1` and `…_n` as separate strings, and
both branches can render, so wiring the key off only one of them reproduces the same blank-message
bug on the other branch.

## The corner this leaves, and why it is accepted here

`location-select-modes.js` wraps `noResults` in the `related-list` branch to observe abandoned
searches (#350/#517). That wrap has to install the key even when no string exists, so in that one
path the blank message is still reachable. Reaching it needs **two public filters used
destructively at once** — `woodev_location_i18n` emptying `noResults` AND WooCommerce's own
`woocommerce_get_script_data` suppressing `i18n_no_matches`. No default gets there. The trade is
deliberate and is written at the call site: the RECORD of an abandoned search outranks the message
shown for it.

## Where the strings come from

Never invent translations for this block. WooCommerce localizes them onto its own
`wc-country-select` handle as `wc_country_select_params`, and
`WC_Frontend_Scripts::get_script_data()` (`case 'wc-country-select'`) is the authoritative key
list. Declare `wc-country-select` as a script dependency so the global is both present and printed
first.

One key looks like a bug in the reference and is not: `errorLoading` returns `i18n_searching`, not
`i18n_ajax_error`. That is WooCommerce's own documented workaround for select2/select2#4355, copied
in `country-select.js` and in the CDEK reference. Do not "correct" it.

## Related

- [the-three-location-field-modes-and-their-russian-labels](the-three-location-field-modes-and-their-russian-labels.md) — which mode is which
- [select2-close-fires-before-select2-select](select2-close-fires-before-select2-select.md) — the other select2 assumption this layer got backwards
- `../sessions/s93.md` — the critic pass that refuted it
