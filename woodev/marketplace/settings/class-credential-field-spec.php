<?php
/**
 * UI and validation specification for one marketplace credential field.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Describes how a single credential field declared by {@see \Woodev\Framework\Marketplace\Account\Credential_Fields}
 * renders on the settings page and validates a submitted value.
 *
 * @since 2.1.2
 */
final class Credential_Field_Spec {
	/**
	 * Field label.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Optional tooltip text.
	 *
	 * @var string
	 */
	private $tooltip;

	/**
	 * Optional placeholder text.
	 *
	 * @var string
	 */
	private $placeholder;

	/**
	 * Optional validator (`function ( string $value ): ?string`), returning null when valid or a localized error message.
	 *
	 * @var callable|null
	 */
	private $validator;

	/**
	 * Creates the field specification.
	 *
	 * @since 2.1.2
	 *
	 * @param string        $label       Localized field label (e.g. `Client-Id`, `Ключ API`).
	 * @param string        $tooltip     Optional localized tooltip text.
	 * @param string        $placeholder Optional input placeholder.
	 * @param callable|null $validator   `function ( string $value ): ?string` — null when valid, error text otherwise.
	 */
	public function __construct( string $label, string $tooltip = '', string $placeholder = '', ?callable $validator = null ) {
		$this->label       = $label;
		$this->tooltip     = $tooltip;
		$this->placeholder = $placeholder;
		$this->validator   = $validator;
	}

	/**
	 * Returns the localized field label.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Returns the localized tooltip text, or '' when none is needed.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function tooltip(): string {
		return $this->tooltip;
	}

	/**
	 * Returns the input placeholder, or '' when none is needed.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function placeholder(): string {
		return $this->placeholder;
	}

	/**
	 * Validates a non-empty submitted value.
	 *
	 * @since 2.1.2
	 *
	 * @param string $value Trimmed submitted value.
	 * @return string|null Null when valid; localized error message when invalid.
	 */
	public function validate( string $value ): ?string {
		if ( null === $this->validator ) {
			return null;
		}

		$result = call_user_func( $this->validator, $value );

		return is_string( $result ) && '' !== $result ? $result : null;
	}
}
