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

		/** @var bool whether this setting must be filled (validated client + server) */
		protected $required = false;

		/** @var callable|null plugin-supplied validator overriding the default format check */
		private $validate = null;

		/** @var string custom error message for a failed validate callback */
		private $validate_message = '';

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
		 * Whether this setting is required (must be non-empty for requirable controls).
		 *
		 * @since 2.0.2
		 * @return bool
		 */
		public function is_required(): bool {
			return $this->required;
		}

		/**
		 * Sets the required flag.
		 *
		 * @since 2.0.2
		 * @param bool $value required flag.
		 * @return void
		 */
		public function set_required( bool $value ): void {
			$this->required = $value;
		}

		/**
		 * The plugin-supplied validate callback, or null.
		 *
		 * @since 2.0.2
		 * @return callable|null
		 */
		public function get_validate(): ?callable {
			return $this->validate;
		}

		/**
		 * Sets the validate callback (fn($value): bool). Overrides the default
		 * format/type/enum check for this field; required is still applied.
		 *
		 * NOTE: this OVERRIDES the default format/type AND the options (enum) check
		 * for this field. If the setting has options, the callback must itself
		 * validate the value against the allowed set when that constraint applies.
		 *
		 * @since 2.0.2
		 * @param callable|null $value validator.
		 * @return void
		 */
		public function set_validate( ?callable $value ): void {
			$this->validate = $value;
		}

		/**
		 * The custom message shown when the validate callback fails.
		 *
		 * @since 2.0.2
		 * @return string
		 */
		public function get_validate_message(): string {
			return $this->validate_message;
		}

		/**
		 * Sets the custom validate-failure message.
		 *
		 * @since 2.0.2
		 * @param string $value message.
		 * @return void
		 */
		public function set_validate_message( string $value ): void {
			$this->validate_message = $value;
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

			// A null default means "no default supplied" — never run format validators on it
			// (avoids is_email(null)/strpos(null) etc.; the value stays null either way).
			if ( null === $value ) {
				$this->default = null;
				return;
			}

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

				$control_type = $this->control instanceof Woodev_Control ? $this->control->get_type() : null;

				if ( $this->required && self::is_requirable( $control_type )
					&& 0 === count( array_filter( $elements, fn( $element ) => ! self::is_empty_value( $control_type, $element ) ) ) ) {
					throw new Woodev_Plugin_Exception( __( 'Обязательное поле.', 'woodev-plugin-framework' ), 400 );
				}

				foreach ( $elements as $element ) {
					$error = $this->get_validation_error( $element );
					if ( null !== $error ) {
						throw new Woodev_Plugin_Exception( $error, 400 );
					}
				}

				$this->set_value( $elements );

			} else {

				$value = $this->sanitize_value( $this->coerce_value( $value ) );

				$error = $this->get_validation_error( $value );
				if ( null !== $error ) {
					throw new Woodev_Plugin_Exception( $error, 400 );
				}

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
		 * Returns a human-readable validation error for the given input, or null when valid.
		 *
		 * Single server source of truth for SP-3 validation. Rule order: coerce → required →
		 * empty-optional → format → legacy type → enum. Mirrored client-side in
		 * src/components/validate.js — keep both in sync (the rule table lives in the SP-3
		 * design spec §4).
		 *
		 * @since 2.0.2
		 * @param mixed $value the raw input value.
		 * @return string|null error message (Russian) or null when valid.
		 */
		public function get_validation_error( $value ): ?string {

			$value        = $this->coerce_value( $value );
			$control_type = $this->control instanceof Woodev_Control ? $this->control->get_type() : null;

			if ( $this->required && self::is_requirable( $control_type ) && self::is_empty_value( $control_type, $value ) ) {
				return __( 'Обязательное поле.', 'woodev-plugin-framework' );
			}

			if ( self::is_empty_value( $control_type, $value ) ) {
				return null;
			}

			// A plugin-supplied validate callback overrides the default format/type/enum
			// check for this field (required was already applied above). Server-authoritative.
			if ( is_callable( $this->validate ) ) {
				return (bool) call_user_func( $this->validate, $value )
					? null
					: ( '' !== $this->validate_message ? $this->validate_message : __( 'Неверное значение.', 'woodev-plugin-framework' ) );
			}

			switch ( $control_type ) {

				case Woodev_Control::TYPE_EMAIL:
					// $value is non-empty here (is_empty_value() returned false above). Guard is_string
					// so a crafted non-scalar (e.g. an array POSTed to a scalar field) can't reach is_email().
					if ( ! is_string( $value ) || ! is_email( $value ) ) {
						return __( 'Введите корректный email.', 'woodev-plugin-framework' );
					}
					break;

				case Woodev_Control::TYPE_URL:
					if ( ! self::is_valid_url( $value ) ) {
						return __( 'Введите корректный URL (с http:// или https://).', 'woodev-plugin-framework' );
					}
					break;

				case Woodev_Control::TYPE_TEL:
					if ( ! self::is_valid_tel( $value ) ) {
						return __( 'Введите корректный номер телефона.', 'woodev-plugin-framework' );
					}
					break;

				case Woodev_Control::TYPE_NUMBER:
				case Woodev_Control::TYPE_RANGE:
					if ( ! is_numeric( $value ) ) {
						return __( 'Введите число.', 'woodev-plugin-framework' );
					}
					$min = $this->control->get_min();
					$max = $this->control->get_max();
					if ( null !== $min && (float) $value < $min ) {
						return sprintf( __( 'Значение не меньше %s.', 'woodev-plugin-framework' ), self::format_number( $min ) );
					}
					if ( null !== $max && (float) $value > $max ) {
						return sprintf( __( 'Значение не больше %s.', 'woodev-plugin-framework' ), self::format_number( $max ) );
					}
					break;
			}

			// Legacy type validity (string/url/email/integer/float/boolean).
			if ( ! $this->validate_value( $value ) ) {
				return sprintf( __( 'Недопустимое значение для типа %s.', 'woodev-plugin-framework' ), $this->type );
			}

			// Enum: accept an option KEY (assoc map) or VALUE (plain list).
			if ( ! empty( $this->options )
				&& ! ( is_scalar( $value ) && array_key_exists( $value, $this->options ) )
				&& ! in_array( $value, $this->options, true ) ) {

				return sprintf(
					__( 'Значение должно быть одним из: %s.', 'woodev-plugin-framework' ),
					Woodev_Helper::list_array_items( $this->options, 'or' )
				);
			}

			return null;
		}

		/**
		 * Whether a `required` flag applies to a given control type.
		 *
		 * Toggle/checkbox/range always carry a value, so requiring them is a no-op.
		 *
		 * @since 2.0.2
		 * @param string|null $control_type control type.
		 * @return bool
		 */
		public static function is_requirable( ?string $control_type ): bool {
			return ! in_array(
				$control_type,
				[ Woodev_Control::TYPE_TOGGLE, Woodev_Control::TYPE_CHECKBOX, Woodev_Control::TYPE_RANGE ],
				true
			);
		}

		/**
		 * Whether a value counts as "empty" for the given control type.
		 *
		 * Public so the settings handler (Woodev_Abstract_Settings::validate_values) can
		 * call it when counting non-empty elements in a required is_multi field.
		 *
		 * @since 2.0.2
		 * @param string|null $control_type control type.
		 * @param mixed       $value        value to inspect.
		 * @return bool
		 */
		public static function is_empty_value( ?string $control_type, $value ): bool {

			if ( is_array( $value ) ) {
				return 0 === count( $value );
			}

			if ( in_array( $control_type, [ Woodev_Control::TYPE_SELECT, Woodev_Control::TYPE_RADIO ], true ) ) {
				return '' === (string) $value;
			}

			return '' === trim( (string) $value );
		}

		/**
		 * Permissive phone validator: allowed chars only, at least 5 digits.
		 *
		 * @since 2.0.2
		 * @param mixed $value value to validate.
		 * @return bool
		 */
		private static function is_valid_tel( $value ): bool {

			if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
				return false;
			}

			$value = (string) $value;

			if ( ! preg_match( '/^[\d\s\-\(\)\+]+$/', $value ) ) {
				return false;
			}

			return strlen( (string) preg_replace( '/\D/', '', $value ) ) >= 5;
		}

		/**
		 * Formats a numeric bound without a trailing ".0" for whole numbers.
		 *
		 * @since 2.0.2
		 * @param float $number bound to format.
		 * @return string
		 */
		private static function format_number( float $number ): string {
			return floor( $number ) === $number ? (string) (int) $number : (string) $number;
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

			if ( ! is_string( $url ) ) {
				return false;
			}

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
			return is_string( $value ) && (bool) is_email( $value );
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
