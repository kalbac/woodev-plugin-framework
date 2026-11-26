<?php
/**
 * The admin-ajax side of the seller actions on the order screens.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Admin\Guard\Admin_Guard;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Flows\Order_Meta_Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Two `wp_ajax_` actions for the order screen: running a seller action, and the choices of one of its list fields.
 *
 * Every request must pass, in this order: the nonce of this order together with the capability `edit_shop_orders`
 * ({@see Admin_Guard}), the right to edit this order, then the ownership check — the order is a registry posting of an account of
 * this marketplace and carries that account's owner stamp ({@see Order_Meta_Contract::order_owner()}). Anything else is answered
 * 404 and the marketplace is never asked. Whether the action may run is the same {@see Seller_Action_Runner} the «Заказы» table
 * asks.
 *
 * @since 2.1.27
 */
final class Seller_Action_Ajax {
	/**
	 * @var Admin_Guard
	 */
	private $guard;

	/**
	 * @var Account_Store
	 */
	private $accounts;

	/**
	 * @var Order_Meta_Contract
	 */
	private $meta;

	/**
	 * @var Seller_Action_Runner
	 */
	private $runner;

	/**
	 * @var Screen_Names
	 */
	private $names;

	/**
	 * Returns the posting of an order: `function ( int $order_id ): ?array{account_id:int}`.
	 *
	 * @var callable
	 */
	private $posting_of_order;

	/**
	 * @since 2.1.27
	 *
	 * @param Admin_Guard          $guard            Authorisation.
	 * @param Account_Store        $accounts         Accounts.
	 * @param Order_Meta_Contract  $meta             The marketplace's order meta contract.
	 * @param Seller_Action_Runner $runner           The seller actions.
	 * @param Screen_Names         $names            Names.
	 * @param callable             $posting_of_order `function ( int $order_id ): ?array{account_id:int}`; null for an order with no posting.
	 */
	public function __construct( Admin_Guard $guard, Account_Store $accounts, Order_Meta_Contract $meta, Seller_Action_Runner $runner, Screen_Names $names, callable $posting_of_order ) {
		$this->guard            = $guard;
		$this->accounts         = $accounts;
		$this->meta             = $meta;
		$this->runner           = $runner;
		$this->names            = $names;
		$this->posting_of_order = $posting_of_order;
	}

	/**
	 * Hooks both actions.
	 *
	 * @since 2.1.27
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . $this->names->seller_action(), [ $this, 'handle_run' ] );
		add_action( 'wp_ajax_' . $this->names->seller_options_action(), [ $this, 'handle_options' ] );
	}

	/**
	 * Runs the action on the whole order, or on one of its units when `unit` is sent.
	 *
	 * @since 2.1.27
	 *
	 * @return void
	 */
	public function handle_run(): void {
		try {
			list( $order, $account ) = $this->authorised_order();

			$unit_id = self::raw_string( 'unit' );
			$action  = $this->asked_action( '' === $unit_id ? Seller_Action::ORDER : Seller_Action::UNIT );
			$fields  = self::field( 'fields' );
			$report  = $this->runner->run( $account, $action, [ $order ], is_array( $fields ) ? $fields : [], $unit_id );
		} catch ( Seller_Action_Refused $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], $e->status() );
		} catch ( Action_Input_Exception $e ) {
			wp_send_json_error(
				[
					'message' => __( 'Проверьте заполненные поля.', 'woodev-plugin-framework' ),
					'errors'  => $e->get_errors(),
				],
				422
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => __( 'Не удалось выполнить действие. Повторите чуть позже.', 'woodev-plugin-framework' ) ], 500 );
		}

		wp_send_json_success( self::answer( $report ) );
	}

	/**
	 * Answers with the choices of a list field.
	 *
	 * @since 2.1.27
	 *
	 * @return void
	 */
	public function handle_options(): void {
		try {
			list( , $account ) = $this->authorised_order();

			$action  = $this->asked_action( null );
			$options = $this->runner->field_options( $account, $action, sanitize_key( self::raw_string( 'field' ) ), sanitize_text_field( self::raw_string( 'search' ) ) );
		} catch ( Seller_Action_Refused $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], $e->status() );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => __( 'Не удалось загрузить список.', 'woodev-plugin-framework' ) ], 500 );
		}

		wp_send_json_success( [ 'options' => $options ] );
	}

	/**
	 * What the dialog shows after a run: the outcome in a sentence, a line for every unit that was not done, and whether the
	 * page should be read again.
	 *
	 * @param Seller_Action_Report $report Report.
	 * @return array{message:string,lines:string[],changed:bool}
	 */
	private static function answer( Seller_Action_Report $report ): array {
		$lines = [];

		foreach ( $report->failed() as $result ) {
			$lines[] = $result->target()->unit()->label() . ' — ' . $result->reason();
		}

		foreach ( $report->skipped() as $skip ) {
			$lines[] = '' === $skip['unit'] ? $skip['reason'] : $skip['unit'] . ' — ' . $skip['reason'];
		}

		$done = count( $report->done() );

		if ( [] === $lines ) {
			$message = __( 'Готово.', 'woodev-plugin-framework' );
		} elseif ( $done > 0 ) {
			$message = __( 'Выполнено частично: причины перечислены ниже.', 'woodev-plugin-framework' );
		} else {
			$message = __( 'Ничего не выполнено: причины перечислены ниже.', 'woodev-plugin-framework' );
		}

		return [
			'message' => $message,
			'lines'   => $lines,
			'changed' => $done > 0,
		];
	}

	/**
	 * Runs the nonce, capability and ownership checks and returns the order and its account.
	 *
	 * @return array{0:\WC_Order,1:Account}
	 * @throws Seller_Action_Refused When the user may not act on this order, or it is not an order of an account of this plugin.
	 */
	private function authorised_order(): array {
		$raw_id   = self::raw_string( 'order_id' );
		$order_id = 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $raw_id ) ? (int) $raw_id : 0;
		$nonce    = sanitize_text_field( self::raw_string( 'nonce' ) );

		if ( $order_id <= 0 || '' === $nonce || ! $this->guard->check( Cancel_Ajax::CAPABILITY, $this->names->seller_nonce( $order_id ), $nonce )
			|| ! current_user_can( 'edit_shop_order', $order_id ) ) {
			throw new Seller_Action_Refused( __( 'Страница устарела или у вас нет прав для этого действия. Обновите страницу и повторите.', 'woodev-plugin-framework' ), 403 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$order   = wc_get_order( $order_id );
		$account = $order instanceof \WC_Order ? $this->owner_of( $order_id ) : null;

		if ( null === $account ) {
			throw new Seller_Action_Refused( sprintf( /* translators: %s: marketplace name. */ __( 'Заказ не найден среди заказов «%s» этого сайта. Обновите страницу.', 'woodev-plugin-framework' ), $this->names->name() ), 404 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return [ $order, $account ];
	}

	/**
	 * The account of this marketplace whose posting the order is and whose owner stamp it carries.
	 *
	 * @param int $order_id Order id.
	 * @return Account|null
	 */
	private function owner_of( int $order_id ): ?Account {
		$posting = call_user_func( $this->posting_of_order, $order_id );
		$account = is_array( $posting ) ? $this->accounts->get( (int) $posting['account_id'] ) : null;

		if ( null === $account || $account->adapter_id() !== $this->names->id() ) {
			return null;
		}

		$stamp = $this->meta->account_owner( $account );

		return '' !== $stamp && $this->meta->order_owner( $order_id ) === $stamp ? $account : null;
	}

	/**
	 * The declared action the request names.
	 *
	 * @param string|null $target Where it must be offered; null for any place.
	 * @return Seller_Action
	 * @throws Seller_Action_Refused When it is not declared, or not offered there.
	 */
	private function asked_action( ?string $target ): Seller_Action {
		$action = $this->runner->action( sanitize_key( self::raw_string( 'seller_action' ) ) );

		if ( null === $action || ( null !== $target && ! $action->is_for( $target ) ) ) {
			throw new Seller_Action_Refused( __( 'Это действие недоступно для заказов этого маркетплейса.', 'woodev-plugin-framework' ), 404 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $action;
	}

	/**
	 * Reads a request field, unslashed and otherwise untouched; the callers check its type and format.
	 *
	 * @param string $key Field name.
	 * @return mixed Null when the field is absent.
	 */
	private static function field( string $key ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is checked in authorised_order() before any field acts; the readers and Seller_Action_Input validate.
	}

	/**
	 * @param string $key Field name.
	 * @return string Empty when the field is absent or not a string.
	 */
	private static function raw_string( string $key ): string {
		$value = self::field( $key );

		return is_string( $value ) ? $value : '';
	}
}
