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
 *   (the model's), and a value of a later card that differs from the first card's is not added to them. A variation reads them
 *   from its parent when it is exported ({@see \Woodev\Framework\Marketplace\Catalog\Source_Resolver});
 * - a product that converts to a variable one keeps what it has: {@see Group_Assembler::convert()} turns the aspects among them
 *   into variation attributes.
 *
 * An attribute the product has already is never touched, so the import run again — or resumed — adds nothing twice. «Has already»
 * is decided by the attribute's **identity** (its name, the case ignored), not by WordPress's slug alone, because the slug is lossy:
 * it drops punctuation and cuts a long name at 200 characters of its URL-encoded form (about 33 Cyrillic letters), so two names of
 * the marketplace's own, `…кабеля, м` and `…кабеля, см`, can share one. {@see self::of()} gives such a name a prefix that tells it
 * apart — `[10096] …кабеля, см` — in the writer and in the mapping alike, and an attribute of the merchant's that has the slug is
 * left as it is. A **global** attribute of the shop with the characteristic's name (`pa_brand` for «Brand») is the same attribute:
 * it is left as it is and no custom twin is made next to it. What the import creates are the product's own attributes, never new
 * global taxonomies, like the aspects.
 *
 * @since 2.1.15
 */
final class Card_Attributes {
	/**
	 * Lists what the import writes for a card as product attributes, each under the name it is stored by. A name that another
	 * attribute of the card — or of the product, when it is given — has by its slug but not by its identity is replaced by a prefixed
	 * one that is free; a name the product has by identity, or as a global attribute, stays as it is.
	 *
	 * The writer and the mapping take their names from here, with the product the attributes go to, so that a link names the
	 * attribute that holds its value.
	 *
	 * @since 2.1.15
	 *
	 * @param Card_Content     $content The card.
	 * @param \WC_Product|null $product The product that carries the attributes: the simple product, or the variable parent. Without it only the card's own names are told apart.
	 * @return array<int,array{id:string,name:string,values:string[]}> `id` is the characteristic's field id, '' for an aspect or when the adapter gave none.
	 */
	public static function of( Card_Content $content, ?\WC_Product $product = null ): array {
		$taken = self::taken( $product );
		$items = [];

		foreach ( self::wanted( $content ) as $item ) {
			$item['name']                              = self::free_name( $item, $taken );
			$taken[ sanitize_title( $item['name'] ) ] = self::identity( $item['name'] );
			$items[]                                   = $item;
		}

		return $items;
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

		foreach ( self::of( $content, $product ) as $item ) {
			if ( ! self::has( $attributes, $item['name'] ) ) {
				$attributes[ sanitize_title( $item['name'] ) ] = self::attribute( $item, count( $attributes ) );
				$changed                                       = true;
			}
		}

		if ( $changed ) {
			$product->set_attributes( array_values( $attributes ) );
		}

		return $changed;
	}

	/**
	 * Lists what a card has to say as attributes, under the names the marketplace gave: the aspects, then the other characteristics.
	 *
	 * @param Card_Content $content The card.
	 * @return array<int,array{id:string,name:string,values:string[]}>
	 */
	private static function wanted( Card_Content $content ): array {
		$items = [];

		foreach ( (array) $content->group()['aspects'] as $name => $value ) {
			$name  = trim( (string) $name );
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';

			if ( '' !== $name && '' !== $value ) {
				$items[ self::identity( $name ) ] = [
					'id'     => '',
					'name'   => $name,
					'values' => [ $value ],
				];
			}
		}

		foreach ( $content->characteristics() as $characteristic ) {
			$items += [ self::identity( $characteristic['name'] ) => $characteristic ];
		}

		return array_values( $items );
	}

	/**
	 * Reads which slugs a product's attributes occupy, and whose they are.
	 *
	 * @param \WC_Product|null $product The product, null for none.
	 * @return array<string,string> The identity of the attribute under each slug.
	 */
	private static function taken( ?\WC_Product $product ): array {
		$taken = [];

		foreach ( null === $product ? [] : (array) $product->get_attributes( 'edit' ) as $slug => $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute ) {
				$taken[ (string) $slug ] = self::identity( $attribute->get_name() );
			}
		}

		return $taken;
	}

	/**
	 * Finds the name an attribute is stored by: the characteristic's own, unless another attribute holds its slug, then the name
	 * with a prefix — the field's id, or a short hash of the name when it has none — and a counter if even that is taken.
	 *
	 * @param array{id:string,name:string,values:string[]} $item  The characteristic.
	 * @param array<string,string>                         $taken The identity of the attribute under each slug.
	 * @return string
	 */
	private static function free_name( array $item, array $taken ): string {
		$name = $item['name'];
		$tag  = '' !== $item['id'] ? $item['id'] : substr( md5( self::identity( $name ) ), 0, 6 );

		for ( $try = 1; ; $try++ ) {
			$candidate = 1 === $try ? $name : sprintf( '[%s] %s', 2 === $try ? $tag : $tag . '-' . $try, $name );
			$owner     = $taken[ sanitize_title( $candidate ) ] ?? null;

			if ( null === $owner || self::identity( $candidate ) === $owner || isset( $taken[ wc_attribute_taxonomy_name( $candidate ) ] ) ) {
				return $candidate;
			}
		}
	}

	/**
	 * Tells whether a product has an attribute by a name: a custom one of that name, or the shop's global attribute of it.
	 *
	 * @param array<int|string,mixed> $attributes The product's attributes.
	 * @param string                  $name       The attribute's name, as {@see self::of()} gave it.
	 * @return bool
	 */
	private static function has( array $attributes, string $name ): bool {
		return isset( $attributes[ sanitize_title( $name ) ] ) || isset( $attributes[ wc_attribute_taxonomy_name( $name ) ] );
	}

	/**
	 * Reduces a name to what makes it the same attribute: trimmed, the case ignored.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function identity( string $name ): string {
		return mb_strtolower( trim( $name ) );
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
