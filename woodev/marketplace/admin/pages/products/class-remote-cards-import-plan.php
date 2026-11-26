<?php
/**
 * What «Импортировать» will do with each selected card of the «Карточки на маркетплейсе» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Flows\Group_Assembler;
use Woodev\Framework\Marketplace\Flows\Unit_Products;

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
 * For a marketplace that sells units the third and fourth rows are decided by {@see self::unit_lookup()} instead of the SKU:
 * several products matching the adapter's keys leave the card out ({@see self::AMBIGUOUS}).
 *
 * Before those, a card the list does not hold, a card whose details did not agree with the list ({@see self::INCOMPLETE}) and a card
 * that is already being imported ({@see self::IMPORTING}) are left out.
 *
 * **Groups.** The cards of one marketplace group become one variable product ({@see Group_Assembler}), so the plan also counts
 * the products behind the cards to create: a snapshot card names its group in `group` (`key`, '' or absent when it stands alone
 * — the member {@see \Woodev\Framework\Marketplace\Catalog\Card_Content::group()} has), three colour cards of one group are
 * **one** product, and a card whose group already has a product on the site adds none: it joins that product as a variation.
 * What a group has on the site is the job's own decision ({@see Group_Assembler::lookup()}): a card whose group's product is
 * half-made is left out ({@see self::GROUP_BUILDING}), because the job would skip it — unless the selection also holds the card
 * that resumes that product, which is then listed **first** so that the job finishes it before its siblings join.
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
	 * Another card of the group is half-made (its import was cut short): the job builds nothing upon it, so the card waits.
	 */
	public const GROUP_BUILDING = 'group_building';

	/**
	 * Several shop products match the unit: the merchant links it by hand.
	 */
	public const AMBIGUOUS = 'ambiguous';

	/**
	 * The card resumes its own half-made product. Inside the plan only: such a card is listed under {@see self::CREATE}, first.
	 */
	private const RESUME = 'resume';

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
	 * Kind of the shop products a unit matches: several.
	 */
	public const SKU_SEVERAL = 'several';

	/**
	 * Names the product that carries an article: `function ( string $sku ): string`, one of the `SKU_*` kinds.
	 *
	 * @var callable
	 */
	private $sku_kind;

	/**
	 * Tells what a group has on the site: `function ( int $account_id, string $key ): string`, one of
	 * {@see Group_Assembler::NONE}, {@see Group_Assembler::BUILDING} and {@see Group_Assembler::READY}.
	 *
	 * @var callable
	 */
	private $group_state;

	/**
	 * Names the products of unit cards: `function ( int $account_id, array $cards ): array<string,string>`, a `SKU_*` kind by article; null for a marketplace whose article is the SKU.
	 *
	 * @var callable|null
	 */
	private $unit_kinds;

	/**
	 * The kinds of the cards of the plan being built.
	 *
	 * @var array<string,string>
	 */
	private $kinds = [];

	/**
	 * Creates the plan builder.
	 *
	 * @since 2.1.6
	 * @since 2.1.23 `$unit_kinds`.
	 *
	 * @param callable      $sku_kind    `function ( string $sku ): string`, one of the `SKU_*` kinds; {@see self::woocommerce_lookup()} in production.
	 * @param callable      $group_state `function ( int $account_id, string $key ): string`, what a group has on the site; {@see Group_Assembler::lookup()} in production.
	 * @param callable|null $unit_kinds  {@see self::unit_lookup()}, for an adapter that sells units.
	 */
	public function __construct( callable $sku_kind, callable $group_state, ?callable $unit_kinds = null ) {
		$this->sku_kind    = $sku_kind;
		$this->group_state = $group_state;
		$this->unit_kinds  = $unit_kinds;
	}

	/**
	 * Asks the importer's own decision about the products of unit cards ({@see Unit_Products::find()}). A read; nothing is written.
	 *
	 * @since 2.1.23
	 *
	 * @param Unit_Products $units      The importer's lookup.
	 * @param string        $marker_key Meta key an import marks its half-made product with.
	 * @return callable `function ( int $account_id, array $cards ): array<string,string>`.
	 */
	public static function unit_lookup( Unit_Products $units, string $marker_key ): callable {
		return static function ( int $account_id, array $cards ) use ( $units, $marker_key ): array {
			$kinds = [];

			foreach ( $units->find( $account_id, $cards ) as $offer_id => $found ) {
				if ( $found['pending'] > 0 ) {
					$product            = wc_get_product( $found['pending'] );
					$kinds[ $offer_id ] = $product && '' !== (string) $product->get_meta( $marker_key ) ? self::SKU_MARKED : self::SKU_PLAIN;
				} elseif ( count( $found['matches'] ) > 1 ) {
					$kinds[ $offer_id ] = self::SKU_SEVERAL;
				} else {
					$kinds[ $offer_id ] = [] === $found['matches'] ? self::SKU_NONE : self::SKU_PLAIN;
				}
			}

			return $kinds;
		};
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
	 * @return array{create:string[],link:string[],skipped:array<string,string[]>,products:int,joined:int} Articles to create — the ones that resume a half-made product first — articles to link, and the left-out articles by reason; a card the list no longer holds has no article and is an empty string under {@see self::NOT_IN_LIST}. `products` is how many products the `create` articles make — the cards of one group make one — and `joined` how many of them become variations of products that are on the site already.
	 */
	public function build( array $cards, array $linked, array $importing, int $account_id ): array {
		$plan    = [
			self::CREATE => [],
			self::LINK   => [],
			'skipped'    => [],
		];
		$seen    = [];
		$resumed = [];
		$fresh   = [];

		$this->kinds = null === $this->unit_kinds ? [] : (array) call_user_func( $this->unit_kinds, $account_id, array_values( array_filter( $cards ) ) );

		foreach ( $cards as $card ) {
			$offer_id = null === $card ? '' : (string) $card['offer_id'];

			if ( null !== $card && isset( $seen[ $offer_id ] ) ) {
				continue;
			}

			$seen[ $offer_id ] = true;
			$outcome           = null === $card ? self::NOT_IN_LIST : $this->outcome( $card, $linked, $importing );
			$group             = null === $card ? '' : Group_Assembler::key_of( (array) ( $card['group'] ?? [] ) );

			if ( self::RESUME === $outcome ) {
				$resumed[] = [ $offer_id, $group ];
			} elseif ( self::CREATE === $outcome ) {
				$fresh[] = [ $offer_id, $group ];
			} elseif ( self::LINK === $outcome ) {
				$plan[ self::LINK ][] = $offer_id;
			} else {
				$plan['skipped'][ $outcome ][] = $offer_id;
			}
		}

		return $this->with_products( $plan, $resumed, $fresh, $account_id );
	}

	/**
	 * Lists the cards to create and counts the products they make. A card with no group makes one; the cards of one group make
	 * one between them; a card whose group has a product on the site makes none and joins that product as a variation; a card
	 * whose group's product is half-made is left out, unless a card of this selection resumes that product. A card that resumes
	 * is counted by what it resumes: the group's half-made product is a product, a half-made variation of a finished group joins
	 * it. The same group decision as the job's ({@see Group_Assembler::key_of()} and {@see Group_Assembler::lookup()}).
	 *
	 * @param array{create:string[],link:string[],skipped:array<string,string[]>} $plan       The plan so far.
	 * @param array<int,array{0:string,1:string}>                                 $resumed    Article and group key of each card that resumes its own half-made product.
	 * @param array<int,array{0:string,1:string}>                                 $fresh      Article and group key of each card that has no product yet.
	 * @param int                                                                 $account_id Account id.
	 * @return array{create:string[],link:string[],skipped:array<string,string[]>,products:int,joined:int}
	 */
	private function with_products( array $plan, array $resumed, array $fresh, int $account_id ): array {
		$plan[ self::CREATE ] = array_column( $resumed, 0 );
		$plan['products']     = 0;
		$plan['joined']       = 0;
		$states               = [];

		foreach ( $resumed as list( , $group ) ) {
			$known = '' !== $group && isset( $states[ $group ] );
			$state = '' === $group ? Group_Assembler::NONE : ( $states[ $group ] ?? (string) call_user_func( $this->group_state, $account_id, $group ) );

			if ( Group_Assembler::READY === $state ) {
				// A half-made variation of a group whose product is finished: it joins that product, like a new sibling.
				$states[ $group ] = Group_Assembler::READY;
				++$plan['joined'];
				continue;
			}

			// Its own half-made product — for a group, the group's: that product is counted once, its other cards join it.
			$plan['products'] += $known ? 0 : 1;

			if ( '' !== $group ) {
				$states[ $group ] = Group_Assembler::NONE;
			}
		}

		foreach ( $fresh as list( $offer_id, $group ) ) {
			if ( '' !== $group && ! isset( $states[ $group ] ) ) {
				$states[ $group ]  = (string) call_user_func( $this->group_state, $account_id, $group );
				$plan['products'] += Group_Assembler::NONE === $states[ $group ] ? 1 : 0;
			}

			$state = $states[ $group ] ?? Group_Assembler::NONE;

			if ( Group_Assembler::BUILDING === $state ) {
				$plan['skipped'][ self::GROUP_BUILDING ][] = $offer_id;
				continue;
			}

			$plan[ self::CREATE ][] = $offer_id;
			$plan['products']      += '' === $group ? 1 : 0;
			$plan['joined']        += Group_Assembler::READY === $state ? 1 : 0;
		}

		return $plan;
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
	 * @return string {@see self::CREATE}, {@see self::RESUME}, {@see self::LINK} or {@see self::VARIABLE_PARENT}.
	 */
	private function by_article( string $offer_id ): string {
		$kind = null === $this->unit_kinds ? call_user_func( $this->sku_kind, $offer_id ) : ( $this->kinds[ $offer_id ] ?? self::SKU_NONE );

		switch ( $kind ) {
			case self::SKU_SEVERAL:
				return self::AMBIGUOUS;
			case self::SKU_VARIABLE:
				return self::VARIABLE_PARENT;
			case self::SKU_PLAIN:
				return self::LINK;
			case self::SKU_MARKED:
				return self::RESUME;
			default:
				return self::CREATE;
		}
	}
}
