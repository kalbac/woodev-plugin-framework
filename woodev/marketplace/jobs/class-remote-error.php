<?php
/**
 * A refused marketplace call, as the jobs read it.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * What a job must do after a marketplace call failed as a whole. The adapter classifies its own exception into one of five
 * consequences ({@see Error_Classifier}); the runners act on the consequence and never on a marketplace's codes:
 *
 * | Consequence | Meaning | What a runner does |
 * |---|---|---|
 * | `PAUSE_ACCOUNT` | the marketplace refuses the account's credentials | pauses the account ({@see Account_Pause}); the items wait |
 * | `THROTTLE` | the marketplace asked to wait | waits {@see self::retry_after()}; not a failed attempt |
 * | `RETRY` | the outcome is uncertain (timeout, server error): the call may have been applied | counts a failure, backs off |
 * | `FAIL` | the marketplace refused these items for good (a validation error) | counts a failure with the text |
 * | `API_CHANGED` | the marketplace no longer offers the method | counts a failure and stops the run |
 *
 * @since 2.1.0
 */
final class Remote_Error {
	public const PAUSE_ACCOUNT = 'pause_account';
	public const THROTTLE      = 'throttle';
	public const RETRY         = 'retry';
	public const FAIL          = 'fail';
	public const API_CHANGED   = 'api_changed';

	private const CONSEQUENCES = [ self::PAUSE_ACCOUNT, self::THROTTLE, self::RETRY, self::FAIL, self::API_CHANGED ];

	/**
	 * Consequence.
	 *
	 * @var string
	 */
	private $consequence;

	/**
	 * The adapter's own short name of the error, for the log (`auth_invalid`, `rate_limited`).
	 *
	 * @var string
	 */
	private $kind;

	/**
	 * The marketplace's text, verbatim.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Seconds the marketplace asked to wait, or null.
	 *
	 * @var int|null
	 */
	private $retry_after;

	/**
	 * Whether the request left this site.
	 *
	 * @var bool
	 */
	private $sent;

	/**
	 * Creates the error.
	 *
	 * @since 2.1.0
	 *
	 * @param string   $consequence One of the consequence constants.
	 * @param string   $kind        The adapter's short name of the error, for the log.
	 * @param string   $message     The marketplace's text, verbatim.
	 * @param int|null $retry_after Seconds the marketplace asked to wait, or null when it named none.
	 * @param bool     $sent        False when the request never left this site (held back by the rate limiter), so it does
	 *                              not count against a run's request budget.
	 * @throws \InvalidArgumentException When the consequence is not one of the constants.
	 */
	public function __construct( string $consequence, string $kind, string $message, ?int $retry_after = null, bool $sent = true ) {
		if ( ! in_array( $consequence, self::CONSEQUENCES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown consequence of a remote error: ' . $consequence ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$this->consequence = $consequence;
		$this->kind        = $kind;
		$this->message     = $message;
		$this->retry_after = $retry_after;
		$this->sent        = $sent;
	}

	/**
	 * Returns the consequence.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function consequence(): string {
		return $this->consequence;
	}

	/**
	 * Tells whether the error has a given consequence.
	 *
	 * @since 2.1.0
	 *
	 * @param string $consequence One of the consequence constants.
	 * @return bool
	 */
	public function is( string $consequence ): bool {
		return $this->consequence === $consequence;
	}

	/**
	 * Returns the adapter's short name of the error.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Returns the marketplace's text.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Returns the seconds the marketplace asked to wait, or null.
	 *
	 * @since 2.1.0
	 *
	 * @return int|null
	 */
	public function retry_after(): ?int {
		return $this->retry_after;
	}

	/**
	 * Tells whether the request left this site.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public function was_sent(): bool {
		return $this->sent;
	}

	/**
	 * Returns the line a log shows: the kind and the text.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function describe(): string {
		return $this->kind . ': ' . $this->message;
	}
}
