<?php
/**
 * Unit tests for the buyer-facing tracking block (#1575).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\{Orders_Provider, Orders_Registry};
use Woodev\Framework\Shipping\Order\{Buyer_Tracking, Delivery_Status, Tracking_Shipment};
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once __DIR__ . '/tracking-order-fakes.php';

/**
 * Each test runs in its own process: the block formats dates with `wc_date_format()`, and a Brain Monkey stub of it
 * would stay defined for every later test — `License_Messages` and its tests assert the path where that function is
 * ABSENT (PlatformNeutralLicensingTest), so a leaked stub fails them depending on the run order (seen on PHP 7.4 in CI).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @covers \Woodev\Framework\Shipping\Order\Buyer_Tracking
 */
final class BuyerTrackingTest extends TestCase {

	use Fakes_Tracking_Orders;

	/** @var string the format wp_date() was asked for. */
	private string $date_format_used = '';

	protected function setUp(): void {
		parent::setUp();
		$this->fake_tracking_orders();
		$this->date_format_used = '';
		$this->reset_singleton();

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wc_date_format' )->justReturn( 'j F Y' );
		Functions\when( 'wp_date' )->alias(
			function ( string $format, int $time ) {
				$this->date_format_used = $format;

				return gmdate( 'd.m.Y', $time );
			}
		);
		// Prints the REAL template, so the markup and its escaping are what is asserted.
		Functions\when( 'wc_get_template' )->alias(
			static function ( string $name, array $args, string $template_path, string $default_path ) {
				extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
				include $default_path . $name;
			}
		);
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();
		$this->reset_singleton();
		parent::tearDown();
	}

	private function reset_singleton(): void {
		$property = new \ReflectionProperty( Buyer_Tracking::class, 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, null );
	}

	/**
	 * Registers a carrier whose order marker is `_marker`, with the given parcels from its resolver.
	 *
	 * @param Tracking_Shipment[] $shipments parcels the carrier reports.
	 * @param array<string,mixed> $args      more provider arguments.
	 */
	private function carrier( array $shipments, array $args = [] ): Orders_Provider {
		$provider = Orders_Provider::create(
			'test',
			'Перевозчик',
			'_marker',
			[ 'test_shipping' ],
			$args + [ 'shipments_resolver' => static function () use ( $shipments ) { return $shipments; } ]
		);
		Orders_Registry::instance()->register_provider( $provider );
		$this->meta['_marker'] = 'yes';

		return $provider;
	}

	private function rendered( $order ): string {
		ob_start();
		Buyer_Tracking::instance()->render( $order );

		return (string) ob_get_clean();
	}

	/** @param array<int,array{0:string,1:int,2:string}> $entries */
	private function log( array $entries ): void {
		$this->meta['_woodev_delivery_status_log_test'] = (string) json_encode( $entries );
	}

	public function test_the_block_is_hooked_once_on_the_order_details_hook(): void {
		$this->reset_singleton();
		Actions\expectAdded( 'woocommerce_order_details_after_order_table' )->once();

		Buyer_Tracking::instance();
		Buyer_Tracking::instance();
	}

	public function test_a_value_that_is_not_an_order_prints_nothing(): void {
		$this->carrier( [ Tracking_Shipment::create( 'A1' ) ] );

		$this->assertSame( '', $this->rendered( null ) );
		$this->assertSame( '', $this->rendered( new \stdClass() ) );
	}

	public function test_an_order_of_no_registered_carrier_prints_nothing(): void {
		$this->carrier( [ Tracking_Shipment::create( 'A1' ) ] );
		$this->meta = [];

		$this->assertSame( '', $this->rendered( $this->tracking_order() ) );
	}

	public function test_no_shipments_means_no_output_at_all_not_even_the_heading(): void {
		$this->carrier( [] );

		$this->assertSame( '', $this->rendered( $this->tracking_order() ) );
	}

	public function test_one_shipment_is_a_tracking_number_row_without_a_shipment_caption(): void {
		$this->carrier( [ Tracking_Shipment::create( 'TRK 42', 'https://track.test/TRK%2042' ) ] );

		$html = $this->rendered( $this->tracking_order() );

		$this->assertStringContainsString( 'Delivery tracking', $html );
		$this->assertStringContainsString( '<th scope="row">Tracking number</th>', $html );
		$this->assertStringNotContainsString( 'Shipment 1 of', $html );
		$this->assertStringContainsString( '<a href="https://track.test/TRK%2042" target="_blank" rel="noopener">TRK 42</a>', $html );
	}

	public function test_several_shipments_are_numbered_n_of_m(): void {
		$this->carrier(
			[
				Tracking_Shipment::create( 'A1', 'https://track.test/A1' ),
				Tracking_Shipment::create( 'B2', 'https://track.test/B2' ),
				Tracking_Shipment::create( 'C3', 'https://track.test/C3' ),
			]
		);

		$html = $this->rendered( $this->tracking_order() );

		$this->assertStringContainsString( 'Shipment 1 of 3', $html );
		$this->assertStringContainsString( 'Shipment 2 of 3', $html );
		$this->assertStringContainsString( 'Shipment 3 of 3', $html );
		$this->assertStringNotContainsString( '>Tracking number<', $html );
		$this->assertSame( 3, substr_count( $html, 'rel="noopener"' ), 'every link opens safely' );
	}

	public function test_number_and_status_label_are_escaped(): void {
		$this->carrier( [ Tracking_Shipment::create( 'TRK<script>alert(1)</script>', null, '<b>Принят</b>', 86400 ), Tracking_Shipment::create( 'B2' ) ] );

		$html = $this->rendered( $this->tracking_order() );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'TRK&lt;script&gt;', $html );
	}

	public function test_a_link_only_shipment_links_a_call_to_follow_it(): void {
		$this->carrier( [ Tracking_Shipment::create( '', 'https://track.test/share/1' ) ] );

		$this->assertStringContainsString( '<a href="https://track.test/share/1" target="_blank" rel="noopener">Track shipment</a>', $this->rendered( $this->tracking_order() ) );
	}

	public function test_a_number_without_a_link_is_plain_text(): void {
		$this->carrier( [ Tracking_Shipment::create( 'A1' ) ] );

		$html = $this->rendered( $this->tracking_order() );

		$this->assertStringContainsString( 'A1', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	public function test_one_shipment_shows_the_newest_log_entry_with_the_carriers_own_label_first(): void {
		$this->carrier( [ Tracking_Shipment::create( 'A1' ) ], [ 'status_labels' => [ 'ACCEPTED' => 'Принят в пункте' ] ] );
		$this->log(
			[
				[ Delivery_Status::CREATED, 1000000, 'NEW' ],
				[ Delivery_Status::IN_TRANSIT, 1700000000, 'ACCEPTED' ],
			]
		);

		$html = $this->rendered( $this->tracking_order() );

		$this->assertStringContainsString( '<div class="woodev-tracking__status">Current status: Принят в пункте, 14.11.2023</div>', $html );
		$this->assertStringNotContainsString( 'Created', $html, 'only the newest entry' );
		$this->assertSame( 'j F Y', $this->date_format_used, 'the date is in the site\'s WooCommerce date format' );
	}

	public function test_without_a_carrier_label_the_canonical_buyer_wording_is_used(): void {
		$this->carrier( [ Tracking_Shipment::create( 'A1' ) ] );
		$this->log( [ [ Delivery_Status::IN_TRANSIT, 1700000000, 'UNLABELLED' ] ] );

		$this->assertStringContainsString( 'Current status: In transit, 14.11.2023', $this->rendered( $this->tracking_order() ) );
	}

	public function test_an_entry_without_a_time_prints_the_status_without_a_date(): void {
		$this->carrier( [ Tracking_Shipment::create( 'A1' ) ] );
		$this->log( [ [ Delivery_Status::DELIVERED, 0, '' ] ] );

		$this->assertStringContainsString( '>Current status: Delivered</div>', $this->rendered( $this->tracking_order() ) );
	}

	public function test_an_empty_log_or_an_unknown_state_prints_no_status_line(): void {
		$this->carrier( [ Tracking_Shipment::create( 'A1' ) ] );
		$this->assertStringNotContainsString( 'woodev-tracking__status', $this->rendered( $this->tracking_order() ), 'empty log' );

		$this->log( [ [ Delivery_Status::UNKNOWN, 1700000000, '' ] ] );
		$html = $this->rendered( $this->tracking_order() );
		$this->assertStringNotContainsString( 'woodev-tracking__status', $html, 'a state the buyer cannot be told about' );
		$this->assertStringContainsString( 'A1', $html );
	}

	public function test_several_shipments_show_their_own_status_never_the_order_level_one(): void {
		$this->carrier(
			[
				Tracking_Shipment::create( 'A1', null, 'Доставлено в ПВЗ', 1700000000 ),
				Tracking_Shipment::create( 'B2' ),
			]
		);
		$this->log( [ [ Delivery_Status::RETURNED, 1700000000, '' ] ] );

		$html = $this->rendered( $this->tracking_order() );

		$this->assertStringContainsString( 'Current status: Доставлено в ПВЗ, 14.11.2023', $html );
		$this->assertStringNotContainsString( 'Returned', $html, 'the order-level log is not copied onto every line' );
		$this->assertSame( 1, substr_count( $html, 'woodev-tracking__status' ), 'a parcel with no status of its own has no status text' );
	}

	public function test_one_shipment_with_its_own_status_shows_that_status_not_the_log(): void {
		$this->carrier( [ Tracking_Shipment::create( 'A1', null, 'В контейнере', 1700000000 ) ] );
		$this->log( [ [ Delivery_Status::IN_TRANSIT, 1700000000, '' ] ] );

		$html = $this->rendered( $this->tracking_order() );

		$this->assertStringContainsString( '<div class="woodev-tracking__status">Current status: В контейнере, 14.11.2023</div>', $html );
		$this->assertStringNotContainsString( 'In transit', $html, 'the order-level log is not used when the parcel has its own status' );
	}

	public function test_one_shipment_own_status_without_a_time_prints_it_without_a_date(): void {
		$this->carrier( [ Tracking_Shipment::create( 'A1', null, 'В контейнере' ) ] );
		$this->log( [ [ Delivery_Status::IN_TRANSIT, 1700000000, '' ] ] );

		$this->assertStringContainsString( '>Current status: В контейнере</div>', $this->rendered( $this->tracking_order() ) );
	}
}
