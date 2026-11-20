<?php
/**
 * The address where a merchant creates the credentials of an account.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Optional capability of a {@see Settings_Descriptor}: the page of the marketplace's seller cabinet where the identifier and the
 * key of an account are issued. A descriptor that implements it gets that address as a link in the description of the
 * «Подключение» tab; one that does not gets the same description without the link, so a marketplace is never forced to supply one.
 *
 * @since 2.1.13
 */
interface Credentials_Cabinet_Link {
	/**
	 * The absolute https address of the cabinet page where the credentials are issued, or '' when there is none.
	 *
	 * @since 2.1.13
	 *
	 * @return string
	 */
	public function credentials_url(): string;
}
