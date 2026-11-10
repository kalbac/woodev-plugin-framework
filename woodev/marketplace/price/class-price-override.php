<?php
/**
 * Per-link price rule override.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

defined( 'ABSPATH' ) || exit;

/**
 * The link's `price_rule_override` (spec 3.1) as a validated value: three optional fields that replace the account / profile rule
 * one by one. Amounts are in the **cabinet currency**, the currency the marketplace sees.
 *
 * | field       | `fixed`                     | `percent`                                | `none`                          |
 * |-------------|-----------------------------|------------------------------------------|---------------------------------|
 * | `price`     | the selling price itself    | replaces the markup of the rule          | no markup for this product      |
 * | `old_price` | the old price itself        | the regular price grown by that percent  | never send an old price         |
 * | `min_price` | the minimum price itself    | that percent of the selling price        | (not allowed)                   |
 *
 * A field that is absent inherits the rule. Anything else (unknown field, unknown kind, malformed number) is an error, never
 * «no override»: a half-read override must not turn into a silent price change.
 *
 * @since 2.0.29
 */
final class Price_Override {
	public const KIND_FIXED   = 'fixed';
	public const KIND_PERCENT = 'percent';
	public const KIND_NONE    = 'none';

	public const FIELD_PRICE     = 'price';
	public const FIELD_OLD_PRICE = 'old_price';
	public const FIELD_MIN_PRICE = 'min_price';

	private const ALLOWED_KINDS = [
		self::FIELD_PRICE     => [ self::KIND_FIXED, self::KIND_PERCENT, self::KIND_NONE ],
		self::FIELD_OLD_PRICE => [ self::KIND_FIXED, self::KIND_PERCENT, self::KIND_NONE ],
		self::FIELD_MIN_PRICE => [ self::KIND_FIXED, self::KIND_PERCENT ],
	];

	/**
	 * Fields by name: `array{kind:string,value:string}`.
	 *
	 * @var array<string,array{kind:string,value:string}>
	 */
	private $fields;

	/**
	 * Creates the override from validated fields.
	 *
	 * @param array<string,array{kind:string,value:string}> $fields Fields.
	 */
	private function __construct( array $fields ) {
		$this->fields = $fields;
	}

	/**
	 * Builds an override from stored JSON (or the product tab's input).
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed>|null $raw    `{ price: {kind, value}, old_price: {...}, min_price: {...} }`; null or empty = no override.
	 * @param bool                     $stored True when reading what the link row holds: the v1 migration copies v1's free-text
	 *                                         amounts unchanged (`699.999`), so a fixed amount with more than two decimals is rounded to
	 *                                         the cent (half away from zero, as `Money` does) instead of refused. New input from a form
	 *                                         is read with `false` and keeps the two-decimal rule.
	 * @return self
	 * @throws \InvalidArgumentException On an unknown field or kind, or a malformed or out-of-range number.
	 */
	public static function from_array( ?array $raw, bool $stored = false ): self {
		$fields = [];

		foreach ( (array) $raw as $name => $field ) {
			if ( ! isset( self::ALLOWED_KINDS[ $name ] ) ) {
				throw new \InvalidArgumentException( 'Unknown price override field.' );
			}

			if ( null === $field ) {
				continue;
			}

			$fields[ $name ] = self::field( (string) $name, $field, $stored );
		}

		return new self( $fields );
	}

	/**
	 * Tells whether the override changes nothing.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return [] === $this->fields;
	}

	/**
	 * Returns one field.
	 *
	 * @since 2.0.29
	 *
	 * @param string $name `FIELD_*`.
	 * @return array{kind:string,value:string}|null Null when the field is absent (inherits the rule).
	 */
	public function field_of( string $name ): ?array {
		return $this->fields[ $name ] ?? null;
	}

	/**
	 * Tells whether the selling price is an absolute amount, which no rule can be inverted from.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function has_fixed_price(): bool {
		return self::KIND_FIXED === ( $this->fields[ self::FIELD_PRICE ]['kind'] ?? '' );
	}

	/**
	 * Returns the canonical array to store, null when empty.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,array{kind:string,value:string}>|null
	 */
	public function to_array(): ?array {
		return [] === $this->fields ? null : $this->fields;
	}

	/**
	 * Validates one field.
	 *
	 * @param string $name   Field name.
	 * @param mixed  $field  `{kind, value}`.
	 * @param bool   $stored Whether this is a stored value (see {@see self::from_array()}).
	 * @return array{kind:string,value:string}
	 * @throws \InvalidArgumentException When it is malformed.
	 */
	private static function field( string $name, $field, bool $stored ): array {
		$kind = is_array( $field ) ? (string) ( $field['kind'] ?? '' ) : '';

		if ( ! in_array( $kind, self::ALLOWED_KINDS[ $name ], true ) ) {
			throw new \InvalidArgumentException( 'Unknown price override kind.' );
		}

		if ( self::KIND_NONE === $kind ) {
			return [
				'kind'  => $kind,
				'value' => '0',
			];
		}

		$value = self::number( $field['value'] ?? '' );

		if ( self::KIND_FIXED === $kind ) {
			$value = $stored && 1 === preg_match( '/^\d{1,12}\.\d{3,}$/', $value ) ? Money::from_string( $value, 'RUB' )->to_string() : $value;
			self::assert_amount( $value );
		} else {
			self::assert_percent( $name, $value );
		}

		return [
			'kind'  => $kind,
			'value' => $value,
		];
	}

	/**
	 * Normalises a stored number to a decimal string (the v1 migration stores ints and floats).
	 *
	 * @param mixed $value Number.
	 * @return string
	 * @throws \InvalidArgumentException When it is not a scalar number.
	 */
	private static function number( $value ): string {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			throw new \InvalidArgumentException( 'A price override needs a number.' );
		}

		return str_replace( ',', '.', trim( (string) $value ) );
	}

	/**
	 * Requires a positive amount with at most two decimals.
	 *
	 * @param string $value Amount.
	 * @return void
	 * @throws \InvalidArgumentException When it is not.
	 */
	private static function assert_amount( string $value ): void {
		if ( 1 !== preg_match( '/^\d{1,12}(?:\.\d{1,2})?$/', $value ) || (float) $value <= 0 ) {
			throw new \InvalidArgumentException( 'A fixed price override is an amount above zero with at most two decimals.' );
		}
	}

	/**
	 * Requires a usable percentage: a markup stays above -100, a minimum price is in (0, 100].
	 *
	 * @param string $name  Field name.
	 * @param string $value Percentage.
	 * @return void
	 * @throws \InvalidArgumentException When it is not.
	 */
	private static function assert_percent( string $name, string $value ): void {
		list( $numerator, $denominator ) = Money::percent_ratio( $value );

		$usable = self::FIELD_MIN_PRICE === $name
			? $numerator > 0 && $numerator <= $denominator
			: $numerator + $denominator > 0;

		if ( ! $usable ) {
			throw new \InvalidArgumentException( 'A percent price override is out of range.' );
		}
	}
}
