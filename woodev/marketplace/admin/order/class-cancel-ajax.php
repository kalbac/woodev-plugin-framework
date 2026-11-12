<?php
/**
 * The admin-ajax side of cancelling a posting from an order.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Admin\Guard\Admin_Guard;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Flows\Order_Meta_Contract;
use Woodev\Framework\Marketplace\Jobs\License_Gate;

defined( 'ABSPATH' ) || exit;

/**
 * Two `wp_ajax_` actions for the order screens: the reasons for the modal, and the cancellation.
 *
 * Every request must pass, in this order: the nonce of this order together with the capability `edit_shop_orders`
 * ({@see Admin_Guard}), then the ownership check — the order carries the owner stamp of an account of this plugin
 * ({@see Order_Meta_Contract::order_owner()}) and a posting number. An order that is not a marketplace order, or whose account is
 * gone, is answered 404 and the marketplace is never asked. The licence gate runs here, before either remote call; the reason
 * check lives in the {@see Order_Canceller}. Any other failure of the adapter is answered 502 and logged.
 *
 * The WooCommerce order is not changed here beyond a note: the posting is queued for a refresh, and the order writer moves the order
 * to `cancelled` (restoring stock through WooCommerce) once the marketplace reports it. It works the same on both order storages:
 * it goes through `wc_get_order()` only.
 *
 * @since 2.1.2
 */
final class Cancel_Ajax {
	public const CAPABILITY = 'edit_shop_orders';

	/**
	 * Authorisation.
	 *
	 * @var Admin_Guard
	 */
	private $guard;

	/**
	 * Accounts.
	 *
	 * @var Account_Store
	 */
	private $accounts;

	/**
	 * Which account owns an order.
	 *
	 * @var Order_Meta_Contract
	 */
	private $meta;

	/**
	 * The marketplace's cancellation.
	 *
	 * @var Order_Canceller
	 */
	private $canceller;

	/**
	 * Names.
	 *
	 * @var Screen_Names
	 */
	private $names;

	/**
	 * Licence.
	 *
	 * @var License_Gate
	 */
	private $license;

	/**
	 * Writes a line to the plugin's log: `function ( string $line ): void`.
	 *
	 * @var callable|null
	 */
	private $log_error;

	/**
	 * Creates the handler.
	 *
	 * @since 2.1.2
	 *
	 * @param Admin_Guard         $guard     Authorisation.
	 * @param Account_Store       $accounts  Accounts.
	 * @param Order_Meta_Contract $meta      The marketplace's order meta contract.
	 * @param Order_Canceller     $canceller The marketplace's cancellation.
	 * @param Screen_Names        $names     Names.
	 * @param License_Gate        $license   Licence: no licence in force, no remote call.
	 * @param callable|null       $log_error `function ( string $line ): void`, for failures that are not a refusal.
	 */
	public function __construct( Admin_Guard $guard, Account_Store $accounts, Order_Meta_Contract $meta, Order_Canceller $canceller, Screen_Names $names, License_Gate $license, ?callable $log_error = null ) {
		$this->guard     = $guard;
		$this->accounts  = $accounts;
		$this->meta      = $meta;
		$this->canceller = $canceller;
		$this->names     = $names;
		$this->license   = $license;
		$this->log_error = $log_error;
	}

	/**
	 * Hooks both actions.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . $this->names->cancel_reasons_action(), [ $this, 'handle_reasons' ] );
		add_action( 'wp_ajax_' . $this->names->cancel_action(), [ $this, 'handle_cancel' ] );
	}

	/**
	 * Answers with the reasons the modal offers.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	public function handle_reasons(): void {
		try {
			list( , $account, $posting_number ) = $this->authorised_order();

			$reasons = $this->canceller->reasons( $account, $posting_number );
		} catch ( Cancel_Refused $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], $e->status() );
		} catch ( \Throwable $e ) {
			$this->fail_unexpectedly( $e );
		}

		if ( [] === $reasons ) {
			wp_send_json_error( [ 'message' => __( 'Маркетплейс не вернул причин, с которыми можно отменить это отправление. Попробуйте позже.', 'woodev-plugin-framework' ) ], 502 );
		}

		wp_send_json_success(
			[
				'reasons'      => $reasons,
				'other_reason' => $this->canceller->other_reason_id(),
			]
		);
	}

	/**
	 * Cancels the posting of the order.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	public function handle_cancel(): void {
		try {
			list( $order, $account, $posting_number ) = $this->authorised_order();

			$this->require_open( $order );

			$reason = $this->canceller->cancel(
				$account,
				$posting_number,
				self::positive_int( self::field( 'reason_id' ) ),
				self::text( self::field( 'message' ) )
			);
		} catch ( Cancel_Refused $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], $e->status() );
		} catch ( \Throwable $e ) {
			$this->fail_unexpectedly( $e );
		}

		/* translators: 1: posting number, 2: marketplace name, 3: cancellation reason. */
		$order->add_order_note( sprintf( __( 'Отправление %1$s отменено: %2$s, из WooCommerce. Причина: %3$s.', 'woodev-plugin-framework' ), $posting_number, $this->names->name(), $reason['title'] ) );
		$this->queue_refresh( $account, $posting_number );

		wp_send_json_success( [ 'message' => __( 'Отправление отменено. Заказ обновится в течение нескольких минут.', 'woodev-plugin-framework' ) ] );
	}

	/**
	 * Runs the nonce, capability and ownership checks and returns the order, its account and its posting number.
	 *
	 * @return array{0:\WC_Order,1:Account,2:string}
	 * @throws Cancel_Refused When the user may not cancel this order, or it is not an order of an account of this plugin.
	 */
	private function authorised_order(): array {
		$order_id = self::positive_int( self::field( 'order_id' ) );
		$nonce    = self::field( 'nonce' );

		if ( $order_id <= 0 || ! is_string( $nonce ) || ! $this->guard->check( self::CAPABILITY, $this->names->cancel_nonce( $order_id ), sanitize_text_field( $nonce ) ) ) {
			throw new Cancel_Refused( __( 'Страница устарела или у вас нет прав для этого действия. Обновите страницу и повторите.', 'woodev-plugin-framework' ), 403 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			throw $this->not_a_marketplace_order(); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$posting_number = $this->canceller->posting_number( $order );
		$account        = '' === $posting_number ? null : $this->owner_of( $order_id );

		if ( null === $account ) {
			throw $this->not_a_marketplace_order(); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$this->require_usable( $account );
		$this->require_licence();

		return [ $order, $account, $posting_number ];
	}

	/**
	 * Refuses every remote call (the reasons included) while the licence is not in force.
	 *
	 * @return void
	 * @throws Cancel_Refused When the licence is not in force.
	 */
	private function require_licence(): void {
		$refusal = $this->license->refusal_for( __( 'отменить отправление на маркетплейсе', 'woodev-plugin-framework' ) );

		if ( null !== $refusal ) {
			throw new Cancel_Refused( $refusal, 403 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Answers 502 for a failure the adapter did not turn into a {@see Cancel_Refused}, and logs it.
	 *
	 * @param \Throwable $e The failure.
	 * @return never
	 */
	private function fail_unexpectedly( \Throwable $e ): void {
		if ( null !== $this->log_error ) {
			call_user_func( $this->log_error, sprintf( 'Cancelling a posting failed: %s: %s', get_class( $e ), $e->getMessage() ) );
		}

		wp_send_json_error( [ 'message' => __( 'Маркетплейс не ответил. Повторите позже.', 'woodev-plugin-framework' ) ], 502 );
	}

	/**
	 * Finds the account of this plugin that owns an order: the one whose owner stamp the order carries.
	 *
	 * @param int $order_id Order id.
	 * @return Account|null
	 */
	private function owner_of( int $order_id ): ?Account {
		$stamp = $this->meta->order_owner( $order_id );

		if ( '' === $stamp ) {
			return null;
		}

		foreach ( $this->accounts->all( $this->names->id(), false ) as $account ) {
			if ( $stamp === $this->meta->account_owner( $account ) ) {
				return $account;
			}
		}

		return null;
	}

	/**
	 * Refuses an account that is switched off or does not receive orders.
	 *
	 * With order receiving off, a refresh never updates an existing order (registry-only accounting), so the cancellation would stay
	 * unsynchronised for good: refuse before the marketplace is asked.
	 *
	 * @param Account $account Account.
	 * @return void
	 * @throws Cancel_Refused When the account cannot take the cancellation back into the order.
	 */
	private function require_usable( Account $account ): void {
		if ( ! $account->is_enabled() ) {
			throw new Cancel_Refused( __( 'Подключение к маркетплейсу для этого заказа выключено в настройках плагина. Включите его и повторите.', 'woodev-plugin-framework' ), 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		if ( ! (bool) $account->setting( 'orders_enabled', true ) ) {
			throw new Cancel_Refused( __( 'Приём заказов с маркетплейса выключен в настройках плагина, поэтому заказ не обновится после отмены. Включите приём заказов в настройках и повторите.', 'woodev-plugin-framework' ), 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Builds the answer for an order that is not one of this marketplace's.
	 *
	 * @return Cancel_Refused
	 */
	private function not_a_marketplace_order(): Cancel_Refused {
		return new Cancel_Refused(
			sprintf( /* translators: %s: marketplace name. */ __( 'Заказ не найден среди заказов «%s» этого сайта. Обновите страницу.', 'woodev-plugin-framework' ), $this->names->name() ),
			404
		);
	}

	/**
	 * Queues the refresh that brings the cancellation into the order; a queue that refuses is not an error for the merchant.
	 *
	 * @param Account $account        Account.
	 * @param string  $posting_number Posting number.
	 * @return void
	 */
	private function queue_refresh( Account $account, string $posting_number ): void {
		try {
			$this->canceller->queue_refresh( $account, $posting_number );
		} catch ( Persistence_Exception $e ) {
			return;
		}
	}

	/**
	 * Refuses a posting that is delivered or already cancelled.
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 * @throws Cancel_Refused When the posting is finished.
	 */
	private function require_open( \WC_Order $order ): void {
		if ( $this->canceller->is_finished( $order ) ) {
			throw new Cancel_Refused( __( 'Это отправление уже завершено или отменено, отменить его нельзя.', 'woodev-plugin-framework' ), 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Reads a request field, unslashed and otherwise untouched; the callers check its type and format.
	 *
	 * @param string $key Field name.
	 * @return mixed Null when the field is absent.
	 */
	private static function field( string $key ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is checked in authorised_order() before any field acts; positive_int() and text() validate.
	}

	/**
	 * Reads a positive decimal integer written as a string; anything else is 0 (an array, '', '0', '-3', '1.5', ' 1').
	 *
	 * @param mixed $raw Request value.
	 * @return int
	 */
	private static function positive_int( $raw ): int {
		return is_string( $raw ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $raw ) ? (int) $raw : 0;
	}

	/**
	 * Reads a free-text field.
	 *
	 * @param mixed $raw Request value.
	 * @return string
	 */
	private static function text( $raw ): string {
		return is_string( $raw ) ? sanitize_textarea_field( $raw ) : '';
	}
}
