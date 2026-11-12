<?php
/**
 * Writes the link of an existing marketplace card to a shop product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that turns «this card is this product» into a link row. The linking job (exact matches), the confirmation of a near
 * match and the manual «Связать с…» all call it, so they cannot disagree about what may be linked.
 *
 * It writes only to our table and **never to the marketplace**. It never rewrites a link that exists:
 *
 * - a product that already has an article or a marketplace identifier keeps it ({@see self::PRODUCT_LINKED});
 * - a card whose article already belongs to a row, of this or another product, is not given to a second one
 *   ({@see self::CARD_LINKED}) — the unique `(account_id, offer_id)` index is the last guard;
 * - the configuration row of a variable product is not a card ({@see self::NOT_A_CARD});
 * - a row an export holds right now is left alone ({@see self::BUSY}).
 * - a run whose account claim was taken over writes nothing ({@see self::CLAIM_LOST}): the claim is a condition of the new-row
 *   `INSERT` and of the adoption `UPDATE` themselves, and a declined card's text goes in the same statement.
 *
 * The link starts with both synchronisation switches off: linking a card must not make the shop push stock or price to it by itself.
 *
 * @since 2.1.0
 */
final class Card_Linker {
	public const LINKED         = 'linked';
	public const PRODUCT_LINKED = 'product_linked';
	public const CARD_LINKED    = 'card_linked';
	public const NOT_A_CARD     = 'not_a_card';
	public const BUSY           = 'busy';
	public const CLAIM_LOST     = 'claim_lost';

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Creates the linker.
	 *
	 * @since 2.1.0
	 *
	 * @param Wpdb_Link_Store $links Links.
	 */
	public function __construct( Wpdb_Link_Store $links ) {
		$this->links = $links;
	}

	/**
	 * Links a card to a product or variation.
	 *
	 * @since 2.1.0
	 *
	 * @param int                 $account_id    Account id.
	 * @param int                 $wc_product_id Product or variation id.
	 * @param int                 $wc_parent_id  Parent product id, 0 for a simple product.
	 * @param array<string,mixed> $card          A {@see Link_Resolver} card: `offer_id`, `remote_ids` and, when known, `state`, `is_created`, `status_text`, `failed`, and `oversize` (bool; an absent key keeps the stored flag).
	 * @param Claim_Fence|null    $fence         The account claim of the run, or null at request time.
	 * @return string One of the class constants; {@see self::CLAIM_LOST} tells the job to stop.
	 * @throws \Woodev\Framework\Marketplace\Data\Persistence_Exception When the database refuses the insert.
	 */
	public function link( int $account_id, int $wc_product_id, int $wc_parent_id, array $card, ?Claim_Fence $fence = null ): string {
		$offer_id   = (string) ( $card['offer_id'] ?? '' );
		$remote_ids = Link_Record::clean_ids( (array) ( $card['remote_ids'] ?? [] ) );

		if ( '' === $offer_id || [] === $remote_ids ) {
			return self::NOT_A_CARD;
		}

		$owner = $this->links->find_by_offer_id( $account_id, $offer_id );

		if ( null !== $owner ) {
			return $owner->wc_product_id() === $wc_product_id ? self::PRODUCT_LINKED : self::CARD_LINKED;
		}

		$state = (string) ( $card['state'] ?? Link_Record::STATE_UNLINKED );
		$row   = $this->links->find( $account_id, $wc_product_id );
		$error = ! empty( $card['failed'] ) ? (string) ( $card['status_text'] ?? '' ) : '';

		if ( null === $row ) {
			return $this->insert( $account_id, $wc_product_id, $wc_parent_id, $card, $remote_ids, $state, $error, $fence );
		}

		if ( ! $row->is_offer() ) {
			return self::NOT_A_CARD;
		}

		if ( null !== $row->offer_id() || [] !== $row->remote_ids() ) {
			return self::PRODUCT_LINKED;
		}

		if ( $this->links->adopt_card( (int) $row->link_id(), $this->values( $card, $remote_ids, $state, $error ), $fence ) ) {
			return self::LINKED;
		}

		return $this->lost( $account_id, $fence ) ? self::CLAIM_LOST : self::BUSY;
	}

	/**
	 * Tells the merchant what an outcome means.
	 *
	 * @since 2.1.0
	 *
	 * @param string $outcome One of the class constants.
	 * @return string Russian sentence.
	 */
	public static function message( string $outcome ): string {
		switch ( $outcome ) {
			case self::LINKED:
				return __( 'Товар связан с карточкой маркетплейса.', 'woodev-plugin-framework' );
			case self::PRODUCT_LINKED:
				return __( 'Этот товар уже связан с карточкой маркетплейса. Сначала снимите старую связь.', 'woodev-plugin-framework' );
			case self::CARD_LINKED:
				return __( 'Эта карточка маркетплейса уже связана с другим товаром.', 'woodev-plugin-framework' );
			case self::NOT_A_CARD:
				return __( 'К этому товару нельзя привязать карточку. Для вариативного товара выберите один из его вариантов.', 'woodev-plugin-framework' );
			default:
				return __( 'Товар сейчас обрабатывается (например, выгружается на маркетплейс). Повторите чуть позже.', 'woodev-plugin-framework' );
		}
	}

	/**
	 * Inserts a new row, the marketplace's text for a declined card included, in one statement.
	 *
	 * @param int                 $account_id    Account id.
	 * @param int                 $wc_product_id Product id.
	 * @param int                 $wc_parent_id  Parent id.
	 * @param array<string,mixed> $card          Card.
	 * @param array<string,mixed> $remote_ids    The card's identifiers, cleaned.
	 * @param string              $state         Card state.
	 * @param string              $error         The marketplace's text for a declined card, '' otherwise.
	 * @param Claim_Fence|null    $fence         The account claim of the run, or null.
	 * @return string
	 */
	private function insert( int $account_id, int $wc_product_id, int $wc_parent_id, array $card, array $remote_ids, string $state, string $error, ?Claim_Fence $fence ): string {
		$record = Link_Record::offer(
			$account_id,
			$wc_product_id,
			$wc_parent_id,
			(string) $card['offer_id'],
			[
				'remote_ids' => $remote_ids,
				'card_state' => $state,
				'is_created' => ! empty( $card['is_created'] ) ? 1 : 0,
			] + $this->oversize( $card )
		);

		switch ( $this->links->insert_card( $record, $error, $fence ) ) {
			case Wpdb_Link_Store::INSERTED:
				return self::LINKED;
			case Wpdb_Link_Store::FENCED_OUT:
				return self::CLAIM_LOST;
			default:
				return self::BUSY; // Another run took the product or the article between the two reads.
		}
	}

	/**
	 * Tells whether a failed write was a lost claim.
	 *
	 * @param int              $account_id Account id.
	 * @param Claim_Fence|null $fence      The run's claim, or null at request time.
	 * @return bool
	 */
	private function lost( int $account_id, ?Claim_Fence $fence ): bool {
		return null !== $fence && ! $this->links->claim_held( $account_id, $fence );
	}

	/**
	 * Builds the values {@see Wpdb_Link_Store::adopt_card()} writes.
	 *
	 * @param array<string,mixed> $card       Card.
	 * @param array<string,mixed> $remote_ids The card's identifiers, cleaned.
	 * @param string              $state      Card state.
	 * @param string              $error      Text for a declined card.
	 * @return array<string,mixed>
	 */
	private function values( array $card, array $remote_ids, string $state, string $error ): array {
		return [
			'offer_id'   => (string) $card['offer_id'],
			'remote_ids' => $remote_ids,
			'state'      => $state,
			'is_created' => ! empty( $card['is_created'] ),
			'error_text' => $error,
		] + $this->oversize( $card );
	}

	/**
	 * Builds the `is_oversize` column from the card's `oversize` flag. An absent key yields nothing, so the stored flag stays.
	 *
	 * @param array<string,mixed> $card Card.
	 * @return array<string,int>
	 */
	private function oversize( array $card ): array {
		if ( ! array_key_exists( 'oversize', $card ) ) {
			return [];
		}

		return [ 'is_oversize' => ! empty( $card['oversize'] ) ? 1 : 0 ];
	}
}
