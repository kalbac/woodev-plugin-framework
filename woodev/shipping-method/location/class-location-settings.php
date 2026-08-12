<?php
/**
 * Woodev Location Settings
 *
 * The store-level settings handler for the Location Provider layer (Task 3;
 * spec D4: "provider tokens/keys are store settings, held server-side"). Owns
 * exactly two kinds of fields:
 *
 * 1. `active_provider` — a select whose options are every registered provider's
 *    id => name, defaulting to {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::DEFAULT_PROVIDER_ID}.
 * 2. Whatever the currently ACTIVE provider declares via
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
		 * The active provider's declared settings fields (Location_Provider::get_settings_fields()
		 * shape), or `[]` when no provider is active. Set BEFORE the parent
		 * constructor runs, since `register_settings()` (called from it) reads it.
		 *
		 * @since 2.0.2
		 * @var array<string, array<string, mixed>>
		 */
		private array $provider_fields;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param string                              $id               settings id (the option-name namespace).
		 * @param array<string, string>               $provider_options registered provider `id => name` pairs.
		 * @param array<string, array<string, mixed>> $provider_fields  the active provider's declared
		 *                                                               settings fields, already resolved
		 *                                                               by the caller.
		 */
		public function __construct( string $id, array $provider_options, array $provider_fields = [] ) {
			$this->provider_options = $provider_options;
			$this->provider_fields  = $provider_fields;

			parent::__construct( $id );
		}

		/**
		 * Gets the settings ids this handler owns, in registration order — the
		 * active-provider select first, then the active provider's own fields.
		 * Used by {@see Location_Provider_Registry} to build the `Settings_Section`
		 * without duplicating this handler's own field list.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_owned_setting_ids(): array {
			return array_merge( [ Location_Provider_Registry::SETTING_ACTIVE_PROVIDER ], array_keys( $this->provider_fields ) );
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
