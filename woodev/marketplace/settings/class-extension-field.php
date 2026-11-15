<?php
/**
 * One field of a {@see Settings_Extension}.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Describes a field once, for both screens: «Общие» registers it through {@see Field_Kit} (and so draws and validates it like any
 * other setting), the product tab prints it as a row of its own form. Two kinds exist because they are what both screens can draw
 * today: a select and an on/off switch.
 *
 * A select's value is one of the option keys, as a string; the empty string is a legitimate option (on the product tab it is the
 * «as in the shop settings» choice and stores nothing). A toggle's value is a bool.
 *
 * @since 2.1.7
 */
final class Extension_Field {
	public const TYPE_SELECT = 'select';
	public const TYPE_TOGGLE = 'toggle';

	/**
	 * Id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Kind.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Label.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Options of a select (`value => label`).
	 *
	 * @var array<string,string>
	 */
	private $options;

	/**
	 * Default value: a string for a select, a bool for a toggle.
	 *
	 * @var string|bool
	 */
	private $default;

	/**
	 * Tooltip.
	 *
	 * @var string
	 */
	private $tooltip;

	/**
	 * Use {@see self::select()} or {@see self::toggle()}.
	 *
	 * @param string               $id      Id.
	 * @param string               $type    `self::TYPE_*`.
	 * @param string               $label   Label.
	 * @param array<string,string> $options Options of a select.
	 * @param string|bool          $default Default.
	 * @param string               $tooltip Tooltip.
	 */
	private function __construct( string $id, string $type, string $label, array $options, $default, string $tooltip ) {
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $id ) ) {
			throw new \InvalidArgumentException( 'An extension field id is lower-case letters, digits and underscores.' );
		}

		$this->id      = $id;
		$this->type    = $type;
		$this->label   = $label;
		$this->options = $options;
		$this->default = $default;
		$this->tooltip = $tooltip;
	}

	/**
	 * A select.
	 *
	 * @since 2.1.7
	 *
	 * @param string               $id      Id (`[a-z0-9_]+`).
	 * @param string               $label   Label.
	 * @param array<string,string> $options `value => label`, at least one; the first is the default unless `$default` names another.
	 * @param string|null          $default Default value, one of the options.
	 * @param string               $tooltip Tooltip.
	 * @return self
	 * @throws \InvalidArgumentException When there is no option or the default is not one of them.
	 */
	public static function select( string $id, string $label, array $options, ?string $default = null, string $tooltip = '' ): self {
		$clean = [];

		foreach ( $options as $value => $text ) {
			$clean[ (string) $value ] = (string) $text;
		}

		if ( [] === $clean ) {
			throw new \InvalidArgumentException( 'A select needs at least one option.' );
		}

		$default = $default ?? (string) array_key_first( $clean );

		if ( ! array_key_exists( $default, $clean ) ) {
			throw new \InvalidArgumentException( 'The default of a select is one of its options.' );
		}

		return new self( $id, self::TYPE_SELECT, $label, $clean, $default, $tooltip );
	}

	/**
	 * An on/off switch.
	 *
	 * @since 2.1.7
	 *
	 * @param string $id      Id (`[a-z0-9_]+`).
	 * @param string $label   Label.
	 * @param bool   $default Default.
	 * @param string $tooltip Tooltip.
	 * @return self
	 */
	public static function toggle( string $id, string $label, bool $default = false, string $tooltip = '' ): self {
		return new self( $id, self::TYPE_TOGGLE, $label, [], $default, $tooltip );
	}

	/**
	 * Name of the product meta that holds the value a merchant chose on the product tab.
	 *
	 * @since 2.1.7
	 *
	 * @param string $adapter_id Adapter id.
	 * @param string $field_id   Field id.
	 * @return string
	 */
	public static function product_meta_key( string $adapter_id, string $field_id ): string {
		return '_woodev_' . $adapter_id . '_ext_' . $field_id;
	}

	/**
	 * Id.
	 *
	 * @since 2.1.7
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Kind.
	 *
	 * @since 2.1.7
	 *
	 * @return string `self::TYPE_*`.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Label.
	 *
	 * @since 2.1.7
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Options of a select.
	 *
	 * @since 2.1.7
	 *
	 * @return array<string,string> `value => label`; empty for a toggle.
	 */
	public function options(): array {
		return $this->options;
	}

	/**
	 * Default value.
	 *
	 * @since 2.1.7
	 *
	 * @return string|bool
	 */
	public function default() {
		return $this->default;
	}

	/**
	 * Tooltip.
	 *
	 * @since 2.1.7
	 *
	 * @return string
	 */
	public function tooltip(): string {
		return $this->tooltip;
	}

	/**
	 * Whether a submitted value is one this field can hold: a listed option, or a switch value.
	 *
	 * @since 2.1.7
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public function accepts( $value ): bool {
		if ( self::TYPE_TOGGLE === $this->type ) {
			return is_bool( $value ) || in_array( $value, [ '0', '1', 0, 1 ], true );
		}

		return is_string( $value ) && array_key_exists( $value, $this->options );
	}

	/**
	 * Turns an accepted value into the form that is stored: a string for a select, a bool for a toggle.
	 *
	 * @since 2.1.7
	 *
	 * @param mixed $value A value {@see self::accepts()} said yes to.
	 * @return string|bool
	 */
	public function normalize( $value ) {
		if ( self::TYPE_TOGGLE === $this->type ) {
			return true === $value || 1 === $value || '1' === $value;
		}

		return (string) $value;
	}
}
