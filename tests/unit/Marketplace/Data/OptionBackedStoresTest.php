<?php
/**
 * The stores that live in options and transients under the plugin's own family.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Data\Cards_Snapshot_Store;
use Woodev\Framework\Marketplace\Data\Link_Progress_Store;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Data\Uninstaller;

/**
 * Names are built from the option family, so two marketplace plugins never read each other's records and the uninstaller finds
 * every one of them.
 */
final class OptionBackedStoresTest extends TestCase {
	/**
	 * Options and transients, name => value.
	 *
	 * @var array<string,mixed>
	 */
	private $storage = [];

	/**
	 * Stub names and values the stores write, by function.
	 *
	 * @var array<string,string[]>
	 */
	private $written = [
		'option'    => [],
		'transient' => [],
	];

	/**
	 * Backs the WordPress storage functions with arrays.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->storage = [];
		$this->written = [
			'option'    => [],
			'transient' => [],
		];

		$read = function ( string $name, $fallback = false ) {
			return array_key_exists( $name, $this->storage ) ? $this->storage[ $name ] : $fallback;
		};
		$drop = function ( string $name ): bool {
			unset( $this->storage[ $name ] );

			return true;
		};

		Functions\when( 'get_option' )->alias( $read );
		Functions\when( 'get_transient' )->alias( $read );
		Functions\when( 'delete_option' )->alias( $drop );
		Functions\when( 'delete_transient' )->alias( $drop );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->written['option'][] = $name;
				$this->storage[ $name ]    = $value;

				return true;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->written['transient'][] = $name;
				$this->storage[ $name ]       = $value;

				return true;
			}
		);
	}

	/**
	 * Restores Brain Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Every name starts with the plugin's family, which is what the uninstaller removes.
	 *
	 * @return void
	 */
	public function test_every_record_lives_under_the_plugins_family(): void {
		$family = Uninstaller::option_prefix( 'fake-seller' );

		( new Link_Progress_Store( $family ) )->save( 3, Link_Progress_Store::KIND_LINK, [ 'started_at' => 1, 'scope' => 0, 'state' => [] ] );
		( new Link_Report_Store( $family ) )->replace_near_matches( 3, [ [ 'wc_product_id' => 1, 'card' => [ 'offer_id' => 'A' ] ] ] );
		( new Link_Report_Store( $family ) )->save_run( 3, [ 'linked' => 2 ] );
		$snapshots = new Cards_Snapshot_Store( $family );
		$snapshots->put( 3, [ [ 'offer_id' => 'A' ] ], 100 );
		$snapshots->mark_refreshing( 3 );
		$snapshots->fail( 3, 'x' );

		$names = array_merge( $this->written['option'], $this->written['transient'] );
		$this->assertNotEmpty( $names );
		foreach ( $names as $name ) {
			$this->assertStringStartsWith( 'woodev_fake-seller_', $name );
		}
	}

	/**
	 * Two plugins never read each other's records.
	 *
	 * @return void
	 */
	public function test_two_plugins_do_not_share_records(): void {
		$mine  = new Link_Report_Store( 'woodev_one_' );
		$other = new Link_Report_Store( 'woodev_two_' );

		$mine->save_run( 3, [ 'linked' => 2 ] );

		$this->assertSame( [ 'linked' => 2 ], $mine->last_run( 3 ) );
		$this->assertNull( $other->last_run( 3 ) );
	}

	/**
	 * A run continues only a record of the same scope that is younger than six hours.
	 *
	 * @return void
	 */
	public function test_progress_is_continued_only_by_the_same_young_run(): void {
		$store  = new Link_Progress_Store( 'woodev_one_' );
		$record = $store->load( 3, Link_Progress_Store::KIND_LINK, 5, 1000 );
		$this->assertSame( [], $record['state'], 'a new record' );

		$record['state'] = [ 'page' => 4 ];
		$store->save( 3, Link_Progress_Store::KIND_LINK, $record );

		$this->assertSame( [ 'page' => 4 ], $store->load( 3, Link_Progress_Store::KIND_LINK, 5, 2000 )['state'] );
		$this->assertSame( [], $store->load( 3, Link_Progress_Store::KIND_LINK, 6, 2000 )['state'], 'another scope' );
		$this->assertSame( [], $store->load( 3, Link_Progress_Store::KIND_LINK, 5, 1000 + Link_Progress_Store::MAX_AGE )['state'], 'too old' );
		$this->assertSame( [], $store->load( 4, Link_Progress_Store::KIND_LINK, 5, 2000 )['state'], 'another account' );

		$store->clear( 3, Link_Progress_Store::KIND_LINK );
		$this->assertSame( [], $store->load( 3, Link_Progress_Store::KIND_LINK, 5, 2000 )['state'] );
	}

	/**
	 * A pair the merchant answered «no» to is not offered by the next run; it is, once an article of the pair changed, and the
	 * memory goes with the account's options.
	 *
	 * @return void
	 */
	public function test_a_dismissed_pair_stays_out_of_the_next_run_until_an_article_changes(): void {
		$store = new Link_Report_Store( 'woodev_one_' );
		$pair  = static function ( int $product, string $sku, string $offer ): array {
			return [
				'wc_product_id' => $product,
				'wc_sku'        => $sku,
				'card'          => [ 'offer_id' => $offer ],
			];
		};

		$store->replace_near_matches( 3, [ $pair( 1, 'ABC-1', 'abc-1' ), $pair( 2, 'XYZ', 'xyz' ) ] );

		$this->assertTrue( $store->dismiss_near_match( 3, 1, 'abc-1' ) );
		$this->assertFalse( $store->dismiss_near_match( 3, 1, 'abc-1' ), 'the pair is gone from the report: nothing to dismiss twice' );
		$this->assertSame( [ 2 ], array_column( $store->near_matches( 3 ), 'wc_product_id' ) );

		$this->assertSame( 0, $store->replace_near_matches( 3, [ $pair( 1, 'ABC-1', 'abc-1' ), $pair( 2, 'XYZ', 'xyz' ) ] ) );
		$this->assertSame( [ 2 ], array_column( $store->near_matches( 3 ), 'wc_product_id' ), 'the same pair is not offered again' );

		$store->replace_near_matches( 3, [ $pair( 1, 'ABC-1', 'abc-1' ) ] );
		$this->assertSame( [], $store->near_matches( 3 ), 'a run that finds only the answered pair offers nothing' );

		$store->replace_near_matches( 3, [ $pair( 1, 'ABC-1x', 'abc-1' ) ] );
		$this->assertSame( [ 1 ], array_column( $store->near_matches( 3 ), 'wc_product_id' ), 'a changed article makes it a new question' );

		$store->replace_near_matches( 3, [ $pair( 1, 'ABC-1', 'abc-1' ) ] );
		$this->assertSame( [], $store->near_matches( 3 ), 'the answer outlives a run that did not find the pair' );

		$other = new Link_Report_Store( 'woodev_two_' );
		$other->replace_near_matches( 3, [ $pair( 1, 'ABC-1', 'abc-1' ) ] );
		$this->assertCount( 1, $other->near_matches( 3 ), 'another plugin\'s family is untouched' );
	}

	/**
	 * Forgetting an account removes its report, its answered pairs and its last run, and only those of that account.
	 *
	 * @return void
	 */
	public function test_forgetting_an_account_removes_only_its_report_answers_and_run(): void {
		$store = new Link_Report_Store( 'woodev_one_' );
		$other = new Link_Report_Store( 'woodev_two_' );
		$pair  = [ 'wc_product_id' => 1, 'wc_sku' => 'ABC-1', 'card' => [ 'offer_id' => 'abc-1' ] ];

		foreach ( [ [ $store, 3 ], [ $store, 33 ], [ $other, 3 ] ] as [ $target, $account ] ) {
			$target->replace_near_matches( $account, [ $pair ] );
			$target->dismiss_near_match( $account, 1, 'abc-1' );
			$target->save_run( $account, [ 'linked' => 2 ] );
		}

		$store->forget_account( 3 );

		$this->assertSame( [], $store->near_matches( 3 ) );
		$this->assertNull( $store->last_run( 3 ) );
		$this->assertArrayNotHasKey( 'woodev_one_near_dismissed_3', $this->storage, 'the answered pairs go too' );
		$this->assertArrayNotHasKey( 'woodev_one_near_matches_3', $this->storage );
		$this->assertArrayNotHasKey( 'woodev_one_link_run_3', $this->storage );

		foreach ( [ 'woodev_one_link_run_33', 'woodev_one_near_dismissed_33', 'woodev_two_link_run_3', 'woodev_two_near_dismissed_3' ] as $kept ) {
			$this->assertArrayHasKey( $kept, $this->storage, 'another account and another plugin keep ' . $kept );
		}
	}

	/**
	 * The near-match report is capped, replaced by a new run, found by pair, and settled by a decision.
	 *
	 * @return void
	 */
	public function test_the_near_match_report(): void {
		$store   = new Link_Report_Store( 'woodev_one_' );
		$entries = [];
		for ( $i = 1; $i <= Link_Report_Store::MAX_ENTRIES + 3; $i++ ) {
			$entries[] = [
				'wc_product_id' => $i,
				'card'          => [ 'offer_id' => 'A' . $i ],
			];
		}

		$this->assertSame( 3, $store->replace_near_matches( 3, $entries ), 'entries left out for the limit' );
		$this->assertCount( Link_Report_Store::MAX_ENTRIES, $store->near_matches( 3 ) );
		$this->assertSame( 'A2', $store->find_near_match( 3, 2, 'A2' )['card']['offer_id'] );
		$this->assertNull( $store->find_near_match( 3, 2, 'A3' ) );

		$store->replace_near_matches(
			3,
			[
				[
					'wc_product_id' => 1,
					'card'          => [ 'offer_id' => 'X' ],
				],
				[
					'wc_product_id' => 1,
					'card'          => [ 'offer_id' => 'Y' ],
				],
				[
					'wc_product_id' => 2,
					'card'          => [ 'offer_id' => 'X' ],
				],
			]
		);

		$store->settle_near_match( 3, 1, 'X', false );
		$this->assertCount( 2, $store->near_matches( 3 ), '«no» removes the pair only' );

		$store->settle_near_match( 3, 1, 'Y', true );
		$this->assertSame( [ 2 ], array_column( $store->near_matches( 3 ), 'wc_product_id' ), 'a link removes the product\'s and the card\'s other entries' );

		$store->replace_near_matches( 3, [] );
		$this->assertSame( [], $store->near_matches( 3 ) );
		$this->assertNull( $store->last_run( 3 ) );
	}

	/**
	 * The cards snapshot: stored with its time, a refresh mark that a stored snapshot clears, and the last error.
	 *
	 * @return void
	 */
	public function test_the_cards_snapshot_lifecycle(): void {
		$store = new Cards_Snapshot_Store( 'woodev_one_' );

		$this->assertNull( $store->get( 3 ) );

		$store->mark_refreshing( 3 );
		$this->assertTrue( $store->is_refreshing( 3 ) );

		$store->fail( 3, 'Маркетплейс не ответил' );
		$this->assertFalse( $store->is_refreshing( 3 ) );
		$this->assertSame( 'Маркетплейс не ответил', $store->last_error( 3 ) );

		$this->assertTrue( $store->put( 3, [ [ 'offer_id' => 'A' ] ], 100 ) );
		$this->assertSame(
			[
				'fetched_at' => 100,
				'cards'      => [ [ 'offer_id' => 'A' ] ],
			],
			$store->get( 3 )
		);
		$this->assertSame( '', $store->last_error( 3 ), 'a stored snapshot clears the last error' );

		$this->assertNull( ( new Cards_Snapshot_Store( 'woodev_two_' ) )->get( 3 ), 'another plugin has none' );
	}
}
