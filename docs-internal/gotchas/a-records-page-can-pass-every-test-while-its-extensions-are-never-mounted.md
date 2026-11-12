# Gotcha: [admin-ui/records] A records page passes every unit and jest test while its extensions are never mounted

**Discovered:** s229 (12.11.2026), F12a #1691 / PR #1743, found by the Opus critic.

## What happens

The «Товары сайта» records view rendered only the table: bulk actions and activity extensions were missing. PHPUnit,
jest and CI asset parity were all green.

## Root cause

The extensions are registered through hooks (`useActionExtensions`, activity extensions), and the page's entry never
mounted them. Unit tests exercise the extension in isolation and parity only compares the bundle with the source, so
nothing asserted that the built entry wires them in.

## Fix

For a records page, check that the entry mounts the hooks, and keep one test that renders the page entry and expects an
extension-provided control.

```js
// ❌ the entry renders <RecordsTable /> alone
// ✅ the entry also calls useActionExtensions() and mounts the activity extensions
```

## Related

- [a-test-that-manufactures-its-own-precondition-never-tests-the-producer](a-test-that-manufactures-its-own-precondition-never-tests-the-producer.md) — green tests that never reach the wiring
