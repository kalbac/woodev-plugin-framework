<?php
/**
 * Imports one marketplace card as a shop product, or links it to the product that already has its article.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Links\Unit_Key;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decides, for one card and in this order:
 *
 * 1. the article has a link in this account → nothing ({@see self::ALREADY_LINKED});
 * 2. the card is archived → nothing: no product, no link ({@see self::SKIPPED}, with a note);
 * 3. a shop product has the article and carries no import marker → it is linked, its content untouched
 *    ({@see self::LINKED_EXISTING}); a **variable parent** is refused before the linker, because the linker would insert an offer
 *    link onto a parent nobody opened on the marketplace tab; a variation is linked with its real parent id;
 * 4. a shop product has the article and carries the import marker (an earlier run was cut short) → the import resumes on it;
 * 5. no product → {@see Product_Writer} creates it, then the card is linked ({@see self::CREATED}).
 *
 * Why this order holds at every cut point: cut before the writer's first save, nothing exists and the next run starts again at
 * row 5; cut between the first and the final save, the SKU and the marker are both stored, so the next run resumes on the same
 * product (row 4); cut between the final save and the link, the SKU has no marker and the next run links it (row 3); a link
 * refused because the claim was lost stops the run with {@see Claim_Lost}, and the retry meets row 3.
 *
 * **Groups** ({@see Group_Assembler}). The cards of one marketplace group become one variable product, and the rows above stay as
 * they are — a card is still found by its article, which is the SKU of a simple product or of a variation:
 *
 * - row 5, the group has no product yet → the card is written as a simple product exactly like a card with no group, the
 *   writer's **first save** also storing the group's mark; then, **before the link**, the product of a group of several cards
 *   becomes variable in place and the card is linked as its first variation ({@see Group_Assembler::place()}). A run cut anywhere
 *   in between leaves the card unlinked, so its own retry meets row 4, row 3 or this row again and finishes;
 * - row 5, the group has a product → that product is made variable if it is not yet, and the card becomes one more variation:
 *   a first save with the SKU and the marker, then {@see Product_Writer::complete()} — whose final save also stores whether the
 *   variation is switched on — then the link with the product as parent;
 * - row 4 on a marked variation → the same, from the second step; row 3 on a finished variation → linked with its parent id;
 * - row 1 → nothing is imported, but a conversion of the card's product that was left open is finished
 *   ({@see Group_Assembler::heal()});
 * - a group whose first product is still half-made (its own import was cut) is not built upon: the card gets a note, and is skipped
 *   ({@see self::SKIPPED}) — or, when the caller says the card that resumes that product is still queued, **waits**
 *   ({@see self::WAITING}) so the caller can bring it back after that card. The preview of «Импортировать» makes the same decision
 *   ({@see Group_Assembler::group_of()}).
 *
 * **Mapping of the category.** A product the import creates, while the setting «Создавать категории при импорте» is on, gets its
 * shop category mapped to the card's marketplace category and type when it has no mapping ({@see Import_Mapping}) — after the first
 * save and **before** the product is filled and priced, so the profile that governs the import is the one the export reads. A
 * mapping that exists is the merchant's and is not changed.
 *
 * **Units** ({@see Sellable_Units}). For a marketplace that sells a size inside a card the article is a unit key, so rows 3–5
 * change in one thing — how the card's product is found and named ({@see Unit_Products}): the product an unfinished import of
 * the unit made, else the one product the adapter's match keys find (several are a conflict: the card is skipped with a note,
 * nothing is guessed). A product the import creates gets the adapter's candidate SKU, or none when another product holds it —
 * the merchant is told at the card and at the product.
 *
 * What the merchant should know goes into an import note ({@see Link_Report_Store::set_import_note()}); a successful import
 * clears the card's note. This class never talks to the marketplace; the card is already read.
 *
 * @since 2.1.6
 */
final class Card_Import {
	public const ALREADY_LINKED  = 'already_linked';
	public const SKIPPED         = 'skipped';
	public const LINKED_EXISTING = 'linked_existing';
	public const CREATED         = 'created';

	/**
	 * The card's group has a half-made product that another queued card resumes: nothing was written but the note; the card must be tried again.
	 */
	public const WAITING = 'waiting';

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Linker.
	 *
	 * @var Card_Linker
	 */
	private $linker;

	/**
	 * Import notes.
	 *
	 * @var Link_Report_Store
	 */
	private $notes;

	/**
	 * Settings.
	 *
	 * @var Effective_Settings
	 */
	private $settings;

	/**
	 * Groups of cards.
	 *
	 * @var Group_Assembler
	 */
	private $groups;

	/**
	 * Makes the first save of a new product: {@see Product_Writer::start()}.
	 *
	 * @var callable
	 */
	private $start;

	/**
	 * Fills a started product and saves it for good: {@see Product_Writer::complete()}.
	 *
	 * @var callable
	 */
	private $complete;

	/**
	 * Gives the shop category of an imported card its mapping; null leaves the mappings to the merchant.
	 *
	 * @var Import_Mapping|null
	 */
	private $mapping;

	/**
	 * Finds the products of units; null for a marketplace whose article is the SKU.
	 *
	 * @var Unit_Products|null
	 */
	private $units;

	/**
	 * Creates the importer.
	 *
	 * @since 2.1.6
	 * @since 2.1.22 `$units`.
	 *
	 * @param Wpdb_Link_Store     $links    Links.
	 * @param Card_Linker         $linker   Linker.
	 * @param Link_Report_Store   $notes    Import notes.
	 * @param Effective_Settings  $settings The shop's import settings.
	 * @param Group_Assembler     $groups   Assembles the cards of one group into one variable product.
	 * @param callable            $start    `function ( Account, Card_Content, bool $create_categories, ?callable $prepare, ?string $sku ): \WC_Product`, `Product_Writer::start()`; `$prepare` is called on the unsaved product.
	 * @param callable            $complete `function ( Account, Card_Content, \WC_Product, bool $publish, callable $renew, ?callable $stored ): string[]`, `Product_Writer::complete()`.
	 * @param Import_Mapping|null $mapping Gives the category of a created product its mapping when the import creates categories ({@see self::link()}).
	 * @param Unit_Products|null  $units   Finds the products of units, for an adapter that sells them.
	 */
	public function __construct( Wpdb_Link_Store $links, Card_Linker $linker, Link_Report_Store $notes, Effective_Settings $settings, Group_Assembler $groups, callable $start, callable $complete, ?Import_Mapping $mapping = null, ?Unit_Products $units = null ) {
		$this->links    = $links;
		$this->linker   = $linker;
		$this->notes    = $notes;
		$this->settings = $settings;
		$this->groups   = $groups;
		$this->start    = $start;
		$this->complete = $complete;
		$this->mapping  = $mapping;
		$this->units    = $units;
	}

	/**
	 * Imports one card.
	 *
	 * @since 2.1.6
	 *
	 * @param Account       $account Account.
	 * @param Card_Content  $content The card, read whole.
	 * @param Claim_Fence   $fence   The run's account claim.
	 * @param callable      $renew   `function (): void`; extends the claim before each image and throws when it was lost or the run's time is spent.
	 * @param callable|null $stored `function (): void`; called right after a new image was stored, for the run's budget.
	 * @param callable|null $waits  `function ( string $article ): bool`; asked when the card's group has a half-made product, with the article of that product: true when the card that resumes it is still queued, so this card should wait for it. Without it such a card is skipped.
	 * @return string One of the outcome constants of this class.
	 * @throws Claim_Lost When a link write is refused because another run took the account claim.
	 * @throws \Throwable Whatever WooCommerce throws when it refuses a save; the caller notes it for this card.
	 */
	public function import( Account $account, Card_Content $content, Claim_Fence $fence, callable $renew, ?callable $stored = null, ?callable $waits = null ): string {
		$card    = $content->card();
		$article = (string) ( $card['offer_id'] ?? '' );

		if ( '' === $article ) {
			return self::SKIPPED;
		}

		if ( ! Unit_Key::fits( $article ) ) {
			return $this->skipped( $account, $article, Card_Linker::message( Card_Linker::KEY_TOO_LONG ) ); // Before any write: no product either.
		}

		$link = $this->links->find_by_offer_id( $account->id(), $article );

		if ( null !== $link ) {
			$this->groups->heal( $account, $content, $link, $fence );

			// A unit's run cut right after the link left its pending mark; the retry meets this row and closes it.
			$product = null === $this->units ? null : $this->product( $this->links->find_by_offer_id( $account->id(), $article )->wc_product_id() );

			$this->note( $account, $article, null === $product ? [] : $this->unit_linked( $account, $product ) );

			return self::ALREADY_LINKED;
		}

		if ( ! empty( $card['archived'] ) || Link_Record::STATE_ARCHIVED === (string) ( $card['state'] ?? '' ) ) {
			return $this->skipped( $account, $article, __( 'Карточка в архиве — не импортируем.', 'woodev-plugin-framework' ) );
		}

		if ( null === $this->units ) {
			$product = $this->product_with_article( $article );
		} else {
			$found   = $this->units->find( $account->id(), [ $content->card() ] )[ $article ];
			$matches = $found['pending'] > 0 ? [ $found['pending'] ] : $found['matches'];

			if ( count( $matches ) > 1 ) {
				return $this->skipped( $account, $article, self::ambiguous_text( $matches ) );
			}

			$product = [] === $matches ? null : $this->product( $matches[0] );

			// A conversion cut before its first variation: the card joins the group below, which finishes it.
			if ( null !== $product && $found['pending'] > 0 && $product->is_type( 'variable' ) ) {
				$product = null;
			}
		}

		if ( null === $product ) {
			return $this->create( $account, $content, $fence, $renew, $stored, $waits );
		}

		if ( Product_Writer::is_marked( $product, $account->adapter_id() ) ) {
			return $this->resume( $account, $content, $product, $fence, $renew, $stored );
		}

		return $this->link_existing( $account, $content, $product, $fence );
	}

	/**
	 * Says why a card that matches several products was not imported.
	 *
	 * @since 2.1.22
	 *
	 * @param int[] $product_ids The matching products.
	 * @return string
	 */
	public static function ambiguous_text( array $product_ids ): string {
		$names = [];

		foreach ( array_slice( $product_ids, 0, 5 ) as $product_id ) {
			$title   = (string) get_the_title( $product_id );
			$names[] = ( '' === $title ? '' : '«' . html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) . '» ' ) . '№' . $product_id;
		}

		/* translators: %s: list of products, each a name and a number. */
		return sprintf( __( 'Под эту карточку подходят несколько товаров сайта: %s. Свяжите нужный вручную: «Связать с товаром…».', 'woodev-plugin-framework' ), implode( ', ', $names ) );
	}

	/**
	 * Returns a product by id.
	 *
	 * @param int $id Product or variation id.
	 * @return \WC_Product|null
	 */
	private function product( int $id ): ?\WC_Product {
		$product = $id > 0 ? wc_get_product( $id ) : null;

		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Returns the product an unfinished import of the card made in the group it joins, found as the importer finds its own.
	 *
	 * @param Account             $account Account.
	 * @param array<string,mixed> $card    Card.
	 * @return \WC_Product|null
	 */
	private function own_product( Account $account, array $card ): ?\WC_Product {
		if ( null === $this->units ) {
			return $this->product_with_article( (string) $card['offer_id'] );
		}

		return $this->product( $this->units->find( $account->id(), [ $card ] )[ (string) $card['offer_id'] ]['pending'] );
	}

	/**
	 * Returns the SKU a new product of the card gets and what is stored on the unsaved product besides: for a unit, the pending
	 * mark, and the SKU another product holds.
	 *
	 * @param Account      $account Account.
	 * @param Card_Content $content Card.
	 * @return array{0:string|null,1:callable} The SKU (null writes the article) and `function ( \WC_Product $unsaved ): void`.
	 */
	private function new_product_identity( Account $account, Card_Content $content ): array {
		if ( null === $this->units ) {
			return [
				null,
				static function (): void {
				},
			];
		}

		$card    = $content->card();
		$wanted  = $this->units->units()->import_sku( $card );
		$taken   = '' !== $wanted && (int) wc_get_product_id_by_sku( $wanted ) > 0;
		$article = (string) $card['offer_id'];
		$name    = '' !== $content->name() ? '' : $this->units->units()->shown_article( $article, $card )->text();

		return [
			$taken ? '' : $wanted,
			static function ( \WC_Product $unsaved ) use ( $account, $article, $wanted, $taken, $name ): void {
				$unsaved->update_meta_data( Unit_Products::pending_key( $account->adapter_id() ), Unit_Products::pending_value( $account->id(), $article ) );

				if ( $taken ) {
					$unsaved->update_meta_data( Unit_Products::sku_conflict_key( $account->adapter_id() ), $wanted );
				}

				if ( '' !== $name ) {
					$unsaved->set_name( $name ); // The unit key is never a name.
				}
			},
		];
	}

	/**
	 * Returns the shop product whose SKU is the article, exactly.
	 *
	 * @param string $article Article.
	 * @return \WC_Product|null
	 */
	private function product_with_article( string $article ): ?\WC_Product {
		$id      = (int) wc_get_product_id_by_sku( $article );
		$product = $id > 0 ? wc_get_product( $id ) : null;

		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Row 3: links the product that carries the article, leaving its content alone. A product the importer itself made for the
	 * card's group, cut before its link, first takes its place in the group.
	 *
	 * @param Account      $account Account.
	 * @param Card_Content $content Card.
	 * @param \WC_Product  $product Product with the article as its SKU, no import marker.
	 * @param Claim_Fence  $fence   The run's claim.
	 * @return string
	 */
	private function link_existing( Account $account, Card_Content $content, \WC_Product $product, Claim_Fence $fence ): string {
		$article = (string) $content->card()['offer_id'];

		if ( $product->is_type( 'variable' ) ) {
			return $this->skipped( $account, $article, __( 'К вариативному товару нельзя привязать карточку. Выберите один из его вариантов через «Связать с…».', 'woodev-plugin-framework' ) );
		}

		return $this->link( $account, $content, $this->groups->place( $account, $product, $content, $fence ), $fence, self::LINKED_EXISTING, [] );
	}

	/**
	 * Row 4: finishes the product an earlier run started, then links it. A marked variation is a card that was joining its group.
	 *
	 * @param Account       $account Account.
	 * @param Card_Content  $content Card.
	 * @param \WC_Product   $product The half-made product.
	 * @param Claim_Fence   $fence   The run's claim.
	 * @param callable      $renew   Extends the claim.
	 * @param callable|null $stored Called when a new image was stored.
	 * @return string
	 */
	private function resume( Account $account, Card_Content $content, \WC_Product $product, Claim_Fence $fence, callable $renew, ?callable $stored ): string {
		if ( $product->get_parent_id() > 0 ) {
			return $this->finish_variation( $account, $content, $product, $fence, $renew, $stored );
		}

		return $this->finish( $account, $content, $product, $fence, $renew, $stored );
	}

	/**
	 * Row 5: creates the product, then links it. A card whose group already has a product becomes a variation of that product;
	 * a card whose group's product is half-made waits.
	 *
	 * @param Account       $account Account.
	 * @param Card_Content  $content Card.
	 * @param Claim_Fence   $fence   The run's claim.
	 * @param callable      $renew   Extends the claim.
	 * @param callable|null $stored Called when a new image was stored.
	 * @param callable|null $waits  See {@see self::import()}.
	 * @return string
	 */
	private function create( Account $account, Card_Content $content, Claim_Fence $fence, callable $renew, ?callable $stored, ?callable $waits ): string {
		$group = $this->groups->group_of( $account, $content );

		if ( null !== $group['product'] && Group_Assembler::BUILDING === $group['state'] ) {
			return $this->group_building( $account, $content, $this->article_of( $account, $group['product'] ), $waits );
		}

		if ( null !== $group['product'] ) {
			return $this->join( $account, $content, $group['product'], $fence, $renew, $stored );
		}

		list( $sku, $identify ) = $this->new_product_identity( $account, $content );

		$product = call_user_func(
			$this->start,
			$account,
			$content,
			$this->settings->import_create_categories(),
			function ( \WC_Product $unsaved ) use ( $account, $content, $identify ): void {
				$this->groups->mark( $account, $unsaved, $content );
				call_user_func( $identify, $unsaved );
			},
			$sku
		);

		return $this->finish( $account, $content, $product, $fence, $renew, $stored );
	}

	/**
	 * Returns the article of the card a half-made product of a group is made for.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product The half-made product.
	 * @return string
	 */
	private function article_of( Account $account, \WC_Product $product ): string {
		if ( null === $this->units ) {
			return (string) $product->get_sku( 'edit' );
		}

		$mark   = (string) $product->get_meta( Unit_Products::pending_key( $account->adapter_id() ) );
		$prefix = Unit_Products::pending_value( $account->id(), '' );

		return 0 === strpos( $mark, $prefix ) ? substr( $mark, strlen( $prefix ) ) : '';
	}

	/**
	 * A card whose group's product is half-made is not built upon. It gets the note the merchant reads; whether it is skipped or must
	 * wait for the card that resumes that product is the caller's knowledge (the queue), so it is asked.
	 *
	 * @param Account       $account Account.
	 * @param Card_Content  $content Card.
	 * @param string        $article Article of the half-made product: the card that resumes it.
	 * @param callable|null $waits   See {@see self::import()}.
	 * @return string {@see self::WAITING} or {@see self::SKIPPED}.
	 */
	private function group_building( Account $account, Card_Content $content, string $article, ?callable $waits ): string {
		/* translators: %s: article of another card of the same group */
		$text    = sprintf( __( 'Импорт другой карточки этой группы (артикул %s) не завершён. Импортируйте сначала её, затем повторите.', 'woodev-plugin-framework' ), null === $this->units ? $article : $this->units->shown( $account->id(), $article ) );
		$skipped = $this->skipped( $account, (string) $content->card()['offer_id'], $text );

		return null !== $waits && call_user_func( $waits, $article ) ? self::WAITING : $skipped;
	}

	/**
	 * Fills a started product of the card's own, gives it its place in the card's group and links the card.
	 *
	 * @param Account       $account Account.
	 * @param Card_Content  $content Card.
	 * @param \WC_Product   $product The started product.
	 * @param Claim_Fence   $fence   The run's claim.
	 * @param callable      $renew   Extends the claim.
	 * @param callable|null $stored Called when a new image was stored.
	 * @return string
	 */
	private function finish( Account $account, Card_Content $content, \WC_Product $product, Claim_Fence $fence, callable $renew, ?callable $stored ): string {
		$notes    = $this->category_mapping( $content, $product );
		$warnings = (array) call_user_func( $this->complete, $account, $content, $product, $this->settings->import_publish(), $renew, $stored );

		return $this->link( $account, $content, $this->groups->place( $account, $product, $content, $fence ), $fence, self::CREATED, array_merge( $notes, $warnings ) );
	}

	/**
	 * Row 5 for a card whose group has a product: the product becomes variable when it is not yet, and the card joins it. When
	 * the conversion that is finished here was the card's own (it was cut before the card was linked), the card is the first
	 * variation already and is only linked.
	 *
	 * @param Account       $account       Account.
	 * @param Card_Content  $content       Card.
	 * @param \WC_Product   $group_product The product of the card's group.
	 * @param Claim_Fence   $fence         The run's claim.
	 * @param callable      $renew         Extends the claim.
	 * @param callable|null $stored        Called when a new image was stored.
	 * @return string
	 */
	private function join( Account $account, Card_Content $content, \WC_Product $group_product, Claim_Fence $fence, callable $renew, ?callable $stored ): string {
		$this->groups->convert( $account, $group_product, $fence );

		$own = $this->own_product( $account, $content->card() );

		if ( null !== $own ) {
			return $this->link( $account, $content, $own, $fence, self::CREATED, [] );
		}

		list( $sku, $identify ) = $this->new_product_identity( $account, $content );

		$variation = $this->groups->start_variation( $account, (int) $group_product->get_id(), $content, $sku, $identify );

		return $this->finish_variation( $account, $content, $variation, $fence, $renew, $stored );
	}

	/**
	 * Fills the variation of a card and links it, the variable product being the link's parent. Whether the variation is
	 * switched on is decided first, so the writer's final save stores it together with the removal of the marker: a variation
	 * that must stay off is never stored switched on.
	 *
	 * @param Account       $account   Account.
	 * @param Card_Content  $content   Card.
	 * @param \WC_Product   $variation The started variation.
	 * @param Claim_Fence   $fence     The run's claim.
	 * @param callable      $renew     Extends the claim.
	 * @param callable|null $stored    Called when a new image was stored.
	 * @return string
	 */
	private function finish_variation( Account $account, Card_Content $content, \WC_Product $variation, Claim_Fence $fence, callable $renew, ?callable $stored ): string {
		$this->groups->offer( $variation, $content );

		$switched_on = $this->groups->sells_at_once( $variation, $this->settings->import_publish() );
		$notes       = $this->category_mapping( $content, $this->parent_of( $variation ) ); // The characteristics are the variable product's.
		$warnings    = (array) call_user_func( $this->complete, $account, Group_Assembler::variation_content( $content ), $variation, $switched_on, $renew, $stored );

		return $this->link( $account, $content, $variation, $fence, self::CREATED, array_merge( $notes, $warnings ) );
	}

	/**
	 * Writes the link and the note the merchant reads.
	 *
	 * @param Account      $account  Account.
	 * @param Card_Content $content  Card.
	 * @param \WC_Product  $product  The product the card belongs to.
	 * @param Claim_Fence  $fence    The run's claim.
	 * @param string       $done     The outcome when the link is written.
	 * @param string[]     $warnings What could not be set on a created product.
	 * @return string
	 * @throws Claim_Lost When the claim was taken over before the link was written.
	 */
	private function link( Account $account, Card_Content $content, \WC_Product $product, Claim_Fence $fence, string $done, array $warnings ): string {
		$article = (string) $content->card()['offer_id'];
		$result  = $this->linker->link( $account->id(), (int) $product->get_id(), (int) $product->get_parent_id(), $content->card(), $fence, self::CREATED !== $done ); // A product made from the card already holds the card's price: only its stock is sent first.

		if ( Card_Linker::CLAIM_LOST === $result ) {
			throw new Claim_Lost( 'The account claim was taken over before the link of an imported card was written.' );
		}

		if ( Card_Linker::LINKED !== $result ) {
			return $this->skipped( $account, $article, Card_Linker::message( $result ) );
		}

		$this->groups->parent_row( $account, $product, $content, $fence );

		$this->note( $account, $article, array_merge( $warnings, $this->groups->notice( $account, $product, $content ), $this->unit_linked( $account, $product ) ) );

		return $done;
	}

	/**
	 * Stores what the merchant should know about a card, or clears its note when there is nothing.
	 *
	 * @param Account  $account  Account.
	 * @param string   $article  Article.
	 * @param string[] $warnings Sentences.
	 * @return void
	 */
	private function note( Account $account, string $article, array $warnings ): void {
		if ( [] === $warnings ) {
			$this->notes->clear_import_note( $account->id(), $article );
		} else {
			$this->notes->set_import_note( $account->id(), $article, implode( ' ', $warnings ) );
		}
	}

	/**
	 * Closes the import of a unit once its card is linked: the pending mark goes, and a SKU the product could not get is said.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product The linked product.
	 * @return string[] The note for the merchant, if there is one.
	 */
	private function unit_linked( Account $account, \WC_Product $product ): array {
		if ( null === $this->units ) {
			return [];
		}

		$key = Unit_Products::pending_key( $account->adapter_id() );

		if ( '' !== (string) $product->get_meta( $key ) ) {
			$product->delete_meta_data( $key );
			$product->save();
		}

		$conflict = Unit_Products::sku_conflict_text( $account->adapter_id(), $product );

		return '' === $conflict ? [] : [ $conflict ];
	}

	/**
	 * Returns the variable product of a variation.
	 *
	 * @param \WC_Product $variation The variation.
	 * @return \WC_Product|null Null when it cannot be read.
	 */
	private function parent_of( \WC_Product $variation ): ?\WC_Product {
		$parent = wc_get_product( (int) $variation->get_parent_id() );

		return $parent instanceof \WC_Product ? $parent : null;
	}

	/**
	 * Gives the shop category of a product the import is making the mapping the merchant would otherwise make by hand, when the import
	 * chooses categories ({@see Import_Mapping}). It is done **before** the product is filled: the profile of the mapping in force then
	 * governs the description, the weight and the price the writer sets, as it governs every later export.
	 *
	 * @param Card_Content     $content Card.
	 * @param \WC_Product|null $product The product that will carry the card's attributes: the simple product, or the group's variable product.
	 * @return string[] The note for the merchant, if there is one.
	 */
	private function category_mapping( Card_Content $content, ?\WC_Product $product ): array {
		if ( null === $this->mapping || ! $this->settings->import_create_categories() ) {
			return [];
		}

		$note = $this->mapping->ensure( $content, $product );

		return '' === $note ? [] : [ $note ];
	}

	/**
	 * Leaves a card as it is and tells the merchant why.
	 *
	 * @param Account $account Account.
	 * @param string  $article Article.
	 * @param string  $text    The sentence the merchant reads.
	 * @return string
	 */
	private function skipped( Account $account, string $article, string $text ): string {
		$this->notes->set_import_note( $account->id(), $article, $text );

		return self::SKIPPED;
	}
}
