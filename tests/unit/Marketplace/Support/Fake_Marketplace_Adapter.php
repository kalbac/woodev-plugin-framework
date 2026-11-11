<?php
/**
 * Wildberries-shaped adapter used to test the marketplace layer.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Credential_Health;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Catalog\Article_Policy;
use Woodev\Framework\Marketplace\Catalog\Card_Exporter;
use Woodev\Framework\Marketplace\Catalog\Catalog_Schema;
use Woodev\Framework\Marketplace\Catalog\Validation_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Category_Exists_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Category_Mapped_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Currency_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Dictionary_Values_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Dimensions_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Images_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Offer_Id_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Required_Fields_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Selling_Price_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Variation_Group_Rule;
use Woodev\Framework\Marketplace\Diagnostics\Report_Section;
use Woodev\Framework\Marketplace\Diagnostics\Support_Report;
use Woodev\Framework\Marketplace\Governor\Bucket_Spec;
use Woodev\Framework\Marketplace\Governor\Sync_Cadence;
use Woodev\Framework\Marketplace\Intake\Push_Protocol;
use Woodev\Framework\Marketplace\Jobs\Error_Classifier;
use Woodev\Framework\Marketplace\Ledger\Inbound_Decoder;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Line;
use Woodev\Framework\Marketplace\Orders\Order_Source;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Pusher;
use Woodev\Framework\Marketplace\Price\Price_Source;
use Woodev\Framework\Marketplace\Stock\Stock_Policy;
use Woodev\Framework\Marketplace\Stock\Stock_Pusher;
use Woodev\Framework\Marketplace\Sync\Price_Reader;
use Woodev\Framework\Marketplace\Sync\Stock_Rules;
use Woodev\Framework\Marketplace\Sync\Sync_Limits;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Article_Policy;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Card_Exporter;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Catalog_Schema;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Clock;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Credential_Health;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Error_Classifier;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Field_Price_Source;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Source;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Price_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Stock_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Stock_Pusher;

/**
 * A second adapter that is deliberately NOT Ozon. It mimics the Wildberries shapes found in
 * `docs/recon/wildberries-api-2026.md`, so every common interface is designed under the pressure of two marketplaces:
 *
 * - one JWT token with a 180-day expiry, no Client-Id (`Fake_Credential_Health`);
 * - stock keyed by `chrtId`, absolute amounts, 1000 per request (`Fake_Stock_Pusher`);
 * - price upload is an asynchronous task with a quarantine (`Fake_Price_Pusher`);
 * - card upload has no task id and a 30-minute sync window (`Fake_Card_Exporter`);
 * - orders are poll-only, one item per assembly order, grouped by `orderUid`, money in integer kopecks
 *   (`Fake_Order_Source`, `self::wb_order()`);
 * - no push, so `inbound_decoder()` is null and `PUSH_NOTIFICATIONS` is unsupported;
 * - a 4xx answer costs ten requests (`bucket_specs()`).
 */
final class Fake_Marketplace_Adapter implements Marketplace_Adapter {
	/**
	 * Clock shared by the parts.
	 *
	 * @var Fake_Marketplace\Fake_Clock
	 */
	private $clock;

	/**
	 * Credential checker.
	 *
	 * @var Fake_Credential_Health
	 */
	private $health;

	/**
	 * Stock pusher.
	 *
	 * @var Fake_Stock_Pusher
	 */
	private $stock;

	/**
	 * Price pusher.
	 *
	 * @var Fake_Price_Pusher
	 */
	private $price;

	/**
	 * Order source.
	 *
	 * @var Fake_Order_Source
	 */
	private $orders;

	/**
	 * Catalogue schema.
	 *
	 * @var Fake_Catalog_Schema
	 */
	private $schema;

	/**
	 * Card exporter.
	 *
	 * @var Fake_Card_Exporter
	 */
	private $exporter;

	/**
	 * The marketplace's policy answers: price source, cadences, article policy, rule list, stock policy.
	 *
	 * @var array<string,mixed>
	 */
	private $policies;

	/**
	 * Builds the adapter and its parts around one clock.
	 *
	 * Without `$policies` the answers are those of a Wildberries-style marketplace, deliberately unlike Ozon's: no price source beyond
	 * the neutral shapes, prices at most once in ten minutes with four attempts, stock with three, 20-character articles, four
	 * rules, and the reservation added to the stock it pushes.
	 *
	 * @param Fake_Clock|null     $clock    Clock; a new one when null.
	 * @param array<string,mixed> $policies Answers that replace the defaults: `price_source`, `price_cadence`, `stock_cadence`,
	 *                                      `article_policy`, `export_rules`, `stock_policy`, and the answers of the sync flows:
	 *                                      `error_classifier`, `sync_limits`, `stock_rules`, `price_reader`, `push_protocol`.
	 */
	public function __construct( ?Fake_Clock $clock = null, array $policies = [] ) {
		$this->policies = array_merge(
			[
				'price_source'   => null,
				'price_cadence'  => new Sync_Cadence( 600, 4 ),
				'stock_cadence'  => new Sync_Cadence( 0, 3 ),
				'article_policy' => new Fake_Article_Policy( 20 ),
				'export_rules'   => [ new Category_Mapped_Rule(), new Required_Fields_Rule( true ), new Offer_Id_Rule(), new Selling_Price_Rule() ],
				'stock_policy'   => new Stock_Policy( Stock_Policy::FORMULA_PLUS_RESERVED ),
				// Wildberries-style again: a thousand lines per request, no price read-back, no push.
				'error_classifier' => new Fake_Error_Classifier(),
				'sync_limits'      => new Sync_Limits( Fake_Stock_Pusher::MAX_PER_REQUEST, 1000, 30.0 ),
				'stock_rules'      => new Scripted_Stock_Pusher(),
				'price_reader'     => null,
				'push_protocol'    => null,
			],
			$policies
		);

		$this->clock    = $clock ?? new Fake_Clock();
		$this->health   = new Fake_Credential_Health( $this->clock );
		$this->stock    = new Fake_Stock_Pusher();
		$this->price    = new Fake_Price_Pusher( $this->clock );
		$this->orders   = new Fake_Order_Source();
		$this->schema   = new Fake_Catalog_Schema();
		$this->exporter = new Fake_Card_Exporter( $this->clock );
	}

	/**
	 * Builds the adapter with the values the Ozon adapter passes in, for the suites that moved with the layer and keep their donor
	 * expectations: the `field:KEY` price source, 360 s / 5 attempts for prices, 0 s / 5 for stock, 50-character articles, all ten
	 * rules in the merchant order, plain WC stock.
	 *
	 * @param Fake_Clock|null $clock Clock; a new one when null.
	 * @return self
	 */
	public static function with_donor_values( ?Fake_Clock $clock = null ): self {
		return new self(
			$clock,
			[
				'price_source'   => new Fake_Field_Price_Source(),
				'price_cadence'  => new Sync_Cadence( 360, 5 ),
				'stock_cadence'  => new Sync_Cadence( 0, 5 ),
				'article_policy' => new Fake_Article_Policy( 50 ),
				'export_rules'   => [
					new Category_Mapped_Rule(),
					new Category_Exists_Rule(),
					new Required_Fields_Rule( true ),
					new Dictionary_Values_Rule(),
					new Dimensions_Rule(),
					new Images_Rule(),
					new Offer_Id_Rule(),
					new Selling_Price_Rule(),
					new Currency_Rule(),
					new Variation_Group_Rule(),
				],
				'stock_policy'   => new Stock_Policy( Stock_Policy::FORMULA_WC_ONLY ),
			]
		);
	}

	/**
	 * Builds one Wildberries assembly order as the layer's DTO: one item unit, kopeck money, grouped by `orderUid`.
	 *
	 * @param int    $account_id     Account id.
	 * @param int    $order_id       Assembly order `id`.
	 * @param string $order_uid      Cart id shared by the cart's orders.
	 * @param int    $price_kopecks  Price of the one unit in kopecks.
	 * @param string $supplier_status `new`, `confirm`, `complete` or `cancel`.
	 * @param string $wb_status      `waiting`, `sorted`, `sold`, `canceled`, ...
	 * @param string $updated_at     ISO 8601 UTC time of the last change.
	 * @param string $article        Seller article.
	 * @param int    $chrt_id        Size id.
	 * @return Order_DTO
	 */
	public static function wb_order( int $account_id, int $order_id, string $order_uid, int $price_kopecks, string $supplier_status, string $wb_status, string $updated_at, string $article = 'SKU-1', int $chrt_id = 1000 ): Order_DTO {
		$unit_price = Money::of( $price_kopecks, 'RUB' );
		$line       = new Order_Line( $article, array( 'chrtID' => $chrt_id ), 1, $unit_price, 'Платье' );

		return new Order_DTO(
			$account_id,
			(string) $order_id,
			$order_uid,
			$supplier_status . '/' . $wb_status,
			self::canonical_status( $supplier_status, $wb_status ),
			array( $line ),
			array( 'total' => $unit_price ),
			null,
			array(
				'created_at' => '2026-11-08T09:00:00Z',
				'updated_at' => $updated_at,
			),
			array(),
			array(
				'id'             => $order_id,
				'orderUid'       => $order_uid,
				'supplierStatus' => $supplier_status,
				'wbStatus'       => $wb_status,
			)
		);
	}

	/**
	 * Maps `supplierStatus` x `wbStatus` to the canonical vocabulary.
	 *
	 * @param string $supplier_status `new`, `confirm`, `complete`, `cancel`, `cancel_carrier`.
	 * @param string $wb_status       Wildberries' own status.
	 * @return string
	 */
	public static function canonical_status( string $supplier_status, string $wb_status ): string {
		if ( in_array( $supplier_status, array( 'cancel', 'cancel_carrier' ), true ) || in_array( $wb_status, array( 'canceled', 'canceled_by_client', 'declined_by_client' ), true ) ) {
			return Status_Map::CANCELLED;
		}

		if ( 'sold' === $wb_status ) {
			return Status_Map::DELIVERED;
		}

		$by_supplier = array(
			'new'      => Status_Map::NEW,
			'confirm'  => Status_Map::AWAITING_SHIPMENT,
			'complete' => Status_Map::DELIVERING,
		);

		return $by_supplier[ $supplier_status ] ?? Status_Map::NEW;
	}

	/**
	 * Returns the clock the parts share.
	 *
	 * @return Fake_Clock
	 */
	public function clock(): Fake_Clock {
		return $this->clock;
	}

	/**
	 * Returns the adapter id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'fake_wildberries';
	}

	/**
	 * Tells whether the fake marketplace offers a feature.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	public function supports( string $capability ): bool {
		return in_array( $capability, array( Capability::CANCEL_ORDER, Capability::GET_LABEL, Capability::CONFIRM_SHIPMENT ), true );
	}

	/**
	 * Declares the credentials: a cabinet reference and the one JWT token.
	 *
	 * @return Credential_Fields
	 */
	public function credential_fields(): Credential_Fields {
		return new Credential_Fields( 'cabinet', array( 'token' ) );
	}

	/**
	 * Returns the credential checker.
	 *
	 * @return Credential_Health
	 */
	public function credential_health(): Credential_Health {
		return $this->health;
	}

	/**
	 * Returns the stock pusher.
	 *
	 * @return Stock_Pusher
	 */
	public function stock_pusher(): Stock_Pusher {
		return $this->stock;
	}

	/**
	 * Returns the price pusher.
	 *
	 * @return Price_Pusher|null
	 */
	public function price_pusher(): ?Price_Pusher {
		return $this->price;
	}

	/**
	 * Returns the order source.
	 *
	 * @return Order_Source
	 */
	public function order_source(): Order_Source {
		return $this->orders;
	}

	/**
	 * Returns the catalogue schema.
	 *
	 * @return Catalog_Schema
	 */
	public function catalog_schema(): Catalog_Schema {
		return $this->schema;
	}

	/**
	 * Returns the card exporter.
	 *
	 * @return Card_Exporter
	 */
	public function card_exporter(): Card_Exporter {
		return $this->exporter;
	}

	/**
	 * Returns no decoder: this marketplace has no push.
	 *
	 * @return Inbound_Decoder|null
	 */
	public function inbound_decoder(): ?Inbound_Decoder {
		return null;
	}

	/**
	 * Returns Wildberries-style buckets.
	 *
	 * @return Bucket_Spec[]
	 */
	public function bucket_specs(): array {
		return array(
			new Bucket_Spec( 'marketplace', 300, 60, 20, Bucket_Spec::COST_REQUEST, 10 ),
			new Bucket_Spec( 'prices', 10, 6, 5 ),
			new Bucket_Spec( 'content_write', 10, 60, 5 ),
		);
	}

	/**
	 * Returns the price source, if any.
	 *
	 * @return Price_Source|null
	 */
	public function price_source(): ?Price_Source {
		return $this->policies['price_source'];
	}

	/**
	 * Returns the limits on price sends.
	 *
	 * @return Sync_Cadence
	 */
	public function price_cadence(): Sync_Cadence {
		return $this->policies['price_cadence'];
	}

	/**
	 * Returns the limits on stock sends.
	 *
	 * @return Sync_Cadence
	 */
	public function stock_cadence(): Sync_Cadence {
		return $this->policies['stock_cadence'];
	}

	/**
	 * Returns the article policy.
	 *
	 * @return Article_Policy
	 */
	public function article_policy(): Article_Policy {
		return $this->policies['article_policy'];
	}

	/**
	 * Returns the full-scope rule list.
	 *
	 * @return Validation_Rule[]
	 */
	public function export_rules(): array {
		return $this->policies['export_rules'];
	}

	/**
	 * Returns the stock formula in force.
	 *
	 * @return Stock_Policy
	 */
	public function stock_policy(): Stock_Policy {
		return $this->policies['stock_policy'];
	}

	/**
	 * Returns the adapter's support sections; the token is masked by the report.
	 *
	 * @param Account $account Account.
	 * @return Report_Section[]
	 */
	public function support_sections( Account $account ): array {
		return array(
			new Report_Section(
				'Fake Wildberries',
				array(
					'Токен'   => (string) ( $account->credentials()['token'] ?? '' ),
					'Кабинет' => Support_Report::mask_identifier( (string) ( $account->credentials()['supplier_id'] ?? '' ) ),
				)
			),
		);
	}

	/**
	 * Returns the classifier of the marketplace's errors.
	 *
	 * @return Error_Classifier
	 */
	public function error_classifier(): Error_Classifier {
		return $this->policies['error_classifier'];
	}

	/**
	 * Returns the per-request limits.
	 *
	 * @return Sync_Limits
	 */
	public function sync_limits(): Sync_Limits {
		return $this->policies['sync_limits'];
	}

	/**
	 * Returns the rules of a stock send.
	 *
	 * @return Stock_Rules
	 */
	public function stock_rules(): Stock_Rules {
		return $this->policies['stock_rules'];
	}

	/**
	 * Returns the price reader: none, prices are not read back.
	 *
	 * @return Price_Reader|null
	 */
	public function price_reader(): ?Price_Reader {
		return $this->policies['price_reader'];
	}

	/**
	 * Returns the notification endpoint rules: none, the marketplace has no push.
	 *
	 * @return Push_Protocol|null
	 */
	public function push_protocol(): ?Push_Protocol {
		return $this->policies['push_protocol'];
	}
}
