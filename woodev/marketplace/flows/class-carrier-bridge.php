<?php
/**
 * A delivery service's news about a seller-delivered order, passed on to the marketplace.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Carrier_Actions;
use Woodev\Framework\Marketplace\Admin\Order\Posting_Unit;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Refused;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Report;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Runner;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Unit_Result;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Data\Wpdb_Seller_Action_Store;
use Woodev\Framework\Marketplace\Jobs\Backoff;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Orders\Order_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to the two things the shipping module announces about any shipment, and answers for the marketplace's own
 * seller-delivered orders only ({@see Seller_Order::belongs_to()}), with the actions the adapter names ({@see Carrier_Actions}):
 *
 * - `woodev_shipping_order_exported` — the hand-over action;
 * - `woodev_shipping_delivery_status_changed` to «delivered» — the hand-over action first if the marketplace still waits for it,
 *   then the delivered action once, without input. An answer that asks for the buyer's confirmation code
 *   ({@see Seller_Unit_Result::code_required()}) is remembered for the account ({@see Code_Required_Accounts}): the order, and
 *   every later one, asks the merchant instead of being sent;
 * - the same to «returning» or «returned» — nothing is sent, the order asks the merchant to run the returned action.
 *
 * Nothing is sent inside the delivery service's request: the listeners queue the job `{plugin id}_carrier_action( account_id,
 * order_id, news )`. A call that was not decided (no answer, a closed connection) is tried again on the jobs' ladder
 * ({@see Backoff}), at most {@see self::MAX_ATTEMPTS} times; a refusal is never repeated. Sending goes through
 * {@see Seller_Action_Runner}, so what was already sent, by a click or an earlier run, is not sent twice.
 *
 * @since 2.1.29
 */
final class Carrier_Bridge {
	public const JOB = 'carrier_action';

	/**
	 * Most runs one piece of news gets. The ladder's last step is half an hour, so this is about ten hours.
	 */
	public const MAX_ATTEMPTS = 24;

	/**
	 * Order meta of the runs already spent, by news.
	 */
	public const META_ATTEMPTS = '_woodev_mp_carrier_attempts';

	/**
	 * The shipment state «handed to the buyer», as `woodev_shipping_delivery_status_changed` gives it. The three states are the
	 * hook's contract: this module imports nothing of the shipping module, and a test keeps them equal to its constants.
	 */
	public const STATE_DELIVERED = 'delivered';

	/**
	 * The shipment state «on its way back».
	 */
	public const STATE_RETURNING = 'returning';

	/**
	 * The shipment state «back with the sender».
	 */
	public const STATE_RETURNED = 'returned';

	/**
	 * What a send came to: no unit takes the action.
	 */
	private const NOTHING = 'nothing';

	/**
	 * Accepted now or sent earlier: the marketplace has not moved the order yet.
	 */
	private const SENT = 'sent';

	/**
	 * Nothing was decided: to be tried again.
	 */
	private const LATER = 'later';

	/**
	 * The marketplace said no: never repeated.
	 */
	private const REFUSED = 'refused';

	/**
	 * @var Carrier_Actions
	 */
	private $adapter;

	/**
	 * @var Seller_Action_Runner
	 */
	private $runner;

	/**
	 * @var Job_Gate
	 */
	private $gate;

	/**
	 * @var Order_Registry
	 */
	private $postings;

	/**
	 * @var Code_Required_Accounts
	 */
	private $code_accounts;

	/**
	 * @var Job_Names
	 */
	private $names;

	/**
	 * What the marketplace's orders are created via.
	 *
	 * @var string
	 */
	private $created_via;

	/**
	 * Merchant-facing name of the marketplace.
	 *
	 * @var string
	 */
	private $marketplace;

	/**
	 * Writes a line to the plugin's log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * @since 2.1.29
	 *
	 * @param Carrier_Actions        $adapter       The marketplace's actions.
	 * @param Seller_Action_Runner   $runner        Sends them.
	 * @param Job_Gate               $gate          Gate of licence, account and pause.
	 * @param Order_Registry         $postings      The postings registry: which account an order belongs to.
	 * @param Code_Required_Accounts $code_accounts Accounts that confirm a delivery only with the buyer's code.
	 * @param Job_Names              $names         Hook names of the plugin.
	 * @param string                 $created_via   What the marketplace's orders are created via.
	 * @param string                 $marketplace   Merchant-facing marketplace name.
	 * @param callable               $log_error     `function ( string $line ): void`.
	 */
	public function __construct( Carrier_Actions $adapter, Seller_Action_Runner $runner, Job_Gate $gate, Order_Registry $postings, Code_Required_Accounts $code_accounts, Job_Names $names, string $created_via, string $marketplace, callable $log_error ) {
		$this->adapter       = $adapter;
		$this->runner        = $runner;
		$this->gate          = $gate;
		$this->postings      = $postings;
		$this->code_accounts = $code_accounts;
		$this->names         = $names;
		$this->created_via   = $created_via;
		$this->marketplace   = $marketplace;
		$this->log_error     = $log_error;
	}

	/**
	 * Hooks the listeners and the job.
	 *
	 * @since 2.1.29
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woodev_shipping_order_exported', [ $this, 'on_exported' ] );
		add_action( 'woodev_shipping_delivery_status_changed', [ $this, 'on_status_changed' ], 10, 3 );
		add_action( $this->names->hook( self::JOB ), [ $this, 'run' ], 10, 3 );
	}

	/**
	 * A delivery service took a shipment over.
	 *
	 * @internal Hooked to `woodev_shipping_order_exported`.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order The exported order.
	 * @return void
	 */
	public function on_exported( \WC_Order $order ): void {
		$this->take( $order, Carrier_Actions::HANDED_OVER, [ Carrier_Actions::HANDED_OVER ] );
	}

	/**
	 * A delivery service reports a new state of a shipment.
	 *
	 * @internal Hooked to `woodev_shipping_delivery_status_changed`.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order   $order    The order.
	 * @param string|null $previous Previous canonical state.
	 * @param string      $state    New canonical state.
	 * @return void
	 */
	public function on_status_changed( \WC_Order $order, ?string $previous, string $state ): void {
		if ( self::STATE_DELIVERED === $state ) {
			$this->take( $order, Carrier_Actions::DELIVERED, [ Carrier_Actions::HANDED_OVER, Carrier_Actions::DELIVERED ] );
		} elseif ( self::STATE_RETURNING === $state || self::STATE_RETURNED === $state ) {
			$this->ask_about_return( $order );
		}
	}

	/**
	 * Runs `carrier_action`.
	 *
	 * @since 2.1.29
	 *
	 * @param int|string $account_id Account id (Action Scheduler passes the stored argument).
	 * @param int|string $order_id   WooCommerce order id.
	 * @param string     $news       {@see Carrier_Actions::HANDED_OVER} or {@see Carrier_Actions::DELIVERED}.
	 * @return void
	 * @throws Persistence_Exception When the next attempt cannot be queued.
	 */
	public function run( $account_id, $order_id, $news ): void {
		$account_id = (int) $account_id;
		$order_id   = (int) $order_id;
		$news       = (string) $news;

		list( $account, $wait ) = $this->gate->check( $account_id );

		if ( null === $account ) {
			return;
		}

		if ( $wait > 0 ) {
			$this->queue( $account_id, $order_id, $news, $wait );

			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || ! Seller_Order::belongs_to( $order, $this->created_via ) ) {
			return;
		}

		if ( Carrier_Actions::HANDED_OVER === $news ) {
			$this->hand_over( $account, $order );
		} elseif ( Carrier_Actions::DELIVERED === $news ) {
			$this->confirm_delivery( $account, $order );
		}
	}

	/**
	 * Queues a piece of news about an order of the marketplace. Nothing may reach the delivery service's request: a failure is
	 * logged and said on the order.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $news   The news.
	 * @param string[]  $needed The news kinds one of which must name an action, or there is nothing to do.
	 * @return void
	 */
	private function take( \WC_Order $order, string $news, array $needed ): void {
		try {
			$account_id = $this->account_of( $order );

			if ( 0 === $account_id || [] === array_filter( array_map( [ $this, 'action' ], $needed ) ) ) {
				return;
			}

			$this->forget_attempts( $order, $news );
			$this->queue( $account_id, $order->get_id(), $news );
		} catch ( \Throwable $e ) {
			$this->log( 'Queueing the delivery news of order ' . $order->get_id() . ' failed', $e );
			$this->note( $order, __( 'Не удалось запланировать передачу сведений службы доставки. Выполните действие в этом заказе вручную.', 'woodev-plugin-framework' ) );
		}
	}

	/**
	 * Asks the merchant to run the returned action. Nothing is sent: only the merchant knows what happened to the parcel.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function ask_about_return( \WC_Order $order ): void {
		try {
			$action = $this->action( Carrier_Actions::RETURNED );

			if ( null === $action || 0 === $this->account_of( $order ) ) {
				return;
			}

			/* translators: %s: name of the action, «Отказ при получении». */
			$text = sprintf( __( 'Служба доставки сообщает, что заказ возвращается отправителю. Отметьте это действием «%s».', 'woodev-plugin-framework' ), $action->label() );

			$this->ask( $order, $action, $text );
		} catch ( \Throwable $e ) {
			$this->log( 'Recording the return of order ' . $order->get_id() . ' failed', $e );
		}
	}

	/**
	 * @param Account   $account Account.
	 * @param \WC_Order $order   Order.
	 * @return void
	 * @throws Persistence_Exception When the next attempt cannot be queued.
	 */
	private function hand_over( Account $account, \WC_Order $order ): void {
		$action = $this->action( Carrier_Actions::HANDED_OVER );

		if ( null === $action ) {
			return;
		}

		$sent = $this->send( $account, $order, $action );

		if ( self::LATER === $sent['outcome'] ) {
			$this->retry( $account, $order, Carrier_Actions::HANDED_OVER, $action, $sent['reason'] );

			return;
		}

		$this->forget_attempts( $order, Carrier_Actions::HANDED_OVER );

		$this->settle( $order, $action, $sent );
	}

	/**
	 * Confirms a delivery: the marketplace must have the order handed over first, and a cabinet that asks for the buyer's code is
	 * not tried again.
	 *
	 * @param Account   $account Account.
	 * @param \WC_Order $order   Order.
	 * @return void
	 * @throws Persistence_Exception When the next attempt cannot be queued.
	 */
	private function confirm_delivery( Account $account, \WC_Order $order ): void {
		$hand_over = $this->action( Carrier_Actions::HANDED_OVER );

		if ( null !== $hand_over ) {
			$sent = $this->send( $account, $order, $hand_over );

			// Handed over just now or on its way: the delivery is confirmed once the marketplace has moved the order.
			if ( self::LATER === $sent['outcome'] || self::SENT === $sent['outcome'] ) {
				$this->retry( $account, $order, Carrier_Actions::DELIVERED, $hand_over, $sent['reason'] );

				return;
			}

			if ( self::REFUSED === $sent['outcome'] ) {
				$this->settle( $order, $hand_over, $sent );
			}
		}

		$action = $this->action( Carrier_Actions::DELIVERED );

		if ( null === $action || [] === $this->takers( $order, $action ) ) {
			$this->forget_attempts( $order, Carrier_Actions::DELIVERED );

			return;
		}

		if ( $this->code_accounts->has( $account->id() ) ) {
			$this->forget_attempts( $order, Carrier_Actions::DELIVERED );
			$this->ask_for_code( $order, $action );

			return;
		}

		$sent = $this->send( $account, $order, $action );

		if ( $sent['asks_code'] ) {
			$this->code_accounts->remember( $account->id() );
			$this->forget_attempts( $order, Carrier_Actions::DELIVERED );
			$this->ask_for_code( $order, $action );

			return;
		}

		if ( self::LATER === $sent['outcome'] ) {
			$this->retry( $account, $order, Carrier_Actions::DELIVERED, $action, $sent['reason'] );

			return;
		}

		$this->forget_attempts( $order, Carrier_Actions::DELIVERED );
		$this->settle( $order, $action, $sent );
	}

	/**
	 * Leaves on the order what a finished send came to: why the merchant has to act, or nothing. A refusal is already in the
	 * order's notes.
	 *
	 * @param \WC_Order                                          $order  Order.
	 * @param Seller_Action                                      $action Action.
	 * @param array{outcome:string,reason:string,asks_code:bool} $sent   What the send came to.
	 * @return void
	 */
	private function settle( \WC_Order $order, Seller_Action $action, array $sent ): void {
		if ( self::REFUSED === $sent['outcome'] ) {
			Carrier_Notice::put( $order, $action->id(), self::refusal_text( $action, $sent['reason'] ) );
		} else {
			Carrier_Notice::clear( $order, $action->id() );
		}
	}

	/**
	 * Sends an action to every unit of an order that takes it.
	 *
	 * @param Account       $account Account.
	 * @param \WC_Order     $order   Order.
	 * @param Seller_Action $action  Action.
	 * @return array{outcome:string,reason:string,asks_code:bool}
	 */
	private function send( Account $account, \WC_Order $order, Seller_Action $action ): array {
		try {
			$takers = $this->takers( $order, $action );

			if ( [] === $takers ) {
				return self::outcome( self::NOTHING );
			}

			$report = $this->runner->run( $account, $action, [ $order ], [] );
			$answer = self::read( $report );

			if ( null !== $answer ) {
				return $answer;
			}

			// Every unit was left out by the marketplace's own rule, so nothing was sent and nothing says it on the order yet.
			$reason = $this->runner->unit_reason( $account, $action, $order, $takers[0] );

			$this->note( $order, self::refusal_text( $action, $reason ) );

			return self::outcome( self::REFUSED, $reason );
		} catch ( Seller_Action_Refused $e ) {
			return self::outcome( self::LATER, $e->getMessage() );
		} catch ( \Throwable $e ) {
			$this->log( 'Sending the seller action ' . $action->id() . ' for a delivery failed', $e );

			return self::outcome( self::LATER );
		}
	}

	/**
	 * Reads what a run did. A refused unit wins over everything: sending again would repeat the refusal.
	 *
	 * @param Seller_Action_Report $report Report.
	 * @return array{outcome:string,reason:string,asks_code:bool}|null Null when no unit was sent, now or earlier.
	 */
	private static function read( Seller_Action_Report $report ): ?array {
		if ( [] !== $report->failed() ) {
			$reasons = [];
			$asks    = false;

			foreach ( $report->failed() as $result ) {
				$reasons[] = $result->reason();
				$asks      = $asks || $result->asks_code();
			}

			return self::outcome( self::REFUSED, implode( ' ', array_unique( $reasons ) ), $asks );
		}

		$sent = [] !== $report->done();

		foreach ( $report->skipped() as $skip ) {
			// A claim left by an earlier send: accepted («sent»), or still undecided.
			if ( ! isset( $skip['state'] ) ) {
				continue;
			}

			if ( Wpdb_Seller_Action_Store::SENT !== $skip['state'] ) {
				return self::outcome( self::LATER, $skip['reason'] );
			}

			$sent = true;
		}

		return $sent ? self::outcome( self::SENT ) : null;
	}

	/**
	 * @param \WC_Order     $order  Order.
	 * @param Seller_Action $action Action.
	 * @return Posting_Unit[] The units of the order the action exists for and whose status offers it.
	 */
	private function takers( \WC_Order $order, Seller_Action $action ): array {
		return array_values(
			array_filter(
				$this->runner->units( $order ),
				static function ( Posting_Unit $unit ) use ( $action ): bool {
					return $unit->has_action( $action->id() ) && $action->is_offered_in( $unit->status() );
				}
			)
		);
	}

	/**
	 * Queues the next run, or gives up after {@see self::MAX_ATTEMPTS} and leaves the action to the merchant.
	 *
	 * @param Account       $account Account.
	 * @param \WC_Order     $order   Order.
	 * @param string        $news    The news being handled.
	 * @param Seller_Action $action  The action that did not go through.
	 * @param string        $reason  Why, when something says it.
	 * @return void
	 * @throws Persistence_Exception When the next attempt cannot be queued.
	 */
	private function retry( Account $account, \WC_Order $order, string $news, Seller_Action $action, string $reason ): void {
		$attempts = $this->attempts( $order );
		$spent    = ( $attempts[ $news ] ?? 0 ) + 1;

		if ( $spent >= self::MAX_ATTEMPTS ) {
			$this->forget_attempts( $order, $news );

			/* translators: 1: name of the action, 2: number of attempts. */
			$text = sprintf( __( 'Не удалось выполнить действие «%1$s» после %2$d попыток. Выполните его вручную.', 'woodev-plugin-framework' ), $action->label(), $spent );

			$this->ask( $order, $action, trim( $text . ' ' . $reason ) );

			return;
		}

		$attempts[ $news ] = $spent;

		$order->update_meta_data( self::META_ATTEMPTS, $attempts );
		$order->save_meta_data();

		$this->queue( $account->id(), $order->get_id(), $news, Backoff::seconds_after( $spent ) );
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return array<string,int> Runs spent, by news.
	 */
	private function attempts( \WC_Order $order ): array {
		$stored = $order->get_meta( self::META_ATTEMPTS );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * @param \WC_Order $order Order.
	 * @param string    $news  News whose count starts over.
	 * @return void
	 */
	private function forget_attempts( \WC_Order $order, string $news ): void {
		$attempts = $this->attempts( $order );

		if ( ! isset( $attempts[ $news ] ) ) {
			return;
		}

		unset( $attempts[ $news ] );

		if ( [] === $attempts ) {
			$order->delete_meta_data( self::META_ATTEMPTS );
		} else {
			$order->update_meta_data( self::META_ATTEMPTS, $attempts );
		}

		$order->save_meta_data();
	}

	/**
	 * Queues a run unless the same one is already pending.
	 *
	 * @param int    $account_id Account id: the first argument, so the account's deletion takes the job off the queue.
	 * @param int    $order_id   Order id.
	 * @param string $news       News.
	 * @param int    $delay      Seconds from now.
	 * @return void
	 * @throws Persistence_Exception When Action Scheduler could not store the action.
	 */
	private function queue( int $account_id, int $order_id, string $news, int $delay = 0 ): void {
		$hook    = $this->names->hook( self::JOB );
		$args    = [ $account_id, $order_id, $news ];
		$pending = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $this->names->group(),
				'status'   => 'pending',
				'per_page' => 1,
			],
			'ids'
		);

		if ( is_array( $pending ) && [] !== $pending ) {
			return;
		}

		if ( 0 === (int) as_schedule_single_action( time() + max( 0, $delay ), $hook, $args, $this->names->group() ) ) {
			throw Persistence_Exception::queue( 'the delivery news of order ' . $order_id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Asks the merchant for the buyer's confirmation code.
	 *
	 * @param \WC_Order     $order  Order.
	 * @param Seller_Action $action The delivered action.
	 * @return void
	 */
	private function ask_for_code( \WC_Order $order, Seller_Action $action ): void {
		$this->ask( $order, $action, __( 'Подтвердите вручение: нужен код покупателя.', 'woodev-plugin-framework' ) );
	}

	/**
	 * Puts a notice on the order, with a note when it says something new.
	 *
	 * @param \WC_Order     $order  Order.
	 * @param Seller_Action $action The action that settles it.
	 * @param string        $text   Sentence.
	 * @return void
	 */
	private function ask( \WC_Order $order, Seller_Action $action, string $text ): void {
		if ( Carrier_Notice::put( $order, $action->id(), $text ) ) {
			$this->note( $order, $text );
		}
	}

	/**
	 * @param \WC_Order $order Order.
	 * @param string    $text  What happened.
	 * @return void
	 */
	private function note( \WC_Order $order, string $text ): void {
		$order->add_order_note( $this->marketplace . ': ' . $text );
	}

	/**
	 * @param string $news One of the constants of {@see Carrier_Actions}.
	 * @return Seller_Action|null The declared action the adapter names for it.
	 */
	private function action( string $news ): ?Seller_Action {
		$id = (string) ( $this->adapter->carrier_actions()[ $news ] ?? '' );

		return '' === $id ? null : $this->runner->action( $id );
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return int The account of an order the seller delivers on this marketplace; 0 for any other order.
	 */
	private function account_of( \WC_Order $order ): int {
		if ( ! Seller_Order::belongs_to( $order, $this->created_via ) ) {
			return 0;
		}

		$posting = $this->postings->posting_of_order( $order->get_id() );

		return null === $posting ? 0 : $posting['account_id'];
	}

	/**
	 * @param string     $what What was being done.
	 * @param \Throwable $e    Failure.
	 * @return void
	 */
	private function log( string $what, \Throwable $e ): void {
		call_user_func( $this->log_error, sprintf( '%s: %s: %s', $what, get_class( $e ), $e->getMessage() ) );
	}

	/**
	 * @param Seller_Action $action Action.
	 * @param string        $reason Why it did not go through.
	 * @return string
	 */
	private static function refusal_text( Seller_Action $action, string $reason ): string {
		/* translators: 1: name of the action, 2: the reason. */
		return sprintf( __( 'Не удалось выполнить действие «%1$s»: %2$s', 'woodev-plugin-framework' ), $action->label(), $reason );
	}

	/**
	 * @param string $outcome   What a send came to.
	 * @param string $reason    Why.
	 * @param bool   $asks_code Whether the buyer's code was asked for.
	 * @return array{outcome:string,reason:string,asks_code:bool}
	 */
	private static function outcome( string $outcome, string $reason = '', bool $asks_code = false ): array {
		return [
			'outcome'   => $outcome,
			'reason'    => $reason,
			'asks_code' => $asks_code,
		];
	}
}
