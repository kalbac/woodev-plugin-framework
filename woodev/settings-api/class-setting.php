<?php

if ( ! class_exists( 'Woodev_Setting' ) ) :

	/**
	 * The base setting object.
	 */
	class Woodev_Setting {

		/** @var string the string setting type */
		const TYPE_STRING = 'string';

		/** @var string the URL setting type */
		const TYPE_URL = 'url';

		/** @var string the email setting type */
		const TYPE_EMAIL = 'email';

		/** @var string the integer setting type */
		const TYPE_INTEGER = 'integer';

		/** @var string the float setting type */
		const TYPE_FLOAT = 'float';

		/** @var string the boolean setting type */
		const TYPE_BOOLEAN = 'boolean';

		/** @var string unique setting ID */
		protected $id;

		/** @var string setting type */
		protected $type;

		/** @var string setting name */
		protected $name;

		/** @var string setting description */
		protected $description;

		/** @var bool whether the setting holds an array of multiple values */
		protected $is_multi = false;

		/** @var array valid setting options */
		protected $options = [];

		/** @var mixed setting default value */
		protected $default;

		/** @var mixed setting current value */
		protected $value;

		/** @var Woodev_Control control object */
		protected $control;

		/** @var bool whether this setting holds a secret (masked in transport) */
		protected $sensitive = false;

		/** @var string|null name of a PHP constant that, when defined, supplies the value (kept out of the DB) */
		protected $constant_name = null;

		/**
		 * Gets the setting ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return $this->id;
		}

		/**
		 * Gets the setting type.
		 *
		 * @return string
		 */
		public function get_type() {
			return $this->type;
		}

		/**
		 * Gets the setting name.
		 *
		 * @return string
		 */
		public function get_name() {
			return $this->name;
		}

		/**
		 * Gets the setting description.
		 *
		 * @return string
		 */
		public function get_description() {
			return $this->description;
		}

		/**
		 * Returns whether the setting holds an array of multiple values.
		 *
		 * @return bool
		 */
		public function is_is_multi() {
			return $this->is_multi;
		}


		/**
		 * Gets the setting options.
		 *
		 * @return array
		 */
		public function get_options() {
			return $this->options;
		}

		/**
		 * Gets the setting default value.
		 *
		 * @return mixed
		 */
		public function get_default() {
			return $this->default;
		}

		/**
		 * Gets the setting current value.
		 *
		 * @return mixed
		 */
		public function get_value() {
			if ( null !== $this->constant_name && defined( $this->constant_name ) ) {
				return constant( $this->constant_name );
			}

			return $this->value;
		}

		/**
		 * Gets the setting control.
		 *
		 * @return Woodev_Control
		 */
		public function get_control() {
			return $this->control;
		}

		/**
		 * Whether this setting holds a secret value (masked in the schema).
		 *
		 * @since 2.0.2
		 * @return bool
		 */
		public function is_sensitive(): bool {
			return $this->sensitive;
		}

		/**
		 * Sets the sensitive flag.
		 *
		 * @since 2.0.2
		 * @param bool $value sensitive flag.
		 * @return void
		 */
		public function set_sensitive( bool $value ): void {
			$this->sensitive = $value;
		}

		/**
		 * The PHP constant name backing this setting, or null.
		 *
		 * @since 2.0.2
		 * @return string|null
		 */
		public function get_constant_name(): ?string {
			return $this->constant_name;
		}

		/**
		 * Sets the backing constant name.
		 *
		 * @since 2.0.2
		 * @param string|null $value constant name.
		 * @return void
		 */
		public function set_constant_name( ?string $value ): void {
			$this->constant_name = ( null === $value || '' === $value ) ? null : $value;
		}

		/**
		 * Sets the setting ID.
		 *
		 * @param string $id
		 */
		public function set_id( $id ) {
			$this->id = $id;
		}

		/**
		 * Sets the setting type.
		 *
		 * @param string $type
		 */
		public function set_type( $type ) {
			$this->type = $type;
		}


		/**
		 * Sets the setting name.
		 *
		 * @param string $name
		 */
		public function set_name( $name ) {
			$this->name = $name;
		}

		/**
		 * Sets the setting description.
		 *
		 * @param string $description
		 */
		public function set_description( $description ) {
			$this->description = $description;
		}

		/**
		 * Sets whether the setting holds an array of multiple values.
		 *
		 * @param bool $is_multi
		 */
		public function set_is_multi( $is_multi ) {
			$this->is_multi = $is_multi;
		}

		/**
		 * Sets the setting options.
		 *
		 * @param array $options
		 */
		public function set_options( $options ) {

			foreach ( $options as $key => $option ) {

				// Keep the option when EITHER its KEY (the submittable token for an
				// associative [ key => label ] map) OR its VALUE (a plain [ value, value ]
				// list) is valid for the setting type. Validating only the label wrongly
				// dropped whole enums whose labels are free-text display strings (e.g. an
				// integer setting registered as [ 0 => 'Zero', 1 => 'One' ]), which left
				// validation with no options and silently accepted any value of the type.
				if ( ! $this->validate_value( $key ) && ! $this->validate_value( $option ) ) {
					unset( $options[ $key ] );
				}
			}

			$this->options = $options;
		}

		/**
		 * Sets the setting default value.
		 *
		 * @param mixed $value default value to set
		 */
		public function set_default( $value ) {

			if ( $this->is_is_multi() ) {

				$_value = array_filter( (array) $value, [ $this, 'validate_value' ] );

				// clear the default if all values were invalid
				$value = ! empty( $_value ) ? $_value : null;

			} elseif ( ! $this->validate_value( $value ) ) {

				$value = null;
			}

			$this->default = $value;
		}

		/**
		 * Sets the setting current value.
		 *
		 * @param mixed $value
		 */
		public function set_value( $value ) {
			$this->value = $value;
		}

		/**
		 * Sets the setting control.
		 *
		 * @param Woodev_Control $control
		 */
		public function set_control( $control ) {
			$this->control = $control;
		}

		/**
		 * Sets the setting current value, after validating it against the type and, if set, options.
		 *
		 * For is_multi settings, $value must be an array; each element is validated individually
		 * against the type and, when options are configured, against the option keys or values.
		 * For scalar settings, $value is validated directly.
		 *
		 * @param mixed $value
		 * @throws Woodev_Plugin_Exception
		 */
		public function update_value( $value ) {

			if ( $this->is_is_multi() ) {

				$elements = array_map(
					function ( $element ) {
						return $this->sanitize_value( $this->coerce_value( $element ) );
					},
					array_values( (array) $value )
				);

				foreach ( $elements as $element ) {
					$this->assert_valid_value( $element );
				}

				$this->set_value( $elements );

			} else {

				$value = $this->sanitize_value( $this->coerce_value( $value ) );

				$this->assert_valid_value( $value );
				$this->set_value( $value );
			}
		}

		/**
		 * Coerces a numeric string to the setting's numeric type.
		 *
		 * HTML number inputs submit their value as a string (e.g. "5000"); the strict
		 * is_int()/is_float() validators would reject it. Coercion is applied only for
		 * the integer/float setting types and only when the value is genuinely numeric;
		 * everything else is returned untouched so non-numeric types keep their strict
		 * validation.
		 *
		 * @param mixed $value
		 * @return mixed int|float for numeric types, the value untouched otherwise
		 */
		private function coerce_value( $value ) {

			if ( self::TYPE_INTEGER === $this->type && is_numeric( $value ) && (float) (int) $value === (float) $value ) {
				return (int) $value;
			}

			if ( self::TYPE_FLOAT === $this->type && is_numeric( $value ) ) {
				return (float) $value;
			}

			return $value;
		}

		/**
		 * Sanitizes an HTML-bearing value before persistence.
		 *
		 * A richtext control submits raw HTML; without sanitization a privileged user
		 * could persist script-capable markup that is later re-rendered (stored XSS).
		 * Run it through wp_kses_post() — which also strips unsafe link protocols such as
		 * javascript: — when the setting's control is a richtext editor. All other
		 * controls store their value verbatim.
		 *
		 * @param mixed $value
		 * @return mixed wp_kses_post()'d string for a richtext control, the value untouched otherwise
		 */
		private function sanitize_value( $value ) {

			if ( is_string( $value ) ) {

				$control = $this->get_control();

				if ( $control instanceof Woodev_Control && Woodev_Control::TYPE_RICHTEXT === $control->get_type() ) {
					return wp_kses_post( $value );
				}
			}

			return $value;
		}

		/**
		 * Asserts that a single scalar value is valid for this setting's type and options.
		 *
		 * Accepts a value when it is either an option KEY (array_key_exists on assoc maps)
		 * or an option VALUE (strict in_array), covering both associative [key=>label] and
		 * plain list [label, label] registration styles.
		 *
		 * @param mixed $value
		 * @throws Woodev_Plugin_Exception
		 */
		private function assert_valid_value( $value ) {

			if ( ! $this->validate_value( $value ) ) {
				throw new Woodev_Plugin_Exception( "Setting value for setting {$this->id} is not valid for the setting type {$this->type}", 400 );
			}

			if ( ! empty( $this->options )
				&& ! ( is_scalar( $value ) && array_key_exists( $value, $this->options ) )
				&& ! in_array( $value, $this->options, true ) ) {

				throw new Woodev_Plugin_Exception(
					sprintf(
						'Setting value for setting %s must be one of %s',
						$this->id,
						Woodev_Helper::list_array_items( $this->options, 'or' )
					),
					400
				);
			}
		}

		/**
		 * Validates the setting value.
		 *
		 * @param mixed $value
		 * @return bool
		 */
		public function validate_value( $value ) {

			$validate_method = "validate_{$this->get_type()}_value";

			return is_callable( [ $this, $validate_method ] ) ? $this->$validate_method( $value ) : true;
		}

		/**
		 * Validates a string value.
		 *
		 * @param mixed $value value to validate
		 * @return bool
		 */
		protected function validate_string_value( $value ) {
			return is_string( $value );
		}

		/**
		 * Validates a URL value.
		 *
		 * @param mixed $value value to validate
		 * @return bool
		 */
		protected function validate_url_value( $value ) {
			return self::is_valid_url( $value );
		}

		/**
		 * Validates a URL using the previous WooCommerce helper semantics.
		 *
		 * @param string $url URL to validate.
		 * @return bool
		 */
		private static function is_valid_url( $url ) {

			if ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
				return false;
			}

			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Validates an email value.
		 *
		 * @param mixed $value value to validate
		 * @return bool
		 */
		protected function validate_email_value( $value ) {
			return (bool) is_email( $value );
		}

		/**
		 * Validates an integer value.
		 *
		 * @param mixed $value value to validate
		 * @return bool
		 */
		protected function validate_integer_value( $value ) {
			return is_int( $value );
		}

		/**
		 * Validates a float value.
		 *
		 * @param mixed $value value to validate
		 * @return bool
		 */
		protected function validate_float_value( $value ) {
			return is_int( $value ) || is_float( $value );
		}

		/**
		 * Validates a boolean value.
		 *
		 * @param mixed $value value to validate
		 * @return bool
		 */
		protected function validate_boolean_value( $value ) {
			return is_bool( $value );
		}
	}

endif;
