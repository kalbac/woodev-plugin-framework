<?php
/**
 * Wildberries-shaped adapter used to test the marketplace layer.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Health;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Catalog\Card_Exporter;
use Woodev\Framework\Marketplace\Catalog\Catalog_Schema;
use Woodev\Framework\Marketplace\Diagnostics\Report_Section;
use Woodev\Framework\Marketplace\Diagnostics\Support_Report;
use Woodev\Framework\Marketplace\Governor\Bucket_Spec;
use Woodev\Framework\Marketplace\Ledger\Inbound_Decoder;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Line;
use Woodev\Framework\Marketplace\Orders\Order_Source;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Pusher;
use Woodev\Framework\Marketplace\Stock\Stock_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Card_Exporter;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Catalog_Schema;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Clock;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Credential_Health;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Source;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Price_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Stock_Pusher;

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
	 * Builds the adapter and its parts around one clock.
	 *
	 * @param Fake_Clock|null $clock Clock; a new one when null.
	 */
	public function __construct( ?Fake_Clock $clock = null ) {
		$this->clock    = $clock ?? new Fake_Clock();
		$this->health   = new Fake_Credential_Health( $this->clock );
		$this->stock    = new Fake_Stock_Pusher();
		$this->price    = new Fake_Price_Pusher( $this->clock );
		$this->orders   = new Fake_Order_Source();
		$this->schema   = new Fake_Catalog_Schema();
		$this->exporter = new Fake_Card_Exporter( $this->clock );
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
}
