<?php
/**
 * Pre-export validator.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

use Woodev\Framework\Marketplace\Catalog\Rules\Category_Mapped_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Required_Fields_Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a product against its mapping and profile before anything is sent. Request time, no API calls, no WordPress functions:
 * what the validator cannot read from the product it takes from the {@see Validation_Context}.
 *
 * Each check is a {@see Validation_Rule}. The layer ships the rule classes and the runner; the **list of rules in force for the full
 * scope, and their order, is the marketplace's** and is handed to the constructor (a typical order: category, category still in the
 * tree, required fields, dictionary values, weight and dimensions, images, article, price, currency, variation group). The mapping
 * scope keeps its two neutral rules. Every rule adds its own issues, so one report lists everything wrong with a product at once;
 * the bulk report groups products by the first error.
 *
 * Result shape (stable, read by the export job, the products screen and the validation modal): {@see Validation_Report} per product,
 * {@see Bulk_Validation_Report} for a selection.
 *
 * @since 2.0.29
 */
final class Export_Validator {
	/**
	 * Every rule of the marketplace: the product-dependent checks as well as the mapping checks. The default.
	 */
	public const SCOPE_FULL = 'full';

	/**
	 * Only the category and the field sources: what can be said about a mapping before a product is looked at. The mapping screen's
	 * «Проверить товары этой категории» and a migration acceptance test use it.
	 */
	public const SCOPE_MAPPING = 'mapping';

	public const CODE_NO_CATEGORY     = Category_Mapped_Rule::CODE;
	public const CODE_FIELD_NO_SOURCE = Required_Fields_Rule::CODE_NO_SOURCE;

	/**
	 * Rules of the full scope, in the order they run.
	 *
	 * @var Validation_Rule[]
	 */
	private $full_rules;

	/**
	 * Creates the validator.
	 *
	 * @since 2.0.29
	 *
	 * @param Validation_Rule[] $full_rules The marketplace's rules for the full scope, in the order they run.
	 * @throws \InvalidArgumentException When an element is not a {@see Validation_Rule}.
	 */
	public function __construct( array $full_rules ) {
		foreach ( $full_rules as $rule ) {
			if ( ! $rule instanceof Validation_Rule ) {
				throw new \InvalidArgumentException( 'The full-scope rule list holds something that is not a validation rule.' );
			}
		}

		$this->full_rules = array_values( $full_rules );
	}

	/**
	 * Validates one product.
	 *
	 * @since 2.0.29
	 *
	 * @param \WC_Product         $product WooCommerce product or variation.
	 * @param Mapping             $mapping Effective mapping (the link's overrides already laid over it).
	 * @param Profile             $profile Resolved export profile.
	 * @param array<string,mixed> $context Outside facts; the keys are listed on {@see Validation_Context}.
	 * @return Validation_Report
	 */
	public function validate( \WC_Product $product, Mapping $mapping, Profile $profile, array $context ): Validation_Report {
		$context = new Validation_Context( $context );
		$report  = new Validation_Report();

		foreach ( $this->rules( $context->scope() ) as $rule ) {
			$report = $rule->check( $product, $mapping, $profile, $context, $report );
		}

		return $report;
	}

	/**
	 * Returns the rules of a scope in the order they run.
	 *
	 * @param string $scope `SCOPE_FULL` or `SCOPE_MAPPING`.
	 * @return Validation_Rule[]
	 */
	private function rules( string $scope ): array {
		if ( self::SCOPE_MAPPING === $scope ) {
			return [ new Category_Mapped_Rule(), new Required_Fields_Rule( false ) ];
		}

		return $this->full_rules;
	}
}
