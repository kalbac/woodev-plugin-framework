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
