<?php
/**
 * Rule: the shop currency matches the cabinet currency.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog\Rules;

use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Validation_Context;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Validation_Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Prices are sent in the cabinet's currency. When the shop sells in another one, the profile must say how to get the cabinet price
 * (`currency_rule`: `fixed:CODE`, `rate:N`, or a shape of the marketplace's own). Without a rule the number would go out as if the
 * currencies were equal (`[#3344]`, `[#13748]`). An unknown cabinet currency is not checked.
 *
 * The rule itself judges only the neutral shapes (empty, `fixed:` and `rate:`). Any other shape goes to the adapter's
 * {@see \Woodev\Framework\Marketplace\Price\Price_Source}, which says whether the shape is its own and whether the product holds a usable price for it; with no source
 * for the shape the rule reports the profile's rule as unsupported.
 *
 * @since 2.0.29
 */
final class Currency_Rule implements Validation_Rule {
	public const CODE = 'currency_mismatch';

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
		$cabinet = $context->account_currency();
		$shop    = $context->shop_currency();
		$rule    = trim( (string) $profile->setting( 'currency_rule', '' ) );

		$source = $context->price_source();
		$own    = '' !== $rule && null !== $source && $source->supports( $rule );

		if ( '' !== $rule && ! $own && ! self::is_neutral_rule( $rule ) ) {
			return $this->issue( $report, 'Правило валюты в профиле («' . $rule . '») не распознано или курс в нём не больше нуля — исправьте его в профиле.' );
		}

		$problem = $own ? $source->problem( $rule, $product, $context->parent_product() ) : null;

		if ( null !== $problem ) {
			return $this->issue( $report, $problem );
		}

		if ( null === $cabinet ) {
			return $report;
		}

		if ( 0 === strpos( $rule, 'fixed:' ) ) {
			$fixed = strtoupper( substr( $rule, 6 ) );

			return $fixed === $cabinet ? $report : $this->issue( $report, 'В профиле выбрана валюта ' . $fixed . ', а валюта кабинета — ' . $cabinet . ' — исправьте правило валюты в профиле.' );
		}

		if ( '' === $rule && $cabinet !== $shop ) {
			return $this->issue( $report, 'Валюта магазина ' . $shop . ', валюта кабинета ' . $cabinet . ' — укажите курс или цену для ' . $context->marketplace( 'маркетплейса' ) . ' в профиле.' );
		}

		return $report;
	}

	/**
	 * Tells whether a currency rule has a neutral supported shape: `fixed:CODE`, or `rate:N` with N above zero.
	 *
	 * @param string $rule Rule.
	 * @return bool
	 */
	private static function is_neutral_rule( string $rule ): bool {
		if ( 1 === preg_match( '/^fixed:[A-Za-z]{3}$/', $rule ) ) {
			return true;
		}

		return 1 === preg_match( '/^rate:(\d+(?:\.\d+)?)$/', $rule, $parts ) && (float) $parts[1] > 0;
	}

	/**
	 * Adds the currency error.
	 *
	 * @param Validation_Report $report  Report so far.
	 * @param string            $message Russian reason.
	 * @return Validation_Report
	 */
	private function issue( Validation_Report $report, string $message ): Validation_Report {
		return $report->with_issue( Validation_Report::ERROR, self::CODE, $message, null, Validation_Report::FIX_PROFILE, 'валюта магазина не совпадает с валютой кабинета' );
	}
}
