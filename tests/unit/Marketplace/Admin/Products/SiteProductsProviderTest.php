<?php
/**
 * The «Товары сайта» provider against the records engine: what it declares, the rows it builds, the locks it reads and the
 * answers it gives to a preview and an apply are all run through the engine's own normalisers.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_Response;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Activity_Reader;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Row_Normalizer;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Selection;
use Woodev\Framework\Marketplace\Admin\Pages\Products\State_Groups;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;

require_once dirname( __DIR__, 3 ) . '/Shipping/Rest_Api/wp-rest-controller-stub.php';

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

	public function test_the_definition_is_accepted_by_the_engine_and_declares_the_twelve_actions(): void {
		$definition = $this->definition()->to_array();

		$this->assertSame(
			[ 'export', 'link_by_article', 'send_stock', 'send_price', 'sync_stock_on', 'sync_stock_off', 'sync_price_on', 'sync_price_off', 'set_warehouse', 'archive', 'detach', 'reset' ],
			array_column( $definition['bulk_actions'], 'id' )
		);
		$this->assertSame( 'account', $definition['bulk_actions'][1]['scope'], 'Linking by article names its reach.' );
		$this->assertNotSame( '', $definition['bulk_actions'][1]['scope_sentence'] );
		$this->assertSame( [ [ 'value' => (string) $this->account_id, 'label' => 'Основной' ] ], $definition['context']['options'] );
		$this->assertNotNull( $definition['children'] );
		$this->assertNull( $definition['analytics'] );
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

	public function test_an_adapter_that_cannot_import_gets_no_export_and_no_linking(): void {
		$this->withheld = [ Site_Products_Bulk::EXPORT, Site_Products_Bulk::LINK_BY_ARTICLE ];

		$ids = array_column( $this->definition()->to_array()['bulk_actions'], 'id' );

		$this->assertCount( 10, $ids );
		$this->assertNotContains( 'export', $ids );
		$this->assertNotContains( 'link_by_article', $ids );
	}

	public function test_the_list_is_sorted_over_the_whole_table_and_each_row_is_account_scoped(): void {
		$body = $this->list_body( [ 'sort' => 'price', 'direction' => 'desc' ] );

		$this->assertSame( 3, $body['total'] );
		$this->assertSame( [ $this->row_id( 10 ), $this->row_id( 30 ), $this->row_id( 20 ) ], array_column( $body['rows'], 'id' ) );
		$this->assertStringContainsString( '3', $body['messages']['summary'] );
	}

	public function test_a_variable_product_is_a_parent_row_that_sums_its_variations(): void {
		$rows   = $this->by_id( $this->list_body() );
		$parent = $rows[ $this->row_id( 20 ) ];

		$this->assertTrue( $parent['has_children'] );
		$this->assertSame( 'Кроссовки', $parent['cells']['name'] );
		$this->assertSame( 'На сайте: 7', $parent['cells']['stock'][0]['value'], 'Three plus four.' );
		$this->assertSame( 'На сайте: 200 – 250', $parent['cells']['price'][0]['value'] );
		$this->assertSame( 'Ошибка у 1 варианта', $parent['cells']['error'] );
		$this->assertSame( [ 'Ошибка', 'Не связан' ], array_column( array_column( $parent['cells']['state'], 'value' ), 'label' ) );
		$this->assertSame( 'https://example.test/wp-admin/post.php?post=20&action=edit', $parent['actions'][0]['url'] );
	}

	public function test_a_simple_product_shows_its_state_article_and_marketplace_stock(): void {
		$this->shop->readback( (int) $this->link_of( 10 )->link_id(), 9, 2 );

		$row = $this->by_id( $this->list_body() )[ $this->row_id( 10 ) ];

		$this->assertFalse( $row['has_children'] );
		$this->assertSame( 'Создан', $row['cells']['state'][0]['value']['label'] );
		$this->assertSame( 'success', $row['cells']['state'][0]['value']['tone'] );
		$this->assertSame( [ 'P-10', 'OZ-10' ], array_column( $row['cells']['sku'], 'value' ) );
		$this->assertSame( [ 'На сайте: 5', 'Тестмаркет: 7' ], array_column( $row['cells']['stock'], 'value' ) );
		$this->assertSame( 'По умолчанию', $row['cells']['warehouse'] );
		$this->assertSame( 'Остатки: да · Цены: да', $row['cells']['sync'] );
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
		$this->assertSame( 'Не заполнен бренд', $body['rows'][0]['cells']['error'] );
		$this->assertSame( 'Ошибка', $body['rows'][0]['cells']['state'][0]['value']['label'] );
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
		$this->assertNull( $row['cells']['warehouse'] );
	}

	public function test_the_table_id_is_the_page_table(): void {
		$this->assertSame( 'products', $this->provider()->get_id() );
		$this->assertSame( Sqlite_Tables::MARKETPLACE_ID, 'fake' );
	}
}
