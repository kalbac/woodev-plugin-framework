<?php
/**
 * The «Товары сайта» provider against the records engine: what it declares, the rows it builds, the locks it reads and the
 * answers it gives to a preview and an apply are all run through the engine's own normalisers.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Shared\Link_Note;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Link_Notes;
use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_Response;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Activity_Reader;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Row_Normalizer;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Definition;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Selection;
use Woodev\Framework\Marketplace\Admin\Pages\Products\State_Groups;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;

require_once dirname( __DIR__, 3 ) . '/Shipping/Rest_Api/wp-rest-controller-stub.php';
require_once dirname( __DIR__ ) . '/Mappings/wp-term-stub.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Provider
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Definition
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Rows
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Activity
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Selection
 */
final class SiteProductsProviderTest extends Products_Test_Case {
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_terms' )->justReturn( [ (object) [ 'term_id' => 7, 'name' => 'Обувь' ] ] );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'get_edit_post_link' )->alias(
			static function ( $id ): string {
				return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit';
			}
		);
	}

	/**
	 * @return Records_Definition
	 */
	private function definition(): Records_Definition {
		return Records_Definition::from_array( $this->provider()->get_definition( $this->context() ) );
	}

	/**
	 * @param array<string,mixed> $query Query as the engine validates it.
	 * @return array<string,mixed> The list body.
	 */
	private function list_body( array $query = [] ): array {
		$result = $this->provider()->query( $this->context(), array_merge( [ 'page' => 1, 'per_page' => 20 ], $query ) );
		$body   = Row_Normalizer::page( $this->definition(), $result, 20 );

		$this->assertIsArray( $body, 'The engine accepts the list.' );

		return $body;
	}

	/**
	 * @param array<string,mixed> $body List body.
	 * @return array<string,array<string,mixed>> Rows by id.
	 */
	private function by_id( array $body ): array {
		return array_column( $body['rows'], null, 'id' );
	}

	public function test_the_definition_is_accepted_by_the_engine_and_declares_the_fourteen_actions_and_the_two_run_buttons(): void {
		$definition = $this->definition()->to_array();

		$this->assertSame(
			[ 'export', 'link_by_article', 'send_stock', 'send_price', 'sync_stock_on', 'sync_stock_off', 'sync_price_on', 'sync_price_off', 'set_warehouse', 'pull_prices', 'archive', 'detach', 'reset', 'reset_price', 'bulk_stop', 'bulk_report' ],
			array_column( $definition['bulk_actions'], 'id' )
		);
		$this->assertSame( 'account', $definition['bulk_actions'][1]['scope'], 'Linking by article names its reach.' );
		$this->assertNotSame( '', $definition['bulk_actions'][1]['scope_sentence'] );
		$this->assertSame( [ [ 'value' => (string) $this->account_id, 'label' => 'Основной' ] ], $definition['context']['options'] );
		$this->assertNotNull( $definition['children'] );
		$this->assertNull( $definition['analytics'] );
		$this->assertSame( [ 'state' ], array_column( array_filter( $definition['filters'], static fn( array $filter ): bool => ! empty( $filter['views'] ) ), 'id' ), 'The state is the counted row.' );
		$this->assertArrayNotHasKey( 'summary_label', $definition['messages'], 'The count word comes with each list, in the right plural form.' );
		$this->assertSame(
			[ 'Выгрузка', 'Выгрузка', 'Выгрузка', 'Синхронизация', 'Синхронизация', 'Синхронизация', 'Синхронизация', 'Синхронизация', 'Синхронизация', 'Связи и архив', 'Связи и архив', 'Связи и архив', 'Связи и архив' ],
			array_column( array_filter( $definition['bulk_actions'], static fn( array $action ): bool => 'selection' === $action['scope'] ), 'group' ),
			'The select lists the actions under three headings, in this order.'
		);
		$this->assertSame( 'Проверить связи', $definition['bulk_actions'][1]['preview_label'] );
		$this->assertSame( 'Начните вводить название категории', $definition['bulk_actions'][1]['fields'][1]['placeholder'] );
		$this->assertSame( 'Выберите действие', $definition['messages']['bulk_placeholder'] );
	}

	public function test_the_warehouse_action_asks_for_a_warehouse_from_a_local_list(): void {
		$actions = array_column( $this->definition()->to_array()['bulk_actions'], null, 'id' );
		$field   = $actions['set_warehouse']['fields'][0];

		$this->assertSame( 'warehouse', $field['id'] );
		$this->assertTrue( $field['required'] );
		$this->assertTrue( $field['options_source'] );

		$options = $this->provider()->get_field_options( $this->context(), 'set_warehouse', 'warehouse', [ 'row_id' => '', 'search' => '', 'cursor' => '' ] );

		$this->assertSame( '0', $options['options'][0]['value'], 'The first choice is the account default.' );
	}

	public function test_linking_by_article_asks_the_whole_shop_switch_and_which_category(): void {
		$actions = array_column( $this->definition()->to_array()['bulk_actions'], null, 'id' );
		$fields  = array_column( $actions['link_by_article']['fields'], null, 'id' );

		$this->assertSame( [ 'whole_shop', 'category' ], array_keys( $fields ) );
		$this->assertSame( 'boolean', $fields['whole_shop']['type'] );
		$this->assertTrue( $fields['whole_shop']['toggle'] );
		$this->assertTrue( $fields['category']['options_source'] );
		$this->assertTrue( $fields['category']['searchable'] );
		$this->assertTrue( $fields['category']['required'], 'The category is needed while the switch is off.' );
		$this->assertSame( 'whole_shop', $fields['category']['hidden_when'], 'The switch hides it.' );
		$this->assertArrayNotHasKey( 'fields', Site_Products_Definition::link_by_article(), 'A table that cannot list categories keeps the plain action.' );
	}

	/**
	 * Categories as the term API returns them.
	 *
	 * @param array<int,array{0:int,1:string,2:int}> $rows Term id, name, parent.
	 * @return \WP_Term[]
	 */
	private function terms( array $rows ): array {
		return array_map(
			static function ( array $row ): \WP_Term {
				$term          = new \WP_Term();
				$term->term_id = $row[0];
				$term->name    = $row[1];
				$term->parent  = $row[2];

				return $term;
			},
			$rows
		);
	}

	/**
	 * Makes term 7 «Обувь» the only product category the shop has.
	 *
	 * @return void
	 */
	private function shop_has_category_seven(): void {
		Functions\when( 'get_term' )->alias(
			function ( int $term_id ) {
				return 7 === $term_id ? $this->terms( [ [ 7, 'Обувь', 0 ] ] )[0] : null;
			}
		);
	}

	public function test_one_category_reaches_the_bulk_as_the_term_and_the_whole_shop_as_none(): void {
		$this->shop_has_category_seven();

		$this->provider()->preview( $this->context(), 'link_by_article', [], [ 'whole_shop' => false, 'category' => '7' ] );
		$this->provider()->apply( $this->context(), 'link_by_article', [], [ 'whole_shop' => false, 'category' => '7' ] );
		$this->provider()->preview( $this->context(), 'link_by_article', [], [ 'whole_shop' => true ] );
		$this->provider()->preview( $this->context(), 'link_by_article', [], [ 'whole_shop' => true, 'category' => '7' ] );

		$this->assertSame( [ 7, 7, null, null ], $this->candidates_asked, 'The apply replans with the category; the switch ignores a category left in the form.' );
		$queued = $this->scheduler->of( ( new Link_Scheduler( $this->names() ) )->link_hook() );
		$this->assertSame( [ $this->account_id, 7, 0 ], $queued[0]['args'] );
	}

	/**
	 * @return array<string,array{0:array<string,string>}>
	 */
	public function bad_categories(): array {
		return [
			'none chosen'      => [ [] ],
			'switch off'       => [ [ 'whole_shop' => false ] ],
			'empty'            => [ [ 'whole_shop' => false, 'category' => '' ] ],
			'not a number'     => [ [ 'whole_shop' => false, 'category' => 'shoes' ] ],
			'zero'             => [ [ 'whole_shop' => false, 'category' => '0' ] ],
			'negative'         => [ [ 'whole_shop' => false, 'category' => '-7' ] ],
			'not a product_cat' => [ [ 'whole_shop' => false, 'category' => '99' ] ],
		];
	}

	/**
	 * @dataProvider bad_categories
	 *
	 * @param array<string,string> $payload Fields.
	 * @return void
	 */
	public function test_a_category_that_is_not_a_product_category_is_refused_on_the_category_field( array $payload ): void {
		$this->shop_has_category_seven();

		foreach ( [ 'preview', 'apply' ] as $method ) {
			try {
				$this->provider()->$method( $this->context(), 'link_by_article', [], $payload );
				$this->fail( "$method accepted the category." );
			} catch ( Action_Input_Exception $e ) {
				$this->assertSame( [ 'category' ], array_keys( $e->get_errors() ) );
			}
		}

		$this->assertSame( [], $this->scheduler->of( ( new Link_Scheduler( $this->names() ) )->link_hook() ), 'Nothing was queued.' );
	}

	public function test_the_category_picker_pages_and_searches_by_name_and_shows_the_parents(): void {
		$asked = [];
		Functions\when( 'get_terms' )->alias(
			function ( array $args ) use ( &$asked ): array {
				$asked[] = $args;

				return $this->terms( [ [ 7, 'Обувь', 5 ], [ 8, 'Сумки', 0 ] ] );
			}
		);
		Functions\when( 'get_ancestors' )->alias(
			static function ( int $term_id ): array {
				return 7 === $term_id ? [ 5, 4 ] : [];
			}
		);
		Functions\when( 'get_term' )->alias(
			function ( int $term_id ) {
				$names = [ 4 => 'Каталог', 5 => 'Одежда' ];

				return isset( $names[ $term_id ] ) ? $this->terms( [ [ $term_id, $names[ $term_id ], 0 ] ] )[0] : null;
			}
		);

		$page = $this->provider()->get_field_options( $this->context(), 'link_by_article', 'category', [ 'row_id' => '', 'search' => 'об', 'cursor' => '60' ] );

		$this->assertSame( 'product_cat', $asked[0]['taxonomy'] );
		$this->assertSame( 'об', $asked[0]['search'] );
		$this->assertSame( 60, $asked[0]['offset'] );
		$this->assertSame( [ [ 'value' => '7', 'label' => 'Каталог › Одежда › Обувь' ], [ 'value' => '8', 'label' => 'Сумки' ] ], $page['options'] );
		$this->assertNull( $page['next_cursor'], 'A short page is the last one.' );
	}

	public function test_the_category_picker_hands_over_a_cursor_while_there_are_more_categories(): void {
		Functions\when( 'get_ancestors' )->justReturn( [] );
		Functions\when( 'get_terms' )->alias(
			function (): array {
				return $this->terms( array_map( static fn( int $id ): array => [ $id, "Категория $id", 0 ], range( 1, 31 ) ) );
			}
		);

		$page = $this->provider()->get_field_options( $this->context(), 'link_by_article', 'category', [ 'row_id' => '', 'search' => '', 'cursor' => '' ] );

		$this->assertCount( 30, $page['options'], 'The thirty-first category only proves there is a next page.' );
		$this->assertSame( '30', $page['next_cursor'] );
	}

	public function test_an_adapter_that_cannot_import_gets_no_export_and_no_linking(): void {
		$this->withheld = [ Site_Products_Bulk::EXPORT, Site_Products_Bulk::LINK_BY_ARTICLE ];

		$ids = array_column( $this->definition()->to_array()['bulk_actions'], 'id' );

		$this->assertCount( 14, $ids );
		$this->assertNotContains( 'export', $ids );
		$this->assertNotContains( 'link_by_article', $ids );
	}

	public function test_the_list_is_sorted_over_the_whole_table_and_each_row_is_account_scoped(): void {
		$body = $this->list_body( [ 'sort' => 'price', 'direction' => 'desc' ] );

		$this->assertSame( 3, $body['total'] );
		$this->assertSame( [ $this->row_id( 10 ), $this->row_id( 30 ), $this->row_id( 20 ) ], array_column( $body['rows'], 'id' ) );
		$this->assertArrayNotHasKey( 'summary', $body['messages'], 'The card footer counts the rows; no sentence repeats it.' );
	}

	/**
	 * @param array<string,mixed> $query Query as the engine validates it.
	 * @return array<string,int> Count by state group; the empty key is the count with the state itself left out.
	 */
	private function state_facets( array $query = [] ): array {
		return array_column( $this->list_body( $query )['facets']['state'], 'count', 'value' );
	}

	public function test_the_state_facets_count_each_group_as_the_list_filtered_by_it_would(): void {
		$facets = $this->state_facets();

		unset( $facets[''] );

		$this->assertSame( array_keys( State_Groups::labels() ), array_keys( $facets ), 'One count per group, in the filter\'s own order.' );

		foreach ( $facets as $group => $count ) {
			$this->assertSame( $count, $this->list_body( [ 'filters' => [ 'state' => $group ] ] )['total'], "The count of $group is the total of that view." );
		}
	}

	public function test_the_state_facets_are_not_a_partition_when_a_variable_product_has_variations_in_two_groups(): void {
		$body   = $this->list_body();
		$facets = $this->state_facets();
		$all    = $facets[''];

		unset( $facets[''] );

		// Product 20 has an errored variation and an unlinked one: it is listed under both groups, so the groups add up to one
		// more than the products.
		$this->assertSame( $body['total'] + 1, array_sum( $facets ) );
		$this->assertSame( 1, $facets[ State_Groups::ERROR ] );
		$this->assertSame( $body['total'], $all, 'The entry with the empty value is the real number of products.' );
	}

	public function test_the_empty_value_facet_is_the_product_count_without_the_state_whichever_state_is_chosen(): void {
		$every = $this->list_body()['total'];

		$this->assertSame( $every, $this->state_facets( [ 'filters' => [ 'state' => State_Groups::ERROR ] ] )[''], 'A chosen view does not change «Все».' );
		$this->assertSame( 1, $this->state_facets( [ 'filters' => [ 'q' => 'B-30' ] ] )[''], 'Another filter does.' );
		$this->assertSame( 1, $this->state_facets( [ 'filters' => [ 'q' => 'B-30', 'state' => State_Groups::ERROR ] ] )[''] );
	}

	public function test_the_state_facets_keep_every_other_filter_and_ignore_the_state_itself(): void {
		$searched = $this->state_facets( [ 'filters' => [ 'state' => State_Groups::CREATED, 'q' => 'B-30' ] ] );

		// «Все» is a separate count (`count_without_state()`); it must not stand in for the siblings' own numbers.
		unset( $searched[''] );

		$this->assertSame( 0, $searched[ State_Groups::CREATED ], 'The search narrows the counts…' );
		$this->assertGreaterThan( 0, array_sum( $searched ), '…and the chosen state does not: its siblings still show their numbers.' );
	}

	public function test_a_variable_product_is_a_parent_row_that_sums_its_variations(): void {
		$rows   = $this->by_id( $this->list_body() );
		$parent = $rows[ $this->row_id( 20 ) ];

		$this->assertTrue( $parent['has_children'] );
		$this->assertSame( 'Кроссовки', $parent['cells']['name'] );
		$this->assertSame( '7 / —', $parent['cells']['stock'], 'Three plus four.' );
		$this->assertSame( '200 – 250 / —', $parent['cells']['price'] );
		$this->assertSame( [ 'Ошибка', 'Не связан' ], array_column( array_column( $parent['cells']['state'], 'value' ), 'label' ) );
		$this->assertSame( 'Ошибка у 1 варианта', $parent['cells']['state'][0]['value']['tooltip'], 'The error of a variation is the tooltip of the first badge.' );
		$this->assertSame( 'https://example.test/wp-admin/post.php?post=20&action=edit', $parent['actions'][0]['url'] );
	}

	public function test_a_simple_product_shows_its_state_article_and_marketplace_stock(): void {
		$this->shop->readback( (int) $this->link_of( 10 )->link_id(), 9, 2 );

		$row = $this->by_id( $this->list_body() )[ $this->row_id( 10 ) ];

		$this->assertFalse( $row['has_children'] );
		$this->assertSame( 'Создан', $row['cells']['state'][0]['value']['label'] );
		$this->assertSame( 'success', $row['cells']['state'][0]['value']['tone'] );
		$this->assertSame( [ 'P-10', 'OZ-10' ], array_column( $row['cells']['sku'], 'value' ), 'The offer id differs from the article: both are shown.' );
		$this->assertSame( '5 / 7', $row['cells']['stock'] );
		$this->assertCount( 1, $row['cells']['state'], 'One badge; what is synchronised is its tooltip.' );
		$this->assertSame( 'Синхронизируются: остатки, цены', $row['cells']['state'][0]['value']['tooltip'], 'Only what is on is listed; the default warehouse is not written out.' );
		$this->assertArrayNotHasKey( 'warehouse', $row['cells'] );
	}

	public function test_filters_reach_the_provider_as_typed_values(): void {
		$errors = $this->list_body( [ 'filters' => [ 'state' => State_Groups::ERROR ] ] );

		$this->assertSame( [ $this->row_id( 20 ) ], array_column( $errors['rows'], 'id' ) );
		$this->assertSame( [ $this->row_id( 30 ) ], array_column( $this->list_body( [ 'filters' => [ 'q' => 'B-30' ] ] )['rows'], 'id' ) );
	}

	public function test_a_table_without_an_account_is_empty_not_an_error(): void {
		$result = $this->provider()->query( [ 'account_id' => '' ], [ 'page' => 1, 'per_page' => 20 ] );

		$this->assertSame( [ 'rows' => [], 'total' => 0 ], $result );
	}

	public function test_variations_are_child_rows_with_their_own_state_error_and_actions(): void {
		$definition = $this->definition();
		$result     = $this->provider()->get_children( $this->context(), $this->row_id( 20 ), [ 'page' => 1, 'per_page' => 20 ] );
		$body       = Row_Normalizer::children_page( $definition, $result, 20 );

		$this->assertIsArray( $body );
		$this->assertSame( 2, $body['total'] );
		$this->assertSame( [ $this->row_id( 21 ), $this->row_id( 22 ) ], array_column( $body['rows'], 'id' ) );
		$this->assertSame( [ 'красный', 'синий' ], array_column( array_column( $body['rows'], 'cells' ), 'name' ) );
		$this->assertSame( 'Ошибка', $body['rows'][0]['cells']['state'][0]['value']['label'] );
		$this->assertStringContainsString( 'Ошибка: Не заполнен бренд', $body['rows'][0]['cells']['state'][0]['value']['tooltip'] );
		$this->assertSame( 'https://example.test/wp-admin/post.php?post=20&action=edit', $body['rows'][0]['actions'][0]['url'], 'A variation is edited on its parent.' );
		$this->assertFalse( $body['rows'][0]['has_children'] );
	}

	public function test_only_rows_of_this_account_and_listed_products_pass_the_object_check(): void {
		$provider = $this->provider();

		$this->assertTrue( $provider->authorize( 'list', $this->context(), [] ) );
		$this->assertTrue( $provider->authorize( 'sync_stock_on', $this->context(), [ $this->row_id( 10 ), $this->row_id( 21 ) ] ) );
		$this->assertFalse( $provider->authorize( 'sync_stock_on', $this->context(), [ '999:10' ] ), 'Another account.' );
		$this->assertFalse( $provider->authorize( 'sync_stock_on', $this->context(), [ $this->row_id( 999 ) ] ), 'Not a product.' );
		$this->assertFalse( $provider->authorize( 'sync_stock_on', $this->context(), [ $this->account_id . ':010' ] ), 'Not the canonical id.' );
		$this->assertTrue( $provider->authorize( 'children', $this->context(), [ $this->row_id( 20 ) ] ) );
		$this->assertFalse( $provider->authorize( 'children', $this->context(), [ $this->row_id( 21 ) ] ), 'A variation has no children.' );
		$this->assertFalse( $provider->authorize( 'children', $this->context(), [ $this->row_id( 10 ) ] ), 'Neither has a simple product.' );
	}

	public function test_the_activity_refresh_is_not_refused_because_a_row_has_vanished(): void {
		$provider = $this->provider();

		$this->assertTrue( $provider->authorize( 'activity', $this->context(), [ $this->row_id( 10 ), $this->row_id( 777 ) ] ), 'A trashed product is answered in removed_ids.' );
		$this->assertFalse( $provider->authorize( 'activity', $this->context(), [ '999:10' ] ), 'Another account is still refused.' );
		$this->assertFalse( $provider->authorize( 'activity', $this->context(), [ 'nonsense' ] ) );
	}

	public function test_selecting_a_parent_and_its_child_reaches_each_variation_once(): void {
		$resolved = ( new Site_Products_Selection( $this->reader() ) )->resolve( $this->account_id, [ $this->row_id( 20 ), $this->row_id( 21 ), $this->row_id( 10 ) ] );

		$this->assertEqualsCanonicalizing( [ 21, 22, 10 ], $resolved['offers'] );
		$this->assertSame( [ 10 => 10, 21 => 20, 22 => 20 ], $resolved['editable'], 'A variation is judged by its parent.' );
		$this->assertNull( ( new Site_Products_Selection( $this->reader() ) )->resolve( $this->account_id, [ '999:10' ] ) );
	}

	public function test_a_preview_counts_distinct_variations_and_groups_what_it_leaves_out(): void {
		$result = $this->provider()->preview( $this->context(), 'sync_stock_off', [ $this->row_id( 20 ), $this->row_id( 21 ) ], [] );
		$body   = Action_Response::preview( $this->definition()->get_action( 'sync_stock_off' ), $result );

		$this->assertIsArray( $body );
		$this->assertSame( 2, $body['requested'], 'The parent and its child are two variations, not three rows.' );
		$this->assertSame( 1, $body['eligible'], 'Only 21 has a link.' );
		$this->assertSame( 1, $body['skipped'] );
		$this->assertStringContainsString( 'сначала экспортируйте', $body['groups'][0]['reason'] );
		$this->assertTrue( $body['can_apply'] );
		$this->assertTrue( $this->link_of( 21 )->sync_stock(), 'A preview writes nothing.' );
	}

	public function test_an_apply_reports_what_landed_and_the_engine_derives_the_status(): void {
		$result = $this->provider()->apply( $this->context(), 'sync_stock_off', [ $this->row_id( 20 ) ], [] );
		$body   = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'sync_stock_off' ), $result );

		$this->assertIsArray( $body );
		$this->assertSame( 'partial', $body['status'] );
		$this->assertSame( 1, $body['accepted'] );
		$this->assertSame( 1, $body['skipped'] );
		$this->assertFalse( $this->link_of( 21 )->sync_stock() );
	}

	public function test_the_warehouse_must_be_one_of_the_accounts_or_the_default(): void {
		$this->expectException( Action_Input_Exception::class );

		try {
			$this->provider()->preview( $this->context(), 'set_warehouse', [ $this->row_id( 10 ) ], [ 'warehouse' => '987654' ] );
		} catch ( Action_Input_Exception $e ) {
			$this->assertSame( [ 'warehouse' ], array_keys( $e->get_errors() ) );

			throw $e;
		}
	}

	public function test_the_default_warehouse_is_accepted(): void {
		$result = $this->provider()->apply( $this->context(), 'set_warehouse', [ $this->row_id( 10 ) ], [ 'warehouse' => '0' ] );

		$this->assertSame( 0, $result['accepted'], 'The link already has the default.' );
		$this->assertSame( 1, $result['skipped'] );
	}

	public function test_a_lapsed_licence_stops_every_action_that_writes_to_the_marketplace(): void {
		$this->licensed = false;

		foreach ( [ 'export', 'send_stock', 'send_price', 'archive', 'link_by_article' ] as $action ) {
			try {
				$this->provider()->preview( $this->context(), $action, 'link_by_article' === $action ? [] : [ $this->row_id( 10 ) ], [] );
				$this->fail( $action . ' must be refused.' );
			} catch ( Action_State_Exception $e ) {
				$this->assertStringContainsString( 'Лицензия', $e->getMessage() );
			}
		}

		// Local changes do not need the licence.
		$this->assertSame( 1, $this->provider()->preview( $this->context(), 'sync_price_off', [ $this->row_id( 10 ) ], [] )['eligible'] );
	}

	public function test_a_selection_past_the_limit_is_refused_whole(): void {
		$shop = $this->shop;
		$ids  = [];

		for ( $i = 0; $i <= Site_Products_Selection::MAX_OFFERS; $i++ ) {
			$shop->product( 1000 + $i, 'Товар ' . $i, 'X-' . $i, 1, '1', 'simple' );
			$ids[] = $this->row_id( 1000 + $i );
		}

		$this->expectException( Action_State_Exception::class );
		$this->provider()->preview( $this->context(), 'sync_stock_off', $ids, [] );
	}

	public function test_a_card_being_submitted_is_locked_against_export_and_detach_with_its_reason(): void {
		$this->links()->set_state( (int) $this->link_of( 21 )->link_id(), Link_Record::STATE_SUBMITTED );

		$definition = $this->definition();
		$result     = $this->provider()->get_children( $this->context(), $this->row_id( 20 ), [ 'page' => 1, 'per_page' => 20 ] );
		$body       = Row_Normalizer::children_page( $definition, $result, 20 );
		$locked     = $body['rows'][0]['activity'];

		$this->assertTrue( $locked['locked'] );
		$this->assertSame( [ 'export', 'detach' ], $locked['blocked_actions'] );
		$this->assertNotSame( '', $locked['reason'] );
		$this->assertSame( 'waiting', $locked['processes'][0]['state'] );
		$this->assertNull( $locked['processes'][0]['percent'], 'No measurable total: no percentage.' );
		$this->assertFalse( $body['rows'][1]['activity']['locked'], 'The other variation is free.' );

		$parent = $this->by_id( $this->list_body() )[ $this->row_id( 20 ) ]['activity'];
		$this->assertFalse( $parent['locked'], 'A parent is blocked only when every variation is.' );
		$this->assertSame( 'Карточка', $parent['processes'][0]['label'] );
		$this->assertSame( '1 вариант', $parent['processes'][0]['text'] );
	}

	public function test_a_pending_stock_intent_shows_as_a_process_without_a_lock(): void {
		$link_id = (int) $this->link_of( 10 )->link_id();
		$this->intents()->record( $this->account_id, $link_id, 22, Sync_State_Store::KIND_STOCK, [ 'stock' => 4 ] );

		$activity = $this->by_id( $this->list_body() )[ $this->row_id( 10 ) ]['activity'];

		$this->assertFalse( $activity['locked'] );
		$this->assertSame( [], $activity['blocked_actions'] );
		$this->assertSame( 'Отправка остатков', $activity['processes'][0]['label'] );
		$this->assertSame( 'queued', $activity['processes'][0]['state'] );
	}

	public function test_the_account_line_blocks_detach_while_another_holder_has_the_claim(): void {
		$idle = $this->provider()->query( $this->context(), [ 'page' => 1, 'per_page' => 20 ] )['account_activity'];
		$this->assertFalse( $idle['active'] );

		$this->assertNotNull( $this->accounts()->claim( $this->account_id, 60 ) );

		$busy = $this->provider()->query( $this->context(), [ 'page' => 1, 'per_page' => 20 ] )['account_activity'];

		$this->assertTrue( $busy['active'] );
		$this->assertSame( [ 'detach' ], $busy['blocked_actions'] );
		$this->assertNotSame( '', $busy['text'] );
	}

	public function test_the_activity_read_answers_for_parents_children_and_vanished_rows(): void {
		$ids  = [ $this->row_id( 20 ), $this->row_id( 21 ), $this->row_id( 10 ), $this->row_id( 777 ) ];
		$body = Activity_Reader::read( $this->definition(), $this->provider(), $this->context(), $ids );

		$this->assertIsArray( $body );
		$this->assertEqualsCanonicalizing( [ $this->row_id( 20 ), $this->row_id( 21 ), $this->row_id( 10 ) ], array_column( $body['rows'], 'id' ) );
		$this->assertSame( [ $this->row_id( 777 ) ], $body['removed_ids'] );
		$this->assertFalse( $body['account_activity']['active'] );
	}

	public function test_a_link_row_of_another_account_never_leaks_into_this_table(): void {
		$this->shop->link( 30, 0, 'FOREIGN-30', Link_Record::STATE_CREATED, [], 99 );

		$row = $this->by_id( $this->list_body() )[ $this->row_id( 30 ) ];

		$this->assertSame( 'Не связан', $row['cells']['state'][0]['value']['label'] );
		$this->assertCount( 1, $row['cells']['state'], 'Unlinked: the badge alone, no synchronisation line.' );
	}

	/**
	 * Makes the adapter keep a note about the links of some products.
	 *
	 * @param array<int,Link_Note> $by_product Notes by product id.
	 * @return Fake_Link_Notes
	 */
	private function note( array $by_product ): Fake_Link_Notes {
		$notes = new Fake_Link_Notes();

		foreach ( $by_product as $product_id => $note ) {
			$notes->notes[ (int) $this->link_of( $product_id )->link_id() ] = $note;
		}

		$this->link_notes = $notes;

		return $notes;
	}

	public function test_a_note_about_a_link_opens_the_tooltip_of_its_state_badge_and_colours_it(): void {
		$notes = $this->note( [ 10 => new Link_Note( 'Добавьте документы в кабинете.' ) ] );

		$badge = $this->by_id( $this->list_body() )[ $this->row_id( 10 ) ]['cells']['state'][0]['value'];

		$this->assertSame( 'Создан', $badge['label'] );
		$this->assertSame( 'warning', $badge['tone'] );
		$this->assertSame( "Добавьте документы в кабинете.\nСинхронизируются: остатки, цены", $badge['tooltip'] );
		$this->assertCount( 1, $notes->asked, 'the plugin is asked once for the whole page' );
		$this->assertSame( $this->account_id, $notes->asked[0][0] );
		$this->assertEqualsCanonicalizing( [ (int) $this->link_of( 10 )->link_id(), (int) $this->link_of( 21 )->link_id() ], $notes->asked[0][1], 'only the products with a link' );
	}

	public function test_a_note_about_a_variation_is_said_at_the_badge_of_its_group_of_the_parent_row(): void {
		$this->note( [ 21 => new Link_Note( 'Добавьте документы.', Link_Note::TONE_INFO ) ] );

		$states = array_column( $this->by_id( $this->list_body() )[ $this->row_id( 20 ) ]['cells']['state'], 'value' );

		$this->assertSame( [ 'Ошибка', 'Не связан' ], array_column( $states, 'label' ) );
		$this->assertSame( "Добавьте документы.\nОшибка у 1 варианта", $states[0]['tooltip'] );
		$this->assertSame( 'danger', $states[0]['tone'] );
		$this->assertArrayNotHasKey( 'tooltip', $states[1] );
	}

	public function test_a_child_row_and_its_activity_refresh_say_the_note_too(): void {
		$notes    = $this->note( [ 21 => new Link_Note( 'Отклонена модерацией.', Link_Note::TONE_ERROR ) ] );
		$provider = $this->provider();

		$children = $provider->get_children( $this->context(), $this->row_id( 20 ), [ 'page' => 1, 'per_page' => 20 ] );
		$activity = $provider->get_activity( $this->context(), [ $this->row_id( 21 ) ] );

		foreach ( [ $children['rows'][0], $activity['rows'][0] ] as $row ) {
			$badge = $row['cells']['state'][0]['value'];

			$this->assertStringStartsWith( "Отклонена модерацией.\n", $badge['tooltip'] );
			$this->assertSame( 'danger', $badge['tone'] );
		}

		$this->assertCount( 2, $notes->asked, 'one question per read' );
	}

	public function test_the_table_id_is_the_page_table(): void {
		$this->assertSame( 'products', $this->provider()->get_id() );
		$this->assertSame( Sqlite_Tables::MARKETPLACE_ID, 'fake' );
	}
}
