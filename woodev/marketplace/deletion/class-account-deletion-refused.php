<?php
/**
 * A request to delete an account that cannot be granted.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the sentence the merchant reads and the HTTP status the controller answers with.
 *
 * @since 2.1.5
 */
final class Account_Deletion_Refused extends \RuntimeException {
	/**
	 * Creates the refusal.
	 *
	 * @since 2.1.5
	 *
	 * @param string $message Sentence for the merchant.
	 * @param int    $status  HTTP status: 404 no such account, 409 the account is switched on, 400 the confirmation does not match.
	 */
	public function __construct( string $message, int $status ) {
		parent::__construct( $message, $status );
	}

	/**
	 * The HTTP status.
	 *
	 * @since 2.1.5
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->getCode();
	}
}
