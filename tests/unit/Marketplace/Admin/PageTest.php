<?php
/**
 * The page descriptor a marketplace plugin declares.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin
 */

namespace Woodev\Tests\Unit\Marketplace\Admin;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Admin\Page;

require_once dirname( __DIR__, 4 ) . '/woodev/marketplace/admin/class-page.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Page
 */
final class PageTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function records_descriptor(): array {
		return [
			'slug'   => 'products',
			'title'  => 'Товары',
			'kind'   => 'records',
			'tables' => [ 'products' ],
		];
	}

	public function test_a_records_page_keeps_what_it_was_given(): void {
		$page = Page::from_array( $this->records_descriptor() );

		$this->assertSame( 'products', $page->get_slug() );
		$this->assertSame( 'Товары', $page->get_title() );
		$this->assertSame( Page::KIND_RECORDS, $page->get_kind() );
		$this->assertSame( [ 'products' ], $page->get_tables() );
	}

	public function test_a_page_heading_defaults_to_its_title_and_can_differ_from_the_menu_entry(): void {
		$plain   = Page::from_array( $this->records_descriptor() );
		$heading = Page::from_array( $this->records_descriptor() + [ 'heading' => 'Товары OZON Seller' ] );

		$this->assertSame( 'Товары', $plain->get_heading() );
		$this->assertSame( 'Товары OZON Seller', $heading->get_heading() );
		$this->assertSame( 'Товары', $heading->get_title(), 'the menu entry keeps its short title' );
	}

	public function test_a_page_inherits_the_capability_of_its_menu_unless_it_names_its_own(): void {
		$inherits = Page::from_array( $this->records_descriptor() );
		$own      = Page::from_array( $this->records_descriptor() + [ 'capability' => 'manage_options' ] );

		$this->assertSame( 'manage_woocommerce', $inherits->get_capability( 'manage_woocommerce' ) );
		$this->assertSame( 'manage_options', $own->get_capability( 'manage_woocommerce' ) );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function provide_descriptors_that_are_refused(): array {
		return [
			'slug with a capital'            => [ [ 'slug' => 'Products', 'title' => 'T', 'kind' => 'settings' ] ],
			'slug with a slash'              => [ [ 'slug' => 'a/b', 'title' => 'T', 'kind' => 'settings' ] ],
			'no slug'                        => [ [ 'title' => 'T', 'kind' => 'settings' ] ],
			'empty title'                    => [ [ 'slug' => 'a', 'title' => '  ', 'kind' => 'settings' ] ],
			'unknown kind'                   => [ [ 'slug' => 'a', 'title' => 'T', 'kind' => 'classic' ] ],
			'records page without a table'   => [ [ 'slug' => 'a', 'title' => 'T', 'kind' => 'records' ] ],
			'custom page without a view'     => [ [ 'slug' => 'a', 'title' => 'T', 'kind' => 'custom' ] ],
			'analytics slot used'            => [ [ 'slug' => 'a', 'title' => 'T', 'kind' => 'settings', 'analytics' => [ 'x' ] ] ],
		];
	}

	/**
	 * @dataProvider provide_descriptors_that_are_refused
	 *
	 * @param array<string,mixed> $descriptor Descriptor.
	 */
	public function test_a_descriptor_that_cannot_describe_a_page_is_refused( array $descriptor ): void {
		$this->expectException( \InvalidArgumentException::class );

		Page::from_array( $descriptor );
	}

	public function test_the_analytics_slot_may_be_declared_null(): void {
		$page = Page::from_array( [ 'slug' => 'a', 'title' => 'T', 'kind' => 'settings', 'analytics' => null ] );

		$this->assertSame( 'a', $page->get_slug() );
	}

	/**
	 * @return array<string,array{0:string|string[],1:array<string,string>,2:bool}>
	 */
	public function provide_legacy_requests(): array {
		return [
			'a bare value is the page argument'             => [ 'ozon-seller', [ 'page' => 'ozon-seller' ], true ],
			'extra request arguments are ignored'           => [ 'ozon-seller', [ 'page' => 'ozon-seller', 'paged' => '2' ], true ],
			'a longer slug is not the same page'            => [ 'ozon-seller', [ 'page' => 'ozon-seller-orders' ], false ],
			'a slug inside another argument is not a match' => [ 'ozon-seller', [ 'page' => 'wc-status', 'log_file' => 'ozon-seller.log' ], false ],
			'every declared argument must match'            => [ 'page=wc-settings&tab=ozon', [ 'page' => 'wc-settings', 'tab' => 'cdek' ], false ],
			'a declared pair matches'                       => [ 'page=wc-settings&tab=ozon', [ 'page' => 'wc-settings', 'tab' => 'ozon' ], true ],
			'a list matches any of its entries'             => [ [ 'ozon-seller', 'ozon-seller-products' ], [ 'page' => 'ozon-seller-products' ], true ],
			'no declaration never matches'                  => [ [], [ 'page' => 'ozon-seller' ], false ],
			'an empty declaration never matches'            => [ '', [ 'page' => 'ozon-seller' ], false ],
		];
	}

	/**
	 * @dataProvider provide_legacy_requests
	 *
	 * @param string|string[]      $declared Declared legacy URLs.
	 * @param array<string,string> $request  Parsed request.
	 * @param bool                 $expected Whether the page claims the request.
	 */
	public function test_legacy_urls_match_on_parsed_arguments( $declared, array $request, bool $expected ): void {
		$page = Page::from_array( $this->records_descriptor() + [ 'legacy_page' => $declared ] );

		$this->assertSame( $expected, $page->matches_legacy_request( $request ) );
	}
}
