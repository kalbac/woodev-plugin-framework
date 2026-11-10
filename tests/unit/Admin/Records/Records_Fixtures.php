<?php
/**
 * Records engine test fixtures: a fake provider, a standard valid definition, a request double.
 *
 * @package Woodev\Tests\Unit\Admin\Records
 */

namespace Woodev\Tests\Unit\Admin\Records;

use Woodev\Framework\Admin\Records\{Provides_Field_Options, Records_Provider};

require_once dirname( __DIR__, 2 ) . '/Shipping/Rest_Api/wp-rest-controller-stub.php'; // WP_REST_Server constants.

/**
 * Request double with the two accessors the controller uses.
 */
class Records_Test_Request extends \WP_REST_Request {

	/** @var array<string,mixed> */
	private $query;

	/**
	 * @param array<string,string> $url_params   Route params.
	 * @param array<string,mixed>  $query_params Query-string params.
	 */
	public function __construct( array $url_params = [], array $query_params = [] ) {
		parent::__construct( array_merge( $url_params, $query_params ) );
		$this->set_url_params( $url_params );
		$this->query = $query_params;
	}

	/** @return array<string,mixed> */
	public function get_query_params() {
		return $this->query;
	}
}

/**
 * Builds definitions the validator accepts.
 */
final class Records_Definitions {

	/**
	 * A complete, valid definition (accounts a1/a2, every filter type, children, both action lists).
	 *
	 * @param array<string,mixed> $overrides Top-level keys to replace.
	 * @return array<string,mixed>
	 */
	public static function standard( array $overrides = [] ): array {
		return array_merge(
			[
				'context'      => [
					'label'   => 'Аккаунт',
					'options' => [
						[ 'value' => 'a1', 'label' => 'Первый' ],
						[ 'value' => 'a2', 'label' => 'Второй' ],
					],
					'default' => 'a1',
				],
				'columns'      => [
					[ 'id' => 'name', 'label' => 'Название', 'cell_type' => 'text', 'sort_key' => 'name', 'required' => true ],
					[ 'id' => 'price', 'label' => 'Цена', 'cell_type' => 'number', 'sort_key' => 'price', 'align' => 'right' ],
					[ 'id' => 'status', 'label' => 'Статус', 'cell_type' => 'badge' ],
					[ 'id' => 'card', 'label' => 'Карточка', 'cell_type' => 'link' ],
					[ 'id' => 'actions', 'label' => '', 'cell_type' => 'actions' ],
				],
				'filters'      => [
					[ 'id' => 'q', 'label' => 'Поиск', 'type' => 'search' ],
					[
						'id'      => 'state',
						'label'   => 'Состояние',
						'type'    => 'select',
						'options' => [
							[ 'value' => 'ok', 'label' => 'Готово' ],
							[ 'value' => 'wait', 'label' => 'Ожидает' ],
						],
					],
					[ 'id' => 'tags', 'label' => 'Теги', 'type' => 'multi_select', 'options_source' => true ],
					[ 'id' => 'in_stock', 'label' => 'В наличии', 'type' => 'boolean' ],
					[ 'id' => 'created', 'label' => 'Создан', 'type' => 'date_range' ],
				],
				'sorts'        => [
					'keys'    => [ 'name', 'price' ],
					'default' => [ 'key' => 'name', 'direction' => 'asc' ],
				],
				'pagination'   => [
					'default_per_page' => 20,
					'per_page_options' => [ 10, 20, 50 ],
					'max_per_page'     => 50,
					'max_selection'    => 100,
				],
				'row_actions'  => [
					[ 'id' => 'open', 'label' => 'Открыть', 'effect' => 'read', 'interaction' => 'link' ],
					[
						'id'          => 'sync',
						'label'       => 'Отправить',
						'effect'      => 'mutate',
						'interaction' => 'submit',
						'capability'  => 'edit_products',
						'fields'      => [
							[ 'id' => 'warehouse', 'label' => 'Склад', 'type' => 'select', 'options_source' => true ],
							[ 'id' => 'note', 'label' => 'Заметка', 'type' => 'text' ],
						],
					],
				],
				'bulk_actions' => [
					[
						'id'             => 'sync_all',
						'label'          => 'Отправить все',
						'effect'         => 'mutate',
						'interaction'    => 'dialog',
						'scope'          => 'account',
						'scope_sentence' => 'Действие затронет весь аккаунт.',
					],
				],
				'children'     => [
					'default_per_page' => 10,
					'max_per_page'     => 20,
				],
				'activity'     => null,
				'messages'     => [ 'empty' => 'Нет записей' ],
				'analytics'    => null,
			],
			$overrides
		);
	}

	/**
	 * A row that fits {@see self::standard()}.
	 *
	 * @param string              $id        Row id.
	 * @param array<string,mixed> $overrides Row keys to replace.
	 * @return array<string,mixed>
	 */
	public static function row( string $id, array $overrides = [] ): array {
		return array_merge(
			[
				'id'           => $id,
				'revision'     => 'r1',
				'cells'        => [
					'name'   => 'Товар ' . $id,
					'price'  => 100,
					'status' => [ 'label' => 'Готово', 'tone' => 'success' ],
					'card'   => [ 'label' => 'Открыть', 'url' => 'https://example.test/card/' . $id ],
				],
				'actions'      => [ [ 'id' => 'open', 'enabled' => true, 'reason' => '' ] ],
				'selectable'   => true,
				'has_children' => false,
			],
			$overrides
		);
	}
}

/**
 * Configurable provider that records every call, so a test can prove nothing ran.
 */
class Fake_Provider implements Records_Provider, Provides_Field_Options {

	/** @var string */
	public $id;

	/** @var array<string,mixed> */
	public $definition;

	/** @var array<int,array<string,mixed>> */
	public $rows = [];

	/** @var int|null Null = count($rows). */
	public $total = null;

	/** @var array<int,array<string,mixed>> */
	public $child_rows = [];

	/** @var callable|null fn( string $operation, array $context, array $ids ): bool — default allows all. */
	public $authorizer = null;

	/** @var \Throwable|null Thrown by query(). */
	public $throw = null;

	/** @var array<int,array<string,mixed>> */
	public $field_options = [];

	/** @var string[] Names of the methods called, in order. */
	public $calls = [];

	/** @var array<string,mixed> Last arguments, by method. */
	public $last = [];

	/**
	 * @param string              $id         Table id.
	 * @param array<string,mixed> $definition Definition.
	 */
	public function __construct( string $id = 'products', array $definition = [] ) {
		$this->id         = $id;
		$this->definition = [] === $definition ? Records_Definitions::standard() : $definition;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_definition( array $context ): array {
		$this->calls[]                 = 'get_definition';
		$this->last['get_definition'] = $context;

		return $this->definition;
	}

	public function authorize( string $operation, array $context, array $ids ): bool {
		$this->calls[]             = 'authorize';
		$this->last['authorize'][] = [ $operation, $context, $ids ];

		return null === $this->authorizer ? true : (bool) ( $this->authorizer )( $operation, $context, $ids );
	}

	public function query( array $context, array $query ): array {
		$this->calls[]       = 'query';
		$this->last['query'] = [ $context, $query ];
		if ( null !== $this->throw ) {
			throw $this->throw;
		}

		return [
			'rows'  => $this->rows,
			'total' => $this->total ?? count( $this->rows ),
		];
	}

	public function get_children( array $context, string $parent_id, array $query ): array {
		$this->calls[]          = 'get_children';
		$this->last['children'] = [ $context, $parent_id, $query ];

		return [
			'rows'  => $this->child_rows,
			'total' => count( $this->child_rows ),
		];
	}

	public function preview( array $context, string $action, array $ids, array $payload ): array {
		$this->calls[] = 'preview';

		return [];
	}

	public function apply( array $context, string $action, array $ids, array $payload ): array {
		$this->calls[] = 'apply';

		return [];
	}

	public function get_activity( array $context, array $ids ): array {
		$this->calls[] = 'get_activity';

		return [];
	}

	public function get_field_options( array $context, string $action, string $field, array $query ): array {
		$this->calls[]                = 'get_field_options';
		$this->last['field_options'] = [ $context, $action, $field, $query ];

		return [
			'options'     => $this->field_options,
			'next_cursor' => null,
		];
	}
}
