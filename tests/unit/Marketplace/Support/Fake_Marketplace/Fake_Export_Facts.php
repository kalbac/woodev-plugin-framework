<?php
/**
 * The marketplace's local export facts, as a double.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Flows\Export_Facts;

/**
 * A marketplace that sells goods that ship: a virtual or downloadable product is no card. The article is the SKU unless one was
 * frozen. Tests put extra validator facts into {@see self::$facts}.
 */
final class Fake_Export_Facts implements Export_Facts {
	/**
	 * Facts for the validator.
	 *
	 * @var array<string,mixed>
	 */
	public $facts = [ 'marketplace_name' => 'Fake' ];

	/**
	 * The cabinet currency.
	 *
	 * @var string
	 */
	public $currency = 'RUB';

	/**
	 * Calls of {@see self::facts()}: product ids.
	 *
	 * @var int[]
	 */
	public $asked = [];

	/**
	 * Returns the facts.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product.
	 * @param Mapping     $mapping Mapping.
	 * @param Profile     $profile Profile.
	 * @return array<string,mixed>
	 */
	public function facts( Account $account, \WC_Product $product, Mapping $mapping, Profile $profile ): array {
		$this->asked[] = (int) $product->get_id();

		return $this->facts;
	}

	/**
	 * Returns the cabinet currency.
	 *
	 * @param Account $account Account.
	 * @return string
	 */
	public function account_currency( Account $account ): string {
		return $this->currency;
	}

	/**
	 * Returns the article: the frozen one, else the SKU.
	 *
	 * @param string|null $frozen  Frozen article.
	 * @param \WC_Product $product Product.
	 * @param Profile     $profile Profile.
	 * @return string
	 */
	public function offer_id_for( ?string $frozen, \WC_Product $product, Profile $profile ): string {
		return $frozen ?? (string) $product->get_sku();
	}

	/**
	 * Tells whether a product becomes a card.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public function is_exportable( \WC_Product $product ): bool {
		return ! $product->is_virtual() && ! $product->is_downloadable();
	}
}
