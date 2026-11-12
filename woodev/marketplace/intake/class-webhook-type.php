<?php
/**
 * One kind of notification a marketplace can push.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * What the adapter declares for each kind of notification its marketplace pushes ({@see Push_Protocol::webhook_types()}): the
 * framework turns it into an on/off switch with its explanation on the notifications settings screen, and the intake drops a
 * notification whose switch is off.
 *
 * The id is the type the adapter's {@see \Woodev\Framework\Marketplace\Ledger\Inbound_Decoder} gives the event
 * ({@see \Woodev\Framework\Marketplace\Ledger\Inbound_Event::type()}), so one string ties the switch to the notifications it governs.
 *
 * @since 2.1.2
 */
final class Webhook_Type {
	/**
	 * Event type the decoder gives the notification.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Merchant-facing name of the switch.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Plain-language sentence on what the notification is for.
	 *
	 * @var string
	 */
	private $purpose;

	/**
	 * Where in the marketplace cabinet this notification is switched on and the shop's address entered.
	 *
	 * @var string
	 */
	private $cabinet_location;

	/**
	 * Whether the switch is on until the merchant changes it.
	 *
	 * @var bool
	 */
	private $default_enabled;

	/**
	 * Whether this is the stock notification, which an account that does not sync stock does not subscribe to.
	 *
	 * @var bool
	 */
	private $stock;

	/**
	 * Creates a type.
	 *
	 * @since 2.1.2
	 *
	 * @param string $id               Event type the decoder gives the notification.
	 * @param string $label            Merchant-facing name of the switch, e.g. «Новый заказ».
	 * @param string $purpose          What the notification is for, in a sentence a WordPress novice understands.
	 * @param string $cabinet_location Where in the marketplace cabinet the shop's address goes, e.g. «Настройки → Интеграции → Уведомления».
	 * @param bool   $default_enabled  Whether the switch is on until the merchant changes it.
	 * @param bool   $stock            Whether this is the stock notification: an account in the stock mode `off` drops it.
	 */
	public function __construct( string $id, string $label, string $purpose, string $cabinet_location, bool $default_enabled = true, bool $stock = false ) {
		$this->id               = $id;
		$this->label            = $label;
		$this->purpose          = $purpose;
		$this->cabinet_location = $cabinet_location;
		$this->default_enabled  = $default_enabled;
		$this->stock            = $stock;
	}

	/**
	 * Returns the event type this switch governs.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Returns the merchant-facing name of the switch.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Returns what the notification is for.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function purpose(): string {
		return $this->purpose;
	}

	/**
	 * Returns where in the marketplace cabinet the shop's address goes.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function cabinet_location(): string {
		return $this->cabinet_location;
	}

	/**
	 * Tells whether the switch is on until the merchant changes it.
	 *
	 * @since 2.1.2
	 *
	 * @return bool
	 */
	public function default_enabled(): bool {
		return $this->default_enabled;
	}

	/**
	 * Tells whether this is the stock notification.
	 *
	 * @since 2.1.2
	 *
	 * @return bool
	 */
	public function is_stock(): bool {
		return $this->stock;
	}
}
