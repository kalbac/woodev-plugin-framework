<?php
/**
 * The articles the products of a mapping would be sent with.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Article_Preview;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Flows\Export_Context_Builder;
use Woodev\Framework\Marketplace\Flows\Export_Facts;
use Woodev\Framework\Marketplace\Flows\Sellable_Units;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the products of a mapping that have no card yet, with the article each of their units would get.
 *
 * What a product is sent with is decided account by account, and the product's own settings win over the mapping on the screen:
 * another mapping (chosen at the product, or found at a nearer category) and an article typed at the product. So every unit is
 * resolved for every enabled account the way the export resolves it ({@see Export_Context_Builder}); the adapter answers the
 * article of the resolved mapping ({@see Article_Preview}), and a typed article is read the way the export reads it. A line whose
 * article comes from the product's own setting says so, and accounts that disagree get a line each. With no enabled account there
 * are no own settings to read, and the mapping on the screen answers.
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
	 * What the export resolves for a product.
	 *
	 * @var Export_Context_Builder
	 */
	private $contexts;

	/**
	 * The articles typed at products.
	 *
	 * @var Pinned_Offer_Id
	 */
	private $pinned;

	/**
	 * Lists the enabled accounts: `function (): Account[]`.
	 *
	 * @var callable
	 */
	private $accounts;

	/**
	 * The adapter's unit rules, when its article is not the key of a link.
	 *
	 * @var Sellable_Units|null
	 */
	private $units;

	/**
	 * Creates the preview.
	 *
	 * @since 2.1.27
	 *
	 * @param Article_Preview        $articles The adapter's answer.
	 * @param Wpdb_Mapping_Store     $mappings Mappings.
	 * @param Mapping_Products       $products The products a mapping covers.
	 * @param Wpdb_Link_Store        $links    Links.
	 * @param Task_Ledger            $ledger   Task ledger.
	 * @param Export_Facts           $facts    The marketplace's facts.
	 * @param Export_Context_Builder $contexts What the export resolves for a product.
	 * @param Pinned_Offer_Id        $pinned   The articles typed at products.
	 * @param callable               $accounts Lists the enabled accounts: `function (): Account[]`.
	 * @param Sellable_Units|null    $units    The adapter, when it sells units.
	 */
	public function __construct(
		Article_Preview $articles,
		Wpdb_Mapping_Store $mappings,
		Mapping_Products $products,
		Wpdb_Link_Store $links,
		Task_Ledger $ledger,
		Export_Facts $facts,
		Export_Context_Builder $contexts,
		Pinned_Offer_Id $pinned,
		callable $accounts,
		?Sellable_Units $units = null
	) {
		$this->articles = $articles;
		$this->mappings = $mappings;
		$this->products = $products;
		$this->links    = $links;
		$this->ledger   = $ledger;
		$this->facts    = $facts;
		$this->contexts = $contexts;
		$this->pinned   = $pinned;
		$this->accounts = $accounts;
		$this->units    = $units;
	}

	/**
	 * Reads the next products of a mapping that would get a new card.
	 *
	 * @since 2.1.27
	 *
	 * @param int $mapping_id Mapping id.
	 * @param int $offset     Products already read, in id order.
	 * @return array{products:array<int,array{id:int,name:string,url:string,articles:array<int,array{article:string,note:string,variations:string}>}>,next_offset:int}|null
	 *         `next_offset` is 0 once every product has been read; null when the mapping does not exist.
	 * @throws \Throwable When the adapter cannot work out an article.
	 */
	public function read( int $mapping_id, int $offset = 0 ): ?array {
		$mapping = $this->mappings->get( $mapping_id );

		if ( null === $mapping ) {
			return null;
		}

		$accounts = (array) call_user_func( $this->accounts );
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
				'articles' => $this->lines_of( $units, $mapping, $accounts ),
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
	 * Resolves every unit and puts the units that share an article and a note on one line.
	 *
	 * @param \WC_Product[] $units    Product or variations.
	 * @param Mapping       $mapping  The mapping on the screen.
	 * @param Account[]     $accounts Enabled accounts.
	 * @return array<int,array{article:string,note:string,variations:string}> `variations` is '' for a product without variations.
	 */
	private function lines_of( array $units, Mapping $mapping, array $accounts ): array {
		$lines = [];

		foreach ( $units as $unit ) {
			$name = $unit->is_type( 'variation' ) ? self::variation_name( $unit ) : '';

			foreach ( $this->answers_of( $unit, $mapping, $accounts ) as $key => $answer ) {
				$lines[ $key ]                = $lines[ $key ] ?? $answer;
				$lines[ $key ]['variations'][] = $name;
			}
		}

		foreach ( $lines as $key => $line ) {
			$lines[ $key ]['variations'] = implode( '; ', array_filter( $line['variations'], 'strlen' ) );
		}

		return array_values( $lines );
	}

	/**
	 * Answers what one unit would be sent with: one answer when the accounts agree, else one for each article and reason, which
	 * names its accounts.
	 *
	 * @param \WC_Product $unit     Product or variation.
	 * @param Mapping     $mapping  The mapping on the screen.
	 * @param Account[]   $accounts Enabled accounts.
	 * @return array<string,array{article:string,note:string}> By a key that is the same for the same article and note.
	 */
	private function answers_of( \WC_Product $unit, Mapping $mapping, array $accounts ): array {
		$found = [];

		foreach ( $accounts as $account ) {
			list( $article, $reasons ) = $this->for_account( $unit, $mapping, $account );

			$key                       = (string) wp_json_encode( [ $article, $reasons ] );
			$found[ $key ]['article']  = $article;
			$found[ $key ]['reasons']  = $reasons;
			$found[ $key ]['names'][]  = self::plain( $account->name() );
		}

		if ( [] === $accounts ) {
			$article = $this->articles->preview_article( $unit, $mapping, $this->contexts->profile( $mapping ) );
			$found[] = [
				'article' => $article,
				'reasons' => [],
				'names'   => [],
			];
		}

		$answers = [];

		foreach ( $found as $answer ) {
			$notes = $answer['reasons'];

			if ( count( $found ) > 1 ) {
				array_unshift( $notes, Mapping_Messages::article_accounts( $answer['names'] ) );
			}

			$note = implode( '. ', $notes );

			$answers[ (string) wp_json_encode( [ $answer['article'], $note ] ) ] = [
				'article' => $answer['article'],
				'note'    => $note,
			];
		}

		return $answers;
	}

	/**
	 * Resolves one unit for one account, in the export's order: the product's mapping and its profile, then the typed article.
	 *
	 * @param \WC_Product $unit    Product or variation.
	 * @param Mapping     $mapping The mapping on the screen.
	 * @param Account     $account Account.
	 * @return array{0:string,1:string[]} The article, and why it is not simply what the mapping on the screen gives.
	 */
	private function for_account( \WC_Product $unit, Mapping $mapping, Account $account ): array {
		$own = $this->contexts->mapping( $account, $unit );

		// The product names a mapping that is gone: nothing would be sent, so no article is shown.
		if ( null === $own->id() ) {
			return [ '', [ Mapping_Messages::article_mapping_gone() ] ];
		}

		$reasons = [];

		if ( $own->id() !== $mapping->id() ) {
			$stored    = $this->mappings->find( (int) $own->id() );
			$reasons[] = Mapping_Messages::article_other_mapping( null === $stored ? '' : self::plain( $stored['name'] ) );
		}

		$article = $this->articles->preview_article( $unit, $own, $this->contexts->profile( $own ) );

		if ( null !== $this->pinned->of( $unit, $account->id() ) ) {
			$typed = $this->typed_article( $unit, $account );

			// An adapter may refuse what was typed and send its own article: then there is nothing to say about it.
			if ( $typed !== $article ) {
				$article   = $typed;
				$reasons[] = Mapping_Messages::article_typed();
			}
		}

		return [ $article, $reasons ];
	}

	/**
	 * Returns the article the export would send for a unit whose article was typed at the product.
	 *
	 * @param \WC_Product $unit    Product or variation.
	 * @param Account     $account Account.
	 * @return string As the merchant knows it.
	 */
	private function typed_article( \WC_Product $unit, Account $account ): string {
		$link = $this->links->find( $account->id(), (int) $unit->get_id() )
			?? Link_Record::offer( $account->id(), (int) $unit->get_id(), (int) $unit->get_parent_id(), '' );
		$sent = $this->contexts->offer_id( $account, $unit, $link );

		return null === $this->units || '' === $sent ? $sent : $this->units->shown_article( $sent )->article();
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
