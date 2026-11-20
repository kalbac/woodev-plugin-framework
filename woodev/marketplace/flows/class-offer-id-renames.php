<?php
/**
 * Changing the article of a card after the export.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\Rename_Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Both halves of the change, so the one rule is written once:
 *
 * - **At save time** ({@see self::request()}): the merchant typed another article for a card the marketplace knows. The article is checked against the
 *   account (no other product holds it or waits for it), written to the link as a *waiting* change and the `rename_offer_ids` job is queued. The
 *   link keeps its old article and nothing is sent: a save never waits for the marketplace.
 * - **In the job** ({@see self::send()}): the waiting changes of an account go to the adapter ({@see Offer_Id_Renamer}) in batches. A change the
 *   marketplace confirmed moves the link's article (the unique `(account, article)` index decides a clash) and the chosen article kept on the product, in
 *   one step; a change it refused leaves the link on the old article and keeps the marketplace's answer for the product's problems.
 *
 * It sends only what the rows ask for, never an article derived from the SKU: a changed SKU of a sent card does not rename it.
 *
 * @since 2.1.13
 */
final class Offer_Id_Renames {
	/**
	 * Most batches one job run sends before it hands the rest to the next run.
	 */
	public const MAX_BATCHES = 20;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Scheduler of the job.
	 *
	 * @var Rename_Scheduler
	 */
	private $scheduler;

	/**
	 * Moves the article the merchant chose for a product: `function ( Link_Record $link, string $offer_id ): void`.
	 *
	 * @var callable
	 */
	private $move_choice;

	/**
	 * Writes a failure to the plugin's error log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * Writes a success to the plugin's debug log, which records it only while logging is on: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_debug;

	/**
	 * Rows this run already looked at (link id => true), so a row whose local move keeps failing is not retried again within one run.
	 *
	 * @var array<int,true>
	 */
	private $seen = [];

	/**
	 * Seconds the job waits before it retries a local move that the database refused, or looks again at a card that is being exported.
	 */
	public const RETRY_AFTER = 300;
	public const UNREADY_WAIT = 120;

	/**
	 * Creates the service.
	 *
	 * @since 2.1.13
	 *
	 * @param Wpdb_Link_Store  $links       Links.
	 * @param Rename_Scheduler $scheduler   Scheduler of the job.
	 * @param callable         $move_choice `function ( Link_Record $link, string $offer_id ): void`, keeps the article the merchant chose for the product in step
	 *                                      with the link ({@see \Woodev\Framework\Marketplace\Data\Pinned_Offer_Id}).
	 * @param callable         $log_error   `function ( string $line ): void`, for a failure.
	 * @param callable         $log_debug   `function ( string $line ): void`, for a success; recorded only while logging is on.
	 */
	public function __construct( Wpdb_Link_Store $links, Rename_Scheduler $scheduler, callable $move_choice, callable $log_error, callable $log_debug ) {
		$this->links       = $links;
		$this->scheduler   = $scheduler;
		$this->move_choice = $move_choice;
		$this->log_error   = $log_error;
		$this->log_debug   = $log_debug;
	}

	/**
	 * Builds the service the product screen and the job share: the choice kept on the product follows the link ({@see Pinned_Offer_Id}), but only for
	 * a product that has one, so a rename never invents a choice.
	 *
	 * @since 2.1.13
	 *
	 * @param Wpdb_Link_Store $links  Links.
	 * @param Job_Names       $names  Job names of the plugin.
	 * @param Pinned_Offer_Id $pinned The chosen articles.
	 * @param callable        $log_error `function ( string $line ): void`, for a failure.
	 * @param callable        $log_debug `function ( string $line ): void`, for a success; recorded only while logging is on.
	 * @return self
	 */
	public static function for_plugin( Wpdb_Link_Store $links, Job_Names $names, Pinned_Offer_Id $pinned, callable $log_error, callable $log_debug ): self {
		return new self(
			$links,
			new Rename_Scheduler( $names ),
			static function ( Link_Record $link, string $offer_id ) use ( $pinned ): void {
				$product = wc_get_product( $link->wc_product_id() );

				if ( $product instanceof \WC_Product && null !== $pinned->of( $product, $link->account_id() ) ) {
					$pinned->store( $product, $link->account_id(), $offer_id );
				}
			},
			$log_error,
			$log_debug
		);
	}

	/**
	 * Records the merchant's wish for another article of a card the marketplace knows, and queues the job.
	 *
	 * Typing the article the card already carries takes a waiting or refused change back; typing the one a change is already waiting for sends nothing again,
	 * unless the marketplace refused it (then it is asked again).
	 *
	 * @since 2.1.13
	 *
	 * @param Account     $account Account.
	 * @param Link_Record $row     The product's offer row.
	 * @param string      $offer_id The article typed, already checked against the article policy.
	 * @return string|null Sentence for the merchant when it cannot be done; null when it is saved.
	 * @throws \RuntimeException When the database refuses a write.
	 */
	public function request( Account $account, Link_Record $row, string $offer_id ): ?string {
		$link_id = (int) $row->link_id();

		if ( ! $row->can_rename_offer_id() ) {
			return __( 'Артикул можно изменить, когда карточка уже создана на маркетплейсе. Пока она создаётся, подождите.', 'woodev-plugin-framework' );
		}

		if ( $offer_id === $row->offer_id() ) {
			if ( null !== $row->pending_offer_id() ) {
				$this->links->clear_offer_id_rename( $link_id );
			}

			return null;
		}

		if ( $offer_id === $row->pending_offer_id() && ( null === $row->rename_error() || $row->rename_confirmed() ) ) {
			return $this->queue( $account );
		}

		if ( $this->links->offer_id_is_taken( $account->id(), $offer_id, $link_id ) ) {
			return sprintf(
				/* translators: %s: article. */
				__( 'Артикул «%s» уже используется у другого товара этого аккаунта. Выберите другой.', 'woodev-plugin-framework' ),
				$offer_id
			);
		}

		if ( ! $this->links->request_offer_id_rename( $account->id(), $link_id, $offer_id ) ) {
			return __( 'Не удалось изменить артикул: карточка товара изменилась. Обновите страницу и повторите.', 'woodev-plugin-framework' );
		}

		return $this->queue( $account );
	}

	/**
	 * Sends one batch of the account's waiting changes and settles each row.
	 *
	 * @since 2.1.13
	 *
	 * @param Account          $account Account.
	 * @param Offer_Id_Renamer $renamer The adapter's part.
	 * @param string           $context Name of the calling job.
	 * @return int Rows settled; 0 when nothing waits.
	 * @throws \Throwable When the request fails as a whole.
	 */
	public function send( Account $account, Offer_Id_Renamer $renamer, string $context ): int {
		$waiting = array_values(
			array_filter(
				$this->links->pending_offer_id_renames( $account->id(), max( 1, $renamer->max_renames_per_request() ) ),
				function ( Link_Record $row ): bool {
					return ! isset( $this->seen[ (int) $row->link_id() ] );
				}
			)
		);
		$pairs   = [];
		$rows    = [];

		foreach ( $waiting as $row ) {
			$old = (string) $row->offer_id();
			$new = (string) $row->pending_offer_id();

			$this->seen[ (int) $row->link_id() ] = true;

			if ( $row->rename_confirmed() ) {
				// The marketplace already changed this card: only the shop's own move is left, and the request is not sent again.
				$this->settle( $account, $row, $new, Offer_Id_Rename_Outcome::renamed() );
				continue;
			}

			if ( '' === $new || $new === $old || isset( $pairs[ $old ] ) || in_array( $new, $pairs, true ) ) {
				$this->links->fail_offer_id_rename( (int) $row->link_id(), $new, __( 'Артикул уже занят другим изменением. Выберите другой.', 'woodev-plugin-framework' ) );
				continue;
			}

			$pairs[ $old ] = $new;
			$rows[ $old ]  = $row;
		}

		if ( [] !== $pairs ) {
			$answers = $renamer->rename_offer_ids( $account, $pairs, $context );

			foreach ( $rows as $old => $row ) {
				$this->settle( $account, $row, $pairs[ $old ], $answers[ $old ] ?? null );
			}
		}

		return count( $waiting );
	}

	/**
	 * Puts every change of the account that waits and was not refused into the failed state, when the job stops for good. The merchant sees the
	 * reason on the product and saving the article again asks again.
	 *
	 * @since 2.1.13
	 *
	 * @param int    $account_id Account id.
	 * @param string $text       What went wrong, as the merchant should read it.
	 * @return void
	 */
	public function fail_waiting( int $account_id, string $text ): void {
		$this->links->fail_waiting_offer_id_renames( $account_id, $text );
	}

	/**
	 * Deals with the changes whose card is not renamable right now: while an update of the card is exported or polled they keep waiting (the job
	 * looks again later), a card the marketplace refused cannot be renamed and the change says so.
	 *
	 * @since 2.1.13
	 *
	 * @param Account $account Account.
	 * @return bool True when some change still waits for its card, so the job must come back.
	 */
	public function settle_unready( Account $account ): bool {
		$again = false;

		foreach ( $this->links->waiting_offer_id_renames_not_ready( $account->id() ) as $row ) {
			if ( in_array( $row->card_state(), [ Link_Record::STATE_QUEUED, Link_Record::STATE_SUBMITTED ], true ) ) {
				$again = true;
				continue;
			}

			$this->links->fail_offer_id_rename( (int) $row->link_id(), (string) $row->pending_offer_id(), __( 'Карточка сейчас не принята маркетплейсом, поэтому сменить артикул нельзя. Исправьте карточку и сохраните артикул ещё раз.', 'woodev-plugin-framework' ) );
		}

		return $again;
	}

	/**
	 * Queues the job, or says why it could not be.
	 *
	 * @param Account $account Account.
	 * @return string|null
	 */
	private function queue( Account $account ): ?string {
		try {
			$this->scheduler->queue( $account->id() );
		} catch ( \RuntimeException $e ) {
			call_user_func( $this->log_error, 'rename_offer_ids: account ' . $account->id() . ': ' . $e->getMessage() );

			return __( 'Артикул сохранён, но отправить его не удалось: не работает очередь задач. Сохраните товар ещё раз чуть позже.', 'woodev-plugin-framework' );
		}

		return null;
	}

	/**
	 * Schedules the job again for a local move that failed.
	 *
	 * @param Account $account Account.
	 * @return void
	 */
	private function retry_move( Account $account ): void {
		try {
			$this->scheduler->again( $account->id(), self::RETRY_AFTER, 0, 0 );
		} catch ( \RuntimeException $e ) {
			call_user_func( $this->log_error, 'rename_offer_ids: account ' . $account->id() . ': the retry was not scheduled: ' . $e->getMessage() );
		}
	}

	/**
	 * Applies the answer for one row.
	 *
	 * @param Account                      $account Account.
	 * @param Link_Record                  $row     The row as it was sent.
	 * @param string                       $wanted  The article that was asked for.
	 * @param Offer_Id_Rename_Outcome|null $answer  The adapter's answer; null when it gave none.
	 * @return void
	 */
	private function settle( Account $account, Link_Record $row, string $wanted, ?Offer_Id_Rename_Outcome $answer ): void {
		$link_id = (int) $row->link_id();
		$old     = (string) $row->offer_id();

		if ( null === $answer || ! $answer->is_renamed() ) {
			$text = null === $answer || '' === $answer->message() ? __( 'Маркетплейс не подтвердил смену артикула.', 'woodev-plugin-framework' ) : $answer->message();
			$this->links->fail_offer_id_rename( $link_id, $wanted, $text );
			call_user_func( $this->log_error, sprintf( 'rename_offer_ids: account %d: article %s was not changed to %s: %s', $account->id(), $old, $wanted, $text ) );

			return;
		}

		$moved = $this->links->finish_offer_id_rename( $link_id, $old, $wanted );

		if ( 'done' === $moved ) {
			call_user_func( $this->move_choice, $row, $wanted );
			call_user_func( $this->log_debug, sprintf( 'rename_offer_ids: account %d: article %s changed to %s (product %d)', $account->id(), $old, $wanted, $row->wc_product_id() ) );

			return;
		}

		if ( 'taken' === $moved ) {
			// The marketplace changed the card, but another row of the shop took the new article meanwhile: the shop must not lose track of it.
			$text = sprintf(
				/* translators: %s: article. */
				__( 'Маркетплейс сменил артикул на «%s», но на сайте он уже занят другим товаром. Освободите артикул и обновите связь с карточкой.', 'woodev-plugin-framework' ),
				$wanted
			);
			$this->links->fail_offer_id_rename( $link_id, $wanted, $text );
			call_user_func( $this->log_error, sprintf( 'rename_offer_ids: account %d: article %s was changed to %s on the marketplace but the shop already holds %s', $account->id(), $old, $wanted, $wanted ) );

			return;
		}

		if ( 'error' === $moved ) {
			// The marketplace changed the card but the shop's own write failed: keep the destination, never send the request again, show it, retry the move.
			$this->links->confirm_offer_id_rename(
				$link_id,
				$wanted,
				sprintf(
					/* translators: %s: article. */
					__( 'Маркетплейс уже сменил артикул на «%s», но сайт не смог это записать. Запись будет повторена; если ошибка остаётся, сохраните товар ещё раз.', 'woodev-plugin-framework' ),
					$wanted
				)
			);
			call_user_func( $this->log_error, sprintf( 'rename_offer_ids: account %d: article %s was changed to %s on the marketplace but the shop could not write it; the move will be retried', $account->id(), $old, $wanted ) );
			$this->retry_move( $account );

			return;
		}

		// `gone`: the link was detached or deleted while the request was on its way; there is nothing left to move. The marketplace did change the card.
		$this->links->clear_offer_id_rename( $link_id );
		call_user_func( $this->log_error, sprintf( 'rename_offer_ids: account %d: article %s was changed to %s on the marketplace, the link had gone', $account->id(), $old, $wanted ) );
	}
}
