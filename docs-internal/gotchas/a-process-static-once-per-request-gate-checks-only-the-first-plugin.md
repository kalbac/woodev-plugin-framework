# A process-static "once per request" gate checks only the FIRST plugin, not each one

**Namespace:** `[shipping/checkout]` · **Measured:** s113 (02.09.2026), card #736 / PR #740, while
fixing the pickup-declaration reconciliation the rig's second carrier had just falsified.

## The trap

A class that each plugin instantiates its own copy of guarded a self-check with a single
process-wide bool:

```php
private static bool $pickup_declarations_reconciled = false;
```

Its docblock even explained the intent, and the intent was reasonable:

> Process-static, not per-instance: several plugins may each build their own `Checkout_Handler`,
> and the point of the gate is "at most once per request", not "at most once per handler".

That reasoning is right about the LOGGING and wrong about the CHECK. "Once per request" and "once
per handler" are only the same thing while exactly one handler exists. With two carrier plugins the
first `Checkout_Handler` built in the request flips the bool, and **every other plugin's
declarations are never examined at all** — silently, for the rest of the request.

## Why it survives review and a green suite

- The unit suite builds one handler per test, so the gate is always unset when it matters.
- On a one-plugin shop the behaviour is indistinguishable from correct.
- The symptom is the ABSENCE of a check, not a failure — nothing is logged, nothing goes red.
  Reviewers see a guard that reads exactly as intended and move on.

It was caught only because the same card was already fixing a different defect in the same method,
and the rig's notice text proved it empirically: it named the realistic carrier's lists and had
plainly never looked at the test carrier's.

## ❌ Wrong

```php
private static bool $reconciled = false;

if ( self::$reconciled ) { return; }
self::$reconciled = true;
```

## ✅ Correct — key the gate by the identity the class is per-instance OF

```php
/** @var array<string,bool> keyed by plugin id */
private static array $reconciled = [];

if ( isset( self::$reconciled[ $this->plugin_id() ] ) ) { return; }
self::$reconciled[ $this->plugin_id() ] = true;
```

Reset it wherever the class's other per-plugin static registry is reset, or the unit suite
inherits state across tests. In this file that is `reset_native_field_registry()`, beside
`$native_field_registry` — which was **already** keyed by `plugin_id` and sitting ten lines below
the broken bool. The correct shape was in the same file the whole time.

## The general rule

Before writing `private static` in a framework class, ask what the class is one-of. If plugins each
build their own, a bare static is a FLEET-wide singleton and any "have I done this yet" flag on it
answers for the fleet, not for the caller. Either key it by that identity, or make it non-static.

## Related

- [the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other.md](the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other.md) — the other way a checkout rule ends up half-applied
- [standing-up-a-second-carrier-plugin-has-three-traps-a-green-unit-suite-cannot-see.md](standing-up-a-second-carrier-plugin-has-three-traps-a-green-unit-suite-cannot-see.md) — the second carrier that made this observable
- [the-rig-runs-the-live-yandex-point-source-so-a-fixture-change-may-never-reach-it.md](the-rig-runs-the-live-yandex-point-source-so-a-fixture-change-may-never-reach-it.md) — why the rig needed a second carrier at all
