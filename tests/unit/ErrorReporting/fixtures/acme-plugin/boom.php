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
}
