<?php
/**
 * A validated profile form.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Marketplace\Price\Price_Rule;
use Woodev\Framework\Marketplace\Price\Price_Source;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the JSON body of a profile save into the profile `settings`: sources, fallback dimensions and weight, units,
 * VAT, the currency rule and an optional price rule that overrides the account's. Pure apart from the WordPress
 * sanitizers. A value the profile already stores but the form no longer offers (one a migration copied) is kept for
 * as long as the merchant leaves it alone.
 *
 * @since 2.1.0
 */
final class Profile_Input {
	public const MAX_NAME = 200;

	public const CURRENCY_ACCOUNT = 'account';

	/**
	 * Body keys besides the source settings (those come from {@see Profile_Choices}).
	 *
	 * @var string[]
	 */
	private const FIXED_KEYS = [
		'name',
		'fallback_width',
		'fallback_height',
		'fallback_depth',
		'fallback_weight',
		'unit_weight',
		'unit_dimension',
		'vat_rate',
		'currency_kind',
		'currency_rate',
		'currency_field',
		'price_override',
		'price_base',
		'price_markup_kind',
		'price_markup',
		'price_rounding',
		'price_old_price_rule',
		'price_min_percent',
	];

	/**
	 * Allowed units by measure.
	 *
	 * @var array<string,string[]>
	 */
	private const UNITS = [
		'weight'    => [ 'g', 'kg' ],
		'dimension' => [ 'mm', 'cm', 'm' ],
	];

	/**
	 * What the form offers.
	 *
	 * @var Profile_Choices
	 */
	private $choices;

	/**
	 * The marketplace's price source for `field:KEY` rules; null when it has none.
	 *
	 * @var Price_Source|null
	 */
	private $price_source;

	/**
	 * Merchant label.
	 *
	 * @var string
	 */
	private $name = '';

	/**
	 * Settings JSON content.
	 *
	 * @var array<string,mixed>
	 */
	private $settings = [];

	/**
	 * Error lines.
	 *
	 * @var string[]
	 */
	private $errors = [];

	/**
	 * Creates an empty reader.
	 *
	 * @param Profile_Choices   $choices      What the form offers.
	 * @param Price_Source|null $price_source Price source of the marketplace.
	 */
	private function __construct( Profile_Choices $choices, ?Price_Source $price_source ) {
		$this->choices      = $choices;
		$this->price_source = $price_source;
	}

	/**
	 * Lists the keys a profile body may hold; any other key is refused by the controller.
	 *
	 * @since 2.1.0
	 *
	 * @param Profile_Choices $choices What the form offers.
	 * @return string[]
	 */
	public static function body_keys( Profile_Choices $choices ): array {
		$keys = self::FIXED_KEYS;

		foreach ( $choices->settings() as $setting ) {
			array_push( $keys, $setting, $setting . '_key' );
		}

		return $keys;
	}

	/**
	 * Reads a body.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $body         Decoded request body.
	 * @param Profile_Choices     $choices      What the form offers.
	 * @param Price_Source|null   $price_source Price source of the marketplace.
	 * @param array<string,mixed> $stored       Settings the profile has now; empty for a new one.
	 * @return self
	 */
	public static function from_request(
		array $body,
		Profile_Choices $choices,
		?Price_Source $price_source,
		array $stored = []
	): self {
		$input = new self( $choices, $price_source );
		$input->read_name( $body );
		$input->read_sources( $body, $stored );
		$input->read_fallbacks( $body );
		$input->read_units( $body, $stored );
		$input->read_vat( $body, $stored );
		$input->read_currency( $body, $stored );
		$input->read_price_rule( $body );

		return $input;
	}

	/**
	 * Tells whether the form can be saved.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return [] === $this->errors;
	}

	/**
	 * Returns the error lines.
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Returns the label.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the settings JSON content.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,mixed>
	 */
	public function settings(): array {
		return $this->settings;
	}

	/**
	 * Reads the label.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @return void
	 */
	private function read_name( array $body ): void {
		$this->name = self::text( $body, 'name' );

		if ( '' === $this->name ) {
			$this->errors[] = Mapping_Messages::profile_name_required();
		} elseif ( mb_strlen( $this->name ) > self::MAX_NAME ) {
			$this->errors[] = Mapping_Messages::name_too_long( self::MAX_NAME );
		}
	}

	/**
	 * Reads the source settings.
	 *
	 * @param array<string,mixed> $body   Request body.
	 * @param array<string,mixed> $stored Stored settings.
	 * @return void
	 */
	private function read_sources( array $body, array $stored ): void {
		foreach ( $this->choices->settings() as $setting ) {
			$kind = self::scalar( $body, $setting, $this->choices->default_for( $setting ) );
			$key  = self::text( $body, $setting . '_key' );

			$value = Profile_Choices::KIND_META === $kind ? $kind . ':' . $key : $kind;

			if ( $this->choices->is_known( $setting, $value ) || self::is_kept( $setting, $value, $stored ) ) {
				$this->settings[ $setting ] = $value;
				continue;
			}

			$offered              = isset( $this->choices->sources()[ $setting ][ $kind ] );
			$names_a_custom_field = Profile_Choices::KIND_META === $kind && $offered;
			$this->errors[]       = $names_a_custom_field
				? Mapping_Messages::custom_field_missing()
				: Mapping_Messages::malformed();
		}
	}

	/**
	 * Tells whether a value is the one the profile already stores (a legacy value the form cannot build).
	 *
	 * @param string              $setting Settings key.
	 * @param string              $value   Sent value.
	 * @param array<string,mixed> $stored  Stored settings.
	 * @return bool
	 */
	private static function is_kept( string $setting, string $value, array $stored ): bool {
		return isset( $stored[ $setting ] ) && is_string( $stored[ $setting ] ) && $stored[ $setting ] === $value;
	}

	/**
	 * Reads the fallback dimensions and weight: empty or a positive number.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @return void
	 */
	private function read_fallbacks( array $body ): void {
		$labels = [
			'fallback_width'  => __( 'Ширина', 'woodev-plugin-framework' ),
			'fallback_height' => __( 'Высота', 'woodev-plugin-framework' ),
			'fallback_depth'  => __( 'Глубина', 'woodev-plugin-framework' ),
			'fallback_weight' => __( 'Вес', 'woodev-plugin-framework' ),
		];

		foreach ( $labels as $key => $label ) {
			$raw = self::decimal( $body, $key );

			if ( '' === $raw ) {
				continue;
			}

			if ( ! is_numeric( $raw ) || (float) $raw <= 0 ) {
				$this->errors[] = Mapping_Messages::fallback_invalid( $label );
				continue;
			}

			$this->settings[ $key ] = $raw + 0;
		}
	}

	/**
	 * Reads the units.
	 *
	 * @param array<string,mixed> $body   Request body.
	 * @param array<string,mixed> $stored Stored settings.
	 * @return void
	 */
	private function read_units( array $body, array $stored ): void {
		$units  = [];
		$legacy = isset( $stored['units'] ) && is_array( $stored['units'] ) ? $stored['units'] : [];

		foreach ( self::UNITS as $measure => $allowed ) {
			$value = self::scalar( $body, 'unit_' . $measure, '' );
			$kept  = '' !== $value && isset( $legacy[ $measure ] ) && (string) $legacy[ $measure ] === $value;

			if ( in_array( $value, $allowed, true ) || $kept ) {
				// A unit the form no longer offers survives while the merchant leaves it alone.
				$units[ $measure ] = $value;
			}
		}

		if ( [] !== $units ) {
			$this->settings['units'] = $units;
		}
	}

	/**
	 * Reads the VAT rate.
	 *
	 * @param array<string,mixed> $body   Request body.
	 * @param array<string,mixed> $stored Stored settings.
	 * @return void
	 */
	private function read_vat( array $body, array $stored ): void {
		$vat = self::scalar( $body, 'vat_rate', '' );

		if ( '' === $vat ) {
			return;
		}

		$kept = isset( $stored['vat_rate'] ) && (string) $stored['vat_rate'] === $vat;

		if ( isset( $this->choices->vat_rates()[ $vat ] ) || $kept ) {
			$this->settings['vat_rate'] = $vat;

			return;
		}

		$this->errors[] = Mapping_Messages::vat_invalid();
	}

	/**
	 * Reads the currency rule: `account`, `rate:{multiplier}`, the marketplace's own `field:{key}` or the stored
	 * `fixed:{code}`.
	 *
	 * @param array<string,mixed> $body   Request body.
	 * @param array<string,mixed> $stored Stored settings.
	 * @return void
	 */
	private function read_currency( array $body, array $stored ): void {
		$kind = self::scalar( $body, 'currency_kind', self::CURRENCY_ACCOUNT );

		if ( self::CURRENCY_ACCOUNT === $kind ) {
			return;
		}

		$stored_rule = (string) ( $stored['currency_rule'] ?? '' );

		if ( 'fixed' === $kind && 0 === strpos( $stored_rule, 'fixed:' ) ) {
			$this->settings['currency_rule'] = $stored_rule;

			return;
		}

		if ( 'rate' === $kind ) {
			$this->read_rate( $body );

			return;
		}

		if ( 'field' === $kind ) {
			$this->read_currency_field( $body );

			return;
		}

		$this->errors[] = Mapping_Messages::malformed();
	}

	/**
	 * Reads the exchange-rate multiplier.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @return void
	 */
	private function read_rate( array $body ): void {
		$rate = self::decimal( $body, 'currency_rate' );

		if ( ! is_numeric( $rate ) || (float) $rate <= 0 ) {
			$this->errors[] = Mapping_Messages::rate_invalid();

			return;
		}

		$this->settings['currency_rule'] = 'rate:' . $rate;
	}

	/**
	 * Reads the product field the price comes from. Only a marketplace whose price source handles the shape offers it.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @return void
	 */
	private function read_currency_field( array $body ): void {
		$field = self::text( $body, 'currency_field' );
		$rule  = 'field:' . $field;

		if ( '' === $field || null === $this->price_source || ! $this->price_source->supports( $rule ) ) {
			$this->errors[] = Mapping_Messages::price_field_invalid();

			return;
		}

		$this->settings['currency_rule'] = $rule;
	}

	/**
	 * Reads the price rule that overrides the account's; absent when the box is not ticked.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @return void
	 */
	private function read_price_rule( array $body ): void {
		if ( empty( $body['price_override'] ) ) {
			return;
		}

		try {
			$rule = Price_Rule::from_array(
				[
					'base'           => self::scalar( $body, 'price_base', Price_Rule::BASE_ACTIVE ),
					'markup_kind'    => self::scalar( $body, 'price_markup_kind', Price_Rule::MARKUP_NONE ),
					'markup'         => str_replace( ',', '.', self::scalar( $body, 'price_markup', '0' ) ),
					'rounding'       => self::scalar( $body, 'price_rounding', Price_Rule::ROUND_NONE ),
					'old_price_rule' => self::scalar( $body, 'price_old_price_rule', Price_Rule::OLD_PRICE_ON_SALE ),
					'min_percent'    => self::decimal( $body, 'price_min_percent' ),
				]
			);
		} catch ( \InvalidArgumentException $e ) {
			$this->errors[] = Mapping_Messages::price_rule_invalid();

			return;
		}

		$this->settings['price_rule'] = [
			'base'           => $rule->base(),
			'markup_kind'    => $rule->markup_kind(),
			'markup'         => $rule->markup(),
			'rounding'       => $rule->rounding(),
			'old_price_rule' => $rule->old_price_rule(),
			'min_percent'    => $rule->min_percent(),
		];
	}

	/**
	 * Reads a scalar body value as a trimmed string.
	 *
	 * @param array<string,mixed> $body     Request body.
	 * @param string              $key      Field name.
	 * @param string              $fallback Value when the field is missing.
	 * @return string
	 */
	private static function scalar( array $body, string $key, string $fallback ): string {
		return isset( $body[ $key ] ) && is_scalar( $body[ $key ] ) ? trim( (string) $body[ $key ] ) : $fallback;
	}

	/**
	 * Reads a sanitised text body value.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @param string              $key  Field name.
	 * @return string
	 */
	private static function text( array $body, string $key ): string {
		if ( ! isset( $body[ $key ] ) || ! is_scalar( $body[ $key ] ) ) {
			return '';
		}

		return trim( sanitize_text_field( (string) $body[ $key ] ) );
	}

	/**
	 * Reads a decimal body value as a string with a point as the separator.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @param string              $key  Field name.
	 * @return string
	 */
	private static function decimal( array $body, string $key ): string {
		return str_replace( ',', '.', self::scalar( $body, $key, '' ) );
	}
}
