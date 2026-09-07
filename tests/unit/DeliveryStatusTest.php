<?php
/**
 * Unit: Delivery_Status — the canonical delivery-status enum (SP-10 increment 2a, spec D4).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Order\Delivery_Status;

class DeliveryStatusTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	public function test_canonical_states_lists_exactly_the_nine_s32_states(): void {
		$this->assertSame(
			[
				'pending',
				'created',
				'in_transit',
				'ready_for_pickup',
				'delivered',
				'returning',
				'returned',
				'failed',
				'cancelled',
			],
			Delivery_Status::canonical_states()
		);
	}

	public function test_canonical_states_excludes_unknown(): void {
		$this->assertNotContains( Delivery_Status::UNKNOWN, Delivery_Status::canonical_states() );
	}

	/**
	 * Every canonical state (and unknown) has a non-empty label.
	 */
	public function test_every_state_has_a_label(): void {
		foreach ( array_merge( Delivery_Status::canonical_states(), [ Delivery_Status::UNKNOWN ] ) as $state ) {
			$this->assertNotSame( '', Delivery_Status::label( $state ), "state \"$state\" must have a label" );
		}
	}

	public function test_label_falls_back_to_unknown_for_an_unrecognized_state(): void {
		$this->assertSame( Delivery_Status::label( Delivery_Status::UNKNOWN ), Delivery_Status::label( 'not-a-real-state' ) );
	}

	// ----- resolve() -----

	public function test_resolve_maps_a_known_raw_status_to_its_canonical_state(): void {
		$result = Delivery_Status::resolve( 'CDEK_ACCEPTED', [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ] );

		$this->assertSame( Delivery_Status::IN_TRANSIT, $result['canonical'] );
		$this->assertSame( Delivery_Status::label( Delivery_Status::IN_TRANSIT ), $result['canonical_label'] );
	}

	/**
	 * The single most important behaviour in this increment: an unmapped raw status
	 * resolves to a DISTINCT unknown, never a guessed canonical state.
	 */
	public function test_an_unmapped_raw_status_resolves_to_unknown_not_a_guess(): void {
		$result = Delivery_Status::resolve( 'SOME_NEW_STATUS_THE_MAP_DOES_NOT_KNOW', [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ] );

		$this->assertSame( Delivery_Status::UNKNOWN, $result['canonical'] );
	}

	/**
	 * A `status_map` entry that names something other than one of the nine canonical
	 * states (a typo, or a stray literal) must ALSO fail to `unknown` — the framework
	 * never trusts an arbitrary string as a canonical state.
	 */
	public function test_a_status_map_entry_naming_an_invalid_state_resolves_to_unknown(): void {
		$result = Delivery_Status::resolve( 'CDEK_WEIRD', [ 'CDEK_WEIRD' => 'not_a_real_canonical_state' ] );

		$this->assertSame( Delivery_Status::UNKNOWN, $result['canonical'] );
	}

	public function test_a_null_raw_status_resolves_to_unknown_with_null_raw(): void {
		$result = Delivery_Status::resolve( null, [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ] );

		$this->assertSame( Delivery_Status::UNKNOWN, $result['canonical'] );
		$this->assertNull( $result['raw'] );
		$this->assertNull( $result['raw_label'] );
	}

	public function test_an_empty_string_raw_status_is_treated_as_absent(): void {
		$result = Delivery_Status::resolve( '', [] );

		$this->assertSame( Delivery_Status::UNKNOWN, $result['canonical'] );
		$this->assertNull( $result['raw'] );
	}

	/**
	 * The raw carrier value and its own label must survive even when the mapping is
	 * incomplete — nothing is lost while a carrier's status_map is a work in progress.
	 */
	public function test_raw_value_and_label_survive_when_unmapped(): void {
		$result = Delivery_Status::resolve( 'CDEK_MYSTERY', [], [ 'CDEK_MYSTERY' => 'Загадочный статус' ] );

		$this->assertSame( Delivery_Status::UNKNOWN, $result['canonical'] );
		$this->assertSame( 'CDEK_MYSTERY', $result['raw'] );
		$this->assertSame( 'Загадочный статус', $result['raw_label'] );
	}

	/**
	 * Raw survival also holds for a MAPPED status — raw/raw_label are independent of
	 * whether the canonical side resolved.
	 */
	public function test_raw_value_and_label_survive_when_mapped(): void {
		$result = Delivery_Status::resolve(
			'CDEK_ACCEPTED',
			[ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ],
			[ 'CDEK_ACCEPTED' => 'Принят курьером' ]
		);

		$this->assertSame( 'CDEK_ACCEPTED', $result['raw'] );
		$this->assertSame( 'Принят курьером', $result['raw_label'] );
	}

	/**
	 * A raw status absent from `status_labels` falls back to showing the raw value
	 * itself, per D4/increment-2a wording ("absent means show the raw value").
	 */
	public function test_raw_label_falls_back_to_the_raw_value_when_no_label_declared(): void {
		$result = Delivery_Status::resolve( 'CDEK_ACCEPTED', [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ] );

		$this->assertSame( 'CDEK_ACCEPTED', $result['raw_label'] );
	}

	public function test_resolve_returns_the_exact_four_key_shape(): void {
		$result = Delivery_Status::resolve( 'X', [] );

		$this->assertSame( [ 'canonical', 'canonical_label', 'raw', 'raw_label' ], array_keys( $result ) );
	}
}
