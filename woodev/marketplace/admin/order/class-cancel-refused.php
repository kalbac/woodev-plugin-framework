<?php
/**
 * A refused cancellation of a marketplace posting.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by {@see Cancel_Ajax} and by an {@see Order_Canceller} when a cancellation must not go ahead. The message is a sentence for
 * the merchant; the status is the HTTP status of the AJAX answer (403 no right, 404 not a marketplace order, 409 wrong state,
 * 502 the marketplace refused or did not answer).
 *
 * @since 2.1.2
 */
final class Cancel_Refused extends \RuntimeException {
	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Creates the exception.
	 *
	 * @since 2.1.2
	 *
	 * @param string $message Sentence for the merchant.
	 * @param int    $status  HTTP status of the answer.
	 */
	public function __construct( string $message, int $status = 409 ) {
		parent::__construct( $message );

		$this->status = $status;
	}

	/**
	 * Returns the HTTP status of the answer.
	 *
	 * @since 2.1.2
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}
}
