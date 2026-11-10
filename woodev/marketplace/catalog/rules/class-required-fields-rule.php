<?php
/**
 * Rule: every required field has a source and a value.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog\Rules;

use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Validation_Context;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Validation_Rule;
use Woodev\Framework\Marketplace\Catalog\Source_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Every required field of the category needs a source (always) and, in the full scope, a value for this product. The list of required
 * fields comes from the category's schema in the context, never from a constant.
 *
 * @since 2.0.29
 */
final class Required_Fields_Rule implements Validation_Rule {
	public const CODE_NO_SOURCE = 'required_field_has_no_source';
	public const CODE_EMPTY     = 'required_field_empty';
	public const CODE_UNKNOWN   = 'category_fields_unknown';

	/**
	 * Whether the product must also give a value, not only a source.
	 *
	 * @var bool
	 */
	private $check_values;

	/**
	 * Creates the rule.
	 *
	 * @since 2.0.29
	 *
	 * @param bool $check_values True to require a value for the product (the full scope).
	 */
	public function __construct( bool $check_values ) {
		$this->check_values = $check_values;
	}

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

		if ( $this->check_values && ! $context->fields_known() ) {
			return $report->with_issue(
				Validation_Report::ERROR,
				self::CODE_UNKNOWN,
				'Список обязательных полей категории ещё не загружен с ' . $context->marketplace( 'маркетплейса' ) . ' — откройте категорию в «Сопоставление» или дождитесь обновления каталога и повторите проверку.',
				null,
				Validation_Report::FIX_MAPPING,
				'не загружены поля категории'
			);
		}

		foreach ( $context->fields() as $field ) {
			if ( ! $field->is_required() ) {
				continue;
			}

			$source = $mapping->source_for( $field->id() );

			if ( null === $source || ! $source->is_complete() ) {
				$report = $report->with_issue(
					Validation_Report::ERROR,
					self::CODE_NO_SOURCE,
					'Для обязательного поля «' . $field->name() . '» не указано, откуда брать значение — заполните его в сопоставлении.',
					$field->id(),
					Validation_Report::FIX_MAPPING,
					'не задан источник поля «' . $field->name() . '»'
				);
			} elseif ( $this->check_values && ! Source_Resolver::has_value( $source, $product, $context->parent_product() ) ) {
				$report = $report->with_issue(
					Validation_Report::ERROR,
					self::CODE_EMPTY,
					'У товара нет значения для обязательного поля «' . $field->name() . '» — заполните его в карточке товара или укажите значение по умолчанию в сопоставлении.',
					$field->id(),
					Validation_Report::FIX_PRODUCT,
					'не заполнено поле «' . $field->name() . '»'
				);
			}
		}

		return $report;
	}
}
