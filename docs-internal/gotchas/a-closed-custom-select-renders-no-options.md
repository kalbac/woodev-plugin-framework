# gotcha: asserting on a CLOSED custom select proves nothing — its options are not in the DOM at all

**Namespace:** `[testing/js]`
**Discovered:** s88 (2026-08-24)

## What happened

A test was written to prove the settlement chooser no longer offers «Предустановленный список»:

```js
render( createElement( ControlField, { settingId: 'field_mode_settlement', schema, ... } ) );
expect( screen.queryByText( 'Предустановленный список' ) ).toBeNull();   // ✅ passes
```

It passed. It also passed against an implementation deliberately broken to re-add that exact
option. `woodev-select` is a `components-dropdown`, not a native `<select>` — its options render
into a popover portal **only while open**, so a closed control contains none of them and the
negative assertion is vacuous.

## Two more traps in the same 20 minutes

Both were found only because the fix was **controlled** (deliberately reverted, expecting failure):

1. **The control patch edited the wrong branch.** `control-field.js` has three
   `normalizeOptions( schema.options )` call sites — `select`, `radio`, `multiselect`. Patching by
   "first occurrence" or by a remembered line number hit `radio`. The test passed, proving nothing.
2. **The control used a placeholder label.** Injecting `'related-list': 'CONTROL-PRESET'` while the
   assertion looked for `'Предустановленный список'` also "passed".

A control that passes is not a control. If reverting the fix does not turn the test red, the
control itself is broken — find out why before trusting the test.

## ✅ Open the control, and assert a positive first

```js
test( '…', async () => {
    const { container } = render( createElement( ControlField, { … } ) );

    fireEvent.click( container.querySelector( '.woodev-select__trigger' ) );

    // Await it: the popover positions asynchronously, and the state update otherwise
    // lands outside act() — @wordpress/jest-console then fails the NEXT test in the file.
    await waitFor( () => {
        expect( screen.getByText( 'Текст с подсказками' ) ).toBeTruthy();   // positive control
    } );

    expect( screen.queryByText( 'Предустановленный список' ) ).toBeNull();
} );
```

Two things make it honest:

- **the positive assertion first** — it can never again pass by rendering nothing;
- **`await waitFor`** — without it the Popover's own state update escapes `act()`, and
  `@wordpress/jest-console` turns the warning into a failure of a *different, unrelated* test later
  in the file, which reads as an unrelated regression.

For assertions about the option SET only (not the widget), the `radio` control is the cheaper
target — same `normalizeOptions()` call, native inputs, no portal. `settings-page-control-field.test.js`
already uses it that way for issue #387.

## Related

- [narrowing-a-server-option-list-has-a-client-half.md](narrowing-a-server-option-list-has-a-client-half.md)
  — the production-side twin
- [jest-resetmodules-leaves-listeners-on-the-surviving-body.md](jest-resetmodules-leaves-listeners-on-the-surviving-body.md)
