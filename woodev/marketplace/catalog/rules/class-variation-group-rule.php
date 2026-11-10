<?php
/**
 * Rule: variations of one parent share and differ in the right fields.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog\Rules;

use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Validation_Context;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Validation_Rule;
use Woodev\Framework\Marketplace\Catalog\Dictionary_Resolution;
use Woodev\Framework\Marketplace\Catalog\Field_Definition;
use Woodev\Framework\Marketplace\Catalog\Source_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Variations of one parent form one model on the marketplace: they must share the model-name field and each must differ from every
 * sibling in at least one aspect field. A marketplace that groups variations otherwise leaves this rule out of its full-scope rule list. The adapter names the two field groups and supplies the siblings with their
 * mappings; a product with no siblings, or a context with no such fields, is not checked.
 *
 * @since 2.0.29
 */
final class Variation_Group_Rule implements Validation_Rule {
	public const CODE_SHARED = 'variation_model_differs';
	public const CODE_ASPECT = 'variation_aspects_equal';

	/**
	 * Checks the product.
	 *
	 * @since 2.0.29
	 *
	 * @param \WC_Product        $product Product.
	 * @param Mapping            $mapping Mapping.
	 * @param Profile            $profile Profile.
	 * @param Validation_Context $context Context.
	 * @param Validation_Report  $report  Report so far.
	 * @return Validation_Report
	 */
	public function check( \WC_Product $product, Mapping $mapping, Profile $profile, Validation_Context $context, Validation_Report $report ): Validation_Report {
		foreach ( $context->siblings() as $sibling ) {
			$report = $this->check_shared( $product, $mapping, $sibling, $context, $report );
			$report = $this->check_aspects( $product, $mapping, $sibling, $context, $report );
		}

		return $report;
	}

	/**
	 * Adds an error when a sibling holds another value in a field every variation must share.
	 *
	 * @param \WC_Product                                $product Product.
	 * @param Mapping                                    $mapping Mapping.
	 * @param array{product:\WC_Product,mapping:Mapping} $sibling Sibling.
	 * @param Validation_Context                         $context Context.
	 * @param Validation_Report                          $report  Report so far.
	 * @return Validation_Report
	 */
	private function check_shared( \WC_Product $product, Mapping $mapping, array $sibling, Validation_Context $context, Validation_Report $report ): Validation_Report {
		foreach ( $context->shared_field_ids() as $field_id ) {
			if ( $this->value_of( $product, $mapping, $field_id, $context ) === $this->value_of( $sibling['product'], $sibling['mapping'], $field_id, $context ) ) {
				continue;
			}

			return $report->with_issue(
				Validation_Report::ERROR,
				self::CODE_SHARED,
				'Вариации одного товара должны иметь одинаковое название модели, а у «' . $product->get_name() . '» и «' . $sibling['product']->get_name() . '» оно разное — исправьте источник поля в сопоставлении.',
				$field_id,
				Validation_Report::FIX_MAPPING,
				'у вариаций разное название модели'
			);
		}

		return $report;
	}

	/**
	 * Adds an error when this variation equals a sibling in every aspect field.
	 *
	 * @param \WC_Product                                $product Product.
	 * @param Mapping                                    $mapping Mapping.
	 * @param array{product:\WC_Product,mapping:Mapping} $sibling Sibling.
	 * @param Validation_Context                         $context Context.
	 * @param Validation_Report                          $report  Report so far.
	 * @return Validation_Report
	 */
	private function check_aspects( \WC_Product $product, Mapping $mapping, array $sibling, Validation_Context $context, Validation_Report $report ): Validation_Report {
		$aspects = $context->aspect_field_ids();

		if ( [] === $aspects ) {
			return $report;
		}

		foreach ( $aspects as $field_id ) {
			if ( $this->value_of( $product, $mapping, $field_id, $context ) !== $this->value_of( $sibling['product'], $sibling['mapping'], $field_id, $context ) ) {
				return $report;
			}
		}

		return $report->with_issue(
			Validation_Report::ERROR,
			self::CODE_ASPECT,
			'Вариации «' . $product->get_name() . '» и «' . $sibling['product']->get_name() . '» не отличаются ни цветом, ни размером, ни другим признаком вариации — задайте им разные значения.',
			null,
			Validation_Report::FIX_PRODUCT,
			'вариации ничем не отличаются'
		);
	}

	/**
	 * Returns the comparable value of a field for a product: the dictionary ids that would be sent for a dictionary field (a chosen id
	 * for any source kind, else the looked-up ids), otherwise the values the product gives.
	 *
	 * @param \WC_Product        $product  Product.
	 * @param Mapping            $mapping  Its mapping.
	 * @param string             $field_id Field id.
	 * @param Validation_Context $context  Context.
	 * @return string
	 */
	private function value_of( \WC_Product $product, Mapping $mapping, string $field_id, Validation_Context $context ): string {
		$source = $mapping->source_for( $field_id );

		if ( null === $source ) {
			return '';
		}

		$field = $this->field( $field_id, $context );

		if ( null !== $field && Field_Definition::TYPE_DICTIONARY === $field->type() ) {
			$resolved = Dictionary_Resolution::resolve( $source, $field, $product, $context );

			return 'id:' . implode( ',', $resolved['ids'] ) . '|v:' . implode( '|', $resolved['unresolved'] );
		}

		return 'v:' . implode( '|', Source_Resolver::values( $source, $product, $context->parent_product() ) );
	}

	/**
	 * Finds a field definition in the context.
	 *
	 * @param string             $field_id Field id.
	 * @param Validation_Context $context  Context.
	 * @return Field_Definition|null
	 */
	private function field( string $field_id, Validation_Context $context ): ?Field_Definition {
		foreach ( $context->fields() as $field ) {
			if ( $field->id() === $field_id ) {
				return $field;
			}
		}

		return null;
	}
}
