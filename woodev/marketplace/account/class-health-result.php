<?php
/**
 * Credential check outcome.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Account;

defined( 'ABSPATH' ) || exit;

/**
 * The answer of one credential check. `extra` carries adapter data for the settings panel (Ozon: roles, currency,
 * limits; Wildberries: token type and scopes).
 *
 * @since 2.0.29
 */
final class Health_Result {
	public const OK           = 'ok';
	public const AUTH_FAILED  = 'auth_failed';
	public const ROLE_MISSING = 'role_missing';
	public const KEY_EXPIRED  = 'key_expired';
	public const UNREACHABLE  = 'unreachable';

	/**
	 * One of the status constants.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Message for the merchant.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * When the credentials stop working, if the marketplace says.
	 *
	 * @var \DateTimeImmutable|null
	 */
	private $expires_at;

	/**
	 * Adapter-specific data.
	 *
	 * @var array<string,mixed>
	 */
	private $extra;

	/**
	 * Creates a result.
	 *
	 * @since 2.0.29
	 *
	 * @param string                  $status     One of the status constants.
	 * @param string                  $message    Message for the merchant.
	 * @param \DateTimeImmutable|null $expires_at Credential expiry.
	 * @param array<string,mixed>     $extra      Adapter-specific data.
	 * @throws \InvalidArgumentException On an unknown status.
	 */
	public function __construct( string $status, string $message = '', ?\DateTimeImmutable $expires_at = null, array $extra = [] ) {
		if ( ! in_array( $status, self::statuses(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown health status.' );
		}

		$this->status     = $status;
		$this->message    = $message;
		$this->expires_at = $expires_at;
		$this->extra      = $extra;
	}

	/**
	 * Lists every status.
	 *
	 * @since 2.0.29
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return [ self::OK, self::AUTH_FAILED, self::ROLE_MISSING, self::KEY_EXPIRED, self::UNREACHABLE ];
	}

	/**
	 * Returns the status.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Tells whether the credentials work.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_ok(): bool {
		return self::OK === $this->status;
	}

	/**
	 * Returns the message for the merchant.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Returns the credential expiry, or null when unknown.
	 *
	 * @since 2.0.29
	 *
	 * @return \DateTimeImmutable|null
	 */
	public function expires_at(): ?\DateTimeImmutable {
		return $this->expires_at;
	}

	/**
	 * Returns the adapter-specific data.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>
	 */
	public function extra(): array {
		return $this->extra;
	}
}
