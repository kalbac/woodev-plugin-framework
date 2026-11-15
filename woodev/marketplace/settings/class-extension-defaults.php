<?php
/**
 * The «Общие» side of a {@see Settings_Extension}.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Settings\Settings_Group;

defined( 'ABSPATH' ) || exit;

/**
 * Registers an adapter's default fields on the settings handler, lists them as groups of the «Общие» section and reads and writes
 * their values. {@see Defaults_Section} owns one of these when its adapter implements {@see Settings_Extension}, and asks it before it
 * asks itself.
 *
 * Setting ids are `defaults_ext_{field id}`. The values live in the defaults option under {@see Effective_Settings::EXTRA_KEY}, a
 * sub-array of their own: the framework's own keys of that option are filtered by name ({@see Effective_Settings::defaults()}), so an
 * adapter's field can never shadow one of them. Checking a submitted value is the registered setting's job (a select refuses a
 * value it does not list); {@see self::write()} only refuses what the field itself cannot hold.
 *
 * @since 2.1.7
 */
final class Extension_Defaults {
	public const PREFIX = 'defaults_ext_';

	/**
	 * The adapter's fields, by id.
	 *
	 * @var array<string,Extension_Field>|null
	 */
	private $fields;

	/**
	 * The extension.
	 *
	 * @var Settings_Extension
	 */
	private $extension;

	/**
	 * Adapter id (the defaults option is keyed by it).
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * Creates the helper.
	 *
	 * @since 2.1.7
	 *
	 * @param Settings_Extension $extension  The adapter's extension.
	 * @param string             $adapter_id Adapter id.
	 */
	public function __construct( Settings_Extension $extension, string $adapter_id ) {
		$this->extension  = $extension;
		$this->adapter_id = $adapter_id;
	}

	/**
	 * Setting id of a field.
	 *
	 * @since 2.1.7
	 *
	 * @param string $field_id Field id.
	 * @return string
	 */
	public static function setting_id( string $field_id ): string {
		return self::PREFIX . $field_id;
	}

	/**
	 * Whether the adapter has any default field.
	 *
	 * @since 2.1.7
	 *
	 * @return bool
	 */
	public function has_fields(): bool {
		return [] !== $this->fields();
	}

	/**
	 * Registers every field with its control on the handler.
	 *
	 * @since 2.1.7
	 *
	 * @param Settings_Handler $handler Handler.
	 * @return void
	 */
	public function register( Settings_Handler $handler ): void {
		foreach ( $this->fields() as $field ) {
			$id = self::setting_id( $field->id() );

			if ( Extension_Field::TYPE_TOGGLE === $field->type() ) {
				Field_Kit::add_toggle( $handler, $id, $field->label(), (bool) $field->default(), $field->tooltip() );
				continue;
			}

			$options = [];

			foreach ( $field->options() as $value => $label ) {
				$options[] = Field_Kit::option( (string) $value, $label );
			}

			Field_Kit::add_select( $handler, $id, $field->label(), $options, $field->tooltip(), [ 'default' => $field->default() ] );
		}
	}

	/**
	 * One card per group the adapter declared (a group with no field is left out).
	 *
	 * @since 2.1.7
	 *
	 * @return Settings_Group[]
	 */
	public function groups(): array {
		$groups = [];
		$seen   = [];

		foreach ( $this->extension->default_groups() as $group ) {
			$ids = [];

			foreach ( $group->fields() as $field ) {
				if ( ! isset( $seen[ $field->id() ] ) ) {
					$seen[ $field->id() ] = true;
					$ids[]                = self::setting_id( $field->id() );
				}
			}

			if ( [] !== $ids ) {
				$groups[] = Settings_Group::create( self::PREFIX . $group->id(), $group->title() )->with_fields( $ids );
			}
		}

		return $groups;
	}

	/**
	 * Setting ids of every field, in the order the groups list them.
	 *
	 * @since 2.1.7
	 *
	 * @return string[]
	 */
	public function setting_ids(): array {
		return array_map( [ self::class, 'setting_id' ], array_keys( $this->fields() ) );
	}

	/**
	 * Whether `$setting_id` is one of the adapter's default fields.
	 *
	 * @since 2.1.7
	 *
	 * @param string $setting_id Setting id.
	 * @return bool
	 */
	public function handles( string $setting_id ): bool {
		return null !== $this->field_of( $setting_id );
	}

	/**
	 * Reads a field in the form its control expects: the saved value, or the field's default.
	 *
	 * @since 2.1.7
	 *
	 * @param string $setting_id Setting id.
	 * @return string|bool
	 */
	public function read( string $setting_id ) {
		$field = $this->field_of( $setting_id );

		if ( null === $field ) {
			return '';
		}

		$stored = Effective_Settings::for_adapter( $this->adapter_id )->extra( $field->id(), $field->default() );

		return $field->accepts( $stored ) ? $field->normalize( $stored ) : $field->default();
	}

	/**
	 * Saves a field. A value the field cannot hold is ignored (the handler validates a submission before it gets here).
	 *
	 * @since 2.1.7
	 *
	 * @param string $setting_id Setting id.
	 * @param mixed  $value      Submitted value.
	 * @return void
	 */
	public function write( string $setting_id, $value ): void {
		$field = $this->field_of( $setting_id );

		if ( null === $field || ! $field->accepts( $value ) ) {
			return;
		}

		$option = Effective_Settings::option_name( $this->adapter_id );
		$all    = get_option( $option, [] );
		$all    = is_array( $all ) ? $all : [];
		$extra  = isset( $all[ Effective_Settings::EXTRA_KEY ] ) && is_array( $all[ Effective_Settings::EXTRA_KEY ] ) ? $all[ Effective_Settings::EXTRA_KEY ] : [];
		$new    = $field->normalize( $value );

		if ( array_key_exists( $field->id(), $extra ) && $extra[ $field->id() ] === $new ) {
			return;
		}

		$extra[ $field->id() ]                = $new;
		$all[ Effective_Settings::EXTRA_KEY ] = $extra;

		update_option( $option, $all );
	}

	/**
	 * The field behind a setting id.
	 *
	 * @param string $setting_id Setting id.
	 * @return Extension_Field|null
	 */
	private function field_of( string $setting_id ): ?Extension_Field {
		if ( 0 !== strpos( $setting_id, self::PREFIX ) ) {
			return null;
		}

		return $this->fields()[ substr( $setting_id, strlen( self::PREFIX ) ) ] ?? null;
	}

	/**
	 * Every default field of the adapter, by id (the first of two with one id wins).
	 *
	 * @return array<string,Extension_Field>
	 */
	private function fields(): array {
		if ( null === $this->fields ) {
			$this->fields = [];

			foreach ( $this->extension->default_groups() as $group ) {
				foreach ( $group->fields() as $field ) {
					$this->fields[ $field->id() ] = $this->fields[ $field->id() ] ?? $field;
				}
			}
		}

		return $this->fields;
	}
}
