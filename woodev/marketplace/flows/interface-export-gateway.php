<?php
/**
 * The marketplace's side of a card export request.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Validation_Context;
use Woodev\Framework\Marketplace\Data\Link_Record;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a card in the marketplace's shape and sends a batch of them. The `export_batch` job owns everything around the call
 * (the gate, the claim, the task row, the retry ladder); this contract owns the card and the request.
 *
 * @since 2.1.0
 */
interface Export_Gateway {
	/**
	 * Builds one card in the shape the marketplace's import takes.
	 *
	 * @since 2.1.0
	 *
	 * @param Link_Record                                  $link      Its offer row.
	 * @param \WC_Product                                  $product   Product or variation.
	 * @param Mapping                                      $mapping   Effective mapping.
	 * @param array<string,array<int,array<string,mixed>>> $overrides Override values of the link.
	 * @param Profile                                      $profile   Profile.
	 * @param Validation_Context                           $context   The context the card was validated with.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When the card cannot be built.
	 */
	public function build_item( Link_Record $link, \WC_Product $product, Mapping $mapping, array $overrides, Profile $profile, Validation_Context $context ): array;

	/**
	 * Sends a batch of built cards in one request.
	 *
	 * @since 2.1.0
	 *
	 * @param Account                        $account Account.
	 * @param array<int,array<string,mixed>> $items   Link id => card, as {@see self::build_item()} returned them.
	 * @param string                         $context Name of the calling job, for the governor and the error ring.
	 * @return string The marketplace's number of the import task; '' when it accepted the request and gave none.
	 * @throws \Throwable When the request fails; the adapter's error classifier decides what that means.
	 */
	public function submit( Account $account, array $items, string $context ): string;
}
