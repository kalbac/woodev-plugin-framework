<?php
/**
 * Facts the validator needs from outside the product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Price\Price_Rule;
use Woodev\Framework\Marketplace\Price\Price_Source;

defined( 'ABSPATH' ) || exit;

/**
 * A typed reading of the `$context` array of {@see Export_Validator::validate()}. The validator makes no API call and uses no
 * WordPress function, so everything it cannot read from the product, the mapping and the profile arrives here, resolved by the
 * adapter that knows the marketplace.
 *
 * Keys (all optional; an absent key means what the accessor says):
 *
 * - `marketplace_name`: indeclinable name used in messages («Ozon»); absent = neutral wording («маркетплейса»).
 * - `parent_product`: the parent of a variation (`parent_name`, description / weight / dimension fallbacks); absent = none.
 * - `unsupported_image_sources`: profile image-source values that name no image list; each is an error.
 * - `scope`: `Export_Validator::SCOPE_FULL` (default) or `SCOPE_MAPPING` (only the category and the field sources).
 * - `fields`: `Field_Definition[]` of the mapped category; absent = unknown, which the full scope reports as an error.
 * - `remote_category_exists`: bool, whether the mapped category is still in the marketplace tree; absent = unknown, not checked.
 * - `dictionary_lookup`: `callable( Field_Definition, string $label ): ?string` turning a label into a dictionary value id from
 *   local data (it must not call the marketplace); absent = no label can be resolved.
 * - `image_urls`: `string[]` image URLs of the card in sending order; absent = none.
 * - `offer_id_owner`: `callable( string $offer_id ): ?int` giving the product id that already holds the article in the account,
 *   archived cards included; absent = uniqueness is not checked.
 * - `article_policy`: the marketplace's `Article_Policy`; absent = the article cannot be worked out, so its uniqueness is not checked.
 * - `price_source`: the marketplace's `Price_Source` for currency-rule shapes of its own; absent = such a rule is unsupported.
 * - `frozen_offer_id`: the article already sent for this product, if any.
 * - `price_rule`: the `Price_Rule` in force (account, profile and link already merged); absent = no markup.
 * - `price_pair`: the `Price_Pair` the card will carry, already worked out by the adapter (rule and per-link override); when present
 *   the price checks use it instead of applying `price_rule` themselves.
 * - `price_error`: a Russian reason why no price can be worked out (an unreadable stored rule); the price check reports it.
 * - `shop_currency`: ISO code of the shop currency (default `RUB`); `account_currency`: ISO code of the cabinet currency, absent =
 *   not checked.
 * - `siblings`: `array{product:WC_Product,mapping:Mapping}[]`, the other variations of the same parent with their mappings.
 * - `shared_field_ids`: field ids whose value every variation of a parent must share (Ozon 9048, the model name).
 * - `aspect_field_ids`: field ids in which two variations of a parent must differ.
 *
 * @since 2.0.29
 */
final class Validation_Context {
	/**
	 * Raw context.
	 *
	 * @var array<string,mixed>
	 */
	private $data;

	/**
	 * Creates the context.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $data Raw context.
	 */
	public function __construct( array $data = [] ) {
		$this->data = $data;
	}

	/**
	 * Returns the marketplace's name for merchant messages. It is an indeclinable name («Ozon»), so one value fits every case.
	 *
	 * @since 2.0.29
	 *
	 * @param string $fallback Neutral wording in the grammatical case the message needs («маркетплейса»).
	 * @return string The adapter's name when it gave one, else the fallback.
	 */
	public function marketplace( string $fallback ): string {
		$name = trim( (string) ( $this->data['marketplace_name'] ?? '' ) );

		return '' !== $name ? $name : $fallback;
	}

	/**
	 * Returns the parent of a variation, as the adapter loaded it.
	 *
	 * @since 2.0.29
	 *
	 * @return \WC_Product|null
	 */
	public function parent_product(): ?\WC_Product {
		$parent = $this->data['parent_product'] ?? null;

		return $parent instanceof \WC_Product ? $parent : null;
	}

	/**
	 * Returns the profile's image-source values that name no image list (so the card would have no image from them).
	 *
	 * @since 2.0.29
	 *
	 * @return string[]
	 */
	public function unsupported_image_sources(): array {
		return array_values( array_map( 'strval', (array) ( $this->data['unsupported_image_sources'] ?? [] ) ) );
	}

	/**
	 * Returns the scope.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function scope(): string {
		return Export_Validator::SCOPE_MAPPING === ( $this->data['scope'] ?? null ) ? Export_Validator::SCOPE_MAPPING : Export_Validator::SCOPE_FULL;
	}

	/**
	 * Returns the fields of the mapped category.
	 *
	 * @since 2.0.29
	 *
	 * @return Field_Definition[]
	 */
	public function fields(): array {
		return array_values(
			array_filter(
				(array) ( $this->data['fields'] ?? [] ),
				static function ( $field ): bool {
					return $field instanceof Field_Definition;
				}
			)
		);
	}

	/**
	 * Tells whether the caller supplied the fields of the category. Without them «required» cannot be judged.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function fields_known(): bool {
		return is_array( $this->data['fields'] ?? null );
	}

	/**
	 * Tells whether the mapped category is still in the marketplace tree.
	 *
	 * @since 2.0.29
	 *
	 * @return bool|null Null when unknown.
	 */
	public function remote_category_exists(): ?bool {
		return isset( $this->data['remote_category_exists'] ) ? (bool) $this->data['remote_category_exists'] : null;
	}

	/**
	 * Returns the local dictionary lookup.
	 *
	 * @since 2.0.29
	 *
	 * @return callable|null
	 */
	public function dictionary_lookup(): ?callable {
		$lookup = $this->data['dictionary_lookup'] ?? null;

		return is_callable( $lookup ) ? $lookup : null;
	}

	/**
	 * Returns the card's image URLs.
	 *
	 * @since 2.0.29
	 *
	 * @return string[]
	 */
	public function image_urls(): array {
		return array_values(
			array_filter(
				array_map( 'strval', (array) ( $this->data['image_urls'] ?? [] ) ),
				static function ( string $url ): bool {
					return '' !== $url;
				}
			)
		);
	}

	/**
	 * Returns the article-owner lookup.
	 *
	 * @since 2.0.29
	 *
	 * @return callable|null
	 */
	public function offer_id_owner(): ?callable {
		$owner = $this->data['offer_id_owner'] ?? null;

		return is_callable( $owner ) ? $owner : null;
	}

	/**
	 * Returns the marketplace's article policy.
	 *
	 * @since 2.0.29
	 *
	 * @return Article_Policy|null Null when the adapter supplied none.
	 */
	public function article_policy(): ?Article_Policy {
		$policy = $this->data['article_policy'] ?? null;

		return $policy instanceof Article_Policy ? $policy : null;
	}

	/**
	 * Returns the marketplace's price source for currency-rule shapes of its own.
	 *
	 * @since 2.0.29
	 *
	 * @return Price_Source|null Null when the adapter supplied none.
	 */
	public function price_source(): ?Price_Source {
		$source = $this->data['price_source'] ?? null;

		return $source instanceof Price_Source ? $source : null;
	}

	/**
	 * Returns the article already sent for the product.
	 *
	 * @since 2.0.29
	 *
	 * @return string|null
	 */
	public function frozen_offer_id(): ?string {
		$frozen = $this->data['frozen_offer_id'] ?? null;

		return is_string( $frozen ) && '' !== $frozen ? $frozen : null;
	}

	/**
	 * Returns the price rule in force.
	 *
	 * @since 2.0.29
	 *
	 * @return Price_Rule
	 */
	public function price_rule(): Price_Rule {
		$rule = $this->data['price_rule'] ?? null;

		return $rule instanceof Price_Rule ? $rule : new Price_Rule();
	}

	/**
	 * Returns the price triple the adapter worked out, or null when it supplied none.
	 *
	 * @since 2.0.29
	 *
	 * @return Price_Pair|null
	 */
	public function price_pair(): ?Price_Pair {
		$pair = $this->data['price_pair'] ?? null;

		return $pair instanceof Price_Pair ? $pair : null;
	}

	/**
	 * Returns why no price can be worked out, or null.
	 *
	 * @since 2.0.29
	 *
	 * @return string|null
	 */
	public function price_error(): ?string {
		$error = trim( (string) ( $this->data['price_error'] ?? '' ) );

		return '' === $error ? null : $error;
	}

	/**
	 * Returns the shop currency.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function shop_currency(): string {
		$currency = strtoupper( (string) ( $this->data['shop_currency'] ?? '' ) );

		return '' === $currency ? 'RUB' : $currency;
	}

	/**
	 * Returns the cabinet currency.
	 *
	 * @since 2.0.29
	 *
	 * @return string|null Null when unknown.
	 */
	public function account_currency(): ?string {
		$currency = strtoupper( (string) ( $this->data['account_currency'] ?? '' ) );

		return '' === $currency ? null : $currency;
	}

	/**
	 * Returns the other variations of the same parent.
	 *
	 * @since 2.0.29
	 *
	 * @return array<int,array{product:\WC_Product,mapping:Mapping}>
	 */
	public function siblings(): array {
		$siblings = [];

		foreach ( (array) ( $this->data['siblings'] ?? [] ) as $sibling ) {
			if ( is_array( $sibling ) && ( $sibling['product'] ?? null ) instanceof \WC_Product && ( $sibling['mapping'] ?? null ) instanceof Mapping ) {
				$siblings[] = [
					'product' => $sibling['product'],
					'mapping' => $sibling['mapping'],
				];
			}
		}

		return $siblings;
	}

	/**
	 * Returns the field ids every variation of a parent must share.
	 *
	 * @since 2.0.29
	 *
	 * @return string[]
	 */
	public function shared_field_ids(): array {
		return array_values( array_map( 'strval', (array) ( $this->data['shared_field_ids'] ?? [] ) ) );
	}

	/**
	 * Returns the field ids in which two variations must differ.
	 *
	 * @since 2.0.29
	 *
	 * @return string[]
	 */
	public function aspect_field_ids(): array {
		return array_values( array_map( 'strval', (array) ( $this->data['aspect_field_ids'] ?? [] ) ) );
	}
}
