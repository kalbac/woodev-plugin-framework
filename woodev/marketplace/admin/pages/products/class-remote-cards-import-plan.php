<?php
/**
 * What «Импортировать» will do with each selected card of the «Карточки на маркетплейсе» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Flows\Group_Assembler;

defined( 'ABSPATH' ) || exit;

/**
 * Sorts the selected cards into the ones the import job will take and the ones it leaves, with the reason for each. A pure decision
 * over local facts (the snapshot's card, the links, the shop's product with the card's article): the preview and the apply of the
 * action both call it, so the two cannot disagree. It follows the first rows of the importer's order, for each card:
 *
 * 1. the article has a link → {@see self::ON_SITE};
 * 2. the card is archived → {@see self::ARCHIVED};
 * 3. a shop product has the article: a variable parent → {@see self::VARIABLE_PARENT}; a half-made product of an earlier import
 *    (it carries the import marker) → created again, resuming it; any other product → the card will be linked to it.
 * 4. no such product → the card will create one.
 *
 * Before those, a card the list does not hold, a card whose details did not agree with the list ({@see self::INCOMPLETE}) and a card
 * that is already being imported ({@see self::IMPORTING}) are left out.
 *
 * **Groups.** The cards of one marketplace group become one variable product ({@see Group_Assembler}), so the plan also counts
 * the products behind the cards to create: a snapshot card names its group in `group` (`key`, '' or absent when it stands alone
 * — the member {@see \Woodev\Framework\Marketplace\Catalog\Card_Content::group()} has), three colour cards of one group are
 * **one** product, and a card whose group already has a product on the site adds none: it joins that product as a variation.
 *
 * @since 2.1.6
 */
final class Remote_Cards_Import_Plan {
	/**
	 * The card will get a new product.
	 */
	public const CREATE = 'create';

	/**
	 * The card will be linked to the product that carries its article.
	 */
	public const LINK = 'link';

	public const ON_SITE         = 'on_site';
	public const ARCHIVED        = 'archived';
	public const VARIABLE_PARENT = 'variable_parent';
	public const INCOMPLETE      = 'incomplete';
	public const NOT_IN_LIST     = 'not_in_list';
	public const IMPORTING       = 'importing';

	/**
	 * Kind of the shop product that carries an article: no product.
	 */
	public const SKU_NONE = 'none';

	/**
	 * Kind of the shop product that carries an article: a variable parent.
	 */
	public const SKU_VARIABLE = 'variable';

	/**
	 * Kind of the shop product that carries an article: a half-made product of an import that was cut short.
	 */
	public const SKU_MARKED = 'marked';

	/**
	 * Kind of the shop product that carries an article: any other product (simple, or a variation).
	 */
	public const SKU_PLAIN = 'plain';

	/**
	 * Names the product that carries an article: `function ( string $sku ): string`, one of the `SKU_*` kinds.
	 *
	 * @var callable
	 */
	private $sku_kind;

	/**
	 * Finds the product a group already has on the site: `function ( int $account_id, string $key ): int`, 0 when it has none.
	 *
	 * @var callable
	 */
	private $group_product;

	/**
	 * Creates the plan builder.
	 *
	 * @since 2.1.6
	 *
	 * @param callable $sku_kind      `function ( string $sku ): string`, one of the `SKU_*` kinds; {@see self::woocommerce_lookup()} in production.
	 * @param callable $group_product `function ( int $account_id, string $key ): int`, the product a group already has, 0 for none; {@see Group_Assembler::lookup()} in production.
	 */
	public function __construct( callable $sku_kind, callable $group_product ) {
		$this->sku_kind      = $sku_kind;
		$this->group_product = $group_product;
	}

	/**
	 * Looks the article up among the shop's products with WooCommerce's own SKU lookup. A read; nothing is written.
	 *
	 * @since 2.1.6
	 *
	 * @param string $marker_key Meta key an import marks its half-made product with ({@see \Woodev\Framework\Marketplace\Flows\Product_Writer::marker_key()}).
	 * @return callable `function ( string $sku ): string`.
	 */
	public static function woocommerce_lookup( string $marker_key ): callable {
		return static function ( string $sku ) use ( $marker_key ): string {
			$product = '' === $sku ? null : wc_get_product( (int) wc_get_product_id_by_sku( $sku ) );

			if ( ! $product ) {
				return self::SKU_NONE;
			}

			if ( $product->is_type( 'variable' ) ) {
				return self::SKU_VARIABLE;
			}

			return '' !== (string) $product->get_meta( $marker_key ) ? self::SKU_MARKED : self::SKU_PLAIN;
		};
	}

	/**
	 * Sorts the selected cards.
	 *
	 * @since 2.1.6
	 *
	 * @param array<int,array<string,mixed>|null> $cards      The snapshot's card of each selected row, null where the list no longer holds it, in the selection's order.
	 * @param array<string,int>                   $linked     Product id by the article of every card that has a link.
	 * @param string[]                            $importing  Articles that have a pending or running import.
	 * @param int                                 $account_id The account the cards belong to: a group is looked up among its products.
	 * @return array{create:string[],link:string[],skipped:array<string,string[]>,products:int,joined:int} Articles to create, articles to link, and the left-out articles by reason; a card the list no longer holds has no article and is an empty string under {@see self::NOT_IN_LIST}. `products` is how many products the `create` articles make — the cards of one group make one — and `joined` how many of them become variations of products that are on the site already.
	 */
	public function build( array $cards, array $linked, array $importing, int $account_id ): array {
		$plan   = [
			self::CREATE => [],
			self::LINK   => [],
			'skipped'    => [],
		];
		$seen   = [];
		$groups = [];

		foreach ( $cards as $card ) {
			$offer_id = null === $card ? '' : (string) $card['offer_id'];

			if ( null !== $card && isset( $seen[ $offer_id ] ) ) {
				continue;
			}

			$seen[ $offer_id ] = true;
			$outcome           = null === $card ? self::NOT_IN_LIST : $this->outcome( $card, $linked, $importing );

			if ( self::CREATE === $outcome ) {
				$groups[] = Group_Assembler::key_of( (array) ( $card['group'] ?? [] ) );
			}

			if ( in_array( $outcome, [ self::CREATE, self::LINK ], true ) ) {
				$plan[ $outcome ][] = $offer_id;
			} else {
				$plan['skipped'][ $outcome ][] = $offer_id;
			}
		}

		return array_merge( $plan, $this->products( $groups, $account_id ) );
	}

	/**
	 * Counts the products the cards to create make. A card with no group makes one; the cards of one group make one between
	 * them; a card whose group has a product on the site makes none and joins that product as a variation. The same group
	 * decision as the job's ({@see Group_Assembler::key_of()} and the group's product lookup).
	 *
	 * @param string[] $groups     Group key of each card to create, '' for a card with no group.
	 * @param int      $account_id Account id.
	 * @return array{products:int,joined:int}
	 */
	private function products( array $groups, int $account_id ): array {
		$count = [
			'products' => 0,
			'joined'   => 0,
		];
		$known = [];

		foreach ( $groups as $key ) {
			if ( '' === $key ) {
				++$count['products'];
				continue;
			}

			if ( ! isset( $known[ $key ] ) ) {
				$known[ $key ] = (int) call_user_func( $this->group_product, $account_id, $key ) > 0 ? 'joined' : 'products';

				if ( 'products' === $known[ $key ] ) {
					++$count['products'];
				}
			}

			if ( 'joined' === $known[ $key ] ) {
				++$count['joined'];
			}
		}

		return $count;
	}

	/**
	 * What the import does with one card.
	 *
	 * @param array<string,mixed> $card      Snapshot card.
	 * @param array<string,int>   $linked    Product id by article of the linked cards.
	 * @param string[]            $importing Articles being imported.
	 * @return string One of the outcomes of this class.
	 */
	private function outcome( array $card, array $linked, array $importing ): string {
		$offer_id = (string) $card['offer_id'];

		if ( in_array( $offer_id, $importing, true ) ) {
			return self::IMPORTING;
		}

		if ( isset( $linked[ $offer_id ] ) ) {
			return self::ON_SITE;
		}

		if ( empty( $card['details_ok'] ) ) {
			return self::INCOMPLETE;
		}

		if ( ! empty( $card['archived'] ) || Link_Record::STATE_ARCHIVED === (string) ( $card['state'] ?? '' ) ) {
			return self::ARCHIVED;
		}

		return $this->by_article( $offer_id );
	}

	/**
	 * @param string $offer_id Article.
	 * @return string {@see self::CREATE}, {@see self::LINK} or {@see self::VARIABLE_PARENT}.
	 */
	private function by_article( string $offer_id ): string {
		switch ( call_user_func( $this->sku_kind, $offer_id ) ) {
			case self::SKU_VARIABLE:
				return self::VARIABLE_PARENT;
			case self::SKU_PLAIN:
				return self::LINK;
			default:
				return self::CREATE;
		}
	}
}
