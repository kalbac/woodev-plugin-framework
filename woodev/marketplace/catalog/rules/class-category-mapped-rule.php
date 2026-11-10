<?php
/**
 * Rule: a category is chosen.
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
 * A product without a mapped category cannot be exported; every other category-dependent rule stays silent until it has one.
 *
 * @since 2.0.29
 */
final class Category_Mapped_Rule implements Validation_Rule {
	public const CODE = 'category_not_mapped';

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
		if ( $mapping->has_category() ) {
			return $report;
		}

		return $report->with_issue(
			Validation_Report::ERROR,
			self::CODE,
			'Категория товара не сопоставлена с категорией ' . $context->marketplace( 'маркетплейса' ) . ' — откройте «Сопоставление».',
			null,
			Validation_Report::FIX_MAPPING,
			'не сопоставлена категория'
		);
	}
}
