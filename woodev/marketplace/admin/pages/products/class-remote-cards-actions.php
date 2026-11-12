<?php
/**
 * The actions of the «Карточки на маркетплейсе» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Cards_Snapshot_Store;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Plans and does the three actions of the table. **None of them calls the marketplace**: «Обновить список» and «Связать по артикулу»
 * start a background job, and «Связать с…» writes one row of our own link table.
 *
 * | Action | What it does | Who may |
 * |---|---|---|
 * | `refresh_list` | marks the snapshot as being refreshed and queues the job that fills it | `manage_woocommerce` |
 * | `link_by_article` | the account-wide job of {@see Site_Products_Bulk}, the same as on the products table | `manage_woocommerce` |
 * | `link_to` | links the one card to the product the merchant picked ({@see Card_Linker}) | `edit_products`, and `edit_post` on the product |
 *
 * The card for `link_to` is read from **this account's** snapshot, never from the request: a row id names a card by the key of its
 * article, and a card that is not in the snapshot any more (the list expired) is refused as a stale list.
 *
 * Both of the linking actions are previewed and then replanned at apply, as every action of the records engine is.
 *
 * @since 2.1.5
 */
final class Remote_Cards_Actions {
	/**
	 * @var Cards_Snapshot_Store
	 */
	private $snapshots;

	/**
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * @var Card_Linker
	 */
	private $linker;

	/**
	 * @var Link_Scheduler
	 */
	private $scheduler;

	/**
	 * @var Site_Products_Bulk
	 */
	private $bulk;

	/**
	 * @var License_Gate
	 */
	private $license;

	/**
	 * @var Remote_Cards_Products
	 */
	private $products;

	/**
	 * @var Remote_Cards_Rows
	 */
	private $rows;

	/**
	 * Creates the actions.
	 *
	 * @since 2.1.5
	 *
	 * @param Cards_Snapshot_Store  $snapshots Snapshot of the cabinet's cards.
	 * @param Wpdb_Link_Store       $links     Links.
	 * @param Card_Linker           $linker    The one place that writes a card's link.
	 * @param Link_Scheduler        $scheduler Queues the jobs.
	 * @param Site_Products_Bulk    $bulk      The products table's actions, for «Связать по артикулу».
	 * @param License_Gate          $license   Licence gate.
	 * @param Remote_Cards_Products $products  The shop's products.
	 * @param Remote_Cards_Rows     $rows      Row builder, for the row an action changed.
	 */
	public function __construct( Cards_Snapshot_Store $snapshots, Wpdb_Link_Store $links, Card_Linker $linker, Link_Scheduler $scheduler, Site_Products_Bulk $bulk, License_Gate $license, Remote_Cards_Products $products, Remote_Cards_Rows $rows ) {
		$this->snapshots = $snapshots;
		$this->links     = $links;
		$this->linker    = $linker;
		$this->scheduler = $scheduler;
		$this->bulk      = $bulk;
		$this->license   = $license;
		$this->products  = $products;
		$this->rows      = $rows;
	}

	/**
	 * The plan of an action. Writes nothing.
	 *
	 * @since 2.1.5
	 *
	 * @param Account             $account Account.
	 * @param string              $action  Action id.
	 * @param string[]            $ids     Row ids (one for `link_to`, none for an account action).
	 * @param array<string,mixed> $payload Validated fields.
	 * @return array<string,mixed> The preview answer of the records engine.
	 * @throws Action_State_Exception When the licence is not in force.
	 * @throws Action_Input_Exception When the picked product cannot take a card.
	 */
	public function preview( Account $account, string $action, array $ids, array $payload ): array {
		$this->require_licence( $action );

		switch ( $action ) {
			case Remote_Cards_Definition::LINK_TO:
				return $this->preview_link( $account, (string) ( $ids[0] ?? '' ), $payload );
			case Remote_Cards_Definition::REFRESH:
				return $this->preview_refresh( $account );
			default:
				return $this->bulk->preview( $account, $action, [], [] );
		}
	}

	/**
	 * Replans from the current state and does the action.
	 *
	 * @since 2.1.5
	 *
	 * @param Account             $account Account.
	 * @param string              $action  Action id.
	 * @param string[]            $ids     Row ids.
	 * @param array<string,mixed> $payload Validated fields.
	 * @return array<string,mixed> The apply answer of the records engine.
	 * @throws Action_State_Exception When the licence is not in force or the queue refuses the job.
	 * @throws Action_Input_Exception When the picked product cannot take a card.
	 */
	public function apply( Account $account, string $action, array $ids, array $payload ): array {
		$this->require_licence( $action );

		switch ( $action ) {
			case Remote_Cards_Definition::LINK_TO:
				return $this->apply_link( $account, (string) ( $ids[0] ?? '' ), $payload );
			case Remote_Cards_Definition::REFRESH:
				return $this->apply_refresh( $account );
			default:
				return $this->bulk->apply( $account, $action, [], [] );
		}
	}

	/**
	 * @param Account             $account Account.
	 * @param string              $row_id  Row id.
	 * @param array<string,mixed> $payload Fields.
	 * @return array<string,mixed>
	 */
	private function preview_link( Account $account, string $row_id, array $payload ): array {
		$product = $this->products->pick( $payload[ Remote_Cards_Definition::PRODUCT_FIELD ] ?? null, Remote_Cards_Definition::PRODUCT_FIELD );
		$card    = $this->card( $account, $row_id );
		$reason  = $this->reason_not_to_link( $card );

		if ( null !== $reason ) {
			return $this->refused_preview( $reason );
		}

		return [
			'requested'      => 1,
			'eligible'       => 1,
			'skipped'        => 0,
			'scope_sentence' => sprintf(
				/* translators: 1: the card's article, 2: the card's name, 3: the product's name. */
				__( 'Карточка %1$s «%2$s» будет связана с товаром «%3$s». На маркетплейсе ничего не меняется.', 'woodev-plugin-framework' ),
				(string) $card['offer_id'],
				(string) ( $card['name'] ?? '' ),
				$product['title']
			),
		];
	}

	/**
	 * @param Account             $account Account.
	 * @param string              $row_id  Row id.
	 * @param array<string,mixed> $payload Fields.
	 * @return array<string,mixed>
	 */
	private function apply_link( Account $account, string $row_id, array $payload ): array {
		$product = $this->products->pick( $payload[ Remote_Cards_Definition::PRODUCT_FIELD ] ?? null, Remote_Cards_Definition::PRODUCT_FIELD );
		$card    = $this->card( $account, $row_id );
		$reason  = $this->reason_not_to_link( $card );

		if ( null !== $reason ) {
			return $this->refused_apply( $reason, (string) ( $card['offer_id'] ?? '' ) );
		}

		try {
			$outcome = $this->linker->link( $account->id(), $product['id'], $product['parent_id'], $card );
		} catch ( Persistence_Exception $e ) {
			return [
				'requested' => 1,
				'accepted'  => 0,
				'skipped'   => 0,
				'failed'    => 1,
				'groups'    => [ $this->group( __( 'Не удалось записать связь: база данных не ответила. Повторите чуть позже.', 'woodev-plugin-framework' ), 'failed', $card ) ],
			];
		}

		if ( Card_Linker::LINKED !== $outcome ) {
			return $this->refused_apply( Card_Linker::message( $outcome ), (string) $card['offer_id'] );
		}

		$text = Card_Linker::message( Card_Linker::LINKED );

		return [
			'requested' => 1,
			'accepted'  => 1,
			'skipped'   => 0,
			'failed'    => 0,
			'rows'      => [ $this->rows->row( $account->id(), array_merge( $card, [ 'wc_product_id' => $product['id'] ] ) ) ],
			'messages'  => [ 'action_status_done' => $text ],
		];
	}

	/**
	 * @param Account $account Account.
	 * @return array<string,mixed>
	 */
	private function preview_refresh( Account $account ): array {
		if ( $this->snapshots->is_refreshing( $account->id() ) ) {
			return $this->refused_preview( $this->already_refreshing() );
		}

		return [
			'requested' => 1,
			'eligible'  => 1,
			'skipped'   => 0,
		];
	}

	/**
	 * Marks the snapshot as being refreshed, then queues the job: the mark comes first so that two clicks do not queue two reads.
	 *
	 * @param Account $account Account.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When Action Scheduler refuses the job.
	 */
	private function apply_refresh( Account $account ): array {
		if ( $this->snapshots->is_refreshing( $account->id() ) ) {
			return $this->refused_apply( $this->already_refreshing(), '' );
		}

		$this->snapshots->mark_refreshing( $account->id() );

		if ( ! $this->scheduler->refresh_cards( $account->id() ) ) {
			$text = __( 'Не удалось поставить загрузку списка в очередь. Проверьте, что в WooCommerce работает планировщик задач (Action Scheduler), и повторите.', 'woodev-plugin-framework' );
			$this->snapshots->fail( $account->id(), $text );

			throw new Action_State_Exception( $text );
		}

		$text = __( 'Список карточек загружается. Это займёт от нескольких секунд до пары минут — обновите страницу чуть позже.', 'woodev-plugin-framework' );

		return [
			'requested' => 1,
			'accepted'  => 1,
			'skipped'   => 0,
			'failed'    => 0,
			'queued'    => true,
			'messages'  => [ 'action_status_queued' => $text ],
		];
	}

	/**
	 * The card of a row, read from the account's snapshot.
	 *
	 * @param Account $account Account.
	 * @param string  $row_id  Row id.
	 * @return array<string,mixed>|null Null when the snapshot has expired or no longer holds the card.
	 */
	private function card( Account $account, string $row_id ): ?array {
		$key      = Remote_Cards_Ids::key_of( $row_id, $account->id() );
		$snapshot = null === $key ? null : $this->snapshots->get( $account->id() );

		return null === $snapshot ? null : Remote_Cards_Query::find( $snapshot['cards'], (string) $key );
	}

	/**
	 * Why a card cannot be linked from this list, or null when it can. A card whose details did not agree with the list is not
	 * linkable: its identifiers are not trustworthy.
	 *
	 * @param array<string,mixed>|null $card Card.
	 * @return string|null
	 */
	private function reason_not_to_link( ?array $card ): ?string {
		if ( null === $card ) {
			return __( 'Этой карточки нет в загруженном списке — он мог устареть. Нажмите «Обновить список» и повторите.', 'woodev-plugin-framework' );
		}

		if ( empty( $card['details_ok'] ) ) {
			return __( 'О карточке нет полных данных: при загрузке списка они не совпали. Обновите список и повторите.', 'woodev-plugin-framework' );
		}

		return null;
	}

	/**
	 * @return string
	 */
	private function already_refreshing(): string {
		return __( 'Список уже загружается. Дождитесь окончания и обновите страницу.', 'woodev-plugin-framework' );
	}

	/**
	 * A preview in which the one asked-for target is left out for a reason.
	 *
	 * @param string $reason Reason.
	 * @return array<string,mixed>
	 */
	private function refused_preview( string $reason ): array {
		return [
			'requested' => 1,
			'eligible'  => 0,
			'skipped'   => 1,
			'groups'    => [ $this->group( $reason, 'skipped', null ) ],
		];
	}

	/**
	 * An apply in which the one asked-for target is left out for a reason.
	 *
	 * @param string $reason   Reason.
	 * @param string $offer_id The card's article, for the example; '' for none.
	 * @return array<string,mixed>
	 */
	private function refused_apply( string $reason, string $offer_id ): array {
		return [
			'requested' => 1,
			'accepted'  => 0,
			'skipped'   => 1,
			'failed'    => 0,
			'groups'    => [ $this->group( $reason, 'skipped', '' === $offer_id ? null : [ 'offer_id' => $offer_id ] ) ],
		];
	}

	/**
	 * Builds one group of reasons for the one target.
	 *
	 * @param string                   $reason Reason.
	 * @param string                   $kind   `skipped` or `failed`.
	 * @param array<string,mixed>|null $card   The card, whose article is the example; null for none.
	 * @return array<string,mixed>
	 */
	private function group( string $reason, string $kind, ?array $card ): array {
		return [
			'kind'     => $kind,
			'reason'   => $reason,
			'count'    => 1,
			'examples' => null === $card || '' === (string) ( $card['offer_id'] ?? '' ) ? [] : [ (string) $card['offer_id'] ],
		];
	}

	/**
	 * Refuses an action that reaches the marketplace while the licence is not in force.
	 *
	 * @param string $action Action id.
	 * @return void
	 * @throws Action_State_Exception When the licence does not allow it.
	 */
	private function require_licence( string $action ): void {
		$phrases = [
			Remote_Cards_Definition::REFRESH    => __( 'загрузить список карточек с маркетплейса', 'woodev-plugin-framework' ),
			Site_Products_Bulk::LINK_BY_ARTICLE => __( 'искать карточки на маркетплейсе', 'woodev-plugin-framework' ),
		];

		$refusal = isset( $phrases[ $action ] ) ? $this->license->refusal_for( $phrases[ $action ] ) : null;

		if ( null !== $refusal ) {
			throw new Action_State_Exception( $refusal );
		}
	}
}
