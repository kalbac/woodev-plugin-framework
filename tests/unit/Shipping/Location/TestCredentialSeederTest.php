<?php
/**
 * Unit tests for the rig's DaData credential-seeding decision
 * (`tests/_fixtures/woodev-test-shipping-method/class-test-credential-seeder.php`).
 *
 * Only `Woodev_Test_Credential_Seeder::should_seed()` is exercised here — it is the
 * pure half of the seeder, deliberately split out (see that class's own docblock) so
 * this decision is testable without mocking `get_option()`/`update_option()`. The
 * impure half ({@see \Woodev_Test_Credential_Seeder::maybe_seed()}) is thin WordPress
 * glue over this same decision and is exercised on the rig / an integration run
 * instead.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/tests/_fixtures/woodev-test-shipping-method/class-test-credential-seeder.php';

/**
 * @covers \Woodev_Test_Credential_Seeder
 */
final class TestCredentialSeederTest extends TestCase {

	/**
	 * A defined, non-empty constant and an empty (unset) option must seed.
	 */
	public function test_seeds_when_constant_is_set_and_option_is_empty(): void {
		$this->assertTrue( \Woodev_Test_Credential_Seeder::should_seed( 'real-token-value', '' ) );
	}

	/**
	 * An undefined/empty constant must never seed, regardless of the option's state —
	 * there is nothing to write.
	 */
	public function test_never_seeds_when_the_constant_is_empty(): void {
		$this->assertFalse( \Woodev_Test_Credential_Seeder::should_seed( '', '' ) );
		$this->assertFalse( \Woodev_Test_Credential_Seeder::should_seed( '', 'already-set-value' ) );
	}

	/**
	 * An operator-provided (or previously seeded) option value must NEVER be
	 * overwritten, even when the constant carries a different value — the
	 * idempotent/non-destructive half of the contract.
	 */
	public function test_never_overwrites_an_existing_option_value(): void {
		$this->assertFalse( \Woodev_Test_Credential_Seeder::should_seed( 'rig-constant-value', 'operator-typed-value' ) );
	}

	/**
	 * Seeding the exact same value the option already holds is still a no-op — the
	 * rule is "option is empty", not "option differs from the constant".
	 */
	public function test_never_reseeds_when_the_option_already_equals_the_constant(): void {
		$this->assertFalse( \Woodev_Test_Credential_Seeder::should_seed( 'same-value', 'same-value' ) );
	}
}
