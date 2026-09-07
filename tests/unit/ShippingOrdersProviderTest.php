<?php
/**
 * Unit: Orders_Provider descriptor validation (SP-10 increment 1).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Exceptions\Shipping_Exception;

class ShippingOrdersProviderTest extends TestCase {

	public function test_create_stores_required_fields(): void {
		$provider = Orders_Provider::create( 'cdek', 'СДЭК', '_wc_edostavka_shipping' );

		$this->assertSame( 'cdek', $provider->get_id() );
		$this->assertSame( 'СДЭК', $provider->get_label() );
		$this->assertSame( '_wc_edostavka_shipping', $provider->get_marker_meta_key() );
	}

	public function test_create_stores_but_does_not_yet_consume_optional_fields(): void {
		$provider = Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_wc_edostavka_shipping',
			[
				'tracking_url_template' => 'https://cdek.ru/track/{tracking}',
				'legacy_page_slug'      => 'wc_edostavka_orders',
			]
		);

		$this->assertSame( 'https://cdek.ru/track/{tracking}', $provider->get_tracking_url_template() );
		$this->assertSame( 'wc_edostavka_orders', $provider->get_legacy_page_slug() );
	}

	public function test_optional_fields_default_to_null(): void {
		$provider = Orders_Provider::create( 'cdek', 'СДЭК', '_wc_edostavka_shipping' );

		$this->assertNull( $provider->get_tracking_url_template() );
		$this->assertNull( $provider->get_legacy_page_slug() );
	}

	public function test_empty_id_throws(): void {
		$this->expectException( Shipping_Exception::class );
		$this->expectExceptionMessage( 'id' );

		Orders_Provider::create( '', 'СДЭК', '_wc_edostavka_shipping' );
	}

	public function test_empty_label_throws(): void {
		$this->expectException( Shipping_Exception::class );
		$this->expectExceptionMessage( 'label' );

		Orders_Provider::create( 'cdek', '', '_wc_edostavka_shipping' );
	}

	public function test_empty_marker_meta_key_throws(): void {
		$this->expectException( Shipping_Exception::class );
		$this->expectExceptionMessage( 'marker_meta_key' );

		Orders_Provider::create( 'cdek', 'СДЭК', '' );
	}
}
