<?php
/**
 * Merchant-facing names of every notification type an adapter handles.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * Optional half of {@see Push_Protocol}: the adapter's Russian names for every kind of notification its decoder handles, not only
 * the ones the merchant can switch off ({@see Push_Protocol::webhook_types()}). A marketplace always accepts some notifications
 * (a changed delivery date, a changed category tree) and offers no switch for them, yet the latest one received is still shown to
 * the merchant by name. A protocol that does not implement this keeps today's behaviour: only the switchable types have names.
 *
 * @since 2.1.13
 */
interface Names_Event_Types {
	/**
	 * Returns the names of the notification types the adapter handles.
	 *
	 * @since 2.1.13
	 *
	 * @return array<string,string> Event type the decoder gives (`TYPE_DELIVERY_DATE_CHANGED`) => its name in Russian
	 *                              («изменение даты доставки»); matched without regard to case.
	 */
	public function event_names(): array;
}
