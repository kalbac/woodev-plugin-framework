<?php
/**
 * Unit tests for the buyer-facing parcel value object (#1575).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Order\Tracking_Shipment;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-tracking-shipment.php';

/** @covers \Woodev\Framework\Shipping\Order\Tracking_Shipment */
final class TrackingShipmentTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( static function ( $url ) { return parse_url( $url ); } );
	}

	public function test_create_trims_the_number_and_keeps_a_valid_link(): void {
		$shipment = Tracking_Shipment::create( "  TRK 42\n", 'https://track.test/a?b=1&c=2' );

		$this->assertSame( 'TRK 42', $shipment->get_number() );
		$this->assertSame( 'https://track.test/a?b=1&c=2', $shipment->get_url() );
		$this->assertFalse( $shipment->is_empty() );
	}

	/**
	 * @dataProvider unsafe_urls
	 */
	public function test_a_link_that_is_not_an_absolute_http_url_becomes_null( string $url ): void {
		$this->assertNull( Tracking_Shipment::create( 'TRK', $url )->get_url() );
	}

	/** @return array<string,array{0:string}> */
	public function unsafe_urls(): array {
		return [
			'javascript scheme'            => [ 'javascript:alert(1)' ],
			'javascript with an authority' => [ 'javascript://example.com/%0Aalert(1)' ],
			'empty'                        => [ '' ],
			'relative'                     => [ '/track/42' ],
			'ftp'                          => [ 'ftp://h.test/x' ],
			'data'                         => [ 'data://text/html,x' ],
			'not a URL'                    => [ 'not a URL' ],
		];
	}

	public function test_a_shipment_needs_a_number_or_a_usable_link(): void {
		$this->assertTrue( Tracking_Shipment::create( '' )->is_empty() );
		$this->assertTrue( Tracking_Shipment::create( '   ', 'javascript:alert(1)' )->is_empty(), 'an unusable link is no link' );
		$this->assertFalse( Tracking_Shipment::create( '', 'https://track.test/x' )->is_empty(), 'a link-only shipment is allowed' );
		$this->assertFalse( Tracking_Shipment::create( 'TRK' )->is_empty(), 'a number-only shipment is allowed' );
	}

	public function test_status_fields_are_normalised(): void {
		$shipment = Tracking_Shipment::create( 'TRK', null, "  Принят \n", 1700000000 );
		$this->assertSame( 'Принят', $shipment->get_status_label() );
		$this->assertSame( 1700000000, $shipment->get_status_time() );

		$blank = Tracking_Shipment::create( 'TRK', null, '   ', 0 );
		$this->assertNull( $blank->get_status_label() );
		$this->assertNull( $blank->get_status_time(), 'zero means «no date»' );
		$this->assertNull( Tracking_Shipment::create( 'TRK', null, null, -5 )->get_status_time() );
	}

	public function test_display_text_is_the_number_or_a_call_to_follow_the_link(): void {
		$this->assertSame( 'TRK', Tracking_Shipment::create( 'TRK', 'https://track.test/x' )->get_display_text() );
		$this->assertSame( 'Track shipment', Tracking_Shipment::create( '', 'https://track.test/x' )->get_display_text() );
	}
}
