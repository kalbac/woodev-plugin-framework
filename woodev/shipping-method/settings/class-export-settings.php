<?php
/**
 * Woodev Export Settings
 *
 * The «Выгрузка заказов» settings of ONE carrier plugin (#1007): whether its orders are exported to the carrier
 * on their own, on which WooCommerce statuses, and which status an order gets once the carrier delivered it.
 * They live on the plugin's own tab of the framework
 * settings page (`wp-admin/admin.php?page=woodev-settings`), not on WooCommerce → Settings →
 * Integrations: every Woodev plugin keeps its settings on `woodev-settings`
 * ({@see \Woodev\Framework\Settings\Settings_Page_Registry}), and the framework hands this tab to every
 * {@see \Woodev\Framework\Shipping\Shipping_Plugin} through
 * {@see \Woodev\Framework\Shipping\Shipping_Plugin::get_settings_providers()} — a carrier writes no code.
 * The section shares the plugin's ONE tab with the carrier's own sections (#1014) and is left out for a
 * carrier that does not export orders.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Settings;

use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Order\Order_Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Settings\\Export_Settings' ) ) :

	/**
	 * Settings handler of one carrier plugin's «Выгрузка заказов» section.
	 *
	 * Storage is the framework settings API's: one option per setting, `woodev_{plugin id}_export_{key}`.
	 * The three keys are the ones the shipped v1 carrier plugins stored inside their WooCommerce integration
	 * option (`woocommerce_{plugin id}_settings`, see {@see Order_Automation::SETTING_AUTO_EXPORT} and
	 * {@see self::SETTING_STATUS_DELIVERED}); a site that upgrades keeps what it had chosen —
	 * {@see self::migrate_from_integration()} carries the values over, once.
	 *
	 * @since 2.0.2
	 */
	class Export_Settings extends \Woodev_Abstract_Settings {

		/** @var string the section id on the plugin's tab */
		public const SECTION_ID = 'export';

		/**
		 * @var string the setting id (and the v1 integration-option key — byte for byte) of the status an order
		 *             gets once the carrier delivered it. Stored as `woodev_{plugin id}_export_status_delivered`:
		 *             `wc-completed` (the default), another `wc-…` status, or {@see self::STATUS_DELIVERED_NONE}.
		 */
		public const SETTING_STATUS_DELIVERED = 'status_delivered';

		/** @var string the stored value of «Не менять» — v1's own spelling (its «Не использовать»), so a v1 value is carried verbatim */
		public const STATUS_DELIVERED_NONE = 'none';

		/**
		 * @var string the setting id of the status an order gets once the carrier cancelled its shipment (#1203).
		 *             Stored as `woodev_{plugin id}_export_status_cancelled`: `wc-cancelled` (the default), another
		 *             `wc-…` status, or {@see self::STATUS_CANCELLED_NONE}. New in v2 — v1 had no such option.
		 */
		public const SETTING_STATUS_CANCELLED = 'status_cancelled';

		/** @var string the stored value of «Не менять» for the cancelled status — the same spelling as {@see self::STATUS_DELIVERED_NONE} */
		public const STATUS_CANCELLED_NONE = 'none';

		/** @var string the suffix of the handler id (the option namespace) after the plugin id */
		private const HANDLER_ID_SUFFIX = '_export';

		/** @var string the key, in the handler's option namespace, of the option that records the one-time migration as done */
		private const MIGRATED_FLAG = 'migrated_from_integration';

		/** @var string the carrier plugin's underscored id */
		private string $plugin_id;

		/** @var \Closure|null returns the v1 integration option's name (the handler's `get_option_key()`), or null when there is none */
		private ?\Closure $legacy_option_key_resolver;

		/**
		 * @since 2.0.2
		 *
		 * @param string        $plugin_id                  the carrier plugin's underscored id ({@see \Woodev_Plugin::get_id_underscored()}) —
		 *                                                  the same id its WooCommerce integration option is keyed by.
		 * @param \Closure|null $legacy_option_key_resolver optional; returns the name of the v1 integration option — the
		 *                                                  integration handler's own `get_option_key()`. Called lazily, only
		 *                                                  while the migration is still pending, and may return null (no
		 *                                                  handler yet): the id-derived name of {@see self::get_legacy_option_key()}
		 *                                                  is used then.
		 */
		public function __construct( string $plugin_id, ?\Closure $legacy_option_key_resolver = null ) {

			$this->plugin_id                  = $plugin_id;
			$this->legacy_option_key_resolver = $legacy_option_key_resolver;

			// BEFORE the parent loads the stored values: the carried-over v1 values must be what it reads.
			$this->migrate_from_integration();

			parent::__construct( $plugin_id . self::HANDLER_ID_SUFFIX );
		}

		/**
		 * The setting ids this handler owns, in display order — the section's field list.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_owned_setting_ids(): array {
			return [ Order_Automation::SETTING_AUTO_EXPORT, Order_Automation::SETTING_EXPORT_STATUSES, self::SETTING_STATUS_DELIVERED, self::SETTING_STATUS_CANCELLED ];
		}

		/**
		 * The WooCommerce status an order is moved to once the carrier delivered it, or null for «Не менять».
		 *
		 * @since 2.0.2
		 *
		 * @return string|null a status slug without the `wc-` prefix; null when the merchant chose to leave the status alone.
		 */
		public function get_delivered_status(): ?string {
			return $this->get_chosen_status( self::SETTING_STATUS_DELIVERED, self::STATUS_DELIVERED_NONE );
		}

		/**
		 * The WooCommerce status an order is moved to once the carrier cancelled its shipment, or null for «Не менять».
		 *
		 * @since 2.0.2
		 *
		 * @return string|null a status slug without the `wc-` prefix; null when the merchant chose to leave the status alone.
		 */
		public function get_cancelled_status(): ?string {
			return $this->get_chosen_status( self::SETTING_STATUS_CANCELLED, self::STATUS_CANCELLED_NONE );
		}

		/**
		 * One of the «status for a delivery outcome» selects, as a bare slug.
		 *
		 * @param string $setting_id the select's setting id.
		 * @param string $none_value the stored value of its «Не менять» choice.
		 * @return string|null null for «Не менять», an empty value or a bare `wc-`.
		 */
		private function get_chosen_status( string $setting_id, string $none_value ): ?string {

			$value = $this->get_value( $setting_id );

			if ( ! is_string( $value ) || '' === $value || $none_value === $value ) {
				return null;
			}

			$status = self::unprefix( $value );

			return '' !== $status ? $status : null;
		}

		/**
		 * Whether the merchant switched auto-export on for this carrier. Default OFF (spec §12).
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function is_auto_export_enabled(): bool {
			return true === $this->get_value( Order_Automation::SETTING_AUTO_EXPORT );
		}

		/**
		 * The WooCommerce statuses the merchant chose auto-export for — whatever was saved, including a
		 * status the framework no longer exports on (the gate refuses such an order; see
		 * {@see self::get_unsupported_statuses()}).
		 *
		 * @since 2.0.2
		 *
		 * @return string[] status slugs without the `wc-` prefix.
		 */
		public function get_export_statuses(): array {

			$statuses = [];

			foreach ( (array) $this->get_value( Order_Automation::SETTING_EXPORT_STATUSES ) as $status ) {

				if ( ! is_string( $status ) ) {
					continue;
				}

				$status = self::unprefix( $status );

				if ( '' !== $status ) {
					$statuses[] = $status;
				}
			}

			return array_values( array_unique( $statuses ) );
		}

		/**
		 * The saved auto-export statuses the framework no longer exports on — a v1 site could pick any
		 * non-final status, including a custom one, and v2 offers only
		 * {@see Order_Actions::EXPORTABLE_STATUSES}. The runner refuses such an order, so the merchant is
		 * told instead of finding out from orders that never reach the carrier.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,string> `wc-` status slug => its name, for every saved status outside the allowed set.
		 */
		public function get_unsupported_statuses(): array {

			$unsupported = [];

			foreach ( $this->get_export_statuses() as $status ) {

				if ( ! in_array( $status, Order_Actions::EXPORTABLE_STATUSES, true ) ) {
					$unsupported[ 'wc-' . $status ] = wc_get_order_status_name( $status );
				}
			}

			return $unsupported;
		}

		/**
		 * The note under the section title: what the section does, and — when a saved status can no
		 * longer be exported on — which ones, so the merchant learns it from the page instead of from
		 * orders that never reach the carrier. Plain text: the page renders it as text.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_section_description(): string {

			$description = __( 'Когда заказ сам отправляется перевозчику. Отмена заказа и его полный возврат отменяют заявку у перевозчика сами, независимо от этих настроек.', 'woodev-plugin-framework' );

			foreach ( $this->get_unsupported_statuses() as $name ) {
				$description .= ' ' . sprintf(
					/* translators: %s: the order status name */
					__( 'Статус «%s» больше не поддерживается для автоэкспорта: заказы в нём не выгружаются автоматически. Выберите поддерживаемый статус.', 'woodev-plugin-framework' ),
					$name
				);
			}

			return $description;
		}

		/**
		 * Registers the four settings.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function register_settings() {

			$this->register_setting(
				Order_Automation::SETTING_AUTO_EXPORT,
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'    => __( 'Включить автоэкспорт', 'woodev-plugin-framework' ),
					'default' => false,
				]
			);
			// the explanation is the «Автоэкспорт» card's description (Shipping_Plugin::build_export_section())
			$this->register_control( Order_Automation::SETTING_AUTO_EXPORT, \Woodev_Control::TYPE_TOGGLE );

			$this->register_setting(
				Order_Automation::SETTING_EXPORT_STATUSES,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'     => __( 'Статусы для автоэкспорта', 'woodev-plugin-framework' ),
					'is_multi' => true,
					'options'  => $this->get_status_options(),
					'default'  => [ 'wc-processing' ],
				]
			);
			$this->register_control(
				Order_Automation::SETTING_EXPORT_STATUSES,
				\Woodev_Control::TYPE_MULTISELECT,
				[
					'tooltip' => __( 'Заказ выгружается в тот момент, когда переходит в один из этих статусов. Здесь только статусы, в которых заказ ещё можно отправить перевозчику.', 'woodev-plugin-framework' ),
				]
			);

			$this->register_setting(
				self::SETTING_STATUS_DELIVERED,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Статус доставленного заказа', 'woodev-plugin-framework' ),
					'options' => array_merge(
						[ self::STATUS_DELIVERED_NONE => __( 'Не менять', 'woodev-plugin-framework' ) ],
						wc_get_order_statuses()
					),
					'default' => 'wc-completed',
				]
			);
			$this->register_control(
				self::SETTING_STATUS_DELIVERED,
				\Woodev_Control::TYPE_SELECT,
				[
					'tooltip' => __( 'Этот статус получит заказ один раз — когда перевозчик сообщит, что посылка вручена покупателю. Отменённый, возвращённый или неоплаченный заказ не меняется. «Не менять» — статус заказа остаётся прежним.', 'woodev-plugin-framework' ),
				]
			);

			$this->register_setting(
				self::SETTING_STATUS_CANCELLED,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Статус отменённого заказа', 'woodev-plugin-framework' ),
					'options' => array_merge(
						[ self::STATUS_CANCELLED_NONE => __( 'Не менять', 'woodev-plugin-framework' ) ],
						wc_get_order_statuses()
					),
					'default' => 'wc-cancelled',
				]
			);
			$this->register_control(
				self::SETTING_STATUS_CANCELLED,
				\Woodev_Control::TYPE_SELECT,
				[
					'tooltip' => __( 'Этот статус получит заказ, когда перевозчик сообщит, что отправление отменено. Заказ, который уже отменён, возвращён, выполнен или не оплачен, не меняется. Отмена заявки в самом магазине здесь ни при чём. «Не менять» — статус заказа остаётся прежним.', 'woodev-plugin-framework' ),
				]
			);
		}

		/**
		 * Loads the stored values, then keeps a saved status the list cannot offer in the list.
		 *
		 * A saved status outside the allowed set stays selectable, marked by its name: a saved value the
		 * field cannot show is one the next save would erase without the merchant seeing it, and the
		 * setting would reject it as «not an option». It goes away only when the merchant deselects it
		 * (the v1 data contract).
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function load_settings() {

			parent::load_settings();

			$setting = $this->get_setting( Order_Automation::SETTING_EXPORT_STATUSES );

			if ( ! $setting ) {
				return;
			}

			$options = $setting->get_options();

			foreach ( $this->get_unsupported_statuses() as $slug => $name ) {
				$options[ $slug ] = sprintf(
					/* translators: %s: the order status name */
					__( '%s (не поддерживается)', 'woodev-plugin-framework' ),
					$name
				);
			}

			$setting->set_options( $options );
		}

		/**
		 * The statuses auto-export may be set to: the ones «Экспорт» is offered in
		 * ({@see Order_Actions::EXPORTABLE_STATUSES}) — a later status would queue an export the gate refuses.
		 *
		 * @return array<string,string> `wc-` status slug => its name.
		 */
		private function get_status_options(): array {

			$options = [];

			foreach ( Order_Actions::EXPORTABLE_STATUSES as $status ) {
				$options[ 'wc-' . $status ] = wc_get_order_status_name( $status );
			}

			return $options;
		}

		/**
		 * One-time carry-over (#1007) of the three values the v1 carrier plugins kept inside their
		 * WooCommerce integration option (`woocommerce_{plugin id}_settings` — an installed-site data
		 * contract), so a merchant who had auto-export on in v1 keeps it.
		 *
		 * Deliberately NOT a per-plugin {@see \Woodev_Lifecycle::upgrade_to_X_Y_Z()} routine: that mechanism
		 * is keyed to each carrier plugin's own `$upgrade_versions`, which the framework — vendored inside
		 * every plugin — cannot know. Run from the constructor instead, it is version-independent and
		 * happens before the first read, so there is no window in which an upgraded site reads defaults.
		 *
		 * Idempotent and non-destructive: the done-flag makes every later call a single option read, a value
		 * already stored in the new place (the merchant saved it there first) is never overwritten, and the
		 * v1 keys stay in the integration option — still the record of what v1 had, and a way back.
		 *
		 * The done-flag is written ONLY once the v1 option has actually been read as an array — whether or
		 * not it held the two keys. A miss (no v1 option, or a malformed non-array one) leaves the flag
		 * unset, so the carry-over is retried on a later request instead of turning one wrong read into a
		 * permanent loss. The price is one extra option read per construction on a site that has no v1
		 * option at all (a fresh install).
		 *
		 * The v1 auto-export value is carried over as a bool (`true`/`false`) or a string (through
		 * `wc_string_to_bool()`, so `'yes'` is on); any other type (int, array, null) is not something v1 ever
		 * wrote — it is treated as not set, and the new default (off) applies.
		 *
		 * @return void
		 */
		private function migrate_from_integration(): void {

			$prefix = 'woodev_' . $this->plugin_id . self::HANDLER_ID_SUFFIX . '_';
			$flag   = $prefix . self::MIGRATED_FLAG;

			if ( 'yes' === get_option( $flag, '' ) ) {
				return;
			}

			$legacy = get_option( $this->get_legacy_option_key(), null );

			if ( ! is_array( $legacy ) ) {
				return; // a miss: no done-flag, so a later request tries again
			}

			$auto_export = Order_Automation::SETTING_AUTO_EXPORT;
			$statuses    = Order_Automation::SETTING_EXPORT_STATUSES;

			if ( array_key_exists( $auto_export, $legacy ) && null === get_option( $prefix . $auto_export, null ) ) {

				$value = $legacy[ $auto_export ];

				if ( is_string( $value ) ) {
					$value = wc_string_to_bool( $value );
				}

				if ( is_bool( $value ) ) {
					update_option( $prefix . $auto_export, $value ? 'yes' : 'no' );
				}
			}

			if ( array_key_exists( $statuses, $legacy ) && null === get_option( $prefix . $statuses, null ) ) {

				$carried = [];

				foreach ( (array) $legacy[ $statuses ] as $status ) {

					$status = is_string( $status ) ? self::unprefix( $status ) : '';

					if ( '' !== $status ) {
						$carried[] = 'wc-' . $status;
					}
				}

				update_option( $prefix . $statuses, array_values( array_unique( $carried ) ) );
			}

			$delivered = self::SETTING_STATUS_DELIVERED;

			// v1 stored the status the way WooCommerce lists it («wc-completed»), or «none» for «Не использовать».
			if ( array_key_exists( $delivered, $legacy ) && null === get_option( $prefix . $delivered, null ) && is_string( $legacy[ $delivered ] ) && '' !== $legacy[ $delivered ] ) {

				$status = $legacy[ $delivered ];

				if ( self::STATUS_DELIVERED_NONE !== $status ) {
					$status = 'wc-' . self::unprefix( $status );
				}

				update_option( $prefix . $delivered, $status );
			}

			update_option( $flag, 'yes' );
		}

		/**
		 * The name of the v1 integration option the carry-over reads.
		 *
		 * The source of truth is the integration handler's own `get_option_key()` (WC_Settings_API:
		 * `{plugin_id}{id}_settings`), handed in by {@see \Woodev\Framework\Shipping\Shipping_Plugin::get_export_settings()}.
		 * The handler is not always reachable — `get_integration_handler()` returns null by default, and
		 * the carriers' own implementations read WooCommerce's integrations registry, which is empty until
		 * WooCommerce has booted — so this falls back to the name the framework's integration gives itself
		 * (`woocommerce_` + the plugin's underscored id, the same derivation as
		 * {@see \Woodev\Framework\Shipping\Shipping_Plugin::get_integration_option()}).
		 *
		 * @return string
		 */
		private function get_legacy_option_key(): string {

			$key = null !== $this->legacy_option_key_resolver ? ( $this->legacy_option_key_resolver )() : null;

			return is_string( $key ) && '' !== $key ? $key : 'woocommerce_' . $this->plugin_id . '_settings';
		}

		/**
		 * @param string $status a status slug, with or without the `wc-` prefix.
		 * @return string the slug without the prefix.
		 */
		private static function unprefix( string $status ): string {
			return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		}
	}

endif;
