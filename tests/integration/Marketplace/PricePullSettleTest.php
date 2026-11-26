<?php
/**
 * The one-time price pull's settle on a real MySQL / MariaDB (#1797, D3).
 *
 * The unit suite runs the queue on SQLite, whose `UPDATE` reports the rows it MATCHED. MySQL and MariaDB report the rows it CHANGED,
 * so settling a pair that is already exactly settled changes none — and used to read as a lost claim, which requeued the whole batch
 * for ever. What only a real server shows is here: an identical second settle, and a batch whose first article is already settled
 * while its second needs an update.
 *
 * The tables are created and dropped through a connection of their own, outside the WordPress test case's transaction (and its
 * CREATE TABLE → TEMPORARY TABLE rewriting), as in {@see BulkRunTwoConnectionsTest}.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Flows\Export_Price_Policy;
use Woodev\Framework\Marketplace\Governor\Sync_Cadence;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Currency;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Price\Price_Refusal;
use Woodev\Framework\Marketplace\Price\Price_Rule;
use Woodev\Framework\Marketplace\Price\Price_Sync_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Framework\Marketplace\Sync\Price_Context;
use Woodev\Framework\Marketplace\Sync\Price_Reader;
use Woodev\Framework\Marketplace\Sync\Price_Refresher;
use Woodev\Tests\Integration\TestCase;

class PricePullSettleTest extends TestCase {
	private const ADAPTER = 'itestpull';

	/**
	 * @var Schema
	 */
	private $schema;

	/**
	 * The connection the tables live on: no transaction of the test case wraps it.
	 *
	 * @var \wpdb
	 */
	private $conn;

	/**
	 * @var int
	 */
	private $account_id = 0;

	protected function setUp(): void {
		$this->schema = new Schema( self::ADAPTER );

		parent::setUp();

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		global $wpdb;

		$this->conn         = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->conn->prefix = $wpdb->prefix;

		$this->drop_tables();
		$statements = $this->schema->statements( $wpdb->prefix, $this->conn->get_charset_collate() );

		foreach ( [ 'accounts', 'links', 'link_ids', 'sync_state' ] as $table ) {
			$this->assertNotFalse( $this->conn->query( $statements[ $this->table( $table ) ] ), 'Created ' . $table );
		}

		$this->account_id = $this->accounts()->save( new Account( 0, self::ADAPTER, 'Основной', true, [ 'cabinet' => 'c-1', 'token' => 't-1' ], null, [] ) );
	}

	protected function tearDown(): void {
		$this->conn->close();

		parent::tearDown();

		$this->drop_tables();
	}

	private function table( string $name ): string {
		global $wpdb;

		return $this->schema->table_name( $name, $wpdb->prefix );
	}

	private function drop_tables(): void {
		global $wpdb;

		foreach ( [ 'accounts', 'links', 'link_ids', 'sync_state' ] as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names are this test's own.
		}
	}

	private function accounts(): Wpdb_Account_Store {
		return new Wpdb_Account_Store( $this->conn, $this->table( 'accounts' ), new Secret_Cipher( 'integration-salt' ), self::ADAPTER, new Credential_Fields( 'cabinet', [ 'token' ] ) );
	}

	private function links(): Wpdb_Link_Store {
		return new Wpdb_Link_Store( $this->conn, $this->table( 'links' ), $this->table( 'link_ids' ) );
	}

	private function queue(): Price_Sync_Queue {
		return new Price_Sync_Queue( $this->conn, $this->table( 'sync_state' ), $this->table( 'links' ), $this->table( 'accounts' ), new Sync_Cadence( 360, 5 ) );
	}

	private function store(): Sync_State_Store {
		return new Sync_State_Store( $this->conn, $this->table( 'sync_state' ) );
	}

	/**
	 * A shop product with a created card whose shop price the shop has queued for the marketplace (revision 1).
	 *
	 * @param string $offer Article.
	 * @param string $price Shop and queued price.
	 * @return array{0:int,1:int} Product id and link id.
	 */
	private function queued( string $offer, string $price ): array {
		$product = new \WC_Product_Simple();
		$product->set_regular_price( $price );
		$product->set_sku( $offer . '-' . wp_generate_password( 6, false ) );
		$product->save();

		$this->links()->save( new Product_Link( $this->account_id, $product->get_id(), 0, $offer, [ 'item' => 700 + $product->get_id() ], Product_Link::STATE_CREATED, [ 'sync_price' => true ] ) );

		$link_id = (int) $this->links()->find( $this->account_id, $product->get_id() )->link_id();

		$this->store()->record(
			$this->account_id,
			$link_id,
			0,
			Sync_State_Store::KIND_PRICE,
			[
				'price'         => $price,
				'old_price'     => '0',
				'min_price'     => null,
				'currency_code' => 'RUB',
			],
			[
				'regular' => $price,
				'sale'    => '',
				'from'    => null,
				'to'      => null,
			]
		);

		return [ $product->get_id(), $link_id ];
	}

	/**
	 * The export side with the identity rule, over real WooCommerce products.
	 *
	 * @return Price_Context
	 */
	private function contexts(): Price_Context {
		return new class() implements Price_Context {
			public function product( int $product_id ): ?\WC_Product {
				$product = wc_get_product( $product_id );

				return $product instanceof \WC_Product ? $product : null;
			}

			public function price_pair( Account $account, \WC_Product $product, Link_Record $link ): ?Price_Pair {
				return new Price_Pair( Money::from_string( (string) $product->get_regular_price(), 'RUB' ) );
			}

			public function payload( Price_Pair $pair ): array {
				return Export_Price_Policy::payload( $pair );
			}

			public function price_terms( Account $account, \WC_Product $product ): array {
				return [
					'rule'     => new Price_Rule(),
					'currency' => Price_Currency::none( 'RUB' ),
				];
			}

			public function invert( Money $price, ?Money $old_price, Price_Rule $rule, ?array $override, Price_Currency $currency ): ?array {
				return Export_Price_Policy::invert( $price, $old_price, $rule, $override, $currency );
			}
		};
	}

	/**
	 * A marketplace that holds the given prices.
	 *
	 * @param array<string,string> $prices Article => price.
	 * @return Price_Reader
	 */
	private function reader( array $prices ): Price_Reader {
		return new class( $prices ) implements Price_Reader {
			private $prices;

			public function __construct( array $prices ) {
				$this->prices = $prices;
			}

			public function read( Account $account, array $offer_ids ): array {
				$read = [];

				foreach ( $offer_ids as $offer_id ) {
					if ( isset( $this->prices[ $offer_id ] ) ) {
						$read[ $offer_id ] = new Price_Pair( Money::from_string( $this->prices[ $offer_id ], 'RUB' ) );
					}
				}

				return $read;
			}
		};
	}

	/**
	 * @return array<string,string|null>
	 */
	private function payload( string $price ): array {
		return [
			'price'         => $price,
			'old_price'     => '0',
			'min_price'     => null,
			'currency_code' => 'RUB',
		];
	}

	/**
	 * The second of two identical settles changes no row on MySQL: it stands when the row is read back as settled under the live claim,
	 * and is refused without the claim or for another revision.
	 *
	 * @return void
	 */
	public function test_an_identical_second_settle_stands_on_a_real_server(): void {
		list( , $link_id ) = $this->queued( 'a', '150.00' );

		$token = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		$this->assertNotSame( '', $token );
		$this->assertTrue( $this->queue()->settle_pulled( $this->account_id, $link_id, 1, $this->payload( '100.00' ), $token ), 'The first settle changes the row.' );
		$this->assertTrue( $this->queue()->settle_pulled( $this->account_id, $link_id, 1, $this->payload( '100.00' ), $token ), 'The identical second settle changes none and still stands.' );
		$this->assertFalse( $this->queue()->settle_pulled( $this->account_id, $link_id, 1, $this->payload( '100.00' ), 'not-the-holder' ), 'Without the claim it is refused.' );
		$this->assertFalse( $this->queue()->settle_pulled( $this->account_id, $link_id, 2, $this->payload( '100.00' ), $token ), 'Another revision is refused.' );
		$this->assertSame( [], $this->queue()->due( $this->account_id, 10 ), 'The pair is settled: nothing is due.' );

		$this->accounts()->release_claim( $this->account_id, $token );
	}

	/**
	 * A batch whose first article is already settled and whose second needs a price update loads both, in one run, under one claim.
	 *
	 * @return void
	 */
	public function test_a_batch_with_an_already_settled_first_article_loads_the_second(): void {
		list( $first_product )  = $this->queued( 'a', '150.00' );
		list( $second_product ) = $this->queued( 'b', '160.00' );
		$refresher              = new Price_Refresher( $this->reader( [ 'a' => '100.00', 'b' => '110.00' ] ), $this->links(), $this->contexts(), $this->queue() );

		$token = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$fence = new Claim_Fence( $this->accounts()->table(), $token );

		// Article a only: it is settled now. Then the batch runs a and b.
		$refresher->pull_once( $this->accounts()->get( $this->account_id ), [ 'a' ], $fence );
		$result = $refresher->pull_once( $this->accounts()->get( $this->account_id ), [ 'a', 'b' ], $fence );

		$this->assertSame( [ 'a' ], $result->unchanged() );
		$this->assertSame( [ 'b' ], $result->updated() );
		$this->assertSame( [], $result->skipped() );
		$this->assertSame( '100', (string) (float) wc_get_product( $first_product )->get_regular_price() );
		$this->assertSame( '110', (string) (float) wc_get_product( $second_product )->get_regular_price() );
		$this->assertSame( [], $this->queue()->due( $this->account_id, 10 ), 'Both pairs are settled.' );

		$this->accounts()->release_claim( $this->account_id, $token );
	}

	/**
	 * A price task's refusal and the next send read columns they also assign: MySQL evaluates the assignments left to right, so the
	 * refused payload is taken from the snapshot before it is cleared, and a new revision drops the old reason and its text.
	 *
	 * @return void
	 */
	public function test_a_refused_price_and_the_next_send_keep_their_order_of_assignment(): void {
		list( , $link_id ) = $this->queued( 'a', '150.00' );
		$token             = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$queue             = $this->queue();
		$sent              = $queue->due( $this->account_id, 10 )[0];

		$this->assertTrue( $queue->begin( $this->account_id, $sent, $token ) );
		$this->assertTrue( $queue->await( $this->account_id, $sent, 41, $token ) );
		$this->assertTrue( $queue->await( $this->account_id, $sent, 41, $token ), 'The identical second mark changes no row and stands.' );
		$this->assertSame( [], $queue->due( $this->account_id, 10 ), 'A waiting pair is not due.' );
		$this->assertSame( [ $link_id ], array_keys( $queue->awaiting( $this->account_id, 41 ) ) );

		$this->assertTrue( $queue->refuse( $this->account_id, $sent, Price_Refusal::QUARANTINED, 'held', $token ) );
		$row = $this->price_row( $link_id );
		$this->assertSame( $row['pending_payload'], $row['refused_payload'] );
		$this->assertNull( $row['in_flight_payload'] );
		$this->assertSame( [], $queue->due( $this->account_id, 10 ), 'The refused price is not due.' );

		$this->store()->record( $this->account_id, $link_id, 0, Sync_State_Store::KIND_PRICE, $this->payload( '140.00' ) );
		$next = $queue->due( $this->account_id, 10 )[0];
		$this->assertTrue( $queue->begin( $this->account_id, $next, $token ) );

		$row = $this->price_row( $link_id );
		$this->assertNull( $row['error_code'] );
		$this->assertNull( $row['last_error'] );
		$this->assertSame( 2, (int) $row['in_flight_rev'] );

		$this->accounts()->release_claim( $this->account_id, $token );
	}

	/**
	 * Reads the price row of a link.
	 *
	 * @param int $link_id Link id.
	 * @return array<string,mixed>
	 */
	private function price_row( int $link_id ): array {
		return (array) $this->conn->get_row( $this->conn->prepare( 'SELECT * FROM %i WHERE link_id = %d AND kind = %s', $this->table( 'sync_state' ), $link_id, 'price' ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The test's own table.
	}
}
