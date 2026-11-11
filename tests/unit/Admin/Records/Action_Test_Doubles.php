<?php
/**
 * Doubles of the action tests: a JSON request, a provider that plans and applies on demand, and a
 * definition that declares every kind of action and input field.
 *
 * @package Woodev\Tests\Unit\Admin\Records
 */

namespace Woodev\Tests\Unit\Admin\Records;

require_once __DIR__ . '/Records_Fixtures.php';

/**
 * A POST request: route params, query-string params and a JSON body.
 */
class Action_Test_Request extends Records_Test_Request {

	/** @var mixed */
	private $json;

	/**
	 * @param mixed               $json         JSON body (null = no JSON body).
	 * @param array<string,string> $url_params   Route params.
	 * @param array<string,mixed>  $query_params Query-string params.
	 */
	public function __construct( $json, array $url_params = [], array $query_params = [] ) {
		parent::__construct( $url_params, $query_params );
		$this->json = $json;
	}

	/** @return mixed */
	public function get_json_params() {
		return $this->json;
	}
}

/**
 * Plans and applies what the test tells it to, and records every call.
 */
class Action_Test_Provider extends Fake_Provider {

	/** @var array<string,mixed> What preview() returns. */
	public $preview_result = [
		'requested' => 3,
		'eligible'  => 2,
		'skipped'   => 1,
		'groups'    => [ [ 'reason' => 'Нет артикула', 'count' => 1 ] ],
	];

	/** @var array<int,array<string,mixed>> What apply() returns, one entry per call; the last one repeats. */
	public $apply_results = [
		[
			'requested' => 3,
			'accepted'  => 3,
			'skipped'   => 0,
			'failed'    => 0,
		],
	];

	/** @var \Throwable|null Thrown by preview() and apply(). */
	public $action_throw = null;

	/** @var int Number of apply() calls so far. */
	public $applied = 0;

	public function preview( array $context, string $action, array $ids, array $payload ): array {
		$this->calls[]                = 'preview';
		$this->last['preview']        = [ $context, $action, $ids, $payload ];
		if ( null !== $this->action_throw ) {
			throw $this->action_throw;
		}

		return $this->preview_result;
	}

	public function apply( array $context, string $action, array $ids, array $payload ): array {
		$this->calls[]         = 'apply';
		$this->last['apply']   = [ $context, $action, $ids, $payload ];
		if ( null !== $this->action_throw ) {
			throw $this->action_throw;
		}

		$index = min( $this->applied, count( $this->apply_results ) - 1 );
		++$this->applied;

		return $this->apply_results[ $index ];
	}
}

/**
 * Definitions with actions.
 */
final class Action_Definitions {

	/**
	 * A table with a row action, a selection bulk action with one field of every type, an
	 * account-wide action, a download and a plain link.
	 *
	 * @return array<string,mixed>
	 */
	public static function with_actions(): array {
		return Records_Definitions::standard(
			[
				'row_actions'  => [
					[ 'id' => 'open', 'label' => 'Открыть', 'effect' => 'read', 'interaction' => 'link' ],
					[
						'id'          => 'sync',
						'label'       => 'Отправить',
						'effect'      => 'mutate',
						'interaction' => 'submit',
						'capability'  => 'edit_products',
						'fields'      => [
							[ 'id' => 'note', 'label' => 'Заметка', 'type' => 'text' ],
						],
					],
				],
				'bulk_actions' => [
					[
						'id'            => 'export',
						'label'         => 'Выгрузить',
						'effect'        => 'mutate',
						'interaction'   => 'dialog',
						'confirm_label' => 'Выгрузить выбранные',
						'fields'        => [
							[ 'id' => 'warehouse', 'label' => 'Склад', 'type' => 'select', 'required' => true, 'options_source' => true ],
							[
								'id'      => 'mode',
								'label'   => 'Режим',
								'type'    => 'select',
								'options' => [
									[ 'value' => 'full', 'label' => 'Полный' ],
									[ 'value' => 'fast', 'label' => 'Быстрый' ],
								],
							],
							[ 'id' => 'qty', 'label' => 'Количество', 'type' => 'number' ],
							[ 'id' => 'comment', 'label' => 'Комментарий', 'type' => 'textarea' ],
							[ 'id' => 'notify', 'label' => 'Уведомить', 'type' => 'boolean' ],
							[
								'id'      => 'tags',
								'label'   => 'Теги',
								'type'    => 'multi_select',
								'options' => [
									[ 'value' => 'a', 'label' => 'A' ],
									[ 'value' => 'b', 'label' => 'B' ],
								],
							],
						],
					],
					[
						'id'             => 'link_all',
						'label'          => 'Связать по артикулу',
						'effect'         => 'mutate',
						'interaction'    => 'dialog',
						'scope'          => 'account',
						'scope_sentence' => 'Будут обработаны все товары аккаунта.',
					],
					[
						'id'          => 'labels',
						'label'       => 'Скачать этикетки',
						'effect'      => 'read',
						'interaction' => 'download',
					],
				],
			]
		);
	}
}
