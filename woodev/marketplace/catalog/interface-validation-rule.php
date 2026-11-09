<?php
/**
 * One pre-export check.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * A single check of spec 5.1.1. A rule only adds issues to the report it is given; the order the validator runs them in is the
 * merchant order of the spec, and the bulk report groups a product under the first rule that raised an error.
 *
 * @since 2.0.29
 */
interface Validation_Rule {
	/**
	 * Checks one product.
	 *
	 * @since 2.0.29
	 *
	 * @param \WC_Product        $product Product or variation.
	 * @param Mapping            $mapping Effective mapping.
	 * @param Profile            $profile Export profile.
	 * @param Validation_Context $context Outside facts.
	 * @param Validation_Report  $report  Report so far.
	 * @return Validation_Report The report with this rule's issues added.
	 */
	public function check( \WC_Product $product, Mapping $mapping, Profile $profile, Validation_Context $context, Validation_Report $report ): Validation_Report;
}
