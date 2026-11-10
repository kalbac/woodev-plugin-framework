<?php
/**
 * Rule: the price is above zero and the old price is sane.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog\Rules;

use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Validation_Context;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Validation_Rule;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Calculator;
use Woodev\Framework\Marketplace\Price\Price_Pair;

defined( 'ABSPATH' ) || exit;

/**
 * The selling price, after the price rule of the context, must be above zero, and the old price must be absent or above the selling
 * price (a marketplace may answer «Old price is less than price»). A marketplace whose price model differs leaves this rule out of
 * its full-scope rule list.
 *
 * @since 2.0.29
 */
final class Selling_Price_Rule implements Validation_Rule {
	public const CODE_PRICE     = 'price_missing';
	public const CODE_OLD_PRICE = 'old_price_below_price';
	public const CODE_RULE      = 'price_rule_invalid';

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
		if ( null !== $context->price_error() ) {
			return $report->with_issue( Validation_Report::ERROR, self::CODE_RULE, (string) $context->price_error(), null, Validation_Report::FIX_PROFILE, 'правило цены не распознано' );
		}

		$pair = $context->price_pair() ?? $this->pair( $product, $context );
		$pair = null !== $pair && $pair->price()->is_positive() ? $pair : null;

		if ( null === $pair ) {
			return $report->with_issue(
				Validation_Report::ERROR,
				self::CODE_PRICE,
				'У товара не указана цена (или она равна нулю) — заполните «Базовую цену» на вкладке «Основные» в карточке товара.',
				null,
				Validation_Report::FIX_PRODUCT,
				'нет цены'
			);
		}

		return $this->check_pair( $pair, $report );
	}

	/**
	 * Checks a calculated price pair.
	 *
	 * @since 2.0.29
	 *
	 * @param Price_Pair        $pair   Price and old price as they would be sent.
	 * @param Validation_Report $report Report so far.
	 * @return Validation_Report
	 */
	public function check_pair( Price_Pair $pair, Validation_Report $report ): Validation_Report {
		$old = $pair->old_price();

		if ( null === $old || $old->is_zero() || $old->compare( $pair->price() ) > 0 ) {
			return $report;
		}

		return $report->with_issue(
			Validation_Report::ERROR,
			self::CODE_OLD_PRICE,
			'Старая цена (' . $old->to_string() . ') должна быть выше цены продажи (' . $pair->price()->to_string() . ') или не задана — поправьте правило цены в профиле.',
			null,
			Validation_Report::FIX_PROFILE,
			'старая цена не выше цены'
		);
	}

	/**
	 * Calculates the pair the product would be sent with.
	 *
	 * @param \WC_Product        $product Product.
	 * @param Validation_Context $context Context.
	 * @return Price_Pair|null Null when the product has no usable price.
	 */
	private function pair( \WC_Product $product, Validation_Context $context ): ?Price_Pair {
		$currency = $context->shop_currency();

		try {
			$base    = Money::from_string( (string) $product->get_price(), $currency );
			$regular = '' === (string) $product->get_regular_price() ? null : Money::from_string( (string) $product->get_regular_price(), $currency );
			$pair    = Price_Calculator::calculate( $base, $regular, $context->price_rule() );
		} catch ( \InvalidArgumentException $exception ) {
			return null;
		}

		return $pair->price()->is_positive() ? $pair : null;
	}
}
