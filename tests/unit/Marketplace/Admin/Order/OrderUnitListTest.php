<?php
/**
 * Unit tests for the unit list of a grouped order on its order screen.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Order_Unit_List;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Buttons;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Runner;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Availability;
use Woodev\Framework\Marketplace\Admin\Shared\Order_Unit_Text;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Names;
use Woodev\Framework\Marketplace\Data\Wpdb_Seller_Action_Store;
use Woodev\Framework\Marketplace\Flows\Article_Display;
use Woodev\Framework\Marketplace\Flows\Order_Unit;
use Woodev\Framework\Marketplace\Flows\Order_Units;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Links\Unit_Key;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Seller_Adapter;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Sellable_Units;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Order\Order_Unit_List
 * @covers \Woodev\Framework\Marketplace\Admin\Shared\Order_Unit_Text
 */
final class OrderUnitListTest extends TestCase {
	private const ORDER_ID = 5501;

	/**
	 * Unit records of the order.
	 *
	 * @var Order_Unit[]
	 */
	private $records = [];

	/**
	 * Units the marketplace lists for seller actions.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private $units = [];

	protected function setUp(): void {
		parent::setUp();

		$this->units   = [];
		$this->records = [
			self::record( '701', 'confirm/waiting', '' ),
			self::record( '702', 'new/declined_by_client', '2026-11-02T08:30:00Z' ),
		];

		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_attr' )->alias( 'htmlspecialchars' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wc_price' )->alias(
			static function ( $amount ): string {
				return '<span>' . $amount . ' RUB</span>';
			}
		);
	}

	public function test_every_unit_is_listed_with_its_article_status_and_amount(): void {
		$html = $this->unit_list()->render( $this->order() );

		$this->assertSame( 2, substr_count( $html, 'Футболка' ) );
		$this->assertSame( 2, substr_count( $html, 'coat · M' ), 'The article and the size, not the unit key.' );
		$this->assertStringNotContainsString( 'coat|M', $html );
		$this->assertStringContainsString( '<span class="woodev-mp-badge is-neutral">На сборке</span>', $html );
		$this->assertStringContainsString( '<span class="woodev-mp-badge is-danger">Отменено</span> <span class="description">Отменено покупателем</span>', $html );
		$this->assertStringContainsString( 'woodev-mp-units__cancelled', $html );
		$this->assertStringContainsString( '450.00 RUB', $html );
		$this->assertStringNotContainsString( '701', $html, 'The marketplace unit id never reaches the page.' );
		$this->assertStringNotContainsString( 'declined_by_client', $html );
		$this->assertStringNotContainsString( 'Действия', $html, 'No actions column without actions.' );
	}

	public function test_the_actions_of_a_unit_stand_beside_its_record(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		$this->units = [ [ '701', 'Футболка', 'confirm' ], [ '702', 'Футболка', 'cancelled', [] ] ];

		$html = $this->unit_list( true )->render( $this->order() );

		$this->assertStringContainsString( '<th>Действия</th>', $html );
		$this->assertSame( 1, substr_count( $html, 'data-unit="701"' ) );
		$this->assertStringNotContainsString( 'data-unit="702"', $html );
	}

	public function test_an_order_without_units_has_no_list(): void {
		$this->records = [];

		$this->assertSame( '', $this->unit_list()->render( $this->order() ) );
	}

	private function unit_list( bool $with_actions = false ): Order_Unit_List {
		$names = new class() implements Posting_Status_Names {
			public function posting_status_name( string $status ): string {
				$known = [
					'confirm/waiting'        => 'На сборке',
					'new/declined_by_client' => 'Отменено покупателем',
				];

				return $known[ $status ] ?? $status;
			}
		};

		$seller = null;

		if ( $with_actions ) {
			$db = new Sqlite_Wpdb();
			Sqlite_Tables::install( $db );
			$adapter = new Fake_Seller_Adapter();
			$runner  = new Seller_Action_Runner(
				$adapter,
				new Posting_Action_Gate( new Label_Availability( null ), null, null, null, $adapter ),
				new License_Gate(
					static function (): bool {
						return true;
					}
				),
				'Фейк',
				new Wpdb_Seller_Action_Store( $db, Sqlite_Tables::table( 'seller_actions' ) ),
				static function (): void {}
			);
			$account = new Account( 1, 'fake', 'Основной', true, [], null, [] );
			$seller  = new Seller_Action_Buttons(
				new Screen_Names( 'fake', 'Фейк' ),
				$runner,
				static function () use ( $account ): Account {
					return $account;
				},
				'https://shop.test/assets/',
				'1.0.0'
			);
		}

		return new Order_Unit_List(
			new Order_Unit_Text( $names, new Article_Display( new Fake_Sellable_Units() ) ),
			static function (): int {
				return 1;
			},
			$seller
		);
	}

	private function order(): \WC_Order {
		$order = \Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( self::ORDER_ID );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( $key ) {
				if ( Fake_Seller_Adapter::META_UNITS === $key ) {
					return $this->units;
				}

				if ( Order_Units::META !== $key ) {
					return '';
				}

				return array_map(
					static function ( Order_Unit $unit ): array {
						return $unit->to_array();
					},
					$this->records
				);
			}
		);

		return $order;
	}

	private static function record( string $id, string $status, string $cancelled_at ): Order_Unit {
		return new Order_Unit(
			$id,
			$status,
			$cancelled_at,
			[
				[
					'product_id' => 12,
					'offer_id'   => Unit_Key::encode( 'coat', 'M' ),
					'name'       => 'Футболка',
					'quantity'   => 1,
					'unit_minor' => 45000,
					'currency'   => 'RUB',
				],
			]
		);
	}
}
