# A fixture class that implements a framework interface must be declared inside the plugin's init callback

**Namespace:** `[testing/integration]` · **Discovered:** s46 (2026-07-31), wiring the SP-5 pickup fixture

## The trap

Declaring a class at the top level of a test-fixture plugin file looks harmless:

```php
// tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php

class Woodev_Test_Bulk_Point_Source implements \Woodev\Framework\Shipping\Pickup\Point_Source {
	// …
}

function woodev_test_shipping_method_plugin_init() { /* … */ }
add_action( 'plugins_loaded', 'woodev_test_shipping_method_plugin_init', 20 );
```

It fatals:

```
Interface "Woodev\Framework\Shipping\Pickup\Point_Source" not found
```

**Top-level code in a plugin file runs the moment WordPress `require`s it** — during
`plugins_loaded`'s *file-loading* phase, before `Woodev_Plugin_Bootstrap` has sorted the
registered plugins, selected the highest framework version, and registered
`Woodev_Framework_Autoloader`. At that instant no framework class or interface is resolvable at
all, so `implements` on any `Woodev\Framework\*` symbol cannot be satisfied.

This has nothing to do with the interface being missing, mis-namespaced, or absent from
`class-map.php` — all the things the error message invites you to check first. The symbol is
fine; the *clock* is wrong.

## The fix

Declare such classes inside the plugin's own init callback, which is exactly why the main
fixture plugin class already lives there:

```php
function woodev_test_shipping_method_plugin_init() {
	class Woodev_Test_Bulk_Point_Source implements \Woodev\Framework\Shipping\Pickup\Point_Source {
		// … resolvable here: the autoloader is registered by now
	}

	// … construct and register the plugin
}
```

## Rule of thumb

In a vendored-framework plugin — fixture or real — **anything that names a `Woodev\Framework\*`
symbol at class-definition time belongs after the bootstrap has run.** `implements`, `extends`,
and typed constant/property defaults are all resolved when the class is *declared*, not when it
is instantiated, so moving the `new` later does not help. Only moving the `class` keyword does.

Note this is the mirror image of [[framework-classmap-autoload-vendored-boot]]: there the class
map was incomplete, here it is complete but not yet installed. Both surface as "class not found"
on a real vendored boot and are invisible under Composer's autoloader in unit tests.

## Related

- [[framework-classmap-autoload-vendored-boot]] — the other half: a class missing from the generated map
- [[classmap-autoload-breaks-class-exists-once-guard]] — another way the runtime autoloader defeats an assumption
- [[wpenv-resolver-fixture-mapping]] — how fixtures get their bundled framework copy in the first place
