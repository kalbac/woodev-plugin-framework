<?php
/**
 * The licence gate is real in the integration environment — #1270.
 *
 * The base integration TestCase seeds every fixture plugin with a signed, in-force licence claim
 * (see {@see \Woodev\Tests\Integration\Seeds_Signed_License_Claims}), because the gate would otherwise
 * close checkout for all of them. That seeding must not be a way of switching the gate off: this test
 * keeps the other half of the contract — the same fixture carrier yields NO rate when its signed claim
 * is gone, and a rate when it is there.
 *
 * @package Woodev\Tests\Integration\Shipping
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Tests\Integration\TestCase;

/**
 * @since 2.0.2
 */
class LicenseGateCheckoutTest extends TestCase {

	/**
	 * Any non-zero instance id works; the fixture method needs one for its instance settings.
	 *
	 * @var int
	 */
	private const INSTANCE_ID = 4243;

	/**
	 * @return void
	 */
	protected function tearDown(): void {

		delete_option( 'woocommerce_woodev_test_shipping_' . self::INSTANCE_ID . '_settings' );

		parent::tearDown();
	}

	/**
	 * With the seeded signed claim the fixture carrier offers its rate.
	 *
	 * @return void
	 */
	public function test_the_fixture_carrier_offers_a_rate_while_its_signed_licence_is_in_force(): void {

		$plugin = \woodev_test_shipping_method_plugin();

		$this->assertTrue( $plugin->get_license_instance()->is_license_in_force(), 'The base TestCase must have seeded a signed claim.' );

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );
		$method->calculate_shipping( [] );

		$this->assertCount( 1, $method->rates, 'An in-force licence must leave the fixture carrier its rate.' );
	}

	/**
	 * Without a signed claim the same carrier yields no rate: the gate is closed, not bypassed.
	 *
	 * @return void
	 */
	public function test_the_fixture_carrier_yields_no_rate_without_a_signed_licence_claim(): void {

		$plugin = \woodev_test_shipping_method_plugin();

		$this->remove_license_claim( $plugin );

		$this->assertFalse( $plugin->get_license_instance()->is_license_in_force(), 'No claim must mean the licence is not in force.' );

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );
		$method->calculate_shipping( [] );

		$this->assertCount( 0, $method->rates, 'A carrier with no verified signed claim must yield no rate.' );
	}

	/**
	 * The seeded claim opens the gate only because the test key verifies it: under any other authority
	 * key the very same stored claim is no claim.
	 *
	 * @return void
	 */
	public function test_the_seeded_claim_stops_counting_under_another_authority_key(): void {

		$plugin = \woodev_test_shipping_method_plugin();
		$other  = base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) );

		add_filter(
			'woodev_license_authority_pubkey',
			static function () use ( $other ): string {
				return $other;
			},
			20
		);

		// The memo from the seeding still holds the verified payload; drop it so the stored claim is read afresh.
		$this->forget_license_claims();

		$this->assertFalse( $plugin->get_license_instance()->is_license_in_force() );
	}
}
