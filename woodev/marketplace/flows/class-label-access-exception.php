<?php
/**
 * A label download that is not allowed.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * The refusal of {@see Label_Download::authorise()}: the exception code is the HTTP status (403, or 404 for a document that is
 * not there), the message is a sentence safe to show to the person who asked.
 *
 * @since 2.1.0
 */
final class Label_Access_Exception extends \RuntimeException {
	/**
	 * Builds a refusal.
	 *
	 * @since 2.1.0
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Sentence for the person.
	 * @return self
	 */
	public static function with_status( int $status, string $message ): self {
		return new self( $message, $status );
	}
}
