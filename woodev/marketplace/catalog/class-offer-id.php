<?php
/**
 * The seller's article of a product on the marketplace.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * The article is frozen once sent; before that it is the WooCommerce SKU normalised by the marketplace's {@see Article_Policy}, and a
 * product without a SKU gets the policy's fallback. The validator checks uniqueness of exactly the value the card builder will send,
 * so both call this: freezing and the normalise-then-fallback order are the layer's, the length, the cleaning and the fallback are the
 * marketplace's.
 *
 * @since 2.0.29
 */
final class Offer_Id {
	/**
	 * The marketplace's rules.
	 *
	 * @var Article_Policy
	 */
	private $policy;

	/**
	 * Creates the article source.
	 *
	 * @since 2.0.29
	 *
	 * @param Article_Policy $policy The marketplace's article policy.
	 */
	public function __construct( Article_Policy $policy ) {
		$this->policy = $policy;
	}

	/**
	 * Returns the article for a product.
	 *
	 * @since 2.0.29
	 *
	 * @param string|null $frozen     The article already sent, or null.
	 * @param string      $sku        WooCommerce SKU.
	 * @param int         $product_id WooCommerce product or variation id.
	 * @return string
	 */
	public function for_product( ?string $frozen, string $sku, int $product_id ): string {
		if ( null !== $frozen && '' !== $frozen ) {
			return $frozen;
		}

		$normalised = $this->policy->normalise( $sku );

		return '' !== $normalised ? $normalised : $this->policy->fallback( $product_id );
	}

	/**
	 * Normalises a SKU the way the marketplace wants it.
	 *
	 * @since 2.0.29
	 *
	 * @param string $sku SKU.
	 * @return string Empty when nothing is left.
	 */
	public function normalise( string $sku ): string {
		return $this->policy->normalise( $sku );
	}

	/**
	 * Returns the longest article the marketplace accepts.
	 *
	 * @since 2.0.29
	 *
	 * @return int Characters.
	 */
	public function max_length(): int {
		return $this->policy->max_length();
	}
}
