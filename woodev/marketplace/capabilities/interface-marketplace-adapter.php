<?php
/**
 * Marketplace adapter contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Capabilities;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Credential_Health;
use Woodev\Framework\Marketplace\Catalog\Article_Policy;
use Woodev\Framework\Marketplace\Catalog\Card_Exporter;
use Woodev\Framework\Marketplace\Catalog\Catalog_Schema;
use Woodev\Framework\Marketplace\Catalog\Validation_Rule;
use Woodev\Framework\Marketplace\Diagnostics\Report_Section;
use Woodev\Framework\Marketplace\Governor\Bucket_Spec;
use Woodev\Framework\Marketplace\Governor\Sync_Cadence;
use Woodev\Framework\Marketplace\Intake\Push_Protocol;
use Woodev\Framework\Marketplace\Jobs\Error_Classifier;
use Woodev\Framework\Marketplace\Ledger\Inbound_Decoder;
use Woodev\Framework\Marketplace\Orders\Order_Source;
use Woodev\Framework\Marketplace\Price\Price_Pusher;
use Woodev\Framework\Marketplace\Price\Price_Source;
use Woodev\Framework\Marketplace\Stock\Stock_Policy;
use Woodev\Framework\Marketplace\Stock\Stock_Pusher;
use Woodev\Framework\Marketplace\Sync\Price_Reader;
use Woodev\Framework\Marketplace\Sync\Stock_Rules;
use Woodev\Framework\Marketplace\Sync\Sync_Limits;

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
	 * Declares which credentials an account of this marketplace holds: one identifier and the secret fields. The account store
	 * encrypts exactly the secret fields and keeps the identifier readable and unique; the settings section renders the same
	 * declaration.
	 *
	 * @since 2.0.29
	 *
	 * @return Credential_Fields
	 */
	public function credential_fields(): Credential_Fields;

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
	 * Returns the price source for currency-rule shapes of the marketplace's own (for example `field:KEY`).
	 *
	 * @since 2.0.29
	 *
	 * @return Price_Source|null Null when the marketplace has no shape beyond the neutral ones.
	 */
	public function price_source(): ?Price_Source;

	/**
	 * Returns the limits on price sends: the shortest time between two accepted sends of one product and the attempt cap.
	 *
	 * @since 2.0.29
	 *
	 * @return Sync_Cadence
	 */
	public function price_cadence(): Sync_Cadence;

	/**
	 * Returns the limits on stock sends (the stock side uses the attempt cap).
	 *
	 * @since 2.0.29
	 *
	 * @return Sync_Cadence
	 */
	public function stock_cadence(): Sync_Cadence;

	/**
	 * Returns the rules for the seller's article: length, normalisation and the fallback for a product without a SKU.
	 *
	 * @since 2.0.29
	 *
	 * @return Article_Policy
	 */
	public function article_policy(): Article_Policy;

	/**
	 * Returns the rules of the full validation scope, in the order they run.
	 *
	 * @since 2.0.29
	 *
	 * @return Validation_Rule[]
	 */
	public function export_rules(): array;

	/**
	 * Returns the stock formula in force.
	 *
	 * @since 2.0.29
	 *
	 * @return Stock_Policy
	 */
	public function stock_policy(): Stock_Policy;

	/**
	 * Returns the marketplace's own sections of the support report.
	 *
	 * @since 2.0.29
	 *
	 * @param Account $account Account the report is about.
	 * @return Report_Section[]
	 */
	public function support_sections( Account $account ): array;

	/**
	 * Returns the classifier of the marketplace's errors: what a failed call means for the job that made it (pause the account,
	 * wait, retry, give the items up).
	 *
	 * @since 2.0.30
	 *
	 * @return Error_Classifier
	 */
	public function error_classifier(): Error_Classifier;

	/**
	 * Returns the per-request limits of the stock and price calls: how many lines one request takes and how long it may run.
	 *
	 * @since 2.0.30
	 *
	 * @return Sync_Limits
	 */
	public function sync_limits(): Sync_Limits;

	/**
	 * Returns the rules of a stock send: what a refused line means and how a card is archived.
	 *
	 * @since 2.0.30
	 *
	 * @return Stock_Rules
	 */
	public function stock_rules(): Stock_Rules;

	/**
	 * Returns the reader of the prices the marketplace holds (the `from_marketplace` direction).
	 *
	 * @since 2.0.30
	 *
	 * @return Price_Reader|null Null when prices cannot be read back from this marketplace.
	 */
	public function price_reader(): ?Price_Reader;

	/**
	 * Returns the rules of the notification endpoint: the sender's address, the marketplace's allow-list and its error format.
	 *
	 * @since 2.0.30
	 *
	 * @return Push_Protocol|null Null when the marketplace has no push, like {@see self::inbound_decoder()}.
	 */
	public function push_protocol(): ?Push_Protocol;
}
