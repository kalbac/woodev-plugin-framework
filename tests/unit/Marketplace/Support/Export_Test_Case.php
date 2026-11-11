<?php
/**
 * Harness of the export flow tests: stores on SQLite, a fake marketplace, scripted gateway and lifecycle.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Flows\Export_Context_Builder;
use Woodev\Framework\Marketplace\Flows\Export_Service;
use Woodev\Framework\Marketplace\Flows\Link_Context_Resolver;
use Woodev\Framework\Marketplace\Jobs\Export_Batch_Runner;
use Woodev\Framework\Marketplace\Jobs\Export_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Poll_Task_Runner;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Export_Facts;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Card_Lifecycle;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Export_Gateway;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Rule;

/**
 * One account (RUB, enabled), one term-bound mapping on term 50, product doubles keyed by id, and the fake marketplace's export
 * answers in public properties the tests script. Time is `$this->now` for every store and runner.
 */
abstract class Export_Test_Case extends Sync_Test_Case {
	protected const TERM = 50;

	/**
	 * The fake marketplace.
	 *
	 * @var Fake_Marketplace_Adapter
	 */
	protected $adapter;

	/**
	 * Local export facts.
	 *
	 * @var Fake_Export_Facts
	 */
	protected $facts;

	/**
	 * Card builder and import request.
	 *
	 * @var Scripted_Export_Gateway
	 */
	protected $gateway;

	/**
	 * Import result and card state.
	 *
	 * @var Scripted_Card_Lifecycle
	 */
	protected $lifecycle;

	/**
	 * The validation rule the tests script.
	 *
	 * @var Scripted_Rule
	 */
	protected $rule;

	/**
	 * Mapping bound to the term.
	 *
	 * @var int
	 */
	protected $mapping_id;

	/**
	 * SKUs by product id; a test changes one to change the product's SKU.
	 *
	 * @var array<int,string>
	 */
	protected $skus = [];

	/**
	 * Card-created calls: `[ link_id, account_id ]`.
	 *
	 * @var array<int,array{0:int,1:int}>
	 */
	protected $created = [];

	/**
	 * When set, seeding throws it.
	 *
	 * @var \Throwable|null
	 */
	protected $seed_failure;

	/**
	 * Links the seeding saw: link ids.
	 *
	 * @var int[]
	 */
	protected $seeded = [];

	/**
	 * Builds the harness.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->rule         = new Scripted_Rule();
		$this->adapter      = new Fake_Marketplace_Adapter( null, [ 'export_rules' => [ $this->rule ] ] );
		$this->facts        = $this->adapter->export_facts();
		$this->gateway      = $this->adapter->export_gateway();
		$this->lifecycle    = $this->adapter->card_lifecycle();
		$this->created      = [];
		$this->skus         = [];
		$this->seeded       = [];
		$this->seed_failure = null;
		$this->mapping_id   = $this->mappings()->create( 'Блокноты', null, [ 'category_ref' => 'c-1' ], null, [] );
		$this->mappings()->link_term_if_absent( self::TERM, $this->mapping_id );

		Functions\when( 'get_woocommerce_currency' )->justReturn( 'RUB' );
	}

	/**
	 * Builds a product double and registers it.
	 *
	 * @param array<string,mixed> $props Getter overrides: `id`, `parent_id`, `sku`, `price`, `regular_price`, `virtual`,
	 *                                   `downloadable`, `children`, `type`.
	 * @return \WC_Product
	 */
	protected function add_product( array $props = [] ): \WC_Product {
		$props = array_merge(
			[
				'id'            => 0,
				'parent_id'     => 0,
				'sku'           => '',
				'price'         => '1000',
				'regular_price' => '1000',
				'virtual'       => false,
				'downloadable'  => false,
				'children'      => [],
				'type'          => 'simple',
			],
			$props
		);

		$id = 0 === $props['id'] ? count( $this->products ) + 201 : (int) $props['id'];

		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( $id );
		$product->shouldReceive( 'get_parent_id' )->andReturn( (int) $props['parent_id'] );
		$this->skus[ $id ] = (string) ( '' === $props['sku'] ? 'SKU-' . $id : $props['sku'] );
		$product->shouldReceive( 'get_sku' )->andReturnUsing(
			function () use ( $id ): string {
				return $this->skus[ $id ];
			}
		);
		$product->shouldReceive( 'get_name' )->andReturn( 'Товар ' . $id );
		$product->shouldReceive( 'get_price' )->andReturn( $props['price'] );
		$product->shouldReceive( 'get_regular_price' )->andReturn( $props['regular_price'] );
		$product->shouldReceive( 'is_virtual' )->andReturn( (bool) $props['virtual'] );
		$product->shouldReceive( 'is_downloadable' )->andReturn( (bool) $props['downloadable'] );
		$product->shouldReceive( 'get_children' )->andReturn( $props['children'] );
		$product->shouldReceive( 'is_type' )->andReturnUsing(
			static function ( $type ) use ( $props ): bool {
				return $props['type'] === $type;
			}
		);
		$product->shouldReceive( 'get_meta' )->andReturn( '' );

		$this->products[ $id ] = $product;

		return $product;
	}

	/**
	 * Inserts an offer row for a product.
	 *
	 * @param \WC_Product         $product Product.
	 * @param string              $state   Card state.
	 * @param array<string,mixed> $columns More columns.
	 * @return Link_Record
	 */
	protected function add_link( \WC_Product $product, string $state = Link_Record::STATE_UNLINKED, array $columns = [] ): Link_Record {
		$this->links()->insert_if_absent(
			Link_Record::offer(
				$this->account_id,
				(int) $product->get_id(),
				(int) $product->get_parent_id(),
				(string) $product->get_sku(),
				array_merge(
					[
						'card_state' => $state,
						'sync_stock' => 1,
						'sync_price' => 1,
					],
					$columns
				)
			)
		);

		return $this->link_of( (int) $product->get_id() );
	}

	/**
	 * Returns the task a link belongs to.
	 *
	 * @param Link_Record $link Link.
	 * @return int|null
	 */
	protected function task_of( Link_Record $link ): ?int {
		$task = $this->db->get_var( 'SELECT last_task_id FROM ' . Sqlite_Tables::table( 'links' ) . ' WHERE link_id = ' . (int) $link->link_id() );

		return null === $task || (int) $task <= 0 ? null : (int) $task;
	}

	/**
	 * Mappings.
	 *
	 * @return Wpdb_Mapping_Store
	 */
	protected function mappings(): Wpdb_Mapping_Store {
		return new Wpdb_Mapping_Store( $this->db, Sqlite_Tables::table( 'mappings' ), Sqlite_Tables::table( 'mapping_terms' ), $this->clock() );
	}

	/**
	 * Profiles.
	 *
	 * @return Wpdb_Profile_Store
	 */
	protected function profiles(): Wpdb_Profile_Store {
		return new Wpdb_Profile_Store( $this->db, Sqlite_Tables::table( 'profiles' ), 'woodev_fake_price_changed' );
	}

	/**
	 * Task ledger.
	 *
	 * @return Wpdb_Task_Ledger
	 */
	protected function ledger(): Wpdb_Task_Ledger {
		return new Wpdb_Task_Ledger( $this->db, Sqlite_Tables::table( 'tasks' ), Sqlite_Tables::table( 'task_items' ), $this->clock() );
	}

	/**
	 * Error ring.
	 *
	 * @return Api_Error_Ring
	 */
	protected function ring(): Api_Error_Ring {
		return new Api_Error_Ring( $this->db, Sqlite_Tables::table( 'api_errors' ), $this->clock() );
	}

	/**
	 * Export scheduler.
	 *
	 * @return Export_Scheduler
	 */
	protected function export_scheduler(): Export_Scheduler {
		return new Export_Scheduler( $this->names() );
	}

	/**
	 * Validator with the scripted rule.
	 *
	 * @return Export_Validator
	 */
	protected function validator(): Export_Validator {
		return new Export_Validator( $this->adapter->export_rules() );
	}

	/**
	 * Export inputs (and the price answers of the sync).
	 *
	 * @return Export_Context_Builder
	 */
	protected function contexts(): Export_Context_Builder {
		return new Export_Context_Builder(
			new Link_Context_Resolver( $this->links(), $this->mappings() ),
			$this->profiles(),
			$this->links(),
			$this->facts,
			$this->adapter->article_policy(),
			new Pinned_Offer_Id( Sqlite_Tables::MARKETPLACE_ID, $this->adapter->article_policy() ),
			$this->adapter->price_source(),
			static function (): array {
				return [ self::TERM ];
			},
			null,
			$this->ledger()
		);
	}

	/**
	 * The request-time service.
	 *
	 * @return Export_Service
	 */
	protected function service(): Export_Service {
		return new Export_Service( $this->links(), $this->contexts(), $this->export_scheduler(), $this->facts, $this->validator() );
	}

	/**
	 * The `export_batch` job.
	 *
	 * @return Export_Batch_Runner
	 */
	protected function export_runner(): Export_Batch_Runner {
		return new Export_Batch_Runner( $this->job_gate(), $this->links(), $this->ledger(), $this->contexts(), $this->validator(), $this->gateway, $this->export_scheduler(), $this->classifier, $this->ring(), [ $this, 'log_line' ] );
	}

	/**
	 * The `poll_task` job.
	 *
	 * @return Poll_Task_Runner
	 */
	protected function poll_runner(): Poll_Task_Runner {
		return new Poll_Task_Runner(
			$this->job_gate(),
			$this->links(),
			$this->ledger(),
			$this->export_scheduler(),
			$this->lifecycle,
			$this->classifier,
			function ( Account $account, Link_Record $link ): void {
				if ( null !== $this->seed_failure ) {
					throw $this->seed_failure;
				}

				$this->seeded[] = (int) $link->link_id();
			},
			function ( int $link_id, int $account_id ): void {
				$this->created[] = [ $link_id, $account_id ];
			},
			$this->ring(),
			[ $this, 'log_line' ],
			$this->clock()
		);
	}
}
