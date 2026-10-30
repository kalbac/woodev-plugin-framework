<?php
/**
 * Woodev Session Key Merger
 *
 * Keeps a framework-owned `WC()->session` key that ANOTHER request saved while this one was
 * running from being written back stale by WooCommerce's whole-row session save (#1381).
 *
 * @since 2.0.15
 */

namespace Woodev\Framework\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Session_Key_Merger' ) ) :

	/**
	 * A per-key merge of the framework's own session keys, run just before WooCommerce saves the session.
	 *
	 * THE RACE. `WC_Session_Handler` loads the whole session row when a request starts and, on
	 * `shutdown` (priority 20, `init_hooks()`), writes the whole row back when anything in it changed.
	 * A classic `update_order_review` that rates live takes 1–3 s; a `/location/select` or a pickup
	 * `/select` sent meanwhile answers in ~100 ms and saves first. When the slow request ends it writes
	 * back the row as IT loaded it — the shopper's new city or pickup point is gone, although both
	 * routes answered «saved» (measured 5/5 on WooCommerce 11.2.1, 2/7 through the real widget;
	 * `docs-internal/research/2026-10-26-classic-session-race-1381/report.md`).
	 *
	 * THE FIX. The framework's writers ({@see \Woodev\Framework\Shipping\Location\Customer_Location_Store},
	 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Selection}) tell this class which of their keys the
	 * current request READ and which it WROTE. One `shutdown` callback, hooked at
	 * {@see self::PRIORITY} (one before WooCommerce's save), re-reads the session row straight from
	 * `{$wpdb->prefix}woocommerce_sessions` — not through `WC_Session_Handler::get_session()`, which
	 * answers from the object cache the stale request itself filled — and, for every framework key this
	 * request touched but did NOT write, adopts the stored value into `WC()->session`: the concurrent
	 * write wins, a key the other request removed is removed here too. A key this request wrote keeps
	 * this request's value — same key, two writers, last writer wins, which is also what WooCommerce
	 * 11.4 does. Residual window: between this re-read and WooCommerce's write, milliseconds instead of
	 * the request's full duration.
	 *
	 * WHEN IT RUNS, AND WHEN IT DOES NOTHING.
	 *
	 * - Only for the keys a request touched, plus the location record ALWAYS
	 *   ({@see self::protect_location_record()}). The pickup keys are coined by each plugin's
	 *   {@see \Woodev\Framework\Shipping\Pickup\Selection_Scope}, so a request that never read one
	 *   cannot be told to protect it; the record's key is fixed, and a request that saves the session
	 *   without rating shipping (`wc-ajax=apply_coupon` leaving a notice) would otherwise write back a
	 *   stale copy of it (kalbac/woocommerce-yandex-delivery#150). The query is still paid only by a
	 *   request that is about to SAVE the session — the gates below run before it — and on WooCommerce
	 *   11.4+ nothing is hooked at all.
	 * - Only while WooCommerce does not merge per key itself: from 11.4 (woocommerce#69559) `save_data()`
	 *   applies only the keys the request changed, and this class steps aside. The gate is the ABSENCE of
	 *   that class, not a version string, so it tracks the shipped code.
	 * - Only for WooCommerce's own `WC_Session_Handler`, by exact class: a custom handler installed through
	 *   `woocommerce_session_handler` (Redis sessions, the Store API cart-token handler) may not keep its
	 *   rows in that table at all, and reading a row that is not theirs would delete keys that are.
	 * - Only when `has_session()` is true and the session is DIRTY — the two conditions
	 *   `WC_Session_Handler::save_data()` itself writes under. A session WooCommerce is not going to save
	 *   must be left clean: adopting a value through `set()` would mark it dirty and make WooCommerce
	 *   write the whole stale row, undoing EVERY concurrent change instead of just ours.
	 *
	 * The merge never touches a key it was not told about: WooCommerce's own keys (`chosen_shipping_methods`,
	 * `customer`, …) stay whole-row last-writer-wins on < 11.4, as today.
	 *
	 * @since 2.0.15
	 * @since 2.0.18 The location record is protected in every request that saves the session, not only in
	 *               one that read it ({@see self::protect_location_record()}).
	 */
	class Session_Key_Merger {

		/**
		 * The `shutdown` priority WooCommerce saves the session at
		 * (`WC_Session_Handler::init_hooks()`, `add_action( 'shutdown', [ $this, 'save_data' ], 20 )`).
		 *
		 * @since 2.0.15
		 */
		private const WC_SAVE_PRIORITY = 20;

		/**
		 * The `shutdown` priority this merge runs at — right before WooCommerce writes the row.
		 *
		 * @since 2.0.15
		 */
		public const PRIORITY = self::WC_SAVE_PRIORITY - 1;

		/**
		 * The class WooCommerce 11.4 merges session rows with (woocommerce#69559). While it exists,
		 * WooCommerce saves per key itself and this class does nothing.
		 *
		 * @since 2.0.15
		 */
		public const CORE_MERGE_CLASS = 'Automattic\\WooCommerce\\Internal\\Session\\SessionDataWriter';

		/**
		 * The session handler class this merge reads the sessions table for. Checked by EXACT name:
		 * a subclass may store elsewhere.
		 *
		 * @since 2.0.15
		 */
		private const CORE_HANDLER_CLASS = 'WC_Session_Handler';

		/**
		 * The session key of the customer's location record
		 * ({@see \Woodev\Framework\Shipping\Location\Customer_Location_Store}) — the one key every request
		 * is protected for, whether it read the key or not ({@see self::protect_location_record()}).
		 *
		 * @since 2.0.18
		 */
		public const LOCATION_RECORD_KEY = 'woodev_customer_location';

		/**
		 * The per-request instance.
		 *
		 * @since 2.0.15
		 * @var self|null
		 */
		private static ?Session_Key_Merger $instance = null;

		/**
		 * Every framework key this request read or wrote, `key => true`.
		 *
		 * @since 2.0.15
		 * @var array<string, bool>
		 */
		private array $touched = [];

		/**
		 * The subset of {@see self::$touched} this request WROTE, `key => true`.
		 *
		 * @since 2.0.15
		 * @var array<string, bool>
		 */
		private array $written = [];

		/**
		 * Whether {@see self::merge_before_save()} is hooked on `shutdown` yet.
		 *
		 * @since 2.0.15
		 * @var bool
		 */
		private bool $hooked = false;

		/**
		 * The per-request instance.
		 *
		 * @since 2.0.15
		 *
		 * @return self
		 */
		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Puts the customer's location record under the merge for THIS request, although the request
		 * never reads it (kalbac/woocommerce-yandex-delivery#150).
		 *
		 * The reads and writes of {@see Customer_Location_Store} report themselves, which covers every
		 * request that rates shipping. A request that saves the session WITHOUT rating — an AJAX call that
		 * only leaves a notice, such as `wc-ajax=apply_coupon` with a wrong code — holds a copy of the row
		 * from the moment it loaded, never reports the record, and is written back whole by WooCommerce
		 * < 11.4: a city picked while it ran is gone (measured on WooCommerce 11.2.1, a slow
		 * `apply_coupon` across `/location/select`: the record lost, 2 of 2).
		 *
		 * Called once per request by every shipping plugin that uses the Location Provider layer; calling it
		 * again changes nothing. A request that reads or writes the record afterwards is judged exactly as
		 * before: the write keeps its value, the read has been pending since here.
		 *
		 * Registers nothing when WooCommerce merges per key itself ({@see self::core_merges_per_key()}).
		 *
		 * @since 2.0.18
		 *
		 * @return void
		 */
		public static function protect_location_record(): void {
			$merger = self::instance();

			if ( $merger->core_merges_per_key() ) {
				return;
			}

			$merger->touch( self::LOCATION_RECORD_KEY, false );
		}

		/**
		 * Drops the per-request instance — its touched keys and its hook flag — or replaces it with
		 * `$replacement` (a probe whose seams a test controls). Tests only.
		 *
		 * @since 2.0.15
		 *
		 * @param self|null $replacement The instance the static notes go to from now on, or `null`
		 *                               for a fresh one on the next touch.
		 *
		 * @return void
		 */
		public static function reset_for_tests( ?Session_Key_Merger $replacement = null ): void {
			self::$instance = $replacement;
		}

		/**
		 * Records that this request READ a framework-owned session key.
		 *
		 * A read is what puts a key under protection: the request now holds a copy that WooCommerce
		 * will write back whole, so a concurrent write to that key must be re-read before the save.
		 *
		 * @since 2.0.15
		 *
		 * @param string $key The session key, as passed to `WC()->session->get()`.
		 *
		 * @return void
		 */
		public static function note_read( string $key ): void {
			self::instance()->touch( $key, false );
		}

		/**
		 * Records that this request WROTE a framework-owned session key — this request's value is
		 * the one that must survive, so the key is never merged.
		 *
		 * @since 2.0.15
		 *
		 * @param string $key The session key, as passed to `WC()->session->set()`.
		 *
		 * @return void
		 */
		public static function note_write( string $key ): void {
			self::instance()->touch( $key, true );
		}

		/**
		 * The keys this request touched but did not write — the ones {@see self::merge_before_save()}
		 * re-reads.
		 *
		 * @since 2.0.15
		 *
		 * @return string[]
		 */
		public function pending_keys(): array {
			return array_keys( array_diff_key( $this->touched, $this->written ) );
		}

		/**
		 * The keys this request wrote.
		 *
		 * @since 2.0.15
		 *
		 * @return string[]
		 */
		public function written_keys(): array {
			return array_keys( $this->written );
		}

		/**
		 * `shutdown` callback: re-reads the stored row and adopts, for every pending key, what another
		 * request saved since this one loaded the session.
		 *
		 * @since 2.0.15
		 *
		 * @return void
		 */
		public function merge_before_save(): void {
			$keys = $this->pending_keys();

			if ( [] === $keys ) {
				return;
			}

			$session = $this->session();

			if ( null === $session || ! $this->applies_to( $session ) ) {
				return;
			}

			$customer_id = (string) $session->get_customer_id();

			if ( '' === $customer_id ) {
				return;
			}

			$stored = $this->read_stored_row( $customer_id );

			// No row yet (a session saved for the first time, a guest just migrated onto an account) or
			// a failed read: there is nothing another request could have written, nothing to adopt.
			if ( null === $stored ) {
				return;
			}

			foreach ( $keys as $key ) {
				// `WC_Session::set()` with `null` removes the key; with an equal value it changes nothing.
				$session->set( $key, array_key_exists( $key, $stored ) ? maybe_unserialize( $stored[ $key ] ) : null );
			}
		}

		/**
		 * Records a touch and hooks the merge on first use.
		 *
		 * @since 2.0.15
		 *
		 * @param string $key     The session key.
		 * @param bool   $written Whether the touch was a write.
		 *
		 * @return void
		 */
		private function touch( string $key, bool $written ): void {
			if ( '' === $key ) {
				return;
			}

			$this->touched[ $key ] = true;

			if ( $written ) {
				$this->written[ $key ] = true;
			}

			if ( ! $this->hooked ) {
				$this->hooked = true;
				add_action( 'shutdown', [ $this, 'merge_before_save' ], self::PRIORITY );
			}
		}

		/**
		 * Whether the merge applies to `$session` in this request — see the class docblock for each gate.
		 *
		 * @since 2.0.15
		 *
		 * @param object $session The live session handler.
		 *
		 * @return bool
		 */
		private function applies_to( $session ): bool {
			if ( $this->core_merges_per_key() ) {
				return false;
			}

			if ( self::CORE_HANDLER_CLASS !== get_class( $session ) ) {
				return false;
			}

			if ( ! method_exists( $session, 'has_session' ) || ! $session->has_session() ) {
				return false;
			}

			return $this->is_dirty( $session );
		}

		/**
		 * Whether this WooCommerce merges session rows per key itself (11.4+, woocommerce#69559).
		 *
		 * `protected` as a test seam: the real answer is whether {@see self::CORE_MERGE_CLASS} is
		 * loadable, which a unit test can only vary by declaring the class in its own process.
		 *
		 * @since 2.0.15
		 *
		 * @return bool
		 */
		protected function core_merges_per_key(): bool {
			return class_exists( self::CORE_MERGE_CLASS );
		}

		/**
		 * Whether `WC_Session_Handler::save_data()` is going to write this session — its own `$_dirty`
		 * flag, read by reflection because WooCommerce exposes no accessor for it.
		 *
		 * Unreadable means `false`: a handler whose flag cannot be seen is left alone rather than
		 * guessed at (adopting into a clean session would itself make WooCommerce write the stale row).
		 *
		 * @since 2.0.15
		 *
		 * @param object $session The live session handler.
		 *
		 * @return bool
		 */
		private function is_dirty( $session ): bool {
			try {
				$property = new \ReflectionProperty( get_class( $session ), '_dirty' );

				if ( PHP_VERSION_ID < 80100 ) {
					$property->setAccessible( true );
				}

				return (bool) $property->getValue( $session );
			} catch ( \ReflectionException $e ) {
				return false;
			}
		}

		/**
		 * The live `WC()->session`, or `null` when WooCommerce is unavailable or no session was started.
		 *
		 * `protected` as a test seam — same shape and reasoning as
		 * {@see \Woodev\Framework\Shipping\Location\Customer_Location_Store::session()}.
		 *
		 * @since 2.0.15
		 *
		 * @return object|null
		 */
		protected function session() {
			if ( ! function_exists( 'WC' ) || ! WC()->session ) {
				return null;
			}

			return WC()->session;
		}

		/**
		 * Reads the session row as it is stored RIGHT NOW, bypassing the object cache.
		 *
		 * `WC_Session_Handler::get_session()` asks `wp_cache_get()` first, and the cache holds whatever
		 * this request loaded (or the last save filled in) — the stale copy. The table is what
		 * `save_data()` writes, so it is the only place the concurrent write can be seen.
		 *
		 * `protected` as a test seam.
		 *
		 * @since 2.0.15
		 *
		 * @param string $customer_id The session key (`WC_Session::get_customer_id()`).
		 *
		 * @return array<string, mixed>|null The stored row, each value still serialized the way
		 *                                   `WC_Session::set()` keeps it; `null` when there is no row
		 *                                   or the read failed.
		 */
		protected function read_stored_row( string $customer_id ): ?array {
			global $wpdb;

			if ( ! is_object( $wpdb ) ) {
				return null;
			}

			$table = $wpdb->prefix . 'woocommerce_sessions';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reading the row behind the object cache is the point; the table name is WooCommerce's own.
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM {$table} WHERE session_key = %s", $customer_id ) );

			if ( '' !== (string) $wpdb->last_error || null === $value ) {
				return null;
			}

			$row = maybe_unserialize( $value );

			return is_array( $row ) ? $row : null;
		}
	}

endif;
