<?php
/**
 * A validation rule that blocks chosen products.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Validation_Context;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Validation_Rule;

/**
 * Raises an error `blocked_by_test` for every product id in {@see self::$blocked}, and keeps the context it saw for the product.
 */
final class Scripted_Rule implements Validation_Rule {
	/**
	 * Product ids to block.
	 *
	 * @var int[]
	 */
	public $blocked = [];

	/**
	 * The context of each checked product: id => context.
	 *
	 * @var array<int,Validation_Context>
	 */
	public $seen = [];

	/**
	 * Checks a product.
	 *
	 * @param \WC_Product        $product Product.
	 * @param Mapping            $mapping Mapping.
	 * @param Profile            $profile Profile.
	 * @param Validation_Context $context Context.
	 * @param Validation_Report  $report  Report so far.
	 * @return Validation_Report
	 */
	public function check( \WC_Product $product, Mapping $mapping, Profile $profile, Validation_Context $context, Validation_Report $report ): Validation_Report {
		$this->seen[ (int) $product->get_id() ] = $context;

		return in_array( (int) $product->get_id(), $this->blocked, true ) ? $report->with_issue( 'error', 'blocked_by_test', 'Blocked.' ) : $report;
	}
}
