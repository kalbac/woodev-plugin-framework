<?php
/**
 * What the export flow needs to know about a product that only the marketplace can answer.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * The local half of the marketplace's export rules: facts read from the plugin's own data (its catalogue cache, its media
 * rules), never from the marketplace itself. The export flow calls it while a merchant clicks (the validation report) and in the
 * job, so an implementation makes no remote call.
 *
 * @since 2.0.30
 */
interface Export_Facts {
	/**
	 * Returns the marketplace's facts for the validator: the keys of {@see \Woodev\Framework\Marketplace\Catalog\Validation_Context}
	 * it knows (`marketplace_name`, `fields`, `remote_category_exists`, `aspect_field_ids`, `shared_field_ids`, `image_urls`,
	 * `unsupported_image_sources`, `parent_product`, ...). The flow adds the keys it resolves itself (the
	 * article policy, the price source, the frozen article, the price, the siblings, who holds an article) and they win.
	 *
	 * @since 2.0.30
	 *
	 * @param Account     $account Account the card goes to.
	 * @param \WC_Product $product Product or variation.
	 * @param Mapping     $mapping Effective mapping.
	 * @param Profile     $profile Profile.
	 * @return array<string,mixed>
	 */
	public function facts( Account $account, \WC_Product $product, Mapping $mapping, Profile $profile ): array;

	/**
	 * Returns the currency the account's cabinet holds its prices in, as the account's last connection test stored it.
	 *
	 * @since 2.0.30
	 *
	 * @param Account $account Account.
	 * @return string ISO code.
	 */
	public function account_currency( Account $account ): string;

	/**
	 * Returns the seller's article a product would be sent with: the frozen one, else the profile's source for the product,
	 * normalised by the marketplace's article policy.
	 *
	 * @since 2.0.30
	 *
	 * @param string|null $frozen  The article already sent for the link, or null while it may still follow the product.
	 * @param \WC_Product $product Product or variation.
	 * @param Profile     $profile Profile.
	 * @return string
	 */
	public function offer_id_for( ?string $frozen, \WC_Product $product, Profile $profile ): string;

	/**
	 * Tells whether a product or variation becomes a card on this marketplace (for a marketplace that sells goods that ship,
	 * a virtual or downloadable product does not).
	 *
	 * @since 2.0.30
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return bool
	 */
	public function is_exportable( \WC_Product $product ): bool;
}
