<?php
/**
 * Unit: Orders_Provider descriptor validation (SP-10 increments 1, 2a, 2b round 2).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Exceptions\Shipping_Exception;

class ShippingOrdersProviderTest extends TestCase {

	public function test_create_stores_required_fields(): void {
		$provider = Orders_Provider::create( 'cdek', 'СДЭК', '_wc_edostavka_shipping', [ 'cdek' ] );

		$this->assertSame( 'cdek', $provider->get_id() );
		$this->assertSame( 'СДЭК', $provider->get_label() );
		$this->assertSame( '_wc_edostavka_shipping', $provider->get_marker_meta_key() );
		$this->assertSame( [ 'cdek' ], $provider->get_method_ids() );
	}

	/**
	 * Round 2, defect 1: every real carrier ships at least two shipping methods
	 * (courier and pickup) — `method_ids` must carry all of them, in order.
	 */
	public function test_create_stores_several_method_ids_in_order(): void {
		$provider = Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_wc_edostavka_shipping',
			[ 'cdek_courier', 'cdek_pickup' ]
		);

		$this->assertSame( [ 'cdek_courier', 'cdek_pickup' ], $provider->get_method_ids() );
	}

	public function test_create_stores_but_does_not_yet_consume_legacy_page_slug(): void {
		$provider = Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_wc_edostavka_shipping',
			[ 'cdek' ],
			[
				'legacy_page_slug' => 'wc_edostavka_orders',
			]
		);

		$this->assertSame( 'wc_edostavka_orders', $provider->get_legacy_page_slug() );
	}

	public function test_optional_fields_default_sanely(): void {
		$provider = Orders_Provider::create( 'cdek', 'СДЭК', '_wc_edostavka_shipping', [ 'cdek' ] );

		$this->assertNull( $provider->get_tracking_url_template() );
		$this->assertNull( $provider->get_legacy_page_slug() );
		$this->assertNull( $provider->get_status_meta_key() );
		$this->assertNull( $provider->get_tracking_meta_key() );
		$this->assertNull( $provider->get_pickup_point_meta_key() );
		$this->assertNull( $provider->get_cron_hook() );
		$this->assertSame( [], $provider->get_status_map() );
		$this->assertSame( [], $provider->get_status_labels() );
	}

	/**
	 * SP-10 spec D9 (#828): the carrier cron hook «Next update» is read off of — accepted
	 * and retrievable, optional because a webhook-only carrier has none.
	 */
	public function test_create_stores_the_cron_hook(): void {
		$provider = Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_wc_edostavka_shipping',
			[ 'cdek' ],
			[
				'cron_hook' => 'wc_edostavka_orders_update',
			]
		);

		$this->assertSame( 'wc_edostavka_orders_update', $provider->get_cron_hook() );
	}

	public function test_empty_id_throws(): void {
		$this->expectException( Shipping_Exception::class );
		$this->expectExceptionMessage( 'id' );

		Orders_Provider::create( '', 'СДЭК', '_wc_edostavka_shipping', [ 'cdek' ] );
	}

	public function test_empty_label_throws(): void {
		$this->expectException( Shipping_Exception::class );
		$this->expectExceptionMessage( 'label' );

		Orders_Provider::create( 'cdek', '', '_wc_edostavka_shipping', [ 'cdek' ] );
	}

	public function test_empty_marker_meta_key_throws(): void {
		$this->expectException( Shipping_Exception::class );
		$this->expectExceptionMessage( 'marker_meta_key' );

		Orders_Provider::create( 'cdek', 'СДЭК', '', [ 'cdek' ] );
	}

	/**
	 * Round 2, defect 1: `method_ids` is a required, non-empty LIST — same
	 * validation style as the other required fields.
	 */
	public function test_empty_method_ids_list_throws(): void {
		$this->expectException( Shipping_Exception::class );
		$this->expectExceptionMessage( 'method_ids' );

		Orders_Provider::create( 'cdek', 'СДЭК', '_wc_edostavka_shipping', [] );
	}

	/**
	 * The increment-2a fields: tracking link + meta key, the raw-status maps, and the
	 * pickup-point meta key — all stored and retrievable, none derived by the framework.
	 */
	public function test_create_stores_the_increment_2a_fields(): void {
		$status_map    = [ 'CDEK_ACCEPTED' => 'in_transit' ];
		$status_labels = [ 'CDEK_ACCEPTED' => 'Принят курьером' ];

		$provider = Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_wc_edostavka_shipping',
			[ 'cdek' ],
			[
				'status_meta_key'        => '_wc_edostavka_status',
				'status_map'             => $status_map,
				'status_labels'          => $status_labels,
				'tracking_url_template'  => 'https://cdek.ru/track/{tracking}',
				'tracking_meta_key'      => '_wc_edostavka_tracking_code',
				'pickup_point_meta_key'  => '_wc_edostavka_pickup_point',
			]
		);

		$this->assertSame( '_wc_edostavka_status', $provider->get_status_meta_key() );
		$this->assertSame( $status_map, $provider->get_status_map() );
		$this->assertSame( $status_labels, $provider->get_status_labels() );
		$this->assertSame( 'https://cdek.ru/track/{tracking}', $provider->get_tracking_url_template() );
		$this->assertSame( '_wc_edostavka_tracking_code', $provider->get_tracking_meta_key() );
		$this->assertSame( '_wc_edostavka_pickup_point', $provider->get_pickup_point_meta_key() );
	}

	/**
	 * A non-array `status_map`/`status_labels` must not fatal — it falls back to an
	 * empty map rather than trusting a caller's mistake.
	 */
	public function test_non_array_status_map_and_labels_fall_back_to_empty(): void {
		$provider = Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_wc_edostavka_shipping',
			[ 'cdek' ],
			[
				'status_map'    => 'not-an-array',
				'status_labels' => 'not-an-array',
			]
		);

		$this->assertSame( [], $provider->get_status_map() );
		$this->assertSame( [], $provider->get_status_labels() );
	}
}
