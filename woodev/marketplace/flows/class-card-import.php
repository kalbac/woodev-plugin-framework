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
	 * Creates a product: {@see Product_Writer::write()}.
	 *
	 * @var callable
	 */
	private $write;

	/**
	 * Finishes a product an earlier run started: {@see Product_Writer::complete()}.
	 *
	 * @var callable
	 */
	private $resume;

	/**
	 * Creates the importer.
	 *
	 * @since 2.1.6
	 *
	 * @param Wpdb_Link_Store    $links    Links.
	 * @param Card_Linker        $linker   Linker.
	 * @param Link_Report_Store  $notes    Import notes.
	 * @param Effective_Settings $settings The shop's import settings.
	 * @param callable           $write    `function ( Account, Card_Content, bool $publish, bool $create_categories, callable $renew, ?callable $stored ): array{product:\WC_Product,warnings:string[]}`, `Product_Writer::write()`.
	 * @param callable           $resume   `function ( Account, Card_Content, \WC_Product, bool $publish, callable $renew, ?callable $stored ): string[]`, `Product_Writer::complete()`.
	 */
	public function __construct( Wpdb_Link_Store $links, Card_Linker $linker, Link_Report_Store $notes, Effective_Settings $settings, callable $write, callable $resume ) {
		$this->links    = $links;
		$this->linker   = $linker;
		$this->notes    = $notes;
		$this->settings = $settings;
		$this->write    = $write;
		$this->resume   = $resume;
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
	 * @return string One of the outcome constants of this class.
	 * @throws Claim_Lost When the link is refused because another run took the account claim.
	 * @throws \Throwable Whatever WooCommerce throws when it refuses a save; the caller notes it for this card.
	 */
	public function import( Account $account, Card_Content $content, Claim_Fence $fence, callable $renew, ?callable $stored = null ): string {
		$card    = $content->card();
		$article = (string) ( $card['offer_id'] ?? '' );

		if ( '' === $article ) {
			return self::SKIPPED;
		}

		if ( null !== $this->links->find_by_offer_id( $account->id(), $article ) ) {
			$this->notes->clear_import_note( $account->id(), $article );

			return self::ALREADY_LINKED;
		}

		if ( ! empty( $card['archived'] ) || Link_Record::STATE_ARCHIVED === (string) ( $card['state'] ?? '' ) ) {
			return $this->skipped( $account, $article, __( 'Карточка в архиве — не импортируем.', 'woodev-plugin-framework' ) );
		}

		$product = $this->product_with_article( $article );

		if ( null === $product ) {
			return $this->create( $account, $content, $fence, $renew, $stored );
		}

		if ( Product_Writer::is_marked( $product, $account->adapter_id() ) ) {
			return $this->resume( $account, $content, $product, $fence, $renew, $stored );
		}

		return $this->link_existing( $account, $content, $product, $fence );
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
	 * Row 3: links the product that carries the article, leaving its content alone.
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

		return $this->link( $account, $content, $product, $fence, self::LINKED_EXISTING, [] );
	}

	/**
	 * Row 4: finishes the product an earlier run started, then links it.
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
		$warnings = (array) call_user_func( $this->resume, $account, $content, $product, $this->settings->import_publish(), $renew, $stored );

		return $this->link( $account, $content, $product, $fence, self::CREATED, $warnings );
	}

	/**
	 * Row 5: creates the product, then links it.
	 *
	 * @param Account       $account Account.
	 * @param Card_Content  $content Card.
	 * @param Claim_Fence   $fence   The run's claim.
	 * @param callable      $renew   Extends the claim.
	 * @param callable|null $stored Called when a new image was stored.
	 * @return string
	 */
	private function create( Account $account, Card_Content $content, Claim_Fence $fence, callable $renew, ?callable $stored ): string {
		$written = call_user_func( $this->write, $account, $content, $this->settings->import_publish(), $this->settings->import_create_categories(), $renew, $stored );

		return $this->link( $account, $content, $written['product'], $fence, self::CREATED, (array) $written['warnings'] );
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
		$result  = $this->linker->link( $account->id(), (int) $product->get_id(), (int) $product->get_parent_id(), $content->card(), $fence );

		if ( Card_Linker::CLAIM_LOST === $result ) {
			throw new Claim_Lost( 'The account claim was taken over before the link of an imported card was written.' );
		}

		if ( Card_Linker::LINKED !== $result ) {
			return $this->skipped( $account, $article, Card_Linker::message( $result ) );
		}

		if ( [] === $warnings ) {
			$this->notes->clear_import_note( $account->id(), $article );
		} else {
			$this->notes->set_import_note( $account->id(), $article, implode( ' ', $warnings ) );
		}

		return $done;
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
