<?php
/**
 * Decoded inbound marketplace notification.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * One push notification, decoded by the adapter and ready for the ledger.
 *
 * `dedup_key` is built by the adapter so that a re-delivery of the same notification gets the same key and two
 * different notifications never do. `event_id` is null until the ledger stores the row; the ledger sets it so that
 * `Event_Ledger::complete()`, `retry()` and `fail()` can name the row (an addition to the §7.2 sketch, which listed
 * no row id).
 *
 * @since 2.0.29
 */
final class Inbound_Event {
	/**
	 * Account id, null when the URL token matched no account.
	 *
	 * @var int|null
	 */
	private $account_id;

	/**
	 * Marketplace event type.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Deduplication key.
	 *
	 * @var string
	 */
	private $dedup_key;

	/**
	 * Decoded body.
	 *
	 * @var array<string,mixed>
	 */
	private $payload;

	/**
	 * Receipt time.
	 *
	 * @var \DateTimeImmutable
	 */
	private $received_at;

	/**
	 * Sender address.
	 *
	 * @var string
	 */
	private $source_ip;

	/**
	 * Whether the sender address belongs to the marketplace's published range.
	 *
	 * @var bool
	 */
	private $ip_trusted;

	/**
	 * Ledger row id, null before the row is stored.
	 *
	 * @var int|null
	 */
	private $event_id;

	/**
	 * Delivery attempts made so far, counting the claim that returned this row; 0 for an event not yet stored.
	 *
	 * @var int
	 */
	private $attempts = 0;

	/**
	 * Why the event is stored but never processed (invalid body, unknown account, unsupported type); '' for a normal one.
	 *
	 * @var string
	 */
	private $ignore_reason = '';

	/**
	 * Creates an event.
	 *
	 * @since 2.0.29
	 *
	 * @param int|null            $account_id  Account id or null.
	 * @param string              $type        Event type.
	 * @param string              $dedup_key   Deduplication key.
	 * @param array<string,mixed> $payload     Decoded body.
	 * @param \DateTimeImmutable  $received_at Receipt time.
	 * @param string              $source_ip   Sender address.
	 * @param bool                $ip_trusted  Whether the address is in the published range.
	 * @param int|null            $event_id    Ledger row id.
	 */
	public function __construct( ?int $account_id, string $type, string $dedup_key, array $payload, \DateTimeImmutable $received_at, string $source_ip, bool $ip_trusted, ?int $event_id = null ) {
		$this->account_id  = $account_id;
		$this->type        = $type;
		$this->dedup_key   = $dedup_key;
		$this->payload     = $payload;
		$this->received_at = $received_at;
		$this->source_ip   = $source_ip;
		$this->ip_trusted  = $ip_trusted;
		$this->event_id    = $event_id;
	}

	/**
	 * Returns a copy that carries the ledger row id.
	 *
	 * @since 2.0.29
	 *
	 * @param int $event_id Ledger row id.
	 * @return self
	 */
	public function with_event_id( int $event_id ): self {
		$copy           = clone $this;
		$copy->event_id = $event_id;

		return $copy;
	}

	/**
	 * Returns a copy that carries the number of delivery attempts (the ledger sets it when it hands a claimed row out).
	 *
	 * @since 2.0.29
	 *
	 * @param int $attempts Attempts so far.
	 * @return self
	 */
	public function with_attempts( int $attempts ): self {
		$copy           = clone $this;
		$copy->attempts = $attempts;

		return $copy;
	}

	/**
	 * Returns a copy marked «store, never process». The ledger saves such an event with the status `ignored` and the
	 * reason as its result, so a malformed body or an unknown account is visible to diagnostics without being worked.
	 *
	 * @since 2.0.29
	 *
	 * @param string $reason Short reason text.
	 * @return self
	 */
	public function with_ignore_reason( string $reason ): self {
		$copy                = clone $this;
		$copy->ignore_reason = $reason;

		return $copy;
	}

	/**
	 * Returns the number of delivery attempts, counting the current one.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function attempts(): int {
		return $this->attempts;
	}

	/**
	 * Returns why the event must not be processed, or '' for a normal event.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function ignore_reason(): string {
		return $this->ignore_reason;
	}

	/**
	 * Returns the account id, or null.
	 *
	 * @since 2.0.29
	 *
	 * @return int|null
	 */
	public function account_id(): ?int {
		return $this->account_id;
	}

	/**
	 * Returns the event type.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Returns the deduplication key.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function dedup_key(): string {
		return $this->dedup_key;
	}

	/**
	 * Returns the decoded body.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * Returns the receipt time.
	 *
	 * @since 2.0.29
	 *
	 * @return \DateTimeImmutable
	 */
	public function received_at(): \DateTimeImmutable {
		return $this->received_at;
	}

	/**
	 * Returns the sender address.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function source_ip(): string {
		return $this->source_ip;
	}

	/**
	 * Tells whether the sender address is in the published range.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function ip_trusted(): bool {
		return $this->ip_trusted;
	}

	/**
	 * Returns the ledger row id, or null before the row is stored.
	 *
	 * @since 2.0.29
	 *
	 * @return int|null
	 */
	public function event_id(): ?int {
		return $this->event_id;
	}
}
