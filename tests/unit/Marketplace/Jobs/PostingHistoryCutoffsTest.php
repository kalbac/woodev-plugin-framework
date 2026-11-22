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
 * One option per account holds the moment its first poll began; `add_option()` keeps the first record of an account and never
 * touches another account's. The option table is a double with the same rule as WordPress's: `add_option()` stores nothing when
 * the name exists.
 */
final class PostingHistoryCutoffsTest extends TestCase {
	/**
	 * The options as the test's doubles keep them.
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
		Functions\when( 'add_option' )->alias(
			function ( string $name, $value ): bool {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}

				$this->options[ $name ] = (string) $value; // The options table hands every value back as a string.

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
		$this->assertSame( [ 'woodev_ozon-seller_postings_history_cutoff_3', 'woodev_ozon-seller_postings_history_cutoff_4' ], array_keys( $this->options ) );
	}

	/**
	 * The first poll of two accounts begins in two requests at once: both records are kept, neither overwrites the other, however
	 * the two interleave — each request holds its own copy of the store and nothing is read back whole.
	 *
	 * @return void
	 */
	public function test_two_accounts_first_records_do_not_lose_each_other(): void {
		$request_a = new Posting_History_Cutoffs( 'ozon-seller' );
		$request_b = new Posting_History_Cutoffs( 'ozon-seller' );

		$this->assertNull( $request_a->get( 3 ), 'request A looks first' );
		$this->assertNull( $request_b->get( 4 ), 'request B looks before A has written' );

		$request_a->remember( 3, 1795000000 );
		$request_b->remember( 4, 1796000000 );

		$this->assertSame( 1795000000, $request_b->get( 3 ), 'A\'s record survives B\'s write' );
		$this->assertSame( 1796000000, $request_a->get( 4 ) );
	}

	/**
	 * The first record stays, however late the second comes; an account with no history stores 0, which is a record too.
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
