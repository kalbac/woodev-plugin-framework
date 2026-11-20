<?php
/**
 * The read-only half every list of the «Сопоставление и профили» page shares.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Records_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * A list of a few records, read whole, sorted and cut into pages here: a shop has tens of mappings and profiles, so there is nothing to
 * gain from asking the database for a page. A list gives {@see self::records()} and the plural forms of its count; the engine's table
 * does the rest.
 *
 * The engine runs no action of these lists: «Редактировать» is a link, and «Удалить» is the page's own dialog and the page's REST
 * route, so no object operation but the table's own definition and list reads is authorised.
 *
 * @since 2.1.13
 */
abstract class List_Provider implements Records_Provider {
	/**
	 * Address of the page the forms are on, without the form's own argument: `…admin.php?page=wc-admin&path=%2Fozon-seller%2Fmappings`.
	 *
	 * @var string
	 */
	private $page_url;

	/**
	 * Creates the provider.
	 *
	 * @since 2.1.13
	 *
	 * @param string $page_url Address of the «Сопоставление и профили» page.
	 */
	public function __construct( string $page_url ) {
		$this->page_url = $page_url;
	}

	/**
	 * Every record of the list, in no order: `[ 'id' => string, 'url' => the form's address, 'cells' => [ column id => cell ], 'sort' => [ sort key => scalar ] ]`.
	 *
	 * @since 2.1.13
	 *
	 * @return array<int,array{id:string,url:string,cells:array<string,mixed>,sort:array<string,string|int>}>
	 */
	abstract protected function records(): array;

	/**
	 * The count of the table's footer in the plural form that fits: «3 сопоставления».
	 *
	 * @since 2.1.13
	 *
	 * @param int $total Number of records.
	 * @return string The noun alone, e.g. «сопоставления».
	 */
	abstract protected function noun( int $total ): string;

	/**
	 * Sentences that depend on what is in the shop and so cannot be in the definition (the empty list's explanation).
	 *
	 * @since 2.1.13
	 *
	 * @param int $total Number of records.
	 * @return array<string,string>
	 */
	protected function messages( int $total ): array {
		return [];
	}

	/**
	 * Only the table's own reads: the definition and the list.
	 *
	 * @since 2.1.13
	 *
	 * @param string               $operation `definition`, `list`, `children`, `options`, `activity` or an action id.
	 * @param array<string,string> $context   Resolved context.
	 * @param string[]             $ids       Row ids.
	 * @return bool
	 */
	public function authorize( string $operation, array $context, array $ids ): bool {
		return in_array( $operation, [ 'definition', 'list' ], true );
	}

	/**
	 * One page of the sorted list.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param array<string,mixed>  $query   Validated query.
	 * @return array<string,mixed>
	 */
	public function query( array $context, array $query ): array {
		$records = $this->sorted( $this->records(), (string) $query['sort'], 'desc' === $query['direction'] ? -1 : 1 );
		$total   = count( $records );
		$page    = array_slice( $records, ( (int) $query['page'] - 1 ) * (int) $query['per_page'], (int) $query['per_page'] );

		return [
			'rows'     => array_map( [ $this, 'row' ], $page ),
			'total'    => $total,
			'messages' => array_merge( [ 'summary_label' => $this->noun( $total ) ], $this->messages( $total ) ),
		];
	}

	/**
	 * The list has no child rows.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,string> $context   Resolved context.
	 * @param string               $parent_id Row id.
	 * @param array<string,mixed>  $query     Validated query.
	 * @return array<string,mixed>
	 */
	public function get_children( array $context, string $parent_id, array $query ): array {
		return [
			'rows'  => [],
			'total' => 0,
		];
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- The two methods below only refuse: no action reaches them.

	/**
	 * Nothing is previewed: no action of the list is run by the engine ({@see self::authorize()} refuses them before this).
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Action id.
	 * @param string[]             $ids     Selected row ids.
	 * @param array<string,mixed>  $payload Validated fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception Always.
	 */
	public function preview( array $context, string $action, array $ids, array $payload ): array {
		throw new Action_State_Exception( 'The list runs no action.' );
	}

	/**
	 * Nothing is applied: see {@see self::preview()}.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Action id.
	 * @param string[]             $ids     Selected row ids.
	 * @param array<string,mixed>  $payload Validated fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception Always.
	 */
	public function apply( array $context, string $action, array $ids, array $payload ): array {
		throw new Action_State_Exception( 'The list runs no action.' );
	}

	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	/**
	 * The list has no activity.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string[]             $ids     Row ids.
	 * @return array<string,mixed>
	 */
	public function get_activity( array $context, array $ids ): array {
		return [ 'removed_ids' => [] ];
	}

	/**
	 * The address that opens a form: the page with the arguments the page's route reads (`mapping=5`, `tab=profiles&profile=3`).
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,string> $arguments Route arguments.
	 * @return string
	 */
	protected function form_url( array $arguments ): string {
		return add_query_arg( array_map( 'rawurlencode', $arguments ), $this->page_url );
	}

	/**
	 * A record as the engine's row: its cells and the two actions every row has.
	 *
	 * @param array{id:string,url:string,cells:array<string,mixed>,sort:array<string,string|int>} $record Record.
	 * @return array<string,mixed>
	 */
	private function row( array $record ): array {
		return [
			'id'      => $record['id'],
			'cells'   => $record['cells'],
			'actions' => [
				[
					'id'      => List_Definition::EDIT,
					'enabled' => true,
					'url'     => $record['url'],
				],
				[
					'id'      => List_Definition::DELETE,
					'enabled' => true,
				],
			],
		];
	}

	/**
	 * The records in the order of one sort key; records that tie keep the order of their id, so a page never shuffles.
	 *
	 * @param array<int,array<string,mixed>> $records   Records.
	 * @param string                         $key       Sort key.
	 * @param int                            $direction 1 ascending, -1 descending.
	 * @return array<int,array<string,mixed>>
	 */
	private function sorted( array $records, string $key, int $direction ): array {
		usort(
			$records,
			static function ( array $a, array $b ) use ( $key, $direction ): int {
				$left   = $a['sort'][ $key ] ?? '';
				$right  = $b['sort'][ $key ] ?? '';
				$order  = is_int( $left ) && is_int( $right ) ? $left <=> $right : strcmp( mb_strtolower( (string) $left ), mb_strtolower( (string) $right ) );
				$order *= $direction;

				return 0 !== $order ? $order : (int) $a['id'] <=> (int) $b['id'];
			}
		);

		return $records;
	}
}
