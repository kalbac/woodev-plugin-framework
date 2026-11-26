<?php
/**
 * Harness of the shipment page tests.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shipments;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Row_Normalizer;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Runner;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Seller;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Shipments;
use Woodev\Framework\Marketplace\Admin\Pages\Shipments\Shipments_Data_Status;
use Woodev\Framework\Marketplace\Admin\Pages\Shipments\Shipments_Ids;
use Woodev\Framework\Marketplace\Admin\Pages\Shipments\Shipments_Provider;
use Woodev\Framework\Marketplace\Admin\Pages\Shipments\Shipments_Rows;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Availability;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate;
use Woodev\Framework\Marketplace\Data\Wpdb_Seller_Action_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store;
use Woodev\Framework\Marketplace\Data\Uninstaller;
use Woodev\Framework\Marketplace\Flows\Shipment_Service;
use Woodev\Framework\Marketplace\Flows\Shipment_Snapshot;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Shipment_Jobs;
use Woodev\Framework\Marketplace\Settings\Shipment_Noun;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Seller_Adapter;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Shipment_Marketplace;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Meta;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * The shipment tables on SQLite, a scripted marketplace, the order doubles 101–104 (registered in the order registry as `P-101` …
 * and carrying the account's owner stamp) and the services wired the way the module wires them.
 */
abstract class Shipments_Test_Case extends Sync_Test_Case {
	/**
	 * The stamp the account puts on its orders (the `cabinet_ref` credential of the shared account).
	 */
	protected const OWNER = 'cabinet-4417';

	/**
	 * @var Fake_Shipment_Marketplace
	 */
	protected $marketplace;

	/**
	 * @var Fake_Order_Meta
	 */
	protected $meta;

	/**
	 * Order doubles by id.
	 *
	 * @var array<int,\Mockery\MockInterface>
	 */
	protected $wc_orders = [];

	/**
	 * Order id => its units for the seller actions, `[ id, label, status, action ids or null ]`.
	 *
	 * @var array<int,array<int,array<int,mixed>>>
	 */
	protected $units = [];

	/**
	 * The options, by name.
	 *
	 * @var array<string,mixed>
	 */
	protected $options = [];

	/**
	 * Orders the user may not edit.
	 *
	 * @var int[]
	 */
	protected $forbidden = [];

	/**
	 * The adapter's seller actions; null for none.
	 *
	 * @var Fake_Seller_Adapter|null
	 */
	protected $seller;

	protected function setUp(): void {
		parent::setUp();

		$this->marketplace = new Fake_Shipment_Marketplace();
		$this->meta        = new Fake_Order_Meta();
		$this->wc_orders   = [];
		$this->units       = [];
		$this->options     = [];
		$this->forbidden   = [];
		$this->seller      = null;

		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return $this->options[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );

				return true;
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, ?int $time = null ): string {
				return gmdate( $format, $time ?? self::NOW );
			}
		);
		foreach ( [ 'home_url', 'site_url' ] as $function ) {
			Functions\when( $function )->justReturn( 'https://shop.test' );
		}
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) {
				return $this->wc_orders[ (int) $id ] ?? false;
			}
		);

		foreach ( [ 101, 102, 103, 104 ] as $id ) {
			$this->add_order( $id );
		}
	}

	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * @param int $id Order id.
	 * @return void
	 */
	protected function add_order( int $id ): void {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'get_order_number' )->andReturn( (string) $id );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://shop.test/wp-admin/post.php?post=' . $id . '&action=edit' );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( $key ) use ( $id ) {
				return Fake_Seller_Adapter::META_UNITS === $key ? ( $this->units[ $id ] ?? [] ) : '';
			}
		);
		$order->shouldReceive( 'add_order_note' )->andReturn( 1 );

		$this->wc_orders[ $id ]    = $order;
		$this->meta->owners[ $id ] = self::OWNER;

		$this->db->insert(
			Sqlite_Tables::table( 'postings' ),
			[
				'account_id'    => $this->account_id,
				'external_key'  => 'P-' . $id,
				'wc_order_id'   => $id,
				'first_seen_at' => gmdate( 'Y-m-d H:i:s', self::NOW ),
			]
		);
	}

	protected function shop_store(): Wpdb_Shipment_Store {
		return new Wpdb_Shipment_Store( $this->db, Sqlite_Tables::table( 'shipments' ), Sqlite_Tables::table( 'shipment_units' ), Uninstaller::option_prefix( self::PLUGIN_ID ), $this->clock() );
	}

	protected function snapshot(): Shipment_Snapshot {
		return Shipment_Snapshot::over( $this->marketplace, $this->shop_store(), $this->registry() );
	}

	protected function license(): License_Gate {
		return new License_Gate(
			function (): bool {
				return $this->licensed;
			}
		);
	}

	protected function shipment_jobs(): Shipment_Jobs {
		return new Shipment_Jobs( $this->job_gate(), $this->accounts(), $this->snapshot(), $this->shop_store(), $this->classifier, $this->names(), Sqlite_Tables::MARKETPLACE_ID, [ $this, 'log_line' ] );
	}

	protected function noun(): Shipment_Noun {
		return new Shipment_Noun(
			[
				'title'     => 'Поставки',
				'one'       => 'поставка',
				'of_one'    => 'поставки',
				'to_one'    => 'поставке',
				'acc'       => 'поставку',
				'many'      => 'поставки',
				'of_many'   => 'поставок',
				'place'     => 'грузовое место',
				'places'    => 'грузовые места',
				'of_places' => 'грузовых мест',
			]
		);
	}

	protected function shipment_service(): Shipment_Service {
		return new Shipment_Service(
			$this->marketplace,
			$this->shop_store(),
			$this->snapshot(),
			new Posting_Action_Gate( new Label_Availability( null ), null, $this->write_gate ),
			$this->license(),
			$this->classifier,
			$this->pause(),
			$this->noun(),
			function ( int $account_id ): void {
				$this->shipment_jobs()->queue_refresh( $account_id );
			},
			new Wpdb_Seller_Action_Store( $this->db, Sqlite_Tables::table( 'seller_actions' ), $this->clock() ),
			[ $this, 'log_line' ]
		);
	}

	protected function seller_part(): ?Site_Orders_Seller {
		if ( null === $this->seller ) {
			return null;
		}

		return new Site_Orders_Seller(
			new Seller_Action_Runner(
				$this->seller,
				new Posting_Action_Gate( new Label_Availability( null ), null, $this->write_gate, null, $this->seller ),
				$this->license(),
				'Фейк',
				new Wpdb_Seller_Action_Store( $this->db, Sqlite_Tables::table( 'seller_actions' ), $this->clock() ),
				[ $this, 'log_line' ]
			)
		);
	}

	protected function can_edit(): callable {
		return function ( int $order_id ): bool {
			return ! in_array( $order_id, $this->forbidden, true );
		};
	}

	protected function provider(): Shipments_Provider {
		$store   = $this->shop_store();
		$service = $this->shipment_service();
		$seller  = $this->seller_part();

		return new Shipments_Provider(
			$this->accounts(),
			$this->marketplace,
			$store,
			$service,
			new Shipments_Rows( $store, $this->marketplace, $service, $this->noun(), $seller, $this->can_edit() ),
			$this->noun(),
			Sqlite_Tables::MARKETPLACE_ID,
			$this->meta,
			$this->can_edit(),
			function ( int $account_id ): void {
				$this->shipment_jobs()->queue_refresh( $account_id );
			},
			static function ( int $account_id, string $key, string $document ): string {
				return 'https://shop.test/wp-admin/admin-post.php?action=doc&account_id=' . $account_id . '&shipment=' . $key . '&document=' . $document;
			},
			new Shipments_Data_Status(
				$store,
				static function () {
					return false;
				}
			),
			$seller,
			[ $this, 'log_line' ]
		);
	}

	protected function orders_action(): Site_Orders_Shipments {
		return new Site_Orders_Shipments( $this->shipment_service(), $this->shop_store(), $this->noun() );
	}

	protected function account(): Account {
		return $this->account_now();
	}

	/**
	 * @return array<string,string>
	 */
	protected function context(): array {
		return [ 'account_id' => (string) $this->account_id ];
	}

	protected function definition(): Records_Definition {
		return Records_Definition::from_array( $this->provider()->get_definition( $this->context() ) );
	}

	/**
	 * @param array<string,mixed> $query Query as the engine validates it.
	 * @return array<string,mixed> The list body, as the engine would send it.
	 */
	protected function list_body( array $query = [] ): array {
		$result = $this->provider()->query( $this->context(), array_merge( [ 'page' => 1, 'per_page' => 20 ], $query ) );
		$body   = Row_Normalizer::page( $this->definition(), $result, 20 );

		$this->assertIsArray( $body, 'The engine accepts the list.' );

		return $body;
	}

	/**
	 * @param string $shipment_id The marketplace's shipment id.
	 * @param string $unit_id     The marketplace's unit id; empty for the shipment's row.
	 * @return string
	 */
	protected function row_id( string $shipment_id, string $unit_id = '' ): string {
		return Shipments_Ids::row( $this->account_id, Wpdb_Shipment_Store::key( $shipment_id ), '' === $unit_id ? '' : Wpdb_Shipment_Store::key( $unit_id ) );
	}

	/**
	 * @param string $shipment_id Shipment id.
	 * @return array<int,array<string,mixed>> Child rows, as the engine would send them.
	 */
	protected function child_rows( string $shipment_id ): array {
		$result = $this->provider()->get_children( $this->context(), $this->row_id( $shipment_id ), [ 'page' => 1, 'per_page' => 50 ] );
		$body   = Row_Normalizer::children_page( $this->definition(), $result, 50 );

		$this->assertIsArray( $body, 'The engine accepts the children.' );

		return $body['rows'];
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param string                         $id   Row id.
	 * @return array<string,array<string,mixed>> The row's actions by id.
	 */
	protected function actions_of( array $rows, string $id ): array {
		return array_column( array_column( $rows, null, 'id' )[ $id ]['actions'], null, 'id' );
	}

	/**
	 * Reads the marketplace into the shop's copy the way the refresh job does.
	 *
	 * @return void
	 */
	protected function refresh(): void {
		$this->shipment_jobs()->refresh( $this->account_id );
	}
}
