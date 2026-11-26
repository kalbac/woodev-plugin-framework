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
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Flows\Category_Ancestry;
use Woodev\Framework\Marketplace\Flows\Export_Facts;
use Woodev\Framework\Marketplace\Flows\Link_Context_Resolver;
use Woodev\Framework\Marketplace\Flows\Sellable_Units;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the products of a mapping that have no card yet, with the article each of their units would get: a simple product, or
 * each variation of a variable one (a variable product without variations makes no card, so it is not listed).
 *
 * What a product is sent with is decided account by account, and the product's own settings win over the mapping on the screen:
 * another mapping (chosen at the product, or found at a nearer category) and an article typed at the product. So every unit is
 * resolved for every enabled account by the export's own rules ({@see Link_Context_Resolver}); the adapter answers the article of
 * the resolved mapping ({@see Article_Preview}), and a typed article is read the way the export reads it
 * ({@see Export_Facts::offer_id_for()}). A line whose article comes from the product's own setting says so, and accounts that
 * disagree get a line each. With no enabled account there are no own settings to read, and the mapping on the screen answers.
 *
 * A unit has a card when one of its links, on any account, keeps its article: the card is known, or a request already carried the
 * article. Such a unit is left out, because its article does not follow the mapping any more.
 *
 * One request is bounded however large the shop is: it reads at most {@see self::UNITS} units and lists at most
 * {@see self::PRODUCTS} products, a product with more units continuing in the next request. The framework's reads are made for the
 * whole request at once, and what units share — a resolved mapping, a profile, a mapping's name — is read once, so their number
 * does not grow with the units or the accounts.
 *
 * @since 2.1.27
 */
final class Mapping_Article_Preview {
	/**
	 * Most products one answer lists.
	 */
	public const PRODUCTS = 20;

	/**
	 * Most units one request reads while looking for them; a product without a unit counts as one.
	 */
	public const UNITS = 100;

	/**
	 * The adapter's answer.
	 *
	 * @var Article_Preview
	 */
	private $articles;

	/**
	 * The marketplace's facts: what becomes a card, and what a typed article is sent as.
	 *
	 * @var Export_Facts
	 */
	private $facts;

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
	 * @var Wpdb_Task_Ledger
	 */
	private $ledger;

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
	 * Lists the category terms of a product, closest first: `function ( \WC_Product ): int[]`.
	 *
	 * @var callable
	 */
	private $term_ids;

	/**
	 * The export's mapping rules.
	 *
	 * @var Link_Context_Resolver
	 */
	private $resolver;

	/**
	 * Chosen mapping ids of this request, by what chooses them: the categories and the mappings the rows name.
	 *
	 * @var array<string,int|null>
	 */
	private $chosen = [];

	/**
	 * Mappings of this request, by mapping id.
	 *
	 * @var array<int,Mapping|null>
	 */
	private $loaded_mappings = [];

	/**
	 * Profiles of this request, by profile id.
	 *
	 * @var array<int,Profile>
	 */
	private $loaded_profiles = [];

	/**
	 * Mapping names of this request, by mapping id.
	 *
	 * @var array<int,string>
	 */
	private $names = [];

	/**
	 * Creates the preview.
	 *
	 * @since 2.1.27
	 *
	 * @param Article_Preview     $articles The adapter's answer.
	 * @param Export_Facts        $facts    The marketplace's facts.
	 * @param Wpdb_Mapping_Store  $mappings Mappings.
	 * @param Wpdb_Profile_Store  $profiles Profiles.
	 * @param Mapping_Products    $products The products a mapping covers.
	 * @param Wpdb_Link_Store     $links    Links.
	 * @param Wpdb_Task_Ledger    $ledger   Task ledger.
	 * @param Pinned_Offer_Id     $pinned   The articles typed at products.
	 * @param callable            $accounts Lists the enabled accounts: `function (): Account[]`.
	 * @param Sellable_Units|null $units    The adapter, when it sells units.
	 * @param callable|null       $term_ids `function ( \WC_Product ): int[]`; {@see Category_Ancestry::for_product()} when null.
	 */
	public function __construct(
		Article_Preview $articles,
		Export_Facts $facts,
		Wpdb_Mapping_Store $mappings,
		Wpdb_Profile_Store $profiles,
		Mapping_Products $products,
		Wpdb_Link_Store $links,
		Wpdb_Task_Ledger $ledger,
		Pinned_Offer_Id $pinned,
		callable $accounts,
		?Sellable_Units $units = null,
		?callable $term_ids = null
	) {
		$this->articles = $articles;
		$this->facts    = $facts;
		$this->mappings = $mappings;
		$this->profiles = $profiles;
		$this->products = $products;
		$this->links    = $links;
		$this->ledger   = $ledger;
		$this->pinned   = $pinned;
		$this->accounts = $accounts;
		$this->units    = $units;
		$this->term_ids = $term_ids ?? [ Category_Ancestry::class, 'for_product' ];
		$this->resolver = new Link_Context_Resolver( $links, $mappings );
	}

	/**
	 * Reads the next products of a mapping that would get a new card.
	 *
	 * @since 2.1.27
	 *
	 * @param int $mapping_id Mapping id.
	 * @param int $offset     Products already read in full, in id order.
	 * @param int $skip       Units already read of the product at the offset.
	 * @return array{products:array<int,array{id:int,name:string,url:string,articles:array<int,array{article:string,note:string,variations:string}>}>,next:array{offset:int,skip:int}|null}|null
	 *         `next` says where the following request starts, null once everything has been read; null instead of the whole answer
	 *         when the mapping does not exist.
	 * @throws \Throwable When the adapter cannot work out an article.
	 */
	public function read( int $mapping_id, int $offset = 0, int $skip = 0 ): ?array {
		$mapping = $this->mappings->get( $mapping_id );

		if ( null === $mapping ) {
			return null;
		}

		// What was shared belongs to one read: the next one asks again.
		$this->chosen          = [];
		$this->loaded_mappings = [];
		$this->loaded_profiles = [];
		$this->names           = [];

		// One more than can be read tells whether anything is left.
		$ids      = $this->products->ids( $mapping_id, self::UNITS + 1, $offset );
		$chunk    = $this->chunk( array_slice( $ids, 0, self::UNITS ), $skip );
		$loaded   = $this->load_units( $chunk );
		$rows     = $this->link_rows( $chunk, $loaded );
		$carded   = $this->carded_ids( $rows );
		$accounts = (array) call_user_func( $this->accounts );
		$listed   = [];
		// Stands when none of the ids is a product any more.
		$next     = count( $ids ) > self::UNITS ? [
			'offset' => $offset + self::UNITS,
			'skip'   => 0,
		] : null;

		foreach ( $chunk as $position => $entry ) {
			$units = [];

			foreach ( $entry['unit_ids'] as $unit_id ) {
				$unit = $loaded[ $unit_id ] ?? null;

				if ( null !== $unit && ! isset( $carded[ $unit_id ] ) && $this->facts->is_exportable( $unit ) ) {
					$units[] = $unit;
				}
			}

			if ( [] !== $units ) {
				$product  = $entry['product'];
				$listed[] = [
					'id'       => (int) $product->get_id(),
					'name'     => self::plain( (string) $product->get_name() ),
					'url'      => (string) get_edit_post_link( (int) $product->get_id(), 'raw' ),
					'articles' => $this->lines_of( $product, $units, $mapping, $accounts, $rows ),
				];
			}

			// Where the following request starts: inside this product when units of it are left, else after it.
			if ( ! $entry['whole'] ) {
				$next = [
					'offset' => $offset + $position,
					'skip'   => $entry['skipped'] + count( $entry['unit_ids'] ),
				];
			} else {
				$next = count( $ids ) > $position + 1 ? [
					'offset' => $offset + $position + 1,
					'skip'   => 0,
				] : null;
			}

			if ( count( $listed ) >= self::PRODUCTS ) {
				break;
			}
		}

		return [
			'products' => $listed,
			'next'     => $next,
		];
	}

	/**
	 * Chooses what one request reads: products in order and, of each, the units that still fit.
	 *
	 * @param int[] $ids  Product ids from where the request starts.
	 * @param int   $skip Units already read of the first of them.
	 * @return array<int,array{product:\WC_Product,unit_ids:int[],skipped:int,whole:bool}> By position in the ids; `whole` is false
	 *         for a product whose units do not all fit.
	 */
	private function chunk( array $ids, int $skip ): array {
		self::prime( $ids );

		$chunk  = [];
		$budget = self::UNITS;

		foreach ( array_values( $ids ) as $position => $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			// A variable product is sold only as its variations: with none it makes no card.
			$all     = $product->is_type( 'variable' ) ? array_map( 'intval', (array) $product->get_children() ) : [ (int) $product->get_id() ];
			$skipped = 0 === $position ? $skip : 0;
			$left    = array_slice( $all, $skipped );
			$taken   = array_slice( $left, 0, $budget );

			$chunk[ $position ] = [
				'product'  => $product,
				'unit_ids' => $taken,
				'skipped'  => $skipped,
				'whole'    => count( $taken ) === count( $left ),
			];

			$budget -= max( 1, count( $taken ) );

			if ( $budget <= 0 ) {
				break;
			}
		}

		return $chunk;
	}

	/**
	 * Loads the products and variations of a chunk.
	 *
	 * @param array<int,array{product:\WC_Product,unit_ids:int[]}> $chunk The chunk.
	 * @return array<int,\WC_Product> By id.
	 */
	private function load_units( array $chunk ): array {
		$loaded  = [];
		$missing = [];

		foreach ( $chunk as $entry ) {
			$loaded[ (int) $entry['product']->get_id() ] = $entry['product'];
		}

		foreach ( $chunk as $entry ) {
			$missing = array_merge( $missing, array_values( array_diff( $entry['unit_ids'], array_keys( $loaded ) ) ) );
		}

		self::prime( $missing );

		foreach ( $missing as $unit_id ) {
			$unit = wc_get_product( $unit_id );

			if ( $unit instanceof \WC_Product ) {
				$loaded[ $unit_id ] = $unit;
			}
		}

		return $loaded;
	}

	/**
	 * Reads every link row of a chunk's products and units at once.
	 *
	 * @param array<int,array{product:\WC_Product,unit_ids:int[]}> $chunk  The chunk.
	 * @param array<int,\WC_Product>                               $loaded Products and units by id.
	 * @return array<int,array<int,Link_Record>> By account id, then product or variation id.
	 */
	private function link_rows( array $chunk, array $loaded ): array {
		$ids = array_keys( $loaded );

		foreach ( $chunk as $entry ) {
			$ids = array_merge( $ids, $entry['unit_ids'] );
		}

		$records = $this->links->rows_of_products( $ids );
		$parents = [];

		foreach ( $records as $record ) {
			$parents[] = $record->wc_parent_id();
		}

		// A row may still name the parent its variation has left: the export follows the row.
		$records = array_merge( $records, $this->links->rows_of_products( array_diff( array_filter( $parents ), $ids ) ) );
		$rows    = [];

		foreach ( $records as $record ) {
			$rows[ $record->account_id() ][ $record->wc_product_id() ] = $record;
		}

		return $rows;
	}

	/**
	 * Names the products and variations that already have a card.
	 *
	 * @param array<int,array<int,Link_Record>> $rows Link rows.
	 * @return array<int,true> By product or variation id.
	 */
	private function carded_ids( array $rows ): array {
		$carded = [];
		$open   = [];

		foreach ( $rows as $of_account ) {
			foreach ( $of_account as $record ) {
				if ( ! $record->is_offer() ) {
					continue;
				}

				if ( null !== $record->frozen_offer_id() ) {
					$carded[ $record->wc_product_id() ] = true;
				} else {
					$open[ (int) $record->link_id() ] = $record->wc_product_id();
				}
			}
		}

		foreach ( $this->ledger->sent_link_ids( array_keys( $open ) ) as $link_id ) {
			$carded[ $open[ $link_id ] ] = true;
		}

		return $carded;
	}

	/**
	 * Resolves the units of one product and puts those that share an article and a note on one line.
	 *
	 * @param \WC_Product                       $product  The product.
	 * @param \WC_Product[]                     $units    The product or its variations.
	 * @param Mapping                           $mapping  The mapping on the screen.
	 * @param Account[]                         $accounts Enabled accounts.
	 * @param array<int,array<int,Link_Record>> $rows     Link rows.
	 * @return array<int,array{article:string,note:string,variations:string}> `variations` is '' for a product without variations.
	 */
	private function lines_of( \WC_Product $product, array $units, Mapping $mapping, array $accounts, array $rows ): array {
		$terms = [] === $accounts ? [] : (array) call_user_func( $this->term_ids, $product );
		$lines = [];

		foreach ( $units as $unit ) {
			$name = $unit->is_type( 'variation' ) ? self::variation_name( $unit ) : '';

			foreach ( $this->answers_of( $unit, $mapping, $accounts, $rows, $terms ) as $key => $answer ) {
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
	 * @param \WC_Product                       $unit     Product or variation.
	 * @param Mapping                           $mapping  The mapping on the screen.
	 * @param Account[]                         $accounts Enabled accounts.
	 * @param array<int,array<int,Link_Record>> $rows     Link rows.
	 * @param int[]                             $terms    The product's category terms.
	 * @return array<string,array{article:string,note:string}> By a key that is the same for the same article and note.
	 */
	private function answers_of( \WC_Product $unit, Mapping $mapping, array $accounts, array $rows, array $terms ): array {
		$found    = [];
		$answered = [];

		foreach ( $accounts as $account ) {
			list( $article, $reasons ) = $this->for_account( $unit, $mapping, $account, $rows[ $account->id() ] ?? [], $terms, $answered );

			$key                       = (string) wp_json_encode( [ $article, $reasons ] );
			$found[ $key ]['article']  = $article;
			$found[ $key ]['reasons']  = $reasons;
			$found[ $key ]['names'][]  = self::plain( $account->name() );
		}

		if ( [] === $accounts ) {
			$found[] = [
				'article' => $this->articles->preview_article( $unit, $mapping, $this->profile( $mapping ) ),
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
	 * @param \WC_Product            $unit     Product or variation.
	 * @param Mapping                $mapping  The mapping on the screen.
	 * @param Account                $account  Account.
	 * @param array<int,Link_Record> $rows     The account's link rows, by product or variation id.
	 * @param int[]                  $terms    The product's category terms.
	 * @param array<string,string>   $answered The adapter's answers about this unit so far, by mapping and values: accounts that
	 *                                         resolve the same ask once.
	 * @return array{0:string,1:string[]} The article, and why it is not simply what the mapping on the screen gives.
	 */
	private function for_account( \WC_Product $unit, Mapping $mapping, Account $account, array $rows, array $terms, array &$answered ): array {
		$row       = $rows[ (int) $unit->get_id() ] ?? null;
		$parent_id = null !== $row ? $row->wc_parent_id() : (int) $unit->get_parent_id();
		$parent    = $parent_id > 0 ? ( $rows[ $parent_id ] ?? null ) : null;
		$base      = $this->chosen_mapping( $row, $parent, $terms );

		// The product names a mapping that is gone: nothing would be sent, so no article is shown.
		if ( null === $base ) {
			return [ '', [ Mapping_Messages::article_mapping_gone() ] ];
		}

		$own     = $this->resolver->with_overrides( $base, $row, $parent );
		$reasons = [];

		if ( $own->id() !== $mapping->id() ) {
			$reasons[] = Mapping_Messages::article_other_mapping( $this->name_of( (int) $own->id() ) );
		}

		$profile = $this->profile( $own );
		$asked   = (string) wp_json_encode( [ $own->id(), null === $row ? null : $row->attribute_overrides(), null === $parent ? null : $parent->attribute_overrides() ] );

		$answered[ $asked ] = $answered[ $asked ] ?? $this->articles->preview_article( $unit, $own, $profile );
		$article            = $answered[ $asked ];
		$typed              = $this->pinned->of_primed( (int) $unit->get_id(), $account->id() );

		if ( null !== $typed ) {
			// What the export sends for it: a unit without a card has no frozen article, so the typed one is all it is given.
			$sent  = $this->facts->offer_id_for( $typed, $unit, $profile );
			$typed = null === $this->units || '' === $sent ? $sent : $this->units->shown_article( $sent )->article();

			// An adapter may refuse what was typed and send its own article: then there is nothing to say about it.
			if ( $typed !== $article ) {
				$article   = $typed;
				$reasons[] = Mapping_Messages::article_typed();
			}
		}

		return [ $article, $reasons ];
	}

	/**
	 * Returns the mapping the export chooses for a unit, before the product's values are laid over it. The categories are looked up
	 * once per distinct choice, each mapping is read once.
	 *
	 * @param Link_Record|null $row    The unit's row.
	 * @param Link_Record|null $parent The parent row.
	 * @param int[]            $terms  The product's category terms.
	 * @return Mapping|null Null when nothing names a mapping that exists.
	 */
	private function chosen_mapping( ?Link_Record $row, ?Link_Record $parent, array $terms ): ?Mapping {
		$choice = (string) wp_json_encode( [ $terms, null === $row ? null : $row->mapping_id(), null === $parent ? null : $parent->mapping_id() ] );

		if ( ! array_key_exists( $choice, $this->chosen ) ) {
			$this->chosen[ $choice ] = $this->resolver->mapping_id_of_rows( $row, $parent, $terms );
		}

		$id = $this->chosen[ $choice ];

		if ( null === $id ) {
			return null;
		}

		if ( ! array_key_exists( $id, $this->loaded_mappings ) ) {
			$this->loaded_mappings[ $id ] = $this->mappings->get( $id );
		}

		return $this->loaded_mappings[ $id ];
	}

	/**
	 * Returns a mapping's profile, else an empty one, reading each profile once.
	 *
	 * @param Mapping $mapping Mapping.
	 * @return Profile
	 */
	private function profile( Mapping $mapping ): Profile {
		$id = (int) $mapping->profile_id();

		$this->loaded_profiles[ $id ] = $this->loaded_profiles[ $id ] ?? ( $id > 0 ? $this->profiles->get( $id ) : null ) ?? new Profile( null, '' );

		return $this->loaded_profiles[ $id ];
	}

	/**
	 * Returns a mapping's name, reading each once.
	 *
	 * @param int $mapping_id Mapping id.
	 * @return string '' for a mapping without a name.
	 */
	private function name_of( int $mapping_id ): string {
		if ( ! isset( $this->names[ $mapping_id ] ) ) {
			$stored = $this->mappings->find( $mapping_id );

			$this->names[ $mapping_id ] = null === $stored ? '' : self::plain( $stored['name'] );
		}

		return $this->names[ $mapping_id ];
	}

	/**
	 * Reads the posts, terms and meta of many products at once, so loading each of them asks the database nothing more.
	 *
	 * @param int[] $ids Product or variation ids.
	 * @return void
	 */
	private static function prime( array $ids ): void {
		if ( [] !== $ids ) {
			_prime_post_caches( $ids, true, true );
		}
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
