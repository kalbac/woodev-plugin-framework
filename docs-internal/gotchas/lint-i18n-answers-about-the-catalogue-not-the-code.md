# Gotcha: [tooling/i18n-gate] — `lint:i18n` answers about the CATALOGUE, never about the code

> Tags: tooling, i18n-gate, ci | Session: s120 (measured), s121 (fixed)

## What happens

`npm run lint:i18n` goes green while an English string a merchant reads sits in the code
untranslated. Not a rare edge — measured on `main` in s121, **28 msgids existed in `woodev/`
sources and had never reached `woodev-plugin-framework.pot`** (24 of them not in the `.po` either),
and the gate reported `OK (752 entries, 36 allowlisted)` throughout.

Among the 28 was
`This text is shown to the customer under the method name in the order form. Leave empty to hide it.`
— an English `desc_tip` a merchant reads, exactly the class of defect the i18n rules exist to
prevent, invisible to the gate built to enforce them.

It first surfaced in PR #787 (s120): a new English error message passed a green `lint:i18n` on the
very branch that introduced it.

## Root cause

`scripts/lint-i18n.mjs` opens **one file** and walks **its** entries:

```js
const PO_PATH = join( ROOT, 'woodev/languages/woodev-plugin-framework-ru_RU.po' );
const { entries } = parsePo( PO_PATH );
```

It then flags any entry whose msgid has no Cyrillic and whose msgstr is empty. So its true contract
is *"the catalogue holds no untranslated English entry"* — **not** *"the code holds no untranslated
English string"*. A string that never reached the catalogue produces no entry, so it produces no
work for the gate and no error.

This is the general shape of a listing gate's blind spot: **it can only check what is enumerated in
its own input, never what exists outside it.** The docs gate has the identical asymmetry for a
different reason — see the Related link.

## Fix

❌ Reading a green `lint:i18n` as "the code's translatable strings are in order". It proves a
narrower claim than it feels like, and the narrow claim is about a file, not about the source tree.

✅ `npm run lint:i18n-sources` (`scripts/lint-i18n-sources.mjs`, added s121/#791) closes the other
half. It re-extracts every msgid straight from the sources with `wp i18n make-pot` and asserts each
one is present in **both** the `.pot` and the `.po` — both, because a msgid that reaches the `.pot`
but not the `.po` is still invisible to `lint:i18n`.

Three properties of it that are load-bearing, and each was proven adversarially before merge:

1. **It uses real wp-cli, not a hand-rolled scanner.** A single-literal scanner cannot see a
   concatenated msgid; `make-pot` folds it the way PHP and gettext do. Verified with a live probe:
   `__( 'Zzz probe part one ' . 'and part two', … )` **is** caught.
2. **It refuses to pass without wp-cli.** No `WP_CLI_PHAR` and no `wp` on `PATH` → exit non-zero
   with an actionable message. A gate that silently skips its own check is the same failure shape
   as the one this gotcha describes.
3. **It is ONE-DIRECTIONAL on purpose.** Source → catalogue only. A catalogue entry with *no*
   source is **not** an error here — cleaning those up is card #775, and a stricter check must not
   do that card's work as a side effect. The 39 such entries on `main` are expected to stay.

⚠ **`wp i18n make-pot` needs neither WordPress nor wp-env** — it runs on plain PHP against a plain
directory. That is why the gate could go into the existing `lint` CI job (which already has PHP 8.1)
instead of standing up a container. The wp-cli phar is **pinned at 2.12.0**, the rig container's
version, so the gate's answer drifts with this repo rather than with upstream.

## s129: a TypeScript string is gated by this PHP-shaped gate, through the BUILT BUNDLE

The extraction runs over `woodev/`, and `woodev/assets/build/**` lives inside it. So a string added
in `src/shipping-orders-page/*.ts` reaches this gate **after `npm run build`**, as
`#: assets/build/shipping-orders-page/index.js:1`. Two consequences that cost a CI round in s129:

- **A front-end-only change can fail an i18n gate.** PR #844 was green on jest, typecheck,
  `lint:ts-baseline`, `lint:i18n`, `lint:mo` and `lint:phone-masks` locally, and CI's `Lint` job
  still failed on three msgids («не равен», «Убрать фильтр по пункту выдачи», «Пункт выдачи
  {{rule /}} {{filter /}}») introduced by a `.ts` file. Running four of the five lint scripts is
  not running the gate — `lint:i18n-sources` is the one that reads the code.
- **Adding the entries is the sanctioned fix, and it is additive.** Both files, msgstr empty (the
  shape every Russian admin msgid here already uses — the `.mo` carries only *translated* entries,
  so its 429 do not move). Do **not** "fix" it with a full `wp i18n update-po` rebuild: that drops
  the obsolete `#~` tail and renumbers every reference, which is a separate decision — see the
  Related link.

## Related

- [a-docs-gate-checks-links-not-listings](a-docs-gate-checks-links-not-listings.md) — the same
  asymmetry in `lint:docs`: a gate follows the links that are written and never sees the row nobody
  wrote. Different mechanism, identical shape.
- [a-concatenated-msgid-is-invisible-to-a-single-literal-scanner](a-concatenated-msgid-is-invisible-to-a-single-literal-scanner.md)
  — why a hand-rolled Node scanner was the wrong answer here, and why real `make-pot` was the right one.
- [the-mo-is-reproducible-from-the-po](the-mo-is-reproducible-from-the-po.md) — the third gate in
  this family; `.mo` is rebuilt only by `wp i18n make-mo` in the rig container.
- [classify-an-i18n-string-by-its-render-path-not-its-file-path](classify-an-i18n-string-by-its-render-path-not-its-file-path.md)
  — which language a msgid should be in, once it does reach the catalogue.
- [a-po-merge-that-drops-obsolete-entries-still-looks-well-formed](a-po-merge-that-drops-obsolete-entries-still-looks-well-formed.md)
  — why the s129 fix added three entries by hand instead of regenerating the catalogue.
- [npm-run-test-js-is-not-the-whole-js-gate](npm-run-test-js-is-not-the-whole-js-gate.md) — the same
  lesson from the JS side: the CI job runs more commands than the one you ran locally.
