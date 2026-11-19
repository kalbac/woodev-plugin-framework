<?php
namespace Woodev\Tests\Unit\Admin\Records;

use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Records_Fixtures.php';

class RecordsDefinitionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( $v ) ) );
	}

	public function test_standard_definition_is_accepted_and_normalised(): void {
		$definition = Records_Definition::from_array( Records_Definitions::standard() );

		$this->assertSame( [ 'name', 'price', 'status', 'card', 'actions' ], $definition->get_column_ids() );
		$this->assertSame( 'account_id', $definition->get_context()['id'] );
		$this->assertSame( [ 'key' => 'name', 'direction' => 'asc' ], $definition->get_default_sort() );
		$this->assertNull( $definition->to_array()['analytics'] );
		$this->assertSame( [ 'asc', 'desc' ], $definition->to_array()['sorts']['directions'] );
		$this->assertSame( 'left', $definition->get_column( 'name' )['align'] );
		$this->assertSame( 'sync', $definition->get_action( 'sync' )['id'] );
		$this->assertSame( 'sync_all', $definition->get_action( 'sync_all' )['id'] );
		$this->assertSame( 'warehouse', $definition->get_action_field( 'sync', 'warehouse' )['id'] );
	}

	/**
	 * @dataProvider invalid_definitions
	 *
	 * @param array<string,mixed> $overrides Overrides that must be refused.
	 */
	public function test_anything_outside_the_vocabularies_is_refused( array $overrides ): void {
		$this->expectException( \InvalidArgumentException::class );

		Records_Definition::from_array( Records_Definitions::standard( $overrides ) );
	}

	/** @return array<string,array{0:array<string,mixed>}> */
	public function invalid_definitions(): array {
		$columns = Records_Definitions::standard()['columns'];
		$filters = Records_Definitions::standard()['filters'];
		$actions = Records_Definitions::standard()['row_actions'];

		return [
			'unknown top-level key'            => [ [ 'html' => '<b>x</b>' ] ],
			'analytics is a reserved slot'     => [ [ 'analytics' => [ 'report' => 'x' ] ] ],
			'unknown cell type'                => [ [ 'columns' => [ array_merge( $columns[0], [ 'cell_type' => 'html' ] ) ] ] ],
			'callable cell renderer is a key'  => [ [ 'columns' => [ array_merge( $columns[0], [ 'render' => 'strtoupper' ] ) ] ] ],
			'duplicate column ids'             => [ [ 'columns' => [ $columns[0], $columns[0] ] ] ],
			'sort key that is not declared'    => [ [ 'columns' => [ array_merge( $columns[0], [ 'sort_key' => 'weight' ] ) ] ] ],
			'no columns'                       => [ [ 'columns' => [] ] ],
			'unknown filter type'              => [ [ 'filters' => [ [ 'id' => 'x', 'label' => 'X', 'type' => 'regex' ] ] ] ],
			'select filter without options'    => [ [ 'filters' => [ [ 'id' => 'x', 'label' => 'X', 'type' => 'select' ] ] ] ],
			'select filter with both sources'  => [ [ 'filters' => [ array_merge( $filters[1], [ 'options_source' => true ] ) ] ] ],
			'boolean filter with options'      => [ [ 'filters' => [ [ 'id' => 'x', 'label' => 'X', 'type' => 'boolean', 'options' => [ [ 'value' => 'a', 'label' => 'A' ] ] ] ] ] ],
			'filter default outside options'   => [ [ 'filters' => [ array_merge( $filters[1], [ 'default' => 'nope' ] ) ] ] ],
			'duplicate option values'          => [ [ 'filters' => [ array_merge( $filters[1], [ 'options' => [ [ 'value' => 'a', 'label' => 'A' ], [ 'value' => 'a', 'label' => 'B' ] ] ] ) ] ] ],
			'views on a multi_select'          => [ [ 'filters' => [ array_merge( $filters[2], [ 'views' => true ] ) ] ] ],
			'views on a boolean'               => [ [ 'filters' => [ array_merge( $filters[3], [ 'views' => true ] ) ] ] ],
			'views on a search'                => [ [ 'filters' => [ array_merge( $filters[0], [ 'views' => true ] ) ] ] ],
			'views that is not a flag'         => [ [ 'filters' => [ array_merge( $filters[1], [ 'views' => 'yes' ] ) ] ] ],
			'two views filters'                => [ [ 'filters' => [ array_merge( $filters[1], [ 'views' => true ] ), array_merge( $filters[1], [ 'id' => 'other', 'views' => true ] ) ] ] ],
			'today on a select'                => [ [ 'filters' => [ array_merge( $filters[1], [ 'today' => '2026-11-15' ] ) ] ] ],
			'today that is not a day'          => [ [ 'filters' => [ array_merge( $filters[4], [ 'today' => '2026-02-30' ] ) ] ] ],
			'today that is not a string'       => [ [ 'filters' => [ array_merge( $filters[4], [ 'today' => 20261115 ] ) ] ] ],
			'sort default is not a sort key'   => [ [ 'sorts' => [ 'keys' => [ 'name' ], 'default' => [ 'key' => 'price', 'direction' => 'asc' ] ] ] ],
			'sort keys without default'        => [ [ 'sorts' => [ 'keys' => [ 'name' ] ] ] ],
			'per page above the ceiling'       => [ [ 'pagination' => [ 'default_per_page' => 20, 'per_page_options' => [ 20, 500 ], 'max_per_page' => 500, 'max_selection' => 10 ] ] ],
			'default per page not an option'   => [ [ 'pagination' => [ 'default_per_page' => 15, 'per_page_options' => [ 10, 20 ], 'max_per_page' => 20, 'max_selection' => 10 ] ] ],
			'option above max per page'        => [ [ 'pagination' => [ 'default_per_page' => 10, 'per_page_options' => [ 10, 100 ], 'max_per_page' => 50, 'max_selection' => 10 ] ] ],
			'selection above the ceiling'      => [ [ 'pagination' => [ 'default_per_page' => 10, 'per_page_options' => [ 10 ], 'max_per_page' => 10, 'max_selection' => 5000 ] ] ],
			'unknown effect'                   => [ [ 'row_actions' => [ array_merge( $actions[0], [ 'effect' => 'delete' ] ) ] ] ],
			'unknown interaction'              => [ [ 'row_actions' => [ array_merge( $actions[0], [ 'interaction' => 'eval' ] ) ] ] ],
			'account action without sentence'  => [ [ 'bulk_actions' => [ [ 'id' => 'b', 'label' => 'B', 'effect' => 'mutate', 'interaction' => 'dialog', 'scope' => 'account' ] ] ] ],
			'mutating bulk action skips preview' => [ [ 'bulk_actions' => [ [ 'id' => 'b', 'label' => 'B', 'effect' => 'mutate', 'interaction' => 'submit', 'skip_preview' => true ] ] ] ],
			'destructive row action skips preview' => [ [ 'row_actions' => [ array_merge( $actions[1], [ 'tone' => 'danger', 'skip_preview' => true ] ) ] ] ],
			'row and bulk action share an id'  => [ [ 'bulk_actions' => [ array_merge( $actions[0], [ 'id' => 'open' ] ) ] ] ],
			'children page larger than max'    => [ [ 'children' => [ 'default_per_page' => 30, 'max_per_page' => 20 ] ] ],
			'activity max below refresh'       => [ [ 'activity' => [ 'refresh_ms' => 5000, 'max_refresh_ms' => 2000, 'stale_after_ms' => 9000, 'id_limit' => 50 ] ] ],
			'message that is not a string'     => [ [ 'messages' => [ 'empty' => [ 'x' ] ] ] ],
			'searchable select without a source' => [ [ 'bulk_actions' => [ self::searchable_action( [ 'options_source' => false, 'options' => [ [ 'value' => 'a', 'label' => 'A' ] ] ] ) ] ] ],
			'searchable text field'            => [ [ 'bulk_actions' => [ self::searchable_action( [ 'type' => 'text', 'options_source' => false ] ) ] ] ],
			'searchable multi select'          => [ [ 'bulk_actions' => [ self::searchable_action( [ 'type' => 'multi_select' ] ) ] ] ],
			'searchable that is not a boolean' => [ [ 'bulk_actions' => [ self::searchable_action( [ 'searchable' => 'yes' ] ) ] ] ],
			'toggle that is not a boolean'     => [ [ 'bulk_actions' => [ self::searchable_action( [ 'toggle' => true ] ) ] ] ],
			'hidden_when names no field'       => [ [ 'bulk_actions' => [ self::searchable_action( [ 'hidden_when' => 'nowhere' ] ) ] ] ],
			'hidden_when names a non-boolean'  => [ [ 'bulk_actions' => [ self::searchable_action( [ 'hidden_when' => 'product' ] ) ] ] ],
			'account default outside options'  => [ [ 'context' => [ 'label' => 'A', 'options' => [ [ 'value' => 'a1', 'label' => 'A' ] ], 'default' => 'zz' ] ] ],
		];
	}

	/**
	 * A bulk action with one field, the field being the searchable select unless overridden.
	 *
	 * @param array<string,mixed> $field Field keys to override.
	 * @return array<string,mixed>
	 */
	private static function searchable_action( array $field ): array {
		return [
			'id'          => 'link',
			'label'       => 'Связать',
			'effect'      => 'mutate',
			'interaction' => 'dialog',
			'fields'      => [ array_merge( [ 'id' => 'product', 'label' => 'Товар', 'type' => 'select', 'options_source' => true, 'searchable' => true ], $field ) ],
		];
	}

	public function test_a_select_with_a_source_can_be_searchable_and_nothing_else_is_by_default(): void {
		$definition = Records_Definition::from_array(
			Records_Definitions::standard(
				[
					'bulk_actions' => [
						self::searchable_action( [] ),
						array_merge( self::searchable_action( [ 'searchable' => false, 'id' => 'warehouse' ] ), [ 'id' => 'send' ] ),
					],
				]
			)
		);

		$this->assertTrue( $definition->get_action( 'link' )['fields'][0]['searchable'] );
		$this->assertFalse( $definition->get_action( 'send' )['fields'][0]['searchable'], 'an existing field stays what it was' );
	}

	public function test_a_boolean_can_be_a_toggle_and_hide_another_field(): void {
		$action = self::searchable_action( [ 'id' => 'category', 'hidden_when' => 'whole_shop', 'required' => true ] );
		array_unshift( $action['fields'], [ 'id' => 'whole_shop', 'label' => 'Весь магазин', 'type' => 'boolean', 'toggle' => true ] );

		$fields = Records_Definition::from_array( Records_Definitions::standard( [ 'bulk_actions' => [ $action ] ] ) )->get_action( 'link' )['fields'];

		$this->assertTrue( $fields[0]['toggle'] );
		$this->assertSame( '', $fields[0]['hidden_when'] );
		$this->assertFalse( $fields[1]['toggle'] );
		$this->assertSame( 'whole_shop', $fields[1]['hidden_when'] );
	}

	public function test_an_action_may_name_its_group_and_preview_button_and_a_field_its_placeholder(): void {
		$action = array_merge(
			self::searchable_action( [ 'placeholder' => 'Начните вводить название' ] ),
			[ 'group' => 'Связи и архив', 'preview_label' => 'Проверить связи' ]
		);

		$parsed = Records_Definition::from_array( Records_Definitions::standard( [ 'bulk_actions' => [ $action, array_merge( self::searchable_action( [] ), [ 'id' => 'plain' ] ) ] ] ) );

		$this->assertSame( 'Связи и архив', $parsed->get_action( 'link' )['group'] );
		$this->assertSame( 'Проверить связи', $parsed->get_action( 'link' )['preview_label'] );
		$this->assertSame( 'Начните вводить название', $parsed->get_action( 'link' )['fields'][0]['placeholder'] );
		$this->assertSame( '', $parsed->get_action( 'plain' )['group'], 'an action that names none is listed unheaded' );
		$this->assertSame( '', $parsed->get_action( 'plain' )['fields'][0]['placeholder'] );
	}

	public function test_a_select_can_be_the_counted_view_row_and_the_flag_is_only_sent_when_it_is_set(): void {
		$filters = Records_Definitions::standard()['filters'];
		$plain   = Records_Definition::from_array( Records_Definitions::standard() )->to_array()['filters'];

		$definition = Records_Definition::from_array(
			Records_Definitions::standard( [ 'filters' => [ $filters[0], array_merge( $filters[1], [ 'views' => true ] ), $filters[3] ] ] )
		)->to_array()['filters'];

		$this->assertTrue( $definition[1]['views'] );
		$this->assertArrayNotHasKey( 'views', $definition[0] );
		$this->assertArrayNotHasKey( 'views', $definition[2] );
		$this->assertArrayNotHasKey( 'views', $plain[1], 'a filter that does not ask for it keeps its old shape' );
	}

	public function test_a_date_range_can_carry_the_sites_current_day(): void {
		$filters = Records_Definitions::standard()['filters'];

		$definition = Records_Definition::from_array( Records_Definitions::standard( [ 'filters' => [ array_merge( $filters[4], [ 'today' => '2026-11-15' ] ), $filters[3] ] ] ) )->to_array()['filters'];

		$this->assertSame( '2026-11-15', $definition[0]['today'] );
		$this->assertArrayNotHasKey( 'today', $definition[1] );
	}

	public function test_a_skipping_row_action_is_allowed_when_the_definition_says_so(): void {
		$action = [ 'id' => 'tick', 'label' => 'Отметить', 'effect' => 'mutate', 'interaction' => 'submit', 'skip_preview' => true ];

		$definition = Records_Definition::from_array( Records_Definitions::standard( [ 'row_actions' => [ $action ], 'bulk_actions' => [] ] ) );

		$this->assertTrue( $definition->get_action( 'tick' )['skip_preview'] );
	}

	public function test_a_table_without_sorting_or_account_selector_is_valid(): void {
		$definition = Records_Definition::from_array(
			Records_Definitions::standard( [ 'context' => null, 'sorts' => [ 'keys' => [] ], 'columns' => [ [ 'id' => 'name', 'label' => 'Название', 'cell_type' => 'text' ] ] ] )
		);

		$this->assertNull( $definition->get_context() );
		$this->assertNull( $definition->get_default_sort() );
		$this->assertSame( [], $definition->get_sort_keys() );
	}

	public function test_text_in_a_definition_is_stripped_of_tags(): void {
		$definition = Records_Definition::from_array(
			Records_Definitions::standard( [ 'columns' => [ [ 'id' => 'name', 'label' => '<b>Название</b>', 'cell_type' => 'text' ] ], 'sorts' => [ 'keys' => [] ] ] )
		);

		$this->assertSame( 'Название', $definition->get_column( 'name' )['label'] );
	}
}
