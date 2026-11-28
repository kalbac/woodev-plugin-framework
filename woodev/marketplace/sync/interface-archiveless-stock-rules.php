<?php
/**
 * The stock rules of a marketplace that has no archive.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Optional: the {@see Stock_Rules} of a marketplace whose cards cannot be archived implement it, and no screen offers
 * «Архивировать»: not the product tab, not the variations, not the bulk actions of the products table. It adds no method.
 *
 * {@see Stock_Rules::archive()} is still called after the zero stock of a removed product, and answers true.
 *
 * @since 2.1.28
 */
interface Archiveless_Stock_Rules extends Stock_Rules {
}
