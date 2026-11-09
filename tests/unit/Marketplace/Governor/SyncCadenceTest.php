<?php
/**
 * The marketplace's limits on a sync queue.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Governor;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Governor\Sync_Cadence;

/**
 * D2 and D3: the interval and the attempt cap are values the adapter hands the queue, so a bad pair is refused where it is made.
 */
final class SyncCadenceTest extends TestCase {
	/**
	 * The cadence returns what it was given.
	 *
	 * @return void
	 */
	public function test_it_holds_the_adapters_numbers(): void {
		$prices = new Sync_Cadence( 360, 5 );
		$stock  = new Sync_Cadence( 0, 5 );

		$this->assertSame( 360, $prices->min_interval() );
		$this->assertSame( 5, $prices->max_attempts() );
		$this->assertSame( 0, $stock->min_interval(), 'no minimum between sends' );
	}

	/**
	 * A negative interval or a cap below one is refused.
	 *
	 * @dataProvider invalid_cadences
	 * @param int $interval Interval.
	 * @param int $attempts Cap.
	 * @return void
	 */
	public function test_an_invalid_cadence_is_refused( int $interval, int $attempts ): void {
		$this->expectException( \InvalidArgumentException::class );
		new Sync_Cadence( $interval, $attempts );
	}

	/**
	 * Pairs that make no sense.
	 *
	 * @return array<string,array{0:int,1:int}>
	 */
	public function invalid_cadences(): array {
		return array(
			'negative interval' => array( -1, 5 ),
			'zero attempts'     => array( 360, 0 ),
			'negative attempts' => array( 360, -2 ),
		);
	}
}
