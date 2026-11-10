<?php
namespace Woodev\Tests\Unit\Admin\Records;

use Woodev\Framework\Admin\Records\{Query_Validator, Records_Definition};
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Records_Fixtures.php';

class QueryValidatorTest extends TestCase {

	/** @var Records_Definition */
	private $definition;

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( $v ) ) );
		$this->definition = Records_Definition::from_array( Records_Definitions::standard() );
	}

	private function assertRejected( $result, string $code, string $param ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $param, $result->get_error_data()['param'] ?? null );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_an_empty_list_query_gets_every_default(): void {
		$query = Query_Validator::list_query( $this->definition, [] );

		$this->assertSame(
			[
				'page'      => 1,
				'per_page'  => 20,
				'search'    => '',
				'sort'      => 'name',
				'direction' => 'asc',
				'filters'   => [],
			],
			$query
		);
	}

	public function test_a_full_valid_query_is_typed(): void {
		$query = Query_Validator::list_query(
			$this->definition,
			[
				'page'      => '3',
				'per_page'  => '50',
				'search'    => '  чайник ',
				'sort'      => 'price',
				'direction' => 'desc',
				'filters'   => [
					'state'    => 'wait',
					'tags'     => [ 't1', 't2' ],
					'in_stock' => 'false',
					'created'  => [ 'from' => '2026-01-01', 'to' => '2026-02-01' ],
				],
			]
		);

		$this->assertSame( 3, $query['page'] );
		$this->assertSame( 50, $query['per_page'] );
		$this->assertSame( 'чайник', $query['search'] );
		$this->assertSame( 'price', $query['sort'] );
		$this->assertSame( 'desc', $query['direction'] );
		$this->assertSame( 'wait', $query['filters']['state'] );
		$this->assertSame( [ 't1', 't2' ], $query['filters']['tags'] );
		$this->assertFalse( $query['filters']['in_stock'], 'an explicit false is a filter, not an omission' );
		$this->assertSame( [ 'from' => '2026-01-01', 'to' => '2026-02-01' ], $query['filters']['created'] );
	}

	public function test_an_omitted_boolean_filter_is_absent_not_false(): void {
		$query = Query_Validator::list_query( $this->definition, [ 'filters' => [ 'state' => 'ok' ] ] );

		$this->assertArrayNotHasKey( 'in_stock', $query['filters'] );
	}

	public function test_direction_alone_keeps_the_default_sort_key(): void {
		$query = Query_Validator::list_query( $this->definition, [ 'direction' => 'desc' ] );

		$this->assertSame( [ 'name', 'desc' ], [ $query['sort'], $query['direction'] ] );
	}

	/**
	 * @dataProvider invalid_queries
	 *
	 * @param array<string,mixed> $params Params that must be refused.
	 * @param string              $param  Offending parameter named in the error.
	 */
	public function test_invalid_filters_sorts_and_paging_are_rejected( array $params, string $param ): void {
		$this->assertRejected( Query_Validator::list_query( $this->definition, $params ), 'woodev_records_invalid_param', $param );
	}

	/** @return array<string,array{0:array<string,mixed>,1:string}> */
	public function invalid_queries(): array {
		return [
			'sort key outside the whitelist'    => [ [ 'sort' => 'status' ], 'sort' ],
			'sort key with SQL'                 => [ [ 'sort' => 'name; DROP TABLE x' ], 'sort' ],
			'sort as an array'                  => [ [ 'sort' => [ 'name' ] ], 'sort' ],
			'direction outside asc/desc'        => [ [ 'direction' => 'sideways' ], 'direction' ],
			'page zero'                         => [ [ 'page' => '0' ], 'page' ],
			'page negative'                     => [ [ 'page' => '-1' ], 'page' ],
			'page not a number'                 => [ [ 'page' => '1e3' ], 'page' ],
			'page beyond the ceiling'           => [ [ 'page' => '100001' ], 'page' ],
			'per_page not among the options'    => [ [ 'per_page' => '15' ], 'per_page' ],
			'per_page zero'                     => [ [ 'per_page' => '0' ], 'per_page' ],
			'unknown filter'                    => [ [ 'filters' => [ 'color' => 'red' ] ], 'filters.color' ],
			'search filter sent as a filter'    => [ [ 'filters' => [ 'q' => 'x' ] ], 'filters.q' ],
			'filters as a list'                 => [ [ 'filters' => [ 'ok' ] ], 'filters' ],
			'select value not an option'        => [ [ 'filters' => [ 'state' => 'gone' ] ], 'filters.state' ],
			'select value as an array'          => [ [ 'filters' => [ 'state' => [ 'ok' ] ] ], 'filters.state' ],
			'multi value duplicated'            => [ [ 'filters' => [ 'tags' => [ 'a', 'a' ] ] ], 'filters.tags' ],
			'multi value not a list'            => [ [ 'filters' => [ 'tags' => 'a' ] ], 'filters.tags' ],
			'multi value with a bad token'      => [ [ 'filters' => [ 'tags' => [ 'a b' ] ] ], 'filters.tags' ],
			'multi value over the limit'        => [ [ 'filters' => [ 'tags' => array_map( 'strval', range( 1, 51 ) ) ] ], 'filters.tags' ],
			'boolean that is a word'            => [ [ 'filters' => [ 'in_stock' => 'yes' ] ], 'filters.in_stock' ],
			'date that does not exist'          => [ [ 'filters' => [ 'created' => [ 'from' => '2026-02-30' ] ] ], 'filters.created' ],
			'date with a NUL byte'              => [ [ 'filters' => [ 'created' => [ 'from' => "2026-01-01\0" ] ] ], 'filters.created' ],
			'date with a trailing newline'      => [ [ 'filters' => [ 'created' => [ 'to' => "2026-01-01\n" ] ] ], 'filters.created' ],
			'date without zero padding'         => [ [ 'filters' => [ 'created' => [ 'from' => '2026-1-1' ] ] ], 'filters.created' ],
			'date followed by a time'           => [ [ 'filters' => [ 'created' => [ 'from' => '2026-01-01 10:00' ] ] ], 'filters.created' ],
			'page with a trailing newline'      => [ [ 'page' => "2\n" ], 'page' ],
			'select token with a newline'       => [ [ 'filters' => [ 'tags' => [ "t1\n" ] ] ], 'filters.tags' ],
			'date range reversed'               => [ [ 'filters' => [ 'created' => [ 'from' => '2026-03-01', 'to' => '2026-01-01' ] ] ], 'filters.created' ],
			'date range with a stray key'       => [ [ 'filters' => [ 'created' => [ 'since' => '2026-01-01' ] ] ], 'filters.created' ],
			'search longer than the limit'      => [ [ 'search' => str_repeat( 'я', 201 ) ], 'search' ],
			'search with a control character'   => [ [ 'search' => "a\x00b" ], 'search' ],
			'search as an array'                => [ [ 'search' => [ 'x' ] ], 'search' ],
		];
	}

	public function test_search_is_refused_by_a_table_without_a_search_filter(): void {
		$definition = Records_Definition::from_array( Records_Definitions::standard( [ 'filters' => [] ] ) );

		$this->assertRejected( Query_Validator::list_query( $definition, [ 'search' => 'x' ] ), 'woodev_records_invalid_param', 'search' );
	}

	public function test_a_table_that_is_not_sortable_refuses_sort_and_direction(): void {
		$definition = Records_Definition::from_array(
			Records_Definitions::standard( [ 'sorts' => [ 'keys' => [] ], 'columns' => [ [ 'id' => 'name', 'label' => 'N', 'cell_type' => 'text' ] ] ] )
		);

		$this->assertRejected( Query_Validator::list_query( $definition, [ 'sort' => 'name' ] ), 'woodev_records_invalid_param', 'sort' );
		$this->assertRejected( Query_Validator::list_query( $definition, [ 'direction' => 'asc' ] ), 'woodev_records_invalid_param', 'direction' );
		$this->assertSame( '', Query_Validator::list_query( $definition, [] )['sort'] );
	}

	public function test_an_oversized_page_is_rejected_with_its_own_code(): void {
		$this->assertRejected( Query_Validator::list_query( $this->definition, [ 'per_page' => '51' ] ), 'woodev_records_per_page_too_large', 'per_page' );
		$this->assertRejected( Query_Validator::list_query( $this->definition, [ 'per_page' => '100000' ] ), 'woodev_records_per_page_too_large', 'per_page' );
	}

	public function test_children_query_has_its_own_bounds_and_needs_a_children_contract(): void {
		$this->assertSame( [ 'page' => 1, 'per_page' => 10 ], Query_Validator::children_query( $this->definition, [] ) );
		$this->assertRejected( Query_Validator::children_query( $this->definition, [ 'per_page' => '21' ] ), 'woodev_records_per_page_too_large', 'per_page' );

		$flat = Records_Definition::from_array( Records_Definitions::standard( [ 'children' => null ] ) );
		$this->assertSame( 'woodev_records_not_found', Query_Validator::children_query( $flat, [] )->get_error_code() );
	}

	public function test_row_ids_must_be_a_flat_distinct_bounded_list_of_tokens(): void {
		$this->assertSame( [ 'p:1:a1', 'p:2:a1' ], Query_Validator::row_ids( $this->definition, [ 'p:1:a1', 'p:2:a1' ] ) );

		$this->assertRejected( Query_Validator::row_ids( $this->definition, [] ), 'woodev_records_invalid_param', 'ids' );
		$this->assertRejected( Query_Validator::row_ids( $this->definition, 'p:1' ), 'woodev_records_invalid_param', 'ids' );
		$this->assertRejected( Query_Validator::row_ids( $this->definition, [ 'a' => 'p:1' ] ), 'woodev_records_invalid_param', 'ids' );
		$this->assertRejected( Query_Validator::row_ids( $this->definition, [ [ 'p:1' ] ] ), 'woodev_records_invalid_param', 'ids' );
		$this->assertRejected( Query_Validator::row_ids( $this->definition, [ 'p:1', 'p:1' ] ), 'woodev_records_invalid_param', 'ids' );
		$this->assertRejected( Query_Validator::row_ids( $this->definition, [ 'p 1' ] ), 'woodev_records_invalid_param', 'ids' );
		$this->assertRejected( Query_Validator::row_ids( $this->definition, [ 1 ] ), 'woodev_records_invalid_param', 'ids' );
	}

	public function test_a_selection_over_the_declared_maximum_is_rejected_not_truncated(): void {
		$ids = array_map( static fn( $n ) => 'p:' . $n, range( 1, 101 ) );

		$this->assertRejected( Query_Validator::row_ids( $this->definition, $ids ), 'woodev_records_selection_too_large', 'ids' );
		$this->assertIsArray( Query_Validator::row_ids( $this->definition, array_slice( $ids, 0, 100 ) ) );
	}

	public function test_unknown_query_parameters_are_refused_but_wordpress_own_ones_pass(): void {
		$this->assertNull( Query_Validator::reject_unknown( [ 'page' => 1, '_locale' => 'user', '_wpnonce' => 'x' ], [ 'page' ] ) );
		$this->assertRejected( Query_Validator::reject_unknown( [ 'page' => 1, 'owner' => 'other' ], [ 'page' ] ), 'woodev_records_invalid_param', 'owner' );
	}

	public function test_requested_context_shape(): void {
		$this->assertSame( [ 'account_id' => '' ], Query_Validator::requested_context( null ) );
		$this->assertSame( [ 'account_id' => 'a1' ], Query_Validator::requested_context( [ 'account_id' => 'a1' ] ) );
		$this->assertRejected( Query_Validator::requested_context( 'a1' ), 'woodev_records_invalid_param', 'context' );
		$this->assertRejected( Query_Validator::requested_context( [ 'account_id' => 'a1', 'user' => '1' ] ), 'woodev_records_invalid_param', 'context' );
		$this->assertRejected( Query_Validator::requested_context( [ 'account_id' => [ 'a1' ] ] ), 'woodev_records_invalid_param', 'context.account_id' );
		$this->assertRejected( Query_Validator::requested_context( [ 'account_id' => "a1'--" ] ), 'woodev_records_invalid_param', 'context.account_id' );
	}

	public function test_a_forged_account_resolves_to_not_found_and_an_omitted_one_to_the_default(): void {
		$this->assertSame( [ 'account_id' => 'a2' ], Query_Validator::resolve_context( $this->definition, [ 'account_id' => 'a2' ] ) );
		$this->assertSame( [ 'account_id' => 'a1' ], Query_Validator::resolve_context( $this->definition, [ 'account_id' => '' ] ) );

		$forged = Query_Validator::resolve_context( $this->definition, [ 'account_id' => 'a999' ] );
		$this->assertSame( 'woodev_records_not_found', $forged->get_error_code() );
		$this->assertSame( 404, $forged->get_error_data()['status'] );
	}

	public function test_a_table_without_an_account_selector_refuses_an_account(): void {
		$definition = Records_Definition::from_array( Records_Definitions::standard( [ 'context' => null ] ) );

		$this->assertSame( [ 'account_id' => '' ], Query_Validator::resolve_context( $definition, [ 'account_id' => '' ] ) );
		$this->assertRejected( Query_Validator::resolve_context( $definition, [ 'account_id' => 'a1' ] ), 'woodev_records_invalid_param', 'context.account_id' );
	}
}
