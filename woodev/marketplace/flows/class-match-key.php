<?php
/**
 * One way a card may be found among the shop's products.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * A match key is a kind of product field and the values of one card that a product may carry there. An adapter declares an ordered
 * list of them ({@see Sellable_Units::match_keys()}); {@see Unit_Matcher} compares the values **as raw strings**, byte for byte.
 *
 * @since 2.1.22
 */
final class Match_Key {
	/**
	 * The values are compared with the product's SKU.
	 */
	public const SKU = 'sku';

	/**
	 * The values are compared with the product's GTIN, its SKU and the custom field the export profiles read the barcode from.
	 */
	public const BARCODE = 'barcode';

	/**
	 * Kind: {@see self::SKU} or {@see self::BARCODE}.
	 *
	 * @var string
	 */
	private $kind;

	/**
	 * Values, none empty.
	 *
	 * @var string[]
	 */
	private $values;

	/**
	 * Creates the key.
	 *
	 * @param string           $kind   Kind.
	 * @param array<int,mixed> $values Values; empty ones and non-strings are dropped.
	 */
	private function __construct( string $kind, array $values ) {
		$kept = [];

		foreach ( $values as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$kept[] = $value;
			}
		}

		$this->kind   = $kind;
		$this->values = array_values( array_unique( $kept ) );
	}

	/**
	 * A key compared with the product's SKU.
	 *
	 * @since 2.1.22
	 *
	 * @param array<int,mixed> $values SKUs the card's product may have; none when the key does not apply to the card.
	 * @return self
	 */
	public static function sku( array $values ): self {
		return new self( self::SKU, $values );
	}

	/**
	 * A key compared with the product's barcodes.
	 *
	 * @since 2.1.22
	 *
	 * @param array<int,mixed> $values Barcodes of the unit; none when the key does not apply to the card.
	 * @return self
	 */
	public static function barcode( array $values ): self {
		return new self( self::BARCODE, $values );
	}

	/**
	 * Returns the kind.
	 *
	 * @since 2.1.22
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Returns the values.
	 *
	 * @since 2.1.22
	 *
	 * @return string[]
	 */
	public function values(): array {
		return $this->values;
	}
}
