<?php
/**
 * A WooCommerce order that remembers what was written into it.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

use Mockery;

/**
 * A `\WC_Order` mock over two arrays, for the code that reads an order's fields and meta back: `$props` are the getters and setters
 * by property name, `$meta` the meta. `$saves` counts both kinds of save.
 */
final class Stateful_Order {
	/**
	 * Order fields a test may read and write.
	 */
	private const FIELDS = [
		'billing_first_name',
		'billing_last_name',
		'billing_phone',
		'shipping_first_name',
		'shipping_last_name',
		'shipping_phone',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'shipping_country',
		'customer_note',
		'payment_method_title',
	];

	/**
	 * @var array<string,mixed>
	 */
	public $props = [];

	/**
	 * @var array<string,mixed>
	 */
	public $meta = [];

	/**
	 * @var int
	 */
	public $saves = 0;

	/**
	 * @var \WC_Order&\Mockery\MockInterface
	 */
	public $order;

	/**
	 * @param int    $id          Order id.
	 * @param string $created_via What created the order.
	 */
	public function __construct( int $id = 700, string $created_via = 'fake-seller' ) {
		$this->props = array_fill_keys( self::FIELDS, '' ) + [ 'date_paid' => null ];
		$this->order = Mockery::mock( '\WC_Order' );

		$this->order->shouldReceive( 'get_id' )->andReturn( $id );
		$this->order->shouldReceive( 'get_created_via' )->andReturn( $created_via );
		$this->order->shouldReceive( 'get_currency' )->andReturn( 'RUB' );
		$this->order->shouldReceive( 'get_date_created' )->andReturn( null );
		$this->order->shouldReceive( 'get_items' )->andReturn( [] );

		foreach ( array_merge( self::FIELDS, [ 'date_paid' ] ) as $field ) {
			$this->order->shouldReceive( "get_$field" )->andReturnUsing(
				function () use ( $field ) {
					return $this->props[ $field ];
				}
			);
			$this->order->shouldReceive( "set_$field" )->andReturnUsing(
				function ( $value ) use ( $field ): void {
					$this->props[ $field ] = $value;
				}
			);
		}

		$this->order->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( string $key ) {
				return $this->meta[ $key ] ?? '';
			}
		);
		$this->order->shouldReceive( 'meta_exists' )->andReturnUsing(
			function ( string $key ): bool {
				return array_key_exists( $key, $this->meta );
			}
		);
		$this->order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}
		);
		$this->order->shouldReceive( 'delete_meta_data' )->andReturnUsing(
			function ( string $key ): void {
				unset( $this->meta[ $key ] );
			}
		);

		foreach ( [ 'save', 'save_meta_data' ] as $method ) {
			$this->order->shouldReceive( $method )->andReturnUsing(
				function (): void {
					++$this->saves;
				}
			);
		}
	}
}
