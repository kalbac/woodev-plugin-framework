<?php
/**
 * The characteristics of an imported card, written into the shop product as its attributes.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Catalog\Card_Content;

defined( 'ABSPATH' ) || exit;

/**
 * One list of what an import writes into a product as attributes, and the writer of it — so that the attribute table the import
 * makes for the card's category ({@see Import_Mapping}) links exactly the attributes that are on the product.
 *
 * The list ({@see self::of()}) is every characteristic the adapter gave ({@see Card_Content::characteristics()}) plus the aspects
 * of the card's group ({@see Card_Content::group()}), an aspect keeping its own value; a card with no characteristics given still
 * has its aspects. A simple product and a variable one get them the same way:
 *
 * - on a simple product ({@see Product_Writer::complete()}) every attribute is the product's own, shown on its page, not used for
 *   variations;
 * - a card joining a variable product ({@see Group_Assembler::offer()}) adds the attributes the product lacks. The aspects have
 *   been made variation attributes by then, so they are only found, never changed; the other characteristics are the product's
 *   (the model's), and a value of a later card that differs from the first card's is not added to them;
 * - a product that converts to a variable one keeps what it has: {@see Group_Assembler::convert()} turns the aspects among them
 *   into variation attributes.
 *
 * An attribute the product has already (by its name) is never touched, so the import run again — or resumed — adds nothing twice.
 * The attributes are the product's own, never global attribute taxonomies, like the aspects.
 *
 * @since 2.1.15
 */
final class Card_Attributes {
	/**
	 * Lists what the import writes for a card as product attributes.
	 *
	 * @since 2.1.15
	 *
	 * @param Card_Content $content The card.
	 * @return array<int,array{id:string,name:string,values:string[]}> `id` is the characteristic's field id, '' for an aspect or when the adapter gave none.
	 */
	public static function of( Card_Content $content ): array {
		$items = [];

		foreach ( (array) $content->group()['aspects'] as $name => $value ) {
			$name  = trim( (string) $name );
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';

			if ( '' !== $name && '' !== $value ) {
				$items[ mb_strtolower( $name ) ] = [
					'id'     => '',
					'name'   => $name,
					'values' => [ $value ],
				];
			}
		}

		foreach ( $content->characteristics() as $characteristic ) {
			$items += [ mb_strtolower( $characteristic['name'] ) => $characteristic ];
		}

		return array_values( $items );
	}

	/**
	 * Adds the attributes of a card that a product lacks. The caller saves the product when this says it changed.
	 *
	 * @since 2.1.15
	 *
	 * @param \WC_Product  $product A simple or a variable product, not a variation.
	 * @param Card_Content $content The card.
	 * @return bool Whether the product's attributes changed.
	 */
	public static function write( \WC_Product $product, Card_Content $content ): bool {
		$attributes = $product->get_attributes( 'edit' );
		$changed    = false;

		foreach ( self::of( $content ) as $item ) {
			$key = sanitize_title( $item['name'] );

			if ( ! isset( $attributes[ $key ] ) ) {
				$attributes[ $key ] = self::attribute( $item, count( $attributes ) );
				$changed            = true;
			}
		}

		if ( $changed ) {
			$product->set_attributes( array_values( $attributes ) );
		}

		return $changed;
	}

	/**
	 * Builds a product's own attribute that is shown on its page and is not a variation's.
	 *
	 * @param array{id:string,name:string,values:string[]} $item     The characteristic.
	 * @param int                                          $position Position among the product's attributes.
	 * @return \WC_Product_Attribute
	 */
	private static function attribute( array $item, int $position ): \WC_Product_Attribute {
		$attribute = new \WC_Product_Attribute();

		$attribute->set_name( $item['name'] );
		$attribute->set_options( array_map( [ self::class, 'option' ], $item['values'] ) );
		$attribute->set_visible( true );
		$attribute->set_variation( false );
		$attribute->set_position( $position );

		return $attribute;
	}

	/**
	 * Cleans one value for storing. WooCommerce keeps the values of a custom attribute in one string split at «|», so a value holding
	 * one would become two.
	 *
	 * @param string $value A value as the marketplace gave it.
	 * @return string
	 */
	private static function option( string $value ): string {
		return str_replace( '|', '/', sanitize_text_field( $value ) );
	}
}
