<?php
/**
 * What the marketplace said about one card.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * The state of one card on the marketplace, already translated by the adapter: the link state it maps to, whether the card
 * exists (and may take stock and price), whether the marketplace declined it, its status in the marketplace's words and the
 * identifiers it carries now.
 *
 * @since 2.1.0
 */
final class Card_Outcome {
	/**
	 * Link state (`Link_Record::STATE_*`).
	 *
	 * @var string
	 */
	private $state;

	/**
	 * Whether the card exists.
	 *
	 * @var bool
	 */
	private $created;

	/**
	 * Whether the marketplace declined the card.
	 *
	 * @var bool
	 */
	private $failed;

	/**
	 * Status in the marketplace's words.
	 *
	 * @var string
	 */
	private $status_text;

	/**
	 * Identifiers the card carries (name => id).
	 *
	 * @var array<string,int|string>
	 */
	private $remote_ids;

	/**
	 * Creates the outcome.
	 *
	 * @since 2.1.0
	 *
	 * @param string                   $state       Link state the card maps to.
	 * @param bool                     $created     Whether the card exists.
	 * @param bool                     $failed      Whether the marketplace declined it.
	 * @param string                   $status_text Status in the marketplace's words.
	 * @param array<string,int|string> $remote_ids  Identifiers the card carries.
	 */
	public function __construct( string $state, bool $created, bool $failed, string $status_text, array $remote_ids = [] ) {
		$this->state       = $state;
		$this->created     = $created;
		$this->failed      = $failed;
		$this->status_text = $status_text;
		$this->remote_ids  = $remote_ids;
	}

	/**
	 * Returns the link state the card maps to.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * Tells whether the card exists.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public function is_created(): bool {
		return $this->created;
	}

	/**
	 * Tells whether the marketplace declined the card.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public function is_failed(): bool {
		return $this->failed;
	}

	/**
	 * Returns the status in the marketplace's words.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function status_text(): string {
		return $this->status_text;
	}

	/**
	 * Returns the identifiers the card carries.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,int|string>
	 */
	public function remote_ids(): array {
		return $this->remote_ids;
	}
}
