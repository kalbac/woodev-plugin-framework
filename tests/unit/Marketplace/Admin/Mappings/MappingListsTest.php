<?php
/**
 * The two lists of the «Сопоставление и профили» page, as the records engine reads them.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Mappings
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Mappings;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Row_Normalizer;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Category_Finder;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_List_Definition;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_List_Provider;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_List_Definition;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_List_Provider;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Mappings_Harness.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\List_Definition
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\List_Provider
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_List_Definition
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_List_Provider
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_List_Definition
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_List_Provider
 */
final class MappingListsTest extends TestCase {
	private const PAGE = 'https://shop.test/wp-admin/admin.php?page=wc-admin&path=%2Fozon-seller%2Fmappings';

	/**
	 * Harness.
	 *
	 * @var Mappings_Harness
	 */
	private $h;

	/**
	 * Stubs WordPress and builds the objects: two product categories, «Одежда» (5) and «Платья» (6).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return trim( strip_tags( (string) $value ) );
			}
		);
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				return $default;
			}
		);
		Functions\when( 'get_term' )->alias(
			static function ( $id ) {
				return $id < 100 ? self::term( (int) $id, 0, 'Категория ' . $id ) : false;
			}
		);
		Functions\when( 'get_term_children' )->justReturn( [] );
		Functions\when( 'get_terms' )->justReturn( [ self::term( 5, 0, 'Одежда' ), self::term( 6, 5, 'Платья' ) ] );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '&' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
			}
		);
		$this->h = new Mappings_Harness();
	}

	/**
	 * @param int    $id     Term id.
	 * @param int    $parent Parent id.
	 * @param string $name   Name.
	 * @return \WP_Term
	 */
	private static function term( int $id, int $parent, string $name ): \WP_Term {
		$term          = new \WP_Term();
		$term->term_id = $id;
		$term->parent  = $parent;
		$term->name    = $name;

		return $term;
	}

	/**
	 * @return Mapping_List_Provider
	 */
	private function mappings(): Mapping_List_Provider {
		return new Mapping_List_Provider( self::PAGE, $this->h->editor, $this->h->profile_editor );
	}

	/**
	 * @return Profile_List_Provider
	 */
	private function profiles(): Profile_List_Provider {
		return new Profile_List_Provider( self::PAGE, $this->h->profile_editor );
	}

	/**
	 * @param array<string,mixed> $extra Replacements.
	 * @return array<string,mixed>
	 */
	private function body( array $extra = [] ): array {
		return array_merge(
			[
				'name'                => 'Платья',
				'terms'               => [ 6 ],
				'category'            => $this->h->dress_key(),
				'profile_id'          => null,
				'attributes'          => [ '1' => [ 'source' => 'fixed', 'value' => 'Zara' ] ],
				'attributes_category' => $this->h->dress_key(),
			],
			$extra
		);
	}

	/**
	 * Reads a table through the engine's own row normaliser, so a cell the engine would refuse fails here.
	 *
	 * @param Mapping_List_Provider|Profile_List_Provider $provider Provider.
	 * @param array<string,mixed>                         $query    Query over the defaults.
	 * @return array<string,mixed>
	 */
	private function read( $provider, array $query = [] ): array {
		$definition = Records_Definition::from_array( $provider->get_definition( [] ) );
		$query      = array_merge(
			[
				'page'      => 1,
				'per_page'  => 20,
				'sort'      => 'name',
				'direction' => 'asc',
			],
			$query
		);
		$page       = Row_Normalizer::page( $definition, $provider->query( [ 'account_id' => '' ], $query ), $query['per_page'] );

		$this->assertIsArray( $page, 'the engine accepts what the provider read' );

		return $page;
	}

	/**
	 * Both definitions pass the engine's parser, with the agreed section texts and a visible «Действия» column.
	 *
	 * @return void
	 */
	public function test_the_definitions_are_valid_and_carry_the_agreed_descriptions(): void {
		$mappings = Records_Definition::from_array( Mapping_List_Definition::build() )->to_array();
		$profiles = Records_Definition::from_array( Profile_List_Definition::build() )->to_array();

		$this->assertSame( 'На этой странице настраивается соответствие категорий вашего магазина категориям Ozon и указывается, откуда брать значения характеристик товаров.', $mappings['messages']['description'] );
		$this->assertSame( 'На этой странице хранятся профили выгрузки — общие правила для габаритов, веса, НДС и цены. Профиль настраивается один раз и выбирается в нужных сопоставлениях.', $profiles['messages']['description'] );
		$this->assertSame( [ 'name', 'category', 'shop', 'profile', 'attributes', 'products', 'actions' ], array_column( $mappings['columns'], 'id' ) );
		$this->assertSame( [ 'name', 'properties', 'used_in', 'actions' ], array_column( $profiles['columns'], 'id' ) );
		$this->assertSame( 'Действия', array_pop( $mappings['columns'] )['label'] );
		$this->assertSame( [ 'edit', 'delete' ], array_column( $mappings['row_actions'], 'id' ) );
		$this->assertSame( [ 'edit', 'trash' ], array_column( $mappings['row_actions'], 'icon' ) );
		$this->assertNotSame( '', $mappings['columns'][4]['help'], 'the «Атрибуты» heading explains itself' );
	}

	/**
	 * A mapping row carries every v1 column; the name and the profile are links to their forms.
	 *
	 * @return void
	 */
	public function test_a_mapping_row_has_the_v1_columns_and_links(): void {
		$profile = $this->h->profile_editor->save( null, [ 'name' => 'Основной', 'fallback_weight' => '300', 'unit_weight' => 'g' ] )['id'];
		$id      = $this->h->editor->save( null, $this->body( [ 'profile_id' => $profile ] ) )['id'];

		$page = $this->read( $this->mappings() );
		$row  = $page['rows'][0];

		$this->assertSame( 1, $page['total'] );
		$this->assertSame( 'сопоставление', $page['messages']['summary_label'] );
		$this->assertSame( (string) $id, $row['id'] );
		$this->assertSame( 'Платья', $row['cells']['name']['label'] );
		$this->assertStringContainsString( 'mapping=' . $id, $row['cells']['name']['url'] );
		$this->assertSame( 'Одежда › Платья', $row['cells']['category'] );
		$this->assertSame( 'Одежда › Платья', $row['cells']['shop'], 'the shop categories are named' );
		$this->assertSame( 'Основной', $row['cells']['profile']['label'] );
		$this->assertStringContainsString( 'tab=profiles&profile=' . $profile, $row['cells']['profile']['url'] );
		$this->assertSame( 0, $row['cells']['products'] );
		$this->assertSame( [ 'edit', 'delete' ], array_column( $row['actions'], 'id' ) );
		$this->assertSame( $row['cells']['name']['url'], $row['actions'][0]['url'], '«Редактировать» is a link to the same form' );
	}

	/**
	 * «Атрибуты»: a dash with a tooltip while the fields are not cached, «1 из 3 !» in orange when a required one is empty.
	 *
	 * @return void
	 */
	public function test_the_attributes_cell_is_a_dash_until_the_fields_are_cached_then_a_warning(): void {
		$this->h->editor->save( null, $this->body() );

		$uncached = $this->read( $this->mappings() )['rows'][0]['cells']['attributes'];

		$this->assertSame( '—', $uncached['label'] );
		$this->assertSame( 'Откройте сопоставление, чтобы загрузить характеристики.', $uncached['tooltip'] );

		$this->h->schema->warm  = true;
		$this->h->schema->calls = [];
		$warm                   = $this->read( $this->mappings() )['rows'][0]['cells']['attributes'];

		$this->assertSame( '1 из 3 !', $warm['label'] );
		$this->assertSame( 'warning', $warm['tone'] );
		$this->assertSame( 'Не заполнены обязательные атрибуты: 1', $warm['tooltip'] );
		$this->assertArrayNotHasKey( 'fields', $this->h->schema->calls, 'the list never asks the marketplace for fields' );
	}

	/**
	 * The list is sorted and paged on the server, by the key the table asks for, with a stable order for ties.
	 *
	 * @return void
	 */
	public function test_the_list_is_sorted_and_paged(): void {
		$other = Category_Finder::key_of( [ 'category_id' => 11, 'type_id' => 2 ] );
		$this->h->editor->save( null, $this->body( [ 'name' => 'Яблоки', 'terms' => [ 5 ] ] ) );
		$this->h->editor->save( null, $this->body( [ 'name' => 'арбузы', 'terms' => [ 6 ], 'category' => $other, 'attributes_category' => $other ] ) );

		$asc  = $this->read( $this->mappings() );
		$desc = $this->read( $this->mappings(), [ 'direction' => 'desc' ] );
		$page = $this->read( $this->mappings(), [ 'per_page' => 1, 'page' => 2 ] );

		$this->assertSame( 'арбузы', $asc['rows'][0]['cells']['name']['label'], 'a lower-case «а» does not sort after a capital «Я»' );
		$this->assertSame( 'Яблоки', $asc['rows'][1]['cells']['name']['label'] );
		$this->assertSame( 'Яблоки', $desc['rows'][0]['cells']['name']['label'] );
		$this->assertSame( 2, $page['total'] );
		$this->assertCount( 1, $page['rows'] );
		$this->assertSame( 'Яблоки', $page['rows'][0]['cells']['name']['label'] );
	}

	/**
	 * A shop with no product categories cannot map anything: the empty list says so.
	 *
	 * @return void
	 */
	public function test_an_empty_list_in_a_shop_without_categories_says_so(): void {
		Functions\when( 'get_terms' )->justReturn( [] );

		$page = $this->read( $this->mappings() );

		$this->assertSame( 0, $page['total'] );
		$this->assertStringContainsString( 'нет категорий товаров', $page['messages']['empty'] );
	}

	/**
	 * A profile row names what the profile sets with the values, «Не настроен» for an empty one, and the mappings that use it.
	 *
	 * @return void
	 */
	public function test_a_profile_row_shows_the_values_and_where_it_is_used(): void {
		$used = $this->h->profile_editor->save( null, [ 'name' => 'Основной', 'fallback_weight' => '300', 'unit_weight' => 'g', 'vat_rate' => '0.2' ] )['id'];
		$this->h->profile_editor->save( null, [ 'name' => 'Пустой' ] );
		$this->h->editor->save( null, $this->body( [ 'profile_id' => $used ] ) );

		$rows = array_column( $this->read( $this->profiles() )['rows'], 'cells', 'id' );

		$this->assertSame( 'Вес: 300 г · НДС: 20%', $rows[ (string) $used ]['properties'] );
		$this->assertSame( 'Платья', $rows[ (string) $used ]['used_in'] );
		$this->assertStringContainsString( 'tab=profiles&profile=' . $used, $rows[ (string) $used ]['name']['url'] );

		$empty = array_values( array_diff_key( $rows, [ (string) $used => 1 ] ) )[0];
		$this->assertSame( 'Не настроен', $empty['properties'] );
		$this->assertSame( '—', $empty['used_in'] );
	}

	/**
	 * Only the table's own reads are authorised: the engine runs no action of these lists.
	 *
	 * @return void
	 */
	public function test_only_the_definition_and_the_list_are_authorised(): void {
		$provider = $this->mappings();

		$this->assertTrue( $provider->authorize( 'definition', [], [] ) );
		$this->assertTrue( $provider->authorize( 'list', [], [] ) );
		$this->assertFalse( $provider->authorize( 'delete', [], [ '1' ] ) );
		$this->assertFalse( $provider->authorize( 'children', [], [ '1' ] ) );
	}
}
