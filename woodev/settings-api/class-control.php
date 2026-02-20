<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Control' ) ) :

	/**
	 * The base control object.
	 */
	class Woodev_Control {

		/** @var string the text control type */
		const TYPE_TEXT = 'text';

		/** @var string the textarea control type */
		const TYPE_TEXTAREA = 'textarea';

		/** @var string the number control type */
		const TYPE_NUMBER = 'number';

		/** @var string the email control type */
		const TYPE_EMAIL = 'email';

		/** @var string the password control type */
		const TYPE_PASSWORD = 'password';

		/** @var string the date control type */
		const TYPE_DATE = 'date';

		/** @var string the checkbox control type */
		const TYPE_CHECKBOX = 'checkbox';

		/** @var string the radio control type */
		const TYPE_RADIO = 'radio';

		/** @var string the select control type */
		const TYPE_SELECT = 'select';

		/** @var string the file control type */
		const TYPE_FILE = 'file';

		/** @var string the color control type */
		const TYPE_COLOR = 'color';

		/** @var string the range control type */
		const TYPE_RANGE = 'range';

		/** @var string|null the setting ID to which this control belongs */
		protected $setting_id;

		/** @var string|null the control type */
		protected $type;

		/** @var string the control name */
		protected $name = '';

		/** @var string the control description */
		protected $description = '';

		/** @var array the control options, as $option => $label */
		protected $options = [];

		/**
		 * The setting ID to which this control belongs.
		 *
		 * @return null|string
		 */
		public function get_setting_id() {

			return $this->setting_id;
		}

		/**
		 * Gets the control type.
		 *
		 * @return null|string
		 */
		public function get_type() {

			return $this->type;
		}

		/**
		 * Gets the control name.
		 *
		 * @return string
		 */
		public function get_name() {
			return $this->name;
		}

		/**
		 * Gets the control description.
		 *
		 * @return string
		 */
		public function get_description() {
			return $this->description;
		}

		/**
		 * Gets the control options.
		 *
		 * As $option => $label for display.
		 *
		 * @return array
		 */
		public function get_options() {
			return $this->options;
		}

		/**
		 * Sets the setting ID.
		 *
		 * @param string $value setting ID to set
		 *
		 * @throws Woodev_Plugin_Exception
		 */
		public function set_setting_id( $value ) {

			if ( ! is_string( $value ) ) {
				throw new Woodev_Plugin_Exception( 'Setting ID value must be a string' );
			}

			$this->setting_id = $value;
		}

		/**
		 * Sets the type.
		 *
		 * @param string   $value       setting ID to set
		 * @param string[] $valid_types allowed control types
		 *
		 * @throws Woodev_Plugin_Exception
		 */
		public function set_type( $value, array $valid_types = [] ) {

			if ( ! empty( $valid_types ) && ! in_array( $value, $valid_types, true ) ) {

				throw new Woodev_Plugin_Exception( sprintf(
					'Control type must be one of %s',
					Woodev_Helper::list_array_items( $valid_types, 'or' )
				) );
			}

			$this->type = $value;
		}

		/**
		 * Sets the name.
		 *
		 * @param string $value control name to set
		 *
		 * @throws Woodev_Plugin_Exception
		 */
		public function set_name( $value ) {

			if ( ! is_string( $value ) ) {
				throw new Woodev_Plugin_Exception( 'Control name value must be a string' );
			}

			$this->name = $value;
		}

		/**
		 * Sets the description.
		 *
		 * @param string $value control description to set
		 *
		 * @throws Woodev_Plugin_Exception
		 */
		public function set_description( $value ) {

			if ( ! is_string( $value ) ) {
				throw new Woodev_Plugin_Exception( 'Control description value must be a string' );
			}

			$this->description = $value;
		}

		/**
		 * Sets the options.
		 *
		 * @param array $options       options to set
		 * @param array $valid_options valid option keys to check against
		 */
		public function set_options( array $options, array $valid_options = [] ) {

			if ( ! empty( $valid_options ) ) {

				foreach ( array_keys( $options ) as $key ) {

					if ( ! in_array( $key, $valid_options, true ) ) {
						unset( $options[ $key ] );
					}
				}
			}

			$this->options = $options;
		}


	}

endif;
