<?php
/**
 * Price source of the fake marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Catalog\Source_Resolver;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Source;

/**
 * `field:KEY`: the product field (else product meta) `KEY` holds the price on the fake marketplace, in the cabinet currency. These
 * are the values the Ozon adapter passes in, so the validator and currency tests that moved with the layer keep their expectations.
 */
final class Fake_Field_Price_Source implements Price_Source {
	/**
	 * Returns the merchant-facing name of the choice.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Взять цену из поля товара';
	}

	/**
	 * Tells whether the rule has the `field:KEY` shape.
	 *
	 * @param string $rule Rule.
	 * @return bool
	 */
	public function supports( string $rule ): bool {
		return 1 === preg_match( '/^field:\S+$/', $rule );
	}

	/**
	 * Tells why the product has no usable price in the field.
	 *
	 * @param string           $rule           Rule.
	 * @param \WC_Product      $product        Product.
	 * @param \WC_Product|null $parent_product Parent of a variation.
	 * @return string|null
	 */
	public function problem( string $rule, \WC_Product $product, ?\WC_Product $parent_product ): ?string {
		$values = $this->values( $rule, $product, $parent_product );

		if ( array() !== $values && is_numeric( $values[0] ) && (float) $values[0] > 0 ) {
			return null;
		}

		return 'Правило валюты «' . $rule . '» берёт курс или цену из поля товара, но у этого товара там нет числа больше нуля — заполните поле или исправьте правило в профиле.';
	}

	/**
	 * Reads the price.
	 *
	 * @param string           $rule           Rule.
	 * @param string           $currency       Cabinet currency.
	 * @param \WC_Product      $product        Product.
	 * @param \WC_Product|null $parent_product Parent of a variation.
	 * @return Money
	 * @throws \InvalidArgumentException When the field holds no price above zero.
	 */
	public function price( string $rule, string $currency, \WC_Product $product, ?\WC_Product $parent_product ): Money {
		$values = $this->values( $rule, $product, $parent_product );
		$text   = str_replace( ',', '.', trim( (string) ( $values[0] ?? '' ) ) );

		if ( 1 !== preg_match( '/^\d{1,12}(?:\.\d{1,2})?$/', $text ) || (float) $text <= 0 ) {
			throw new \InvalidArgumentException( 'The product field holds no marketplace price.' );
		}

		return Money::from_string( $text, $currency );
	}

	/**
	 * Reads the field, else the meta, of the product.
	 *
	 * @param string           $rule           Rule.
	 * @param \WC_Product      $product        Product.
	 * @param \WC_Product|null $parent_product Parent of a variation.
	 * @return string[]
	 */
	private function values( string $rule, \WC_Product $product, ?\WC_Product $parent_product ): array {
		$key    = substr( $rule, 6 );
		$values = Source_Resolver::values( new Source( Source::FIELD, '', $key ), $product, $parent_product );

		return array() !== $values ? $values : Source_Resolver::values( new Source( Source::META, '', $key ), $product, $parent_product );
	}
}
