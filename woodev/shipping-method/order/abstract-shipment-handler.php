<?php
/**
 * Woodev Abstract Shipment Handler
 *
 * Base class for the order→carrier shipment lifecycle: export an order to the
 * carrier, persist the carrier-assigned order id, and cancel a shipment. It
 * drives the carrier seam {@see \Woodev\Framework\Shipping\Api\Shipping_API::create_order()}
 * / {@see \Woodev\Framework\Shipping\Api\Shipping_API::cancel_order()} and routes the
 * carrier-assigned id through {@see \Woodev\Framework\Shipping\Order\Shipping_Order_Handler}
 * so it is stored under the plugin's own installed-site order-meta key. The
 * carrier's raw response shape never leaks past this class: each concrete carrier
 * implements only {@see self::extract_carrier_order_id()}.
 *
 * An export happens at most once per order (#945): an order that already has a carrier id is
 * not sent again, the call runs under a per-order lock, and a failure that does not tell whether
 * the carrier created the order is kept as «unknown» and reconciled — see {@see self::export()}.
 * A failed export the carrier may still complete is not lost: {@see Export_Retry} schedules another
 * attempt through WooCommerce's Action Scheduler, with a growing pause and at most five attempts in
 * all (#954). Lifecycle events are broadcast through forward-only, plugin-namespaced action
 * hooks (`woodev_shipping_{prefix}_shipment_*`); no installed-site contract string
 * — no shipping-method id, no existing hook name — is introduced here (the retry's own hook,
 * group and meta key live in {@see Export_Retry}).
 *
 * See docs-internal/platform-v2-s1-shipping-spec.md §4.3.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
use Woodev\Framework\Shipping\Api\Shipping_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Abstract_Shipment_Handler' ) ) :

	/**
	 * Exports, persists, cancels, and retries a shipment against a carrier.
	 *
	 * A carrier constructs the handler with the API seam and the order-meta handler;
	 * a failed export is retried by the framework ({@see Export_Retry}).
	 * Concrete carriers implement only the response→carrier-id mapping
	 * ({@see self::extract_carrier_order_id()}); everything else is carrier-neutral.
	 *
	 * @since 1.5.0
	 */
	abstract class Abstract_Shipment_Handler {

		/** @var string logical order-meta field, resolved by the order handler to the plugin's real carrier-order-id meta key */
		protected const CARRIER_ORDER_ID_FIELD = 'carrier_order_id';

		/**
		 * The order meta that says «the carrier may have created this order, but the answer was
		 * lost» (#945): the unix time of the FIRST failed attempt. Present from a transport failure
		 * of {@see self::export()} until an export succeeds or is reconciled.
		 *
		 * Installed-site data contract (a meta key of the framework's own): keep byte-for-byte.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const EXPORT_UNKNOWN_META = '_woodev_shipment_export_unknown';

		/** @var string scope of the per-order export lock, see {@see Order_Lock} */
		private const EXPORT_LOCK_SCOPE = 'export';

		/** @var Shipping_API carrier API seam */
		protected Shipping_API $api;

		/** @var Shipping_Order_Handler HPOS-safe order-meta accessor for the plugin's keys */
		protected Shipping_Order_Handler $order_handler;

		/** @var string plugin-supplied token that namespaces this handler's forward hooks */
		protected string $hook_prefix;

		/**
		 * Popular-settlements store (#488) used to bump usage on a successful
		 * export.
		 *
		 * Always non-null after construction (round 3, HIGH 1): when the caller
		 * does not inject one, the constructor defaults to the framework's own
		 * shared instance — {@see Location_Provider_Registry::popular_settlement_store()}
		 * — instead of `null`. No production construction site in this repo ever
		 * supplies a store (a carrier plugin constructs both this class and
		 * {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order}, and the
		 * framework structurally cannot inject the store at that call site), so an
		 * optional-but-null-means-off dependency left the feature permanently
		 * unreachable. `$settlement`/`$provider` on {@see self::export()} remain
		 * independently optional and default to null, so a caller that never
		 * passes them still sees no behaviour change — only a caller that DOES
		 * resolve them (the one real framework caller,
		 * {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order::handle_order_action()})
		 * now enrols out of the box.
		 *
		 * @var Popular_Settlement_Store
		 */
		protected Popular_Settlement_Store $popular_settlement_store;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Added `$popular_settlement_store` (#488 slice 2) — optional,
		 *              defaults to null so an existing call site's behaviour is unchanged.
		 * @since 2.0.2 Round 3 (HIGH 1): a null/omitted `$popular_settlement_store`
		 *              now defaults to the framework's shared instance
		 *              ({@see Location_Provider_Registry::popular_settlement_store()})
		 *              instead of leaving enrolment permanently disabled — an
		 *              explicit instance remains a genuine override (tests, or a
		 *              plugin that wants its own).
		 * @since 2.0.2 Card #954 (BREAKING, ADR-005): the `$retry_handler` parameter is gone. A
		 *              failed export is retried through the framework's own delayed Action
		 *              Scheduler action ({@see Export_Retry}), not through a plugin-supplied
		 *              background-job queue, which cannot delay. A carrier plugin drops the third
		 *              argument of its construction call.
		 *
		 * @param Shipping_API                  $api                      carrier API used to create/cancel orders
		 * @param Shipping_Order_Handler        $order_handler            order-meta accessor that persists the carrier id under the plugin's key
		 * @param string                        $hook_prefix              plugin-supplied token (e.g. the plugin id) that namespaces forward hooks; defaults to none
		 * @param Popular_Settlement_Store|null $popular_settlement_store popular-settlements store used to bump usage on a successful export; null resolves the framework's shared instance
		 */
		public function __construct(
			Shipping_API $api,
			Shipping_Order_Handler $order_handler,
			string $hook_prefix = '',
			?Popular_Settlement_Store $popular_settlement_store = null
		) {
			$this->api                      = $api;
			$this->order_handler            = $order_handler;
			$this->hook_prefix              = $hook_prefix;
			$this->popular_settlement_store = $popular_settlement_store ?? Location_Provider_Registry::instance()->popular_settlement_store();
		}

		/**
		 * Exports an order to the carrier and persists the carrier-assigned id — once.
		 *
		 * An order that already carries a carrier id is NOT sent again: the stored id comes back as
		 * a success, with no API call and no export hook (#945). The call itself runs under a
		 * per-order MySQL named lock ({@see Order_Lock}), so two simultaneous requests (a double
		 * click, a bulk run against a retry job) cannot both reach the carrier; the loser gets a
		 * failure, makes no API call and schedules no retry. The stored id is read AGAIN under the
		 * lock, from a fresh copy of the order, so a request that lost the race to an export that
		 * has since finished returns that export's id.
		 *
		 * Otherwise it calls {@see Shipping_API::create_order()}, maps the response to the carrier
		 * order id via {@see self::extract_carrier_order_id()}, and stores it through the order
		 * handler under the plugin's own meta key. A failed call is classified by
		 * {@see self::is_transport_failure()}:
		 *
		 * - the carrier REFUSED (an HTTP 4xx, a parsed carrier error): the order was not created.
		 *   A failed {@see Action_Result} carries the carrier's own text; nothing is retried —
		 *   a retry of a refusal repeats the refusal.
		 * - the carrier did not ANSWER (timeout, connection error, 5xx, empty response): it MAY have
		 *   created the order, so the order is marked «export state unknown»
		 *   ({@see self::EXPORT_UNKNOWN_META}, kept until an export succeeds or is reconciled). A carrier
		 *   that can look an order up ({@see self::supports_reconcile()}) gets the export retried
		 *   later by {@see Export_Retry} (1 min, 5 min, 30 min, 2 h — five attempts in all, then a
		 *   note on the order); the retry, like any next export of an unknown order,
		 *   asks {@see self::find_exported_order()} FIRST and only creates an order when the carrier
		 *   has none. A carrier that cannot look up gets no automatic retry and a failure text that
		 *   tells the merchant to check the carrier's account; a MANUAL export stays allowed.
		 * - the carrier answered HTTP 429 ({@see \Woodev_API_Rate_Limit_Exception}): it is throttling
		 *   us and created nothing. Not a refusal and not «unknown» — the export is retried by
		 *   {@see Export_Retry} after the `Retry-After` wait the carrier asked for (the standard
		 *   schedule when it gave none), for any carrier, and counts towards the same five attempts.
		 *
		 * A successful export with a NON-EMPTY carrier order id is also "an order
		 * shipped to this settlement" (#488 popular-settlements spec D2) — the
		 * strongest available signal that the shop is genuinely committed to
		 * shipping there, stronger than merely placing the order (which can still be
		 * cancelled/refunded before ever reaching a carrier). When both `$settlement`
		 * and `$provider` are given, {@see self::enroll_popular_settlement()} bumps it
		 * (against the constructor's store — round 3, HIGH 1: a framework default
		 * now, not an opt-in) after the `shipment_exported` hook fires. Both default
		 * to null: an existing call site that does not pass them sees no behaviour
		 * change.
		 *
		 * A create call that RETURNS without an exception but yields an EMPTY carrier id is
		 * «unknown», like a transport failure (#945): the request went out and was not refused,
		 * so the carrier may well have created the order. No empty id is stored, no export hook
		 * fires and nothing is enrolled; the failure text and the retry follow the same rules
		 * as for a transport failure.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Added `$settlement` / `$provider` (#488 slice 2) to enrol the
		 *              order's settlement into the popular-settlements list.
		 * @since 2.0.2 Round 2 (MEDIUM 3): enrolment additionally requires a
		 *              non-empty `$carrier_order_id` — a non-throwing response with
		 *              no id is not evidence of a real export.
		 * @since 2.0.2 Also fires the unprefixed, framework-wide
		 *              `woodev_shipping_order_exported` action so framework code
		 *              (e.g. {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry})
		 *              can react without depending on `$hook_prefix` (#853).
		 * @since 2.0.2 Card #872: returns an {@see Action_Result} instead of the bare
		 *              carrier order id, so a failure can carry the carrier's text.
		 * @since 2.0.2 Card #954: the carrier calls run under the `export` request purpose (30 s), a
		 *              retry is DELAYED and capped at five attempts ({@see Export_Retry}), and an HTTP 429
		 *              is retried after its `Retry-After`. BEHAVIOUR CHANGE (ADR-005): the retry no
		 *              longer goes through a plugin-supplied background-job queue.
		 * @since 2.0.2 Card #945: exports at most once — an already exported order is not sent
		 *              again, the call runs under a per-order lock, and a failure that does not
		 *              tell whether the carrier created the order is kept as «unknown» and
		 *              reconciled. BEHAVIOUR CHANGE (ADR-005): a failure is no longer always
		 *              queued for retry — a carrier refusal is not retried at all, and a
		 *              transport failure is retried only by a carrier that can reconcile.
		 *              A response with NO carrier id is no longer stored as `''` with the export
		 *              hooks fired: it is an «unknown» failure and fires neither hook.
		 *
		 * @param \WC_Order              $order      the order to export to the carrier
		 * @param Location_Record|null   $settlement the settlement this order ships to, if known; null skips enrolment
		 * @param Location_Provider|null $provider the provider that produced `$settlement`, if known; null skips enrolment
		 * @return Action_Result success carrying the carrier-assigned order id, or a failure
		 */
		public function export( \WC_Order $order, ?Location_Record $settlement = null, ?Location_Provider $provider = null ): Action_Result {

			$stored = $this->stored_carrier_order_id( $order );

			if ( '' !== $stored ) {
				return Action_Result::success( $stored );
			}

			$order_id = $order->get_id();

			if ( ! $this->acquire_export_lock( $order_id ) ) {
				return Action_Result::failure( __( 'Этот заказ уже выгружается — дождитесь окончания.', 'woodev-plugin-framework' ) );
			}

			try {
				// The carrier call and the lookup before it are an export: they get the «export» timeout (#954).
				return \Woodev_API_Request_Purpose::run(
					\Woodev_API_Request_Purpose::EXPORT,
					fn() => $this->export_locked( $order, $settlement, $provider )
				);
			} finally {
				$this->release_export_lock( $order_id );
			}
		}

		/**
		 * The export proper, run while the order's export lock is held.
		 *
		 * `$fresh` — the order as it is in the datastore NOW — is what the stored id and the
		 * «unknown» flag are read from and written to; `$order` is the caller's copy, the one the
		 * carrier call and the hooks get.
		 *
		 * @since 2.0.2 Card #945.
		 *
		 * @param \WC_Order              $order      the order to export
		 * @param Location_Record|null   $settlement the settlement this order ships to, if known
		 * @param Location_Provider|null $provider   the provider that produced `$settlement`, if known
		 * @return Action_Result
		 */
		private function export_locked( \WC_Order $order, ?Location_Record $settlement, ?Location_Provider $provider ): Action_Result {

			// What the datastore holds NOW: a request that waited for the lock must see the export that just finished.
			$fresh  = $this->fresh_order( $order );
			$stored = $this->stored_carrier_order_id( $fresh );

			if ( '' !== $stored ) {
				return Action_Result::success( $stored );
			}

			if ( $this->supports_reconcile() && $this->export_unknown_since( $fresh ) > 0 ) {

				try {
					$found = (string) $this->find_exported_order( $fresh );
				} catch ( \Woodev_API_Exception $exception ) {
					// The carrier could not say either way: do NOT create — the state stays unknown.
					return $this->fail_export( $order, $fresh, $exception );
				}

				if ( '' !== $found ) {
					return $this->complete_export( $order, $fresh, $found, $settlement, $provider );
				}
			}

			try {
				$response = $this->api->create_order( $order );
			} catch ( \Woodev_API_Exception $exception ) {
				return $this->fail_export( $order, $fresh, $exception );
			}

			$carrier_order_id = $this->extract_carrier_order_id( $response );

			if ( '' === $carrier_order_id ) {
				// The request went out and was not refused, yet no id came back: the carrier may well have created the order.
				return $this->fail_export(
					$order,
					$fresh,
					new \Woodev_API_Transport_Exception( __( 'Ответ перевозчика не содержит номера заказа', 'woodev-plugin-framework' ) ),
					true
				);
			}

			return $this->complete_export( $order, $fresh, $carrier_order_id, $settlement, $provider );
		}

		/**
		 * Records a successful export (or a reconciled one): stores the id, clears the unknown
		 * state and fires the export hooks.
		 *
		 * @since 2.0.2 Card #945: the body of the old success path of {@see self::export()}.
		 *
		 * @param \WC_Order              $order            the caller's copy of the exported order
		 * @param \WC_Order              $fresh            the order as {@see self::fresh_order()} read it
		 * @param string                 $carrier_order_id the carrier-assigned id (never empty)
		 * @param Location_Record|null   $settlement       the settlement this order ships to, if known
		 * @param Location_Provider|null $provider         the provider that produced `$settlement`, if known
		 * @return Action_Result
		 */
		private function complete_export( \WC_Order $order, \WC_Order $fresh, string $carrier_order_id, ?Location_Record $settlement, ?Location_Provider $provider ): Action_Result {

			$this->order_handler->set( $order, static::CARRIER_ORDER_ID_FIELD, $carrier_order_id );
			$this->clear_export_unknown( $order, $fresh );
			Export_Retry::reset( $fresh, $order );

			/**
			 * Fires after an order is successfully exported to the carrier.
			 *
			 * This is the PLUGIN-facing extension point: its name carries the
			 * plugin's own `$hook_prefix`, so a plugin subscribes to its own exports
			 * only. Framework code that must react to every export regardless of
			 * which plugin produced it uses `woodev_shipping_order_exported` below
			 * instead. Also fires when an export that had timed out is reconciled —
			 * the carrier's order is found and stored, and no second one is created.
			 * Never fires without a carrier order id (#945): a create call that returns
			 * no id is an «unknown» export, not a success.
			 *
			 * @since 1.5.0
			 *
			 * @param \WC_Order $order            the exported order
			 * @param string    $carrier_order_id the carrier-assigned order id now stored on the order
			 */
			do_action( $this->hook( 'shipment_exported' ), $order, $carrier_order_id );

			/**
			 * Fires after ANY carrier's shipment export, framework-wide.
			 *
			 * Unlike the plugin-facing hook above, this action name never varies
			 * with `$hook_prefix` — each plugin sets that independently, so the
			 * framework cannot subscribe to a fixed hook built from it. This is the
			 * framework's OWN internal notification (e.g.
			 * {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::flush_new_order_counts()}
			 * uses it to drop the cached "new orders" badge count). Never fires
			 * without a carrier order id, same as the plugin-facing hook above.
			 *
			 * @since 2.0.2
			 *
			 * @param \WC_Order $order            the exported order
			 * @param string    $carrier_order_id the carrier-assigned order id now stored on the order
			 */
			do_action( 'woodev_shipping_order_exported', $order, $carrier_order_id );

			$this->enroll_popular_settlement( $settlement, $provider );

			return Action_Result::success( $carrier_order_id );
		}

		/**
		 * Handles a failed carrier call: classifies it, keeps the «unknown» state, decides on a retry.
		 *
		 * @since 2.0.2 Card #945.
		 *
		 * @param \WC_Order             $order     the caller's copy of the order whose export failed
		 * @param \WC_Order             $fresh     the order as {@see self::fresh_order()} read it
		 * @param \Woodev_API_Exception $exception the carrier/network failure
		 * @param bool                  $unknown   true to treat the failure as «unknown» whatever {@see self::is_transport_failure()} says (a create call that returned no id)
		 * @return Action_Result
		 */
		private function fail_export( \WC_Order $order, \WC_Order $fresh, \Woodev_API_Exception $exception, bool $unknown = false ): Action_Result {

			$transport_failure = $unknown || $this->is_transport_failure( $exception );
			$can_reconcile     = $this->supports_reconcile();
			$rate_limited      = $exception instanceof \Woodev_API_Rate_Limit_Exception;
			$text              = self::exception_text( $exception );

			if ( $transport_failure ) {
				$this->mark_export_unknown( $fresh );
			}

			// A transport failure of a carrier that can reconcile, or a 429 (nothing was created), is worth another attempt.
			$retryable = $rate_limited || ( $transport_failure && $can_reconcile );
			$outcome   = '';

			if ( $retryable ) {
				$outcome = Export_Retry::after_failure( $fresh, $rate_limited ? $exception->get_retry_after() : null, $text );
			} else {
				Export_Retry::reset( $fresh, $order );
			}

			/**
			 * Fires when an order export to the carrier fails.
			 *
			 * The export is retried — later, with a growing pause and at most five attempts in all
			 * ({@see Export_Retry}) — only after a transport failure of a carrier that can reconcile
			 * ({@see self::supports_reconcile()}) or an HTTP 429 ({@see \Woodev_API_Rate_Limit_Exception});
			 * a refusal, or a carrier that cannot look an order up, is not retried automatically
			 * (#945). A create call that returned without an id fires it too, with a
			 * {@see \Woodev_API_Transport_Exception}.
			 *
			 * @since 1.5.0
			 *
			 * @param \WC_Order            $order     the order whose export failed
			 * @param \Woodev_API_Exception $exception the carrier/network failure
			 */
			do_action( $this->hook( 'shipment_export_failed' ), $order, $exception );

			if ( Export_Retry::GAVE_UP === $outcome ) {
				// The attempts ran out: the order carries the note, and the merchant is told the same.
				return Action_Result::failure( Export_Retry::give_up_text( $text ) );
			}

			if ( $rate_limited && Export_Retry::SCHEDULED === $outcome ) {
				return Action_Result::failure(
					__( 'Перевозчик временно ограничил число запросов. Выгрузка будет повторена автоматически.', 'woodev-plugin-framework' )
				);
			}

			if ( $unknown && ! $can_reconcile ) {
				// The carrier DID answer (no id in it), so the «did not answer» wording of a transport failure would be untrue.
				return Action_Result::failure(
					__( 'Перевозчик ответил без номера заявки — заявка могла быть создана. Проверьте личный кабинет перевозчика перед повторной выгрузкой.', 'woodev-plugin-framework' )
				);
			}

			if ( $transport_failure && ! $can_reconcile ) {
				return Action_Result::failure(
					sprintf(
						/* translators: %s: the transport error, e.g. a timeout */
						__( 'Перевозчик не ответил (%s). Заказ мог быть создан у него — проверьте личный кабинет перевозчика, прежде чем выгружать снова.', 'woodev-plugin-framework' ),
						$text
					)
				);
			}

			return Action_Result::failure( $text );
		}

		/**
		 * Whether a failed carrier call leaves it UNKNOWN if the carrier created the order.
		 *
		 * `true` for a transport-level failure — the HTTP transport failed (timeout, connection
		 * refused, DNS), or the server answered with a 5xx or an empty response
		 * ({@see \Woodev_API_Transport_Exception}); the carrier may have created the order
		 * before the answer was lost. `false` for a carrier-level one — an HTTP 4xx or a parsed
		 * carrier error: the carrier refused, so nothing was created. An exception that is not a
		 * {@see \Woodev_API_Transport_Exception} — the API base re-types only a plain
		 * {@see \Woodev_API_Exception}, a subclass keeps its class. A plain one that reached here is
		 * a refusal (its code may be a carrier error code); a SUBCLASS is read by the HTTP status it
		 * carries as its code: 5xx is transport-level. A carrier whose API signals a transport
		 * problem some other way overrides this.
		 *
		 * @since 2.0.2 Card #945.
		 *
		 * @param \Woodev_API_Exception $exception the failure to classify.
		 * @return bool
		 */
		protected function is_transport_failure( \Woodev_API_Exception $exception ): bool {

			if ( $exception instanceof \Woodev_API_Transport_Exception ) {
				return true;
			}

			// A plain exception is re-typed by the API base on a real transport failure, so its code may be a carrier error code — never read it as a status.
			if ( \Woodev_API_Exception::class === get_class( $exception ) ) {
				return false;
			}

			// A subclass is not re-typed by the API base (it keeps its class), so it is read by the HTTP status it carries.
			$code = (int) $exception->getCode();

			return $code >= 500 && $code < 600;
		}

		/**
		 * Whether this carrier can look an already-created order up on its side (card #945).
		 *
		 * `false` by default. A carrier overriding this to `true` must also override
		 * {@see self::find_exported_order()}: the export of an order whose state is unknown is
		 * then retried automatically, and asks the carrier FIRST whether it already has the order.
		 * Mirrors {@see self::supports_update()}.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function supports_reconcile(): bool {
			return false;
		}

		/**
		 * Finds the carrier order an earlier, timed-out export created — by whatever the carrier
		 * lets it be found (the shop's order number, the order id sent as the carrier's
		 * «external id», …).
		 *
		 * Only asked for an order in the «export state unknown» state, and only when
		 * {@see self::supports_reconcile()} is true. Return the carrier's order id when the carrier
		 * has the order — it is stored, the unknown state is cleared, the success hooks fire and
		 * NO second order is created. Return null when the carrier has no such order — the export
		 * then creates it. «Not found» MUST return null and never throw: an exception is read as
		 * «the carrier could not answer», so a lookup whose 404 surfaces as an exception leaves the
		 * order unknown and blocks the creation for good. Throw {@see \Woodev_API_Exception} only
		 * when the carrier could not answer: nothing is created, and the state stays unknown.
		 *
		 * @since 2.0.2 Card #945.
		 *
		 * @param \WC_Order $order the order whose export state is unknown.
		 * @return string|null the carrier-assigned order id, or null when the carrier has none.
		 * @throws \Woodev_API_Exception when the carrier could not say either way.
		 */
		public function find_exported_order( \WC_Order $order ): ?string {
			return null;
		}

		/**
		 * When the order's export last ended in an unknown state (unix time), or 0 when it is not.
		 *
		 * @since 2.0.2 Card #945.
		 *
		 * @param \WC_Order $order the order to read.
		 * @return int
		 */
		public function export_unknown_since( \WC_Order $order ): int {
			return (int) $order->get_meta( self::EXPORT_UNKNOWN_META );
		}

		/**
		 * Marks the order's export as «unknown», keeping the time of the FIRST failure.
		 *
		 * @since 2.0.2 Card #945.
		 *
		 * @param \WC_Order $fresh the order as {@see self::fresh_order()} read it — never a caller's possibly stale copy.
		 * @return void
		 */
		private function mark_export_unknown( \WC_Order $fresh ): void {

			if ( $this->export_unknown_since( $fresh ) > 0 ) {
				return;
			}

			$fresh->update_meta_data( self::EXPORT_UNKNOWN_META, time() );
			$fresh->save_meta_data();
		}

		/**
		 * Clears the «export unknown» state — the order now has its carrier id.
		 *
		 * @since 2.0.2 Card #945.
		 *
		 * @param \WC_Order $order the caller's copy of the exported order.
		 * @param \WC_Order $fresh the order as {@see self::fresh_order()} read it; the flag is deleted here, so it never survives beside a stored id.
		 * @return void
		 */
		private function clear_export_unknown( \WC_Order $order, \WC_Order $fresh ): void {

			// The flag is deleted on the FRESH object: `delete_meta_data()` on a caller's object that never saw the flag is a no-op.
			$fresh->delete_meta_data( self::EXPORT_UNKNOWN_META );
			$fresh->save_meta_data();

			if ( $fresh !== $order ) {
				// Keep the caller's in-memory copy honest for the export hooks that run next.
				$order->delete_meta_data( self::EXPORT_UNKNOWN_META );
				$order->save_meta_data();
			}
		}

		/**
		 * The carrier order id stored on the order, or an empty string.
		 *
		 * @since 2.0.2 Card #945.
		 *
		 * @param \WC_Order $order the order to read.
		 * @return string
		 */
		private function stored_carrier_order_id( \WC_Order $order ): string {
			return (string) $this->order_handler->get( $order, static::CARRIER_ORDER_ID_FIELD );
		}

		/**
		 * The order re-read from the datastore, or the given one when it cannot be.
		 *
		 * Really fresh on both datastores: `wc_get_order()` alone hands back what the request
		 * cached when it loaded the order, so the post-meta cache and the object's own meta are
		 * dropped too. Protected as the seam a unit test overrides.
		 *
		 * @since 2.0.2 Card #945.
		 *
		 * @param \WC_Order $order the order.
		 * @return \WC_Order
		 */
		protected function fresh_order( \WC_Order $order ): \WC_Order {

			$order_id = $order->get_id();
			$reread   = wc_get_order( $order_id );

			if ( ! $reread instanceof \WC_Order ) {
				return $order;
			}

			// wc_get_order() alone is served from request-local caches: it returns what THIS request saw at load time.
			// The carrier id is read through get_post_meta() on the legacy post store and through the object on HPOS,
			// so both the post-meta cache and the object's own meta are dropped.
			wp_cache_delete( $order_id, 'post_meta' );
			$reread->read_meta_data( true );

			return $reread;
		}

		/**
		 * Takes the order's export lock without waiting: a second export of the same order is
		 * refused, not queued behind the first.
		 *
		 * Protected as the seam a unit test overrides.
		 *
		 * @since 2.0.2 Card #945.
		 *
		 * @param int $order_id the order.
		 * @return bool whether the lock is held.
		 */
		protected function acquire_export_lock( int $order_id ): bool {
			return Order_Lock::acquire( self::EXPORT_LOCK_SCOPE, $order_id, 0 );
		}

		/**
		 * Releases the lock taken by {@see self::acquire_export_lock()}.
		 *
		 * @since 2.0.2 Card #945.
		 *
		 * @param int $order_id the order.
		 * @return void
		 */
		protected function release_export_lock( int $order_id ): void {
			Order_Lock::release( self::EXPORT_LOCK_SCOPE, $order_id );
		}

		/**
		 * The carrier's text out of a failed API call, safe to show the merchant.
		 *
		 * Secrets are redacted with the same helper the logs use, so a token echoed
		 * back in an error body cannot reach the notice either. The text is for the
		 * MERCHANT only and is never stored on the order (#608/#610) — see
		 * {@see Action_Result}.
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev_API_Exception $exception the carrier/network failure
		 * @return string
		 */
		private static function exception_text( \Woodev_API_Exception $exception ): string {
			return \Woodev_API_Base::redact_secret_log_text( $exception->getMessage() );
		}

		/**
		 * Bumps the popular-settlements list (#488) for a successfully exported
		 * order's settlement.
		 *
		 * A silent no-op — never throws — whenever any prerequisite is missing:
		 * the caller does not know the settlement, or the caller does not know
		 * which provider produced it. The D4/D4a gates themselves
		 * ({@see Popular_Settlement_Store::CAPABILITY_RESOLVE_KEY} capability,
		 * {@see \Woodev\Framework\Shipping\Location\Locality_Key::is_derived()})
		 * live in {@see Popular_Settlement_Store::enroll()}, not here.
		 *
		 * `enroll()` itself can still throw — most notably when `$settlement`'s
		 * own `provider_id()` disagrees with `$provider->get_id()`. This is caught
		 * and logged, never left to propagate (round 3, HIGH 2): by the time this
		 * runs the carrier order already exists (`export()` has already persisted
		 * the carrier id and fired `shipment_exported`), so enrolment — a ranking
		 * side-effect — must never undo or fail a real export over a popularity
		 * row it could not write.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Round 3 (HIGH 2): swallow-and-log any `enroll()` failure
		 *              instead of letting it propagate out of {@see self::export()}.
		 *
		 * @param Location_Record|null   $settlement the settlement this order ships to, if known
		 * @param Location_Provider|null $provider   the provider that produced `$settlement`, if known
		 *
		 * @return void
		 */
		protected function enroll_popular_settlement( ?Location_Record $settlement, ?Location_Provider $provider ): void {
			if ( null === $settlement || null === $provider ) {
				return;
			}

			try {
				$this->popular_settlement_store->enroll( $provider, $settlement );
			} catch ( \Throwable $throwable ) {
				error_log(
					sprintf(
						'[woodev] popular-settlements enrolment failed for provider "%s": %s',
						$provider->get_id(),
						\Woodev_API_Base::redact_secret_log_text( $throwable->getMessage() )
					)
				); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- loud-but-contained boundary; enrolment is a ranking side-effect and must never undo/fail an export whose carrier order already exists.
			}
		}

		/**
		 * Cancels an order's shipment with the carrier.
		 *
		 * Reads the stored carrier order id through the order handler and calls
		 * {@see Shipping_API::cancel_order()}. Returns a failure (without calling the
		 * carrier) when the order has no stored carrier id, and a failure carrying the
		 * carrier's text when the carrier rejects the cancellation.
		 *
		 * On a SUCCESSFUL cancellation the stored carrier order id is cleared (written
		 * back through the same order handler {@see self::export()} uses to set it),
		 * before the `shipment_cancelled` action fires. The framework does not own any
		 * carrier's status vocabulary, but it does own "is there a live carrier
		 * shipment for this order" — and after an accepted cancellation the answer is
		 * no. Clearing the id flips {@see \Woodev\Framework\Shipping\Order\Order_Actions::for_order()}'s
		 * export-state gate back to "not exported": «Отменить»/«Обновить» disappear and
		 * «Выгрузить» returns if the WC order status still allows it. A failed
		 * cancellation (carrier rejects, or the API throws) leaves the stored id
		 * untouched, so the button remains available for a retry.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Round 2 (HIGH 1): clear the stored carrier order id on a
		 *              successful cancel, so a merchant cannot send the same
		 *              destructive carrier cancellation twice.
		 * @since 2.0.2 Card #872: returns an {@see Action_Result}; a rejection carries
		 *              the carrier's text, a missing stored id carries none.
		 *
		 * @param \WC_Order $order the order whose shipment to cancel
		 * @return Action_Result success when the carrier accepted the cancellation, a failure otherwise
		 */
		public function cancel( \WC_Order $order ): Action_Result {

			$carrier_order_id = (string) $this->order_handler->get( $order, static::CARRIER_ORDER_ID_FIELD );

			if ( '' === $carrier_order_id ) {
				return Action_Result::failure();
			}

			try {
				\Woodev_API_Request_Purpose::run(
					\Woodev_API_Request_Purpose::EXPORT,
					function () use ( $carrier_order_id ): void {
						$this->api->cancel_order( $carrier_order_id );
					}
				);
			} catch ( \Woodev_API_Exception $exception ) {

				/**
				 * Fires when a shipment cancellation request to the carrier fails.
				 *
				 * @since 1.5.0
				 *
				 * @param \WC_Order            $order     the order whose cancellation failed
				 * @param \Woodev_API_Exception $exception the carrier/network failure
				 */
				do_action( $this->hook( 'shipment_cancel_failed' ), $order, $exception );

				return Action_Result::failure( self::exception_text( $exception ) );
			}

			$this->order_handler->set( $order, static::CARRIER_ORDER_ID_FIELD, '' );

			/**
			 * Fires after a shipment is successfully cancelled with the carrier.
			 *
			 * @since 1.5.0
			 *
			 * @param \WC_Order $order            the cancelled order
			 * @param string    $carrier_order_id the carrier-assigned order id that WAS cancelled
			 *                                    (the stored id has already been cleared by the time
			 *                                    this fires; this argument still carries the value
			 *                                    that was just cancelled, for subscribers that need it)
			 */
			do_action( $this->hook( 'shipment_cancelled' ), $order, $carrier_order_id );

			return Action_Result::success();
		}

		/**
		 * Whether this carrier can refresh one order's state from its own API (card
		 * #824). `false` by default — the framework has NO generic pull of a
		 * carrier's state; that sync is SP-8 (spec §D4). A carrier overriding this to
		 * `true` must also override {@see self::update()}; until it does, «Обновить»
		 * is not offered at all rather than offered and dead
		 * (see {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::for_order()}).
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function supports_update(): bool {
			return false;
		}

		/**
		 * Pulls the carrier's current state for one order and syncs it locally.
		 *
		 * Inert by default — see {@see self::supports_update()}. An overriding
		 * carrier is responsible for its OWN hooks around the refresh (this base
		 * fires none): the framework has no generic "shipment updated" event to fire
		 * on a sync it did not itself define.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Card #872: returns an {@see Action_Result}, so a refusal can
		 *              carry the carrier's text.
		 *
		 * @param \WC_Order $order the order to refresh.
		 * @return Action_Result success when the refresh succeeded, a failure otherwise.
		 */
		public function update( \WC_Order $order ): Action_Result {
			return Action_Result::failure();
		}

		/**
		 * Maps a carrier create-order response to the carrier-assigned order id.
		 *
		 * Each carrier returns the id in a different place in its response, so the
		 * concrete handler extracts it; the base class only knows the result is the
		 * string id to persist on the order.
		 *
		 * @since 1.5.0
		 *
		 * @param \Woodev_API_Response $response the create-order response from the carrier
		 * @return string the carrier-assigned order id
		 */
		abstract protected function extract_carrier_order_id( \Woodev_API_Response $response ): string;

		/**
		 * Builds a namespaced forward-hook name.
		 *
		 * @since 1.5.0
		 *
		 * @param string $name bare hook suffix
		 * @return string the full hook name, e.g. `woodev_shipping_{prefix}_{name}`
		 */
		protected function hook( string $name ): string {

			$prefix = '' !== $this->hook_prefix ? $this->hook_prefix . '_' : '';

			return 'woodev_shipping_' . $prefix . $name;
		}
	}

endif;
