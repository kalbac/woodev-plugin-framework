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
use Woodev\Framework\Marketplace\Flows\Card_Lifecycle;
use Woodev\Framework\Marketplace\Flows\Export_Facts;
use Woodev\Framework\Marketplace\Flows\Export_Gateway;
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
	 * @since 2.1.0
	 *
	 * @return Error_Classifier
	 */
	public function error_classifier(): Error_Classifier;

	/**
	 * Returns the per-request limits of the stock and price calls: how many lines one request takes and how long it may run.
	 *
	 * @since 2.1.0
	 *
	 * @return Sync_Limits
	 */
	public function sync_limits(): Sync_Limits;

	/**
	 * Returns the rules of a stock send: what a refused line means and how a card is archived.
	 *
	 * @since 2.1.0
	 *
	 * @return Stock_Rules
	 */
	public function stock_rules(): Stock_Rules;

	/**
	 * Returns the reader of the prices the marketplace holds (the `from_marketplace` direction).
	 *
	 * @since 2.1.0
	 *
	 * @return Price_Reader|null Null when prices cannot be read back from this marketplace.
	 */
	public function price_reader(): ?Price_Reader;

	/**
	 * Returns the rules of the notification endpoint: the sender's address, the marketplace's allow-list and its error format.
	 *
	 * @since 2.1.0
	 *
	 * @return Push_Protocol|null Null when the marketplace has no push, like {@see self::inbound_decoder()}.
	 */
	public function push_protocol(): ?Push_Protocol;

	/**
	 * Returns the marketplace's local export facts: what its catalogue cache and media rules say about a product, the seller's
	 * article, which products become cards. It makes no remote call.
	 *
	 * @since 2.1.0
	 *
	 * @return Export_Facts
	 */
	public function export_facts(): Export_Facts;

	/**
	 * Returns the marketplace's card builder and import request.
	 *
	 * @since 2.1.0
	 *
	 * @return Export_Gateway
	 */
	public function export_gateway(): Export_Gateway;

	/**
	 * Returns the reader of the import result and of the state of the cards that were sent.
	 *
	 * @since 2.1.0
	 *
	 * @return Card_Lifecycle
	 */
	public function card_lifecycle(): Card_Lifecycle;

	/**
	 * Returns the marketplace's order meta contract: the meta that identifies its order and the meta it writes, the product of a
	 * line, what an order is created via, and which account owns an order (F9b-3).
	 *
	 * @since 2.1.0
	 *
	 * @return \Woodev\Framework\Marketplace\Flows\Order_Meta_Contract
	 */
	public function order_meta(): \Woodev\Framework\Marketplace\Flows\Order_Meta_Contract;

	/**
	 * Returns the marketplace's own half of the order flow: reading one remote order and writing it, and catching up on the orders
	 * changed since the last run (F9b-3).
	 *
	 * @since 2.1.0
	 *
	 * @return \Woodev\Framework\Marketplace\Jobs\Posting_Handler|null Null when the marketplace has no orders.
	 */
	public function posting_handler(): ?\Woodev\Framework\Marketplace\Jobs\Posting_Handler;

	/**
	 * Returns the marketplace's label calls (`Capability::GET_LABEL`, F9b-3).
	 *
	 * @since 2.1.0
	 *
	 * @return \Woodev\Framework\Marketplace\Flows\Label_Provider|null Null when the marketplace has no labels.
	 */
	public function label_provider(): ?\Woodev\Framework\Marketplace\Flows\Label_Provider;

	/**
	 * Returns the reader of the cabinet's existing cards, for linking them to shop products and for the tab that lists them.
	 *
	 * @since 2.1.0
	 *
	 * @return \Woodev\Framework\Marketplace\Flows\Link_Resolver
	 */
	public function link_resolver(): \Woodev\Framework\Marketplace\Flows\Link_Resolver;

	/**
	 * Returns the part of the catalogue refresh that talks to the marketplace: its category tree and the attributes of the
	 * categories that mappings use.
	 *
	 * @since 2.1.0
	 *
	 * @return \Woodev\Framework\Marketplace\Flows\Catalog_Refresher
	 */
	public function catalog_refresher(): \Woodev\Framework\Marketplace\Flows\Catalog_Refresher;

	/**
	 * Returns the push administration service for an account: registering the notification address, the event types, enabling
	 * delivery and the connection check, all remote calls.
	 *
	 * @since 2.1.2
	 *
	 * @param int $account_id Account id.
	 * @return \Woodev\Framework\Marketplace\Settings\Push_Admin|null Null when the marketplace has no push notifications, or the account does not exist.
	 */
	public function push_admin( int $account_id ): ?\Woodev\Framework\Marketplace\Settings\Push_Admin;

	/**
	 * Returns the UI descriptor for settings sections, key-expiry notices and diagnostics.
	 *
	 * @since 2.1.2
	 *
	 * @return \Woodev\Framework\Marketplace\Settings\Settings_Descriptor
	 */
	public function settings_descriptor(): \Woodev\Framework\Marketplace\Settings\Settings_Descriptor;
}
