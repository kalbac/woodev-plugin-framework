<?php
/**
 * Reads the value a mapping source gives for a product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that turns a {@see Source} into values, so the validator and the card builder cannot disagree about what a product
 * «has». A source that reads the product (`wc_attribute`, `meta`, `field`) falls back to its stored `value`: that is how a v1
 * `dynamic` setting kept its default (spec 12.2 row 5).
 *
 * `FIELD` keys are neutral WooCommerce product fields; the
 * ones that name no readable text (`type`, `stock`, `status`, `category_ids`, `tag_ids`, `image`, `images`, `product_url`,
 * `formatted_name`) give no value and fall back to the stored default. A variation reads `parent_name` and the
 * description / weight / dimension fallbacks from its parent, which the adapter supplies (the layer cannot load products).
 *
 * @since 2.0.29
 */
final class Source_Resolver {
	/**
	 * Built-in product fields a `FIELD` source can name, by the getter that reads them.
	 *
	 * @var array<string,string>
	 */
	private const FIELD_GETTERS = [
		'id'                => 'get_id',
		'name'              => 'get_name',
		'sku'               => 'get_sku',
		'global_unique_id'  => 'get_global_unique_id',
		'description'       => 'get_description',
		'short_description' => 'get_short_description',
		'weight'            => 'get_weight',
		'length'            => 'get_length',
		'width'             => 'get_width',
		'height'            => 'get_height',
		'price'             => 'get_price',
		'regular_price'     => 'get_regular_price',
		'sale_price'        => 'get_sale_price',
	];

	/**
	 * Fields a variation takes from its parent when it has none of its own (v1 behaviour).
	 *
	 * @var string[]
	 */
	private const PARENT_FALLBACK = [ 'description', 'weight', 'length', 'width', 'height' ];

	/**
	 * Returns the non-empty values a source gives for a product.
	 *
	 * @since 2.0.29
	 *
	 * @param Source           $source  Source.
	 * @param \WC_Product      $product Product or variation.
	 * @param \WC_Product|null $parent_product  Parent of a variation, null for any other product.
	 * @return string[] Empty when the product has nothing and the source has no fallback.
	 */
	public static function values( Source $source, \WC_Product $product, ?\WC_Product $parent_product = null ): array {
		switch ( $source->kind() ) {
			case Source::FIXED:
				return self::clean( [ $source->value() ] );
			case Source::WC_ATTRIBUTE:
				$read = self::clean( self::attribute_values( $source->wc_key(), $product ) );
				break;
			case Source::META:
				$meta = $product->get_meta( $source->wc_key() );
				$read = self::clean( is_scalar( $meta ) ? [ (string) $meta ] : [] );
				break;
			default:
				$read = self::clean( [ self::field( $source->wc_key(), $product, $parent_product ) ] );
		}

		return [] !== $read ? $read : self::clean( [ $source->value() ] );
	}

	/**
	 * Tells whether a source gives the product a value: a stored dictionary id, or at least one value.
	 *
	 * @since 2.0.29
	 *
	 * @param Source           $source  Source.
	 * @param \WC_Product      $product Product or variation.
	 * @param \WC_Product|null $parent_product  Parent of a variation.
	 * @return bool
	 */
	public static function has_value( Source $source, \WC_Product $product, ?\WC_Product $parent_product = null ): bool {
		return null !== $source->dictionary_value_id() || [] !== self::values( $source, $product, $parent_product );
	}

	/**
	 * Reads the values of a product attribute without splitting a display string: a parent / simple product holds attribute objects
	 * (term names for a taxonomy attribute, the options for a custom one); a variation holds one chosen value, read as one.
	 *
	 * @param string      $key     Attribute key (`pa_color` or a custom attribute's name).
	 * @param \WC_Product $product Product.
	 * @return string[]
	 */
	private static function attribute_values( string $key, \WC_Product $product ): array {
		$attribute = self::find_attribute( $key, (array) $product->get_attributes() );

		if ( null !== $attribute ) {
			return self::attribute_object_values( $attribute );
		}

		return [ (string) $product->get_attribute( $key ) ];
	}

	/**
	 * Finds the attribute object a source names. WooCommerce stores a custom attribute under its slugged name (`Colour` is `colour`),
	 * so the name matches the array key or the object's own name, compared case-insensitively with spaces as dashes.
	 *
	 * @param string       $key        Attribute key or name.
	 * @param array<mixed> $attributes `WC_Product::get_attributes()`.
	 * @return object|null
	 */
	private static function find_attribute( string $key, array $attributes ): ?object {
		$wanted = self::slug( $key );

		foreach ( $attributes as $array_key => $attribute ) {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_options' ) ) {
				continue;
			}

			$name = method_exists( $attribute, 'get_name' ) ? (string) $attribute->get_name() : '';

			if ( (string) $array_key === $key || self::slug( (string) $array_key ) === $wanted || ( '' !== $name && self::slug( $name ) === $wanted ) ) {
				return $attribute;
			}
		}

		return null;
	}

	/**
	 * Lower-cases a name and turns whitespace into dashes.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function slug( string $name ): string {
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $name ) ) : strtolower( trim( $name ) );

		return (string) preg_replace( '/\s+/u', '-', $lower );
	}

	/**
	 * Reads the values of an attribute object.
	 *
	 * @param object $attribute Attribute object (`get_options()`, and `is_taxonomy()` / `get_terms()` for a taxonomy attribute).
	 * @return string[]
	 */
	private static function attribute_object_values( $attribute ): array {
		if ( method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() && method_exists( $attribute, 'get_terms' ) ) {
			$names = [];

			foreach ( (array) $attribute->get_terms() as $term ) {
				$names[] = is_object( $term ) && isset( $term->name ) ? (string) $term->name : '';
			}

			return $names;
		}

		return array_map( 'strval', (array) $attribute->get_options() );
	}

	/**
	 * Reads a built-in product field.
	 *
	 * @param string           $key     Field name.
	 * @param \WC_Product      $product Product.
	 * @param \WC_Product|null $parent_product  Parent of a variation.
	 * @return string Empty for a field that gives no text.
	 */
	private static function field( string $key, \WC_Product $product, ?\WC_Product $parent_product ): string {
		if ( 'parent_name' === $key ) {
			return (string) ( null !== $parent_product ? $parent_product : $product )->get_name();
		}

		if ( 'parent_id' === $key ) {
			return null !== $parent_product ? (string) $parent_product->get_id() : '';
		}

		$getter = self::FIELD_GETTERS[ $key ] ?? null;

		// WooCommerce 8.5 has no `get_global_unique_id()`; the stubs know every getter, a real older install does not.
		if ( null === $getter || ! is_callable( [ $product, $getter ] ) ) {
			return '';
		}

		$value = trim( (string) $product->$getter() );

		if ( '' === $value && null !== $parent_product && in_array( $key, self::PARENT_FALLBACK, true ) ) {
			return (string) $parent_product->$getter();
		}

		return $value;
	}

	/**
	 * Trims the values and drops the empty ones.
	 *
	 * @param string[] $values Values.
	 * @return string[]
	 */
	private static function clean( array $values ): array {
		return array_values(
			array_filter(
				array_map( 'trim', $values ),
				static function ( string $value ): bool {
					return '' !== $value;
				}
			)
		);
	}
}
