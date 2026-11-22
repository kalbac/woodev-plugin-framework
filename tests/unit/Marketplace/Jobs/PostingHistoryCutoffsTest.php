<?php
/**
 * Where each account's order history ends (#1925).
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Jobs\Posting_History_Cutoffs;
use Woodev\Tests\Unit\TestCase;

/**
 * One option of the plugin's family holds the moment each account's first poll began; the first record of an account stays.
 */
final class PostingHistoryCutoffsTest extends TestCase {
	/**
	 * The option as the test's `get_option` and `update_option` keep it.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * Installs the option doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->options = [];

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
	}

	/**
	 * An account nobody recorded has none; a record is kept per account, in the plugin's option family.
	 *
	 * @return void
	 */
	public function test_each_account_keeps_its_own_cutoff(): void {
		$cutoffs = new Posting_History_Cutoffs( 'ozon-seller' );

		$this->assertNull( $cutoffs->get( 3 ) );

		$cutoffs->remember( 3, 1795000000 );
		$cutoffs->remember( 4, 1796000000 );

		$this->assertSame( 1795000000, $cutoffs->get( 3 ) );
		$this->assertSame( 1796000000, $cutoffs->get( 4 ) );
		$this->assertSame( 'woodev_ozon-seller_postings_history_cutoff', array_key_first( $this->options ) );
	}

	/**
	 * The first record stays; an account with no history stores 0, which is a record too.
	 *
	 * @return void
	 */
	public function test_the_first_record_stays_and_zero_is_a_record(): void {
		$cutoffs = new Posting_History_Cutoffs( 'ozon-seller' );

		$cutoffs->remember( 3, 1795000000 );
		$cutoffs->remember( 3, 1799999999 );
		$cutoffs->remember( 5, 0 );
		$cutoffs->remember( 5, 1799999999 );

		$this->assertSame( 1795000000, $cutoffs->get( 3 ) );
		$this->assertSame( 0, $cutoffs->get( 5 ) );
	}
}
