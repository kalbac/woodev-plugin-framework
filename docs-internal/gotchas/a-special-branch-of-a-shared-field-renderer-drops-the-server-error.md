# A special-case branch of a shared field renderer drops the server's field error

**Namespace:** `admin-ui/errors` · **Session:** bot mode, 26.11.2026 (#2016)

## The trap

`POST woodev/v1/settings/{provider}` answers a refusal as `data.errors` (setting id => message), `App` keeps
the map, and the field shows it through `schema.serverError`. Every ordinary field worked, so the chain looked
wired. It was broken twice, each time by a branch that returns before the shared path:

1. `SectionView` returns early for `section.is_connection` and did not hand `serverErrors` to
   `ConnectionBlock`.
2. `ControlField` returns early for `schema.sensitive` and called `withAnatomy()` without an error argument.

A marketplace token is a sensitive field inside a connection block, so it hit both: «Сохранить» said only
«Проверьте правильность заполнения полей.», while «Проверить подключение» (its own result line) gave the
reason. Fixing one of the two changes nothing on screen.

## ❌ Wrong

```js
if ( schema.sensitive ) {
	return withAnatomy( schema, createElement( SecretControl, props ) ); // no error argument
}
```

## ✅ Correct

```js
if ( schema.sensitive ) {
	return withAnatomy( schema, createElement( SecretControl, props ), schema.serverError );
}
```

A new early return in `SectionView` or `ControlField` must carry the server's error, and a new section kind with
fields must receive `serverErrors`. Test it through a real `App` render with a rejected `saveTab()`
(`tests/js/settings-page-connection-server-errors.test.tsx`): a test of one component alone cannot see the other
break.

Still without an error line, by design: `toggle` / `checkbox` / `range` controls.

## Related

- [settings-sensitive-secret-empty-skip-is-client-side](settings-sensitive-secret-empty-skip-is-client-side.md) — the other
  thing special about a sensitive field's path
- [react-missing-key-state-bleed-across-tabs](react-missing-key-state-bleed-across-tabs.md) — the settings page's tab-wide state
