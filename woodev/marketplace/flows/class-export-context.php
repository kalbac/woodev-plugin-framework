<?php
/**
 * Everything the validator and the card builder read about one product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Validation_Context;

defined( 'ABSPATH' ) || exit;

/**
 * One product's export inputs, built by {@see Export_Context_Builder}: request time validates with them and the
 * `export_batch` job validates and builds with the same ones.
 *
 * @since 2.1.0
 */
final class Export_Context {
	/**
	 * Product or variation.
	 *
	 * @var \WC_Product
	 */
	private $product;

	/**
	 * Effective mapping; an empty one (no category) when nothing maps the product.
	 *
	 * @var Mapping
	 */
	private $mapping;

	/**
	 * Override values, variation over parent.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private $overrides;

	/**
	 * Profile.
	 *
	 * @var Profile
	 */
	private $profile;

	/**
	 * Raw validator context.
	 *
	 * @var array<string,mixed>
	 */
	private $context;

	/**
	 * Creates the value.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product                                  $product   Product or variation.
	 * @param Mapping                                      $mapping   Effective mapping.
	 * @param array<string,array<int,array<string,mixed>>> $overrides Override values.
	 * @param Profile                                      $profile   Profile.
	 * @param array<string,mixed>                          $context   Raw validator context.
	 */
	public function __construct( \WC_Product $product, Mapping $mapping, array $overrides, Profile $profile, array $context ) {
		$this->product   = $product;
		$this->mapping   = $mapping;
		$this->overrides = $overrides;
		$this->profile   = $profile;
		$this->context   = $context;
	}

	/**
	 * Returns the product.
	 *
	 * @since 2.1.0
	 *
	 * @return \WC_Product
	 */
	public function product(): \WC_Product {
		return $this->product;
	}

	/**
	 * Returns the effective mapping.
	 *
	 * @since 2.1.0
	 *
	 * @return Mapping
	 */
	public function mapping(): Mapping {
		return $this->mapping;
	}

	/**
	 * Returns the override values.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public function overrides(): array {
		return $this->overrides;
	}

	/**
	 * Returns the profile.
	 *
	 * @since 2.1.0
	 *
	 * @return Profile
	 */
	public function profile(): Profile {
		return $this->profile;
	}

	/**
	 * Returns the raw context for `Export_Validator::validate()`.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,mixed>
	 */
	public function raw_context(): array {
		return $this->context;
	}

	/**
	 * Returns the typed context for the builder.
	 *
	 * @since 2.1.0
	 *
	 * @return Validation_Context
	 */
	public function context(): Validation_Context {
		return new Validation_Context( $this->context );
	}
}
