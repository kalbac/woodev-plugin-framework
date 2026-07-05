# Settings sensitive secret: the "don't overwrite with empty" guard is CLIENT-side, not server

**Namespace:** `settings-api/secrets` · **Session:** s41 (2026-07-06)

## The trap

A `sensitive` settings field masks its stored value: the secret never reaches the
browser, the input renders empty with a "saved" placeholder, and an untouched field
is not re-saved with an empty value. It is tempting to assume the **server** protects
the secret — i.e. that `POST woodev/v1/settings/{provider}` skips a sensitive field
whose posted value is empty, so a blank submit can't wipe a stored credential.

**It does not.** `Woodev_REST_API_Settings_Page::save()` persists **exactly** the
fields the client sends (`values`), scoped to the tab's declared ids + validated, then
calls `update_value()` on each — with **no** "skip empty sensitive" branch. The only
place with a stored-secret fallback is `test_connection()` (it merges the stored secret
for a masked field so the plugin's test still works) — the SAVE path has none.

The "don't overwrite a masked secret with empty" behavior is therefore **purely
client-side dirty-tracking**: the React app sends only fields present in
`edits[providerId]`. An untouched masked field is absent from `edits` → never sent →
the stored secret is preserved. (Contrast the `constant_managed` guard, which IS
server-side: `update_value()` returns early for a defined constant.)

## Consequences

1. **A wipe is just an explicit empty edit.** Sending `''` for a sensitive field calls
   `update_value(id, '')` → `save()` → `delete_option()`. This is exactly what SP-2-DEF
   («Очистить сохранённое») relies on — the whole feature is client-only, no PHP change.
2. **Any non-browser / future REST consumer that posts `''` for a sensitive field WILL
   wipe it.** There is no server guard. A programmatic client must replicate the
   browser's discipline: send a sensitive field only when the user actually set a new
   value; never post `''` unless you intend to clear the credential.

## Correct / incorrect

- ❌ Assuming the server ignores an empty sensitive value on save (it doesn't).
- ❌ Adding a server-side "skip empty sensitive on save" to "protect" the secret — that
  would silently **break the intentional wipe** (SP-2-DEF).
- ✅ Keep the untouched-not-sent discipline in the client; treat an explicit `''` as a
  deliberate clear; if a server guard is ever wanted, gate it on an explicit
  "clear this field" signal, not on emptiness.

## Related

- [[mask-constant-backed-field-even-when-constant-undefined]] — the constant guard IS server-side.
- SP-2-DEF (s41, PR #100): the wipe/clear affordance built on this fact.
- SP-2 (s38, PR #94): masking + connection-test stored-secret merge.
