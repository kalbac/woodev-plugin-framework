<?php
/**
 * Setup wizard step-action result.
 *
 * @package Woodev\Framework\Setup
 */

namespace Woodev\Framework\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * The structured outcome of a {@see Step_Action}: `success` or `error`, a message
 * the merchant reads, and optional data for the step's client code.
 *
 * An action callback returns one of these for a *business* outcome it expects ("the key
 * is not valid"). Anything it throws is an *unexpected* failure and never reaches the
 * browser verbatim (see Woodev_REST_API_Setup::run_action()).
 *
 * @since 2.0.2
 */
final class Action_Outcome {

	/** @var string the action did what it was asked to. */
	const STATUS_SUCCESS = 'success';

	/** @var string the action ran, and the answer is "no" (a business outcome, not a crash). */
	const STATUS_ERROR = 'error';

	/** @var string */
	private string $status;

	/** @var string */
	private string $message;

	/** @var array<string,mixed> */
	private array $data;

	/**
	 * Use the named constructors instead.
	 *
	 * @since 2.0.2
	 *
	 * @param string              $status  one of the STATUS_* constants.
	 * @param string              $message message shown to the merchant.
	 * @param array<string,mixed> $data    optional data for client code.
	 */
	private function __construct( string $status, string $message, array $data ) {
		$this->status  = $status;
		$this->message = $message;
		$this->data    = $data;
	}

	/**
	 * Builds a success result.
	 *
	 * @since 2.0.2
	 *
	 * @param string              $message message shown to the merchant.
	 * @param array<string,mixed> $data    optional JSON-serialisable data.
	 * @return self
	 */
	public static function success( string $message = '', array $data = [] ): self {
		return new self( self::STATUS_SUCCESS, $message, $data );
	}

	/**
	 * Builds an error result (the action ran and the outcome is negative).
	 *
	 * @since 2.0.2
	 *
	 * @param string              $message message shown to the merchant.
	 * @param array<string,mixed> $data    optional JSON-serialisable data.
	 * @return self
	 */
	public static function error( string $message, array $data = [] ): self {
		return new self( self::STATUS_ERROR, $message, $data );
	}

	/**
	 * Whether the action succeeded.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return self::STATUS_SUCCESS === $this->status;
	}

	/**
	 * Returns the message.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_message(): string {
		return $this->message;
	}

	/**
	 * Returns the optional data.
	 *
	 * @since 2.0.2
	 *
	 * @return array<string,mixed>
	 */
	public function get_data(): array {
		return $this->data;
	}

	/**
	 * The REST payload: `{ status, message, data }`.
	 *
	 * @since 2.0.2
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return [
			'status'  => $this->status,
			'message' => $this->message,
			'data'    => $this->data,
		];
	}
}
