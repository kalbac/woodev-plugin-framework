# [i18n/russian-source-plural-n] `_n()` with Russian source strings — when it works, when it cannot, and the half that is no longer true

**Discovered:** 2026-06-11 (s7, GPT-5.5 critic BLOCK on the B-1 mixed-fleet notice renderer)
**Re-measured and corrected:** 2026-09-13 (s134, #874's bulk-action messages)

## ⚠ Correction from s134, before you read the rest

This file used to say: *«there is no ru_RU catalog to fix it, because Russian IS the source»*, and
concluded *«avoid `_n()` entirely»*. **The premise is now false.** The catalogue exists — 864 entries
— it carries the correct three-form header, and **14 plural entries were already relying on it**
before s134 added three more.

So the blanket "never use `_n()`" is too strong. The real rule has two halves, and they differ by
which side of the wire the string is rendered on.

## The measurement (s134, `wp eval` on the rig, not reasoning)

`_n( 'Не удалось экспортировать %1$d заказ из %2$d', '…%1$d заказов из %2$d', $n, $domain )`:

| n | domain NOT loaded | domain loaded (ru_RU) | correct |
|---|---|---|---|
| 1 | 1 заказ | 1 заказ | 1 заказ |
| 2 | 2 заказ**ов** ❌ | 2 заказ**а** ✅ | 2 заказа |
| 5 | 5 заказов | 5 заказов ✅ | 5 заказов |
| 21 | 21 заказ**ов** ❌ | 21 заказ ✅ | 21 заказ |

Gettext's own fallback, used whenever the domain is not loaded, is **binary**: `1 === $n ? $single :
$plural`. Right at 1 and 5, wrong at 2-4 and at 21, 31, 101 — the s7 diagnosis, confirmed.

## ✅ In PHP: `_n()` is correct, IF the entry carries all three forms

A Russian msgid normally needs no catalogue entry (`AGENTS.md` → Conventions). **A plural one does**,
and that exemption is exactly what breaks it — nothing warns, because `lint:i18n` only refuses an
ENGLISH msgid with no translation.

```po
msgid "Не удалось экспортировать %1$d заказ из %2$d"
msgid_plural "Не удалось экспортировать %1$d заказов из %2$d"
msgstr[0] "Не удалось экспортировать %1$d заказ из %2$d"
msgstr[1] "Не удалось экспортировать %1$d заказа из %2$d"
msgstr[2] "Не удалось экспортировать %1$d заказов из %2$d"
```

`[0]` and `[2]` repeating the msgid look redundant and are not: a partially filled plural entry is
not used at all, so the middle form exists only if all three do. ⚠ `wp i18n make-pot` emits only
`msgstr[0]` and `msgstr[1]` — the third must be added by hand, then the `.mo` rebuilt in the rig
container.

⚠ **Still wrong on a site whose locale is not `ru_RU`** — including our own rig, which runs
`WPLANG=en_US` deliberately. There the ru_RU catalogue never loads and the binary fallback returns.
For a Russian shop (the audience) this is fine; for a screenshot taken on the rig it is not, and that
is not a defect to chase.

## ❌ In JS: `_n()` cannot be fixed at all, so do not rely on it

Measured s134: **`wp_set_script_translations()` is called nowhere in this framework and there is no
`make-json` step.** No JS translations are loaded, ever, so `@wordpress/i18n`'s `_n()` always uses its
own binary rule and no catalogue entry can change that.

In JS, therefore: either make the phrase **count-neutral** («Выбрано заказов: %d»), or choose a
construction where the 2-4 form and the 5+ form coincide. The live example — `bulkConfirmQuestion()`
in `src/shipping-orders-page/app.tsx` — survives on the second option: «для» takes the genitive, and
«для 2 выбранных заказов» and «для 5 выбранных заказов» are the same word. That is a property of the
preposition, not a general licence; the function's own docblock says so, because rewording it would
break the string silently.

## Related

- [the-mo-is-reproducible-from-the-po](the-mo-is-reproducible-from-the-po.md) — the `.mo` must be
  rebuilt in the rig container, or the forms never reach a page.
- [lint-i18n-answers-about-the-catalogue-not-the-code](lint-i18n-answers-about-the-catalogue-not-the-code.md)
  — why neither i18n gate catches a missing plural form.
- [license-need-vs-required](license-need-vs-required.md) — same licensing/notice area as the s7 find.
- `woodev/bootstrap.php` `render_mixed_fleet_notice()` — where s7 caught it (PR #27); its
  count-neutral rephrase is still the right answer for that string, because it is rendered without
  the catalogue guaranteed.
