# Gotcha: [rig/loader] — a fixture plugin whose `framework_version` is below the winning plugin's `backwards_compatible` floor is silently dropped: it shows "active", registers nothing

> Tags: rig, loader, fixtures, e2e | Session: s161 (#1173)
**Namespace:** `[rig/loader]`

## The trap

`wp plugin list` says `woodev-test-shipping-method` is **active**, the zone holds an instance of
`woodev_test_shipping`, the DB looks right — and the method is still **not offered at checkout**
(`input[name^="shipping_method"][value^="woodev_test_shipping:"]` → 0 elements). Nothing in the
debug log, no admin notice on the front end.

Cause: `Woodev_Framework_Resolver` sorts the registered plugins by `framework_version`; the
highest wins and its loader definition's `backwards_compatible` becomes the floor. Every plugin
whose own `framework_version` is **below** that floor goes to `incompatible_framework_plugins` and
is skipped (`class-framework-resolver.php`, the `version_compare( $backwards_compatible, $plugin['version'], '>' )`
branch). On the rig `woocommerce-edostavka` (`framework_version 2.0.1`, `backwards_compatible 2.0.0`)
wins, so the two fixtures still declaring `1.4.0` (`woodev-test-shipping-method`,
`woodev-test-plugin`) are dropped. The v2 fixtures (`2.0.0`) load fine — that contrast is the
fastest diagnosis.

It only bites while CDEK is active on the rig, which is why a rig without it (or before it was
mounted) looked healthy.

## Diagnose in one call

```bash
C="$(scripts/machine/rig-container.sh cli)"
docker exec "$C" wp eval '$b = Woodev_Plugin_Bootstrap::instance(); $r = new ReflectionObject($b);
  foreach (["registered_plugins","active_plugins","incompatible_framework_plugins"] as $n) {
    $p = $r->getProperty($n); $p->setAccessible(true); echo $n, ": ", count($p->getValue($b)), "\n"; }'
```

`incompatible_framework_plugins` above 0 is the symptom.

## Fix

⚠ **Not merged — bumping the version alone breaks the rig (measured s161).** Branch
`kalbac/s161-1173-rig-pickup` (`ca66c4ee`) set `'framework_version' => '2.0.0'` on the three 1.4.0
fixtures; unit and jest stayed green, but on the rig all 8 classic-checkout e2e tests failed: once the
fixtures load, three shipping plugins enhance the same native checkout fields,
`Checkout_Handler::guard_native_field_conflicts()` prints `_doing_it_wrong` notices into the page
(`WP_DEBUG_DISPLAY`), and `form.checkout` never renders. The way forward is on card #1173 (move the
pickup spec to the realistic fixture, or switch CDEK off for that e2e).

❌ "bump the fixture to 2.0.0 and it works". ✅ Diagnose with the call above; a NEW fixture declares
`2.0.0` from the start AND must not enhance the same native fields as an active carrier.

## Related

- [rig-serves-the-working-tree-branch-switch-reverts-fixes](rig-serves-the-working-tree-branch-switch-reverts-fixes.md)
- [../wiki/local-rig.md](../wiki/local-rig.md)
