<?php
/**
 * Shipping orders — page-level actions above the orders table
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Shipping\Order\Action_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Toolbar_Actions' ) ) :

	/**
	 * The carrier-declared, page-level actions of the orders page (s164): a button above the table that opens a
	 * dialog and runs ONE thing for SEVERAL orders — «Вызвать курьера» for the orders that need one.
	 *
	 * Everything a carrier supplies arrives through four filters and is re-validated here, so the client only ever
	 * meets a well-formed declaration:
	 *
	 * | filter                                   | answers                                                        |
	 * |------------------------------------------|----------------------------------------------------------------|
	 * | `woodev_shipping_orders_toolbar_actions` | which buttons exist (and whether each is shown)                |
	 * | `woodev_shipping_orders_toolbar_dialog`  | the dialog of one button: its tabs                             |
	 * | `woodev_shipping_perform_toolbar_action` | run the submitted form for ONE order                           |
	 * | `woodev_shipping_perform_toolbar_row_action` | run a button of a row of the dialog's list tab             |
	 *
	 * A dialog is made of TABS, each one of two kinds:
	 *
	 *  - `form` — {@see Order_Action_Fields} (every type, plus the `orders` multi-select, exactly one of which a
	 *    form needs: it names the orders the one submit runs for). The server validates the posted payload against
	 *    the FRESH declaration, then calls the carrier's per-order filter for every chosen order with the shared
	 *    payload and answers one result per order.
	 *  - `list` — rows the carrier supplies (columns + cell values), each with optional buttons («Отменить»), run
	 *    through the row-action filter after the server has checked the button is really on that row.
	 *
	 * The class is the one place the declaration is sanitised and the one place it is performed; the REST routes
	 * ({@see \Woodev\Framework\Shipping\Rest_Api\Toolbar_Controller}) only translate its answers into HTTP.
	 *
	 * @since 2.0.2
	 */
	final class Toolbar_Actions {

		/** @var string */
		public const TAB_FORM = 'form';

		/** @var string */
		public const TAB_LIST = 'list';

		/**
		 * The most tabs a dialog shows; the rest are dropped.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		public const MAX_TABS = 4;

		/**
		 * The most rows a list tab carries; the rest are dropped — a dialog is not a report.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		public const MAX_ROWS = 200;

		/**
		 * The most columns a list tab has.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		public const MAX_COLUMNS = 8;

		/**
		 * The longest a toolbar button's or a row's label may be, in characters.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		public const MAX_LABEL_LENGTH = 120;

		/**
		 * Registry the order's carrier is resolved through.
		 *
		 * @since 2.0.2
		 *
		 * @var Orders_Registry
		 */
		private Orders_Registry $registry;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry $registry registry an order's carrier is resolved through.
		 */
		public function __construct( Orders_Registry $registry ) {
			$this->registry = $registry;
		}

		/**
		 * Every toolbar action the carriers declare, shown or not.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int,array{id:string,label:string,title:string,icon:string,count:int|null,visible:bool}>
		 */
		public function declared(): array {
			/**
			 * Declares the buttons above the orders table, one per page-level action.
			 *
			 * Each entry: `[ 'id' => string (lowercase letters, digits, `_`, `-`; unique), 'label' => string,
			 * 'title' => string (optional tooltip), 'icon' => string (optional Dashicons slug without the
			 * `dashicons-` prefix), 'count' => int (optional: how many orders the action is for now — drawn on the
			 * button, and the button is hidden at 0), 'visible' => bool (optional: overrides that default, e.g.
			 * `true` to keep a dialog reachable for its list tab while nothing is left to run) ]`.
			 *
			 * ⚠ Runs on every load of the orders page and after every action: keep it CHEAP (a count from meta or an
			 * option, never a request to the carrier). The dialog's contents are asked for separately, only when
			 * the merchant opens it ({@see 'woodev_shipping_orders_toolbar_dialog'}).
			 *
			 * @since 2.0.2
			 *
			 * @param array<int,array<string,mixed>> $actions the declarations so far; `[]`.
			 */
			return self::sanitize_declared( apply_filters( 'woodev_shipping_orders_toolbar_actions', [] ) );
		}

		/**
		 * The buttons the page draws: {@see self::declared()} without the hidden ones.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int,array{id:string,label:string,title:string,icon:string,count:int|null}>
		 */
		public function for_page(): array {
			$shown = [];

			foreach ( $this->declared() as $action ) {
				if ( ! $action['visible'] ) {
					continue;
				}

				unset( $action['visible'] );
				$shown[] = $action;
			}

			return $shown;
		}

		/**
		 * One declared action by id — hidden or not: the dialog of a hidden button is still a valid request (the
		 * page may have been open while the count dropped).
		 *
		 * @since 2.0.2
		 *
		 * @param string $id the action id.
		 * @return array{id:string,label:string,title:string,icon:string,count:int|null,visible:bool}|null
		 */
		public function find( string $id ): ?array {
			foreach ( $this->declared() as $action ) {
				if ( $action['id'] === $id ) {
					return $action;
				}
			}

			return null;
		}

		/**
		 * The dialog of one action, as the carrier declares it NOW.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id the action id.
		 * @return array{title:string,description?:string,tabs:array<int,array<string,mixed>>}|null null when no such action is declared, or its carrier supplied no usable dialog.
		 */
		public function dialog( string $id ): ?array {
			$action = $this->find( $id );

			if ( null === $action ) {
				return null;
			}

			/**
			 * Supplies the dialog of one toolbar action — asked for when the merchant opens it, and again after it
			 * has changed something (so the list tab is current).
			 *
			 * Return an array and leave every other action's value untouched (`$dialog` is `null` until a callback
			 * answers; check `$action_id` is yours first):
			 *
			 * `[ 'title' => string (default: the button's label), 'description' => string (optional),
			 *    'tabs' => [ tab, … ] ]`, a tab being one of
			 *
			 *  - `[ 'id' => string, 'type' => 'form', 'label' => string, 'description' => string (optional),
			 *       'submit_label' => string (optional), 'fields' => [ field, … ] ]` — fields as
			 *    {@see Order_Action_Fields} declares them, plus ONE `type => 'orders'` field
			 *    (`options` = `[ [ 'value' => order id, 'label' => '#1047 · Екатеринбург' ], … ]`, preselected
			 *    unless a `default` list says otherwise). A form tab without an `orders` field is dropped.
			 *  - `[ 'id' => string, 'type' => 'list', 'label' => string, 'columns' => [ [ 'id' => string,
			 *       'label' => string ], … ], 'rows' => [ [ 'id' => string, 'cells' => [ column id => text ],
			 *       'actions' => [ [ 'action' => string, 'label' => string, 'title' => string (optional),
			 *       'destructive' => bool (optional), 'confirm' => string (optional, the confirmation's sentence),
			 *       'icon' => string (optional) ], … ] ], … ], 'empty' => string (optional, shown with no rows) ]`.
			 *
			 * @since 2.0.2
			 *
			 * @param array<string,mixed>|null $dialog    the dialog so far; `null`.
			 * @param string                   $action_id the toolbar action asked about.
			 */
			$dialog = apply_filters( 'woodev_shipping_orders_toolbar_dialog', null, $id );

			return self::sanitize_dialog( $dialog, $action['label'] );
		}

		/**
		 * Submits the dialog's form: validates the payload against the FRESH declaration, then runs the carrier's
		 * handler for every chosen order with the shared payload.
		 *
		 * An order's failure — or a throw — never stops the others; each answers on its own in `results`.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id          the action id.
		 * @param mixed  $raw_payload what the client posted, of unknown shape.
		 * @return array{errors:array<int,array{field:string,code:string,message:string}>}|array{requested:int,succeeded:int,failed:int,results:array<int,array{id:int,order_number:string,ok:bool,message:string}>,messages:array{success?:string,error?:string}}|null null when the action or its form is unavailable.
		 */
		public function submit( string $id, $raw_payload ): ?array {
			$dialog = $this->dialog( $id );
			$form   = null === $dialog ? null : self::first_tab( $dialog, self::TAB_FORM );

			if ( null === $form ) {
				return null;
			}

			$resolved = Order_Action_Fields::validate( $form['fields'], $raw_payload );

			if ( [] !== $resolved['errors'] ) {
				return [ 'errors' => $resolved['errors'] ];
			}

			$orders_field = self::orders_field_id( $form['fields'] );
			$order_ids    = array_map( 'absint', (array) ( $resolved['values'][ $orders_field ] ?? [] ) );

			// A form that is not `required` on its orders field must still pick one: a run for no order is no run.
			if ( [] === $order_ids ) {
				return [
					'errors' => [
						[
							'field'   => $orders_field,
							'code'    => 'required',
							'message' => __( 'Выберите хотя бы один заказ.', 'woodev-plugin-framework' ),
						],
					],
				];
			}

			// The handler gets the shared values: the choice of orders is the framework's, not the carrier's input.
			$payload = array_diff_key( $resolved['values'], [ $orders_field => true ] );
			$results = [];
			$ok      = 0;

			foreach ( $order_ids as $order_id ) {
				$result    = $this->run_for_order( $id, $order_id, $payload );
				$results[] = $result;
				$ok       += $result['ok'] ? 1 : 0;
			}

			$total = count( $results );

			return [
				'requested' => $total,
				'succeeded' => $ok,
				'failed'    => $total - $ok,
				'results'   => $results,
				'messages'  => self::build_messages( $ok, $total ),
			];
		}

		/**
		 * Runs a button of a row of the dialog's list tab.
		 *
		 * The button is looked up in the dialog the carrier declares NOW — a client's copy of the list can be
		 * stale, and a row action the carrier does not currently offer on that row is refused, never forwarded.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id         the toolbar action id.
		 * @param string $tab_id     the list tab's id.
		 * @param string $row_id     the row's id.
		 * @param string $row_action the button's id.
		 * @return array{available:bool,ok?:bool,message?:string,confirm?:string} `available` false => the dialog, tab, row or button is not on offer.
		 */
		public function perform_row_action( string $id, string $tab_id, string $row_id, string $row_action ): array {
			$dialog = $this->dialog( $id );

			if ( null === $dialog ) {
				return [ 'available' => false ];
			}

			foreach ( $dialog['tabs'] as $tab ) {
				if ( self::TAB_LIST !== $tab['type'] || $tab['id'] !== $tab_id ) {
					continue;
				}

				foreach ( $tab['rows'] as $row ) {
					if ( $row['id'] !== $row_id ) {
						continue;
					}

					foreach ( $row['actions'] as $action ) {
						if ( $action['action'] === $row_action ) {
							return $this->run_row_action( $id, $tab_id, $row_id, $row_action );
						}
					}
				}
			}

			return [ 'available' => false ];
		}

		/**
		 * Runs the per-order handler for one order and describes the outcome.
		 *
		 * @since 2.0.2
		 *
		 * @param string              $action_id the toolbar action id.
		 * @param int                 $order_id  the order id.
		 * @param array<string,mixed> $payload   the validated shared values, without the orders field.
		 * @return array{id:int,order_number:string,ok:bool,message:string}
		 */
		private function run_for_order( string $action_id, int $order_id, array $payload ): array {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				return self::order_result( $order_id, (string) $order_id, false, __( 'Заказ не найден.', 'woodev-plugin-framework' ) );
			}

			$number   = (string) $order->get_order_number();
			$provider = $this->registry->resolve_provider_for_order( $order );

			if ( null === $provider ) {
				return self::order_result( $order_id, $number, false, __( 'Не удалось определить перевозчика для этого заказа.', 'woodev-plugin-framework' ) );
			}

			try {
				/**
				 * Runs a toolbar action's submitted form for ONE order — called once per chosen order, in the order
				 * the merchant's list gave them.
				 *
				 * Return {@see Action_Result::success()} (its message is shown beside the order) or
				 * {@see Action_Result::failure()} with the carrier's reason — shown to the merchant, prefixed with the
				 * carrier name, never to a buyer. Leave the value untouched for an action that is not yours: the
				 * default is a failure with no text, so an action nothing hooks fails honestly.
				 *
				 * @since 2.0.2
				 *
				 * @param Action_Result       $result    the outcome so far; default a failure with no text.
				 * @param string              $action_id the toolbar action id.
				 * @param \WC_Order           $order     the order to run it for.
				 * @param Orders_Provider     $provider  the order's carrier.
				 * @param array<string,mixed> $payload   the form's validated values, keyed by field id — the same for every
				 *                                       order — WITHOUT the `orders` field. Shapes as in
				 *                                       `woodev_shipping_perform_order_action`.
				 */
				$result = apply_filters( 'woodev_shipping_perform_toolbar_action', Action_Result::failure(), $action_id, $order, $provider, $payload );
			} catch ( \Throwable $exception ) {
				self::log_failure( $provider->get_id(), $action_id, $exception );

				return self::order_result( $order_id, $number, false, __( 'Сервис перевозчика временно недоступен. Попробуйте повторить действие позже.', 'woodev-plugin-framework' ) );
			}

			if ( ! $result instanceof Action_Result ) {
				$result = Action_Result::failure();
			}

			if ( $result->is_success() ) {
				return self::order_result( $order_id, $number, true, '' === $result->get_message() ? '' : $result->merchant_message( $provider->get_label(), '' ) );
			}

			return self::order_result(
				$order_id,
				$number,
				false,
				$result->merchant_message( $provider->get_label(), __( 'Действие не выполнено.', 'woodev-plugin-framework' ) )
			);
		}

		/**
		 * Runs the carrier's handler for a row button already checked against the dialog.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id         the toolbar action id.
		 * @param string $tab_id     the list tab's id.
		 * @param string $row_id     the row's id.
		 * @param string $row_action the button's id.
		 * @return array{available:bool,ok:bool,message:string}
		 */
		private function run_row_action( string $id, string $tab_id, string $row_id, string $row_action ): array {
			try {
				/**
				 * Runs a button of a row of a toolbar dialog's list tab — «Отменить» on a created request.
				 *
				 * The framework has already checked the button is on that row of the dialog the carrier declares
				 * now. Return {@see Action_Result::success()} (its message is shown) or
				 * {@see Action_Result::failure()} with the reason. Leave the value untouched for an action that is
				 * not yours: the default is a failure with no text.
				 *
				 * @since 2.0.2
				 *
				 * @param Action_Result $result     the outcome so far; default a failure with no text.
				 * @param string        $action_id  the toolbar action id.
				 * @param string        $tab_id     the list tab's id.
				 * @param string        $row_id     the row's id, as the carrier declared it.
				 * @param string        $row_action the button's id.
				 */
				$result = apply_filters( 'woodev_shipping_perform_toolbar_row_action', Action_Result::failure(), $id, $tab_id, $row_id, $row_action );
			} catch ( \Throwable $exception ) {
				self::log_failure( $id, $row_action, $exception );

				return [
					'available' => true,
					'ok'        => false,
					'message'   => __( 'Сервис перевозчика временно недоступен. Попробуйте повторить действие позже.', 'woodev-plugin-framework' ),
				];
			}

			if ( ! $result instanceof Action_Result ) {
				$result = Action_Result::failure();
			}

			return [
				'available' => true,
				'ok'        => $result->is_success(),
				'message'   => $result->merchant_message( '', $result->is_success() ? __( 'Готово.', 'woodev-plugin-framework' ) : __( 'Действие не выполнено.', 'woodev-plugin-framework' ) ),
			];
		}

		/**
		 * The aggregate sentences of a submit, in the wording the bulk route uses.
		 *
		 * @since 2.0.2
		 *
		 * @param int $succeeded orders that succeeded.
		 * @param int $total     orders run.
		 * @return array{success?:string,error?:string}
		 */
		private static function build_messages( int $succeeded, int $total ): array {
			$messages = [];

			if ( $succeeded > 0 ) {
				$messages['success'] = sprintf( __( 'Выполнено %1$d из %2$d', 'woodev-plugin-framework' ), $succeeded, $total );
			}

			if ( $succeeded < $total ) {
				$messages['error'] = sprintf( __( 'Не удалось выполнить действие для %1$d из %2$d', 'woodev-plugin-framework' ), $total - $succeeded, $total );
			}

			return $messages;
		}

		/**
		 * One order's line of a submit's answer.
		 *
		 * @since 2.0.2
		 *
		 * @param int    $id      order id.
		 * @param string $number  the order number the merchant knows it by.
		 * @param bool   $ok      whether it succeeded.
		 * @param string $message the carrier's note, or the reason it failed; '' for a plain success.
		 * @return array{id:int,order_number:string,ok:bool,message:string}
		 */
		private static function order_result( int $id, string $number, bool $ok, string $message ): array {
			return [
				'id'           => $id,
				'order_number' => $number,
				'ok'           => $ok,
				'message'      => $message,
			];
		}

		/**
		 * Logs a handler that threw. The browser only ever sees a generic sentence.
		 *
		 * @since 2.0.2
		 *
		 * @param string     $scope     carrier id or toolbar action id.
		 * @param string     $action    the action or row action.
		 * @param \Throwable $exception the caught failure.
		 * @return void
		 */
		private static function log_failure( string $scope, string $action, \Throwable $exception ): void {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a carrier failure; the browser only ever sees a generic sentence.
				sprintf(
					'[woodev] shipping toolbar action "%s" (%s) failed: %s',
					$action,
					$scope,
					\Woodev_API_Base::redact_secret_log_text( $exception->getMessage() )
				)
			);
		}

		/**
		 * The first tab of a kind in a sanitised dialog.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $dialog a sanitised dialog.
		 * @param string              $type   {@see self::TAB_FORM} or {@see self::TAB_LIST}.
		 * @return array<string,mixed>|null
		 */
		private static function first_tab( array $dialog, string $type ): ?array {
			foreach ( $dialog['tabs'] as $tab ) {
				if ( $type === $tab['type'] ) {
					return $tab;
				}
			}

			return null;
		}

		/**
		 * The id of the `orders` field of a sanitised form — there is exactly one in a kept form tab.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int,array<string,mixed>> $fields sanitised fields.
		 * @return string
		 */
		private static function orders_field_id( array $fields ): string {
			foreach ( $fields as $field ) {
				if ( Order_Action_Fields::TYPE_ORDERS === $field['type'] ) {
					return (string) $field['id'];
				}
			}

			return '';
		}

		/**
		 * Re-validates the declared toolbar buttons, dropping malformed and repeated ones.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $actions the filtered value, of unknown shape.
		 * @return array<int,array{id:string,label:string,title:string,icon:string,count:int|null,visible:bool}>
		 */
		public static function sanitize_declared( $actions ): array {
			if ( ! is_array( $actions ) ) {
				return [];
			}

			$clean = [];
			$seen  = [];

			foreach ( $actions as $action ) {
				if ( ! is_array( $action ) ) {
					continue;
				}

				$id    = isset( $action['id'] ) && is_string( $action['id'] ) ? $action['id'] : '';
				$label = self::plain_text( $action['label'] ?? null, self::MAX_LABEL_LENGTH );

				if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $id ) || isset( $seen[ $id ] ) || '' === $label ) {
					continue;
				}

				$count = isset( $action['count'] ) && is_numeric( $action['count'] ) ? max( 0, (int) $action['count'] ) : null;

				$seen[ $id ] = true;
				$clean[]     = [
					'id'      => $id,
					'label'   => $label,
					'title'   => Order_Action_Fields::sanitize_help( $action['title'] ?? null ),
					'icon'    => Order_Actions::sanitize_icon( $action['icon'] ?? null ),
					'count'   => $count,
					// An explicit `visible` wins; otherwise a count of 0 hides the button and no count shows it.
					'visible' => isset( $action['visible'] ) ? (bool) $action['visible'] : ( null === $count || $count > 0 ),
				];
			}

			return $clean;
		}

		/**
		 * Re-validates a dialog the carrier supplied.
		 *
		 * Tabs are kept in the order given, up to {@see self::MAX_TABS}; a tab with no usable id, a repeated id, no
		 * label, an unknown type — or, for a form, no `orders` field — is dropped, and so is every form tab after the
		 * first (a submit runs ONE form). A dialog left with no tab is unusable: `null`.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed  $dialog         the filtered value, of unknown shape.
		 * @param string $fallback_title the title when the dialog names none: the button's label.
		 * @return array{title:string,description?:string,tabs:array<int,array<string,mixed>>}|null
		 */
		public static function sanitize_dialog( $dialog, string $fallback_title ): ?array {
			if ( ! is_array( $dialog ) || ! is_array( $dialog['tabs'] ?? null ) ) {
				return null;
			}

			$tabs     = [];
			$seen     = [];
			$has_form = false;

			foreach ( $dialog['tabs'] as $tab ) {
				if ( count( $tabs ) >= self::MAX_TABS ) {
					break;
				}

				$clean = self::sanitize_tab( $tab );

				// ONE form per dialog: a submit runs the first, and a second would look live and do nothing.
				if ( null === $clean || isset( $seen[ $clean['id'] ] ) || ( $has_form && self::TAB_FORM === $clean['type'] ) ) {
					continue;
				}

				$has_form             = $has_form || self::TAB_FORM === $clean['type'];
				$seen[ $clean['id'] ] = true;
				$tabs[]               = $clean;
			}

			if ( [] === $tabs ) {
				return null;
			}

			$title  = self::plain_text( $dialog['title'] ?? null, self::MAX_LABEL_LENGTH );
			$result = [
				'title' => '' !== $title ? $title : $fallback_title,
				'tabs'  => $tabs,
			];

			$description = Order_Action_Fields::sanitize_help( $dialog['description'] ?? null );

			if ( '' !== $description ) {
				$result['description'] = $description;
			}

			return $result;
		}

		/**
		 * Re-validates one tab.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $tab the declared tab, of unknown shape.
		 * @return array<string,mixed>|null null when the tab is unusable.
		 */
		private static function sanitize_tab( $tab ): ?array {
			if ( ! is_array( $tab ) ) {
				return null;
			}

			$id    = isset( $tab['id'] ) && is_string( $tab['id'] ) ? $tab['id'] : '';
			$label = self::plain_text( $tab['label'] ?? null, self::MAX_LABEL_LENGTH );
			$type  = isset( $tab['type'] ) && is_string( $tab['type'] ) ? $tab['type'] : '';

			if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $id ) || '' === $label ) {
				return null;
			}

			if ( self::TAB_FORM === $type ) {
				return self::sanitize_form_tab( $tab, $id, $label );
			}

			if ( self::TAB_LIST === $type ) {
				return self::sanitize_list_tab( $tab, $id, $label );
			}

			return null;
		}

		/**
		 * Re-validates a form tab: its fields through {@see Order_Action_Fields} (the `orders` type allowed), and
		 * exactly one `orders` field — a second one is dropped.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $tab   the declared tab.
		 * @param string              $id    its usable id.
		 * @param string              $label its usable label.
		 * @return array<string,mixed>|null null when the form has no `orders` field.
		 */
		private static function sanitize_form_tab( array $tab, string $id, string $label ): ?array {
			$fields = [];
			$orders = false;

			foreach ( Order_Action_Fields::sanitize( $tab['fields'] ?? null, true ) as $field ) {
				if ( Order_Action_Fields::TYPE_ORDERS === $field['type'] ) {
					if ( $orders ) {
						continue;
					}

					$orders = true;
				}

				$fields[] = $field;
			}

			if ( ! $orders ) {
				return null;
			}

			$clean = [
				'id'           => $id,
				'type'         => self::TAB_FORM,
				'label'        => $label,
				'submit_label' => self::plain_text( $tab['submit_label'] ?? null, self::MAX_LABEL_LENGTH ),
				'fields'       => $fields,
			];

			$description = Order_Action_Fields::sanitize_help( $tab['description'] ?? null );

			if ( '' !== $description ) {
				$clean['description'] = $description;
			}

			return $clean;
		}

		/**
		 * Re-validates a list tab: usable columns, rows capped at {@see self::MAX_ROWS}, and per row a cell for
		 * every column (a missing one is `''`) and the buttons {@see self::sanitize_row_actions()} keeps.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $tab   the declared tab.
		 * @param string              $id    its usable id.
		 * @param string              $label its usable label.
		 * @return array<string,mixed>|null null when the tab has no usable column.
		 */
		private static function sanitize_list_tab( array $tab, string $id, string $label ): ?array {
			$columns = [];
			$seen    = [];

			foreach ( (array) ( $tab['columns'] ?? [] ) as $column ) {
				if ( count( $columns ) >= self::MAX_COLUMNS ) {
					break;
				}

				$column_id    = is_array( $column ) && isset( $column['id'] ) && is_string( $column['id'] ) ? $column['id'] : '';
				$column_label = is_array( $column ) ? self::plain_text( $column['label'] ?? null, self::MAX_LABEL_LENGTH ) : '';

				if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $column_id ) || isset( $seen[ $column_id ] ) || '' === $column_label ) {
					continue;
				}

				$seen[ $column_id ] = true;
				$columns[]          = [
					'id'    => $column_id,
					'label' => $column_label,
				];
			}

			if ( [] === $columns ) {
				return null;
			}

			$rows     = [];
			$seen_row = [];

			foreach ( (array) ( $tab['rows'] ?? [] ) as $row ) {
				if ( count( $rows ) >= self::MAX_ROWS ) {
					break;
				}

				$row_id = is_array( $row ) && isset( $row['id'] ) && ( is_string( $row['id'] ) || is_int( $row['id'] ) ) ? trim( (string) $row['id'] ) : '';

				if ( '' === $row_id || strlen( $row_id ) > 191 || isset( $seen_row[ $row_id ] ) ) {
					continue;
				}

				$cells = [];

				foreach ( $columns as $column ) {
					$cell                  = $row['cells'][ $column['id'] ] ?? '';
					$cells[ $column['id'] ] = is_scalar( $cell ) ? self::plain_text( $cell, 500 ) : '';
				}

				$seen_row[ $row_id ] = true;
				$rows[]              = [
					'id'      => $row_id,
					'cells'   => $cells,
					'actions' => self::sanitize_row_actions( $row['actions'] ?? null ),
				];
			}

			return [
				'id'      => $id,
				'type'    => self::TAB_LIST,
				'label'   => $label,
				'columns' => $columns,
				'rows'    => $rows,
				'empty'   => Order_Action_Fields::sanitize_help( $tab['empty'] ?? null ),
			];
		}

		/**
		 * Re-validates the buttons of one list row.
		 *
		 * A button needs an id (lowercase letters, digits, `_`, `-`) and a label; `confirm` is kept for a
		 * destructive one only, as on {@see Order_Actions}.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $actions the declared buttons, of unknown shape.
		 * @return array<int,array{action:string,label:string,title:string,destructive:bool,icon:string,confirm?:string}>
		 */
		private static function sanitize_row_actions( $actions ): array {
			if ( ! is_array( $actions ) ) {
				return [];
			}

			$clean = [];
			$seen  = [];

			foreach ( $actions as $action ) {
				if ( ! is_array( $action ) ) {
					continue;
				}

				$id    = isset( $action['action'] ) && is_string( $action['action'] ) ? $action['action'] : '';
				$label = self::plain_text( $action['label'] ?? null, self::MAX_LABEL_LENGTH );

				if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $id ) || isset( $seen[ $id ] ) || '' === $label ) {
					continue;
				}

				$destructive = (bool) ( $action['destructive'] ?? false );
				$entry       = [
					'action'      => $id,
					'label'       => $label,
					'title'       => Order_Action_Fields::sanitize_help( $action['title'] ?? null ),
					'destructive' => $destructive,
					'icon'        => Order_Actions::sanitize_icon( $action['icon'] ?? null ),
				];

				$confirm = $destructive ? Order_Action_Fields::sanitize_help( $action['confirm'] ?? null ) : '';

				if ( '' !== $confirm ) {
					$entry['confirm'] = $confirm;
				}

				$seen[ $id ] = true;
				$clean[]     = $entry;
			}

			return $clean;
		}

		/**
		 * A one-line plain string: tags stripped, whitespace collapsed, cut at `$max` characters.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value the declared value.
		 * @param int   $max   the most characters kept.
		 * @return string '' when not a string or number.
		 */
		private static function plain_text( $value, int $max ): string {
			if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
				return '';
			}

			return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) ), 0, $max );
		}
	}

endif;
