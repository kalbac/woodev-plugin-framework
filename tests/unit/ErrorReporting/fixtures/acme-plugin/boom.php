<?php
/**
 * Fixture "plugin" code that fails in different ways (#130 subprocess tests). Never loaded by
 * PHPUnit itself — only by runner.php in a child process.
 */

namespace Acme_Fixture;

class Boom {

	public function explode( $secret ) {
		throw new \RuntimeException( "Иван Иванов +7 (999) 123-45-67, ул. Ленина 10 token={$secret}" );
	}

	public function start() {
		$this->explode( 'hunter2-secret' );
	}

	public function anonymous() {
		throw new class( 'anon secret text' ) extends \RuntimeException {};
	}

	public function exhaust_memory() {
		ini_set( 'memory_limit', '8M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Blacklisted -- fixture.

		return str_repeat( 'x', 64 * 1024 * 1024 );
	}

	/**
	 * The realistic out-of-memory: many small allocations until the heap is full — unlike the one
	 * impossible allocation above, nothing is left over for whoever runs at shutdown.
	 */
	public function fill_heap() {
		ini_set( 'memory_limit', '8M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Blacklisted -- fixture.

		// Kept in a global: a local would be gone by the time the shutdown handler runs.
		$GLOBALS['woodev_fixture_hog'] = [];

		// Fill the heap until under 16 KB are left, then ask for more than that.
		while ( 8 * 1024 * 1024 - memory_get_usage() > 16 * 1024 ) {
			$GLOBALS['woodev_fixture_hog'][] = str_repeat( 'x', 512 );
		}

		return str_repeat( 'x', 64 * 1024 );
	}
}
