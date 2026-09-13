<?php
/**
 * Woodev Shipping Admin Order
 *
 * The order-edit admin metabox for a shipping plugin (spec §4.4), rehung onto the
 * v2 {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider} contract (card
 * #856): the FRAMEWORK builds and registers this — through
 * {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::add_hooks()}, the
 * same seam that builds the «Заказы доставки» page — the moment at least one
 * provider is registered, exactly as {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::has_providers()}
 * already gates the page. A carrier plugin supplies DATA only, through the
 * provider contract (meta keys, status map, tracking template) plus the two
 * handler registrations ({@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::register_shipment_handler()},
 * {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::register_tracking_handler()})
 * — it never constructs this class and never passes it a field list, a title or
 * an admin-post action, unlike the v1 scheme this replaces.
 *
 * Which order gets a metabox, whether it reads as exported, which fields have a
 * value and which action buttons are offered are all resolved through the exact
 * same seams the orders page/REST surface already use —
 * {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::resolve_provider_for_order()},
 * {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Row_Builder::build()} and
 * {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::for_order()} — so the
 * metabox can never disagree with the table about the same order (the defect
 * class #855 already found once).
 *
 * KISS (operator, #856): no field is mandatory. A value the carrier's provider
 * does not supply for this order is simply not in the field list — never
 * rendered as a placeholder or a dash (a dash belongs in the TABLE, where one
 * column serves every row; the metabox is a list, and an absent field is just a
 * shorter list).
 *
 * See docs-internal/platform-v2-s1-shipping-spec.md §4.4.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Admin;

use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Order_Row_Builder;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Shipping_Admin_Order' ) ) :

	class Shipping_Admin_Order {

		/**
		 * Admin-post action the metabox's action-button forms post to, and the
		 * matching nonce action. Framework-wide (card #856) — no longer
		 * per-plugin, since one instance of this class now serves every
		 * registered carrier.
		 *
		 * @since 2.0.2
		 */
		const ADMIN_POST_ACTION = 'woodev_shipping_order_action';

		/** @var string metabox id. */
		const METABOX_ID = 'woodev_shipping_order';

		/**
		 * Transient key prefix a flashed action-refusal/failure notice is stored
		 * under across the `handle_order_action()` redirect — one per user, so two
		 * admins acting concurrently cannot clobber each other's notice. Mirrors
		 * {@see \Woodev_Account_Connection}'s own `woodev_account_notice` flash
		 * (that codebase's established mechanism for a message that must survive a
		 * `wp_safe_redirect()`), read back by {@see self::render_action_notice()}.
		 *
		 * @since 2.0.2
		 */
		const NOTICE_TRANSIENT_KEY = 'woodev_shipping_order_action_notice_';

		/** @var Orders_Registry registry this metabox resolves providers/handlers through */
		private Orders_Registry $registry;

		/**
		 * Popular-settlements store (#488) used to resolve the settlement + the
		 * provider that produced it before the export action calls
		 * {@see Abstract_Shipment_Handler::export()} — see
		 * {@see self::resolve_popular_settlement_context()}.
		 *
		 * Always non-null after construction: a null/omitted constructor argument
		 * defaults to the framework's shared instance
		 * ({@see Location_Provider_Registry::popular_settlement_store()}) instead
		 * of disabling enrolment.
		 *
		 * @var Popular_Settlement_Store
		 */
		private Popular_Settlement_Store $popular_settlement_store;

		/** @var Order_Row_Builder|null lazily built; @see self::row_builder() */
		private ?Order_Row_Builder $row_builder = null;

		/** @var Order_Actions|null lazily built; @see self::order_actions() */
		private ?Order_Actions $order_actions = null;

		/**
		 * Constructor.
		 *
		 * Stores the collaborators; it adds no hooks — {@see Orders_Registry::add_hooks()}
		 * does that, on the same provider-registration trigger that builds the
		 * «Заказы доставки» page.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Rehung onto the v2 contract (#856): the constructor no longer
		 *              takes a plugin, an order handler, a shipment/tracking handler
		 *              or a field/title/action-name override — every one of those is
		 *              now resolved per-order through {@see Orders_Provider}.
		 *
		 * @param Orders_Registry|null          $registry                 registry providers/handlers are resolved through; null resolves the framework's singleton
		 * @param Popular_Settlement_Store|null $popular_settlement_store popular-settlements store (#488); null resolves the framework's shared instance
		 */
		public function __construct( ?Orders_Registry $registry = null, ?Popular_Settlement_Store $popular_settlement_store = null ) {

			$this->registry                 = $registry ?? Orders_Registry::instance();
			$this->popular_settlement_store = $popular_settlement_store ?? Location_Provider_Registry::instance()->popular_settlement_store();
		}

		/**
		 * The row/action builder, lazily built against {@see self::$registry} — the
		 * exact same seam {@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller}
		 * builds a row's `is_exported` and `actions` from (card #856): the metabox
		 * and the table can never disagree about the same order.
		 *
		 * @since 2.0.2
		 *
		 * @return Order_Row_Builder
		 */
		private function row_builder(): Order_Row_Builder {
			if ( null === $this->row_builder ) {
				$this->row_builder = new Order_Row_Builder( new Order_Actions( $this->registry ) );
			}

			return $this->row_builder;
		}

		/**
		 * The action-set gate, lazily built against {@see self::$registry} — the
		 * exact same seam {@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::perform_action()}
		 * recomputes an order's available actions from before performing one
		 * (#856 round 2): `handle_order_action()` never trusts the posted action
		 * either.
		 *
		 * @since 2.0.2
		 *
		 * @return Order_Actions
		 */
		private function order_actions(): Order_Actions {
			if ( null === $this->order_actions ) {
				$this->order_actions = new Order_Actions( $this->registry );
			}

			return $this->order_actions;
		}

		/**
		 * Registers the shipment metabox on the order-edit screen.
		 *
		 * Mounts on both the legacy (`shop_order`) and HPOS order screens, only when
		 * the order matches a registered provider — resolved by
		 * {@see Orders_Registry::resolve_provider_for_order()}, the same lookup the
		 * orders page/REST surface use, never a hardcoded shipping-method-id check.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Matches by {@see Orders_Registry::resolve_provider_for_order()}
		 *              against every registered provider (#856), replacing the v1
		 *              single-plugin `is_our_order()` check.
		 *
		 * @param string                  $post_type     current screen post type / id
		 * @param \WP_Post|\WC_Order|null $post_or_order current post or order object
		 * @return void
		 */
		public function add_meta_box( string $post_type, $post_or_order = null ): void {

			$order = $this->resolve_order( $post_or_order );

			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			$provider = $this->registry->resolve_provider_for_order( $order );

			if ( null === $provider ) {
				return;
			}

			add_meta_box(
				self::METABOX_ID,
				sprintf(
					/* translators: %s: carrier label, e.g. "СДЭК" */
					__( 'Информация %s', 'woodev-plugin-framework' ),
					$provider->get_label()
				),
				function () use ( $order, $provider ) {
					$this->render_metabox( $order, $provider );
				},
				$post_type,
				'side',
				'default'
			);
		}

		/**
		 * Renders the shipment metabox body.
		 *
		 * Two states, both decided by the SAME `is_exported` {@see Order_Row_Builder::build()}
		 * computes for the table/REST rows (card #856):
		 *
		 * - NOT exported: an informational text plus whatever action the shared
		 *   {@see Order_Actions::for_order()} set offers (normally just export).
		 * - Exported: the non-empty fields, the delivery history, and whatever
		 *   actions that set offers (update/cancel/carrier extras).
		 *
		 * KISS (operator, #856): a field the provider does not supply for this
		 * order is simply absent from `$fields` — never rendered as a dash.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Rehung onto the v2 contract (#856): fields, state and actions
		 *              all come from {@see Order_Row_Builder}/{@see Order_Actions}
		 *              instead of a plugin-supplied field map and a hardcoded
		 *              export/track/cancel trio.
		 *
		 * @param \WC_Order       $order    the order being displayed
		 * @param Orders_Provider $provider the matched carrier
		 * @return void
		 */
		public function render_metabox( \WC_Order $order, Orders_Provider $provider ): void {

			$row = $this->row_builder()->build( $order, $provider );

			$is_exported = ! empty( $row['is_exported'] );
			$fields      = $is_exported ? $this->build_fields( $order, $provider, $row ) : [];
			$actions     = is_array( $row['actions'] ?? null ) ? $row['actions'] : [];

			$info_text = $is_exported ? '' : sprintf(
				/* translators: %s: carrier label, e.g. "СДЭК" */
				__( 'Заказ ещё не передан перевозчику «%s».', 'woodev-plugin-framework' ),
				$provider->get_label()
			);

			$history_html = $is_exported
				? $this->resolve_history_html( $order, $provider, $row['tracking']['number'] ?? null )
				: '';

			$admin_post_action = self::ADMIN_POST_ACTION;
			$nonce_action      = self::ADMIN_POST_ACTION;
			$order_id          = $order->get_id();

			include __DIR__ . '/views/html-admin-order-metabox.php';
		}

		/**
		 * Builds the metabox's field list for the EXPORTED state — every entry has
		 * a non-empty value; an unsupplied field is never added (KISS, #856).
		 *
		 * Reads the carrier order id directly (the row array carries only the
		 * `is_exported` boolean derived from it, not the raw value); tracking and
		 * the pickup point are read from the already-built row so this stays the
		 * single source the table itself reads.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    order.
		 * @param Orders_Provider      $provider matched carrier.
		 * @param array<string, mixed> $row      the row {@see Order_Row_Builder::build()} produced for this order.
		 * @return array<int, array{label: string, value: string, url: string|null}>
		 */
		private function build_fields( \WC_Order $order, Orders_Provider $provider, array $row ): array {

			$fields = [];

			$carrier_order_id_key = $provider->get_carrier_order_id_meta_key();

			if ( null !== $carrier_order_id_key ) {
				$carrier_order_id = (string) \Woodev_Order_Compatibility::get_order_meta( $order, $carrier_order_id_key );

				if ( '' !== $carrier_order_id ) {
					$fields[] = [
						'label' => __( 'ID заказа у перевозчика', 'woodev-plugin-framework' ),
						'value' => $carrier_order_id,
						'url'   => null,
					];
				}
			}

			$tracking_number = $row['tracking']['number'] ?? null;

			if ( null !== $tracking_number && '' !== $tracking_number ) {
				$fields[] = [
					'label' => __( 'Трек-номер', 'woodev-plugin-framework' ),
					'value' => (string) $tracking_number,
					'url'   => isset( $row['tracking']['url'] ) ? (string) $row['tracking']['url'] : null,
				];
			}

			$destination_kind = $row['shipping']['destination_kind'] ?? null;
			$destination_text = $row['shipping']['destination_text'] ?? '';

			if ( 'pickup' === $destination_kind && '' !== $destination_text ) {
				$fields[] = [
					'label' => __( 'Пункт выдачи', 'woodev-plugin-framework' ),
					'value' => (string) $destination_text,
					'url'   => null,
				];
			}

			$status_raw = $row['delivery_status']['raw'] ?? null;

			if ( null !== $status_raw && '' !== $status_raw ) {
				$status_label = $row['delivery_status']['raw_label'] ?? $row['delivery_status']['canonical_label'] ?? $status_raw;

				$fields[] = [
					'label' => __( 'Статус доставки', 'woodev-plugin-framework' ),
					'value' => (string) $status_label,
					'url'   => null,
				];
			}

			return $fields;
		}

		/**
		 * Resolves the delivery-history markup for the metabox.
		 *
		 * The framework draws the history itself (card #856) — a carrier plugin no
		 * longer has to subscribe to anything to get one — but the
		 * `{prefix}_tracking_admin_display` hook survives ({@see Abstract_Tracking_Handler::get_admin_display_hook()})
		 * so a plugin that wants to REPLACE the output still can: when something is
		 * already hooked, this defers to it entirely instead of also drawing the
		 * default, so the two never render side by side.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order       $order           order.
		 * @param Orders_Provider $provider        matched carrier.
		 * @param string|null     $tracking_number the order's tracking number, or null when none is set.
		 * @return string
		 */
		private function resolve_history_html( \WC_Order $order, Orders_Provider $provider, ?string $tracking_number ): string {

			if ( null === $tracking_number || '' === $tracking_number ) {
				return '';
			}

			$tracking_handler = $this->registry->get_tracking_handler( $provider->get_id() );

			if ( null === $tracking_handler ) {
				return '';
			}

			ob_start();

			if ( has_action( $tracking_handler->get_admin_display_hook() ) ) {
				$tracking_handler->display_admin( $order, $tracking_number );
			} else {
				self::render_default_history( $tracking_handler->get_history( $tracking_number ) );
			}

			return (string) ob_get_clean();
		}

		/**
		 * The framework's own default delivery-history rendering — used only when
		 * nothing hooked `{prefix}_tracking_admin_display` to replace it.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int, array{status: string, description: string, timestamp: int, location: string}> $history oldest-to-newest, as {@see Abstract_Tracking_Handler::get_history()} returns.
		 * @return void
		 */
		private static function render_default_history( array $history ): void {

			if ( [] === $history ) {
				return;
			}

			echo '<ul class="woodev-shipping-order-history">';

			foreach ( $history as $event ) {

				$status      = isset( $event['status'] ) ? (string) $event['status'] : '';
				$description = isset( $event['description'] ) ? (string) $event['description'] : '';
				$timestamp   = isset( $event['timestamp'] ) ? (int) $event['timestamp'] : 0;
				$location    = isset( $event['location'] ) ? (string) $event['location'] : '';
				$text        = '' !== $description ? $description : $status;

				if ( '' === $text ) {
					continue;
				}

				echo '<li>';

				if ( $timestamp > 0 ) {
					echo '<strong>' . esc_html( wp_date( 'd.m.Y H:i', $timestamp ) ) . '</strong> ';
				}

				echo esc_html( $text );

				if ( '' !== $location ) {
					echo ' &mdash; ' . esc_html( $location );
				}

				echo '</li>';
			}

			echo '</ul>';
		}

		/**
		 * Handles an export / update / cancel (or carrier-extra) submission from
		 * the metabox's action-button forms.
		 *
		 * Resolves the order's provider the same way {@see self::add_meta_box()}
		 * did — never trusting a posted provider id — and dispatches to
		 * {@see self::perform_action()}, the metabox's sibling of
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::dispatch_action()}.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Framework-wide handler (#856): resolves the provider via the
		 *              registry instead of a single plugin's `is_our_order()`, and
		 *              performs export/update/cancel via the shared
		 *              {@see Order_Actions} action ids instead of a hardcoded
		 *              export/track/cancel trio.
		 * @since 2.0.2 Round 2 (#856): refuses a posted action the shared
		 *              {@see Order_Actions::for_order()} gate does not currently
		 *              offer instead of dispatching it straight to the carrier
		 *              handler, and flashes a notice on a refusal or a failure
		 *              rather than redirecting as if the action succeeded.
		 *
		 * @return void
		 */
		public function handle_order_action(): void {

			check_admin_referer( self::ADMIN_POST_ACTION );

			if ( ! current_user_can( 'edit_shop_orders' ) ) {
				wp_die( esc_html__( 'You do not have permission to manage this shipment.', 'woodev-plugin-framework' ) );
			}

			$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
			$action   = isset( $_POST['woodev_shipping_order_action'] ) ? sanitize_key( wp_unslash( $_POST['woodev_shipping_order_action'] ) ) : '';
			$order    = wc_get_order( $order_id );
			$provider = $order instanceof \WC_Order ? $this->registry->resolve_provider_for_order( $order ) : null;

			if ( $order instanceof \WC_Order && null !== $provider ) {

				$handler = $this->registry->get_shipment_handler( $provider->get_id() );

				if ( null !== $handler ) {
					$this->perform_action( $handler, $order, $action, $provider );
				}
			}

			$redirect = $order instanceof \WC_Order ? $order->get_edit_order_url() : admin_url();

			wp_safe_redirect( $redirect );
			exit;
		}

		/**
		 * Performs one action against the carrier's shipment handler — after
		 * refusing it if the shared {@see Order_Actions::for_order()} gate does not
		 * currently offer it, and classifying the outcome the same way
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::perform_action()}
		 * does: an exception is caught and logged, a handler return that means
		 * failure is reported as one, and only then is the switch below (the
		 * metabox's sibling of {@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::dispatch_action()})
		 * reached. Same three verbs, same `default:` extension point, so a carrier
		 * plugin that hooks `woodev_shipping_perform_order_action` for its own extra
		 * action (e.g. «Печать документа») works from EITHER surface without
		 * change. Card #710 («Создать заказ») is explicitly out of scope here: if
		 * it ever reaches the shared action set, its button flows through this
		 * same `default:` branch unchanged, like any other carrier extra.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Round 2 (#856): recomputes the gate via
		 *              {@see Order_Actions::is_offered()} and refuses an action it
		 *              does not list, catches a thrown carrier exception, and
		 *              checks the handler's return instead of assuming success —
		 *              flashing a notice {@see self::render_action_notice()} shows
		 *              on the redirect for every one of those outcomes.
		 *
		 * @param Abstract_Shipment_Handler $handler  handler resolved for the order's carrier.
		 * @param \WC_Order                 $order    the order.
		 * @param string                    $action   one of {@see Order_Actions}' action ids.
		 * @param Orders_Provider           $provider the matched carrier descriptor.
		 * @return void
		 */
		private function perform_action( Abstract_Shipment_Handler $handler, \WC_Order $order, string $action, Orders_Provider $provider ): void {

			$order_actions = $this->order_actions();

			if ( ! $order_actions->is_offered( $order, $provider, $action ) ) {
				$this->flash_notice( $order_actions->unavailable_reason( $order, $provider, $action ) );

				return;
			}

			try {
				$succeeded = $this->dispatch_action( $handler, $order, $action, $provider );
			} catch ( \Throwable $exception ) {
				self::log_action_failure( $provider->get_id(), $action, $exception );

				$this->flash_notice( self::upstream_error_message() );

				return;
			}

			if ( ! $succeeded ) {
				$this->flash_notice( self::action_failure_message( $action ) );
			}
		}

		/**
		 * Dispatches one action to the carrier's shipment handler, reporting whether
		 * it succeeded — the metabox's sibling of
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::dispatch_action()}.
		 *
		 * ⚠ `export()` returns `''` on failure AND on a carrier response with no id
		 * (card #860) — a `''` return is NOT success. `cancel()`/`update()` already
		 * return bool.
		 *
		 * @since 2.0.2
		 *
		 * @param Abstract_Shipment_Handler $handler  handler resolved for the order's carrier.
		 * @param \WC_Order                 $order    the order.
		 * @param string                    $action   one of {@see Order_Actions}' action ids.
		 * @param Orders_Provider           $provider the matched carrier descriptor.
		 * @return bool
		 */
		private function dispatch_action( Abstract_Shipment_Handler $handler, \WC_Order $order, string $action, Orders_Provider $provider ): bool {

			switch ( $action ) {
				case Order_Actions::EXPORT:
					[ $settlement, $settlement_provider ] = $this->resolve_popular_settlement_context( $order );

					return '' !== $handler->export( $order, $settlement, $settlement_provider );

				case Order_Actions::CANCEL:
					return $handler->cancel( $order );

				case Order_Actions::UPDATE:
					return $handler->update( $order );

				default:
					/** This filter is documented in class-orders-controller.php ({@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::dispatch_action()}). */
					return (bool) apply_filters( 'woodev_shipping_perform_order_action', false, $action, $order, $provider );
			}
		}

		/**
		 * Flashes a message {@see self::render_action_notice()} shows on the next
		 * page load — the redirect back to the order-edit screen — keyed per user
		 * so two admins acting concurrently cannot clobber each other's notice.
		 *
		 * @since 2.0.2
		 *
		 * @param string $message already-translated notice text.
		 * @return void
		 */
		private function flash_notice( string $message ): void {
			set_transient( self::NOTICE_TRANSIENT_KEY . get_current_user_id(), $message, 60 );
		}

		/**
		 * Renders a flashed action-refusal/failure notice, if one is waiting for the
		 * current user. Hooked onto `admin_notices` by
		 * {@see Orders_Registry::add_hooks()} — the same mechanism
		 * {@see \Woodev_Account_Connection::render_connect_notice()} uses for a
		 * message that must survive a `wp_safe_redirect()`. Single-use: read once,
		 * deleted immediately, so it is a NONCE-free static read/clear rather than a
		 * state change.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function render_action_notice(): void {

			$key     = self::NOTICE_TRANSIENT_KEY . get_current_user_id();
			$message = get_transient( $key );

			if ( ! is_string( $message ) || '' === $message ) {
				return;
			}

			delete_transient( $key );

			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}

		/**
		 * The Russian failure sentence for one action.
		 *
		 * Mirrors {@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::action_failure_message()}
		 * text-for-text — same msgids, so this introduces no new translatable
		 * string.
		 *
		 * @since 2.0.2
		 *
		 * @param string $action one of {@see Order_Actions}' action ids.
		 * @return string
		 */
		private static function action_failure_message( string $action ): string {
			switch ( $action ) {
				case Order_Actions::EXPORT:
					return __( 'Не удалось выгрузить заказ перевозчику.', 'woodev-plugin-framework' );

				case Order_Actions::CANCEL:
					return __( 'Не удалось отменить отправление.', 'woodev-plugin-framework' );

				case Order_Actions::UPDATE:
					return __( 'Не удалось обновить информацию по заказу.', 'woodev-plugin-framework' );

				default:
					return __( 'Действие не выполнено.', 'woodev-plugin-framework' );
			}
		}

		/**
		 * The generic Russian sentence for an action that threw rather than
		 * returning a failure.
		 *
		 * Mirrors {@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::action_upstream_error()}'s
		 * message text-for-text — same msgid, so this introduces no new
		 * translatable string. REST reports that same case as a 502; wp-admin has
		 * no status code to report, so the equivalent here is the flashed notice.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private static function upstream_error_message(): string {
			return __( 'Сервис перевозчика временно недоступен. Попробуйте повторить действие позже.', 'woodev-plugin-framework' );
		}

		/**
		 * Logs an action failure. The merchant only ever sees the generic flashed
		 * notice from {@see self::upstream_error_message()}.
		 *
		 * Mirrors {@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::log_action_failure()}.
		 *
		 * @since 2.0.2
		 *
		 * @param string     $provider_id carrier/tab id.
		 * @param string     $action      one of {@see Order_Actions}' action ids.
		 * @param \Throwable $exception   the caught failure.
		 * @return void
		 */
		private static function log_action_failure( string $provider_id, string $action, \Throwable $exception ): void {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a carrier failure; the merchant only ever sees a generic notice.
				sprintf(
					'[woodev] shipping order action "%s" (%s) failed: %s',
					$action,
					$provider_id,
					\Woodev_API_Base::redact_secret_log_text( $exception->getMessage() )
				)
			);
		}

		/**
		 * Resolves the popular-settlements enrolment context for an order about to
		 * be exported — the settlement the customer picked at checkout (via
		 * {@see Popular_Settlement_Store::recall_candidate()}) and the SAME
		 * provider that produced it, looked up by the settlement's own
		 * `provider_id()` via {@see Location_Provider_Registry::get_providers()}.
		 *
		 * Returns `[ null, null ]` when no candidate was recalled, or when the
		 * provider that produced it is no longer registered — the export action
		 * still runs, just without enrolment.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order about to be exported
		 *
		 * @return array{0: \Woodev\Framework\Shipping\Location\Location_Record|null, 1: \Woodev\Framework\Shipping\Location\Location_Provider|null}
		 */
		protected function resolve_popular_settlement_context( \WC_Order $order ): array {
			$settlement = $this->popular_settlement_store->recall_candidate( $order );

			if ( null === $settlement ) {
				return [ null, null ];
			}

			$provider = Location_Provider_Registry::instance()->get_providers()[ $settlement->provider_id() ] ?? null;

			return [ $settlement, $provider ];
		}

		/**
		 * Resolves a post id / post / order to a {@see \WC_Order}, or null.
		 *
		 * @since 1.5.0
		 *
		 * @param int|\WP_Post|\WC_Order|null $value order id, post object, or order object
		 * @return \WC_Order|null
		 */
		private function resolve_order( $value ): ?\WC_Order {

			if ( $value instanceof \WC_Order ) {
				return $value;
			}

			$order = wc_get_order( $value instanceof \WP_Post ? $value->ID : $value );

			return $order instanceof \WC_Order ? $order : null;
		}
	}

endif;
