<?php
/**
 * The REST route of the «Аналитика» tab: who may read it, what it accepts, the stuck threshold and the kept answer.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Orders
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Orders;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Orders_Analytics_Controller;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Orders_Analytics_Query;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Orders_Analytics_Report;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Groups;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Sets;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Orders_Analytics_Controller
 */
final class OrdersAnalyticsControllerTest extends TestCase {
	/**
	 * Transients written in the test, by name.
	 *
	 * @var array<string,mixed>
	 */
	private $kept = [];

	/**
	 * How many reports were built.
	 *
	 * @var int
	 */
	private $builds = 0;

	/**
	 * Whether the adapter of the test classifies its posting statuses.
	 *
	 * @var bool
	 */
	private $classified = true;

	protected function setUp(): void {
		parent::setUp();

		$this->kept   = [];
		$this->builds = 0;

		Functions\when( 'get_option' )->justReturn( 1 );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Europe/Moscow' ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'RUB' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_transient' )->alias(
			function ( string $name ) {
				return $this->kept[ $name ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->kept[ $name ] = $value;

				return true;
			}
		);
	}

	/**
	 * A controller over the analytics shop, with accounts 1 and 2 enabled.
	 *
	 * @return Orders_Analytics_Controller
	 */
	private function controller(): Orders_Analytics_Controller {
		return new Orders_Analytics_Controller(
			'fake-seller',
			'manage_woocommerce',
			static function (): array {
				return [
					1 => 'Основной',
					2 => 'Второй',
				];
			},
			function ( array $accounts ): Orders_Analytics_Report {
				++$this->builds;
				$shop = new Analytics_Shop( true );

				$groups = $this->classified ? new class() implements Posting_Status_Groups {
					public function waiting_posting_statuses(): array {
						return [ 'awaiting_packaging' ];
					}

					public function rejected_posting_statuses(): array {
						return [ 'not_accepted' ];
					}
				} : null;

				return new Orders_Analytics_Report( new Orders_Analytics_Query( $shop->db, Sqlite_Tables::table( 'postings' ), $shop->storage(), Posting_Status_Sets::rejected( $groups ) ), $accounts, null, Analytics_Shop::CREATED_VIA, 'strval', 'strval', $groups );
			}
		);
	}

	/**
	 * @param array<string,mixed> $params Request params.
	 * @return \WP_REST_Request
	 */
	private function request( array $params ): \WP_REST_Request {
		return new \WP_REST_Request(
			$params + [
				'after'  => '2026-11-01',
				'before' => '2026-11-07',
			]
		);
	}

	public function test_only_a_user_with_the_orders_page_capability_may_read_it(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( false );

		$this->assertInstanceOf( \WP_Error::class, $this->controller()->check_permission() );

		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );

		$this->assertTrue( $this->controller()->check_permission() );
	}

	public function test_a_date_is_a_real_calendar_day(): void {
		$this->assertTrue( Orders_Analytics_Controller::validate_date( '2026-02-28' ) );
		$this->assertTrue( Orders_Analytics_Controller::validate_date( '' ), 'An optional date may be empty.' );
		$this->assertFalse( Orders_Analytics_Controller::validate_date( '2026-02-30' ) );
		$this->assertFalse( Orders_Analytics_Controller::validate_date( '2026-2-1' ) );
		$this->assertFalse( Orders_Analytics_Controller::validate_date( [ '2026-02-01' ] ) );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public function refused(): array {
		return [
			'reversed period'           => [ [ 'after' => '2026-11-07', 'before' => '2026-11-01' ], 'woodev_marketplace_analytics_bad_range' ],
			'too long a period'         => [ [ 'after' => '2024-01-01', 'before' => '2026-11-01' ], 'woodev_marketplace_analytics_range_too_wide' ],
			'half a comparison'         => [ [ 'compare_after' => '2026-10-25' ], 'woodev_marketplace_analytics_bad_range' ],
			'an account of no one'      => [ [ 'account' => '9' ], 'woodev_marketplace_analytics_unknown_account' ],
			'an account that is a word' => [ [ 'account' => 'first' ], 'woodev_marketplace_analytics_unknown_account' ],
		];
	}

	/**
	 * @dataProvider refused
	 *
	 * @param array<string,mixed> $params Request params.
	 * @param string              $code   Expected error code.
	 */
	public function test_a_request_it_cannot_answer_is_a_400_and_reads_nothing( array $params, string $code ): void {
		$answer = $this->controller()->get_report( $this->request( $params ) );

		$this->assertInstanceOf( \WP_Error::class, $answer );
		$this->assertSame( $code, $answer->code );
		$this->assertSame( 400, $answer->data['status'] );
		$this->assertSame( 0, $this->builds );
	}

	public function test_the_answer_is_kept_and_refresh_reads_again(): void {
		$controller = $this->controller();
		$first      = $controller->get_report( $this->request( [ 'account' => '1' ] ) );

		$this->assertFalse( $first->data['cached'] );
		$this->assertSame( 'RUB', $first->data['currency'] );
		$this->assertSame( '1', $first->data['account'] );
		$this->assertSame( 'Отправления, которые ждут упаковки или отгрузки дольше 24 часов с момента, когда заказ пришёл в магазин.', $first->data['stuck']['hint'] );

		$second = $controller->get_report( $this->request( [ 'account' => '1' ] ) );

		$this->assertTrue( $second->data['cached'] );
		$this->assertSame( 1, $this->builds );

		$other = $controller->get_report( $this->request( [ 'account' => '2' ] ) );

		$this->assertFalse( $other->data['cached'], 'Another account is another answer.' );

		$fresh = $controller->get_report( $this->request( [ 'account' => '1', 'refresh' => true ] ) );

		$this->assertFalse( $fresh->data['cached'] );
		$this->assertSame( 3, $this->builds );
	}

	public function test_the_stuck_threshold_is_24_hours_unless_a_filter_says_otherwise(): void {
		$this->assertSame( 24, $this->controller()->stuck_hours() );

		Filters\expectApplied( 'woodev_marketplace_stuck_posting_hours' )->once()->with( 24, 'fake-seller' )->andReturn( 48 );

		$this->assertSame( 48, $this->controller()->stuck_hours() );
	}

	public function test_a_filtered_threshold_that_is_not_a_positive_number_falls_back_to_the_default(): void {
		Filters\expectApplied( 'woodev_marketplace_stuck_posting_hours' )->twice()->andReturn( 0, 'soon' );

		$this->assertSame( 24, $this->controller()->stuck_hours() );
		$this->assertSame( 24, $this->controller()->stuck_hours() );
	}

	public function test_a_filtered_threshold_reaches_the_report(): void {
		Filters\expectApplied( 'woodev_marketplace_stuck_posting_hours' )->andReturn( 3 );

		$answer = $this->controller()->get_report( $this->request( [] ) );

		$this->assertSame( 3, $answer->data['stuck']['threshold_hours'] );
	}

	public function test_without_the_adapter_s_classification_the_answer_has_no_stuck_block(): void {
		$this->classified = false;

		$answer = $this->controller()->get_report( $this->request( [] ) );

		$this->assertFalse( $answer->data['classified'] );
		$this->assertNull( $answer->data['stuck'], 'No hint, no «none stuck».' );
	}

	public function test_a_long_stuck_list_says_how_much_is_shown(): void {
		$this->assertSame( '', Orders_Analytics_Controller::stuck_more( 3, 3 ) );
		$this->assertSame( 'Показаны самые старые: 20 из 35.', Orders_Analytics_Controller::stuck_more( 20, 35 ) );
	}
}
