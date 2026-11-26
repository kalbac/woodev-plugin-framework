<?php
/**
 * A seller action that did not go ahead at all.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the framework when the account or the licence does not allow an action, and by an adapter when nothing of a call was
 * done. The message is a sentence for the merchant; the status is the HTTP status of the AJAX answer (403 no right or no licence,
 * 404 not a marketplace order, 409 wrong state, 502 the marketplace refused or did not answer).
 *
 * @since 2.1.27
 */
final class Seller_Action_Refused extends \RuntimeException {
	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * @since 2.1.27
	 *
	 * @param string $message Sentence for the merchant.
	 * @param int    $status  HTTP status of the answer.
	 */
	public function __construct( string $message, int $status = 409 ) {
		parent::__construct( $message );

		$this->status = $status;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return int HTTP status of the answer.
	 */
	public function status(): int {
		return $this->status;
	}
}
