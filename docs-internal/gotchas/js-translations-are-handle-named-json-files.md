# Gotcha: [i18n/js] — A built bundle's `__()` is answered by handle-named JSON, not by the `.mo`; a catalogue entry alone never reaches it
> Tags: i18n, js, wp-i18n, make-json, po, tooling, measurement | Session: s147 (#1032)

## What happens

A msgid in `src/` is rendered by `@wordpress/i18n` in the browser. The `.mo` is a PHP artefact: the
browser never sees it. Until #1032 `wp_set_script_translations()` was called nowhere (measured s134),
so every JS msgid rendered AS WRITTEN under every locale, and a Russian `.po` entry for it was inert —
it only satisfied the lint gates. That is why admin JS here uses Russian msgids: they were the only way
to show Russian.

## Root cause

JS translations travel a separate channel: `wp_set_script_translations( $handle, $domain, $path )`
→ `load_script_textdomain()` → a Jed JSON file, injected inline as `wp.i18n.setLocaleData()`. WordPress
looks for `{path}/{domain}-{locale}-{handle}.json` FIRST (wp-includes/l10n.php, `$handle_filename`,
line 1224 on WP 7.1) and only then for the md5 of the script's path relative to its plugin. `wp i18n
make-json` writes the md5 form. The framework is vendored at a different relative path in every plugin,
so one shipped md5 file can match at most one of them — hence handle-named files.

## Fix

The wiring is three parts, all gated:

1. `Woodev\Framework\Handlers\Script_Translations::register( $plugin, $handle )` right after each
   `wp_enqueue_script()` of a built bundle (six of them). `ScriptTranslationsTest` scans `woodev/` and
   fails for an enqueued `$build_url . '/index.js'` handle without the call.
2. `npm run build:js-i18n` (`scripts/build-js-translations.mjs`, needs the rig) writes
   `woodev/languages/woodev-plugin-framework-ru_RU-{handle}.json`, translated entries only. The
   bundle→handle table is `scripts/lib/js-translations.mjs`; a NEW bundle needs a row there.
3. `npm run lint:js-i18n` (CI `test-js` job + pre-push) compares the committed JSON with what the `.po`
   implies. An entry belongs to a bundle by its `#: assets/build/<dir>/index.js` reference, so a new JS
   msgid needs its `#:` references in the `.po`/`.pot` (copy them from `wp i18n make-pot woodev`).

❌ `wp i18n make-json woodev/languages/…ru_RU.po` on the real file. Its default `--purge` removes the JS
strings from the `.po` itself — it deleted 1128 lines while exiting 0, and the next `lint:i18n-sources`
loses the references it needs. The build script runs it on a copy with `--no-purge`.

✅ Proof on a real WordPress (rig, any locale): `add_filter( 'locale', fn() => 'ru_RU' )`, register a
script with the handle, `wp_set_script_translations()`, then `wp_scripts()->print_translations( $handle,
false )` prints «Скрыть пароль»; under `en_US` it prints nothing and the English msgid renders.

⚠ **Plural forms in JS are no longer structurally impossible** — the claim in
[russian-source-i18n-plural-n](russian-source-i18n-plural-n.md) predates this wiring. An entry with all
three `msgstr[n]` now reaches `_n()` through the JSON; the existing Russian-msgid plural in
`src/shipping-orders-page/app.tsx` still has empty `msgstr`s and so still uses the binary rule.

## Related

- [the-mo-is-reproducible-from-the-po](the-mo-is-reproducible-from-the-po.md) — the PHP-side artefact; this is its JS twin.
- [lint-i18n-answers-about-the-catalogue-not-the-code](lint-i18n-answers-about-the-catalogue-not-the-code.md) — why a catalogue entry proves nothing about delivery.
- [russian-source-i18n-plural-n](russian-source-i18n-plural-n.md) — the `_n()` limitation this wiring lifts.
