# Gotcha: [rig/i18n] — The rig runs `en_US`, so no catalogue translation EVER renders there

> Tags: rig, i18n, gettext, verification, wp-env | Session: s118

## What happens

You ship a translation, ask the operator to look at it on the rig, and he reports he sees no change
at all. You start suspecting the branch, the `.mo`, the merge, the working tree.

None of those. **The rig's locale is `en_US`**, so WordPress never loads a Russian catalogue for
anything. Measured on the rig after #771 merged:

```
locale: en_US | is_textdomain_loaded: NO
expected path: .../woodev-plugin-framework-en_US.mo | exists: NO

"Packing algorithm"           -> !! UNTRANSLATED
"Virtual box (minimal size)"  -> !! UNTRANSLATED
```

The catalogue itself was perfectly fine. Loading the committed `.mo` directly, bypassing the
locale, returns exactly what it should:

```
mo: 61 491 bytes, 417 entries
"Packing algorithm"           -> Алгоритм упаковки
"Virtual box (minimal size)"  -> Виртуальная коробка (минимальный размер)
```

## The part that inverts the intuition

This is also **why an i18n defect is visible on the rig while its fix is not.** Roughly 300 of this
catalogue's entries have RUSSIAN msgids with an empty `msgstr` — gettext returns the msgid itself,
so they render Russian at any locale, `en_US` included. The English msgids render English. The
result is a convincingly mixed admin that looks exactly like "some strings weren't translated" —
which is the real production defect, but arrived at for the wrong reason. Translating them changes
nothing on the rig, and everything on a real `ru_RU` store.

So: **the rig can show you the i18n problem and can never show you the i18n fix.**

## Why you cannot just flip the option

`wp option update WPLANG ru_RU` answers `Success: Value passed for 'WPLANG' option is unchanged` and
leaves it at `en_US`. WordPress sanitises `WPLANG` against the languages actually installed under
`wp-content/languages/`, and the rig has none — the container cannot reach wordpress.org
(`wp language core list` fails with "could not establish a secure connection"). There is no `WPLANG`
constant in `wp-config.php` to fix instead.

To actually review translations on the rig you need to force the locale, e.g. a container-only
mu-plugin doing `add_filter( 'locale', static fn() => 'ru_RU' );`. That changes documented rig
state, so **ask before doing it** and put it back afterwards. Core strings stay English (no core
`ru_RU` catalogue), which is fine — the plugin catalogue is what is under review.

## The other half of the same mistake

While you are at it, check the string is REACHABLE before naming it. #771's headline example was the
«Алгоритм упаковки» select, and it does not render on the rig at all: the field is gated on
`Shipping_Method::supports_box_packing()`, and no fixture declares `FEATURE_BOX_PACKING`:

```
woodev_realistic_shipping          box packing: no
woodev_realistic_pickup_shipping   box packing: no
woodev_test_shipping               box packing: no
```

Two independent reasons the operator could not have seen it, in one sentence of instruction.
Verify the exact rendered label AND that the code path is reachable in the current rig
configuration — before sending anyone to look.

## ✅ Correct

Prove a catalogue change by reading the artefact, not by asking someone to look at a screen that
cannot show it:

```bash
# 1. the .mo is what wp i18n make-mo produces (the standing invariant)
wp i18n make-mo <po> /tmp/out && md5sum /tmp/out/*.mo <committed .mo>
# 2. the strings resolve
php -r '$mo = new MO(); $mo->import_from_file("<committed .mo>"); echo $mo->translate("Packing algorithm");'
# 3. npm run lint:mo   — the CI gate that keeps 1 true
```

## Related

- [the-mo-is-reproducible-from-the-po](the-mo-is-reproducible-from-the-po.md) — the invariant the
  first probe above tests.
- [classify-an-i18n-string-by-its-render-path-not-its-file-path](classify-an-i18n-string-by-its-render-path-not-its-file-path.md)
  — the same "where does this actually surface?" question, one step earlier.
- [rig-checkout-url-is-the-block-checkout](rig-checkout-url-is-the-block-checkout.md) — the other
  rig trap that reads as a broken build rather than a wrong place to look.
- [wiki/local-rig.md](../wiki/local-rig.md)
