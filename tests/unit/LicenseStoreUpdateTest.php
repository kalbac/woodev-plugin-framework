<?php
/**
 * `Woodev_License::update()` corrupted-option guard.
 *
 * #785: the stored license option is an installed-site DATA contract
 * (AGENT-RULES.md Rule 0). `update()` used to assume it is always a
 * `stdClass` (per its own docblock) and dereferenced `$option->$key`
 * unconditionally — a PHP 8 `Error: Attempt to assign property on array`
 * when the row had somehow become an array, with no catch anywhere on the
 * call path, taking down the whole request.
 *
 * Operator decision (s119 brainstorm, recorded on #785): do NOT cast the
 * array to an object. Casting would silently legitimise the foreign shape
 * and overwrite whatever produced it, destroying the only evidence of how
 * it got there. Instead `update()` must write nothing, return `false`, and
 * report via `_doing_it_wrong()` so a human can inspect the row directly.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-store.php';

/**
 * Class LicenseStoreUpdateTest.
 */
class LicenseStoreUpdateTest extends TestCase {

	/**
	 * Builds a real `Woodev_License`, bypassing the constructor (which would
	 * otherwise dispatch a `get()` read of its own), with `option_name` seeded
	 * directly via reflection — same technique `LicensePureOperationsTest`
	 * already uses for this class.
	 *
	 * @return \Woodev_License
	 */
	private function make_store(): \Woodev_License {
		$store = ( new \ReflectionClass( \Woodev_License::class ) )->newInstanceWithoutConstructor();

		$option_name = new \ReflectionProperty( \Woodev_License::class, 'option_name' );
		if ( PHP_VERSION_ID < 80100 ) {
			$option_name->setAccessible( true );
		}
		$option_name->setValue( $store, 'woodev_test_plugin_license' );

		return $store;
	}

	/**
	 * A corrupted (array) option: `update()` must refuse to touch it, return
	 * `false`, report via `_doing_it_wrong()`, and never reach `save()` —
	 * i.e. `update_option()` is never called. No `Error` may escape, which is
	 * exactly what a green run of this test proves: PHP 8 would otherwise
	 * throw before `assertFalse()` is ever reached.
	 *
	 * @return void
	 */
	public function test_update_refuses_a_corrupted_array_option(): void {
		$store = $this->make_store();

		Functions\expect( 'get_option' )
			->once()
			->with( 'woodev_test_plugin_license', \Mockery::type( \stdClass::class ) )
			->andReturn( [ 'license' => 'valid' ] );

		Functions\expect( '_doing_it_wrong' )->once()->with(
			'Woodev_License::update',
			\Mockery::type( 'string' ),
			'2.0.2'
		);

		Functions\expect( 'update_option' )->never();
		Actions\expectDone( 'woodev_license_saved' )->never();

		$result = $store->update( [ 'license' => 'valid' ] );

		$this->assertFalse( $result );
	}

	/**
	 * Regression guard: a well-formed `stdClass` option is unaffected by the
	 * new guard — `update()` still edits it in place, persists via `save()`
	 * (observable as the `update_option()` write + the `woodev_license_saved`
	 * action), returns `true`, and never reports anything.
	 *
	 * @return void
	 */
	public function test_update_still_writes_a_well_formed_object_option(): void {
		$store  = $this->make_store();
		$option = (object) [ 'license' => 'invalid' ];

		// A single alias handles both get_option() reads on this path: update()'s
		// own read of the option, and the get_license_key() read save()->get()
		// makes afterwards (a different option name, falls through to $default
		// '' — empty, so get() early-returns before touching the option again).
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $option ) {
				return 'woodev_test_plugin_license' === $name ? $option : $default;
			}
		);

		Functions\expect( '_doing_it_wrong' )->never();

		Functions\expect( 'update_option' )
			->once()
			->with(
				'woodev_test_plugin_license',
				\Mockery::on(
					static function ( $saved ) {
						return $saved instanceof \stdClass && 'valid' === $saved->license;
					}
				),
				false
			)
			->andReturn( true );

		Actions\expectDone( 'woodev_license_saved' )->once();

		$result = $store->update( [ 'license' => 'valid' ] );

		$this->assertTrue( $result );
	}
}
