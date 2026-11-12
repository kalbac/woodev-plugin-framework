<?php
/**
 * Notification endpoint rules of the fake marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Intake\Push_Protocol;
use Woodev\Framework\Marketplace\Intake\Webhook_Type;

/**
 * The marketplace's network is `203.0.113.0/24`; an error is `{"fault": reason, "text": …}` — a format no real marketplace uses,
 * so a test proves the intake prints what the protocol gave it; it pushes the three kinds of notification the scripted decoder reads.
 */
final class Fake_Push_Protocol implements Push_Protocol {
	public const TRUSTED_IP = '203.0.113.7';
	public const FOREIGN_IP = '198.51.100.9';

	/**
	 * The marketplace's published addresses.
	 *
	 * @var string
	 */
	private $allowlist;

	/**
	 * The kinds of notification, or null for the three the scripted decoder reads.
	 *
	 * @var Webhook_Type[]|null
	 */
	private $types;

	/**
	 * Creates the protocol.
	 *
	 * @param string              $allowlist The marketplace's published addresses.
	 * @param Webhook_Type[]|null $types     The kinds of notification, or null for the default three.
	 */
	public function __construct( string $allowlist = '203.0.113.0/24', ?array $types = null ) {
		$this->allowlist = $allowlist;
		$this->types     = $types;
	}

	/**
	 * Returns the published addresses.
	 *
	 * @return string
	 */
	public function default_ip_allowlist(): string {
		return $this->allowlist;
	}

	/**
	 * Returns the kinds of notification.
	 *
	 * @return Webhook_Type[]
	 */
	public function webhook_types(): array {
		return $this->types ?? [
			new Webhook_Type( 'order_created', 'Новый заказ', 'Сообщает о новом заказе.', 'Кабинет → Уведомления' ),
			new Webhook_Type( 'order_changed', 'Изменение заказа', 'Сообщает, что заказ изменился.', 'Кабинет → Уведомления' ),
			new Webhook_Type( 'stock_changed', 'Остатки', 'Сообщает об изменении остатков.', 'Кабинет → Уведомления' ),
		];
	}

	/**
	 * Returns the error body.
	 *
	 * @param string $reason  Reason.
	 * @param string $message Text.
	 * @return array<string,mixed>
	 */
	public function error_body( string $reason, string $message ): array {
		return [
			'fault' => $reason,
			'text'  => $message,
		];
	}
}
