<?php
/**
 * Unit: every raw status the demo seeder writes is one this carrier actually speaks.
 *
 * The first draft of the seeder's bulk list was typed from memory and invented
 * three statuses (`DELIVERED`, `RETURNING`, `CANCELED`) that
 * `woodev-test-shipping-method.php` never registers. Nothing would have failed:
 * the orders seed fine, and the page renders the bare key instead of a label —
 * a rig that looks populated and quietly lies about what the carrier said.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

// Both fixtures' seeder classes are dependency-free: each file declares one class
// and nothing else, so requiring them costs nothing and needs no WordPress.
require_once dirname( __DIR__, 2 ) . '/tests/_fixtures/woodev-test-shipping-method/class-test-orders-seeder.php';
require_once dirname( __DIR__, 2 ) . '/tests/_fixtures/woodev-realistic-shipping-plugin/includes/class-realistic-orders-seeder.php';

class TestOrdersSeederVocabularyTest extends TestCase {

	/**
	 * The carrier's declared vocabulary, read out of the fixture plugin's own
	 * `Orders_Provider` registration rather than restated here — a second copy
	 * would drift from the first exactly the way the seeder's list did.
	 *
	 * @return array{map: array<string, string>, labels: array<string, string>}
	 */
	private function declared_vocabulary( string $relative_path = '/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php' ): array {
		$source = file_get_contents( dirname( __DIR__ ) . $relative_path );

		$this->assertNotFalse( $source, 'the fixture plugin file must be readable' );

		$extract = static function ( string $block ) use ( $source ): array {
			$found = [];

			if ( 1 !== preg_match( "/'" . $block . "'\s*=>\s*\[(.*?)\n\t*\]/s", $source, $matches ) ) {
				return $found;
			}

			if ( preg_match_all( "/'([A-Z_]+)'\s*=>/", $matches[1], $keys ) ) {
				$found = $keys[1];
			}

			return $found;
		};

		return [
			'map'    => $extract( 'status_map' ),
			'labels' => $extract( 'status_labels' ),
		];
	}

	public function test_the_fixture_declares_a_vocabulary_at_all(): void {
		$vocabulary = $this->declared_vocabulary();

		// Guards the extraction itself: a silently empty result would make every
		// assertion below pass for the wrong reason.
		$this->assertNotEmpty( $vocabulary['labels'], 'status_labels must be extractable' );
		$this->assertContains( 'LOST_IN_TRANSIT', $vocabulary['labels'] );
	}

	public function test_every_seeded_raw_status_is_one_the_carrier_speaks(): void {
		$labels = $this->declared_vocabulary()['labels'];

		foreach ( \Woodev_Test_Orders_Seeder::demo_orders() as $index => $definition ) {
			$this->assertContains(
				$definition['raw_status'],
				$labels,
				sprintf(
					'demo order #%d seeds raw status "%s", which this carrier never declares',
					$index,
					$definition['raw_status']
				)
			);
		}
	}

	/**
	 * The unmapped branch must survive: at least one seeded order carries a raw
	 * status the carrier labels but deliberately does NOT map to a canonical one,
	 * so the «Неизвестно» row stays visible on the rig.
	 */
	public function test_at_least_one_seeded_order_is_deliberately_unmapped(): void {
		$vocabulary = $this->declared_vocabulary();
		$unmapped   = array_diff( $vocabulary['labels'], $vocabulary['map'] );

		$this->assertNotEmpty( $unmapped, 'the fixture must keep at least one unmapped raw status' );

		$seeded = array_column( \Woodev_Test_Orders_Seeder::demo_orders(), 'raw_status' );

		$this->assertNotEmpty(
			array_intersect( $seeded, $unmapped ),
			'no seeded order carries an unmapped raw status, so the «Неизвестно» branch is invisible'
		);
	}

	/**
	 * The seeder exists to make pagination reachable: the REST route's own default
	 * page size is 20, so a set at or below it never shows a second page.
	 */
	public function test_the_seeded_set_is_larger_than_one_page(): void {
		$this->assertGreaterThan(
			20,
			count( \Woodev_Test_Orders_Seeder::demo_orders() ),
			'the demo set must exceed one page, or pagination cannot be exercised on the rig'
		);
	}

	/** Dates must actually differ, or sorting and the date filter have nothing to act on. */
	public function test_the_seeded_orders_span_many_different_dates(): void {
		$days = array_column( \Woodev_Test_Orders_Seeder::demo_orders(), 'days_ago' );

		$this->assertGreaterThan( 15, count( array_unique( $days ) ), 'seeded dates must spread' );
		$this->assertGreaterThan( 365, max( $days ), 'some orders must fall outside the current year' );
	}

	/** The realistic fixture's own seeder gets the same guard — it was written from the same list. */
	private const REALISTIC_PLUGIN = '/_fixtures/woodev-realistic-shipping-plugin/includes/class-realistic-shipping-plugin.php';

	public function test_every_realistic_seeded_raw_status_is_one_that_carrier_speaks(): void {
		$labels = $this->declared_vocabulary( self::REALISTIC_PLUGIN )['labels'];

		$this->assertNotEmpty( $labels, 'the realistic fixture must declare status_labels' );

		foreach ( \Woodev_Realistic_Orders_Seeder::demo_orders() as $index => $definition ) {
			$this->assertContains(
				$definition['raw_status'],
				$labels,
				sprintf(
					'realistic demo order #%d seeds raw status "%s", which that carrier never declares',
					$index,
					$definition['raw_status']
				)
			);
		}
	}

	public function test_the_realistic_seeded_set_also_exceeds_one_page(): void {
		$this->assertGreaterThan( 20, count( \Woodev_Realistic_Orders_Seeder::demo_orders() ) );
	}

	/** Both of that carrier's shipping methods must appear, or the «type» column shows only one. */
	public function test_the_realistic_set_uses_both_shipping_methods(): void {
		$methods = array_unique( array_column( \Woodev_Realistic_Orders_Seeder::demo_orders(), 'method_id' ) );

		$this->assertCount( 2, $methods );
	}

	/**
	 * #876: a «Оплата» column fed by a single payment method is a different shape from a real
	 * shop's and hid two defects on this page before anyone noticed the column was uniform
	 * rather than merely repetitive — so the seeded set must carry SEVERAL different ones.
	 */
	public function test_the_seeded_set_uses_several_different_payment_methods(): void {
		$indexes = array_unique( array_column( \Woodev_Test_Orders_Seeder::demo_orders(), 'payment_method_index' ) );

		$this->assertGreaterThan( 1, count( $indexes ), 'seeded orders must carry more than one payment method' );
	}

	/** Every declared `payment_method_index` must resolve to a real entry in the pool. */
	public function test_every_demo_order_payment_method_index_resolves_to_a_declared_method(): void {
		$pool = \Woodev_Test_Orders_Seeder::payment_method_pool();

		foreach ( \Woodev_Test_Orders_Seeder::demo_orders() as $index => $definition ) {
			$this->assertArrayHasKey( 'payment_method_index', $definition, sprintf( 'demo order #%d has no payment_method_index', $index ) );
			$this->assertArrayHasKey( $definition['payment_method_index'] % count( $pool ), $pool );
		}
	}

	/** The realistic fixture's own seeder gets the same #876 guard. */
	public function test_the_realistic_set_also_uses_several_different_payment_methods(): void {
		$indexes = array_unique( array_column( \Woodev_Realistic_Orders_Seeder::demo_orders(), 'payment_method_index' ) );

		$this->assertGreaterThan( 1, count( $indexes ), 'realistic seeded orders must carry more than one payment method' );
	}

	public function test_every_realistic_demo_order_payment_method_index_resolves_to_a_declared_method(): void {
		$pool = \Woodev_Realistic_Orders_Seeder::payment_method_pool();

		foreach ( \Woodev_Realistic_Orders_Seeder::demo_orders() as $index => $definition ) {
			$this->assertArrayHasKey( 'payment_method_index', $definition, sprintf( 'realistic demo order #%d has no payment_method_index', $index ) );
			$this->assertArrayHasKey( $definition['payment_method_index'] % count( $pool ), $pool );
		}
	}
}
