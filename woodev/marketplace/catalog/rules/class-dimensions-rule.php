<?php
/**
 * Rule: weight and dimensions are above zero.
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
 * Weight and the three dimensions must be above zero after the profile's fallbacks (`fallback_weight`, `fallback_width`,
 * `fallback_height`, `fallback_depth`). The units do not matter here, only that a number is there.
 *
 * @since 2.0.29
 */
final class Dimensions_Rule implements Validation_Rule {
	public const CODE_WEIGHT     = 'weight_missing';
	public const CODE_DIMENSIONS = 'dimensions_missing';

	/**
	 * Checks the product.
	 *
	 * @since 2.0.29
	 *
	 * @param \WC_Product        $product Product.
	 * @param Mapping            $mapping Mapping.
	 * @param Profile            $profile Profile.
	 * @param Validation_Context $context Context.
	 * @param Validation_Report  $report  Report so far.
	 * @return Validation_Report
	 */
	public function check( \WC_Product $product, Mapping $mapping, Profile $profile, Validation_Context $context, Validation_Report $report ): Validation_Report {
		if ( ! self::positive( $product->get_weight() ) && ! self::positive( $profile->setting( 'fallback_weight' ) ) ) {
			$report = $report->with_issue(
				Validation_Report::ERROR,
				self::CODE_WEIGHT,
				'У товара не указан вес — заполните его на вкладке «Доставка» в карточке товара или задайте вес по умолчанию в профиле.',
				null,
				Validation_Report::FIX_PRODUCT,
				'нет веса'
			);
		}

		$missing = [];
		foreach ( $this->dimensions( $product, $profile ) as $name => $value ) {
			if ( ! $value ) {
				$missing[] = $name;
			}
		}

		if ( [] === $missing ) {
			return $report;
		}

		return $report->with_issue(
			Validation_Report::ERROR,
			self::CODE_DIMENSIONS,
			'У товара не указаны размеры (' . implode( ', ', $missing ) . ') — заполните их на вкладке «Доставка» в карточке товара или задайте размеры по умолчанию в профиле.',
			null,
			Validation_Report::FIX_PRODUCT,
			'нет размеров'
		);
	}

	/**
	 * Tells for each dimension whether it has a usable value, product first, profile fallback second.
	 *
	 * @param \WC_Product $product Product.
	 * @param Profile     $profile Profile.
	 * @return array<string,bool> Russian name => has a value.
	 */
	private function dimensions( \WC_Product $product, Profile $profile ): array {
		return [
			'длина'  => self::positive( $product->get_length() ) || self::positive( $profile->setting( 'fallback_depth' ) ),
			'ширина' => self::positive( $product->get_width() ) || self::positive( $profile->setting( 'fallback_width' ) ),
			'высота' => self::positive( $product->get_height() ) || self::positive( $profile->setting( 'fallback_height' ) ),
		];
	}

	/**
	 * Tells whether a stored value is a number above zero.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function positive( $value ): bool {
		return is_numeric( $value ) && (float) $value > 0;
	}
}
