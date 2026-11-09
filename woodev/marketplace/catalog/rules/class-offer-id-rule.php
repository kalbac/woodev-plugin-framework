<?php
/**
 * Rule: the article is unique in the account.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog\Rules;

use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Validation_Context;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Validation_Rule;
use Woodev\Framework\Marketplace\Catalog\Offer_Id;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the article the card builder will send ({@see Offer_Id}) against the account's articles, archived cards included (the
 * marketplace refuses a repeated article). The adapter supplies the owner lookup and the article policy; without either the rule cannot judge and stays silent.
 *
 * @since 2.0.29
 */
final class Offer_Id_Rule implements Validation_Rule {
	public const CODE = 'offer_id_taken';

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
		$lookup = $context->offer_id_owner();
		$policy = $context->article_policy();

		if ( null === $lookup || null === $policy ) {
			return $report;
		}

		$offer_id = ( new Offer_Id( $policy ) )->for_product( $context->frozen_offer_id(), (string) $product->get_sku(), (int) $product->get_id() );
		$owner    = call_user_func( $lookup, $offer_id );

		if ( null === $owner || (int) $owner === (int) $product->get_id() ) {
			return $report;
		}

		return $report->with_issue(
			Validation_Report::ERROR,
			self::CODE,
			'Артикул «' . $offer_id . '» уже занят другим товаром (ID ' . (int) $owner . ') в этом кабинете, в том числе архивным — измените артикул (SKU) товара.',
			null,
			Validation_Report::FIX_PRODUCT,
			'артикул уже занят'
		);
	}
}
