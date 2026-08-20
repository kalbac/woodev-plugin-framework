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
 * 4. EVERY registered provider's own fields, declared via
 *    {@see \Woodev\Framework\Shipping\Location\Location_Provider::get_settings_fields()} —
 *    merged in verbatim, keyed by the provider's own field ids, each already
 *    carrying a `show_if` condition (ADR-008) computed by the caller
 *    ({@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::collect_all_provider_fields()}).
 *    This CHANGED (#375/#377, "dynamic, without saving"): previously only the
 *    ACTIVE provider's fields were merged in at all, so switching the
 *    `active_provider` select only changed the visible key fields after a
 *    save. Now every provider's fields are always registered and the CLIENT
 *    shows/hides them per `show_if` — this handler itself does not know or
 *    care which provider is "active"; it only renders whatever fields (and
 *    conditions) it was handed.
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
		 * @param array<string, array<string, mixed>> $provider_fields                 EVERY registered provider's declared
		 *                                                                               settings fields, each already carrying
		 *                                                                               a `show_if` condition (#375/#377),
		 *                                                                               resolved by the caller.
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
		 * active-provider select, the field-mode select, the address-suggestions
		 * checkbox (Task 10), the three default-locality settings (Task 14), then
		 * EVERY registered provider's own fields (#375/#377 — not only the
		 * active one's, see this class's own docblock), in provider-registration
		 * order. Used by {@see Location_Provider_Registry} to build the
		 * `Settings_Section` without duplicating this handler's own field list.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the three `default_locality_*` settings (Task 14; spec D11).
		 * @since 2.0.2 Added `address_suggestions`, right after `field_mode`
		 *              (Task 10; issue #362; design S3/§3.1).
		 *
		 * @return string[]
		 */
		public function get_owned_setting_ids(): array {
			return array_merge(
				[
					Location_Provider_Registry::SETTING_ACTIVE_PROVIDER,
					Location_Provider_Registry::SETTING_FIELD_MODE,
					Location_Provider_Registry::SETTING_ADDRESS_SUGGESTIONS,
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
					// Relabelled from «Режим отображения полей локации» (design S2 —
					// s79 brainstorm): «field_mode» is the SAME setting the CDEK
					// plugin's own "Выпадающий список городов" vocabulary maps onto
					// (spec §4.1), so the shared surface names it after what it
					// actually controls (the НП/Регион field's INPUT TYPE), not the
					// old, vaguer "display mode" wording.
					'name'    => __( 'Тип поля НП/Регион', 'woodev-plugin-framework' ),
					'options' => $this->field_mode_options,
					'default' => Location_Provider_Registry::MODE_TYPEAHEAD,
				]
			);
			$this->register_control( Location_Provider_Registry::SETTING_FIELD_MODE, \Woodev_Control::TYPE_SELECT );

			/*
			 * `address_suggestions` (Task 10; issue #362; design S3/§3.1/§3.2):
			 * whether the location layer serves the `address` suggest level at
			 * all. Registered right after `field_mode` — design S3 puts it
			 * directly under the provider block. No OPTIONS-gating like
			 * `field_mode`/`default_locality_policy` above: this is a plain
			 * boolean, and its AVAILABILITY (rather than its offered values) is
			 * what varies — that is expressed as a DISABLED control, applied by
			 * Location_Provider_Registry::register_settings() once this handler
			 * exists (the same "resolved by the caller, this handler only
			 * renders it" discipline this class's own docblock already
			 * describes for `provider_options`/`field_mode_options`).
			 */
			$this->register_setting(
				Location_Provider_Registry::SETTING_ADDRESS_SUGGESTIONS,
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'    => __( 'Подсказки для адреса', 'woodev-plugin-framework' ),
					'default' => true,
				]
			);
			$this->register_control( Location_Provider_Registry::SETTING_ADDRESS_SUGGESTIONS, \Woodev_Control::TYPE_CHECKBOX );

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

			/*
			 * Serialized Location_Record JSON (Task 14), written through
			 * Location_Provider_Registry::set_default_locality_record() — the
			 * FUTURE admin picker's own selection (its own, later card; no picker
			 * UI ships in this task), never free text the merchant types here.
			 *
			 * Deliberately registered WITHOUT a control (review finding F4): no
			 * picker exists yet in this PR, so the only UI a control would offer
			 * today is a bare textarea holding raw JSON — directly contradicting
			 * this field's own "never typed free-hand" contract above. Keeping
			 * the setting itself registered (just uncontrolled) preserves the
			 * write path this class's own docblock documents for the picker to
			 * use once built (a plain settings-API field written through
			 * Woodev_Abstract_Settings::update_value(), the same as every other
			 * store-level Location setting) — only the generic settings-page
			 * React surface's editable rendering is what this withholds, by
			 * never giving Field_Schema::from_handler() a controlType for it.
			 */
			$this->register_setting(
				Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_RECORD,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Зафиксированная локация', 'woodev-plugin-framework' ),
					'default' => '',
				]
			);

			/*
			 * Informational flag — see Location_Provider_Registry::get_default_locality_needs_repick().
			 * Also registered WITHOUT a control (review finding F4): this flag is
			 * CODE-OWNED bookkeeping, never a merchant decision — an editable
			 * toggle let a merchant silently switch it off and mask a genuinely
			 * stranded default. The settings page instead surfaces the live
			 * equivalent of this flag through the `default_locality_policy`
			 * field's own description — see
			 * Location_Provider_Registry::apply_default_locality_status_note().
			 */
			$this->register_setting(
				Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_NEEDS_REPICK,
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'    => __( 'Зафиксированная локация требует повторного выбора', 'woodev-plugin-framework' ),
					'default' => false,
				]
			);

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
