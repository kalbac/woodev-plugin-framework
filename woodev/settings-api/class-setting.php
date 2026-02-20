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

				if ( ! $this->validate_value( $option ) ) {
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
		 * @param mixed $value
		 * @throws Woodev_Plugin_Exception
		 */
		public function update_value( $value ) {

			if ( ! $this->validate_value( $value ) ) {

				throw new Woodev_Plugin_Exception( "Setting value for setting {$this->id} is not valid for the setting type {$this->type}", 400 );

			} elseif ( ! empty( $this->options ) && ! in_array( $value, $this->options ) ) {

				throw new Woodev_Plugin_Exception( sprintf(
					'Setting value for setting %s must be one of %s',
					$this->id,
					Woodev_Helper::list_array_items( $this->options, 'or' )
				), 400 );

			} else {

				$this->set_value( $value );
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
			return wc_is_valid_url( $value );
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
