<?php
/**
 * A marketplace whose cards can be sent again right after «Сбросить состояние».
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * Optional: a {@see Card_Lifecycle} implements it when the adapter itself forgets, at its next
 * {@see Export_Gateway::submit()}, whatever it still waited for of a card whose state was reset.
 *
 * «Сбросить состояние» then also closes the card's open items in its export tasks (as skipped) in the same transaction, so the
 * merchant can export the card again at once. The older task no longer asks for it, and finishes by itself.
 *
 * Without it the items stay open until the task closes them, at the latest at its deadline, and «Экспортировать» of the products
 * tables refuses the card until then.
 *
 * @since 2.1.26
 */
interface Releases_Reset_Imports {
}
