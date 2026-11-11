<?php
/**
 * Creating and collecting labels.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Jobs\Error_Classifier;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Ledger\Async_Task;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 5.5 step 5. `create()` asks the marketplace for a label task through the adapter's {@see Label_Provider} and stores the
 * task row; `collect()` is one poll of such a task and stores the body in the documents table when the marketplace has it.
 *
 * The shape of the marketplace's answer is the provider's to read. A file becomes the document with its content type; an answer
 * of another shape is stored as it came, as JSON, so nothing is lost. Whatever the marketplace answered is also kept in the task
 * row, minus the file itself.
 *
 * `{plugin id}_label_ready( document_id, task_id, account_id )` fires when a document is stored and
 * `{plugin id}_label_failed( task_id, account_id, message )` when a task ends without one.
 *
 * @since 2.1.0
 */
final class Label_Service {
	/**
	 * Result of `collect()`: the task is finished, with a document.
	 */
	public const COLLECTED = 'collected';

	/**
	 * Result of `collect()`: the marketplace is still rendering, poll again.
	 */
	public const PENDING = 'pending';

	/**
	 * Result of `collect()`: the task ended without a document.
	 */
	public const ENDED = 'ended';

	/**
	 * How long a task is polled before it is given up, in seconds (spec 4.1: every minute for at most 15 minutes).
	 */
	public const POLL_SECONDS = 900;

	/**
	 * The marketplace's label calls.
	 *
	 * @var Label_Provider
	 */
	private $provider;

	/**
	 * Store.
	 *
	 * @var Wpdb_Label_Store
	 */
	private $store;

	/**
	 * Licence gate.
	 *
	 * @var License_Gate
	 */
	private $license;

	/**
	 * Error classifier of the marketplace.
	 *
	 * @var Error_Classifier
	 */
	private $classifier;

	/**
	 * Hook names.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Creates the service.
	 *
	 * @since 2.1.0
	 *
	 * @param Label_Provider   $provider   The marketplace's label calls.
	 * @param Wpdb_Label_Store $store      Store.
	 * @param License_Gate     $license    Licence gate.
	 * @param Error_Classifier $classifier Error classifier of the marketplace.
	 * @param Job_Names        $names      Hook names of the plugin.
	 */
	public function __construct( Label_Provider $provider, Wpdb_Label_Store $store, License_Gate $license, Error_Classifier $classifier, Job_Names $names ) {
		$this->provider   = $provider;
		$this->store      = $store;
		$this->license    = $license;
		$this->classifier = $classifier;
		$this->names      = $names;
	}

	/**
	 * Returns the action that fires when a document is stored.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function ready_hook(): string {
		return $this->names->hook( 'label_ready' );
	}

	/**
	 * Returns the action that fires when a task ends without a document.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function failed_hook(): string {
		return $this->names->hook( 'label_failed' );
	}

	/**
	 * Asks the marketplace for the labels of remote orders and stores the task.
	 *
	 * The caller queues the first poll ({@see \Woodev\Framework\Marketplace\Jobs\Label_Jobs::queue_poll()}).
	 *
	 * @since 2.1.0
	 *
	 * @param Account  $account       Account the orders belong to.
	 * @param string[] $external_keys Remote-order keys.
	 * @return int Local task id.
	 * @throws Label_Exception With a merchant-readable sentence when the request cannot be made.
	 */
	public function create( Account $account, array $external_keys ): int {
		$this->require_licence();

		$keys = self::clean( $external_keys );

		if ( [] === $keys ) {
			throw new Label_Exception( __( 'Выберите хотя бы один заказ.', 'woodev-plugin-framework' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		if ( count( $keys ) > $this->provider->max_keys() ) {
			/* translators: %d: the largest number of orders for one set of labels. */
			$text = sprintf( __( 'За один раз можно распечатать этикетки не более чем для %d заказов.', 'woodev-plugin-framework' ), $this->provider->max_keys() );

			throw new Label_Exception( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$remote = $this->provider->request( $account, $keys );

		if ( '' === $remote ) {
			throw new Label_Exception( __( 'Маркетплейс не принял задание на печать этикеток. Попробуйте ещё раз через минуту.', 'woodev-plugin-framework' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $this->store->add_task( $account->id(), $remote, $keys );
	}

	/**
	 * Polls a label task once.
	 *
	 * @since 2.1.0
	 *
	 * @param Account $account Account of the task.
	 * @param int     $task_id Local task id.
	 * @return string `COLLECTED`, `PENDING` or `ENDED`; `ENDED` also for a task that is gone or finished already.
	 * @throws \Throwable When the marketplace failed in a way that may pass (the caller reschedules).
	 */
	public function collect( Account $account, int $task_id ): string {
		$task = $this->store->task( $task_id );

		if ( null === $task || $task['account_id'] !== $account->id() || $this->is_finished( $task['status'] ) ) {
			return self::ENDED;
		}

		if ( $this->store->now() - $task['submitted_at'] > self::POLL_SECONDS ) {
			return $this->end( $task, Async_Task::STATUS_EXPIRED, __( 'Маркетплейс не вернул этикетки за 15 минут. Нажмите «Этикетка» ещё раз.', 'woodev-plugin-framework' ) );
		}

		$this->store->touch_polled( $task_id );

		try {
			$answer = $this->provider->fetch( $account, $task['remote_task_id'] );
		} catch ( Label_Exception $e ) {
			return $this->end( $task, Async_Task::STATUS_FAILED, $e->getMessage() );
		} catch ( \Throwable $e ) {
			$error = $this->classifier->classify( $e );

			if ( null !== $error && $error->is( Remote_Error::FAIL ) ) {
				/* translators: %s: the marketplace's message. */
				return $this->end( $task, Async_Task::STATUS_FAILED, sprintf( __( 'Маркетплейс не нашёл задание на печать этикеток: %s', 'woodev-plugin-framework' ), $error->message() ) );
			}

			throw $e;
		}

		return $this->absorb( $task, $answer );
	}

	/**
	 * Ends an open task visibly, for the runner that gives a task up (a lasting failure, retries used up).
	 *
	 * @since 2.1.0
	 *
	 * @param int    $task_id Local task id.
	 * @param string $message What to tell the merchant.
	 * @param string $status  `Async_Task::STATUS_FAILED` or `STATUS_EXPIRED`.
	 * @return void
	 * @throws \Woodev\Framework\Marketplace\Data\Persistence_Exception When the task could not be read or written.
	 */
	public function abandon( int $task_id, string $message, string $status = Async_Task::STATUS_FAILED ): void {
		$task = $this->store->task( $task_id );

		if ( null !== $task && ! $this->is_finished( $task['status'] ) ) {
			$this->end( $task, $status, $message );
		}
	}

	/**
	 * Stores what the marketplace answered, or tells that it is not ready.
	 *
	 * @param array<string,mixed> $task   Task.
	 * @param Label_Answer        $answer Answer.
	 * @return string
	 * @throws \Throwable When the document or the end of the task could not be written (nothing is kept).
	 */
	private function absorb( array $task, Label_Answer $answer ): string {
		if ( Label_Answer::PENDING === $answer->kind() ) {
			return self::PENDING;
		}

		if ( Label_Answer::BROKEN === $answer->kind() ) {
			$text = trim( $answer->message() );

			return $this->end( $task, Async_Task::STATUS_FAILED, '' === $text ? __( 'Маркетплейс не смог подготовить этикетки.', 'woodev-plugin-framework' ) : $text, $answer->raw() );
		}

		$is_file = Label_Answer::FILE === $answer->kind();
		$type    = $is_file ? $answer->content_type() : 'application/json';
		$body    = $is_file ? $answer->body() : (string) wp_json_encode( $answer->raw() );
		$kept    = $task['result'];

		$kept[ Wpdb_Label_Store::RESULT_ANSWER ] = $answer->raw();

		// The document and the end of the task are one transaction: a poll that lost the status fence publishes nothing.
		$document_id = $this->store->publish_document( $task['task_id'], $task['account_id'], $task['result'][ Wpdb_Label_Store::RESULT_EXTERNAL_KEYS ] ?? [], $type, $body, $kept );

		if ( null === $document_id ) {
			return self::ENDED;
		}

		/**
		 * A label document is stored.
		 *
		 * @since 2.1.0
		 *
		 * @param int $document_id Document id; the download link is {@see Label_Download::url()}.
		 * @param int $task_id     Local task id.
		 * @param int $account_id  Account id.
		 */
		do_action( $this->ready_hook(), $document_id, $task['task_id'], $task['account_id'] );

		return self::COLLECTED;
	}

	/**
	 * Ends a task without a document.
	 *
	 * @param array<string,mixed> $task    Task.
	 * @param string              $status  `Async_Task::STATUS_FAILED` or `STATUS_EXPIRED`.
	 * @param string              $message Merchant-readable reason.
	 * @param array<mixed>|null   $answer  The marketplace's answer to keep.
	 * @return string `ENDED`.
	 */
	private function end( array $task, string $status, string $message, ?array $answer = null ): string {
		$kept = $task['result'];

		$kept[ Wpdb_Label_Store::RESULT_MESSAGE ] = $message;

		if ( null !== $answer ) {
			$kept[ Wpdb_Label_Store::RESULT_ANSWER ] = $answer;
		}

		if ( $this->store->finish_task( $task['task_id'], $status, $kept ) ) {
			/**
			 * A label task ended without a document.
			 *
			 * @since 2.1.0
			 *
			 * @param int    $task_id    Local task id.
			 * @param int    $account_id Account id.
			 * @param string $message    What to tell the merchant.
			 */
			do_action( $this->failed_hook(), $task['task_id'], $task['account_id'], $message );
		}

		return self::ENDED;
	}

	/**
	 * Throws the licence refusal when the licence is not in force.
	 *
	 * @return void
	 * @throws Label_Exception With the framework's refusal sentence.
	 */
	private function require_licence(): void {
		$refusal = $this->license->refusal_for( __( 'печатать этикетки', 'woodev-plugin-framework' ) );

		if ( null !== $refusal ) {
			throw new Label_Exception( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Tells whether a task status is final.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	private function is_finished( string $status ): bool {
		return ! in_array( $status, [ Async_Task::STATUS_SUBMITTED, Async_Task::STATUS_POLLING ], true );
	}

	/**
	 * Trims, drops empty and repeated remote-order keys.
	 *
	 * @param array<mixed> $keys Keys.
	 * @return string[]
	 */
	private static function clean( array $keys ): array {
		$trimmed = array_map( 'trim', array_map( 'strval', array_filter( $keys, 'is_scalar' ) ) );

		return array_values(
			array_unique(
				array_filter(
					$trimmed,
					static function ( string $key ): bool {
						return '' !== $key;
					}
				)
			)
		);
	}
}
