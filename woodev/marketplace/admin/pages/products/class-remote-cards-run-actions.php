<?php
/**
 * «Импортировать все по фильтру» and the buttons of its account line.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Bulk_Run;
use Woodev\Framework\Marketplace\Data\Bulk_Run_Store;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Runner;
use Woodev\Framework\Marketplace\Jobs\License_Gate;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the «Карточки на маркетплейсе» table does with a bulk run (#1796): the preview and the start of the import of every card
 * under the filter, «Остановить» and «Подробнее» of the account line, and the line itself. Nothing is imported in the request: the
 * apply stores a run and queues its first tick ({@see Bulk_Run_Runner}); the cards are frozen and fed to the import job there.
 *
 * The run belongs to the account, not to the table: «Остановить» and «Подробнее» act on whichever run the line shows, and a run that
 * the «Товары сайта» table started blocks this import (and the other way round) with the same sentence.
 *
 * @since 2.1.10
 */
final class Remote_Cards_Run_Actions {
	/**
	 * @var Bulk_Run_Runner
	 */
	private $runner;

	/**
	 * @var Bulk_Run_Store
	 */
	private $runs;

	/**
	 * @var Remote_Cards_Matching
	 */
	private $matching;

	/**
	 * @var License_Gate
	 */
	private $license;

	/**
	 * Creates the actions.
	 *
	 * @since 2.1.10
	 *
	 * @param Bulk_Run_Runner       $runner   The runs' lifecycle.
	 * @param Bulk_Run_Store        $runs     Their store.
	 * @param Remote_Cards_Matching $matching The cards a filter reaches.
	 * @param License_Gate          $license  Licence gate.
	 */
	public function __construct( Bulk_Run_Runner $runner, Bulk_Run_Store $runs, Remote_Cards_Matching $matching, License_Gate $license ) {
		$this->runner   = $runner;
		$this->runs     = $runs;
		$this->matching = $matching;
		$this->license  = $license;
	}

	/**
	 * Whether the action is a button of the account line.
	 *
	 * @since 2.1.10
	 *
	 * @param string $action Action id.
	 * @return bool
	 */
	public static function is_line_action( string $action ): bool {
		return in_array( $action, [ Site_Products_Bulk::RUN_STOP, Site_Products_Bulk::RUN_REPORT ], true );
	}

	/**
	 * The account line of the account's bulk run, with its buttons; null when there is nothing to show. A run that is going and has no
	 * tick waiting gets one queued here: the read is made every few seconds while the page is open.
	 *
	 * @since 2.1.10
	 *
	 * @param int $account_id Account.
	 * @param int $now        Unix time.
	 * @return array{active:bool,text:string,actions:array<int,array{action:string,label:string,payload:array<string,string>}>}|null
	 */
	public function line( int $account_id, int $now ): ?array {
		$run = $this->runs->find( $account_id );

		if ( null !== $run && $run->is_running() ) {
			$this->runner->recover( $account_id );
		}

		return Site_Products_Run_Line::line( $run, $now );
	}

	/**
	 * Whether the account's bulk run is this import, still going: its own line already says that cards are being imported.
	 *
	 * @since 2.1.10
	 *
	 * @param int $account_id Account.
	 * @return bool
	 */
	public function is_feeding( int $account_id ): bool {
		$run = $this->runs->find( $account_id );

		return null !== $run && $run->is_running() && Remote_Cards_Run_Line::is_import( $run );
	}

	/**
	 * The plan of the import over every card under the filter: one count and a sentence. Nothing is queued.
	 *
	 * @since 2.1.10
	 *
	 * @param Account                                          $account Account.
	 * @param array{search:string,filters:array<string,mixed>} $query   Validated filter and search.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the import may not start: licence, a run going, the near-match view, a stale list, no card.
	 */
	public function preview_matching( Account $account, array $query ): array {
		$total = $this->startable( $account, $query );

		return [
			'requested'      => $total,
			'eligible'       => $total,
			'skipped'        => 0,
			'scope_sentence' => Remote_Cards_Run_Line::preview( $total ),
		];
	}

	/**
	 * Starts the import of every card under the filter and queues the run's first tick.
	 *
	 * @since 2.1.10
	 *
	 * @param Account                                          $account Account.
	 * @param array{search:string,filters:array<string,mixed>} $query   Validated filter and search.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the import may not start, or the queue refuses the run.
	 */
	public function apply_matching( Account $account, array $query ): array {
		$total = $this->startable( $account, $query );

		try {
			$run_id = $this->runner->start( $account->id(), Remote_Cards_Definition::IMPORT_SELECTED, [], $query, get_current_user_id(), $total );
		} catch ( \RuntimeException $e ) {
			throw new Action_State_Exception( __( 'Не удалось поставить импорт в очередь. Проверьте, что в WooCommerce работает планировщик задач (Action Scheduler), и повторите.', 'woodev-plugin-framework' ) );
		}

		if ( null === $run_id ) {
			throw new Action_State_Exception( $this->running_sentence( $account->id() ) );
		}

		$text = sprintf(
			/* translators: %s: «12 345 карточек». */
			__( 'Импорт запущен в фоне: %s. Ход работы — в строке аккаунта над таблицей.', 'woodev-plugin-framework' ),
			Remote_Cards_Run_Line::cards( $total )
		);

		return [
			'requested' => $total,
			'accepted'  => $total,
			'skipped'   => 0,
			'failed'    => 0,
			'queued'    => true,
			'run'       => [
				'id'             => $run_id,
				'products_total' => $total,
			],
			'messages'  => [
				'action_status_done'    => $text,
				'action_status_partial' => $text,
				'action_status_queued'  => $text,
			],
		];
	}

	/**
	 * What a button of the account line does, before it does it.
	 *
	 * @since 2.1.10
	 *
	 * @param Account             $account Account.
	 * @param string              $action  {@see Site_Products_Bulk::RUN_STOP} or {@see Site_Products_Bulk::RUN_REPORT}.
	 * @param array<string,mixed> $payload Validated fields: the id of the run the button belongs to.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When that run is not the account's current one any more.
	 */
	public function preview_line_action( Account $account, string $action, array $payload ): array {
		$run = $this->shown_run( $account, $payload );

		if ( Site_Products_Bulk::RUN_STOP === $action ) {
			return [
				'requested' => 1,
				'eligible'  => 1,
				'skipped'   => 0,
			];
		}

		$counts = $run->counts();

		return [
			'requested'      => $counts['offers_accepted'] + $counts['offers_skipped'] + $counts['offers_failed'],
			'eligible'       => $counts['offers_accepted'],
			'skipped'        => $counts['offers_skipped'] + $counts['offers_failed'],
			'scope_sentence' => $run->is_running() ? Site_Products_Run_Line::running( $run ) : Site_Products_Run_Line::finished( $run ),
			'groups'         => $run->report(),
			'can_apply'      => false,
		];
	}

	/**
	 * «Остановить»: stops the run the merchant saw. A run id that is not the current running run changes nothing — a second tab must
	 * never stop a newer run.
	 *
	 * @since 2.1.10
	 *
	 * @param Account             $account Account.
	 * @param array<string,mixed> $payload Validated fields: the id of the run the button belongs to.
	 * @return array<string,mixed>
	 */
	public function apply_stop( Account $account, array $payload ): array {
		$outcome = $this->runner->stop( $account->id(), (string) ( $payload[ Site_Products_Bulk::RUN_FIELD ] ?? '' ) );

		if ( Bulk_Run_Runner::STOP_GONE === $outcome ) {
			return [
				'requested' => 1,
				'accepted'  => 0,
				'skipped'   => 1,
				'failed'    => 0,
				'messages'  => [ 'action_status_failed' => __( 'Это действие уже закончилось — новое действие не остановлено.', 'woodev-plugin-framework' ) ],
			];
		}

		$text = Bulk_Run_Runner::STOP_CANCELLED === $outcome
			? __( 'Действие остановлено.', 'woodev-plugin-framework' )
			: __( 'Обработка остановится после текущей части. То, что уже поставлено в очередь, будет обработано.', 'woodev-plugin-framework' );

		return [
			'requested' => 1,
			'accepted'  => 1,
			'skipped'   => 0,
			'failed'    => 0,
			'messages'  => [
				'action_status_done'    => $text,
				'action_status_partial' => $text,
				'action_status_queued'  => $text,
			],
		];
	}

	/**
	 * Checks that the import may start and counts the cards it would reach.
	 *
	 * @param Account                                          $account Account.
	 * @param array{search:string,filters:array<string,mixed>} $query   Validated filter and search.
	 * @return int Cards under the filter, at least one.
	 * @throws Action_State_Exception When it may not start.
	 */
	private function startable( Account $account, array $query ): int {
		$refusal = $this->license->refusal_for( __( 'импортировать карточки с маркетплейса', 'woodev-plugin-framework' ) );

		if ( null !== $refusal ) {
			throw new Action_State_Exception( $refusal );
		}

		if ( Remote_Cards_Matching::is_near_report( $query ) ) {
			throw new Action_State_Exception( __( 'Похожие артикулы не импортируются все сразу: по каждой паре решение принимаете вы. Выберите вид «Карточки» и повторите.', 'woodev-plugin-framework' ) );
		}

		$current = $this->runs->find( $account->id() );

		if ( null !== $current && $current->is_running() ) {
			throw new Action_State_Exception( $this->running_sentence( $account->id() ) );
		}

		$total = $this->matching->count( $account->id(), $query );

		if ( null === $total ) {
			throw new Action_State_Exception( __( 'Список карточек устарел — нажмите «Обновить список» и повторите.', 'woodev-plugin-framework' ) );
		}

		if ( 0 === $total ) {
			throw new Action_State_Exception( __( 'По этому фильтру карточек нет.', 'woodev-plugin-framework' ) );
		}

		return $total;
	}

	/**
	 * «Уже выполняется «Импортировать» по фильтру — дождитесь окончания или остановите его».
	 *
	 * @param int $account_id Account.
	 * @return string
	 */
	private function running_sentence( int $account_id ): string {
		$run = $this->runs->find( $account_id );

		/* translators: %s: the action's name. */
		return sprintf( __( 'Уже выполняется «%s» по фильтру — дождитесь окончания или остановите его.', 'woodev-plugin-framework' ), null === $run ? '' : Remote_Cards_Run_Line::label_of( $run->action() ) );
	}

	/**
	 * The run a button of the account line belongs to, when it is still the account's current one.
	 *
	 * @param Account             $account Account.
	 * @param array<string,mixed> $payload Validated fields.
	 * @return Bulk_Run
	 * @throws Action_State_Exception When the account has no such run any more.
	 */
	private function shown_run( Account $account, array $payload ): Bulk_Run {
		$run = $this->runs->find( $account->id() );

		if ( null === $run || $run->run_id() !== (string) ( $payload[ Site_Products_Bulk::RUN_FIELD ] ?? '' ) ) {
			throw new Action_State_Exception( __( 'Это действие уже закончилось или заменено новым — смотрите строку аккаунта над таблицей.', 'woodev-plugin-framework' ) );
		}

		return $run;
	}
}
