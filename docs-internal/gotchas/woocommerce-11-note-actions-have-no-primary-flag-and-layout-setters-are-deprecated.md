# gotcha: WooCommerce 11 stores no `primary` flag on a note action, and `Note::set_layout()` / `set_image()` are deprecated

**Namespace:** `[woocommerce/admin-notes]`
**Discovered:** s170 (2026-10-12), carrier competitor notices (#1230)

## What happened

1. `Note::set_layout()` and `Note::set_image()` call `wc_deprecated_function()` unconditionally since
   WC 10.8.0. Every note creation logged a deprecation.
2. `Note::add_action( $name, $label, $query, $status, $primary )` ignores `$primary` in WC 11.1: the
   stored action object has `name`, `label`, `query`, `status`, `actioned_text`, `nonce_*` only. The
   renderer compared desired actions with `$action->is_primary`, so it raised
   «Undefined property: stdClass::$is_primary» on every admin page (the warning stretched the notice
   area over the screen) and, because the comparison never matched, re-saved the note on every request.

The unit tests did not see it: the WC note stub still stored `is_primary`.

## ✅ Correct

Do not call `set_layout()` / `set_image()` (layout defaults to `plain` on every WC version). Compare
note actions without `primary`, reading every stored property with `?? ''`. Keep test stubs shaped
like the real WC version the rig runs, and check the rig's `debug.log` after a notice change.

## Related

- [a-rest-arg-default-outside-its-own-minimum-rejects-every-request](a-rest-arg-default-outside-its-own-minimum-rejects-every-request.md)
