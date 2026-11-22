<?php
/**
 * A WooCommerce shop in memory, for the flows that create and reshape products.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

use Brain\Monkey\Functions;

require_once __DIR__ . '/wc-product-attribute-stub.php';

/**
 * Plays the part of WooCommerce the card importer's group assembly touches: products with properties and meta, the SKU lookup,
 * a product loaded as another type, a product's variations and the meta lookup of `get_posts()`.
 *
 * It keeps the three habits of WooCommerce the assembly depends on:
 *
 * - **an object is not the database**: a product is a working copy, and only {@see \WC_Product::save()} stores it. A run that is
 *   cut before a save leaves the stored product as it was;
 * - **a SKU is unique**: `set_sku()` throws when another stored product has it;
 * - **the attribute list is compared by identity**: `set_attributes()` with the very objects the product already holds records
 *   no change, so attributes that were only changed in place are not stored;
 * - **a variable product has no price and no stock status of its own**: loaded as `variable`, a product reads its regular and
 *   sale price as empty, and saved without managing stock it is in stock exactly when one of its variations is.
 *
 * {@see self::$fail_save_at} cuts a run at a chosen save.
 */
final class Fake_Shop {
	private const DEFAULTS = [
		'name'              => '',
		'sku'               => '',
		'status'            => 'publish',
		'parent_id'         => 0,
		'regular_price'     => '',
		'sale_price'        => '',
		'date_on_sale_from' => null,
		'date_on_sale_to'   => null,
		'manage_stock'      => false,
		'stock_quantity'    => null,
		'stock_status'      => 'instock',
		'backorders'        => 'no',
		'weight'            => '',
		'length'            => '',
		'width'             => '',
		'height'            => '',
		'image_id'          => 0,
		'attributes'        => [],
	];

	/**
	 * The stored products: `[ 'type' => string, 'props' => array, 'meta' => array ]` by id.
	 *
	 * @var array<int,array{type:string,props:array<string,mixed>,meta:array<string,mixed>}>
	 */
	public $stored = [];

	/**
	 * Saves made so far.
	 *
	 * @var int
	 */
	public $saves = 0;

	/**
	 * The save that throws instead of storing, counted like {@see self::$saves}; 0 for none.
	 *
	 * @var int
	 */
	public $fail_save_at = 0;

	/**
	 * Id of the next new product.
	 *
	 * @var int
	 */
	private $next_id = 900;

	/**
	 * Makes WooCommerce's functions answer from this shop.
	 *
	 * @return void
	 */
	public function install(): void {
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) {
				return isset( $this->stored[ (int) $id ] ) ? $this->product( $this->stored[ (int) $id ]['type'], (int) $id ) : false;
			}
		);
		Functions\when( 'wc_get_product_object' )->alias(
			function ( string $type, int $id = 0 ) {
				return $this->product( $type, $id );
			}
		);
		Functions\when( 'wc_get_product_id_by_sku' )->alias(
			function ( string $sku ): int {
				return $this->id_by_sku( $sku );
			}
		);
		Functions\when( 'get_posts' )->alias(
			function ( array $args ): array {
				return $this->posts( $args );
			}
		);
		Wc_Attribute_Functions::install();
	}

	/**
	 * Returns a working copy of a product: the stored one, loaded as the given type, or a new one.
	 *
	 * @param string $type Product type.
	 * @param int    $id   Stored product id, 0 for a new product.
	 * @return \WC_Product
	 */
	public function product( string $type, int $id = 0 ): \WC_Product {
		$row         = $this->stored[ $id ] ?? null;
		$box         = new \stdClass();
		$box->id     = null === $row ? 0 : $id;
		$box->type   = $type;
		$box->props  = null === $row ? self::DEFAULTS : $row['props'];
		$box->meta   = null === $row ? [] : $row['meta'];
		$box->listed = false; // Whether `set_attributes()` recorded a change.

		if ( 'variable' === $type ) {
			$box->props['regular_price'] = '';
			$box->props['sale_price']    = '';
		}

		$box->props['attributes'] = self::copy_of( $box->props['attributes'] );
		$box->pristine            = self::copy_of( $box->props['attributes'] );

		$mock = \Mockery::mock( 'WC_Product' );

		$this->identity( $mock, $box );
		$this->properties( $mock, $box );
		$this->meta( $mock, $box );

		$mock->shouldReceive( 'get_attribute' )->andReturnUsing(
			static function ( string $name ) use ( $box ): string {
				return self::attribute_text( (array) $box->props['attributes'], $name );
			}
		);

		$mock->shouldReceive( 'save' )->andReturnUsing(
			function () use ( $box ): int {
				return $this->save( $box );
			}
		);

		return $mock;
	}

	/**
	 * Returns the stored variations of a product, by id.
	 *
	 * @param int $parent_id Product id.
	 * @return array<int,array{type:string,props:array<string,mixed>,meta:array<string,mixed>}>
	 */
	public function variations( int $parent_id ): array {
		return array_filter(
			$this->stored,
			static function ( array $row ) use ( $parent_id ): bool {
				return 'variation' === $row['type'] && $parent_id === (int) $row['props']['parent_id'];
			}
		);
	}

	/**
	 * Returns the id of the stored product with a SKU, 0 when there is none.
	 *
	 * @param string $sku SKU.
	 * @return int
	 */
	public function id_by_sku( string $sku ): int {
		foreach ( $this->stored as $id => $row ) {
			if ( '' !== $sku && $sku === $row['props']['sku'] ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Id, type and variations.
	 *
	 * @param \Mockery\MockInterface $mock Product double.
	 * @param \stdClass              $box  Its working copy.
	 * @return void
	 */
	private function identity( $mock, \stdClass $box ): void {
		$mock->shouldReceive( 'get_id' )->andReturnUsing(
			static function () use ( $box ): int {
				return $box->id;
			}
		);
		$mock->shouldReceive( 'is_type' )->andReturnUsing(
			static function ( $type ) use ( $box ): bool {
				return in_array( $box->type, (array) $type, true );
			}
		);
		$mock->shouldReceive( 'get_children' )->andReturnUsing(
			function () use ( $box ): array {
				return array_keys( $this->variations( $box->id ) );
			}
		);
	}

	/**
	 * Getters and setters of the properties.
	 *
	 * @param \Mockery\MockInterface $mock Product double.
	 * @param \stdClass              $box  Its working copy.
	 * @return void
	 */
	private function properties( $mock, \stdClass $box ): void {
		foreach ( array_keys( self::DEFAULTS ) as $prop ) {
			$mock->shouldReceive( 'get_' . $prop )->andReturnUsing(
				static function () use ( $box, $prop ) {
					return $box->props[ $prop ];
				}
			);
			$mock->shouldReceive( 'set_' . $prop )->andReturnUsing(
				function ( $value ) use ( $box, $prop ): void {
					$this->set( $box, $prop, $value );
				}
			);
		}
	}

	/**
	 * Meta of the product.
	 *
	 * @param \Mockery\MockInterface $mock Product double.
	 * @param \stdClass              $box  Its working copy.
	 * @return void
	 */
	private function meta( $mock, \stdClass $box ): void {
		$mock->shouldReceive( 'get_meta' )->andReturnUsing(
			static function ( string $key ) use ( $box ) {
				return $box->meta[ $key ] ?? '';
			}
		);
		$mock->shouldReceive( 'update_meta_data' )->andReturnUsing(
			static function ( string $key, $value ) use ( $box ): void {
				$box->meta[ $key ] = $value;
			}
		);
		$mock->shouldReceive( 'delete_meta_data' )->andReturnUsing(
			static function ( string $key ) use ( $box ): void {
				unset( $box->meta[ $key ] );
			}
		);
	}

	/**
	 * Sets a property the way WooCommerce does.
	 *
	 * @param \stdClass $box   Working copy.
	 * @param string    $prop  Property.
	 * @param mixed     $value Value.
	 * @return void
	 * @throws \RuntimeException When another stored product has the SKU.
	 */
	private function set( \stdClass $box, string $prop, $value ): void {
		if ( 'sku' === $prop && ! in_array( $this->id_by_sku( (string) $value ), [ 0, $box->id ], true ) ) {
			throw new \RuntimeException( 'Invalid or duplicated SKU.' );
		}

		if ( 'attributes' === $prop && 'variation' !== $box->type ) {
			$value = self::keyed( (array) $value );

			if ( $value === $box->props['attributes'] ) {
				return; // The same objects under the same keys: WooCommerce sees no change.
			}

			$box->listed = true;
		}

		$box->props[ $prop ] = $value;
	}

	/**
	 * Stores a working copy.
	 *
	 * @param \stdClass $box Working copy.
	 * @return int Product id.
	 * @throws \RuntimeException When this save is the one the test cuts.
	 */
	private function save( \stdClass $box ): int {
		++$this->saves;

		if ( $this->fail_save_at === $this->saves ) {
			throw new \RuntimeException( 'The run was cut at save ' . $this->saves . '.' );
		}

		if ( 0 === $box->id ) {
			$box->id = $this->next_id++;
		}

		if ( 'variable' === $box->type && ! $box->props['manage_stock'] ) {
			$box->props['stock_status'] = in_array( 'instock', array_column( array_column( $this->variations( $box->id ), 'props' ), 'stock_status' ), true ) ? 'instock' : 'outofstock';
		}

		$props = $box->props;

		if ( 'variation' !== $box->type && ! $box->listed ) {
			$props['attributes'] = $box->pristine; // Attributes changed in place are not stored.
		}

		$props['attributes']      = self::copy_of( $props['attributes'] );
		$this->stored[ $box->id ] = [
			'type'  => $box->type,
			'props' => $props,
			'meta'  => $box->meta,
		];

		return $box->id;
	}

	/**
	 * Answers `get_posts()` for a meta lookup of products.
	 *
	 * @param array<string,mixed> $args Query.
	 * @return int[]
	 */
	private function posts( array $args ): array {
		$found = [];

		foreach ( $this->stored as $id => $row ) {
			$is_product = 'variation' !== $row['type'] && in_array( $row['props']['status'], (array) $args['post_status'], true );

			if ( $is_product && isset( $row['meta'][ $args['meta_key'] ] ) && $args['meta_value'] === $row['meta'][ $args['meta_key'] ] ) {
				$found[] = $id;
			}
		}

		sort( $found );

		return array_slice( $found, 0, (int) $args['posts_per_page'] );
	}

	/**
	 * Reads an attribute as `WC_Product::get_attribute()` does: by the sanitised name, or the global attribute of it. A product's
	 * attribute gives its values, a variation's gives the one it holds.
	 *
	 * @param array<int|string,mixed> $attributes Attributes of the product or of the variation.
	 * @param string                  $name       Attribute name.
	 * @return string
	 */
	private static function attribute_text( array $attributes, string $name ): string {
		$key   = sanitize_title( $name );
		$found = $attributes[ $key ] ?? $attributes[ wc_attribute_taxonomy_name( $name ) ] ?? '';

		return $found instanceof \WC_Product_Attribute ? implode( ', ', $found->get_options() ) : (string) $found;
	}

	/**
	 * Keys a product's attributes as WooCommerce does, by the sanitised name.
	 *
	 * @param array<int|string,mixed> $attributes Attribute objects.
	 * @return array<string,\WC_Product_Attribute>
	 */
	private static function keyed( array $attributes ): array {
		$keyed = [];

		foreach ( $attributes as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute ) {
				$keyed[ sanitize_title( $attribute->get_name() ) ] = $attribute;
			}
		}

		return $keyed;
	}

	/**
	 * Copies an attribute list, objects included: what is stored is never the object a caller holds.
	 *
	 * @param array<int|string,mixed> $attributes Attributes of a product or of a variation.
	 * @return array<int|string,mixed>
	 */
	private static function copy_of( array $attributes ): array {
		return array_map(
			static function ( $attribute ) {
				return is_object( $attribute ) ? clone $attribute : $attribute;
			},
			$attributes
		);
	}
}
