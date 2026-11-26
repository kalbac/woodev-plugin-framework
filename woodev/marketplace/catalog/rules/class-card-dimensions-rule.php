<?php
/**
 * Rule: the variations of one card agree on weight and dimensions.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog\Rules;

use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Validation_Context;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Validation_Rule;

defined( 'ABSPATH' ) || exit;

/**
 * A marketplace whose card holds several sizes may take **one** weight and one set of dimensions for the whole card
 * (Wildberries: `dimensions` belong to the card, a size has none). Variations of one card that disagree cannot be sent: any
 * choice would be the plugin's, not the merchant's. So the rule refuses every one of them and names what differs; nothing is
 * picked, averaged or enlarged.
 *
 * A value is the variation's own, else its parent's, else the profile's fallback (`fallback_weight`, `fallback_depth`,
 * `fallback_width`, `fallback_height`) — the same places {@see Dimensions_Rule} accepts it from. A value nobody has is not a
 * difference here: {@see Dimensions_Rule} reports it.
 *
 * Which variations share a card is the marketplace's knowledge (a colour card holds the sizes of one colour), so the adapter
 * says it; only an adapter that puts the rule in its list has it. The siblings are the ones the context supplies.
 *
 * @since 2.1.22
 */
final class Card_Dimensions_Rule implements Validation_Rule {
	public const CODE = 'card_dimensions_differ';

	/**
	 * What is compared: product getter => profile fallback setting and the Russian name.
	 */
	private const MEASURES = [
		'get_weight' => [ 'fallback_weight', 'вес' ],
		'get_length' => [ 'fallback_depth', 'длина' ],
		'get_width'  => [ 'fallback_width', 'ширина' ],
		'get_height' => [ 'fallback_height', 'высота' ],
	];

	/**
	 * Names the card of a variation: `function ( \WC_Product $product, Mapping $mapping ): string`.
	 *
	 * @var callable
	 */
	private $card_of;

	/**
	 * Creates the rule.
	 *
	 * @since 2.1.22
	 *
	 * @param callable $card_of `function ( \WC_Product $product, Mapping $mapping ): string` — variations of one parent that get the
	 *                          same answer go into one card of the marketplace.
	 */
	public function __construct( callable $card_of ) {
		$this->card_of = $card_of;
	}

	/**
	 * Checks the product against the other variations of its card.
	 *
	 * @since 2.1.22
	 *
	 * @param \WC_Product        $product Product or variation.
	 * @param Mapping            $mapping Mapping.
	 * @param Profile            $profile Profile.
	 * @param Validation_Context $context Context.
	 * @param Validation_Report  $report  Report so far.
	 * @return Validation_Report
	 */
	public function check( \WC_Product $product, Mapping $mapping, Profile $profile, Validation_Context $context, Validation_Report $report ): Validation_Report {
		$siblings = $context->siblings();

		if ( [] === $siblings ) {
			return $report;
		}

		$card    = (string) call_user_func( $this->card_of, $product, $mapping );
		$own     = $this->measures( $product, $profile, $context );
		$differs = [];

		foreach ( $siblings as $sibling ) {
			if ( (string) call_user_func( $this->card_of, $sibling['product'], $sibling['mapping'] ) !== $card ) {
				continue;
			}

			foreach ( $this->measures( $sibling['product'], $profile, $context ) as $name => $value ) {
				if ( null !== $value && null !== $own[ $name ] && $value !== $own[ $name ] ) {
					$differs[ $name ] = true;
				}
			}
		}

		if ( [] === $differs ) {
			return $report;
		}

		return $report->with_issue(
			Validation_Report::ERROR,
			self::CODE,
			'У размеров одной карточки различаются: ' . implode( ', ', self::in_order( $differs ) ) . '. В карточке ' . $context->marketplace( 'маркетплейса' ) . ' эти значения одни на все размеры — задайте всем размерам одинаковые (например, очистите поля у вариаций и заполните их у товара).',
			null,
			Validation_Report::FIX_PRODUCT,
			'у размеров разные вес или габариты'
		);
	}

	/**
	 * Lists the differing measures in the order the merchant reads them, whatever the order the siblings showed them in.
	 *
	 * @param array<string,true> $differs Russian names that differ.
	 * @return string[]
	 */
	private static function in_order( array $differs ): array {
		return array_values( array_intersect( array_column( self::MEASURES, 1 ), array_keys( $differs ) ) );
	}

	/**
	 * Reads the values a variation's card would be built from.
	 *
	 * @param \WC_Product        $product Variation.
	 * @param Profile            $profile Profile.
	 * @param Validation_Context $context Context.
	 * @return array<string,float|null> Russian name => value, null for none.
	 */
	private function measures( \WC_Product $product, Profile $profile, Validation_Context $context ): array {
		$parent   = $context->parent_product();
		$measures = [];

		foreach ( self::MEASURES as $getter => list( $fallback, $name ) ) {
			$values = [ $product->$getter(), null === $parent ? null : $parent->$getter(), $profile->setting( $fallback ) ];

			$measures[ $name ] = null;

			foreach ( $values as $value ) {
				if ( is_numeric( $value ) && (float) $value > 0 ) {
					$measures[ $name ] = (float) $value;
					break;
				}
			}
		}

		return $measures;
	}
}
