<?php
/**
 * The wiring of the marketplace flows: everything hooked once, per plugin id, with the licence gate in front.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Flow_Wiring;
use Woodev\Framework\Marketplace\Jobs\Event_Handler_Registry;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Posting_Jobs;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Tests\Unit\Marketplace\Admin\Test_Marketplace_Plugin;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Action_Scheduler;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Price_Reader;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Push_Protocol;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Inbound_Decoder;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Admin/Test_Marketplace_Plugin.php';

/**
 * A plugin on the framework's own table store, which is what the wiring needs.
 */
final class Wired_Test_Plugin extends Test_Marketplace_Plugin {
	/** @var Wpdb_Account_Store|null */
	public $store;

	/** @return Account_Store */
	protected function get_account_store(): Account_Store {
		return $this->store ?? parent::get_account_store();
	}

	/** @var callable|null */
	public $write_gate;

	/** @return callable|null */
	protected function account_write_gate(): ?callable {
		return $this->write_gate;
	}
}

/**
 * @covers \Woodev\Framework\Marketplace\Flow_Wiring
 * @covers \Woodev\Framework\Marketplace\Marketplace_Plugin::register_marketplace_flows
 */
final class FlowWiringTest extends TestCase {

	private const PLUGIN = 'fake-seller';
	private const OTHER  = 'other-seller';

	/**
	 * Hooks one registration adds, with how many callbacks each gets. `poll_task` is shared by design: the export poll and the
	 * label poll each answer their own kind of task.
	 *
	 * @var array<string,int>
	 */
	private const ACTIONS = [
		'woodev_%s_flush_sync'                    => 1,
		'woodev_%s_recompute_prices'              => 1,
		'woodev_%s_refresh_prices'                => 1,
		'woodev_%s_export_batch'                  => 1,
		'woodev_%s_poll_task'                     => 2,
		'woodev_%s_link_cards'                    => 1,
		'woodev_%s_refresh_cards'                 => 1,
		'woodev_%s_refresh_catalog'               => 1,
		'woodev_%s_refresh_posting'               => 1,
		'woodev_%s_poll_postings'                 => 1,
		'woodev_%s_prune_labels'                  => 1,
		'woodev_%s_drain_events'                  => 1,
		'woodev_%s_drain_events_sweep'            => 1,
		'admin_post_woodev_%s_label'              => 1,
		'admin_post_nopriv_woodev_%s_label'       => 1,
		'woocommerce_product_set_stock'           => 1,
		'woocommerce_variation_set_stock'         => 1,
		'wp_trash_post'                           => 1,
		'before_delete_post'                      => 1,
		'untrashed_post'                          => 1,
		'woocommerce_product_object_updated_props' => 1,
		'rest_api_init'                           => 1,
	];

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Licence answer.
	 *
	 * @var bool
	 */
	private $licensed = true;

	/**
	 * Lines logged.
	 *
	 * @var string[]
	 */
	private $log = [];

	/**
	 * Fake Action Scheduler.
	 *
	 * @var Fake_Action_Scheduler
	 */
	private $scheduler;

	protected function setUp(): void {
		parent::setUp();

		Flow_Wiring::forget();

		$this->licensed  = true;
		$this->log       = [];
		$this->db        = new Sqlite_Wpdb();
		$this->scheduler = Fake_Action_Scheduler::install();

		foreach ( ( new Schema( 'fake_wildberries' ) )->statements( 'wp_', '' ) as $statement ) {
			$this->db->define( Sqlite_Tables::to_sqlite( $statement ) );
		}
	}

	/**
	 * A marketplace that offers everything: push, labels, exporting, a price reader.
	 *
	 * @param array<string,mixed> $policies Answers that replace the fake's.
	 * @return Fake_Marketplace_Adapter
	 */
	private function adapter( array $policies = [] ): Fake_Marketplace_Adapter {
		return new Fake_Marketplace_Adapter(
			null,
			array_merge(
				[
					'capabilities'    => [ Capability::IMPORT_PRODUCTS, Capability::GET_LABEL, Capability::PUSH_NOTIFICATIONS ],
					'inbound_decoder' => new Scripted_Inbound_Decoder(),
					'push_protocol'   => new Fake_Push_Protocol(),
					'price_reader'    => new Fake_Price_Reader(),
				],
				$policies
			)
		);
	}

	private function wiring( string $plugin_id, ?Fake_Marketplace_Adapter $adapter = null, ?callable $write_gate = null ): Flow_Wiring {
		$adapter  = $adapter ?? $this->adapter();
		$accounts = Wpdb_Account_Store::for_adapter( $this->db, ( new Schema( $adapter->id() ) )->table_name( 'accounts', 'wp_' ), new Secret_Cipher( 'test-salt' ), $adapter );

		return new Flow_Wiring(
			$plugin_id,
			$adapter,
			$accounts,
			$this->db,
			'wp_',
			new License_Gate(
				function (): bool {
					return $this->licensed;
				}
			),
			function ( string $line ): void {
				$this->log[] = $line;
			},
			$write_gate
		);
	}

	/**
	 * Expects every hook of the table above to be added as often as it says, for one plugin id.
	 *
	 * @param string $plugin_id Plugin id in snake case, as the hooks are named.
	 */
	private function expect_every_hook_once( string $plugin_id ): void {
		foreach ( self::ACTIONS as $hook => $times ) {
			if ( 0 === $times ) {
				continue;
			}

			$name = false === strpos( $hook, '%s' ) ? $hook : sprintf( $hook, $plugin_id );

			Actions\expectAdded( $name )->times( $times );
		}

		// The schedule checks: stock and price sender, posting poll, label prune, export sweep, weekly catalogue refresh.
		Actions\expectAdded( 'action_scheduler_init' )->times( 5 );
		// A changed price rule is named after the adapter, not the plugin.
		Actions\expectAdded( 'woodev_fake_wildberries_profile_price_changed' )->once();
		Filters\expectAdded( 'rest_pre_dispatch' )->once();
	}

	public function test_every_flow_is_hooked_exactly_once(): void {
		$this->expect_every_hook_once( 'fake_seller' );

		$this->assertTrue( $this->wiring( self::PLUGIN )->register() );
	}

	public function test_a_second_call_adds_no_hook_no_schedule_and_no_route(): void {
		$this->expect_every_hook_once( 'fake_seller' );

		$wiring = $this->wiring( self::PLUGIN );

		$this->assertTrue( $wiring->register() );
		$this->assertFalse( $wiring->register(), 'the same object, again' );
		$this->assertFalse( $this->wiring( self::PLUGIN )->register(), 'another object of the same plugin id' );
		$this->assertSame( [], $this->scheduler->actions, 'registering creates no schedule: the runners do that on action_scheduler_init' );
	}

	public function test_two_plugins_on_one_framework_copy_share_no_hook_and_no_route(): void {
		$adapter_a = $this->adapter();

		Actions\expectAdded( 'woodev_fake_seller_flush_sync' )->once();
		Actions\expectAdded( 'woodev_other_seller_flush_sync' )->once();
		Actions\expectAdded( 'woodev_fake_seller_drain_events' )->once();
		Actions\expectAdded( 'woodev_other_seller_drain_events' )->once();
		Actions\expectAdded( 'woodev_fake_seller_poll_postings' )->once();
		Actions\expectAdded( 'woodev_other_seller_poll_postings' )->once();

		$this->assertTrue( $this->wiring( self::PLUGIN, $adapter_a )->register() );
		$this->assertTrue( $this->wiring( self::OTHER, $this->adapter() )->register() );
		$this->assertFalse( $this->wiring( self::OTHER, $this->adapter() )->register() );
	}

	public function test_ids_that_differ_only_in_dashes_and_underscores_are_one_plugin(): void {
		Actions\expectAdded( 'woodev_fake_seller_flush_sync' )->once();

		$this->assertTrue( $this->wiring( 'fake-seller' )->register() );
		$this->assertFalse( $this->wiring( 'fake_seller' )->register(), 'same hooks, same group: not a second plugin' );
	}

	public function test_the_posting_poll_reads_the_option_of_the_unnormalised_plugin_id(): void {
		$this->assertSame( 'woodev_fake-seller_poll_postings_minutes', Posting_Jobs::option_key( self::PLUGIN ) );

		$this->expect_every_hook_once( 'fake_seller' );
		$this->wiring( self::PLUGIN )->register();
	}

	public function test_a_flow_the_adapter_cannot_serve_is_left_out(): void {
		$bare = $this->adapter(
			[
				'capabilities'    => [],
				'inbound_decoder' => null,
				'push_protocol'   => null,
				'price_reader'    => null,
				'label_provider'  => null,
				'posting_handler' => null,
			]
		);

		Actions\expectAdded( 'woodev_fake_seller_flush_sync' )->once();
		Actions\expectAdded( 'woodev_fake_seller_drain_events' )->never();
		Actions\expectAdded( 'woodev_fake_seller_export_batch' )->never();
		Actions\expectAdded( 'woodev_fake_seller_link_cards' )->never();
		Actions\expectAdded( 'woodev_fake_seller_refresh_catalog' )->never();
		Actions\expectAdded( 'woodev_fake_seller_refresh_prices' )->never();
		Actions\expectAdded( 'woodev_fake_seller_poll_postings' )->never();
		Actions\expectAdded( 'woodev_fake_seller_prune_labels' )->never();
		Actions\expectAdded( 'rest_api_init' )->never();

		$this->assertTrue( $this->wiring( self::PLUGIN, $bare )->register() );
	}

	public function test_the_plugin_adds_its_event_handlers_before_the_drain_is_hooked(): void {
		$seen = null;

		Actions\expectDone( 'woodev_fake_seller_event_handlers' )->once()->whenHappen(
			static function ( $registry ) use ( &$seen ): void {
				$seen = $registry;
			}
		);

		$this->wiring( self::PLUGIN )->register();

		$this->assertInstanceOf( Event_Handler_Registry::class, $seen );
	}

	public function test_registering_makes_no_remote_call_and_reads_no_table(): void {
		$this->wiring( self::PLUGIN )->register();

		$this->assertSame( [], $this->db->statements, 'no query' );
		$this->assertSame( [], $this->scheduler->actions, 'nothing scheduled' );
		$this->assertSame( [], $this->log );
	}

	public function test_a_wired_sender_asks_the_licence_before_it_claims_the_account(): void {
		$flush = null;

		Actions\expectAdded( 'woodev_fake_seller_flush_sync' )->once()->whenHappen(
			static function ( $callback ) use ( &$flush ): void {
				$flush = $callback;
			}
		);

		$this->wiring( self::PLUGIN )->register();

		$adapter    = $this->adapter();
		$accounts   = Wpdb_Account_Store::for_adapter( $this->db, ( new Schema( 'fake_wildberries' ) )->table_name( 'accounts', 'wp_' ), new Secret_Cipher( 'test-salt' ), $adapter );
		$account_id = $accounts->save( new Account( 0, 'fake_wildberries', 'Основной', true, [ 'cabinet' => '4417', 'token' => 'tok-1' ] ) );

		$this->assertIsCallable( $flush );

		$this->licensed = false;
		$this->db->statements = [];
		$flush( $account_id );

		$this->assertSame( [], $this->claims(), 'the lapsed licence ends the run before the account row is claimed' );

		$this->licensed = true;
		$flush( $account_id );

		$this->assertNotSame( [], $this->claims(), 'with the licence in force the same run takes the claim' );
	}

	/**
	 * The statements that touch the account row's claim.
	 *
	 * @return string[]
	 */
	private function claims(): array {
		return array_values(
			array_filter(
				$this->db->statements,
				static function ( string $statement ): bool {
					return false !== stripos( $statement, 'claim_token' ) && 0 === stripos( ltrim( $statement ), 'UPDATE' );
				}
			)
		);
	}

	public function test_a_write_gate_that_refuses_an_account_stops_its_flush_before_the_claim(): void {
		$flush = null;
		$allow = false;
		$asked = [];

		Actions\expectAdded( 'woodev_fake_seller_flush_sync' )->once()->whenHappen(
			static function ( $callback ) use ( &$flush ): void {
				$flush = $callback;
			}
		);

		$this->wiring(
			self::PLUGIN,
			null,
			function ( Account $account ) use ( &$allow, &$asked ): bool {
				$asked[] = $account->id();

				return $allow;
			}
		)->register();

		$adapter    = $this->adapter();
		$accounts   = Wpdb_Account_Store::for_adapter( $this->db, ( new Schema( 'fake_wildberries' ) )->table_name( 'accounts', 'wp_' ), new Secret_Cipher( 'test-salt' ), $adapter );
		$account_id = $accounts->save( new Account( 0, 'fake_wildberries', 'Основной', true, [ 'cabinet' => '4417', 'token' => 'tok-1' ] ) );

		$this->assertIsCallable( $flush );

		$this->db->statements = [];
		$flush( $account_id );

		$this->assertContains( $account_id, $asked, 'the flush asks the write gate about the account' );
		$this->assertSame( [], $this->claims(), 'a refused account is not claimed, so nothing is sent' );

		$allow = true;
		$flush( $account_id );

		$this->assertNotSame( [], $this->claims(), 'once the gate lets the account write, the same run takes the claim' );
	}

	public function test_the_plugins_write_gate_reaches_the_flush_job_gate(): void {
		global $wpdb;

		$wpdb   = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A test double of WordPress's database.
		$plugin = new Wired_Test_Plugin( self::PLUGIN, [ 'id' => 'fake-seller', 'title' => 'Fake', 'icon' => '', 'position' => '56' ] );
		$plugin->store = Wpdb_Account_Store::for_adapter( $this->db, ( new Schema( 'fake_wildberries' ) )->table_name( 'accounts', 'wp_' ), new Secret_Cipher( 'test-salt' ), $plugin->get_marketplace_adapter() );
		$plugin->write_gate = static function (): bool {
			return false;
		};

		$flush = null;

		Actions\expectAdded( 'woodev_fake_seller_flush_sync' )->once()->whenHappen(
			static function ( $callback ) use ( &$flush ): void {
				$flush = $callback;
			}
		);

		$plugin->register_marketplace_flows();

		$account_id = $plugin->store->save( new Account( 0, 'fake_wildberries', 'Основной', true, [ 'cabinet' => '4417', 'token' => 'tok-1' ] ) );

		$this->assertIsCallable( $flush );

		$this->db->statements = [];
		$flush( $account_id );

		$this->assertSame( [], $this->claims(), 'the plugin\'s refusing write gate reaches the flush: no claim, nothing sent' );
	}

	public function test_the_plugin_wires_its_flows_once_however_often_it_is_asked(): void {
		global $wpdb;

		$wpdb   = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A test double of WordPress's database.
		$plugin = new Wired_Test_Plugin( self::PLUGIN, [ 'id' => 'fake-seller', 'title' => 'Fake', 'icon' => '', 'position' => '56' ] );
		$plugin->store = Wpdb_Account_Store::for_adapter( $this->db, ( new Schema( 'fake_wildberries' ) )->table_name( 'accounts', 'wp_' ), new Secret_Cipher( 'test-salt' ), $plugin->get_marketplace_adapter() );

		Actions\expectAdded( 'woodev_fake_seller_flush_sync' )->once();

		$plugin->register_marketplace_flows();
		$plugin->register_marketplace_flows();
	}

	public function test_a_plugin_with_another_kind_of_account_store_runs_no_flow(): void {
		$plugin = new Test_Marketplace_Plugin( self::PLUGIN, [ 'id' => 'fake-seller', 'title' => 'Fake', 'icon' => '', 'position' => '56' ] );

		Actions\expectAdded( 'woodev_fake_seller_flush_sync' )->never();

		$plugin->register_marketplace_flows();
	}
}
