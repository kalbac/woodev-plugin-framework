<?php
/**
 * Records engine — a provider refuses the input of an action.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by {@see Records_Provider::preview()} or {@see Records_Provider::apply()} when a value
 * the engine could only check by shape (for example a warehouse from a local options source) is
 * not acceptable. The engine answers 422 with each sentence under its field id.
 *
 * Sentences are shown to the merchant as written, so they must be merchant-safe.
 *
 * @since 2.1.0
 */
final class Action_Input_Exception extends \RuntimeException {
	/**
	 * @var array<string,string>
	 */
	private $errors;

	/**
	 * @since 2.1.0
	 *
	 * @param array<string,string> $errors Declared field id => sentence for the merchant.
	 */
	public function __construct( array $errors ) {
		parent::__construct( 'The action input was refused.' );

		$this->errors = $errors;
	}

	/**
	 * @since 2.1.0
	 *
	 * @return array<string,string> Field id => sentence.
	 */
	public function get_errors(): array {
		return $this->errors;
	}
}
