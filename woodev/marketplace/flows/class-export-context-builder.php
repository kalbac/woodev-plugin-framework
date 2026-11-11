<?php
/**
 * Assembles the export inputs of a product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Article_Policy;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Currency;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Price\Price_Rule;
use Woodev\Framework\Marketplace\Price\Price_Source;
use Woodev\Framework\Marketplace\Sync\Price_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves, for one product of one account: the effective mapping (explicit `mapping_id`, then product categories, then their
 * ancestors, with the link overrides laid over it), the profile, and the validator context — the marketplace's own facts
 * ({@see Export_Facts}) plus what only the export knows: the article that will be sent, the price rule, the sibling variations and
 * their mappings.
 *
 * Price: {@see Export_Price_Policy} works out the card's price once (rule of the profile or the account, then the link override);
 * the validator and the card builder both read that pair. The same answers serve the price sync, which asks for them through
 * {@see Price_Context}.
 *
 * @since 2.0.30
 */
final class Export_Context_Builder implements Price_Context {
	/**
	 * Mapping resolution.
	 *
	 * @var Link_Context_Resolver
	 */
	private $resolver;

	/**
	 * Profiles.
	 *
	 * @var Wpdb_Profile_Store
	 */
	private $profiles;

	/**
	 * Links, to tell who holds an article.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * The marketplace's facts.
	 *
	 * @var Export_Facts
	 */
	private $facts;

	/**
	 * The marketplace's article rules.
	 *
	 * @var Article_Policy
	 */
	private $article_policy;

	/**
	 * The article the merchant chose for a product before it was sent.
	 *
	 * @var Pinned_Offer_Id
	 */
	private $pinned;

	/**
	 * The marketplace's own price source, if it has one.
	 *
	 * @var Price_Source|null
	 */
	private $price_source;

	/**
	 * Lists a product's category term ids, closest first: `function ( \WC_Product ): int[]`.
	 *
	 * @var callable
	 */
	private $term_ids;

	/**
	 * Loads a product: `function ( int $id ): ?\WC_Product`.
	 *
	 * @var callable
	 */
	private $load;

	/**
	 * Task ledger, to tell whether a link's article was already sent.
	 *
	 * @var Task_Ledger|null
	 */
	private $ledger;

	/**
	 * Creates the builder.
	 *
	 * @since 2.0.30
	 *
	 * @param Link_Context_Resolver $resolver       Mapping resolution.
	 * @param Wpdb_Profile_Store    $profiles       Profiles.
	 * @param Wpdb_Link_Store       $links          Links.
	 * @param Export_Facts          $facts          The marketplace's facts.
	 * @param Article_Policy        $article_policy The marketplace's article rules.
	 * @param Pinned_Offer_Id       $pinned         The article the merchant chose for a product before it was sent.
	 * @param Price_Source|null     $price_source   The marketplace's own price source, or null.
	 * @param callable|null         $term_ids       `function ( \WC_Product ): int[]`; {@see Category_Ancestry::for_product()} when null.
	 * @param callable|null         $load           `function ( int $id ): ?\WC_Product`; `wc_get_product()` when null.
	 * @param Task_Ledger|null      $ledger         Task ledger; without it only the link row decides whether the article is frozen.
	 */
	public function __construct( Link_Context_Resolver $resolver, Wpdb_Profile_Store $profiles, Wpdb_Link_Store $links, Export_Facts $facts, Article_Policy $article_policy, Pinned_Offer_Id $pinned, ?Price_Source $price_source = null, ?callable $term_ids = null, ?callable $load = null, ?Task_Ledger $ledger = null ) {
		$this->resolver       = $resolver;
		$this->profiles       = $profiles;
		$this->links          = $links;
		$this->facts          = $facts;
		$this->article_policy = $article_policy;
		$this->pinned         = $pinned;
		$this->price_source   = $price_source;
		$this->term_ids       = $term_ids ?? [ Category_Ancestry::class, 'for_product' ];
		$this->ledger         = $ledger;
		$this->load           = $load ?? static function ( int $id ): ?\WC_Product {
			$product = wc_get_product( $id );

			return $product instanceof \WC_Product ? $product : null;
		};
	}

	/**
	 * Builds the inputs of a product.
	 *
	 * @since 2.0.30
	 *
	 * @param Account          $account Account.
	 * @param \WC_Product      $product Product or variation.
	 * @param Link_Record|null $link    Its offer row, null when it has none yet.
	 * @return Export_Context
	 */
	public function build( Account $account, \WC_Product $product, ?Link_Record $link ): Export_Context {
		$mapping = $this->mapping( $account, $product );
		$profile = $this->profile( $mapping );
		$link    = $link ?? Link_Record::offer( $account->id(), (int) $product->get_id(), (int) $product->get_parent_id(), '' );
		$article = $this->facts->offer_id_for( $this->frozen_offer_id( $link ) ?? $this->pinned->of( $product, $account->id() ), $product, $profile );

		$facts = array_merge(
			$this->facts->facts( $account, $product, $mapping, $profile ),
			$this->own_facts( $account ),
			[
				'frozen_offer_id' => $article,
				'siblings'        => $this->siblings( $account, $product ),
			],
			$this->price_facts( $account, $profile, $link, $product )
		);

		return new Export_Context(
			$product,
			$mapping,
			$this->resolver->override_values( $account->id(), (int) $product->get_id(), (int) $product->get_parent_id() ),
			$profile,
			$facts
		);
	}

	/**
	 * Returns the article a link would be sent with now: the frozen one, else the profile's source for the current product.
	 *
	 * @since 2.0.30
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product or variation.
	 * @param Link_Record $link    Its offer row.
	 * @return string
	 */
	public function offer_id( Account $account, \WC_Product $product, Link_Record $link ): string {
		return $this->facts->offer_id_for( $this->frozen_offer_id( $link ) ?? $this->pinned->of( $product, $account->id() ), $product, $this->profile( $this->mapping( $account, $product ) ) );
	}

	/**
	 * Works out what a product costs on the marketplace right now, in the cabinet currency: the rule of its profile (else the
	 * account), the profile's currency rule and the link's override. The one answer the price intents and the creation seeding read.
	 *
	 * @since 2.0.30
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product or variation.
	 * @param Link_Record $link    Its offer row.
	 * @return Price_Pair|null Null when the product has no price.
	 * @throws \InvalidArgumentException When the rule, the currency terms or the override cannot be read.
	 */
	public function price_pair( Account $account, \WC_Product $product, Link_Record $link ): ?Price_Pair {
		$profile = $this->profile( $this->mapping( $account, $product ) );
		$rule    = Export_Price_Policy::rule( $account, $profile );

		if ( ! self::has_price( $product ) ) {
			return null;
		}

		$currency = $this->currency_of( $account, $profile, $product );

		if ( $currency->is_unresolved() ) {
			throw new \InvalidArgumentException( 'The shop and the cabinet differ in currency and the profile has no currency rule.' );
		}

		return Export_Price_Policy::pair( $product, $rule, $link->price_rule_override(), $currency );
	}

	/**
	 * Renders a price pair as the payload kept in the sync state.
	 *
	 * @since 2.0.30
	 *
	 * @param Price_Pair $pair Prices.
	 * @return array<string,string|null>
	 */
	public function payload( Price_Pair $pair ): array {
		return Export_Price_Policy::payload( $pair );
	}

	/**
	 * Reads the rule and the currency terms of a product, for the inverse direction (`from_marketplace`).
	 *
	 * @since 2.0.30
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product or variation.
	 * @return array{rule:Price_Rule,currency:Price_Currency}
	 * @throws \InvalidArgumentException When the rule or the currency terms cannot be read.
	 */
	public function price_terms( Account $account, \WC_Product $product ): array {
		$profile = $this->profile( $this->mapping( $account, $product ) );

		return [
			'rule'     => Export_Price_Policy::rule( $account, $profile ),
			'currency' => $this->currency_of( $account, $profile, $product ),
		];
	}

	/**
	 * Undoes the rule on a marketplace price.
	 *
	 * @since 2.0.30
	 *
	 * @param Money                    $price     The marketplace's selling price.
	 * @param Money|null               $old_price The marketplace's crossed-out price, if any.
	 * @param Price_Rule               $rule      Price rule.
	 * @param array<string,mixed>|null $override  The link's price override.
	 * @param Price_Currency           $currency  Currency rule.
	 * @return array{regular:Money|null,sale:Money|null}|null
	 * @throws \InvalidArgumentException When the override cannot be read.
	 */
	public function invert( Money $price, ?Money $old_price, Price_Rule $rule, ?array $override, Price_Currency $currency ): ?array {
		return Export_Price_Policy::invert( $price, $old_price, $rule, $override, $currency );
	}

	/**
	 * Loads a product.
	 *
	 * @since 2.0.30
	 *
	 * @param int $product_id Product id.
	 * @return \WC_Product|null
	 */
	public function product( int $product_id ): ?\WC_Product {
		return call_user_func( $this->load, $product_id );
	}

	/**
	 * Returns the article a link must keep: frozen by its state or remote ids, or because a request already carried it (a reset or
	 * a retry after an uncertain outcome does not unfreeze it). Null while the article may still follow the SKU.
	 *
	 * @param Link_Record $link Link.
	 * @return string|null
	 */
	private function frozen_offer_id( Link_Record $link ): ?string {
		$frozen = $link->frozen_offer_id();

		if ( null !== $frozen || null === $this->ledger || null === $link->link_id() ) {
			return $frozen;
		}

		return $this->ledger->sent_offer_id( (int) $link->link_id() );
	}

	/**
	 * Returns the facts the flow resolves itself: the policies it was given, the currencies and who holds an article.
	 *
	 * @param Account $account Account.
	 * @return array<string,mixed>
	 */
	private function own_facts( Account $account ): array {
		$account_id = $account->id();

		return [
			'article_policy'   => $this->article_policy,
			'price_source'     => $this->price_source,
			'shop_currency'    => self::shop_currency(),
			'account_currency' => self::cabinet_currency( $this->facts->account_currency( $account ) ),
			'offer_id_owner'   => function ( string $offer_id ) use ( $account_id ): ?int {
				$owner = $this->links->find_by_offer_id( $account_id, $offer_id );

				return null === $owner ? null : $owner->wc_product_id();
			},
		];
	}

	/**
	 * Works out the card's price once, for the validator and the builder: the rule (profile, else account; both stored shapes), the
	 * profile's currency rule and the link's override. A rule, rate or override that cannot be read becomes a validation error; a
	 * product with no price is left to the validator's own «no price» check.
	 *
	 * @param Account     $account Account.
	 * @param Profile     $profile Profile.
	 * @param Link_Record $link    Link.
	 * @param \WC_Product $product Product.
	 * @return array<string,mixed> Context keys `price_rule`, `price_pair` or `price_error`.
	 */
	private function price_facts( Account $account, Profile $profile, Link_Record $link, \WC_Product $product ): array {
		try {
			$rule = Export_Price_Policy::rule( $account, $profile );
		} catch ( \InvalidArgumentException $e ) {
			return [ 'price_error' => __( 'Правило цены в профиле или в настройках аккаунта не распознано — откройте и сохраните его заново.', 'woodev-plugin-framework' ) ];
		}

		if ( ! self::has_price( $product ) ) {
			return [ 'price_rule' => $rule ];
		}

		try {
			$currency = $this->currency_of( $account, $profile, $product );
		} catch ( \InvalidArgumentException $e ) {
			return [ 'price_error' => __( 'Курс валюты или цена для маркетплейса не заданы либо записаны неверно — проверьте правило валюты в профиле и поле у товара.', 'woodev-plugin-framework' ) ];
		}

		try {
			$pair = Export_Price_Policy::pair( $product, $rule, $link->price_rule_override(), $currency );
		} catch ( \InvalidArgumentException $e ) {
			return [ 'price_error' => __( 'Цена товара для маркетплейса (своя цена, старая или минимальная) не распознана — проверьте её в карточке товара.', 'woodev-plugin-framework' ) ];
		}

		return [
			'price_rule' => $rule,
			'price_pair' => $pair,
		];
	}

	/**
	 * Returns the shop currency.
	 *
	 * @return string
	 */
	private static function shop_currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : 'RUB';
	}

	/**
	 * Normalises the cabinet currency the adapter reported.
	 *
	 * @param string $code Currency code.
	 * @return string
	 */
	private static function cabinet_currency( string $code ): string {
		return strtoupper( $code );
	}

	/**
	 * Tells whether a product has a price above zero.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	private static function has_price( \WC_Product $product ): bool {
		return is_numeric( $product->get_price() ) && (float) $product->get_price() > 0;
	}

	/**
	 * Reads the currency terms the profile's rule gives a product: the rate to multiply by, or the price the product holds for the
	 * marketplace.
	 *
	 * @param Account     $account Account.
	 * @param Profile     $profile Profile.
	 * @param \WC_Product $product Product or variation.
	 * @return Price_Currency
	 * @throws \InvalidArgumentException When a rate is not a number above zero, or a price field holds no price.
	 */
	private function currency_of( Account $account, Profile $profile, \WC_Product $product ): Price_Currency {
		$parent_id = (int) $product->get_parent_id();

		return Price_Currency::for_product( self::shop_currency(), self::cabinet_currency( $this->facts->account_currency( $account ) ), (string) $profile->setting( 'currency_rule', '' ), $product, $parent_id > 0 ? $this->product( $parent_id ) : null, $this->price_source );
	}

	/**
	 * Resolves the effective mapping; an empty one when nothing maps the product, which the validator reports.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product.
	 * @return Mapping
	 */
	private function mapping( Account $account, \WC_Product $product ): Mapping {
		$terms = (array) call_user_func( $this->term_ids, $product );

		return $this->resolver->effective_mapping( $account->id(), (int) $product->get_id(), $terms, (int) $product->get_parent_id() ) ?? new Mapping( null, [], null );
	}

	/**
	 * Loads the mapping's profile, else an empty one.
	 *
	 * @param Mapping $mapping Mapping.
	 * @return Profile
	 */
	private function profile( Mapping $mapping ): Profile {
		$profile = null !== $mapping->profile_id() ? $this->profiles->get( (int) $mapping->profile_id() ) : null;

		return $profile ?? new Profile( null, '' );
	}

	/**
	 * Lists the other exported variations of a variation's parent with their effective mappings (the validator's variation-group
	 * rule). A variation the marketplace never turns into a card is not compared.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product or variation.
	 * @return array<int,array{product:\WC_Product,mapping:Mapping}>
	 */
	private function siblings( Account $account, \WC_Product $product ): array {
		$parent_id = (int) $product->get_parent_id();
		$parent    = $parent_id > 0 ? $this->product( $parent_id ) : null;

		if ( null === $parent ) {
			return [];
		}

		$siblings = [];
		foreach ( array_map( 'intval', (array) $parent->get_children() ) as $child_id ) {
			$child = $child_id !== (int) $product->get_id() ? $this->product( $child_id ) : null;

			if ( null !== $child && $this->facts->is_exportable( $child ) ) {
				$siblings[] = [
					'product' => $child,
					'mapping' => $this->mapping( $account, $child ),
				];
			}
		}

		return $siblings;
	}
}
