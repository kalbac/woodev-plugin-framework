<?php
/**
 * What a profile sets, in a merchant's words, for the profiles list.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a profile's stored settings into «Габариты: 20×15×2 см», «Вес: 300 г», «НДС: 20%»: one line per group of settings that
 * has a value, the value included, as the v1 profiles list showed it. A profile with no value in any group gives no lines, and the
 * list says «Не настроен».
 *
 * @since 2.1.13
 */
final class Profile_Properties {
	/**
	 * The merchant's name of a unit of measure (`mm`, `cm`, `m`, `g`, `kg`); an unknown unit is returned as it is.
	 *
	 * @since 2.1.13
	 *
	 * @param string $unit Unit code.
	 * @return string
	 */
	public static function unit_name( string $unit ): string {
		$names = [
			'mm' => __( 'мм', 'woodev-plugin-framework' ),
			'cm' => __( 'см', 'woodev-plugin-framework' ),
			'm'  => __( 'м', 'woodev-plugin-framework' ),
			'kg' => __( 'кг', 'woodev-plugin-framework' ),
			'g'  => __( 'г', 'woodev-plugin-framework' ),
		];

		return $names[ $unit ] ?? $unit;
	}

	/**
	 * The lines of a profile, in the order the profile form shows its groups.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $settings Stored settings.
	 * @param Profile_Choices     $choices  What the profile form offers: the source settings and the VAT rates.
	 * @return string[] E.g. `[ 'Габариты: 20×15×2 см', 'Вес: 300 г' ]`; empty for a profile with nothing set.
	 */
	public static function describe( array $settings, Profile_Choices $choices ): array {
		$units      = isset( $settings['units'] ) && is_array( $settings['units'] ) ? $settings['units'] : [];
		$dimensions = self::dimensions( $settings, $units );
		$weight     = self::weight( $settings, $units );
		$lines      = [
			__( 'Источники данных', 'woodev-plugin-framework' ) => self::sources( $settings, $choices ),
			__( 'Габариты', 'woodev-plugin-framework' )         => $dimensions,
			__( 'Вес', 'woodev-plugin-framework' )              => $weight,
			__( 'Единицы измерения', 'woodev-plugin-framework' ) => '' === $dimensions && '' === $weight ? self::units( $units ) : '',
			__( 'НДС', 'woodev-plugin-framework' )              => self::vat( $settings, $choices ),
			__( 'Валюта', 'woodev-plugin-framework' )           => self::currency( $settings ),
			__( 'Цена', 'woodev-plugin-framework' )             => self::price( $settings ),
		];
		$result     = [];

		foreach ( $lines as $label => $value ) {
			if ( '' !== $value ) {
				$result[] = $label . ': ' . $value;
			}
		}

		return $result;
	}

	/**
	 * A profile saved with the form's defaults still stores every source, so only a source the merchant changed counts.
	 *
	 * @param array<string,mixed> $settings Stored settings.
	 * @param Profile_Choices     $choices  Choices.
	 * @return string «изменены (2)»; empty when every source is the default one.
	 */
	private static function sources( array $settings, Profile_Choices $choices ): string {
		$changed = array_filter(
			$choices->settings(),
			static function ( string $setting ) use ( $settings, $choices ): bool {
				return ! empty( $settings[ $setting ] ) && $settings[ $setting ] !== $choices->default_for( $setting );
			}
		);

		/* translators: %d: how many of the profile's data sources the merchant changed from the default. */
		return [] === $changed ? '' : sprintf( __( 'изменены (%d)', 'woodev-plugin-framework' ), count( $changed ) );
	}

	/**
	 * @param array<string,mixed> $settings Stored settings.
	 * @param array<string,mixed> $units    Stored units.
	 * @return string «20×15×2 см» (a side that is not set is a dash); empty when no side is set.
	 */
	private static function dimensions( array $settings, array $units ): string {
		$sides = [];

		foreach ( [ 'fallback_width', 'fallback_height', 'fallback_depth' ] as $key ) {
			$sides[] = empty( $settings[ $key ] ) ? '—' : (string) $settings[ $key ];
		}

		if ( [ '—', '—', '—' ] === $sides ) {
			return '';
		}

		$unit = (string) ( $units['dimension'] ?? get_option( 'woocommerce_dimension_unit', 'cm' ) );

		return implode( '×', $sides ) . ' ' . self::unit_name( $unit );
	}

	/**
	 * @param array<string,mixed> $settings Stored settings.
	 * @param array<string,mixed> $units    Stored units.
	 * @return string «300 г»; empty when no weight is set.
	 */
	private static function weight( array $settings, array $units ): string {
		if ( empty( $settings['fallback_weight'] ) ) {
			return '';
		}

		$unit = (string) ( $units['weight'] ?? get_option( 'woocommerce_weight_unit', 'kg' ) );

		return $settings['fallback_weight'] . ' ' . self::unit_name( $unit );
	}

	/**
	 * @param array<string,mixed> $units Stored units.
	 * @return string «см, г»; empty when none is chosen.
	 */
	private static function units( array $units ): string {
		$names = array_map( [ self::class, 'unit_name' ], array_map( 'strval', array_values( $units ) ) );

		return implode( ', ', $names );
	}

	/**
	 * @param array<string,mixed> $settings Stored settings.
	 * @param Profile_Choices     $choices  Choices.
	 * @return string The rate as the form names it («20%», «Без НДС»); empty when it is not set.
	 */
	private static function vat( array $settings, Profile_Choices $choices ): string {
		if ( empty( $settings['vat_rate'] ) && '0' !== (string) ( $settings['vat_rate'] ?? '' ) ) {
			return '';
		}

		$rate = (string) $settings['vat_rate'];

		return $choices->vat_rates()[ $rate ] ?? $rate;
	}

	/**
	 * @param array<string,mixed> $settings Stored settings.
	 * @return string How the price is converted to the account's currency; empty when it is not.
	 */
	private static function currency( array $settings ): string {
		if ( empty( $settings['currency_rule'] ) ) {
			return '';
		}

		list( $kind, $rest ) = Profile_Choices::split( (string) $settings['currency_rule'] );

		switch ( $kind ) {
			case 'rate':
				/* translators: %s: the exchange rate the merchant typed. */
				return sprintf( __( 'по курсу %s', 'woodev-plugin-framework' ), $rest );
			case 'fixed':
				return $rest;
			default:
				return __( 'из поля товара', 'woodev-plugin-framework' );
		}
	}

	/**
	 * @param array<string,mixed> $settings Stored settings.
	 * @return string «наценка 10%» for a percent markup, «своё правило» for any other rule; empty when the profile has none.
	 */
	private static function price( array $settings ): string {
		$rule = $settings['price_rule'] ?? null;

		if ( empty( $rule ) || ! is_array( $rule ) ) {
			return '';
		}

		if ( 'percent' === ( $rule['markup_kind'] ?? '' ) && ! empty( $rule['markup'] ) ) {
			/* translators: %s: the markup in percent. */
			return sprintf( __( 'наценка %s%%', 'woodev-plugin-framework' ), $rule['markup'] );
		}

		return __( 'своё правило', 'woodev-plugin-framework' );
	}
}
