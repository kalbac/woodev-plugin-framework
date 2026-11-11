<?php
/**
 * What the marketplace says about a notification URL.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * The marketplace's registry of notification URLs, for one account. The framework stores what it answers on the account row,
 * so every screen reads the registration state without a remote call.
 *
 * @since 2.0.30
 */
interface Push_Registrar {
	/**
	 * Asks the marketplace about one URL.
	 *
	 * @since 2.0.30
	 *
	 * @param string $url The account's notification URL.
	 * @return array<string,mixed>|null What the marketplace holds for the URL (its id, the subscribed types, its health — the
	 *                                  marketplace's own keys), or null when the URL is not registered.
	 * @throws \Throwable When the marketplace could not be asked.
	 */
	public function registration_of( string $url ): ?array;
}
