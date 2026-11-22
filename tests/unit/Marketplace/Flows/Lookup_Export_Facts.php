<?php
/**
 * Export facts of a marketplace that can resolve a dictionary label locally.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Dictionary_Label_Lookup;
use Woodev\Framework\Marketplace\Catalog\Field_Definition;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Flows\Export_Facts;

/**
 * Decorates another set of facts and adds the {@see Dictionary_Label_Lookup} contract: «красный» is the dictionary value 901.
 */
final class Lookup_Export_Facts implements Export_Facts, Dictionary_Label_Lookup {
	/**
	 * The facts decorated.
	 *
	 * @var Export_Facts
	 */
	private $inner;

	/**
	 * Creates the decorator.
	 *
	 * @param Export_Facts $inner The facts decorated.
	 */
	public function __construct( Export_Facts $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Returns the facts of the decorated set.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product.
	 * @param Mapping     $mapping Mapping.
	 * @param Profile     $profile Profile.
	 * @return array<string,mixed>
	 */
	public function facts( Account $account, \WC_Product $product, Mapping $mapping, Profile $profile ): array {
		return $this->inner->facts( $account, $product, $mapping, $profile );
	}

	/**
	 * Returns the cabinet currency of the decorated set.
	 *
	 * @param Account $account Account.
	 * @return string
	 */
	public function account_currency( Account $account ): string {
		return $this->inner->account_currency( $account );
	}

	/**
	 * Returns the article of the decorated set.
	 *
	 * @param string|null $frozen  Frozen article.
	 * @param \WC_Product $product Product.
	 * @param Profile     $profile Profile.
	 * @return string
	 */
	public function offer_id_for( ?string $frozen, \WC_Product $product, Profile $profile ): string {
		return $this->inner->offer_id_for( $frozen, $product, $profile );
	}

	/**
	 * Tells whether the decorated set exports the product.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public function is_exportable( \WC_Product $product ): bool {
		return $this->inner->is_exportable( $product );
	}

	/**
	 * Knows one label.
	 *
	 * @param Field_Definition $field The dictionary field.
	 * @param string           $label The text.
	 * @return string|null
	 */
	public function dictionary_value_id( Field_Definition $field, string $label ): ?string {
		return 'красный' === $label ? '901' : null;
	}
}
