<?php
/**
 * Harness of the «Товары сайта» provider tests: the export harness plus the WooCommerce tables and the wired services.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Activity;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Provider;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Reader;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Selection;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Flows\Link_Detacher;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Framework\Marketplace\Sync\Price_Intents;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;

/**
 * The export harness (one account, product doubles, real stores on SQLite, a fake Action Scheduler) with the catalogue of
 * {@see Products_Shop} on the same database, the bulk actions wired as the module wires them, and the provider over both.
 */
abstract class Products_Test_Case extends Export_Test_Case {
	/**
	 * @var Products_Shop
	 */
	protected $shop;

	/**
	 * Products the user may not edit (the product whose right decides).
	 *
	 * @var int[]
	 */
	protected $forbidden = [];

	/**
	 * Shop products the linking job would try to match.
	 *
	 * @var int
	 */
	protected $candidates = 7;

	/**
	 * Action ids the provider's adapter cannot serve.
	 *
	 * @var string[]
	 */
	protected $withheld = [];

	protected function setUp(): void {
		parent::setUp();

		$this->forbidden  = [];
		$this->candidates = 7;
		$this->withheld   = [];
		$this->shop       = new Products_Shop( $this->db, $this->account_id );
		$this->hook_stock();

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
	}

	/**
	 * The bulk actions, over the harness' stores.
	 *
	 * @return Site_Products_Bulk
	 */
	protected function bulk(): Site_Products_Bulk {
		return new Site_Products_Bulk(
			$this->service(),
			$this->links(),
			$this->stock_hook,
			new Price_Intents( $this->accounts(), Sqlite_Tables::MARKETPLACE_ID, $this->links(), $this->contexts(), $this->intents(), [ $this, 'log_line' ] ),
			$this->contexts(),
			new Link_Detacher( $this->links(), $this->queue(), $this->ledger(), $this->accounts() ),
			new Link_Scheduler( $this->names() ),
			[
				'link_candidates' => function (): int {
					return $this->candidates;
				},
				'can_edit'        => function ( int $product_id ): bool {
					return ! in_array( $product_id, $this->forbidden, true );
				},
				'describe'        => static function ( int $product_id ): string {
					return 'Товар ' . $product_id;
				},
				'fix_urls'        => [ Validation_Report::FIX_MAPPING => 'https://example.test/mappings' ],
			]
		);
	}

	/**
	 * The reader over the harness' tables.
	 *
	 * @return Site_Products_Reader
	 */
	protected function reader(): Site_Products_Reader {
		return new Site_Products_Reader(
			$this->db,
			Sqlite_Tables::table( 'links' ),
			Sqlite_Tables::table( 'sync_state' ),
			static function ( int $term_id ): array {
				return [ $term_id ];
			},
			static function (): ?\WC_Product {
				return null;
			}
		);
	}

	/**
	 * The activity reader over the harness' tables.
	 *
	 * @return Site_Products_Activity
	 */
	protected function activity(): Site_Products_Activity {
		return new Site_Products_Activity( $this->ledger(), $this->db, Sqlite_Tables::table( 'sync_state' ), Sqlite_Tables::table( 'accounts' ), $this->clock() );
	}

	/**
	 * The provider, wired like the module wires it.
	 *
	 * @return Site_Products_Provider
	 */
	protected function provider(): Site_Products_Provider {
		$reader = $this->reader();

		return new Site_Products_Provider(
			$this->accounts(),
			$reader,
			new Site_Products_Selection( $reader ),
			$this->activity(),
			$this->bulk(),
			new Warehouse_Options( new Wpdb_Catalog_Cache( $this->db, Sqlite_Tables::table( 'catalog_cache' ) ) ),
			new License_Gate(
				function (): bool {
					return $this->licensed;
				}
			),
			[
				'adapter_id'  => Sqlite_Tables::MARKETPLACE_ID,
				'marketplace' => 'Тестмаркет',
				'withheld'    => $this->withheld,
			]
		);
	}

	/**
	 * Row id of a product in the harness' account.
	 *
	 * @param int $product_id Product.
	 * @return string
	 */
	protected function row_id( int $product_id ): string {
		return $this->account_id . ':' . $product_id;
	}

	/**
	 * The context the engine resolves for the harness' account.
	 *
	 * @return array{account_id:string}
	 */
	protected function context(): array {
		return [ 'account_id' => (string) $this->account_id ];
	}
}
