# gotcha: narrowing an option list server-side is not enough — the React settings page can put the option back

**Namespace:** `[admin-ui/react-state]`
**Discovered:** s88 (2026-08-24)

## What happened

The settlement axis was supposed to stop offering «Предустановленный список». The server was fixed
and *measured correct* three ways: the registry's own handler offered two modes, `wp eval-file`
confirmed it, and the REST payload the admin page fetches carried exactly two options.

The admin still rendered **three**. The operator saw it on the rig and reported it after the "fix"
had already been merged.

```text
GET /woodev/v1/settings  →  field_mode_settlement.options = { typeahead, ajax-select2 }
rendered dropdown        →  Текст с подсказками, Предустановленный список, Список с поиском
```

## Root cause

`src/components/control-field.js` carried `getLiveSelectOptions()` — a client-side special case
(issue #404) that, when the REGION axis's live value was `related-list`, **replaced** the
settlement axis's own option set with the REGION's, which contains `related-list`:

```js
if ( 'related-list' === ( conditionValues && conditionValues[ FIELD_MODE_REGION_SETTING_ID ] ) ) {
    return fieldModeRegionOptions;   // ← the region's three, not the server's two
}
```

Its purpose was legitimate — reflect an unsaved sibling value so the merchant sees the option set
they will get after saving, without a reload. But it means **the rendered control is not a function
of the payload**, and the two can disagree indefinitely.

## Why the server measurement did not catch it

Every server-side check was measuring the payload. Nobody was measuring the screen. The REST
response and the DOM are two different artifacts, and only the second one is what anyone can see.

## ✅ The rule

**When you narrow an options list server-side, grep the React source for a client-side override of
that same field before believing the fix.**

```bash
grep -rn "getLiveSelectOptions\|conditionValues" src/components/control-field.js
```

Any field whose options are computed from `conditionValues` (a sibling field's live value) has a
second source of truth. Today the pattern is gone for field modes, but the mechanism —
`conditionValues` reaching option computation — can come back for any cross-field rule.

**Verify on the rendered control, not the payload.** For this settings page that means opening the
dropdown, because it is a custom combobox whose options live in a popover portal — see
`a-closed-custom-select-renders-no-options.md`.

## Related

- [a-closed-custom-select-renders-no-options.md](a-closed-custom-select-renders-no-options.md) — the
  test-side twin of this trap
- [the-three-location-field-modes-and-their-russian-labels.md](the-three-location-field-modes-and-their-russian-labels.md)
