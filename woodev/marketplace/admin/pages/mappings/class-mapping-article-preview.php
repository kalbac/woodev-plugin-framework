<?php
/**
 * The articles the products of a mapping would be sent with.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Marketplace\Catalog\Article_Preview;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Flows\Export_Facts;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the products of a mapping that have no card yet, with the article each of their units would get. The article itself is the
 * adapter's answer ({@see Article_Preview}); this class only chooses the units and puts the ones of one article together.
 *
 * A unit has a card when one of its links, on any account, keeps its article: the card is known, or a request already carried the
 * article. Such a unit is left out, because its article does not follow the mapping any more.
 *
 * @since 2.1.27
 */
final class Mapping_Article_Preview {
	/**
	 * Most products one answer lists.
	 */
	public const PRODUCTS = 20;

	/**
	 * Most products one request reads while looking for them.
	 */
	public const SCAN = 200;

	/**
	 * The adapter's answer.
	 *
	 * @var Article_Preview
	 */
	private $articles;

	/**
	 * Mappings.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	private $mappings;

	/**
	 * Profiles.
	 *
	 * @var Wpdb_Profile_Store
	 */
	private $profiles;

	/**
	 * The products a mapping covers.
	 *
	 * @var Mapping_Products
	 */
	private $products;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Task ledger, which remembers the articles already sent.
	 *
	 * @var Task_Ledger
	 */
	private $ledger;

	/**
	 * The marketplace's facts, which say what becomes a card.
	 *
	 * @var Export_Facts
	 */
	private $facts;

	/**
	 * Creates the preview.
	 *
	 * @since 2.1.27
	 *
	 * @param Article_Preview    $articles The adapter's answer.
	 * @param Wpdb_Mapping_Store $mappings Mappings.
	 * @param Wpdb_Profile_Store $profiles Profiles.
	 * @param Mapping_Products   $products The products a mapping covers.
	 * @param Wpdb_Link_Store    $links    Links.
	 * @param Task_Ledger        $ledger   Task ledger.
	 * @param Export_Facts       $facts    The marketplace's facts.
	 */
	public function __construct(
		Article_Preview $articles,
		Wpdb_Mapping_Store $mappings,
		Wpdb_Profile_Store $profiles,
		Mapping_Products $products,
		Wpdb_Link_Store $links,
		Task_Ledger $ledger,
		Export_Facts $facts
	) {
		$this->articles = $articles;
		$this->mappings = $mappings;
		$this->profiles = $profiles;
		$this->products = $products;
		$this->links    = $links;
		$this->ledger   = $ledger;
		$this->facts    = $facts;
	}

	/**
	 * Reads the next products of a mapping that would get a new card.
	 *
	 * @since 2.1.27
	 *
	 * @param int $mapping_id Mapping id.
	 * @param int $offset     Products already read, in id order.
	 * @return array{products:array<int,array{id:int,name:string,url:string,articles:array<int,array{article:string,variations:string}>}>,next_offset:int}|null
	 *         `next_offset` is 0 once every product has been read; null when the mapping does not exist.
	 * @throws \Throwable When the adapter cannot work out an article.
	 */
	public function read( int $mapping_id, int $offset = 0 ): ?array {
		$mapping = $this->mappings->get( $mapping_id );

		if ( null === $mapping ) {
			return null;
		}

		$profile = null !== $mapping->profile_id() ? $this->profiles->get( $mapping->profile_id() ) : null;
		$profile = $profile ?? new Profile( null, '', [] );
		// One more than is read tells whether anything is left.
		$ids  = $this->products->ids( $mapping_id, self::SCAN + 1, $offset );
		$rows = [];
		$read = 0;

		foreach ( array_slice( $ids, 0, self::SCAN ) as $product_id ) {
			++$read;
			$product = wc_get_product( $product_id );
			$units   = $product instanceof \WC_Product ? $this->units_without_card( $product ) : [];

			if ( [] === $units ) {
				continue;
			}

			$rows[] = [
				'id'       => (int) $product->get_id(),
				'name'     => self::plain( (string) $product->get_name() ),
				'url'      => (string) get_edit_post_link( (int) $product->get_id(), 'raw' ),
				'articles' => $this->articles_of( $units, $mapping, $profile ),
			];

			if ( count( $rows ) >= self::PRODUCTS ) {
				break;
			}
		}

		return [
			'products'    => $rows,
			'next_offset' => count( $ids ) > $read ? $offset + $read : 0,
		];
	}

	/**
	 * Returns what of a product would become a new card: the product, or its variations.
	 *
	 * @param \WC_Product $product Product.
	 * @return \WC_Product[]
	 */
	private function units_without_card( \WC_Product $product ): array {
		$children = $product->is_type( 'variable' ) ? array_map( 'intval', (array) $product->get_children() ) : [];
		$members  = [] === $children ? [ $product ] : array_map( 'wc_get_product', $children );
		$carded   = $this->carded_ids( (int) $product->get_id() );
		$units    = [];

		foreach ( $members as $member ) {
			if ( $member instanceof \WC_Product && ! isset( $carded[ (int) $member->get_id() ] ) && $this->facts->is_exportable( $member ) ) {
				$units[] = $member;
			}
		}

		return $units;
	}

	/**
	 * Names the products and variations of a product that already have a card.
	 *
	 * @param int $product_id Product id.
	 * @return array<int,true> By product or variation id.
	 */
	private function carded_ids( int $product_id ): array {
		$carded = [];

		foreach ( $this->links->offers_of_product( $product_id ) as $link ) {
			if ( null !== $link->frozen_offer_id() || null !== $this->ledger->sent_offer_id( (int) $link->link_id() ) ) {
				$carded[ $link->wc_product_id() ] = true;
			}
		}

		return $carded;
	}

	/**
	 * Asks the article of every unit and puts the units of one article on one line.
	 *
	 * @param \WC_Product[] $units   Product or variations.
	 * @param Mapping       $mapping The mapping.
	 * @param Profile       $profile Its profile.
	 * @return array<int,array{article:string,variations:string}> `variations` is '' for a product without variations.
	 */
	private function articles_of( array $units, Mapping $mapping, Profile $profile ): array {
		$lines = [];

		foreach ( $units as $unit ) {
			$article = $this->articles->preview_article( $unit, $mapping, $profile );

			// A numeric article becomes an integer key, so the article is kept beside it as the text it is.
			$lines[ $article ]['article']      = $article;
			$lines[ $article ]['variations'][] = $unit->is_type( 'variation' ) ? self::variation_name( $unit ) : '';
		}

		$articles = [];

		foreach ( $lines as $line ) {
			$articles[] = [
				'article'    => $line['article'],
				'variations' => implode( '; ', array_filter( $line['variations'], 'strlen' ) ),
			];
		}

		return $articles;
	}

	/**
	 * Names a variation by its attribute values («Красный, M»), or by its number when it leaves every attribute open.
	 *
	 * @param \WC_Product $variation Variation.
	 * @return string
	 */
	private static function variation_name( \WC_Product $variation ): string {
		$name = (string) wc_get_formatted_variation( $variation, true, false );

		return '' !== $name ? self::plain( $name ) : '#' . $variation->get_id();
	}

	/**
	 * Turns a stored name into the text the view prints: it escapes by itself, so an entity would show as written.
	 *
	 * @param string $name Name of the shop.
	 * @return string
	 */
	private static function plain( string $name ): string {
		return html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
	}
}
