<?php
/**
 * Answer to a notification request.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * Status, JSON body and headers of the HTTP answer. The intake returns one; the REST route (and a plugin's own legacy route)
 * turns it into output, so the logic is testable without a web server.
 *
 * @since 2.1.0
 */
final class Intake_Response {
	/**
	 * The sender is not the marketplace (a request without a token from a foreign address).
	 */
	public const REASON_UNAUTHORISED = 'unauthorised';

	/**
	 * The URL's token used up its minute.
	 */
	public const REASON_RATE_LIMITED = 'rate_limited';

	/**
	 * The notification could not be made durable; the marketplace should send it again.
	 */
	public const REASON_NOT_STORED = 'not_stored';

	public const RETRY_AFTER_SECOND = 60;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Body, encoded as JSON on output.
	 *
	 * @var array<string,mixed>
	 */
	private $body;

	/**
	 * Extra headers.
	 *
	 * @var array<string,string>
	 */
	private $headers;

	/**
	 * Creates a response.
	 *
	 * @since 2.1.0
	 *
	 * @param int                  $status  HTTP status.
	 * @param array<string,mixed>  $body    Body.
	 * @param array<string,string> $headers Extra headers.
	 */
	public function __construct( int $status, array $body, array $headers = [] ) {
		$this->status  = $status;
		$this->body    = $body;
		$this->headers = $headers;
	}

	/**
	 * The success answer: HTTP 200 and the body the marketplace expects.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $body Success body.
	 * @return self
	 */
	public static function ok( array $body ): self {
		return new self( 200, $body );
	}

	/**
	 * Returns the HTTP status.
	 *
	 * @since 2.1.0
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Returns the body.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,mixed>
	 */
	public function body(): array {
		return $this->body;
	}

	/**
	 * Returns the extra headers.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,string>
	 */
	public function headers(): array {
		return $this->headers;
	}
}
