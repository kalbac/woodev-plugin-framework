<?php
/**
 * What the two lists of the «Сопоставление и профили» page have in common, for the records engine.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Admin\Records\Standard_Messages;

defined( 'ABSPATH' ) || exit;

/**
 * The frame of both lists: sorting by the first column, the page sizes, «Редактировать» and «Удалить» on every row, and the sentences
 * the table shell reads. {@see Mapping_List_Definition} and {@see Profile_List_Definition} add their columns and their own sentences.
 *
 * @since 2.1.13
 */
final class List_Definition {
	/**
	 * Row action: open the record's form (a link, so a middle click opens it in a tab of its own).
	 */
	public const EDIT = 'edit';

	/**
	 * Row action: delete the record. The page asks in its own dialog first; the engine runs nothing for it.
	 */
	public const DELETE = 'delete';

	/**
	 * Builds the definition of a list.
	 *
	 * @since 2.1.13
	 *
	 * @param array<int,array<string,mixed>> $columns   The list's data columns, the name first; the actions column is added here.
	 * @param string[]                       $sort_keys Sort keys the list answers to; the first is the default, ascending.
	 * @param array<string,string>           $messages  The list's own sentences, over the common ones.
	 * @return array<string,mixed>
	 */
	public static function build( array $columns, array $sort_keys, array $messages ): array {
		return [
			'columns'     => array_merge(
				$columns,
				[
					[
						'id'        => 'actions',
						'label'     => __( 'Действия', 'woodev-plugin-framework' ),
						'cell_type' => 'actions',
						'required'  => true,
					],
				]
			),
			'sorts'       => [
				'keys'    => $sort_keys,
				'default' => [
					'key'       => $sort_keys[0],
					'direction' => 'asc',
				],
			],
			'pagination'  => [
				'default_per_page' => 20,
				'per_page_options' => [ 20, 50, 100 ],
				'max_per_page'     => 100,
				'max_selection'    => 1,
			],
			'row_actions' => self::row_actions(),
			'messages'    => array_merge( self::messages(), $messages ),
			'analytics'   => null,
		];
	}

	/**
	 * A sortable data column.
	 *
	 * @since 2.1.13
	 *
	 * @param string              $id    Column id, also its sort key.
	 * @param string              $label Heading.
	 * @param string              $type  Cell type.
	 * @param array<string,mixed> $extra Other column keys (`required`, `align`, `help`).
	 * @return array<string,mixed>
	 */
	public static function column( string $id, string $label, string $type, array $extra = [] ): array {
		return array_merge(
			[
				'id'        => $id,
				'label'     => $label,
				'cell_type' => $type,
				'sort_key'  => $id,
			],
			$extra
		);
	}

	/**
	 * «Редактировать» and «Удалить»: two icon buttons, so the row's actions are the one group of the other tables.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function row_actions(): array {
		return [
			[
				'id'          => self::EDIT,
				'label'       => __( 'Редактировать', 'woodev-plugin-framework' ),
				'icon'        => 'edit',
				'effect'      => 'read',
				'interaction' => 'link',
			],
			[
				'id'          => self::DELETE,
				'label'       => __( 'Удалить', 'woodev-plugin-framework' ),
				'icon'        => 'trash',
				'tone'        => 'danger',
				'effect'      => 'mutate',
				'interaction' => 'dialog',
			],
		];
	}

	/**
	 * The sentences every list's shell reads.
	 *
	 * @return array<string,string>
	 */
	private static function messages(): array {
		return array_merge(
			Standard_Messages::views(),
			[
				'retry'          => __( 'Повторить', 'woodev-plugin-framework' ),
				'actions_column' => __( 'Действия', 'woodev-plugin-framework' ),
			]
		);
	}
}
