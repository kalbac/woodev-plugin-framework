<?php
namespace Woodev\Tests\Unit\Admin\Records;

use Woodev\Framework\Admin\Records\{Records_Definition, Row_Normalizer};
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Records_Fixtures.php';

class RowNormalizerTest extends TestCase {

	/** @var Records_Definition */
	private $definition;

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( $v ) ) );
		$this->definition = Records_Definition::from_array( Records_Definitions::standard() );
	}

	private function page( array $rows, int $per_page = 20, array $extra = [] ) {
		return Row_Normalizer::page( $this->definition, array_merge( [ 'rows' => $rows, 'total' => count( $rows ) ], $extra ), $per_page );
	}

	private function assertContractBroken( $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_records_invalid_provider_response', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_a_valid_row_is_rebuilt_with_every_declared_column(): void {
		$page = $this->page( [ Records_Definitions::row( 'p:1' ) ] );

		$row = $page['rows'][0];
		$this->assertSame( 'p:1', $row['id'] );
		$this->assertSame( [ 'name', 'price', 'status', 'card', 'actions' ], array_keys( $row['cells'] ) );
		$this->assertNull( $row['cells']['actions'] );
		$this->assertSame( [ 'label' => 'Готово', 'tone' => 'success' ], $row['cells']['status'] );
		$this->assertSame( [ [ 'id' => 'open', 'enabled' => true, 'reason' => '' ] ], $row['actions'] );
		$this->assertSame( 1, $page['total'] );
	}

	public function test_keys_outside_the_row_shape_never_reach_the_response(): void {
		$row = Records_Definitions::row( 'p:1', [ 'claim_token' => 'secret-token', 'api_key' => 'k' ] );

		$out = $this->page( [ $row ] )['rows'][0];

		$this->assertSame( [ 'id', 'revision', 'cells', 'actions', 'selectable', 'has_children', 'activity' ], array_keys( $out ) );
		$this->assertStringNotContainsString( 'secret-token', json_encode( $out ) );
	}

	public function test_markup_in_a_text_cell_is_stripped(): void {
		$row = Records_Definitions::row( 'p:1' );
		$row['cells']['name'] = '<img src=x onerror=alert(1)>Чайник';

		$this->assertSame( 'Чайник', $this->page( [ $row ] )['rows'][0]['cells']['name'] );
	}

	/**
	 * @dataProvider unsafe_urls
	 */
	public function test_a_link_cell_url_is_allowlisted( string $url ): void {
		$row = Records_Definitions::row( 'p:1' );
		$row['cells']['card'] = [ 'label' => 'Открыть', 'url' => $url ];

		$this->assertContractBroken( $this->page( [ $row ] ) );
	}

	/** @return array<string,array{0:string}> */
	public function unsafe_urls(): array {
		return [
			'javascript scheme'      => [ 'javascript:alert(1)' ],
			'mixed-case javascript'  => [ 'JaVaScRiPt:alert(1)' ],
			'data scheme'            => [ 'data:text/html;base64,PHNjcmlwdD4=' ],
			'protocol-relative'      => [ '//evil.test/x' ],
			'relative without slash' => [ 'admin.php?page=x' ],
			'backslash trick'        => [ '/\\evil.test' ],
			'whitespace inside'      => [ 'https://example.test/a b' ],
			'newline smuggling'      => [ "https://example.test/\njavascript:x" ],
			'ftp'                    => [ 'ftp://example.test/x' ],
			'empty'                  => [ '' ],
		];
	}

	public function test_http_https_and_root_relative_urls_pass(): void {
		foreach ( [ 'https://example.test/a?b=1#c', 'http://example.test/', '/wp-admin/admin.php?page=x' ] as $url ) {
			$this->assertSame( $url, Row_Normalizer::safe_url( $url ) );
		}
	}

	/**
	 * @dataProvider broken_rows
	 *
	 * @param array<string,mixed> $overrides Row overrides that break the contract.
	 */
	public function test_a_provider_that_breaks_the_row_contract_fails_the_whole_page( array $overrides ): void {
		$this->assertContractBroken( $this->page( [ Records_Definitions::row( 'p:1', $overrides ) ] ) );
	}

	/** @return array<string,array{0:array<string,mixed>}> */
	public function broken_rows(): array {
		return [
			'id with a space'                  => [ [ 'id' => 'p 1' ] ],
			'cell for an undeclared column'    => [ [ 'cells' => [ 'name' => 'x', 'weight' => 5 ] ] ],
			'number cell holding text'         => [ [ 'cells' => [ 'price' => 'cheap' ] ] ],
			'number cell that is infinite'     => [ [ 'cells' => [ 'price' => INF ] ] ],
			'badge with an unknown tone'       => [ [ 'cells' => [ 'status' => [ 'label' => 'x', 'tone' => 'rainbow' ] ] ] ],
			'text cell holding a map'          => [ [ 'cells' => [ 'name' => [ 'x' => 1 ] ] ] ],
			'actions cell with a value'        => [ [ 'cells' => [ 'actions' => 'edit' ] ] ],
			'action that is not declared'      => [ [ 'actions' => [ [ 'id' => 'delete_all', 'enabled' => true ] ] ] ],
			'action declared only as bulk'     => [ [ 'actions' => [ [ 'id' => 'sync_all', 'enabled' => true ] ] ] ],
			'disabled action with no reason'   => [ [ 'actions' => [ [ 'id' => 'sync', 'enabled' => false, 'reason' => '' ] ] ] ],
			'url on an action that is not a link' => [ [ 'actions' => [ [ 'id' => 'sync', 'enabled' => true, 'url' => '/wp-admin/x' ] ] ] ],
			'script url on a link action'      => [ [ 'actions' => [ [ 'id' => 'open', 'enabled' => true, 'url' => 'javascript:alert(1)' ] ] ] ],
			'url that is not text'             => [ [ 'actions' => [ [ 'id' => 'open', 'enabled' => true, 'url' => [ '/x' ] ] ] ] ],
			'action listed twice'              => [ [ 'actions' => [ [ 'id' => 'open', 'enabled' => true ], [ 'id' => 'open', 'enabled' => true ] ] ] ],
			'selectable that is not boolean'   => [ [ 'selectable' => 'yes' ] ],
			'revision with markup'             => [ [ 'revision' => '<x>' ] ],
		];
	}

	public function test_a_disabled_action_keeps_its_reason(): void {
		$row = Records_Definitions::row( 'p:1', [ 'actions' => [ [ 'id' => 'sync', 'enabled' => false, 'reason' => 'Нет склада' ] ] ] );

		$this->assertSame( [ [ 'id' => 'sync', 'enabled' => false, 'reason' => 'Нет склада' ] ], $this->page( [ $row ] )['rows'][0]['actions'] );
	}

	public function test_a_link_action_carries_the_destination_of_its_row(): void {
		$row = Records_Definitions::row( 'p:1', [ 'actions' => [ [ 'id' => 'open', 'enabled' => true, 'url' => 'https://example.test/card/p:1' ] ] ] );

		$this->assertSame(
			[ [ 'id' => 'open', 'enabled' => true, 'reason' => '', 'url' => 'https://example.test/card/p:1' ] ],
			$this->page( [ $row ] )['rows'][0]['actions']
		);
	}

	public function test_more_rows_than_the_requested_page_size_is_a_contract_break(): void {
		$rows = [ Records_Definitions::row( 'p:1' ), Records_Definitions::row( 'p:2' ), Records_Definitions::row( 'p:3' ) ];

		$this->assertContractBroken( $this->page( $rows, 2 ) );
	}

	public function test_duplicate_row_ids_and_a_missing_total_are_contract_breaks(): void {
		$this->assertContractBroken( $this->page( [ Records_Definitions::row( 'p:1' ), Records_Definitions::row( 'p:1' ) ] ) );
		$this->assertContractBroken( Row_Normalizer::page( $this->definition, [ 'rows' => [] ], 20 ) );
		$this->assertContractBroken( Row_Normalizer::page( $this->definition, [ 'rows' => [], 'total' => -1 ], 20 ) );
	}

	public function test_has_children_needs_a_declared_children_contract(): void {
		$flat = Records_Definition::from_array( Records_Definitions::standard( [ 'children' => null ] ) );
		$row  = Records_Definitions::row( 'p:1', [ 'has_children' => true ] );

		$this->assertContractBroken( Row_Normalizer::page( $flat, [ 'rows' => [ $row ], 'total' => 1 ], 20 ) );
		$this->assertTrue( $this->page( [ $row ] )['rows'][0]['has_children'] );
	}

	public function test_a_child_row_cannot_expand_again(): void {
		$result = [ 'rows' => [ Records_Definitions::row( 'v:1', [ 'has_children' => true ] ) ], 'total' => 1 ];

		$this->assertContractBroken( Row_Normalizer::children_page( $this->definition, $result, 10 ) );
	}

	public function test_facets_must_be_keyed_by_declared_filters(): void {
		$ok = $this->page( [], 20, [ 'facets' => [ 'state' => [ [ 'value' => 'ok', 'label' => 'Готово', 'count' => 3 ] ] ] ] );
		$this->assertSame( 3, $ok['facets']['state'][0]['count'] );

		$this->assertContractBroken( $this->page( [], 20, [ 'facets' => [ 'ghost' => [] ] ] ) );
		$this->assertContractBroken( $this->page( [], 20, [ 'facets' => [ 'state' => [ [ 'value' => 'ok', 'label' => 'x', 'count' => -1 ] ] ] ] ) );
	}

	public function test_cell_types_stack_progress_date_and_number(): void {
		$definition = Records_Definition::from_array(
			Records_Definitions::standard(
				[
					'columns' => [
						[ 'id' => 'stack', 'label' => 'S', 'cell_type' => 'stack' ],
						[ 'id' => 'progress', 'label' => 'P', 'cell_type' => 'progress' ],
						[ 'id' => 'date', 'label' => 'D', 'cell_type' => 'date' ],
						[ 'id' => 'num', 'label' => 'N', 'cell_type' => 'number' ],
					],
					'sorts'   => [ 'keys' => [] ],
				]
			)
		);
		$row = [
			'id'    => 'r1',
			'cells' => [
				'stack'    => [ [ 'type' => 'text', 'value' => 'a' ], [ 'type' => 'badge', 'value' => [ 'label' => 'b', 'tone' => 'info' ] ] ],
				'progress' => [ 'completed' => 3, 'total' => null, 'percent' => null, 'text' => 'идёт' ],
				'date'     => '2026-11-10T12:30:00Z',
				'num'      => 12.5,
			],
		];

		$out = Row_Normalizer::page( $definition, [ 'rows' => [ $row ], 'total' => 1 ], 20 )['rows'][0]['cells'];

		$this->assertSame( 'a', $out['stack'][0]['value'] );
		$this->assertSame( [ 'completed' => 3, 'total' => null, 'percent' => null, 'text' => 'идёт' ], $out['progress'] );
		$this->assertSame( '2026-11-10T12:30:00Z', $out['date'] );
		$this->assertSame( 12.5, $out['num'] );

		foreach ( [ [ 'stack', [ [ 'type' => 'stack', 'value' => [] ] ] ], [ 'progress', [ 'percent' => 150 ] ], [ 'date', 'вчера' ], [ 'stack', [] ] ] as [ $column, $value ] ) {
			$bad = $row;
			$bad['cells'][ $column ] = $value;
			$this->assertContractBroken( Row_Normalizer::page( $definition, [ 'rows' => [ $bad ], 'total' => 1 ], 20 ) );
		}
	}

	public function test_options_page_is_bounded_and_typed(): void {
		$ok = Row_Normalizer::options_page( [ 'options' => [ [ 'value' => 'w1', 'label' => 'Склад' ] ], 'next_cursor' => 'c2' ] );
		$this->assertSame( [ 'options' => [ [ 'value' => 'w1', 'label' => 'Склад' ] ], 'next_cursor' => 'c2' ], $ok );

		$this->assertContractBroken( Row_Normalizer::options_page( [ 'options' => [ [ 'value' => 'a b', 'label' => 'x' ] ] ] ) );
		$this->assertContractBroken( Row_Normalizer::options_page( [ 'options' => array_fill( 0, 101, [ 'value' => 'a', 'label' => 'x' ] ) ] ) );
		$this->assertContractBroken( Row_Normalizer::options_page( [ 'options' => [], 'next_cursor' => 'bad cursor' ] ) );
	}
}

