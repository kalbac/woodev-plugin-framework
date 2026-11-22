<?php
/**
 * The history import keeps WooCommerce's e-mails back (#1925).
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Flows\History_Import;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Tests\Unit\TestCase;

/**
 * WooCommerce hangs one dispatcher — `WC_Emails::send_transactional_email()`, or `queue_transactional_email()` when e-mails are
 * deferred — on every action that triggers an e-mail: the order status changes, and the low-stock, no-stock and backorder notices.
 * The test keeps a table of those hooks behind `add_action` / `remove_action` / `has_action`, «fires» an action by running what is
 * registered on it, and counts what the dispatchers would have sent or queued. Queued e-mails are sent later, outside any scope,
 * so a queue that stays empty is the proof that a deferred e-mail has nothing to escape with.
 */
final class HistoryImportTest extends TestCase {
	private const CUTOFF = 1795000000; // 2026-11-17.

	private const ACTIONS = [
		'woocommerce_order_status_pending_to_processing',
		'woocommerce_order_status_pending_to_cancelled',
		'woocommerce_new_customer_note',
		'woocommerce_low_stock',
		'woocommerce_no_stock',
		'woocommerce_product_on_backorder',
	];

	/**
	 * Callbacks per action and priority: `[ 'WC_Emails', method ]`.
	 *
	 * @var array<string,array<int,array<int,array{0:string,1:string}>>>
	 */
	private $hooks = [];

	/**
	 * Actions whose e-mail was sent at once.
	 *
	 * @var string[]
	 */
	private $sent = [];

	/**
	 * Actions whose e-mail was queued for a later request.
	 *
	 * @var string[]
	 */
	private $queued = [];

	/**
	 * `$wp_filter` as the test found it.
	 *
	 * @var mixed
	 */
	private $wp_filter;

	/**
	 * Installs the hook table.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wp_filter = $GLOBALS['wp_filter'] ?? null;
		$this->hooks     = [];
		$this->sent      = [];
		$this->queued    = [];

		Functions\when( 'add_action' )->alias(
			function ( string $action, $callback, int $priority = 10 ): bool {
				$this->hooks[ $action ][ $priority ][] = $callback;

				return true;
			}
		);
		Functions\when( 'has_action' )->alias(
			function ( string $action, $callback ) {
				foreach ( $this->hooks[ $action ] ?? [] as $priority => $callbacks ) {
					if ( in_array( $callback, $callbacks, true ) ) {
						return $priority;
					}
				}

				return false;
			}
		);
		Functions\when( 'remove_action' )->alias(
			function ( string $action, $callback, int $priority = 10 ): bool {
				$index = array_search( $callback, $this->hooks[ $action ][ $priority ] ?? [], true );

				if ( false === $index ) {
					return false;
				}

				unset( $this->hooks[ $action ][ $priority ][ $index ] );

				return true;
			}
		);
	}

	/**
	 * Gives `$wp_filter` back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null === $this->wp_filter ) {
			unset( $GLOBALS['wp_filter'] );
		} else {
			$GLOBALS['wp_filter'] = $this->wp_filter;
		}

		parent::tearDown();
	}

	/**
	 * Both ways WooCommerce can be set up.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function dispatcher_provider(): array {
		return [
			'e-mails sent at once' => [ 'send_transactional_email' ],
			'e-mails deferred'     => [ 'queue_transactional_email' ],
		];
	}

	/**
	 * Registers the dispatcher on every e-mail action, as `WC_Emails::init_transactional_emails()` does, and makes the actions known
	 * to `$wp_filter`, which the import reads the actions from.
	 *
	 * @param string $method Dispatcher.
	 * @return void
	 */
	private function woocommerce_dispatches_with( string $method ): void {
		$GLOBALS['wp_filter'] = [ 'init' => true ];

		foreach ( self::ACTIONS as $action ) {
			$GLOBALS['wp_filter'][ $action ] = true;
			$this->hooks[ $action ][10][]    = [ 'WC_Emails', $method ];
		}

		// Somebody else's callback on the same action: it must not be touched.
		$this->hooks['woocommerce_low_stock'][10][] = [ 'Other_Plugin', 'on_low_stock' ];
	}

	/**
	 * What WooCommerce does for an order that is created, takes stock and changes status: fires the e-mail actions.
	 *
	 * @return void
	 */
	public function woocommerce_fires_every_email_action(): void {
		foreach ( self::ACTIONS as $action ) {
			foreach ( $this->hooks[ $action ] ?? [] as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( 'send_transactional_email' === $callback[1] ) {
						$this->sent[] = $action;
					}

					if ( 'queue_transactional_email' === $callback[1] ) {
						$this->queued[] = $action;
					}
				}
			}
		}
	}

	/**
	 * A remote order created at the given time.
	 *
	 * @param string $created_at ISO 8601 time; empty for an order without one.
	 * @return Order_DTO
	 */
	private function order( string $created_at ): Order_DTO {
		return new Order_DTO( 7, 'P-1', '', 'new', Status_Map::NEW, [], [], null, '' === $created_at ? [] : [ 'created_at' => $created_at ] );
	}

	/**
	 * An order the marketplace created before the cutoff.
	 *
	 * @return Order_DTO
	 */
	private function old_order(): Order_DTO {
		return $this->order( gmdate( 'Y-m-d\TH:i:s\Z', self::CUTOFF - DAY_IN_SECONDS ) );
	}

	/**
	 * The first write of a history order sends and queues nothing — an order e-mail, a customer e-mail or a stock notice — whether
	 * WooCommerce sends at once or defers, and the dispatchers are back on their actions afterwards.
	 *
	 * @dataProvider dispatcher_provider
	 * @param string $method Dispatcher of the shop.
	 * @return void
	 */
	public function test_the_first_write_of_a_history_order_sends_and_queues_nothing( string $method ): void {
		$this->woocommerce_dispatches_with( $method );
		$before = $this->hooks;

		$result = History_Import::during(
			self::CUTOFF,
			function () {
				return History_Import::quietly(
					$this->old_order(),
					true,
					function () {
						$this->woocommerce_fires_every_email_action();

						return 'saved';
					}
				);
			}
		);

		$this->assertSame( 'saved', $result );
		$this->assertSame( [], $this->sent );
		$this->assertSame( [], $this->queued, 'a deferred e-mail was never queued, so no later request can send it' );
		$this->assertSame( [ 'Other_Plugin', 'on_low_stock' ], $this->hooks['woocommerce_low_stock'][10][1], 'what is not WooCommerce\'s dispatcher is left alone' );

		$this->woocommerce_fires_every_email_action();
		$this->assertCount( count( self::ACTIONS ), array_merge( $this->sent, $this->queued ), 'the next e-mail of any order goes out as before' );
		$this->assertSame( $this->normalised( $before ), $this->normalised( $this->hooks ), 'every dispatcher is back on its action and priority' );
	}

	/**
	 * A write that is not the first of a history order, or belongs to an order created after the cutoff, or has no history at all,
	 * or whose creation time cannot be read, mails as WooCommerce always does.
	 *
	 * @return void
	 */
	public function test_every_other_write_is_live(): void {
		$this->woocommerce_dispatches_with( 'send_transactional_email' );
		$live  = $this->order( gmdate( 'Y-m-d\TH:i:s\Z', self::CUTOFF + HOUR_IN_SECONDS ) );
		$cases = [
			'a later change of a history order'    => [ self::CUTOFF, $this->old_order(), false ],
			'an order created after the cutoff'    => [ self::CUTOFF, $live, true ],
			'an account without a history'         => [ 0, $this->old_order(), true ],
			'an order without a creation time'     => [ self::CUTOFF, $this->order( '' ), true ],
			'an order with an unreadable time'     => [ self::CUTOFF, $this->order( 'yesterday-ish' ), true ],
		];

		foreach ( $cases as $name => $case ) {
			$this->sent = [];

			History_Import::during(
				$case[0],
				function () use ( $case ): void {
					History_Import::quietly( $case[1], $case[2], [ $this, 'woocommerce_fires_every_email_action' ] );
				}
			);

			$this->assertCount( count( self::ACTIONS ), $this->sent, $name );
		}

		$this->sent = [];
		History_Import::quietly( $this->old_order(), true, [ $this, 'woocommerce_fires_every_email_action' ] );
		$this->assertCount( count( self::ACTIONS ), $this->sent, 'outside any import' );
	}

	/**
	 * A checkout order made in the same tick, inside the import's stretch but outside the store's save, still mails; so does the
	 * first write of the next history order once the one before it is saved: only the save itself is quiet.
	 *
	 * @return void
	 */
	public function test_only_the_save_of_a_history_order_is_quiet(): void {
		$this->woocommerce_dispatches_with( 'send_transactional_email' );

		History_Import::during(
			self::CUTOFF,
			function () {
				History_Import::quietly( $this->old_order(), true, [ $this, 'woocommerce_fires_every_email_action' ] );
				$this->assertSame( [], $this->sent );

				$this->woocommerce_fires_every_email_action();
				$this->assertCount( count( self::ACTIONS ), $this->sent, 'a checkout order inside the stretch' );
			}
		);
	}

	/**
	 * Every import saves hundreds of orders; the actions are looked up once for the whole run.
	 *
	 * @return void
	 */
	public function test_a_run_of_saves_leaves_every_dispatcher_in_place_each_time(): void {
		$this->woocommerce_dispatches_with( 'queue_transactional_email' );
		$before = $this->normalised( $this->hooks );

		History_Import::during(
			self::CUTOFF,
			function () use ( $before ): void {
				for ( $i = 0; $i < 3; $i++ ) {
					History_Import::quietly( $this->old_order(), true, [ $this, 'woocommerce_fires_every_email_action' ] );
					$this->assertSame( $before, $this->normalised( $this->hooks ) );
				}
			}
		);

		$this->assertSame( [], $this->queued );
	}

	/**
	 * A save that throws puts the dispatchers back, closes the import's stretch, and lets the exception through.
	 *
	 * @return void
	 */
	public function test_the_dispatchers_come_back_when_the_save_throws(): void {
		$this->woocommerce_dispatches_with( 'send_transactional_email' );
		$before = $this->normalised( $this->hooks );

		try {
			History_Import::during(
				self::CUTOFF,
				function () {
					History_Import::quietly(
						$this->old_order(),
						true,
						static function (): void {
							throw new \RuntimeException( 'the save failed' );
						}
					);
				}
			);
			$this->fail( 'The exception must reach the caller.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'the save failed', $e->getMessage() );
		}

		$this->assertSame( 0, History_Import::cutoff(), 'the stretch is closed' );
		$this->assertSame( $before, $this->normalised( $this->hooks ), 'the dispatchers are back' );

		History_Import::during(
			self::CUTOFF,
			function () {
				History_Import::quietly( $this->old_order(), true, [ $this, 'woocommerce_fires_every_email_action' ] );
			}
		);
		$this->assertSame( [], $this->sent, 'the next history save is quiet as well: nothing was left half done' );
	}

	/**
	 * A save inside a save keeps the outer one's dispatchers off until the outer one ends.
	 *
	 * @return void
	 */
	public function test_a_nested_save_does_not_put_the_dispatchers_back_early(): void {
		$this->woocommerce_dispatches_with( 'send_transactional_email' );
		$before = $this->normalised( $this->hooks );

		History_Import::during(
			self::CUTOFF,
			function () {
				History_Import::quietly(
					$this->old_order(),
					true,
					function () {
						History_Import::quietly( $this->old_order(), true, [ $this, 'woocommerce_fires_every_email_action' ] );
						$this->woocommerce_fires_every_email_action();
					}
				);
			}
		);

		$this->assertSame( [], $this->sent );
		$this->assertSame( $before, $this->normalised( $this->hooks ) );
	}

	/**
	 * The stretch keeps the cutoff of the account it runs for, and hands the outer one back.
	 *
	 * @return void
	 */
	public function test_the_cutoff_is_that_of_the_running_stretch(): void {
		$this->assertSame( 0, History_Import::cutoff() );

		History_Import::during(
			100,
			function (): void {
				$this->assertSame( 100, History_Import::cutoff() );

				History_Import::during(
					0,
					function (): void {
						$this->assertSame( 0, History_Import::cutoff(), 'an account without a history is a plain call' );
					}
				);

				$this->assertSame( 100, History_Import::cutoff() );
			}
		);

		$this->assertSame( 0, History_Import::cutoff() );
	}

	/**
	 * The hook table in a shape that compares without regard to the order within a priority.
	 *
	 * @param array<string,array<int,array<int,array{0:string,1:string}>>> $hooks Hook table.
	 * @return array<string,array<int,string[]>>
	 */
	private function normalised( array $hooks ): array {
		$normal = [];

		foreach ( $hooks as $action => $priorities ) {
			foreach ( $priorities as $priority => $callbacks ) {
				$names = array_map(
					static function ( array $callback ): string {
						return $callback[0] . '::' . $callback[1];
					},
					$callbacks
				);
				sort( $names );

				$normal[ $action ][ $priority ] = $names;
			}
		}

		ksort( $normal );

		return $normal;
	}
}
