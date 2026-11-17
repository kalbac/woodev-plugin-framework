<?php
/**
 * The help links a marketplace page's manifest entry carries.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin
 */

namespace Woodev\Tests\Unit\Marketplace\Admin;

use Woodev\Framework\Marketplace\Admin\Menu;
use Woodev\Framework\Marketplace\Admin\Page;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/marketplace/admin/class-page.php';
require_once dirname( __DIR__, 4 ) . '/woodev/marketplace/admin/class-menu.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Menu::build_manifest
 * @covers \Woodev\Framework\Marketplace\Admin\Page::get_problems_topic
 */
final class MenuHelpLinksTest extends TestCase {

	private const DOCS = 'https://woodev.ru/docs';

	/**
	 * @return Menu
	 */
	private function menu(): Menu {
		return new Menu(
			'ozon-plugin',
			[
				'id'    => 'ozon-seller',
				'title' => 'OZON Seller',
			],
			[
				Page::from_array( [ 'slug' => 'products', 'title' => 'Товары', 'kind' => 'records', 'tables' => [ 'products' ], 'help' => 'products' ] ),
				Page::from_array( [ 'slug' => 'tools', 'title' => 'Инструменты', 'kind' => 'records', 'tables' => [ 'tools' ], 'problems' => 'tools-trouble' ] ),
			]
		);
	}

	/**
	 * @param array<string,string> $topics The plugin's help table.
	 * @return array<int,array<string,mixed>>
	 */
	private function pages( array $topics ): array {
		return $this->menu()->build_manifest( static fn(): bool => true, $topics )['pages'];
	}

	public function test_the_problems_link_defaults_to_the_slug_topic_and_a_descriptor_may_name_its_own(): void {
		$pages = $this->pages(
			[
				'products-problems' => self::DOCS . '/products/#chastye-problemy',
				'tools-trouble'     => self::DOCS . '/tools/#chastye-problemy',
			]
		);

		$this->assertSame( self::DOCS . '/products/#chastye-problemy', $pages[0]['problems']['url'] );
		$this->assertSame( self::DOCS . '/tools/#chastye-problemy', $pages[1]['problems']['url'] );
		$this->assertSame( 'Что делать, если не получается', $pages[0]['problems']['text'] );
	}

	public function test_a_page_gets_no_link_for_a_topic_the_plugin_table_lacks(): void {
		$pages = $this->pages( [ 'tools-trouble' => self::DOCS . '/tools/#chastye-problemy' ] );

		$this->assertArrayNotHasKey( 'problems', $pages[0], 'no products-problems in the table' );
		$this->assertArrayHasKey( 'problems', $pages[1] );
		$this->assertArrayNotHasKey( 'help', $pages[0], 'the page help topic is not in the table either' );
	}

	public function test_a_plugin_without_a_table_gets_no_links_at_all(): void {
		foreach ( $this->pages( [] ) as $page ) {
			$this->assertArrayNotHasKey( 'problems', $page );
			$this->assertArrayNotHasKey( 'help', $page );
		}
	}
}
