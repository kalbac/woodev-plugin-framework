<?php
/**
 * The tables of the «Товары» page: which the framework declares by default, and how a page names the tables it offers.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin
 */

namespace Woodev\Tests\Unit\Marketplace\Admin;

use Woodev\Framework\Marketplace\Admin\Page;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Test_Marketplace_Plugin.php';

/**
 * A plugin double whose adapter supports (or does not) the import of products.
 */
final class Importing_Marketplace_Plugin extends Test_Marketplace_Plugin {

	/** @var bool */
	private $imports;

	/**
	 * @param bool $imports Whether the adapter supports {@see Capability::IMPORT_PRODUCTS}.
	 */
	public function __construct( bool $imports ) {
		parent::__construct( 'ozon', [ 'id' => 'ozon-seller', 'title' => 'OZON Seller', 'icon' => 'https://example.test/icon.svg', 'position' => '55.63' ] );

		$this->imports = $imports;
	}

	/** @return Marketplace_Adapter */
	public function get_marketplace_adapter(): Marketplace_Adapter {
		return new Fake_Marketplace_Adapter( null, [ 'capabilities' => $this->imports ? [ Capability::IMPORT_PRODUCTS ] : [] ] );
	}
}

/**
 * @covers \Woodev\Framework\Marketplace\Marketplace_Plugin::get_marketplace_pages
 * @covers \Woodev\Framework\Marketplace\Admin\Page::get_table_labels
 * @covers \Woodev\Framework\Marketplace\Admin\Menu::build_manifest
 */
final class ProductTablesTest extends TestCase {

	/**
	 * @param bool $imports Whether the adapter can import.
	 * @return Page
	 */
	private function products_page( bool $imports ): Page {
		$menu = ( new Importing_Marketplace_Plugin( $imports ) )->get_marketplace_admin_menu();

		$this->assertNotNull( $menu );

		return $menu->get_page( 'products' );
	}

	public function test_the_products_page_offers_the_cards_of_the_marketplace_when_it_can_list_them(): void {
		$page = $this->products_page( true );

		$this->assertSame( [ 'products', 'remote_cards' ], $page->get_tables() );
		$this->assertSame( [ 'products', 'remote_cards' ], array_keys( $page->get_table_labels() ), 'each table has a label for the switch' );
		$this->assertNotContains( '', $page->get_table_labels() );
	}

	public function test_the_products_page_of_a_marketplace_without_import_has_the_site_table_only(): void {
		$page = $this->products_page( false );

		$this->assertSame( [ 'products' ], $page->get_tables() );
		$this->assertSame( [], $page->get_table_labels(), 'one table keeps the page title: nothing to switch between' );
	}

	public function test_a_map_of_tables_keeps_the_ids_in_order_and_their_labels(): void {
		$page = Page::from_array( [ 'slug' => 'products', 'title' => 'Товары', 'kind' => 'records', 'tables' => [ 'products' => 'Товары сайта', 'remote_cards' => ' Карточки ' ] ] );

		$this->assertSame( [ 'products', 'remote_cards' ], $page->get_tables() );
		$this->assertSame( [ 'products' => 'Товары сайта', 'remote_cards' => 'Карточки' ], $page->get_table_labels() );
	}

	public function test_a_list_of_table_ids_still_works_and_has_no_labels(): void {
		$page = Page::from_array( [ 'slug' => 'orders', 'title' => 'Заказы', 'kind' => 'records', 'tables' => [ 'orders' ] ] );

		$this->assertSame( [ 'orders' ], $page->get_tables() );
		$this->assertSame( [], $page->get_table_labels() );
	}

	public function test_a_table_without_a_label_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Page::from_array( [ 'slug' => 'products', 'title' => 'Товары', 'kind' => 'records', 'tables' => [ 'products' => 'Товары', 'remote_cards' => ' ' ] ] );
	}

	public function test_the_manifest_carries_the_labels_of_a_page_that_has_them_and_adds_nothing_to_the_others(): void {
		$menu = ( new Importing_Marketplace_Plugin( true ) )->get_marketplace_admin_menu();

		$manifest = $menu->build_manifest(
			static function (): bool {
				return true;
			}
		);
		$by_id    = array_column( $manifest['pages'], null, 'id' );

		$this->assertSame( $this->products_page( true )->get_table_labels(), $by_id['products']['tableLabels'] );
		$this->assertArrayNotHasKey( 'tableLabels', $by_id['orders'], 'a page of one bare table is published exactly as before' );
	}
}
