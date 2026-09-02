<?php
/**
 * Tests for the realistic shipping fixture's own pickup Point_Source (card #734).
 *
 * The source is deliberately loadable on its own — like `Woodev_Test_Bulk_Point_Source`, and
 * for the same reason its docblock gives: going through the fixture plugin's full Platform v2
 * load path makes the test order-dependent, because the resolver is a process-wide singleton
 * with a one-shot load latch.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-point-source.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';
require_once dirname( __DIR__, 4 ) . '/tests/_fixtures/woodev-realistic-shipping-plugin/includes/class-realistic-point-source.php';

/**
 * Class RealisticPointSourceTest
 */
final class RealisticPointSourceTest extends TestCase {

	/**
	 * Builds a bulk query for a bare locality name.
	 *
	 * @param string $locality Locality name.
	 *
	 * @return \Woodev\Framework\Shipping\Pickup\Point_Query
	 */
	private function query_for( string $locality ): \Woodev\Framework\Shipping\Pickup\Point_Query {

		$query = \Woodev\Framework\Shipping\Pickup\Point_Query::from_request( [ 'locality' => $locality ] );

		$this->assertNotNull( $query, 'the fixture query must be constructible for ' . $locality );

		return $query;
	}

	/**
	 * The source must be a BULK source — the whole locality in one call.
	 */
	public function test_it_declares_the_bulk_strategy(): void {

		$source = new \Woodev_Realistic_Point_Source();

		$this->assertSame(
			\Woodev\Framework\Shipping\Pickup\Point_Source::STRATEGY_BULK,
			$source->get_strategy()
		);
	}

	/**
	 * Moscow carries several points, and every one of them survives `from_array()`.
	 *
	 * ⚠ This assertion is the one that catches a malformed payload: `Pickup_Point::from_array()`
	 * returns null — silently — for a point missing `lng` or carrying a scalar `type`, so a
	 * broken fixture yields an EMPTY list rather than an error. Counting is what makes that
	 * visible.
	 */
	public function test_moscow_returns_every_one_of_its_points(): void {

		$points = ( new \Woodev_Realistic_Point_Source() )->fetch_points( $this->query_for( 'Москва' ) );

		$this->assertCount( 3, $points );

		foreach ( $points as $point ) {
			$this->assertInstanceOf( \Woodev\Framework\Shipping\Pickup\Pickup_Point::class, $point );
		}
	}

	/**
	 * Краснодар carries EXACTLY ONE point, and that is the whole reason it exists.
	 *
	 * Card #150 needs a city whose bounds degenerate to a single coordinate. Before this
	 * fixture no data set in the repository could produce that shape, so a second Krasnodar
	 * point would silently remove the only reproduction available — this test is what stops
	 * someone "tidying" it.
	 */
	public function test_krasnodar_carries_exactly_one_point_for_issue_150(): void {

		$points = ( new \Woodev_Realistic_Point_Source() )->fetch_points( $this->query_for( 'Краснодар' ) );

		$this->assertCount(
			1,
			$points,
			'Краснодар must keep exactly one point — it is the only single-point-city fixture (#150)'
		);
		$this->assertSame( 'REAL-KRD-1', $points[0]->get_id() );
	}

	/**
	 * A locality this fixture does not serve yields an empty list, not everything.
	 */
	public function test_an_unknown_locality_returns_no_points(): void {

		$points = ( new \Woodev_Realistic_Point_Source() )->fetch_points( $this->query_for( 'Урюпинск' ) );

		$this->assertSame( [], $points );
	}

	/**
	 * The English spellings resolve, because this rig's DaData account answers in English.
	 */
	public function test_english_aliases_resolve_to_the_same_points(): void {

		$source = new \Woodev_Realistic_Point_Source();

		$this->assertCount( 3, $source->fetch_points( $this->query_for( 'Moscow' ) ) );
		$this->assertCount( 1, $source->fetch_points( $this->query_for( 'krasnodar' ) ) );
	}

	/**
	 * Matching is case- and whitespace-insensitive but otherwise exact — no partial matching.
	 */
	public function test_a_partial_locality_name_does_not_match(): void {

		$source = new \Woodev_Realistic_Point_Source();

		$this->assertSame( [], $source->fetch_points( $this->query_for( 'Моск' ) ) );
		$this->assertCount( 3, $source->fetch_points( $this->query_for( '  МОСКВА  ' ) ) );
	}

	/**
	 * A point is fetchable by its own id, and an unknown id answers null rather than throwing.
	 */
	public function test_fetch_details_resolves_a_known_id_and_refuses_an_unknown_one(): void {

		$source = new \Woodev_Realistic_Point_Source();

		$point = $source->fetch_details( 'REAL-KRD-1' );

		$this->assertInstanceOf( \Woodev\Framework\Shipping\Pickup\Pickup_Point::class, $point );
		$this->assertSame( 'REAL-KRD-1', $point->get_id() );

		$this->assertNull( $source->fetch_details( 'NO-SUCH-POINT' ) );
	}
}
