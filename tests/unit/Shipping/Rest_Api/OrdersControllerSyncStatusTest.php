<?php
/**
 * Unit: Orders_Controller::get_sync_status() — the REST exposure of delivery-status
 * sync freshness (SP-10 spec D9, #828). Covers the nullable states: never synced, no
 * cron hook, an aggregate where one carrier is much staler than another, and — the
 * load-bearing case, reversed from round 1 — an aggregate that goes to null the
 * moment ANY registered carrier has never synced, even while a sibling is fresh.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Sync_Status;
use Woodev\Framework\Shipping\Rest_Api\Orders_Controller;
use Woodev\Tests\Unit\TestCase;

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::get_sync_status
 */
final class OrdersControllerSyncStatusTest extends TestCase {

	/**
	 * In-memory fake of the wp_options table, keyed by option name.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [ 'add_action', 'remove_action', 'add_filter', 'remove_filter' ] );
		Orders_Registry::instance()->reset_for_tests();

		$this->options = [];

		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				$key = strtolower( (string) $key );
				return preg_replace( '/[^a-z0-9_\-]/', '', $key );
			}
		);

		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'rest_ensure_response' )->returnArg();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function controller(): Orders_Controller {
		return new Orders_Controller( Orders_Registry::instance() );
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function register( string $id, string $label, string $marker, array $args = [] ): void {
		Orders_Registry::instance()->register_provider( Orders_Provider::create( $id, $label, $marker, [ $id ], $args ) );
	}

	// ---- an empty registry ----

	public function test_no_providers_yields_a_null_aggregate_and_an_empty_breakdown(): void {
		$data = $this->controller()->get_sync_status( new \WP_REST_Request() );

		$this->assertNull( $data['last_updated'] );
		$this->assertSame( [], $data['carriers'] );
	}

	// ---- never synced ----

	public function test_a_carrier_that_never_synced_reports_null_for_both_fields(): void {
		$this->register( 'cdek', 'СДЭК', '_marker' );

		$data = $this->controller()->get_sync_status( new \WP_REST_Request() );

		$this->assertSame(
			[ [ 'id' => 'cdek', 'label' => 'СДЭК', 'last_updated' => null, 'next_update' => null ] ],
			$data['carriers']
		);
		$this->assertNull( $data['last_updated'], 'a registry where nothing has ever synced has no aggregate either' );
	}

	// ---- no cron hook (webhook-only carrier) ----

	public function test_a_carrier_with_no_declared_cron_hook_reports_a_null_next_update(): void {
		$this->register( 'cdek', 'СДЭК', '_marker' );
		Delivery_Sync_Status::record_last_updated( 'cdek', 1700000000 );

		$data = $this->controller()->get_sync_status( new \WP_REST_Request() );

		$this->assertSame( 1700000000, $data['carriers'][0]['last_updated'] );
		$this->assertNull( $data['carriers'][0]['next_update'] );
	}

	public function test_a_carrier_with_a_declared_cron_hook_reports_the_scheduled_next_update(): void {
		$this->register( 'cdek', 'СДЭК', '_marker', [ 'cron_hook' => 'wc_edostavka_orders_update' ] );

		Functions\when( 'wp_next_scheduled' )->alias(
			static fn( $hook ) => 'wc_edostavka_orders_update' === $hook ? 1700003600 : false
		);

		$data = $this->controller()->get_sync_status( new \WP_REST_Request() );

		$this->assertSame( 1700003600, $data['carriers'][0]['next_update'] );
	}

	/**
	 * A declared cron hook with nothing currently scheduled (cron toggled off) must
	 * still read as null, not zero — WordPress's own `wp_next_scheduled()` contract.
	 */
	public function test_a_declared_cron_hook_with_nothing_scheduled_reports_a_null_next_update(): void {
		$this->register( 'cdek', 'СДЭК', '_marker', [ 'cron_hook' => 'wc_edostavka_orders_update' ] );

		$data = $this->controller()->get_sync_status( new \WP_REST_Request() );

		$this->assertNull( $data['carriers'][0]['next_update'] );
	}

	// ---- the aggregate: oldest, not freshest ----

	public function test_the_aggregate_is_the_oldest_last_updated_not_the_freshest(): void {
		$this->register( 'cdek', 'СДЭК', '_m1' );
		$this->register( 'yandex', 'Яндекс доставка', '_m2' );

		Delivery_Sync_Status::record_last_updated( 'cdek', 1700000000 );
		Delivery_Sync_Status::record_last_updated( 'yandex', 1000000000 );

		$data = $this->controller()->get_sync_status( new \WP_REST_Request() );

		$this->assertSame(
			1000000000,
			$data['last_updated'],
			'the aggregate must surface the STALEST carrier, or it hides exactly the thing the panel exists to show'
		);
	}

	/**
	 * THE load-bearing case (coordinator, reversing the round-1 reading): one
	 * never-synced carrier must pull the AGGREGATE down to null, even though a
	 * sibling carrier synced recently — "обновлено 10 минут назад" would otherwise
	 * be shown while it is false for every row of the never-synced carrier. Its own
	 * `null` still survives in the per-carrier breakdown untouched, which is what
	 * tells the merchant WHICH carrier is the reason.
	 */
	public function test_one_never_synced_carrier_pulls_the_aggregate_to_null_even_though_a_sibling_is_fresh(): void {
		$this->register( 'cdek', 'СДЭК', '_m1' );
		$this->register( 'yandex', 'Яндекс доставка', '_m2' );

		Delivery_Sync_Status::record_last_updated( 'cdek', 1700000000 );
		// yandex has never synced.

		$data = $this->controller()->get_sync_status( new \WP_REST_Request() );

		$this->assertNull(
			$data['last_updated'],
			'ANY registered carrier that has never synced must make the aggregate null, not just the never-synced one\'s own field'
		);

		$by_id = [];
		foreach ( $data['carriers'] as $carrier ) {
			$by_id[ $carrier['id'] ] = $carrier;
		}

		$this->assertNull( $by_id['yandex']['last_updated'], 'the per-carrier breakdown must still say WHICH carrier caused the null aggregate' );
		$this->assertSame( 1700000000, $by_id['cdek']['last_updated'] );
	}

	/**
	 * Control for the case above: once EVERY carrier has synced at least once, the
	 * aggregate goes back to being a real number — the oldest of them — not null.
	 */
	public function test_the_aggregate_is_a_real_number_once_every_carrier_has_synced_at_least_once(): void {
		$this->register( 'cdek', 'СДЭК', '_m1' );
		$this->register( 'yandex', 'Яндекс доставка', '_m2' );

		Delivery_Sync_Status::record_last_updated( 'cdek', 1700000000 );
		Delivery_Sync_Status::record_last_updated( 'yandex', 1000000000 );

		$data = $this->controller()->get_sync_status( new \WP_REST_Request() );

		$this->assertSame( 1000000000, $data['last_updated'] );
	}
}
