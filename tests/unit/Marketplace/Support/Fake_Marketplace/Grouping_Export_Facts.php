<?php
/**
 * The facts of a marketplace that groups the variations of a product, as a double.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Flows\Export_Grouping;

/**
 * Groups variations by their parent; a product without a parent stands alone.
 */
final class Grouping_Export_Facts extends Fake_Export_Facts implements Export_Grouping {
	/**
	 * Names the group of an offer.
	 *
	 * @param Link_Record $link    Link.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function export_group( Link_Record $link, \WC_Product $product ): string {
		return $product->get_parent_id() > 0 ? 'parent-' . $product->get_parent_id() : '';
	}
}
