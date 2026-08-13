<?php
/**
 * Woodev Location Settings
 *
 * The store-level settings handler for the Location Provider layer (Task 3;
 * spec D4: "provider tokens/keys are store settings, held server-side"). Owns
 * exactly three kinds of fields:
 *
 * 1. `active_provider` — a select whose options are every registered provider's
 *    id => name, defaulting to {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::DEFAULT_PROVIDER_ID}.
 * 2. `field_mode` (Task 13; spec D7) — a select whose OPTIONS are gated by the
 *    active provider's capabilities (typeahead always; related-list/ajax-select2
 *    only when it declares `list`) — computed by
 *    {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry}, this
 *    handler only ever renders whatever option set it was handed, exactly like
 *    `active_provider`'s own options.
 * 3. `default_locality_policy` / `default_locality_record` / `default_locality_needs_repick`
 *    (Task 14; spec D11) — the store-level default-locality policy (`off` |
 *    `fixed` | `geoip`, `geoip` OPTIONS-gated by the active provider's `locate`
 *    capability the same way `field_mode` is gated by `list`), the merchant-picked
 *    FIXED record (JSON, written by {@see Location_Provider_Registry::set_default_locality_record()},
 *    never typed free-hand), and the informational "needs re-picking" flag.
 * 4. Whatever the currently ACTIVE provider declares via
 *    {@see \Woodev\Framework\Shipping\Location\Location_Provider::get_settings_fields()} —
 *    merged in verbatim, keyed by the provider's own field ids. A registered but
 *    NOT-active provider's fields are never merged in (spec §4.1: rendered on the
 *    shared surface, but only for the chosen provider).
 *
 * This handler is deliberately data-only: it does not know how to collect
 * providers or resolve which one is active — {@see Location_Provider_Registry}
 * computes both and hands them to the constructor, keeping this class a plain,
 * unit-testable `Woodev_Abstract_Settings` definition (the same shape as
 * `Woodev_Test_Settings` in the test fixtures).
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Location_Settings' ) ) :

	/**
	 * Store-level settings handler for the Location Provider layer.
	 *
	 * @since 2.0.2
	 */
	class Location_Settings extends \Woodev_Abstract_Settings {

		/**
		 * Registered provider options for the `active_provider` select, `id => name`.
		 *
		 * @since 2.0.2
		 * @var array<string, string>
		 */
		private array $provider_options;

		/**
		 * Offered `field_mode` select options, `id => label` (Task 13; spec D7),
		 * already gated by the active provider's capabilities — resolved by the
		 * caller ({@see Location_Provider_Registry::offered_field_mode_options()}),
		 * exactly like {@see self::$provider_options} is.
		 *
		 * @since 2.0.2
		 * @var array<string, string>
		 */
		private array $field_mode_options;

		/**
		 * The active provider's declared settings fields (Location_Provider::get_settings_fields()
		 * shape), or `[]` when no provider is active. Set BEFORE the parent
		 * constructor runs, since `register_settings()` (called from it) reads it.
		 *
		 * @since 2.0.2
		 * @var array<string, array<string, mixed>>
		 */
		private array $provider_fields;

		/**
		 * Offered `default_locality_policy` select options, `id => label` (Task 14;
		 * spec D11), already gated by the active provider's `locate` capability —
		 * resolved by the caller
		 * ({@see Location_Provider_Registry::offered_default_locality_policy_options_for()}),
		 * exactly like {@see self::$field_mode_options} is.
		 *
		 * @since 2.0.2
		 * @var array<string, string>
		 */
		private array $default_locality_policy_options;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the `$field_mode_options` parameter (Task 13; spec D7).
		 * @since 2.0.2 Added the `$default_locality_policy_options` parameter
		 *              (Task 14; spec D11).
		 *
		 * @param string                              $id                              settings id (the option-name namespace).
		 * @param array<string, string>               $provider_options                registered provider `id => name` pairs.
		 * @param array<string, array<string, mixed>> $provider_fields                 the active provider's declared
		 *                                                                               settings fields, already resolved
		 *                                                                               by the caller.
		 * @param array<string, string>               $field_mode_options              offered `field_mode` select options
		 *                                                                               (`id => label`), already gated by the
		 *                                                                               active provider's capabilities.
		 * @param array<string, string>               $default_locality_policy_options offered `default_locality_policy`
		 *                                                                               select options (`id => label`),
		 *                                                                               already gated by the active
		 *                                                                               provider's `locate` capability.
		 */
		public function __construct(
			string $id,
			array $provider_options,
			array $provider_fields = [],
			array $field_mode_options = [],
			array $default_locality_policy_options = []
		) {
			$this->provider_options                = $provider_options;
			$this->provider_fields                 = $provider_fields;
			$this->field_mode_options               = $field_mode_options;
			$this->default_locality_policy_options = $default_locality_policy_options;

			parent::__construct( $id );
		}

		/**
		 * Gets the settings ids this handler owns, in registration order — the
		 * active-provider select, the field-mode select, the three default-locality
		 * settings (Task 14), then the active provider's own fields. Used by
		 * {@see Location_Provider_Registry} to build the `Settings_Section` without
		 * duplicating this handler's own field list.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the three `default_locality_*` settings (Task 14; spec D11).
		 *
		 * @return string[]
		 */
		public function get_owned_setting_ids(): array {
			return array_merge(
				[
					Location_Provider_Registry::SETTING_ACTIVE_PROVIDER,
					Location_Provider_Registry::SETTING_FIELD_MODE,
					Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_POLICY,
					Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_RECORD,
					Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_NEEDS_REPICK,
				],
				array_keys( $this->provider_fields )
			);
		}

		/**
		 * {@inheritDoc}
		 *
		 * @since 2.0.2
		 */
		protected function register_settings() {

			$this->register_setting(
				Location_Provider_Registry::SETTING_ACTIVE_PROVIDER,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Провайдер локаций', 'woodev-plugin-framework' ),
					'options' => $this->provider_options,
					'default' => Location_Provider_Registry::DEFAULT_PROVIDER_ID,
				]
			);
			$this->register_control( Location_Provider_Registry::SETTING_ACTIVE_PROVIDER, \Woodev_Control::TYPE_SELECT );

			$this->register_setting(
				Location_Provider_Registry::SETTING_FIELD_MODE,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Режим отображения полей локации', 'woodev-plugin-framework' ),
					'options' => $this->field_mode_options,
					'default' => Location_Provider_Registry::MODE_TYPEAHEAD,
				]
			);
			$this->register_control( Location_Provider_Registry::SETTING_FIELD_MODE, \Woodev_Control::TYPE_SELECT );

			$this->register_setting(
				Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_POLICY,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Локация по умолчанию', 'woodev-plugin-framework' ),
					'options' => $this->default_locality_policy_options,
					'default' => Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF,
				]
			);
			$this->register_control( Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_POLICY, \Woodev_Control::TYPE_SELECT );

			// Serialized Location_Record JSON (Task 14), written through
			// Location_Provider_Registry::set_default_locality_record() — the
			// admin picker's own selection, not free text the merchant types here.
			$this->register_setting(
				Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_RECORD,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Зафиксированная локация', 'woodev-plugin-framework' ),
					'default' => '',
				]
			);
			$this->register_control( Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_RECORD, \Woodev_Control::TYPE_TEXTAREA );

			// Informational flag — see Location_Provider_Registry::get_default_locality_needs_repick().
			$this->register_setting(
				Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_NEEDS_REPICK,
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'    => __( 'Зафиксированная локация требует повторного выбора', 'woodev-plugin-framework' ),
					'default' => false,
				]
			);
			$this->register_control( Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_NEEDS_REPICK, \Woodev_Control::TYPE_TOGGLE );

			foreach ( $this->provider_fields as $field_id => $field ) {
				$this->register_provider_field( (string) $field_id, (array) $field );
			}
		}

		/**
		 * Registers one field declared by the active provider's
		 * {@see Location_Provider::get_settings_fields()}.
		 *
		 * `type` is consumed positionally by {@see Woodev_Abstract_Settings::register_setting()},
		 * so it is extracted from the field array rather than passed through inside
		 * `$args` (matching how {@see \Woodev\Framework\Shipping\Map\Map_Provider::get_settings_fields()}
		 * is itself consumed). The provider's shape says nothing about which admin
		 * CONTROL to render — that vocabulary belongs to
		 * {@see Woodev_Abstract_Settings::register_control()}, one layer up from
		 * what a provider (which never touches the settings-PAGE surface, only the
		 * settings-API shape) can declare — so the control type is inferred here
		 * from what the field itself already says: `options` present -> a select;
		 * `sensitive` -> a password field (matching the `secret`/`conn_password`
		 * precedent in the test fixture's own settings); otherwise plain text.
		 *
		 * @since 2.0.2
		 *
		 * @param string               $field_id field id.
		 * @param array<string, mixed> $field    field descriptor (settings-API `register_setting()` args shape + `type`).
		 *
		 * @return void
		 */
		private function register_provider_field( string $field_id, array $field ): void {
			$type = $field['type'] ?? \Woodev_Setting::TYPE_STRING;
			unset( $field['type'] );

			$this->register_setting( $field_id, $type, $field );

			if ( ! empty( $field['options'] ) ) {
				$control_type = \Woodev_Control::TYPE_SELECT;
			} elseif ( ! empty( $field['sensitive'] ) ) {
				$control_type = \Woodev_Control::TYPE_PASSWORD;
			} else {
				$control_type = \Woodev_Control::TYPE_TEXT;
			}

			$this->register_control( $field_id, $control_type );
		}
	}

endif;
