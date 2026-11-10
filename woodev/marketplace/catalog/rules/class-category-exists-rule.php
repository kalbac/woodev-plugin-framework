<?php
/**
 * Rule: the mapped category still exists.
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
 * The marketplace renames and retires categories. A mapping that points at one that left the tree would be rejected at import, so
 * it is stopped here. The adapter tells whether the pair is in the cached tree; an unknown answer (no tree cached yet) is not an error.
 *
 * @since 2.0.29
 */
final class Category_Exists_Rule implements Validation_Rule {
	public const CODE = 'category_gone';

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
		if ( ! $mapping->has_category() || false !== $context->remote_category_exists() ) {
			return $report;
		}

		return $report->with_issue(
			Validation_Report::ERROR,
			self::CODE,
			'Категории, выбранной в сопоставлении, больше нет в дереве ' . $context->marketplace( 'маркетплейса' ) . ' — выберите категорию заново в «Сопоставление».',
			null,
			Validation_Report::FIX_MAPPING,
			'категория больше не существует'
		);
	}
}
