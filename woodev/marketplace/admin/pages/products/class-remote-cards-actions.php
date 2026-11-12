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
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Plans and does the actions of the table. **None of them calls the marketplace**: «Обновить список» and «Связать по артикулу»
 * start a background job, and the other three write our own link table or the near-match report.
 *
 * | Action | What it does | Who may |
 * |---|---|---|
 * | `refresh_list` | marks the snapshot as being refreshed and queues the job that fills it | `manage_woocommerce` |
 * | `link_by_article` | the account-wide job of {@see Site_Products_Bulk}, the same as on the products table | `manage_woocommerce` |
 * | `link_to` | links the one card to the product the merchant picked ({@see Card_Linker}); a link settles the pair in the near-match report | `edit_products`, and `edit_post` on the product |
 * | `near_confirm` | «Это тот же товар»: links a pair of the near-match report from the report's own copy of the card | `edit_products`, and `edit_post` on the product |
 * | `near_dismiss` | «Нет, это разные»: takes the pair out of the report and keeps the answer ({@see Link_Report_Store::dismiss_near_match()}) | `edit_products`, and `edit_post` on the product |
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
	 * @var Link_Report_Store
	 */
	private $reports;

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
	 * @param Link_Report_Store     $reports   The near-match report, which a link settles.
	 * @param Card_Linker           $linker    The one place that writes a card's link.
	 * @param Link_Scheduler        $scheduler Queues the jobs.
	 * @param Site_Products_Bulk    $bulk      The products table's actions, for «Связать по артикулу».
	 * @param License_Gate          $license   Licence gate.
	 * @param Remote_Cards_Products $products  The shop's products.
	 * @param Remote_Cards_Rows     $rows      Row builder, for the row an action changed.
	 */
	public function __construct( Cards_Snapshot_Store $snapshots, Wpdb_Link_Store $links, Link_Report_Store $reports, Card_Linker $linker, Link_Scheduler $scheduler, Site_Products_Bulk $bulk, License_Gate $license, Remote_Cards_Products $products, Remote_Cards_Rows $rows ) {
		$this->snapshots = $snapshots;
		$this->links     = $links;
		$this->reports   = $reports;
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
			case Remote_Cards_Definition::NEAR_CONFIRM:
			case Remote_Cards_Definition::NEAR_DISMISS:
				return $this->preview_near( $account, $action, (string) ( $ids[0] ?? '' ) );
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
			case Remote_Cards_Definition::NEAR_CONFIRM:
				return $this->apply_near_confirm( $account, (string) ( $ids[0] ?? '' ) );
			case Remote_Cards_Definition::NEAR_DISMISS:
				return $this->apply_near_dismiss( $account, (string) ( $ids[0] ?? '' ) );
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
			return $this->write_failed( $card );
		}

		if ( Card_Linker::LINKED !== $outcome ) {
			return $this->refused_apply( Card_Linker::message( $outcome ), (string) $card['offer_id'] );
		}

		$this->reports->settle_near_match( $account->id(), $product['id'], (string) $card['offer_id'], true );

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
	 * What «Это тот же товар» and «Нет, это разные» will do. Both read the report, which is local.
	 *
	 * @param Account $account Account.
	 * @param string  $action  {@see Remote_Cards_Definition::NEAR_CONFIRM} or {@see Remote_Cards_Definition::NEAR_DISMISS}.
	 * @param string  $row_id  Row id of the report.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the product cannot take the card, or the user may not change it.
	 */
	private function preview_near( Account $account, string $action, string $row_id ): array {
		$entry = $this->near_entry( $account, $row_id );

		if ( null === $entry ) {
			return $this->refused_preview( $this->pair_gone() );
		}

		$product_id = (int) $entry['wc_product_id'];
		$card       = (array) $entry['card'];

		if ( Remote_Cards_Definition::NEAR_DISMISS === $action ) {
			$this->require_editable( $product_id );

			return [
				'requested'      => 1,
				'eligible'       => 1,
				'skipped'        => 0,
				'scope_sentence' => __( 'Пара будет убрана из списка, товар и карточка останутся несвязанными. Эта пара не вернётся при следующем поиске по артикулу, пока артикул не изменится.', 'woodev-plugin-framework' ),
			];
		}

		$product = $this->near_product( $product_id );

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
	 * «Это тот же товар»: links the pair from the report's own copy of the card (no call to the marketplace) and settles it.
	 *
	 * @param Account $account Account.
	 * @param string  $row_id  Row id of the report.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the product cannot take the card, or the user may not change it.
	 */
	private function apply_near_confirm( Account $account, string $row_id ): array {
		$entry = $this->near_entry( $account, $row_id );

		if ( null === $entry ) {
			return $this->gone_apply( $row_id );
		}

		$card    = (array) $entry['card'];
		$offer   = (string) $card['offer_id'];
		$product = $this->near_product( (int) $entry['wc_product_id'] );

		try {
			$outcome = $this->linker->link( $account->id(), $product['id'], $product['parent_id'], $card );
		} catch ( Persistence_Exception $e ) {
			return $this->write_failed( $card );
		}

		if ( Card_Linker::BUSY === $outcome || Card_Linker::NOT_A_CARD === $outcome || Card_Linker::CLAIM_LOST === $outcome ) {
			return $this->refused_apply( Card_Linker::message( $outcome ), $offer );
		}

		// Linked, or one side was linked to something else meanwhile: either way the pair is no longer a question.
		$this->reports->settle_near_match( $account->id(), $product['id'], $offer, true );

		if ( Card_Linker::LINKED !== $outcome ) {
			return array_merge( $this->refused_apply( Card_Linker::message( $outcome ), $offer ), [ 'removed_ids' => [ $row_id ] ] );
		}

		return $this->settled_apply( $row_id, Card_Linker::message( Card_Linker::LINKED ) );
	}

	/**
	 * «Нет, это разные»: removes the pair and remembers the answer.
	 *
	 * @param Account $account Account.
	 * @param string  $row_id  Row id of the report.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the user may not change the product.
	 */
	private function apply_near_dismiss( Account $account, string $row_id ): array {
		$entry = $this->near_entry( $account, $row_id );

		if ( null === $entry ) {
			return $this->gone_apply( $row_id );
		}

		$product_id = (int) $entry['wc_product_id'];

		$this->require_editable( $product_id );
		$this->reports->dismiss_near_match( $account->id(), $product_id, (string) $entry['card']['offer_id'] );

		return $this->settled_apply( $row_id, __( 'Пара убрана из списка: товар и карточка остались несвязанными.', 'woodev-plugin-framework' ) );
	}

	/**
	 * The entry of the report a row id names.
	 *
	 * @param Account $account Account.
	 * @param string  $row_id  Row id.
	 * @return array<string,mixed>|null Null when the id is not a report row of this account, or the pair has been settled.
	 */
	private function near_entry( Account $account, string $row_id ): ?array {
		$ref = Remote_Cards_Ids::near_of( $row_id, $account->id() );

		return null === $ref ? null : Remote_Cards_Query::find_near( $this->reports->near_matches( $account->id() ), $ref['key'], $ref['product_id'] );
	}

	/**
	 * The product of a pair that «Это тот же товар» will link.
	 *
	 * @param int $product_id Product or variation id.
	 * @return array{id:int,parent_id:int,title:string}
	 * @throws Action_State_Exception When it is gone, may not be changed by the user or cannot take a card.
	 */
	private function near_product( int $product_id ): array {
		if ( ! wc_get_product( $product_id ) ) {
			throw new Action_State_Exception( __( 'Этого товара уже нет на сайте. Уберите пару кнопкой «Нет, это разные».', 'woodev-plugin-framework' ) );
		}

		try {
			return $this->products->pick( (string) $product_id, Remote_Cards_Definition::PRODUCT_FIELD );
		} catch ( Action_Input_Exception $e ) {
			throw new Action_State_Exception( (string) ( array_values( $e->get_errors() )[0] ?? Card_Linker::message( Card_Linker::NOT_A_CARD ) ) );
		}
	}

	/**
	 * A product that still exists must be one the user may change before its pair is decided; a product that is gone has nothing to protect.
	 *
	 * @param int $product_id Product or variation id.
	 * @return void
	 * @throws Action_State_Exception When the user may not change the product.
	 */
	private function require_editable( int $product_id ): void {
		if ( wc_get_product( $product_id ) && ! current_user_can( 'edit_post', $product_id ) ) {
			throw new Action_State_Exception( __( 'У вас нет прав на изменение этого товара.', 'woodev-plugin-framework' ) );
		}
	}

	/**
	 * @return string
	 */
	private function pair_gone(): string {
		return __( 'Эта пара уже решена или список похожих артикулов обновился. Обновите страницу.', 'woodev-plugin-framework' );
	}

	/**
	 * An apply for a pair that is no longer in the report: nothing to do, and the row goes.
	 *
	 * @param string $row_id Row id.
	 * @return array<string,mixed>
	 */
	private function gone_apply( string $row_id ): array {
		return array_merge( $this->refused_apply( $this->pair_gone(), '' ), [ 'removed_ids' => [ $row_id ] ] );
	}

	/**
	 * An apply that settled the pair: the row goes.
	 *
	 * @param string $row_id Row id.
	 * @param string $text   What happened.
	 * @return array<string,mixed>
	 */
	private function settled_apply( string $row_id, string $text ): array {
		return [
			'requested'   => 1,
			'accepted'    => 1,
			'skipped'     => 0,
			'failed'      => 0,
			'removed_ids' => [ $row_id ],
			'messages'    => [ 'action_status_done' => $text ],
		];
	}

	/**
	 * The database did not take a link.
	 *
	 * @param array<string,mixed> $card The card.
	 * @return array<string,mixed>
	 */
	private function write_failed( array $card ): array {
		return [
			'requested' => 1,
			'accepted'  => 0,
			'skipped'   => 0,
			'failed'    => 1,
			'groups'    => [ $this->group( __( 'Не удалось записать связь: база данных не ответила. Повторите чуть позже.', 'woodev-plugin-framework' ), 'failed', $card ) ],
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
