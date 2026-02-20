<?php

defined( 'ABSPATH' ) or exit;

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
	 * @param string $id unique setting ID
	 * @param string $type setting type
	 * @param array $args setting arguments
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

			$args = wp_parse_args( $args, [
				'name'         => '',
				'description'  => '',
				'is_multi'     => false,
				'options'      => [],
				'default'      => null,
			] );

			$setting->set_name( $args['name'] );
			$setting->set_description( $args['description'] );
			$setting->set_default( $args['default'] );
			$setting->set_is_multi( $args['is_multi'] );

			if ( is_array( $args['options'] ) ) {
				$setting->set_options( $args['options'] );
			}

			$this->settings[ $id ] = $setting;

			return true;

		} catch ( Exception $exception ) {

			wc_doing_it_wrong( __METHOD__, 'Could not register setting: ' . $exception->getMessage(), '1.1.2' );

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
	 * @param array $args optional args for the control
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

			$setting_control_types = $this->get_setting_control_types( $setting );
			if ( ! empty( $setting_control_types ) && ! in_array( $type, $setting_control_types, true ) ) {
				throw new UnexpectedValueException( "{$type} is not a valid control type for setting {$setting->get_id()} of type {$setting->get_type()}" );
			}

			$args = wp_parse_args( $args, [
				'name'        => $setting->get_name(),
				'description' => $setting->get_description(),
				'options'     => [],
			] );

			$control = new Woodev_Control();

			$control->set_setting_id( $setting_id );
			$control->set_type( $type );
			$control->set_name( $args['name'] );
			$control->set_description( $args['description'] );

			if ( is_array( $args['options'] ) ) {
				$control->set_options( $args['options'], $setting->get_options() );
			}

			$setting->set_control( $control );

			return true;

		} catch ( Exception $exception ) {

			wc_doing_it_wrong( __METHOD__, 'Could not register setting control: ' . $exception->getMessage(), '1.1.2' );

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
	 * @param bool $with_default whether to return the default value if nothing is stored
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
	 * @param mixed $value
	 * @throws Woodev_Plugin_Exception
	 */
	public function update_value( $setting_id, $value ) {

		$setting = $this->get_setting( $setting_id );

		if ( ! $setting ) {
			throw new Woodev_Plugin_Exception( "Setting {$setting_id} does not exist", 404 );
		}

		// performs the validations and updates the value
		$setting->update_value( $value );

		$this->save( $setting_id );
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
	 * @param Woodev_Setting $setting
	 * @return mixed
	 */
	protected function get_value_for_database( Woodev_Setting $setting ) {

		$value = $setting->get_value();

		if ( null !== $value && Woodev_Setting::TYPE_BOOLEAN === $setting->get_type() ) {
			$value = wc_bool_to_string( $value );
		}

		return $value;
	}

	/**
	 * Converts the stored value of a setting to the proper setting type.
	 *
	 * @param mixed $value the value stored in an option
	 * @param Woodev_Setting $setting
	 * @return mixed
	 */
	protected function get_value_from_database( $value, Woodev_Setting $setting ) {

		if ( null !== $value ) {

			switch ( $setting->get_type() ) {

				case Woodev_Setting::TYPE_BOOLEAN:
					$value = wc_string_to_bool( $value );
				break;

				case Woodev_Setting::TYPE_INTEGER:
					$value = is_numeric( $value ) ? (int) $value : null;
				break;

				case Woodev_Setting::TYPE_FLOAT:
					$value = is_numeric( $value ) ? (float) $value : null;
				break;
			}
		}

		return $value;
	}

	/**
	 * Gets the list of valid setting types.
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
			'object'
		];

		/**
		 * Filters the list of valid setting types.
		 *
		 * @param string[] $setting_types valid setting types
		 * @param Woodev_Abstract_Settings $settings the settings handler instance
		 */
		return apply_filters( "woodev_{$this->get_id()}_settings_api_setting_types", $setting_types, $this );
	}

	/**
	 * Gets the list of valid control types.
	 *
	 * @return string[]
	 */
	public function get_control_types() {

		$control_types = [
			Woodev_Control::TYPE_TEXT,
			Woodev_Control::TYPE_TEXTAREA,
			Woodev_Control::TYPE_NUMBER,
			Woodev_Control::TYPE_EMAIL,
			Woodev_Control::TYPE_PASSWORD,
			Woodev_Control::TYPE_DATE,
			Woodev_Control::TYPE_CHECKBOX,
			Woodev_Control::TYPE_RADIO,
			Woodev_Control::TYPE_SELECT,
			Woodev_Control::TYPE_FILE,
			Woodev_Control::TYPE_COLOR,
			Woodev_Control::TYPE_RANGE,
		];

		/**
		 * Filters the list of valid control types.
		 *
		 * @param string[] $control_types valid control types
		 * @param Woodev_Abstract_Settings $settings the settings handler instance
		 */
		return apply_filters( "woodev_{$this->get_id()}_settings_api_control_types", $control_types, $this );
	}

	/**
	 * Returns the valid control types for a setting.
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
		return apply_filters( "woodev_{$this->get_id()}_settings_api_setting_control_types", [], $setting->get_type(), $setting, $this );
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
