<?php
/**
 * Article policy of the fake marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Catalog\Article_Policy;

/**
 * Trimmed, whitespace runs become `-`, at most 50 characters, `wc-{id}` for a product with no SKU: the values the Ozon adapter passes
 * in. A test that needs another cap passes it to the constructor.
 */
final class Fake_Article_Policy implements Article_Policy {
	/**
	 * Longest article.
	 *
	 * @var int
	 */
	private $max_length;

	/**
	 * Creates the policy.
	 *
	 * @param int $max_length Longest article.
	 */
	public function __construct( int $max_length = 50 ) {
		$this->max_length = $max_length;
	}

	/**
	 * Returns the longest article.
	 *
	 * @return int
	 */
	public function max_length(): int {
		return $this->max_length;
	}

	/**
	 * Turns a SKU into an article.
	 *
	 * @param string $sku SKU.
	 * @return string
	 */
	public function normalise( string $sku ): string {
		return mb_substr( (string) preg_replace( '/\s+/u', '-', trim( $sku ) ), 0, $this->max_length );
	}

	/**
	 * Returns the article of a product with no SKU.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	public function fallback( int $product_id ): string {
		return 'wc-' . $product_id;
	}
}
