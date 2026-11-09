<?php
/**
 * Marketplace adapter contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Capabilities;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Health;
use Woodev\Framework\Marketplace\Catalog\Card_Exporter;
use Woodev\Framework\Marketplace\Catalog\Catalog_Schema;
use Woodev\Framework\Marketplace\Diagnostics\Report_Section;
use Woodev\Framework\Marketplace\Governor\Bucket_Spec;
use Woodev\Framework\Marketplace\Ledger\Inbound_Decoder;
use Woodev\Framework\Marketplace\Orders\Order_Source;
use Woodev\Framework\Marketplace\Price\Price_Pusher;
use Woodev\Framework\Marketplace\Stock\Stock_Pusher;

defined( 'ABSPATH' ) || exit;

/**
 * The one object that wires a marketplace into the common layer. Admin screens and jobs depend on this interface and on
 * the interfaces it hands out, never on a concrete marketplace.
 *
 * @since 2.0.29
 */
interface Marketplace_Adapter {
	/**
	 * Returns the adapter id stored on accounts (`ozon`, `wildberries`).
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Tells whether the marketplace offers a feature.
	 *
	 * @since 2.0.29
	 *
	 * @param string $capability A `Capability::*` constant.
	 * @return bool
	 */
	public function supports( string $capability ): bool;

	/**
	 * Returns the credential checker.
	 *
	 * @since 2.0.29
	 *
	 * @return Credential_Health
	 */
	public function credential_health(): Credential_Health;

	/**
	 * Returns the stock pusher.
	 *
	 * @since 2.0.29
	 *
	 * @return Stock_Pusher
	 */
	public function stock_pusher(): Stock_Pusher;

	/**
	 * Returns the price pusher.
	 *
	 * @since 2.0.29
	 *
	 * @return Price_Pusher|null Null when prices cannot be pushed to this marketplace.
	 */
	public function price_pusher(): ?Price_Pusher;

	/**
	 * Returns the order source.
	 *
	 * @since 2.0.29
	 *
	 * @return Order_Source
	 */
	public function order_source(): Order_Source;

	/**
	 * Returns the catalogue schema for mappings and the validator.
	 *
	 * @since 2.0.29
	 *
	 * @return Catalog_Schema
	 */
	public function catalog_schema(): Catalog_Schema;

	/**
	 * Returns the card exporter.
	 *
	 * @since 2.0.29
	 *
	 * @return Card_Exporter
	 */
	public function card_exporter(): Card_Exporter;

	/**
	 * Returns the inbound notification decoder.
	 *
	 * @since 2.0.29
	 *
	 * @return Inbound_Decoder|null Null when the marketplace has no push (Wildberries).
	 */
	public function inbound_decoder(): ?Inbound_Decoder;

	/**
	 * Returns the rate-limit buckets of the marketplace.
	 *
	 * @since 2.0.29
	 *
	 * @return Bucket_Spec[]
	 */
	public function bucket_specs(): array;

	/**
	 * Returns the marketplace's own sections of the support report.
	 *
	 * @since 2.0.29
	 *
	 * @param Account $account Account the report is about.
	 * @return Report_Section[]
	 */
	public function support_sections( Account $account ): array;
}
