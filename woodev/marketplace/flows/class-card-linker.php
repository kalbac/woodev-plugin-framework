<?php
/**
 * Writes the link of an existing marketplace card to a shop product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Framework\Marketplace\Sync\Price_Intents;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;
use Woodev\Framework\Marketplace\Sync\Stock_Mode;

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
 * **The synchronisation switches.** A linker built with {@see self::syncing()} asks the account's «sync on link» setting
 * ({@see Effective_Settings::sync_on_link()}) for every link it writes. On: the row's `sync_stock` and `sync_price` are 1 (a new row, and an
 * adopted identity-less row alike), and a card that exists on the marketplace gets the same first send the product tab records when the
 * switches are turned on there. Off, or a linker built without the setting: both switches stay as they were (0 on a new row), so linking
 * never makes the shop push stock or price by itself.
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
	 * Tells whether an account switches synchronisation on when it links: `function ( int $account_id ): bool`. Null never does.
	 *
	 * @var callable|null
	 */
	private $sync_on_link;

	/**
	 * Records the first stock send of a fresh link: `function ( Link_Record $link ): void`.
	 *
	 * @var callable|null
	 */
	private $first_stock;

	/**
	 * Records the first price send of a fresh link: `function ( int $account_id, Link_Record $link ): void`.
	 *
	 * @var callable|null
	 */
	private $first_price;

	/**
	 * Creates the linker.
	 *
	 * @since 2.1.0
	 * @since 2.1.8 The three optional callables.
	 *
	 * @param Wpdb_Link_Store $links        Links.
	 * @param callable|null   $sync_on_link `function ( int $account_id ): bool` — whether the account switches synchronisation on at linking; null never does.
	 * @param callable|null   $first_stock  `function ( Link_Record $link ): void` — records the first stock send of a fresh link.
	 * @param callable|null   $first_price  `function ( int $account_id, Link_Record $link ): void` — records the first price send; null when the marketplace takes no prices.
	 */
	public function __construct( Wpdb_Link_Store $links, ?callable $sync_on_link = null, ?callable $first_stock = null, ?callable $first_price = null ) {
		$this->links        = $links;
		$this->sync_on_link = $sync_on_link;
		$this->first_stock  = $first_stock;
		$this->first_price  = $first_price;
	}

	/**
	 * Builds the linker the plugin runs with: it follows the account's «sync on link» setting and records the first sends through the
	 * plugin's own stock and price intents. The one place the three call sites of a plugin (the link job, the import and the remote-cards
	 * table) get the same behaviour from.
	 *
	 * @since 2.1.8
	 *
	 * @param Wpdb_Link_Store    $links    Links.
	 * @param Effective_Settings $settings Settings resolver.
	 * @param callable           $account  `function ( int $account_id ): ?Account`.
	 * @param Stock_Intents      $stock    Stock intents (not necessarily hooked).
	 * @param Price_Intents|null $price    Price intents; null for a marketplace without a price pusher.
	 * @return self
	 */
	public static function syncing( Wpdb_Link_Store $links, Effective_Settings $settings, callable $account, Stock_Intents $stock, ?Price_Intents $price ): self {
		return new self(
			$links,
			static function ( int $account_id ) use ( $settings, $account ): bool {
				$found = call_user_func( $account, $account_id );

				return null !== $found && $settings->sync_on_link( $found );
			},
			static function ( Link_Record $link ) use ( $stock ): void {
				$stock->record_stock( $link );
			},
			null === $price ? null : static function ( int $account_id, Link_Record $link ) use ( $price, $account ): void {
				$found = call_user_func( $account, $account_id );

				if ( null !== $found ) {
					$price->record_for_link( $found, $link );
				}
			}
		);
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
	 * @param bool                $send_price    Whether the first send may include the price. False for a product the importer just made from the card: its price came from the card, so sending it back would be a no-op at best.
	 * @return string One of the class constants; {@see self::CLAIM_LOST} tells the job to stop.
	 * @throws \Woodev\Framework\Marketplace\Data\Persistence_Exception When the database refuses the insert.
	 */
	public function link( int $account_id, int $wc_product_id, int $wc_parent_id, array $card, ?Claim_Fence $fence = null, bool $send_price = true ): string {
		$offer_id   = (string) ( $card['offer_id'] ?? '' );
		$remote_ids = Link_Record::clean_ids( (array) ( $card['remote_ids'] ?? [] ) );

		if ( '' === $offer_id || [] === $remote_ids ) {
			return self::NOT_A_CARD;
		}

		$owner = $this->links->owner_of_article( $account_id, $offer_id );

		if ( null !== $owner ) {
			return $owner->wc_product_id() === $wc_product_id ? self::PRODUCT_LINKED : self::CARD_LINKED;
		}

		$state = (string) ( $card['state'] ?? Link_Record::STATE_UNLINKED );
		$row   = $this->links->find( $account_id, $wc_product_id );
		$error = ! empty( $card['failed'] ) ? (string) ( $card['status_text'] ?? '' ) : '';
		$sync  = $this->switches( $account_id );

		if ( null === $row ) {
			$outcome = $this->insert( $account_id, $wc_product_id, $wc_parent_id, $card, $remote_ids, $state, $error, $sync, $fence );

			return $this->first_send( $outcome, $account_id, $wc_product_id, $card, $sync, $send_price );
		}

		if ( ! $row->is_offer() ) {
			return self::NOT_A_CARD;
		}

		if ( null !== $row->offer_id() || [] !== $row->remote_ids() ) {
			return self::PRODUCT_LINKED;
		}

		if ( $this->links->adopt_card( (int) $row->link_id(), $this->values( $card, $remote_ids, $state, $error, $sync ), $fence ) ) {
			return $this->first_send( self::LINKED, $account_id, $wc_product_id, $card, $sync, $send_price );
		}

		return $this->lost( $account_id, $fence ) ? self::CLAIM_LOST : self::BUSY;
	}

	/**
	 * The sentence a confirmation window shows about what linking does on the marketplace: that stock and prices will be sent when the
	 * account's «sync on link» setting is on and the account sends them from the site, otherwise the caller's own assurance that
	 * nothing changes there. The one place that sentence is built.
	 *
	 * @since 2.1.8
	 *
	 * @param Account $account   Account the card is linked in.
	 * @param string  $unchanged What to say when linking sends nothing («На маркетплейсе ничего не меняется.»).
	 * @return string
	 */
	public function remote_effect( Account $account, string $unchanged ): string {
		if ( [] === $this->switches( $account->id() ) ) {
			return $unchanged;
		}

		$stock = null !== $this->first_stock && Stock_Mode::is_site( $account );
		$price = null !== $this->first_price && Price_Intents::pushes( $account );

		if ( $stock && $price ) {
			return __( 'После связывания на маркетплейс могут быть отправлены остатки и цены с сайта (так включено в настройках аккаунта).', 'woodev-plugin-framework' );
		}

		if ( $stock ) {
			return __( 'После связывания на маркетплейс могут быть отправлены остатки с сайта (так включено в настройках аккаунта).', 'woodev-plugin-framework' );
		}

		return $price
			? __( 'После связывания на маркетплейс могут быть отправлены цены с сайта (так включено в настройках аккаунта).', 'woodev-plugin-framework' )
			: $unchanged;
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
	 * @param array<string,int>   $sync          The `sync_stock` / `sync_price` columns to write, [] to leave them off.
	 * @param Claim_Fence|null    $fence         The account claim of the run, or null.
	 * @return string
	 */
	private function insert( int $account_id, int $wc_product_id, int $wc_parent_id, array $card, array $remote_ids, string $state, string $error, array $sync, ?Claim_Fence $fence ): string {
		$record = Link_Record::offer(
			$account_id,
			$wc_product_id,
			$wc_parent_id,
			(string) $card['offer_id'],
			[
				'remote_ids' => $remote_ids,
				'card_state' => $state,
				'is_created' => ! empty( $card['is_created'] ) ? 1 : 0,
			] + $this->oversize( $card ) + $sync
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
	 * @param array<string,int>   $sync       The `sync_stock` / `sync_price` columns to write, [] to leave the row's switches.
	 * @return array<string,mixed>
	 */
	private function values( array $card, array $remote_ids, string $state, string $error, array $sync ): array {
		return [
			'offer_id'   => (string) $card['offer_id'],
			'remote_ids' => $remote_ids,
			'state'      => $state,
			'is_created' => ! empty( $card['is_created'] ),
			'error_text' => $error,
		] + $this->oversize( $card ) + $sync;
	}

	/**
	 * The synchronisation switches a link of this account is written with: both on when the account's setting says so, nothing otherwise.
	 *
	 * @param int $account_id Account id.
	 * @return array<string,int>
	 */
	private function switches( int $account_id ): array {
		if ( null === $this->sync_on_link || ! call_user_func( $this->sync_on_link, $account_id ) ) {
			return [];
		}

		return [
			'sync_stock' => 1,
			'sync_price' => 1,
		];
	}

	/**
	 * Records the first send of a link that was just written with its switches on, the way the product tab does when the switches are
	 * turned on there: the stock intent, and the price intent unless the caller keeps the price back. A card that is not created yet
	 * gets nothing (its creation seeds the stock and the price). Each recorder decides for itself whether the account sends at all.
	 *
	 * @param string              $outcome    Outcome of the write.
	 * @param int                 $account_id Account id.
	 * @param int                 $product_id Product or variation id.
	 * @param array<string,mixed> $card       Card.
	 * @param array<string,int>   $sync       What {@see self::switches()} returned.
	 * @param bool                $send_price Whether the price may be sent too.
	 * @return string The same outcome.
	 */
	private function first_send( string $outcome, int $account_id, int $product_id, array $card, array $sync, bool $send_price ): string {
		if ( self::LINKED !== $outcome || [] === $sync || empty( $card['is_created'] ) ) {
			return $outcome;
		}

		$link = $this->links->find( $account_id, $product_id );

		if ( null === $link ) {
			return $outcome;
		}

		if ( null !== $this->first_stock ) {
			call_user_func( $this->first_stock, $link );
		}

		if ( $send_price && null !== $this->first_price ) {
			call_user_func( $this->first_price, $account_id, $link );
		}

		return $outcome;
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
