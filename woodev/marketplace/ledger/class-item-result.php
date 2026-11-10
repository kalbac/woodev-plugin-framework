<?php
/**
 * Per-item outcome of a marketplace write.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * What the marketplace said about one link in a stock push, a price push or a task poll.
 *
 * `code` and `message` are the marketplace's own words, kept verbatim for the merchant. `retry_after_seconds` is set
 * when the marketplace asked us to wait (Ozon `TOO_MANY_REQUESTS` stock rows: 31 s).
 *
 * @since 2.0.29
 */
final class Item_Result {
	/**
	 * Link id.
	 *
	 * @var int
	 */
	private $link_id;

	/**
	 * Whether the marketplace accepted the item.
	 *
	 * @var bool
	 */
	private $ok;

	/**
	 * Marketplace error code, empty on success.
	 *
	 * @var string
	 */
	private $code;

	/**
	 * Marketplace message, empty on success.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Seconds to wait before retrying, or null.
	 *
	 * @var int|null
	 */
	private $retry_after_seconds;

	/**
	 * Remote identifiers learned from the answer.
	 *
	 * @var array<string,int|string>
	 */
	private $remote_ids;

	/**
	 * Creates a result; prefer `success()` and `failure()`.
	 *
	 * @since 2.0.29
	 *
	 * @param int                      $link_id             Link id.
	 * @param bool                     $ok                  Accepted or not.
	 * @param string                   $code                Error code.
	 * @param string                   $message             Error message.
	 * @param int|null                 $retry_after_seconds Wait before retry.
	 * @param array<string,int|string> $remote_ids          Remote identifiers.
	 */
	public function __construct( int $link_id, bool $ok, string $code = '', string $message = '', ?int $retry_after_seconds = null, array $remote_ids = [] ) {
		$this->link_id             = $link_id;
		$this->ok                  = $ok;
		$this->code                = $code;
		$this->message             = $message;
		$this->retry_after_seconds = $retry_after_seconds;
		$this->remote_ids          = $remote_ids;
	}

	/**
	 * Builds an accepted result.
	 *
	 * @since 2.0.29
	 *
	 * @param int                      $link_id    Link id.
	 * @param array<string,int|string> $remote_ids Remote identifiers learned from the answer.
	 * @return self
	 */
	public static function success( int $link_id, array $remote_ids = [] ): self {
		return new self( $link_id, true, '', '', null, $remote_ids );
	}

	/**
	 * Builds a rejected result.
	 *
	 * @since 2.0.29
	 *
	 * @param int      $link_id             Link id.
	 * @param string   $code                Marketplace error code.
	 * @param string   $message             Marketplace message, verbatim.
	 * @param int|null $retry_after_seconds Seconds to wait before retrying, or null when a retry is pointless.
	 * @return self
	 */
	public static function failure( int $link_id, string $code, string $message, ?int $retry_after_seconds = null ): self {
		return new self( $link_id, false, $code, $message, $retry_after_seconds );
	}

	/**
	 * Returns the link id.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function link_id(): int {
		return $this->link_id;
	}

	/**
	 * Tells whether the marketplace accepted the item.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_ok(): bool {
		return $this->ok;
	}

	/**
	 * Returns the marketplace error code.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Returns the marketplace message.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Returns the seconds to wait before a retry, or null.
	 *
	 * @since 2.0.29
	 *
	 * @return int|null
	 */
	public function retry_after_seconds(): ?int {
		return $this->retry_after_seconds;
	}

	/**
	 * Returns the remote identifiers learned from the answer.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,int|string>
	 */
	public function remote_ids(): array {
		return $this->remote_ids;
	}
}
