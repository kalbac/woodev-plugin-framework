# React: a stateful section component bleeds state across tabs without a `key`

**Namespace:** `[admin-ui/react-state]`
**Session:** s38 (2026-06-30)

## Symptom

In the settings page, the connection block's **ephemeral test result** («Подключение успешно») showed up in the **wrong** connection section — after switching sub-tabs from «Подключение» to «Виджет ЛК», the success message from the first block appeared next to the second block's «Подключить» button, even though that block was never tested. It also lingered after Save.

## Root cause

`app.js` renders exactly **one** `<SectionView>` for the currently-active section (`renderSection` is a TabsNav render-prop). Switching sub-tabs re-runs `renderSection` with a different `section`, but the `<SectionView>` element stays at the **same position in the React tree with no `key`**. React reconciles `SectionView → SectionView` (same type, same slot) and **reuses the existing component instance** instead of remounting it. So `ConnectionBlock`'s `useState( result )` persists across section switches → the first section's result bleeds into the next.

`edits`/`values` are provider-scoped (`edits[tab.id]`) and live in `app.js`, so they are NOT the leak — only the **section-local** `useState` (`result`, `busy`) bleeds.

## Fix

Give the per-section component a **stable, section-unique `key`** so React remounts it when the section changes:

```jsx
<SectionView key={ `${ tab.id }:${ section.id }` } … />
```

Now switching sub-tabs unmounts the old block and mounts a fresh one → `result` re-initializes from that section's own `section.status`. Also clear the ephemeral result on field edit (a stale "success" is meaningless once a credential changes).

## Rule

Any component that holds **its own `useState`** and is swapped in/out of a **single tree slot** by a tab/router/conditional MUST carry a `key` that changes with the logical identity it represents. No `key` → React reuses the instance → state bleeds. This is invisible in tests that mount one section at a time; it only shows when a human switches tabs.

## Related
- `[[classmap-autoload-breaks-class-exists-once-guard]]` — another "invisible in tests, only shows live" class of bug.
- SP-2 connection block: `src/settings-page/app.js`, `connection-block.js`, `section-view.js`.
