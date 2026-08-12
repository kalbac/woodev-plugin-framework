<?php
/**
 * Unit tests for the rig's fixture Location_Adapter
 * (`tests/_fixtures/woodev-test-shipping-method/class-test-location-adapter.php`).
 *
 * `Woodev_Test_Location_Adapter` lives in its own file (same reasoning as
 * `class-test-bulk-point-source.php`'s own docblock), so it can be `require_once`d
 * directly here, after the interface/value object it depends on, WITHOUT going
 * through the fixture plugin's full Platform v2 load path.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-adapter.php';
require_once dirname( __DIR__, 4 ) . '/tests/_fixtures/woodev-test-shipping-method/class-test-location-adapter.php';

/**
 * @covers \Woodev_Test_Location_Adapter
 */
final class TestLocationAdapterTest extends TestCase {

	/**
	 * Builds a minimal, valid settlement-level Location_Record.
	 *
	 * @param string      $key             namespaced record key, e.g. 'dadata:123'.
	 * @param string|null $settlement_name settlement component name, or null to omit it.
	 *
	 * @return Location_Record
	 */
	private function make_record( string $key, ?string $settlement_name ): Location_Record {
		$data = [
			'key'         => $key,
			'provider_id' => 'dadata',
			'level'       => Location_Record::LEVEL_SETTLEMENT,
			'country'     => 'RU',
		];

		if ( null !== $settlement_name ) {
			$data['settlement'] = [ 'name' => $settlement_name, 'type' => 'г' ];
		}

		return Location_Record::from_array( $data );
	}

	/**
	 * A record for any settlement other than the fixture's own "unserved" town must
	 * resolve to a deterministic, non-null identity derived from the record's key.
	 */
	public function test_resolve_returns_a_deterministic_identity_derived_from_the_record(): void {
		$adapter = new \Woodev_Test_Location_Adapter();
		$record  = $this->make_record( 'dadata:77-moscow', 'Москва' );

		$identity = $adapter->resolve( $record );

		$this->assertSame( 'fixture-carrier:dadata:77-moscow', $identity );
	}

	/**
	 * Resolving is a pure function of the record — the same record must always resolve
	 * to the same identity (no hidden state, no randomness).
	 */
	public function test_resolve_is_deterministic_across_repeated_calls(): void {
		$adapter = new \Woodev_Test_Location_Adapter();
		$record  = $this->make_record( 'dadata:78-spb', 'Санкт-Петербург' );

		$this->assertSame( $adapter->resolve( $record ), $adapter->resolve( $record ) );
	}

	/**
	 * Two different records must resolve to two different identities — the identity is
	 * genuinely DERIVED from the record, not a constant stand-in.
	 */
	public function test_resolve_differs_for_different_records(): void {
		$adapter = new \Woodev_Test_Location_Adapter();

		$moscow = $adapter->resolve( $this->make_record( 'dadata:77-moscow', 'Москва' ) );
		$spb    = $adapter->resolve( $this->make_record( 'dadata:78-spb', 'Санкт-Петербург' ) );

		$this->assertNotSame( $moscow, $spb );
	}

	/**
	 * The fixture's declared "does not serve" settlement must resolve to `null` — the
	 * interface's own first-class "this carrier does not serve this locality" answer.
	 */
	public function test_resolve_returns_null_for_the_declared_unserved_settlement(): void {
		$adapter = new \Woodev_Test_Location_Adapter();
		$record  = $this->make_record( 'dadata:34-uryupinsk', \Woodev_Test_Location_Adapter::UNSERVED_SETTLEMENT );

		$this->assertNull( $adapter->resolve( $record ) );
	}

	/**
	 * A record with no settlement component at all (e.g. a region-level record) must not
	 * be mistaken for the unserved settlement — absence is not a match.
	 */
	public function test_resolve_does_not_treat_a_missing_settlement_as_unserved(): void {
		$adapter = new \Woodev_Test_Location_Adapter();
		$record  = $this->make_record( 'dadata:77', null );

		$this->assertNotNull( $adapter->resolve( $record ) );
	}
}
