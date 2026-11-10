<?php
/**
 * Rule: dictionary fields resolve to a dictionary value.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog\Rules;

use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Validation_Context;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Validation_Rule;
use Woodev\Framework\Marketplace\Catalog\Field_Definition;
use Woodev\Framework\Marketplace\Catalog\Dictionary_Resolution;

defined( 'ABSPATH' ) || exit;

/**
 * A dictionary field is sent as a dictionary value id, never as free text (measurement G1). The id is either chosen in the mapping
 * (`Source::dictionary_value_id()`) or found by label through the context's local lookup. A label the lookup does not know is an
 * error with a pointer to the dictionary search; a field with no value at all is the business of {@see Required_Fields_Rule}.
 *
 * @since 2.0.29
 */
final class Dictionary_Values_Rule implements Validation_Rule {
	public const CODE = 'dictionary_value_unresolved';

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
		if ( ! $mapping->has_category() ) {
			return $report;
		}

		foreach ( $context->fields() as $field ) {
			$source = $mapping->source_for( $field->id() );

			if ( Field_Definition::TYPE_DICTIONARY !== $field->type() || null === $source || ! $source->is_complete() || null !== $source->dictionary_value_id() ) {
				continue;
			}

			$unknown = Dictionary_Resolution::resolve( $source, $field, $product, $context )['unresolved'];

			if ( [] !== $unknown ) {
				$report = $report->with_issue(
					Validation_Report::ERROR,
					self::CODE,
					'Значение «' . implode( '», «', $unknown ) . '» поля «' . $field->name() . '» не найдено в словаре ' . $context->marketplace( 'маркетплейса' ) . ' — нажмите «Найти в словаре» и выберите подходящее.',
					$field->id(),
					Validation_Report::FIX_DICTIONARY,
					'значение поля «' . $field->name() . '» не найдено в словаре'
				);
			}
		}

		return $report;
	}
}
