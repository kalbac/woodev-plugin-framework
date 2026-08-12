# gotcha: `WP_UnitTestCase` restores the hook table after every test — an identity-based `reset_for_tests()` cannot remove what comes back

**Namespace:** `[testing/integration]`
**Discovered:** s70 (2026-08-12, PR #291 — the only red job on all three integration legs)

## The mechanism

`WP_UnitTestCase` snapshots `$wp_filter` **once**, in the `set_up()` of the first test of the whole
run (`if ( ! self::$hooks_saved ) { $this->_backup_hooks(); }`), and calls `_restore_hooks()` in the
`tear_down()` of **every** test. So the hook table each test starts from is not "whatever the last
test left" — it is that one snapshot, replayed.

Anything a plugin hooked at `plugins_loaded` time is inside that snapshot. It therefore **comes back
after every teardown**, no matter what the previous test removed.

Now put a singleton's test reset next to it:

```php
// ❌ removes hooks only from the instance this reset happens to hold
public function reset_for_tests(): void {
	if ( null !== self::$instance && self::$instance->hooked ) {
		remove_action( 'init', [ self::$instance, 'collect' ], 20 );
		remove_action( 'rest_api_init', [ self::$instance, 'register_rest' ] );
	}

	self::$instance = null;
}
```

`add_action()`/`remove_action()` match by **object identity**, not by class. The instance that
registered the surviving hook is the one built at `plugins_loaded`; by the time the next `setUp()`
runs, `self::$instance` was nulled by the previous teardown, so `instance()` hands back a **brand-new,
unhooked object**. The guard is false, nothing is removed, and the restored hook fires on the next
`do_action()`.

## What it looked like

`LocationRouteTest::test_routes_are_absent_when_no_plugin_declared_need` — a test whose entire point
is that the layer registers nothing when nobody declared need:

```
Failed asserting that an array does not have the key '/woodev/v1/location/suggest'.
```

Its sibling assertion, `assertFalse( has_action( 'init', [ instance(), 'collect' ] ) )`, **passed** —
because it also compares by identity, against the same fresh object. Two assertions, same blind spot,
one of them reassuring.

The probe that settled it (measured, not reasoned — dump the hook table instead of arguing about it):

```php
fwrite( STDERR, 'registry spl=' . spl_object_id( Location_Provider_Registry::instance() ) . "\n" );
foreach ( $GLOBALS['wp_filter']['rest_api_init']->callbacks as $prio => $cbs ) {
	foreach ( $cbs as $cb ) {
		if ( is_array( $cb['function'] ) && is_object( $cb['function'][0] ) ) {
			fwrite( STDERR, get_class( $cb['function'][0] ) . '::' . $cb['function'][1]
				. ' spl=' . spl_object_id( $cb['function'][0] ) . "\n" );
		}
	}
}
```

```
PROBE registry spl=2881
PROBE rest_api_init: …\Location_Provider_Registry::register_rest spl=1555
```

Two different objects of the same class. That is the whole bug on one line.

## The fix — match by class + method, not by instance

```php
// ✅ instance identity no longer decides
private static function remove_hooked_instances( string $hook, string $class, string $method ): void {
	$hooks = $GLOBALS['wp_filter'] ?? [];

	if ( ! isset( $hooks[ $hook ] ) || ! is_object( $hooks[ $hook ] ) || ! isset( $hooks[ $hook ]->callbacks ) ) {
		return; // Brain Monkey unit runs have no hook table — nothing to scrub.
	}

	foreach ( $hooks[ $hook ]->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'] ?? null;

			if ( is_array( $function ) && 2 === count( $function )
				&& $function[0] instanceof $class && $method === $function[1] ) {
				remove_action( $hook, $function, $priority );
			}
		}
	}
}
```

Iterating `WP_Hook::$callbacks` by value is safe against the `remove_action()` inside the loop —
`foreach` walks a copy, the mutation lands on the live table.

A bonus falls out: a callback bound to an object **never stored anywhere**
(`add_action( 'wp_login', [ new Customer_Location_Store(), 'handle_wp_login' ], 10, 2 )`) is
unreachable by identity by construction. Class+method matching is the only thing that can remove it,
so a limitation the old docblock declared unfixable simply stopped existing.

## Why it stayed hidden until s69

`declare_needed()` had only ever been called from *inside* a test, after that test's own reset — so
the registering instance and the resetting instance were always the same object. The trap armed
itself the moment a **fixture plugin started declaring need at `plugins_loaded`**, exactly as a real
shipping plugin does (`tests/_fixtures/woodev-test-shipping-method`, rig pull-forward for PR-C).
Making a fixture more realistic moved it into the pre-snapshot world, and the reset silently stopped
working.

Ordering matters too: run the file **alone** and the test passed; run the full 103-test suite and it
failed. A test whose verdict depends on what ran before it is not proof either way — reproduce
against the suite CI actually runs before believing a green.

## Related

- [[integration-test-global-admin-hooks-output-and-submenu-accumulation]] — the other
  `WP_UnitTestCase` state-leak family: globals that accumulate rather than get restored.
- [[built-on-both-sides-with-no-caller-in-the-middle]] — the `wp_login` callback this reset can now
  remove is the same one that spent PR-A with zero callers.
