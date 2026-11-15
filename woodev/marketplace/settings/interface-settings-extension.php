<?php
/**
 * An adapter's own fields on «Общие» and on the marketplace tab of a product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * An optional part of a marketplace adapter, next to {@see \Woodev\Framework\Marketplace\Flows\Card_Importer}: the adapter implements it
 * when its plugin has a setting the framework does not know. The framework checks `instanceof`, so an adapter that does not implement
 * it keeps booting, and its screens print exactly what they printed before.
 *
 * Two places, one description of a field ({@see Extension_Field}):
 *
 * - {@see self::default_groups()} become cards on «Общие». The values are stored in the same defaults option as the price rule
 *   (`Effective_Settings::option_name()`), checked by the settings machinery (a select refuses a value it does not list) and read
 *   at runtime through {@see Effective_Settings::extra()}.
 * - {@see self::product_groups()} are printed once on the marketplace tab of a product edit screen, after the account blocks.
 *   The values are product meta (the setting belongs to the product, not to one of its marketplace accounts) and are read through
 *   {@see Effective_Settings::product_extra()}. The same fields are printed in the panel of every variation: its empty value
 *   follows the parent product, then «Общие». Only selects are drawn there: a switch has no «as in the shop settings» state, so
 *   give the select an option with the empty value for it (the empty value stores nothing, and the product follows «Общие»).
 *
 * A field id is `[a-z0-9_]+` and is the key the value is read by; the framework puts its own prefix in front of it everywhere a name
 * is shared with other settings, so two adapters (or an adapter and the framework) never collide.
 *
 * @since 2.1.7
 */
interface Settings_Extension {
	/**
	 * Groups of fields for «Общие».
	 *
	 * @since 2.1.7
	 *
	 * @return Extension_Group[] Empty for none.
	 */
	public function default_groups(): array;

	/**
	 * Groups of fields for the marketplace tab of a product.
	 *
	 * @since 2.1.7
	 *
	 * @return Extension_Group[] Empty for none.
	 */
	public function product_groups(): array;
}
