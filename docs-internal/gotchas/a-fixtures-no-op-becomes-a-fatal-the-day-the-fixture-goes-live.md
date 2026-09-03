# A fixture's `init_*()` no-op becomes a fatal the day that fixture goes live

**Namespace:** `[rig/fixtures]` · **Measured:** s114 (04.09.2026), issue #758 — every admin page on the rig was white-screening.

## What happened

`woodev-realistic-shipping-plugin` carried this since 31.05.2026, when it was a PHPUnit-only fixture:

```php
/** No-op admin notice handler for isolated fixture construction. */
protected function init_admin_notice_handler() {}
```

In **s112** that same fixture was promoted to a LIVE second carrier on the rig (#734/#735). Nothing
about the no-op changed — but it now had a caller:

```
Call to a member function add_admin_notice() on null
  woodev/shipping-method/class-shipping-plugin.php:606
#1 Shipping_Plugin->add_delayed_admin_notices('')      ← hooked on admin_footer
#4 wp-admin/admin-footer.php(78): do_action('admin_footer')
```

Every admin page, for every plugin on the site — not just this one.

## The two facts that make it fire, both measurable

1. **`is_debug_enabled()` is ALWAYS true on the rig.** `Shipping_Plugin::is_debug_enabled()` falls
   back to `WP_DEBUG` when no `debug_mode` integration option is set, and the rig runs with
   `WP_DEBUG = true`. So the notice branch runs on every admin page — it is not gated behind
   "someone switched debug on".
2. **`get_admin_notice_handler()` is dereferenced 17 times across `woodev/` with no null check**, and
   `init_admin_notice_handler()` is called unconditionally from `Woodev_Plugin::__construct()`. So
   "no notice handler" was never a supported state — a subclass no-op is a broken contract, not an
   opt-out.

## ✅ How to check, and what the fix shape is

```bash
# does the framework treat this subsystem as optional, or just never check?
grep -rn "get_<subsystem>()->" --include=*.php woodev/ | wc -l
# which init_*() does a fixture stub out?
grep -n "protected function init_[a-z_]*() {}" tests/_fixtures/<plugin>/**/*.php
```

A non-zero first number plus a hit in the second is a latent fatal waiting for a caller.

**Fix in ONE seam, not N guards.** Adding 17 null checks spreads the contract around; instead
enforce it where it is established — right after `init_*()` in the constructor: report the violation
via `_doing_it_wrong()` under `WP_DEBUG` (the #709/#736/#746 shape) **and build the default anyway**,
so existing call sites stay valid. Report-and-recover is deliberate: a `throw` on `admin_footer` IS
the failure being fixed, and silence is what let the no-op sit latent for three months.

## Measured at the same time — the same shape, twice more

| subsystem | unguarded `->` in `woodev/` | no-opped by that fixture |
|---|---|---|
| `get_admin_notice_handler()` | 17 | was, fixed in #758 |
| `get_license_instance()` | **13** | **yes** |
| `get_lifecycle_handler()` | **2** | **yes** |
| `get_admin_message_handler()` | 0 | yes — harmless |

The last row is the control: a no-op is only dangerous where the framework dereferences without a
guard. Card for the remaining two: **#759**.

⚠ And the stated justification did not survive checking: **no unit test constructs that fixture
class at all** (grep over `tests/unit/`), while the sibling fixture never no-opped the handler and
works fine. "For isolated fixture construction" was true once and nobody re-checked it.

## Related

- [standing-up-a-second-carrier-plugin-has-three-traps-a-green-unit-suite-cannot-see](standing-up-a-second-carrier-plugin-has-three-traps-a-green-unit-suite-cannot-see.md) — the other things that broke when this fixture went live
- [a-process-static-once-per-request-gate-checks-only-the-first-plugin](a-process-static-once-per-request-gate-checks-only-the-first-plugin.md) — the same "report it loudly under WP_DEBUG" enforcement shape
- [../wiki/local-rig.md](../wiki/local-rig.md) — what the rig runs and why
