<?php
/**
 * Stand-in for WooCommerce's product attribute object.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

/**
 * Same three methods the resolver uses: `get_options()`, `is_taxonomy()`, `get_terms()`.
 */
final class Fake_Attribute {
	/**
	 * Option labels (term names for a taxonomy attribute).
	 *
	 * @var string[]
	 */
	private $labels;

	/**
	 * Whether the attribute is a taxonomy.
	 *
	 * @var bool
	 */
	private $taxonomy;

	/**
	 * Attribute name as the merchant typed it.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Creates the attribute.
	 *
	 * @param string[] $labels   Labels.
	 * @param bool     $taxonomy Taxonomy attribute.
	 * @param string   $name     Attribute name.
	 */
	public function __construct( array $labels, bool $taxonomy, string $name = '' ) {
		$this->name     = $name;
		$this->labels   = $labels;
		$this->taxonomy = $taxonomy;
	}

	/**
	 * Options (term ids for a taxonomy in WooCommerce; labels here).
	 *
	 * @return string[]
	 */
	public function get_options(): array {
		return $this->labels;
	}

	/**
	 * Attribute name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Taxonomy flag.
	 *
	 * @return bool
	 */
	public function is_taxonomy(): bool {
		return $this->taxonomy;
	}

	/**
	 * Terms of a taxonomy attribute.
	 *
	 * @return object[]
	 */
	public function get_terms(): array {
		return array_map(
			static function ( string $label ): object {
				return (object) array( 'name' => $label );
			},
			$this->labels
		);
	}
}
