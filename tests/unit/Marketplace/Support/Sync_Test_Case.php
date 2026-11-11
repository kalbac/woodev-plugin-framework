<?php
/**
 * Base of the run model, stock and price tests.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

require_once __DIR__ . '/wc-cache-helper-stub.php';

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Governor\Sync_Cadence;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Account_Run;
use Woodev\Framework\Marketplace\Jobs\Flush_Sync_Runner;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Price_Flush_Runner;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Line;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Pusher;
use Woodev\Framework\Marketplace\Price\Price_Sync_Queue;
use Woodev\Framework\Marketplace\Stock\Stock_Policy;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Framework\Marketplace\Sync\Mirror_Stock_Writer;
use Woodev\Framework\Marketplace\Sync\Registry_Stock_Accounting;
use Woodev\Framework\Marketplace\Sync\Stock_Flush_Result;
use Woodev\Framework\Marketplace\Sync\Stock_Flusher;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;
use Woodev\Framework\Marketplace\Sync\Stock_Readback;
use Woodev\Framework\Marketplace\Sync\Stock_Transaction;
use Woodev\Framework\Marketplace\Sync\Sync_Limits;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Error_Classifier;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Stock_Pusher;

/**
 * One enabled account of the fake marketplace over the real tables on SQLite, the stores over one test clock, and a marketplace
 * double that keeps the stock it was sent.
 *
 * **WooCommerce is a double too.** A product is a mock whose stock lives in a table of the same database
 * (`wp_fake_wc_stock`), so a transaction around a stock write and its accounting row is a real one. `wc_update_product_stock()`
 * writes that table and then calls the hooked {@see Stock_Intents}, the way WooCommerce fires `woocommerce_product_set_stock`
 * after every stock write.
 */
abstract class Sync_Test_Case extends TestCase {
	protected const NOW       = 1794139200;
	protected const WAREHOUSE = 22;
	protected const PLUGIN_ID = 'fake-seller';

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	protected $db;

	/**
	 * Test clock, in seconds.
	 *
	 * @var int
	 */
	protected $now = self::NOW;

	/**
	 * The account.
	 *
	 * @var int
	 */
	protected $account_id = 0;

	/**
	 * The marketplace double.
	 *
	 * @var Scripted_Stock_Pusher
	 */
	protected $market;

	/**
	 * Error classifier of the fake marketplace.
	 *
	 * @var Fake_Error_Classifier
	 */
	protected $classifier;

	/**
	 * Fake Action Scheduler.
	 *
	 * @var Fake_Action_Scheduler
	 */
	protected $scheduler;

	/**
	 * Whether the licence is in force.
	 *
	 * @var bool
	 */
	protected $licensed = true;

	/**
	 * The plugin's write gate, or null.
	 *
	 * @var callable|null
	 */
	protected $write_gate;

	/**
	 * The formula in force.
	 *
	 * @var Stock_Policy
	 */
	protected $policy;

	/**
	 * Attempt cap and interval of the stock queue.
	 *
	 * @var Sync_Cadence
	 */
	protected $stock_cadence;

	/**
	 * Lines written to the error log.
	 *
	 * @var string[]
	 */
	protected $log = [];

	/**
	 * Most prices one request of the fake marketplace takes.
	 *
	 * @var int
	 */
	protected $price_batch = 1000;

	/**
	 * Retries the registry accounting asked for: `[ account id, group key, delay ]`.
	 *
	 * @var array<int,array{0:int,1:string,2:int}>
	 */
	protected $retries = [];

	/**
	 * Product doubles by id.
	 *
	 * @var array<int,\WC_Product>
	 */
	protected $products = [];

	/**
	 * Post status of a product, `publish` when absent.
	 *
	 * @var array<int,string>
	 */
	protected $post_status = [];

	/**
	 * The hooked stock recorder, called after every stock write.
	 *
	 * @var Stock_Intents|null
	 */
	protected $stock_hook;

	/**
	 * Next product id.
	 *
	 * @var int
	 */
	private $next_product = 101;

	/**
	 * Boots the stubs, the tables and the account.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->now           = self::NOW;
		$this->licensed      = true;
		$this->write_gate    = null;
		$this->log           = [];
		$this->retries       = [];
		$this->price_batch   = 1000;
		$this->products      = [];
		$this->post_status   = [];
		$this->stock_hook    = null;
		$this->next_product  = 101;
		$this->policy        = new Stock_Policy( Stock_Policy::FORMULA_WC_ONLY );
		$this->stock_cadence = new Sync_Cadence( 0, 5 );
		$this->market        = new Scripted_Stock_Pusher();
		$this->classifier    = new Fake_Error_Classifier();
		$this->scheduler     = Fake_Action_Scheduler::install();

		$this->db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $this->db );
		$this->db->define( 'CREATE TABLE wp_fake_wc_stock (product_id INTEGER PRIMARY KEY, qty INTEGER NULL)' );

		$this->stub_woocommerce();

		$this->account_id = $this->accounts()->save(
			new Account(
				0,
				Sqlite_Tables::MARKETPLACE_ID,
				'Основной',
				true,
				[
					'cabinet_ref' => 'cabinet-4417',
					'api_token'   => 'tok-0000-1111',
				],
				null,
				[
					'warehouse_id' => self::WAREHOUSE,
					'stock_mode'   => Wpdb_Account_Store::STOCK_SITE,
					'currency'     => 'RUB',
				]
			)
		);
	}

	/**
	 * Tears the stubs down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Replaces the WooCommerce and WordPress functions the sync code calls.
	 *
	 * @return void
	 */
	private function stub_woocommerce(): void {
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) {
				return $this->products[ (int) $id ] ?? false;
			}
		);
		Functions\when( 'get_post_status' )->alias(
			function ( $id ) {
				return isset( $this->products[ (int) $id ] ) ? ( $this->post_status[ (int) $id ] ?? 'publish' ) : false;
			}
		);
		Functions\when( 'get_post_type' )->alias(
			function ( $id ) {
				return isset( $this->products[ (int) $id ] ) ? 'product' : false;
			}
		);
		Functions\when( 'wc_update_product_stock' )->alias(
			function ( $product, $quantity = null, $operation = 'set' ) {
				return $this->write_wc_stock( $product, (int) $quantity, (string) $operation );
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'clean_post_cache' )->justReturn( null );
	}

	/**
	 * The double of `wc_update_product_stock()`: writes the stock where it is managed and fires the stock hook.
	 *
	 * @param \WC_Product $product   Product.
	 * @param int         $quantity  Quantity.
	 * @param string      $operation `set`, `increase` or `decrease`.
	 * @return int The stock now.
	 */
	protected function write_wc_stock( \WC_Product $product, int $quantity, string $operation ): int {
		$holder  = (int) $product->get_stock_managed_by_id();
		$current = (int) $this->wc_stock( $holder );
		$changes = [
			'set'      => $quantity,
			'increase' => $current + $quantity,
			'decrease' => $current - $quantity,
		];

		$this->db->query( 'UPDATE wp_fake_wc_stock SET qty = ' . (int) $changes[ $operation ] . ' WHERE product_id = ' . $holder );

		if ( null !== $this->stock_hook ) {
			$this->stock_hook->on_stock_change( $this->products[ $holder ] );
		}

		return (int) $changes[ $operation ];
	}

	/**
	 * Reads a product's WooCommerce stock.
	 *
	 * @param int $product_id Product id.
	 * @return int|null
	 */
	protected function wc_stock( int $product_id ): ?int {
		$qty = $this->db->get_var( 'SELECT qty FROM wp_fake_wc_stock WHERE product_id = ' . $product_id );

		return null === $qty ? null : (int) $qty;
	}

	/**
	 * Creates a product double.
	 *
	 * @param int|null            $stock   Stock; null for a product without a quantity.
	 * @param array<string,mixed> $options `manage_stock` (default true), `managed_by` (the product that holds the stock; itself by default).
	 * @return int Product id.
	 */
	protected function product( ?int $stock, array $options = [] ): int {
		$id         = $this->next_product++;
		$managed_by = (int) ( $options['managed_by'] ?? $id );
		$product    = \Mockery::mock( 'WC_Product' );

		$product->shouldReceive( 'get_id' )->andReturn( $id );
		$product->shouldReceive( 'managing_stock' )->andReturn( $options['manage_stock'] ?? true );
		$product->shouldReceive( 'get_stock_managed_by_id' )->andReturn( $managed_by );
		$product->shouldReceive( 'get_stock_quantity' )->andReturnUsing(
			function () use ( $managed_by ) {
				return $this->wc_stock( $managed_by );
			}
		);

		$this->products[ $id ] = $product;

		if ( $managed_by === $id ) {
			$this->db->query( 'INSERT INTO wp_fake_wc_stock (product_id, qty) VALUES (' . $id . ', ' . ( null === $stock ? 'NULL' : (int) $stock ) . ')' );
		}

		return $id;
	}

	/**
	 * Creates a product with managed stock and a created, stock-synced link.
	 *
	 * @param string              $offer     Article.
	 * @param int                 $remote_id The marketplace's item id.
	 * @param int                 $stock     Stock.
	 * @param array<string,mixed> $options   `warehouse_id` (link override), `oversize`, `sync_stock` (default true), `sync_price`
	 *                                       (default false), `state`, and the options of {@see self::product()}.
	 * @return int Product id.
	 */
	protected function stock_product( string $offer, int $remote_id, int $stock = 10, array $options = [] ): int {
		$id = $this->product( $stock, $options );

		$this->links()->save(
			new Product_Link(
				$this->account_id,
				$id,
				0,
				$offer,
				[ 'item' => $remote_id ],
				$options['state'] ?? Product_Link::STATE_CREATED,
				[
					'sync_stock'  => $options['sync_stock'] ?? true,
					'sync_price'  => $options['sync_price'] ?? false,
					'is_oversize' => $options['oversize'] ?? false,
				],
				$options['warehouse_id'] ?? null
			)
		);

		$this->market->present[ $offer ] = $stock;

		return $id;
	}

	/**
	 * Sets a product's stock the way the product form or an order does.
	 *
	 * @param int $product_id Product id.
	 * @param int $stock      Stock.
	 * @return void
	 */
	protected function set_stock( int $product_id, int $stock ): void {
		wc_update_product_stock( $this->products[ $product_id ], $stock, 'set' );
	}

	/**
	 * Hooks the stock recorder to the stock writes, as the plugin does.
	 *
	 * @return Stock_Intents
	 */
	protected function hook_stock(): Stock_Intents {
		$this->stock_hook = new Stock_Intents( [ $this->accounts(), 'get' ], $this->links(), $this->intents(), $this->queue(), $this->policy );

		return $this->stock_hook;
	}

	/**
	 * The test clock as a callable.
	 *
	 * @return callable
	 */
	protected function clock(): callable {
		return function (): int {
			return $this->now;
		};
	}

	/**
	 * The account store over the test clock.
	 *
	 * @return Wpdb_Account_Store
	 */
	protected function accounts(): Wpdb_Account_Store {
		return new Wpdb_Account_Store(
			$this->db,
			Sqlite_Tables::table( 'accounts' ),
			new Secret_Cipher( 'test-salt-secure-auth' ),
			Sqlite_Tables::MARKETPLACE_ID,
			new Credential_Fields( 'cabinet_ref', [ 'api_token' ] ),
			$this->clock()
		);
	}

	/**
	 * The link store over the test clock (its fenced writes compare the claim with it).
	 *
	 * @return Wpdb_Link_Store
	 */
	protected function links(): Wpdb_Link_Store {
		return new Wpdb_Link_Store( $this->db, Sqlite_Tables::table( 'links' ), Sqlite_Tables::table( 'link_ids' ), $this->clock() );
	}

	/**
	 * The pending side of `sync_state`.
	 *
	 * @return Sync_State_Store
	 */
	protected function intents(): Sync_State_Store {
		return new Sync_State_Store( $this->db, Sqlite_Tables::table( 'sync_state' ), $this->clock() );
	}

	/**
	 * The flusher side of `sync_state`.
	 *
	 * @return Sync_State_Queue
	 */
	protected function queue(): Sync_State_Queue {
		return new Sync_State_Queue( $this->db, Sqlite_Tables::table( 'sync_state' ), Sqlite_Tables::table( 'accounts' ), $this->stock_cadence, $this->clock() );
	}

	/**
	 * The job names of the test plugin.
	 *
	 * @return Job_Names
	 */
	protected function names(): Job_Names {
		return new Job_Names( self::PLUGIN_ID );
	}

	/**
	 * The per-request limits of the fake marketplace: 100 stock lines, 1000 prices (unless the test says otherwise), 15 s.
	 *
	 * @return Sync_Limits
	 */
	protected function limits(): Sync_Limits {
		return new Sync_Limits( 100, $this->price_batch, 15.0 );
	}

	/**
	 * Changes account settings.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return void
	 */
	protected function set_account( array $settings ): void {
		$columns = array_intersect_key( $settings, array_flip( [ 'warehouse_id', 'stock_mode', 'price_direction', 'orders_enabled', 'currency' ] ) );

		if ( [] !== $columns ) {
			$this->accounts()->update_fields( $this->account_id, $columns );
		}

		$rest = array_diff_key( $settings, $columns );

		if ( [] !== $rest ) {
			$this->accounts()->update_settings(
				$this->account_id,
				static function ( array $current ) use ( $rest ): array {
					return array_merge( $current, $rest );
				}
			);
		}
	}

	/**
	 * Returns the account.
	 *
	 * @return Account
	 */
	protected function account_now(): Account {
		return $this->accounts()->get( $this->account_id );
	}

	/**
	 * Returns a product's link.
	 *
	 * @param int $product_id Product id.
	 * @return Link_Record
	 */
	protected function link_of( int $product_id ): Link_Record {
		return $this->links()->find( $this->account_id, $product_id );
	}

	/**
	 * Returns the stock row of a product's link.
	 *
	 * @param int $product_id   Product id.
	 * @param int $warehouse_id Warehouse.
	 * @return array<string,mixed>|null
	 */
	protected function stock_row( int $product_id, int $warehouse_id = self::WAREHOUSE ): ?array {
		return $this->queue()->get( Sync_State_Queue::key( $this->account_id, (int) $this->link_of( $product_id )->link_id(), $warehouse_id, Sync_State_Store::KIND_STOCK ) );
	}

	/**
	 * Writes a line to the test log.
	 *
	 * @param string $line Line.
	 * @return void
	 */
	public function log_line( string $line ): void {
		$this->log[] = $line;
	}

	/**
	 * The account pause over the account store.
	 *
	 * @return Account_Pause
	 */
	protected function pause(): Account_Pause {
		return Account_Pause::over( $this->accounts(), $this->classifier, $this->clock() );
	}

	/**
	 * The job gate: the licence as the test set it, the pause over the account store, the plugin's write gate if any.
	 *
	 * @return Job_Gate
	 */
	protected function job_gate(): Job_Gate {
		$license = new License_Gate(
			function (): bool {
				return $this->licensed;
			}
		);

		return new Job_Gate( $this->accounts(), $license, $this->pause(), $this->write_gate );
	}

	/**
	 * Builds the flusher.
	 *
	 * @return Stock_Flusher
	 */
	protected function flusher(): Stock_Flusher {
		return new Stock_Flusher( $this->queue(), $this->intents(), $this->links(), $this->market, $this->market, $this->limits(), $this->classifier, $this->pause() );
	}

	/**
	 * Starts an account run over a claim token and the test clock.
	 *
	 * @param string $token  Claim token.
	 * @param int    $budget Requests the run may send.
	 * @return Account_Run
	 */
	protected function account_run( string $token, int $budget = Stock_Flusher::MAX_REQUESTS ): Account_Run {
		return new Account_Run(
			$this->account_now(),
			new Claim_Fence( $this->accounts()->table(), $token ),
			function () use ( $token ): bool {
				return $this->accounts()->renew_claim( $this->account_id, $token, Job_Gate::CLAIM_TTL );
			},
			$budget,
			Stock_Flusher::BUDGET_SECONDS,
			function (): float {
				return (float) $this->now;
			}
		);
	}

	/**
	 * Runs one flush as the account's sender: takes the account claim, flushes, releases.
	 *
	 * @param int $budget Requests the run may send.
	 * @return Stock_Flush_Result
	 */
	protected function flush( int $budget = Stock_Flusher::MAX_REQUESTS ): Stock_Flush_Result {
		$token = $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		$this->assertNotNull( $token, 'the account claim is free' );

		try {
			return $this->flusher()->flush( $this->account_run( (string) $token, $budget ) );
		} finally {
			$this->accounts()->release_claim( $this->account_id, (string) $token );
		}
	}

	/**
	 * Builds the flush job over the double.
	 *
	 * @param callable|null $price_half Price half.
	 * @param int           $budget     Requests per run.
	 * @return Flush_Sync_Runner
	 */
	protected function flush_runner( ?callable $price_half = null, int $budget = Stock_Flusher::MAX_REQUESTS ): Flush_Sync_Runner {
		return new Flush_Sync_Runner(
			$this->names(),
			$this->job_gate(),
			$this->flusher(),
			$this->accounts(),
			Sqlite_Tables::MARKETPLACE_ID,
			$this->limits(),
			[ $this, 'log_line' ],
			$price_half,
			$budget,
			function (): float {
				return (float) $this->now;
			}
		);
	}

	/**
	 * Runs the stock step of the daily reconcile over every product of the test, under the account claim.
	 *
	 * @return array<int,array<string,int>> Drift or mirrored entries by link id.
	 */
	protected function reconcile(): array {
		$token = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		try {
			$links = [];

			foreach ( array_keys( $this->products ) as $product_id ) {
				$link = $this->links()->find( $this->account_id, $product_id );

				if ( null !== $link ) {
					$links[] = $link;
				}
			}

			return $this->readback()->reconcile( $this->account_run( $token ), $links );
		} finally {
			$this->accounts()->release_claim( $this->account_id, $token );
		}
	}

	/**
	 * Builds the readback.
	 *
	 * @return Stock_Readback
	 */
	protected function readback(): Stock_Readback {
		return new Stock_Readback( $this->links(), $this->market, $this->intents(), $this->queue(), new Mirror_Stock_Writer(), $this->policy );
	}

	/**
	 * Moves a product to the trash, as `wp_trash_post()` does: the status changes, then the hook runs.
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	protected function trash( int $product_id ): void {
		$this->post_status[ $product_id ] = 'trash';

		if ( null !== $this->stock_hook ) {
			$this->stock_hook->on_removed( $product_id );
		}
	}

	/**
	 * Takes a product out of the trash again.
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	protected function untrash( int $product_id ): void {
		$this->post_status[ $product_id ] = 'publish';

		if ( null !== $this->stock_hook ) {
			$this->stock_hook->on_restored( $product_id );
		}
	}

	/**
	 * Returns the link id of a product.
	 *
	 * @param int $product_id Product id.
	 * @return int
	 */
	protected function link_id( int $product_id ): int {
		return (int) $this->link_of( $product_id )->link_id();
	}

	/**
	 * Builds the answer of a push in which the marketplace accepts the given products.
	 *
	 * @param int ...$product_ids Product ids.
	 * @return array<int,Item_Result> By link id.
	 */
	protected function accepted( int ...$product_ids ): array {
		$results = [];

		foreach ( $product_ids as $product_id ) {
			$link_id             = $this->link_id( $product_id );
			$results[ $link_id ] = Item_Result::success( $link_id );
		}

		return $results;
	}

	/**
	 * Builds the marketplace's refusal of one product's line.
	 *
	 * @param int      $product_id  Product id.
	 * @param string   $code        The marketplace's code.
	 * @param string   $message     The marketplace's text.
	 * @param int|null $retry_after Seconds the marketplace asked to wait.
	 * @return array<int,Item_Result> By link id.
	 */
	protected function refused( int $product_id, string $code, string $message = 'нет', ?int $retry_after = null ): array {
		$link_id = $this->link_id( $product_id );

		return [ $link_id => Item_Result::failure( $link_id, $code, $message, $retry_after ) ];
	}

	/**
	 * The price queue: at most one accepted send in 360 s, five attempts.
	 *
	 * @return Price_Sync_Queue
	 */
	protected function price_queue(): Price_Sync_Queue {
		return new Price_Sync_Queue(
			$this->db,
			Sqlite_Tables::table( 'sync_state' ),
			Sqlite_Tables::table( 'links' ),
			Sqlite_Tables::table( 'accounts' ),
			new Sync_Cadence( 360, 5 ),
			$this->clock()
		);
	}

	/**
	 * Builds the price half over a pusher.
	 *
	 * @param Price_Pusher $pusher Pusher.
	 * @return Price_Flush_Runner
	 */
	protected function price_runner( Price_Pusher $pusher ): Price_Flush_Runner {
		return new Price_Flush_Runner( $this->job_gate(), $this->accounts(), $this->price_queue(), $this->links(), $pusher, $this->limits(), $this->classifier, [ $this, 'log_line' ] );
	}

	/**
	 * Writes a pending price payload for a product's link, as the wish recorder does.
	 *
	 * @param int                       $product_id Product id.
	 * @param array<string,string|null> $payload    Payload; the default is 100.00 RUB with no old or minimum price.
	 * @return void
	 */
	protected function want_price( int $product_id, array $payload = [] ): void {
		$this->intents()->record(
			$this->account_id,
			$this->link_id( $product_id ),
			0,
			Sync_State_Store::KIND_PRICE,
			array_merge(
				[
					'price'         => '100.00',
					'old_price'     => '0',
					'min_price'     => null,
					'currency_code' => 'RUB',
				],
				$payload
			)
		);
	}

	/**
	 * Reads the price row of a product's link.
	 *
	 * @param int $product_id Product id.
	 * @return array<string,mixed>|null
	 */
	protected function price_row( int $product_id ): ?array {
		return $this->queue()->get( Sync_State_Queue::key( $this->account_id, $this->link_id( $product_id ), 0, Sync_State_Store::KIND_PRICE ) );
	}

	/**
	 * The registry of marketplace orders.
	 *
	 * @return Order_Registry
	 */
	protected function registry(): Order_Registry {
		return new Order_Registry( $this->db, Sqlite_Tables::table( 'postings' ), $this->clock() );
	}

	/**
	 * Builds the registry-only accounting. The baseline moment of an account is its `takeover_at` setting, the way a plugin
	 * with an earlier generation would supply it.
	 *
	 * @param callable|null          $checkpoint  Interleaving seam.
	 * @param Stock_Transaction|null $transaction Transaction; the real one when null.
	 * @return Registry_Stock_Accounting
	 */
	protected function accounting( ?callable $checkpoint = null, ?Stock_Transaction $transaction = null ): Registry_Stock_Accounting {
		$links = $this->links();

		return new Registry_Stock_Accounting(
			$this->registry(),
			static function ( int $account_id, string $item_id, string $offer_id ) use ( $links ): ?\WC_Product {
				$link    = $links->by_remote_id( $account_id, 'item', (int) $item_id );
				$link    = null === $link ? $links->by_seller_sku( $account_id, $offer_id ) : $link;
				$product = null === $link ? null : wc_get_product( $link->wc_product_id() );

				return $product instanceof \WC_Product ? $product : null;
			},
			$transaction ?? new Stock_Transaction( $this->db ),
			static function ( Order_DTO $order ): bool {
				return in_array( $order->status_canonical(), [ Status_Map::DELIVERED, Status_Map::CANCELLED ], true );
			},
			function ( int $account_id, string $group_key, int $delay ): void {
				$this->retries[] = [ $account_id, $group_key, $delay ];
			},
			function ( int $account_id, string $message ): void {
				$this->log[] = $message;
			},
			'item',
			static function ( Account $account ): int {
				return (int) $account->setting( 'takeover_at', 0 );
			},
			$checkpoint
		);
	}

	/**
	 * Builds a marketplace order of the account.
	 *
	 * @param string                                $number Order number.
	 * @param string                                $status Canonical status.
	 * @param array<string,array{0:int,1:int}>      $lines  Article => `[ the marketplace's item id, quantity ]`.
	 * @param array<string,string>                  $dates  ISO 8601 dates.
	 * @return Order_DTO
	 */
	protected function market_order( string $number, string $status, array $lines, array $dates = [] ): Order_DTO {
		$order_lines = [];

		foreach ( $lines as $offer => $line ) {
			$order_lines[] = new Order_Line( (string) $offer, [ 'item' => $line[0] ], $line[1], Money::from_string( '100.00', 'RUB' ) );
		}

		return new Order_DTO( $this->account_id, $number, '', $status, $status, $order_lines, [], null, $dates );
	}

	/**
	 * Arms the database double: the statement after the first one that matches the pattern throws, as a process that dies there.
	 *
	 * @param string $pattern Pattern of the statement the process survives.
	 * @param string $message Message of the death.
	 * @return void
	 */
	protected function die_after( string $pattern, string $message ): void {
		$this->db->before_query_pattern = $pattern;
		$this->db->before_query         = function () use ( $message ): void {
			$this->db->before_query_pattern = '/./';
			$this->db->before_query         = static function () use ( $message ): void {
				throw new \RuntimeException( $message );
			};
		};
	}

	/**
	 * Arms the database double: the first statement that matches the pattern throws.
	 *
	 * @param string $pattern Pattern of the statement.
	 * @param string $message Message of the death.
	 * @return void
	 */
	protected function die_at( string $pattern, string $message ): void {
		$this->db->before_query_pattern = $pattern;
		$this->db->before_query         = static function () use ( $message ): void {
			throw new \RuntimeException( $message );
		};
	}
}
