# Gotcha: [rig/loader] — a fixture plugin whose `framework_version` is below the winning plugin's `backwards_compatible` floor is silently dropped: it shows "active", registers nothing

> Tags: rig, loader, fixtures, e2e | Session: s161 (#1173)

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

Declare `'framework_version' => '2.0.0'` in the fixture's loader definition (#1173 did it for
`woodev-test-shipping-method`). The same applies to any new fixture.
