<?php
namespace Woodev\Tests\Unit\Admin\Records;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\{Action_Response, Records_Definition};
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Action_Test_Doubles.php';

/**
 * What a provider may answer to a preview or an apply, and what the engine makes of it.
 */
class ActionResponseTest extends TestCase {

	/** @var Records_Definition */
	private $definition;

	/** @var array<string,mixed> */
	private $action;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( $v ) ) );

		$this->definition = Records_Definition::from_array( Action_Definitions::with_actions() );
		$this->action     = $this->definition->get_action( 'export' );
	}

	/**
	 * @param array<string,mixed> $result Apply answer.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function apply( array $result ) {
		return Action_Response::apply( $this->definition, $this->action, $result + [ 'requested' => 4, 'accepted' => 0, 'skipped' => 0, 'failed' => 0 ] );
	}

	private function assertContractViolation( $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_records_invalid_provider_response', $result->get_error_code() );
	}

	/**
	 * @dataProvider statuses
	 * @param array<string,mixed> $counts Counts and flags.
	 */
	public function test_the_status_follows_from_the_counts( array $counts, string $status ): void {
		$this->assertSame( $status, $this->apply( $counts )['status'] );
	}

	/** @return array<string,array{0:array<string,mixed>,1:string}> */
	public function statuses(): array {
		return [
			'all accepted'                 => [ [ 'accepted' => 4 ], 'done' ],
			'nothing requested nothing done' => [ [ 'requested' => 0 ], 'done' ],
			'some skipped'                 => [ [ 'accepted' => 3, 'skipped' => 1 ], 'partial' ],
			'some failed'                  => [ [ 'accepted' => 2, 'failed' => 2 ], 'partial' ],
			'all skipped'                  => [ [ 'skipped' => 4 ], 'failed' ],
			'queued'                       => [ [ 'accepted' => 4, 'queued' => true ], 'queued' ],
			'queued with a skip'           => [ [ 'accepted' => 3, 'skipped' => 1, 'queued' => true ], 'queued' ],
		];
	}

	/**
	 * @dataProvider broken_counts
	 * @param array<string,mixed> $result Answer that breaks the contract.
	 */
	public function test_counts_must_be_whole_and_add_up( array $result ): void {
		$this->assertContractViolation( Action_Response::apply( $this->definition, $this->action, $result ) );
	}

	/** @return array<string,array{0:array<string,mixed>}> */
	public function broken_counts(): array {
		$ok = [ 'requested' => 4, 'accepted' => 2, 'skipped' => 1, 'failed' => 1 ];

		return [
			'missing'        => [ array_diff_key( $ok, [ 'failed' => 1 ] ) ],
			'negative'       => [ [ 'skipped' => -1 ] + $ok ],
			'a float'        => [ [ 'accepted' => 2.0 ] + $ok ],
			'a numeric text' => [ [ 'accepted' => '2' ] + $ok ],
			'over requested' => [ [ 'accepted' => 3 ] + $ok ],
		];
	}

	/**
	 * @dataProvider broken_groups
	 * @param mixed $groups Groups that break the contract.
	 */
	public function test_a_broken_group_is_a_contract_violation( $groups ): void {
		$this->assertContractViolation( $this->apply( [ 'skipped' => 1, 'groups' => $groups ] ) );
	}

	/** @return array<string,array{0:mixed}> */
	public function broken_groups(): array {
		return [
			'not a list'         => [ [ 'reason' => 'x', 'count' => 1 ] ],
			'no reason'          => [ [ [ 'count' => 1 ] ] ],
			'empty reason'       => [ [ [ 'reason' => ' ', 'count' => 1 ] ] ],
			'zero count'         => [ [ [ 'reason' => 'x', 'count' => 0 ] ] ],
			'string count'       => [ [ [ 'reason' => 'x', 'count' => '1' ] ] ],
			'unknown kind'       => [ [ [ 'reason' => 'x', 'count' => 1, 'kind' => 'error' ] ] ],
			'script fix url'     => [ [ [ 'reason' => 'x', 'count' => 1, 'fix_label' => 'Fix', 'fix_url' => 'javascript:alert(1)' ] ] ],
			'url without label'  => [ [ [ 'reason' => 'x', 'count' => 1, 'fix_url' => '/wp-admin/' ] ] ],
			'too many examples'  => [ [ [ 'reason' => 'x', 'count' => 1, 'examples' => [ 'a', 'b', 'c', 'd', 'e', 'f' ] ] ] ],
			'too many groups'    => [ array_fill( 0, 51, [ 'reason' => 'x', 'count' => 1 ] ) ],
		];
	}

	public function test_group_text_is_plain_and_unknown_keys_are_dropped(): void {
		$body = $this->apply( [ 'skipped' => 1, 'groups' => [ [ 'reason' => '<b>Нет</b> склада', 'count' => 1, 'secret' => 'x', 'examples' => [ '<i>Чашка</i>' ] ] ] ] );

		$this->assertSame(
			[ [ 'kind' => 'skipped', 'reason' => 'Нет склада', 'count' => 1, 'fix_label' => '', 'fix_url' => '', 'examples' => [ 'Чашка' ] ] ],
			$body['groups']
		);
	}

	public function test_removed_ids_and_rows_are_bounded_and_well_formed(): void {
		$this->assertContractViolation( $this->apply( [ 'removed_ids' => [ 'a', 'a' ] ] ) );
		$this->assertContractViolation( $this->apply( [ 'removed_ids' => [ 'bad id' ] ] ) );
		$this->assertContractViolation( $this->apply( [ 'rows' => [ [ 'id' => 'x' ] ] ] ) );
		$this->assertSame( [ 'p:1' ], $this->apply( [ 'removed_ids' => [ 'p:1' ] ] )['removed_ids'] );
	}

	public function test_a_download_file_name_is_a_bare_name(): void {
		$download = $this->definition->get_action( 'labels' );
		$result   = [ 'requested' => 1, 'accepted' => 1, 'skipped' => 0, 'failed' => 0, 'download' => [ 'url' => '/wp-admin/x', 'filename' => '../x.pdf' ] ];

		$this->assertContractViolation( Action_Response::apply( $this->definition, $download, $result ) );
	}

	/**
	 * @dataProvider own_urls
	 */
	public function test_is_own_url( string $url, bool $own ): void {
		Functions\when( 'wp_parse_url' )->alias( static fn( $u, $component = -1 ) => parse_url( $u, $component ) );
		Functions\when( 'home_url' )->justReturn( 'https://shop.example' );
		Functions\when( 'site_url' )->justReturn( 'https://shop.example/cms' );
		Functions\when( 'admin_url' )->justReturn( 'https://admin.shop.example/wp-admin/' );

		$this->assertSame( $own, Action_Response::is_own_url( $url ) );
	}

	/** @return array<string,array{0:string,1:bool}> */
	public function own_urls(): array {
		return [
			'root-relative path'      => [ '/wp-admin/admin-post.php?x=1', true ],
			'the home host'           => [ 'https://shop.example/file.pdf', true ],
			'the admin host'          => [ 'https://admin.shop.example/wp-admin/x', true ],
			'host in another case'    => [ 'https://SHOP.example/file.pdf', true ],
			'another host'            => [ 'https://evil.example/file.pdf', false ],
			'a look-alike host'       => [ 'https://shop.example.evil.example/f', false ],
			'userinfo trick'          => [ 'https://shop.example@evil.example/f', false ],
			'protocol-relative'       => [ '//evil.example/f', false ],
			'javascript scheme'       => [ 'javascript:alert(1)', false ],
			'data scheme'             => [ 'data:text/html,x', false ],
			'whitespace'              => [ 'https://shop.example/a b', false ],
			'empty'                   => [ '', false ],
		];
	}
}
