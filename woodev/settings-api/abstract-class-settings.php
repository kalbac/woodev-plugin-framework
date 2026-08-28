<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Abstract_Settings' ) ) :

	/**
	 * The base settings handler.
	 */
	abstract class Woodev_Abstract_Settings {

		/** @var string settings ID */
		public $id;

		/** @var Woodev_Setting[] registered settings */
		protected $settings = [];

		/**
		 * Constructs the class.
		 *
		 * @param string $id the ID of plugin or payment gateway that owns these settings
		 */
		public function __construct( $id ) {

			$this->id = $id;

			$this->register_settings();
			$this->load_settings();
		}

		/**
		 * Registers the settings.
		 *
		 * Plugins or payment gateways should overwrite this method to register their settings.
		 */
		abstract protected function register_settings();

		/**
		 * Loads the values for all registered settings.
		 */
		protected function load_settings() {

			foreach ( $this->settings as $setting_id => $setting ) {

				$value = get_option( $this->get_option_name_prefix() . '_' . $setting_id, null );
				$value = $this->get_value_from_database( $value, $setting );

				$this->settings[ $setting_id ]->set_value( $value );
			}
		}

		/**
		 * Registers a setting.
		 *
		 * ⚠️ `description` is rendered as RAW HTML on the React settings surface, so that the
		 * `<a href="…">` links this field exists for actually work (issue #373). It must
		 * therefore always be a developer-authored `__()` string. NEVER interpolate anything
		 * that originates at runtime into it — a stored option value, an API response, a
		 * provider's error text — or you have handed that source an XSS. State a runtime
		 * condition through the control's `disabled_reason` instead, which stays escaped.
		 *
		 * @param string $id unique setting ID
		 * @param string $type setting type
		 * @param array  $args setting arguments
		 * @return bool
		 */
		public function register_setting( $id, $type, array $args = [] ) {

			try {

				if ( ! empty( $this->settings[ $id ] ) ) {
					throw new Woodev_Plugin_Exception( "Setting {$id} is already registered" );
				}

				if ( ! in_array( $type, $this->get_setting_types(), true ) ) {
					throw new Woodev_Plugin_Exception( "{$type} is not a valid setting type" );
				}

				$setting = new Woodev_Setting();

				$setting->set_id( $id );
				$setting->set_type( $type );

				$args = wp_parse_args(
					$args,
					[
						'name'             => '',
						'description'      => '',
						'is_multi'         => false,
						'options'          => [],
						'default'          => null,
						'sensitive'        => false,
						'constant_name'    => null,
						'required'         => false,
						'validate'         => null,
						'validate_message' => '',
						'show_if'          => [],
					]
				);

				$setting->set_name( $args['name'] );
				$setting->set_description( $args['description'] );
				$setting->set_is_multi( $args['is_multi'] );
				$setting->set_sensitive( (bool) $args['sensitive'] );
				$setting->set_constant_name( null !== $args['constant_name'] ? (string) $args['constant_name'] : null );
				$setting->set_required( (bool) $args['required'] );
				if ( is_callable( $args['validate'] ) ) {
					$setting->set_validate( $args['validate'] );
					$setting->set_validate_message( (string) $args['validate_message'] );
				}

				$setting->set_show_if( $args['show_if'] );

				if ( is_array( $args['options'] ) ) {
					$setting->set_options( $args['options'] );
				}

				// Default must be set AFTER is_multi so multi-value array defaults are
				// validated per element instead of being rejected as a non-scalar.
				$setting->set_default( $args['default'] );

				$this->settings[ $id ] = $setting;

				return true;

			} catch ( Exception $exception ) {

				_doing_it_wrong( __METHOD__, 'Could not register setting: ' . $exception->getMessage(), '1.1.2' );

				return false;
			}
		}

		/**
		 * Unregisters a setting.
		 *
		 * @param string $id setting ID to unregister
		 */
		public function unregister_setting( $id ) {
			unset( $this->settings[ $id ] );
		}

		/**
		 * Registers a control for a setting.
		 *
		 * @param string $setting_id the setting ID
		 * @param string $type the control type
		 * @param array  $args optional args for the control
		 * @return bool
		 */
		public function register_control( $setting_id, $type, array $args = [] ) {

			try {

				if ( ! in_array( $type, $this->get_control_types(), true ) ) {
					throw new UnexpectedValueException( "{$type} is not a valid control type" );
				}

				$setting = $this->get_setting( $setting_id );

				if ( ! $setting ) {
					throw new InvalidArgumentException( "Setting {$setting_id} does not exist" );
				}

				if ( $setting->is_is_multi() && Woodev_Setting::TYPE_BOOLEAN === $setting->get_type()
					&& in_array( $type, [ Woodev_Control::TYPE_TOGGLE, Woodev_Control::TYPE_CHECKBOX ], true ) ) {
					throw new UnexpectedValueException( "{$type} controls only support scalar boolean settings" );
				}

				$setting_control_types = $this->get_setting_control_types( $setting );
				if ( ! empty( $setting_control_types ) && ! in_array( $type, $setting_control_types, true ) ) {
					throw new UnexpectedValueException( "{$type} is not a valid control type for setting {$setting->get_id()} of type {$setting->get_type()}" );
				}

				$args = wp_parse_args(
					$args,
					[
						'name'        => $setting->get_name(),
						'description' => $setting->get_description(),
						'options'     => [],
					]
				);

				$control = new Woodev_Control();

				$control->set_setting_id( $setting_id );
				$control->set_type( $type );
				$control->set_name( $args['name'] );
				$control->set_description( $args['description'] );

				if ( is_array( $args['options'] ) ) {
					$control->set_options( $args['options'], $setting->get_options() );
				}

				if ( isset( $args['min'] ) ) {
					$control->set_min( $args['min'] );
				}

				if ( isset( $args['max'] ) ) {
					$control->set_max( $args['max'] );
				}

				if ( isset( $args['step'] ) ) {
					$control->set_step( $args['step'] );
				}

				if ( isset( $args['tooltip'] ) ) {
					$control->set_tooltip( (string) $args['tooltip'] );
				}

				if ( isset( $args['placeholder'] ) ) {
					$control->set_placeholder( (string) $args['placeholder'] );
				}

				if ( isset( $args['country'] ) ) {
					$control->set_country( (string) $args['country'] );
				}

				if ( ! empty( $args['disabled'] ) ) {
					$control->set_disabled( true, (string) ( $args['disabled_reason'] ?? '' ) );
				}

				$setting->set_control( $control );

				return true;

			} catch ( Exception $exception ) {

				_doing_it_wrong( __METHOD__, 'Could not register setting control: ' . $exception->getMessage(), '1.1.2' );

				return false;
			}
		}

		/**
		 * Gets the settings ID.
		 *
		 * @return string
		 */
		public function get_id() {

			return $this->id;
		}

		/**
		 * Gets registered settings.
		 *
		 * It returns all settings by default, but you can pass an array of IDs to filter the results.
		 *
		 * @param string[] $ids setting IDs to get
		 * @return Woodev_Setting[]
		 */
		public function get_settings( array $ids = [] ) {

			$settings = $this->settings;

			if ( ! empty( $ids ) ) {

				foreach ( array_keys( $this->settings ) as $id ) {

					if ( ! in_array( $id, $ids, true ) ) {
						unset( $settings[ $id ] );
					}
				}
			}

			return $settings;
		}

		/**
		 * Gets a setting object.
		 *
		 * @param string $id setting ID to get
		 * @return Woodev_Setting|null
		 */
		public function get_setting( $id ) {
			return ! empty( $this->settings[ $id ] ) ? $this->settings[ $id ] : null;
		}

		/**
		 * Gets the stored value for a setting.
		 *
		 * Optionally, will return the setting's default value if nothing is stored.
		 *
		 * @param string $setting_id setting ID
		 * @param bool   $with_default whether to return the default value if nothing is stored
		 * @return mixed
		 * @throws Woodev_Plugin_Exception
		 */
		public function get_value( $setting_id, $with_default = true ) {

			$setting = $this->get_setting( $setting_id );

			if ( ! $setting ) {
				throw new Woodev_Plugin_Exception( "Setting {$setting_id} does not exist" );
			}

			$value = $setting->get_value();

			if ( $with_default && null === $value ) {
				$value = $setting->get_default();
			}

			return $value;
		}

		/**
		 * Updates the stored value for a setting.
		 *
		 * @param string $setting_id setting ID
		 * @param mixed  $value
		 * @throws Woodev_Plugin_Exception
		 */
		public function update_value( $setting_id, $value ) {

			$setting = $this->get_setting( $setting_id );

			if ( ! $setting ) {
				throw new Woodev_Plugin_Exception( "Setting {$setting_id} does not exist", 404 );
			}

			// A constant-backed setting is code-managed (wp-config); never persist
			// it to the DB. The user cannot edit it, so an inbound value is ignored.
			$constant = $setting->get_constant_name();
			if ( null !== $constant && defined( $constant ) ) {
				return;
			}

			// performs the validations and updates the value
			$setting->update_value( $value );

			$this->save( $setting_id );
		}

		/**
		 * Validates a map of setting_id => value, returning a map of field errors.
		 *
		 * Read-only: nothing is persisted. Unknown ids and code-managed
		 * (defined-constant) settings are skipped (they cannot be edited). Mirrors
		 * update_value()'s constant guard so the two passes agree.
		 *
		 * @since 2.0.2
		 * @param array<string,mixed> $values setting_id => value.
		 * @return array<string,string> setting_id => error message (empty when all valid).
		 */
		public function validate_values( array $values ): array {

			$errors = [];

			foreach ( $values as $setting_id => $value ) {

				$setting = $this->get_setting( (string) $setting_id );

				if ( ! $setting ) {
					continue;
				}

				$constant = $setting->get_constant_name();
				if ( null !== $constant && defined( $constant ) ) {
					continue;
				}

				if ( $setting->is_is_multi() ) {

					$elements     = array_values( (array) $value );
					$control      = $setting->get_control();
					$control_type = $control instanceof Woodev_Control ? $control->get_type() : null;

					if ( $setting->is_required() && Woodev_Setting::is_requirable( $control_type )
						&& 0 === count( array_filter( $elements, static fn( $element ) => ! Woodev_Setting::is_empty_value( $control_type, $element ) ) ) ) {
						$errors[ $setting_id ] = __( 'Обязательное поле.', 'woodev-plugin-framework' );
						continue;
					}

					foreach ( $elements as $element ) {
						$element_error = $setting->get_validation_error( $element );
						if ( null !== $element_error ) {
							$errors[ $setting_id ] = $element_error;
							break;
						}
					}
				} else {

					$error = $setting->get_validation_error( $value );
					if ( null !== $error ) {
						$errors[ $setting_id ] = $error;
					}
				}
			}

			return $errors;
		}

		/**
		 * Removes fields hidden by their show_if conditions from a submitted values map.
		 *
		 * Called at the top of both REST save paths so a hidden field is neither
		 * validated nor persisted. Visibility resolves against the EFFECTIVE controlling
		 * value (submitted if present, else the stored value) so the server agrees with
		 * the client (which merges edits over stored/default values). Unknown ids and
		 * unconditional fields pass through unchanged.
		 *
		 * @since 2.0.2
		 * @param array<string,mixed> $values submitted setting_id => value.
		 * @return array<string,mixed> the submitted map with hidden fields removed.
		 */
		public function filter_visible_values( array $values ): array {

			// Resolve every field's visibility against the ORIGINAL submitted map first,
			// then strip — so a chained dependency (a controller that is itself hidden)
			// is order-independent instead of depending on array key order.
			$hidden = [];

			foreach ( array_keys( $values ) as $setting_id ) {

				$setting = $this->get_setting( (string) $setting_id );

				if ( ! $setting ) {
					continue;
				}

				$conditions = $setting->get_show_if_conditions();

				if ( empty( $conditions ) ) {
					continue;
				}

				if ( ! Woodev_Setting::evaluate_conditions( $conditions, $this->effective_condition_values( $conditions, $values ) ) ) {
					$hidden[] = $setting_id;
				}
			}

			foreach ( $hidden as $setting_id ) {
				unset( $values[ $setting_id ] );
			}

			return $values;
		}

		/**
		 * Builds the controlling-value map a condition group needs: for each referenced
		 * controlling setting, the submitted value if present, else the stored value.
		 *
		 * @since 2.0.2
		 * @param array<string,mixed> $conditions the condition group.
		 * @param array<string,mixed> $submitted  the submitted values map.
		 * @return array<string,mixed> controlling setting_id => effective value.
		 */
		private function effective_condition_values( array $conditions, array $submitted ): array {

			$group  = isset( $conditions['setting'] ) ? [ $conditions ] : $conditions;
			$result = [];

			foreach ( $group as $condition ) {

				if ( ! is_array( $condition ) || ! isset( $condition['setting'] ) ) {
					continue;
				}

				// An unregistered controller (typo'd or cross-handler id) has no stored
				// value and get_value() would throw on it — treat it as the empty string,
				// matching the "unset controlling value = empty string" contract.
				$id            = (string) $condition['setting'];
				$result[ $id ] = array_key_exists( $id, $submitted )
					? $submitted[ $id ]
					: ( $this->get_setting( $id ) ? $this->get_value( $id ) : '' );
			}

			return $result;
		}

		/**
		 * Deletes the stored value for a setting.
		 *
		 * @param string $setting_id setting ID
		 * @return bool
		 * @throws Woodev_Plugin_Exception
		 */
		public function delete_value( $setting_id ) {

			$setting = $this->get_setting( $setting_id );

			if ( ! $setting ) {
				throw new Woodev_Plugin_Exception( "Setting {$setting_id} does not exist" );
			}

			$setting->set_value( null );

			return delete_option( "{$this->get_option_name_prefix()}_{$setting->get_id()}" );
		}

		/**
		 * Saves registered settings in their current state.
		 * It saves all settings by default, but you can pass a setting ID to save a specific setting.
		 *
		 * @param string $setting_id setting ID
		 */
		public function save( $setting_id = '' ) {

			if ( ! empty( $setting_id ) ) {
				$settings = [ $this->get_setting( $setting_id ) ];
			} else {
				$settings = $this->settings;
			}

			$settings = array_filter( $settings );

			foreach ( $settings as $setting ) {

				$option_name   = "{$this->get_option_name_prefix()}_{$setting->get_id()}";
				$setting_value = $setting->get_value();

				if ( null === $setting_value ) {

					delete_option( $option_name );

				} else {

					update_option( $option_name, $this->get_value_for_database( $setting ) );
				}
			}
		}

		/**
		 * Converts the value of a setting to be stored in an option.
		 *
		 * Multi-value settings are serialized element by element to match
		 * Woodev_Setting::update_value(), which validates every element. Boolean
		 * multi-values are therefore supported, but register_control() rejects
		 * toggle and checkbox controls for them: the admin UI renders those
		 * controls as one scalar boolean and would collapse the stored array.
		 *
		 * @param Woodev_Setting $setting
		 * @return mixed
		 */
		protected function get_value_for_database( Woodev_Setting $setting ) {

			$value = $setting->get_value();

			if ( null !== $value && Woodev_Setting::TYPE_BOOLEAN === $setting->get_type() ) {
				$value = $setting->is_is_multi() && is_array( $value )
					? array_map( [ self::class, 'bool_to_string' ], $value )
					: self::bool_to_string( $value );
			}

			return $value;
		}

		/**
		 * Converts the stored value of a setting to the proper setting type.
		 *
		 * Multi-value settings are restored element by element to preserve the
		 * same generic is_multi contract used by Woodev_Setting::update_value().
		 * Boolean multi-values must remain unpaired with toggle and checkbox
		 * controls until an array-aware list-of-booleans control exists.
		 *
		 * @param mixed          $value the value stored in an option
		 * @param Woodev_Setting $setting
		 * @return mixed
		 */
		protected function get_value_from_database( $value, Woodev_Setting $setting ) {

			if ( null !== $value ) {

				switch ( $setting->get_type() ) {

					case Woodev_Setting::TYPE_BOOLEAN:
						$value = $setting->is_is_multi() && is_array( $value )
							? array_map( [ self::class, 'string_to_bool' ], $value )
							: self::string_to_bool( $value );
						break;

					case Woodev_Setting::TYPE_INTEGER:
						$value = $setting->is_is_multi() && is_array( $value )
							? array_map(
								static function ( $item ) {
									return is_numeric( $item ) ? (int) $item : null;
								},
								$value
							)
							: ( is_numeric( $value ) ? (int) $value : null );
						break;

					case Woodev_Setting::TYPE_FLOAT:
						$value = $setting->is_is_multi() && is_array( $value )
							? array_map(
								static function ( $item ) {
									return is_numeric( $item ) ? (float) $item : null;
								},
								$value
							)
							: ( is_numeric( $value ) ? (float) $value : null );
						break;
				}
			}

			return $value;
		}

		/**
		 * Converts WooCommerce-style string booleans to native booleans.
		 *
		 * @param mixed $string value to convert
		 * @return bool
		 */
		private static function string_to_bool( $string ) {
			return is_bool( $string )
				? $string
				: (
					( is_string( $string ) && ( 'yes' === strtolower( $string ) || 'true' === strtolower( $string ) || '1' === $string ) )
					|| 1 === $string
				);
		}

		/**
		 * Converts booleans to the installed-site yes/no storage contract.
		 *
		 * @param mixed $bool value to convert
		 * @return string
		 */
		private static function bool_to_string( $bool ) {

			if ( ! is_bool( $bool ) ) {
				$bool = self::string_to_bool( $bool );
			}

			return true === $bool ? 'yes' : 'no';
		}

		/**
		 * Gets the list of valid setting types.
		 *
		 * The `woodev_{id}_settings_api_setting_types` filter's return is
		 * validated: it is consumed by {@see self::register_setting()}'s
		 * `in_array()` call, which throws a `TypeError` on a non-array
		 * haystack. A non-array return degrades to the pre-filter list
		 * rather than reaching that call.
		 *
		 * @since 2.0.2 the filter return is validated with is_array(); a
		 *              non-array return no longer reaches in_array() raw.
		 *
		 * @return string[]
		 */
		public function get_setting_types() {

			$setting_types = [
				Woodev_Setting::TYPE_STRING,
				Woodev_Setting::TYPE_URL,
				Woodev_Setting::TYPE_EMAIL,
				Woodev_Setting::TYPE_INTEGER,
				Woodev_Setting::TYPE_FLOAT,
				Woodev_Setting::TYPE_BOOLEAN,
				'object',
			];

			/**
			 * Filters the list of valid setting types.
			 *
			 * @param string[] $setting_types valid setting types
			 * @param Woodev_Abstract_Settings $settings the settings handler instance
			 */
			$filtered_types = apply_filters( "woodev_{$this->get_id()}_settings_api_setting_types", $setting_types, $this );

			return is_array( $filtered_types ) ? $filtered_types : $setting_types;
		}

		/**
		 * Gets the list of valid control types.
		 *
		 * The `woodev_{id}_settings_api_control_types` filter's return is
		 * validated: it is consumed by {@see self::register_control()}'s
		 * `in_array()` calls, which throw a `TypeError` on a non-array
		 * haystack. A non-array return degrades to the pre-filter list
		 * rather than reaching those calls.
		 *
		 * @since 2.0.2 the filter return is validated with is_array(); a
		 *              non-array return no longer reaches in_array() raw.
		 *
		 * @return string[]
		 */
		public function get_control_types() {

			$control_types = [
				Woodev_Control::TYPE_TEXT,
				Woodev_Control::TYPE_TEXTAREA,
				Woodev_Control::TYPE_NUMBER,
				Woodev_Control::TYPE_EMAIL,
				Woodev_Control::TYPE_TEL,
				Woodev_Control::TYPE_URL,
				Woodev_Control::TYPE_PASSWORD,
				Woodev_Control::TYPE_DATE,
				Woodev_Control::TYPE_CHECKBOX,
				Woodev_Control::TYPE_RADIO,
				Woodev_Control::TYPE_SELECT,
				Woodev_Control::TYPE_FILE,
				Woodev_Control::TYPE_COLOR,
				Woodev_Control::TYPE_RANGE,
				Woodev_Control::TYPE_TOGGLE,
				Woodev_Control::TYPE_RICHTEXT,
				Woodev_Control::TYPE_MULTISELECT,
				Woodev_Control::TYPE_LOCATION_PICKER,
			];

			/**
			 * Filters the list of valid control types.
			 *
			 * @param string[] $control_types valid control types
			 * @param Woodev_Abstract_Settings $settings the settings handler instance
			 */
			$filtered_types = apply_filters( "woodev_{$this->get_id()}_settings_api_control_types", $control_types, $this );

			return is_array( $filtered_types ) ? $filtered_types : $control_types;
		}

		/**
		 * Returns the valid control types for a setting.
		 *
		 * The `woodev_{id}_settings_api_setting_control_types` filter's return
		 * is validated: it is consumed by {@see self::register_control()}'s
		 * `in_array()` call, which throws a `TypeError` on a non-array
		 * haystack. A non-array return degrades to the pre-filter value —
		 * an empty list, meaning "no restriction" — rather than reaching
		 * that call.
		 *
		 * @since 2.0.2 the filter return is validated with is_array(); a
		 *              non-array return no longer reaches in_array() raw.
		 *
		 * @param Woodev_Setting $setting setting object
		 * @return string[]
		 */
		public function get_setting_control_types( $setting ) {
			/**
			 * Filters the list of valid control types for a setting.
			 *
			 * @param string[] $control_types valid control types
			 * @param string $setting_type setting type
			 * @param Woodev_Setting $setting setting object
			 * @param Woodev_Abstract_Settings $settings the settings handler instance
			 */
			$filtered_types = apply_filters( "woodev_{$this->get_id()}_settings_api_setting_control_types", [], $setting->get_type(), $setting, $this );

			return is_array( $filtered_types ) ? $filtered_types : [];
		}

		/**
		 * Gets the prefix for db option names.
		 *
		 * @return string
		 */
		public function get_option_name_prefix() {
			return "woodev_{$this->id}";
		}
	}

endif;
