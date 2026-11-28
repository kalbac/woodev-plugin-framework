# Gotcha: [admin-ui/notices] — a wc-admin page shows every snackbar of the notices store itself

> Tags: wc-admin, snackbar, core/notices, embedded settings | Card: #2025

## What happens

The settings page drew its own `SnackbarList` over the `core/notices` store. On Woodev → Настройки that is the only list. The same
body embedded in a marketplace plugin's page is part of a **wc-admin** page, whose transient notices already render every
`snackbar` notice of that store: each «Настройки сохранены.» and each refusal appeared twice, one toast over the other.

The jest suite could not see it: it renders the body alone, where one list is correct.

## Root cause

The notices store is page-wide. A component mounted inside wc-admin shares it with wc-admin's own renderer.

## Fix

```jsx
// ❌ a list of our own wherever the body is mounted
<SnackbarList notices={ snackbars } onRemove={ remove } />

// ✅ only where nobody else draws the store
{ ! isMarketplace && <SnackbarList notices={ snackbars } onRemove={ remove } /> }
```

Inside a wc-admin page, `createSuccessNotice( text, { type: 'snackbar' } )` is all there is to do.

## Related

- [a-special-branch-of-a-shared-field-renderer-drops-the-server-error](a-special-branch-of-a-shared-field-renderer-drops-the-server-error.md) — the same settings page, the other way a refusal reads wrong
- [../gotcha-index/admin-ui.md](../gotcha-index/admin-ui.md) — the topic index
