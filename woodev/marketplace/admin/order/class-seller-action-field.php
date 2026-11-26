<?php
/**
 * One input of a seller action.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Admin\Records\Records_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * A field of the dialog of a {@see Seller_Action}: a line of text, a longer text, a scanned code or a choice from a list.
 * The framework checks the shape ({@see Seller_Action_Input}); the marketplace's own rule is the adapter's
 * ({@see Seller_Actions::check_seller_input()}).
 *
 * @since 2.1.27
 */
final class Seller_Action_Field {
	/**
	 * A line of text; tags and extra spaces are removed from it.
	 */
	public const TEXT = 'text';

	/**
	 * A longer text.
	 */
	public const TEXTAREA = 'textarea';

	/**
	 * A code that a scanner or a copy brings in, passed on exactly as typed: no character is removed, and the group separator
	 * (U+001D) of a marking code is allowed. The «Заказы» table takes it without that separator; the order screen takes it with it.
	 */
	public const CODE = 'code';

	/**
	 * A choice from a list: fixed (`options`) or read from the adapter ({@see Seller_Action_Options}).
	 */
	public const SELECT = 'select';

	private const TYPES = [ self::TEXT, self::TEXTAREA, self::CODE, self::SELECT ];

	private const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/D';

	/**
	 * @var string
	 */
	private $id;

	/**
	 * @var string
	 */
	private $label;

	/**
	 * @var string
	 */
	private $type;

	/**
	 * @var bool
	 */
	private $required;

	/**
	 * @var string
	 */
	private $help;

	/**
	 * Fixed choices: value => label.
	 *
	 * @var array<string,string>
	 */
	private $options;

	/**
	 * Whether the choices come from the adapter.
	 *
	 * @var bool
	 */
	private $options_source;

	/**
	 * Declares a field.
	 *
	 * @since 2.1.27
	 *
	 * @param string              $id    Field id: lower-case letters, digits, `_` and `-`, at most 32.
	 * @param string              $label Label, translated.
	 * @param string              $type  One of {@see self::TEXT}, {@see self::TEXTAREA}, {@see self::CODE}, {@see self::SELECT}.
	 * @param array<string,mixed> $args  Optional: `required` (bool), `help` (string), `options` (value => label) or
	 *                                   `options_source` (true) for a list.
	 * @throws \InvalidArgumentException When the declaration cannot work.
	 */
	public function __construct( string $id, string $label, string $type = self::TEXT, array $args = [] ) {
		$options = (array) ( $args['options'] ?? [] );
		$source  = true === ( $args['options_source'] ?? false );

		if ( 1 !== preg_match( self::ID_PATTERN, $id ) || '' === trim( $label ) || ! in_array( $type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( 'A seller action field needs a slug id, a label and a known type.' );
		}

		if ( self::SELECT === $type && ( [] === $options ) !== $source ) {
			throw new \InvalidArgumentException( 'A list field has either fixed options or an options source.' );
		}

		foreach ( array_keys( $options ) as $value ) {
			if ( 1 !== preg_match( Records_Definition::VALUE_PATTERN, (string) $value ) ) {
				throw new \InvalidArgumentException( 'A list field value holds letters, digits, "_", ".", ":" and "-" only.' );
			}
		}

		$this->id             = $id;
		$this->label          = $label;
		$this->type           = $type;
		$this->required       = true === ( $args['required'] ?? false );
		$this->help           = (string) ( $args['help'] ?? '' );
		$this->options        = $options;
		$this->options_source = $source;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return bool
	 */
	public function is_required(): bool {
		return $this->required;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return string Empty for none.
	 */
	public function help(): string {
		return $this->help;
	}

	/**
	 * Returns the fixed choices.
	 *
	 * @since 2.1.27
	 *
	 * @return array<int,array{value:string,label:string}> Empty unless the field is a list with fixed choices.
	 */
	public function options(): array {
		$options = [];

		foreach ( $this->options as $value => $label ) {
			$options[] = [
				'value' => (string) $value,
				'label' => (string) $label,
			];
		}

		return $options;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return bool Whether the choices come from the adapter.
	 */
	public function has_options_source(): bool {
		return $this->options_source;
	}
}
