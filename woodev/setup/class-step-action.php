<?php
/**
 * Setup wizard step action.
 *
 * @package Woodev\Framework\Setup
 */

namespace Woodev\Framework\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * A typed server-side operation bound to one wizard step ("check the key", "start from
 * scratch", "clear the old data"), run through REST and answered with an {@see Action_Outcome}.
 *
 * The callback receives the step's *submitted, unsaved* values (only fields declared on the
 * step) and the REST request: `fn( array $values, \WP_REST_Request $request ): Action_Outcome`.
 * An action never persists step values by itself — if its callback should write something,
 * the callback does it. A callback that RESETS values the merchant may have typed returns
 * `Action_Outcome::success( … )->discarding_edits()` so the form drops those edits; a read-only one
 * (a key check) must not. A destructive action declares so and the client asks the merchant
 * to confirm before it runs; the server refuses an unconfirmed destructive run as well.
 *
 * @since 2.0.2
 */
final class Step_Action {

	/** @var string */
	private string $id;

	/** @var string */
	private string $label;

	/** @var callable */
	private $callback;

	/** @var bool */
	private bool $destructive;

	/** @var string */
	private string $confirm_message;

	/**
	 * Use {@see self::create()} instead.
	 *
	 * @since 2.0.2
	 *
	 * @param string   $id              action id.
	 * @param string   $label           button label.
	 * @param callable $callback        the operation.
	 * @param bool     $destructive     whether the operation destroys or overwrites data.
	 * @param string   $confirm_message confirmation text for a destructive action.
	 */
	private function __construct( string $id, string $label, callable $callback, bool $destructive, string $confirm_message ) {
		$this->id              = $id;
		$this->label           = $label;
		$this->callback        = $callback;
		$this->destructive     = $destructive;
		$this->confirm_message = $confirm_message;
	}

	/**
	 * Builds an action.
	 *
	 * @since 2.0.2
	 *
	 * @param string   $id              action id; letters, digits, `_` and `-` (it is a URL segment).
	 * @param string   $label           button label shown to the merchant.
	 * @param callable $callback        `fn( array $values, \WP_REST_Request $request ): Action_Outcome`.
	 * @param bool     $destructive     true when the operation destroys or overwrites data — the client confirms first.
	 * @param string   $confirm_message optional confirmation text (a generic one is used when empty).
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the id is empty or not a valid URL segment.
	 */
	public static function create( string $id, string $label, callable $callback, bool $destructive = false, string $confirm_message = '' ): self {
		if ( 1 !== preg_match( '/^[\w-]+$/', $id ) ) {
			throw new \InvalidArgumentException( 'A setup wizard action id may only contain letters, digits, "_" and "-".' );
		}

		return new self( $id, $label, $callback, $destructive, $confirm_message );
	}

	/**
	 * Returns the action id.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Returns the button label.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Returns the operation.
	 *
	 * @since 2.0.2
	 *
	 * @return callable
	 */
	public function get_callback(): callable {
		return $this->callback;
	}

	/**
	 * Whether the action is destructive (the client must confirm it).
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function is_destructive(): bool {
		return $this->destructive;
	}

	/**
	 * Returns the confirmation text ('' = use the client's generic one).
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_confirm_message(): string {
		return $this->confirm_message;
	}

	/**
	 * The descriptor the client bootstrap carries — everything except the callback.
	 *
	 * @since 2.0.2
	 *
	 * @return array<string,mixed>
	 */
	public function to_client_array(): array {
		return [
			'id'          => $this->id,
			'label'       => $this->label,
			'destructive' => $this->destructive,
			'confirm'     => $this->confirm_message,
		];
	}
}
