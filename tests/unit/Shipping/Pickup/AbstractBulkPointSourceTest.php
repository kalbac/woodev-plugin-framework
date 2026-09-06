<?php
/**
 * Unit tests for Abstract_Bulk_Point_Source (card #144) — the base class factoring the
 * `fetch_details()` shape shared by every `STRATEGY_BULK` fixture: `get_strategy()`
 * always `STRATEGY_BULK`, `fetch_details()` a scan-and-match over
 * {@see Abstract_Bulk_Point_Source::raw_bulk_points()} that normalizes only the
 * matched entry, and `null` on no match. Covers the default id-extraction and
 * normalization hooks, and the override seam a carrier with its own raw shape
 * ({@see Woodev_Test_Live_Yandex_Point_Source}) needs.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup {

	use Woodev\Framework\Shipping\Pickup\Abstract_Bulk_Point_Source;
	use Woodev\Framework\Shipping\Pickup\Pickup_Point;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-point-source.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/abstract-bulk-point-source.php';

	/**
	 * Minimal source using every default hook — raw entries already in
	 * {@see Pickup_Point::from_array()}'s own shape, `id` read straight off the top.
	 */
	final class Default_Hooks_Bulk_Source extends Abstract_Bulk_Point_Source {

		/** @var array<int, array<string, mixed>> */
		private array $points;

		/** @param array<int, array<string, mixed>> $points */
		public function __construct( array $points ) {
			$this->points = $points;
		}

		public function fetch_points( \Woodev\Framework\Shipping\Pickup\Point_Query $query ): array {
			return array_values( array_filter( array_map( [ Pickup_Point::class, 'from_array' ], $this->points ) ) );
		}

		protected function raw_bulk_points(): array {
			return $this->points;
		}
	}

	/**
	 * Source with a carrier-native raw shape — mirrors
	 * `Woodev_Test_Live_Yandex_Point_Source`: raw entries carry `id` but nothing else
	 * `Pickup_Point::from_array()` understands, so both hooks are overridden.
	 */
	final class Custom_Hooks_Bulk_Source extends Abstract_Bulk_Point_Source {

		/** @var array<int, array<string, mixed>> */
		private array $raw_records;

		/** @param array<int, array<string, mixed>> $raw_records */
		public function __construct( array $raw_records ) {
			$this->raw_records = $raw_records;
		}

		public function fetch_points( \Woodev\Framework\Shipping\Pickup\Point_Query $query ): array {
			return [];
		}

		protected function raw_bulk_points(): array {
			return $this->raw_records;
		}

		protected function bulk_point_id( $raw_point ): ?string {
			return is_array( $raw_point ) && isset( $raw_point['carrier_id'] )
				? (string) $raw_point['carrier_id']
				: null;
		}

		protected function normalize_bulk_point( $raw_point ): ?Pickup_Point {
			return Pickup_Point::from_array(
				[
					'id'      => $raw_point['carrier_id'],
					'name'    => $raw_point['title'],
					'lat'     => $raw_point['coords']['lat'],
					'lng'     => $raw_point['coords']['lng'],
					'address' => $raw_point['address'],
					'type'    => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи' ],
				]
			);
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Pickup\Abstract_Bulk_Point_Source
	 */
	final class AbstractBulkPointSourceTest extends TestCase {

		private function valid_payload( string $id ): array {
			return [
				'id'      => $id,
				'name'    => 'Точка ' . $id,
				'lat'     => 55.75,
				'lng'     => 37.61,
				'address' => 'Москва, ул. Тестовая, 1',
				'type'    => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи' ],
			];
		}

		public function test_get_strategy_is_always_bulk(): void {
			$source = new Default_Hooks_Bulk_Source( [] );

			$this->assertSame( Abstract_Bulk_Point_Source::STRATEGY_BULK, $source->get_strategy() );
		}

		public function test_fetch_details_found_normalizes_only_the_matched_entry(): void {
			$source = new Default_Hooks_Bulk_Source(
				[ $this->valid_payload( 'A-1' ), $this->valid_payload( 'A-2' ) ]
			);

			$point = $source->fetch_details( 'A-2' );

			$this->assertInstanceOf( Pickup_Point::class, $point );
			$this->assertSame( 'A-2', $point->get_id() );
		}

		public function test_fetch_details_not_found_returns_null(): void {
			$source = new Default_Hooks_Bulk_Source( [ $this->valid_payload( 'A-1' ) ] );

			$this->assertNull( $source->fetch_details( 'does-not-exist' ) );
		}

		public function test_fetch_details_on_empty_universe_returns_null(): void {
			$source = new Default_Hooks_Bulk_Source( [] );

			$this->assertNull( $source->fetch_details( 'anything' ) );
		}

		/**
		 * A raw entry that fails {@see Pickup_Point::from_array()} validation (issue
		 * #144's divergence seam: a matched id whose payload turns out malformed) must
		 * surface as null, not a fatal — the same "one bad point must not break the
		 * lookup" discipline the interface already requires of fetch_points().
		 */
		public function test_fetch_details_found_but_malformed_returns_null(): void {
			$source = new Default_Hooks_Bulk_Source(
				[ [ 'id' => 'A-1' ] ] // missing every other required field.
			);

			$this->assertNull( $source->fetch_details( 'A-1' ) );
		}

		/**
		 * The divergence seam this card exists to leave: a carrier whose raw payload is
		 * its own shape, not the framework's normalized array — both hooks overridden,
		 * mirroring `Woodev_Test_Live_Yandex_Point_Source`.
		 */
		public function test_fetch_details_with_overridden_id_and_normalize_hooks(): void {
			$source = new Custom_Hooks_Bulk_Source(
				[
					[
						'carrier_id' => 'YND-9',
						'title'      => 'Пятёрочка',
						'coords'     => [ 'lat' => 55.7, 'lng' => 37.6 ],
						'address'    => 'Москва, ул. Арбат, 1',
					],
				]
			);

			$point = $source->fetch_details( 'YND-9' );

			$this->assertInstanceOf( Pickup_Point::class, $point );
			$this->assertSame( 'YND-9', $point->get_id() );
			$this->assertSame( 'Пятёрочка', $point->to_array()['name'] );
			$this->assertNull( $source->fetch_details( 'not-this-one' ) );
		}
	}
}
