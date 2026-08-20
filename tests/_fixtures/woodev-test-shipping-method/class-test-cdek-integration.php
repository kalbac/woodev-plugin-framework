<?php
/**
 * Woodev_Test_Cdek_Integration — the rig's carrier-wide settings home for the CDEK
 * test-contour credentials (issue #375).
 *
 * WHY THIS FILE EXISTS. `Woodev_Test_Cdek_Location_Provider` used to declare
 * `cdek_client_id`/`cdek_client_secret` as its OWN `get_settings_fields()`, so they
 * rendered in the "Локация" settings section — exactly the wrong picture the operator
 * saw on screen (issue #375). CDEK's OAuth client id/secret authenticate EVERY CDEK API
 * call (order creation, tracking, webhooks — not only the location dictionary), so they
 * belong to the CARRIER's own settings, not to a location-provider-scoped field set.
 *
 * `Shipping_Integration` (a `WC_Integration` subclass, one instance per plugin,
 * rendered on WooCommerce > Settings > Integrations, storage keyed by
 * `woocommerce_{plugin_id}_settings`) is the framework's own documented home for
 * exactly this shape of setting — see `docs/shipping-method.md`'s "Settings
 * Integration" section, whose own worked example is `api_key`/`api_secret`. The
 * ALTERNATIVE considered and rejected: `Woodev_Test_Shipping_Method`'s own
 * `instance_form_fields` (the base `Shipping_Method`, a `WC_Shipping_Method`
 * subclass) — those are PER SHIPPING-ZONE-INSTANCE settings. A merchant with CDEK
 * live in two zones (e.g. "Moscow" and "Regions") would need to re-type the SAME
 * client id/secret into each zone's instance separately, and deleting/recreating an
 * instance would silently lose them — the opposite of "shared for the whole
 * carrier's calls" (#375's own wording). `Shipping_Integration` is genuinely
 * store-wide, matching CDEK's real account model: one client id/secret per store.
 *
 * WIRING. `Woodev_Test_Shipping_Method_Plugin::get_integration_handler()` builds and
 * caches ONE instance of this class directly (not via `WC()->integrations->get_integration()`
 * — see that method's own docblock for why: at the point `Shipping_Plugin::add_hooks()`
 * calls `get_integration_handler()` to decide whether to hook `register_integration`,
 * `WC()->integrations` has not necessarily finished constructing its own integration
 * list yet). `register_integration()` (inherited, unconditionally wired by
 * `Shipping_Plugin::add_hooks()` once this returns a `Shipping_Integration`) separately
 * hands WooCommerce the CLASS NAME for its own admin-rendered instance — two
 * instances of this same class end up existing, both agreeing on the same
 * `WC_Settings_API::get_option_key()` (`woocommerce_{plugin_id_underscored}_settings`),
 * which is all that matters for them to never disagree about a stored value.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Test_Cdek_Integration' ) ) {

	/**
	 * Class Woodev_Test_Cdek_Integration
	 */
	class Woodev_Test_Cdek_Integration extends \Woodev\Framework\Shipping\Shipping_Integration {

		/**
		 * Settings field id: OAuth client id.
		 *
		 * The ONE place this field id is declared (issue #375):
		 * {@see \Woodev_Test_Cdek_Location_Provider} used to declare its own,
		 * separate `FIELD_CLIENT_ID` constant and read a `woodev_location_*`
		 * option directly — that constant was REMOVED, not duplicated, when the
		 * credentials moved here, and {@see \Woodev_Test_Cdek_Location_Provider::credential()}
		 * now references THIS constant instead.
		 *
		 * @var string
		 */
		public const FIELD_CLIENT_ID = 'cdek_client_id';

		/**
		 * Settings field id: OAuth client secret.
		 *
		 * @var string
		 */
		public const FIELD_CLIENT_SECRET = 'cdek_client_secret';

		/**
		 * {@inheritDoc}
		 *
		 * Both fields, plain WC_Settings_API shape (`title`/`type`/`description`/
		 * `default`) — this is the SAME `Shipping_Integration::get_method_form_fields()`
		 * vocabulary `docs/shipping-method.md`'s own worked example uses for
		 * `api_key`/`api_secret`, not the Woodev settings-API `register_setting()`
		 * shape {@see \Woodev\Framework\Shipping\Location\Location_Provider::get_settings_fields()}
		 * uses — the two surfaces are unrelated on purpose (#375: this is the
		 * carrier's OWN settings page, not the shared location-provider surface).
		 */
		protected function get_method_form_fields(): array {
			return [
				self::FIELD_CLIENT_ID     => [
					'title'       => __( 'Client ID СДЭК', 'woodev-plugin-framework' ),
					'type'        => 'text',
					'description' => __( 'Идентификатор приложения для Integration API 2.0 (тестовый контур api.edu.cdek.ru). Общий для всех запросов к СДЭК, не только для подсказок адресов.', 'woodev-plugin-framework' ),
					'default'     => '',
				],
				self::FIELD_CLIENT_SECRET => [
					'title'       => __( 'Client Secret СДЭК', 'woodev-plugin-framework' ),
					'type'        => 'password',
					'description' => __( 'Секрет приложения для Integration API 2.0 (тестовый контур api.edu.cdek.ru).', 'woodev-plugin-framework' ),
					'default'     => '',
				],
			];
		}

		/**
		 * {@inheritDoc}
		 *
		 * The provider's own honest answer, mirroring
		 * {@see \Woodev_Test_Cdek_Location_Provider::is_configured()}'s own
		 * requirement one layer up: BOTH halves of the credential pair must
		 * actually be stored.
		 */
		public function is_configured(): bool {
			return '' !== trim( (string) $this->get_option( self::FIELD_CLIENT_ID ) )
				&& '' !== trim( (string) $this->get_option( self::FIELD_CLIENT_SECRET ) );
		}

		/**
		 * {@inheritDoc}
		 */
		protected function init_plugin(): \Woodev\Framework\Shipping\Shipping_Plugin {
			return \Woodev_Test_Shipping_Method_Plugin::instance();
		}
	}
}
