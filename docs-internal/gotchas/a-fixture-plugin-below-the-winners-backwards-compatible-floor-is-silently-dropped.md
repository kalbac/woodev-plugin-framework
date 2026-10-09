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

## The floor moves with the winner — the reverse trap (s163)

The floor belongs to whoever WINS, so changing the winner changes who is dropped. `3eb5bad8` (#1186)
raised `woodev-test-plugin` to `2.0.2`; it now outranks CDEK (`2.0.1`) and declares no
`backwards_compatible`, so **nothing** is dropped any more (rig: `registered 4, incompatible 0`) and
the `1.4.0` `woodev-test-shipping-method` boots next to CDEK. Both declare the same Location-Provider
levels (`source_location` region/settlement/address), which `guard_native_field_conflicts()` used to
report as a conflict on every page — `WP_DEBUG_DISPLAY` printed it before headers, wp-login set no
cookie, `npm run test:e2e` failed 10/11.

That was a FRAMEWORK defect, not a fixture artefact: several carriers sharing the location layer is the
supported case (v2 release, #1179). The guard now compares claim signatures and stays silent only when the
overlapping plugins declare the IDENTICAL set of location levels (e.g. both region + settlement + address).
Equal levels on the overlapping ids are NOT enough: A (region + settlement + address) with B (region +
settlement) keeps one cascade record store per plugin in the classic checkout, so after a city pick the
address A owns stays disabled (jsdom probe, s163 critic) — that differing set stays reported until the
cascade is shared (separate card). A direct takeover against a location field, two direct takeovers and two
different levels on one id are reported too.

## Fix

❌ "bump the fixture to 2.0.0 and it works" (s161: it printed the notice into the page). ✅ Diagnose with
the call above; a NEW fixture declares `2.0.0` from the start. Since s163 two carriers declaring the
identical set of location levels no longer trip the guard, so a fixture MAY share the full location set with an
active carrier — it still must not take a native id over directly.

## Related

- [rig-serves-the-working-tree-branch-switch-reverts-fixes](rig-serves-the-working-tree-branch-switch-reverts-fixes.md)
- [../wiki/local-rig.md](../wiki/local-rig.md)
