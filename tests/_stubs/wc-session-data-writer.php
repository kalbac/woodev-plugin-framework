<?php
/**
 * An empty stand-in for WooCommerce 11.4's `SessionDataWriter` (woocommerce#69559) — the
 * class whose PRESENCE tells `Session_Key_Merger` that WooCommerce merges session rows per key
 * itself. Only ever required from a `@runInSeparateProcess` test: a class declared in the shared
 * PHPUnit process cannot be undeclared, and every other merger test needs it ABSENT.
 *
 * @package Woodev\Tests\Unit
 */

namespace Automattic\WooCommerce\Internal\Session {

	if ( ! class_exists( SessionDataWriter::class, false ) ) {

		class SessionDataWriter {}
	}
}
