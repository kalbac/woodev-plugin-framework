<?php
/**
 * Seller account value.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Account;

defined( 'ABSPATH' ) || exit;

/**
 * One seller cabinet on one marketplace. Data only: the credentials are an opaque array the adapter understands
 * (Ozon: `client_id` and `api_key`; Wildberries: one `token`), so the layer never inspects them.
 *
 * @since 2.0.29
 */
final class Account {
	/**
	 * Account id, 0 before the account is saved.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Adapter id (`ozon`, `wildberries`).
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * Merchant's label.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Merchant's on/off switch.
	 *
	 * @var bool
	 */
	private $is_enabled;

	/**
	 * Adapter-specific credentials.
	 *
	 * @var array<string,mixed>
	 */
	private $credentials;

	/**
	 * Credential expiry, null when unknown.
	 *
	 * @var \DateTimeImmutable|null
	 */
	private $expires_at;

	/**
	 * Remaining per-account knobs (price rule, rounding, order status defaults).
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * Creates an account.
	 *
	 * @since 2.0.29
	 *
	 * @param int                     $id          Account id, 0 when new.
	 * @param string                  $adapter_id  Adapter id.
	 * @param string                  $name        Merchant's label.
	 * @param bool                    $is_enabled  Merchant's switch.
	 * @param array<string,mixed>     $credentials Opaque credentials.
	 * @param \DateTimeImmutable|null $expires_at  Credential expiry.
	 * @param array<string,mixed>     $settings    Per-account knobs.
	 */
	public function __construct( int $id, string $adapter_id, string $name, bool $is_enabled, array $credentials = [], ?\DateTimeImmutable $expires_at = null, array $settings = [] ) {
		$this->id          = $id;
		$this->adapter_id  = $adapter_id;
		$this->name        = $name;
		$this->is_enabled  = $is_enabled;
		$this->credentials = $credentials;
		$this->expires_at  = $expires_at;
		$this->settings    = $settings;
	}

	/**
	 * Returns the account id.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Returns the adapter id.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function adapter_id(): string {
		return $this->adapter_id;
	}

	/**
	 * Returns the merchant's label.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Tells whether the merchant switched the account on.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->is_enabled;
	}

	/**
	 * Returns the opaque credentials.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>
	 */
	public function credentials(): array {
		return $this->credentials;
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
	 * Returns every per-account knob.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>
	 */
	public function settings(): array {
		return $this->settings;
	}

	/**
	 * Reads one per-account knob.
	 *
	 * @since 2.0.29
	 *
	 * @param string $key     Knob name.
	 * @param mixed  $fallback Value when the knob is not set.
	 * @return mixed
	 */
	public function setting( string $key, $fallback = null ) {
		return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $fallback;
	}
}
