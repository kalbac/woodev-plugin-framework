<?php
/**
 * The definition of the table of shipments.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Shipments;

use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Standard_Messages;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Field;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;
use Woodev\Framework\Marketplace\Settings\Shipment_Noun;
use Woodev\Framework\Marketplace\Shipments\Shipment_Document_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the table for the records engine, in the pattern of «Заказы доставки»: a row per shipment with its units as child rows
 * (the variable-product mechanism), row and bulk actions, the view row of states, a switch for the shipments with units to ship
 * again, and every sentence the page shows.
 *
 * @since 2.1.28
 */
final class Shipments_Definition {
	public const CREATE    = 'create';
	public const REFRESH   = 'refresh';
	public const SHIPPING  = 'shipping';
	public const CARGO     = 'cargo';
	public const HAND_OVER = 'handover';
	public const MOVE      = 'move';
	public const BULK_MOVE = 'bulk-move';
	public const DOCUMENT  = 'doc-';

	public const STATE_OPEN   = 'open';
	public const STATE_CLOSED = 'closed';

	/**
	 * Field of a move: the shipment the units join.
	 */
	public const FIELD_TARGET = 'target';

	/**
	 * Field of a new shipment: its name.
	 */
	public const FIELD_NAME = 'name';

	/**
	 * Field of new cargo places: how many.
	 */
	public const FIELD_COUNT = 'count';

	/**
	 * Capability the actions ask for.
	 */
	private const EDIT = 'edit_shop_orders';

	/**
	 * Builds the definition.
	 *
	 * @since 2.1.28
	 *
	 * @param array<int,array{value:string,label:string}> $accounts        Accounts the user may open.
	 * @param Shipment_Noun                               $noun            The marketplace's words.
	 * @param Seller_Action_Field[]                       $shipping_fields Fields of the «pickup point and date» dialog; empty for none.
	 * @param int                                         $cargo_places    Most cargo places one request adds; 0 for none.
	 * @param Shipment_Document_Type[]                    $documents       Documents a shipment may have.
	 * @param array<int,array<string,mixed>>              $unit_actions    Seller actions offered on one unit, already in the engine's shape.
	 * @return array<string,mixed>
	 */
	public static function build( array $accounts, Shipment_Noun $noun, array $shipping_fields, int $cargo_places, array $documents, array $unit_actions = [] ): array {
		return [
			'context'      => [
				'label'   => __( 'Аккаунт', 'woodev-plugin-framework' ),
				'options' => $accounts,
				'default' => (string) ( $accounts[0]['value'] ?? '' ),
			],
			'columns'      => self::columns( $noun ),
			'filters'      => self::filters( $noun ),
			'sorts'        => [
				'keys'    => [ 'created', 'name' ],
				'default' => [
					'key'       => 'created',
					'direction' => 'desc',
				],
			],
			'pagination'   => [
				'default_per_page' => 20,
				'per_page_options' => [ 20, 50, 100 ],
				'max_per_page'     => 100,
				'max_selection'    => 100,
			],
			'children'     => [
				'default_per_page' => 50,
				'max_per_page'     => 100,
			],
			'row_actions'  => self::row_actions( $noun, $shipping_fields, $cargo_places, $documents, $unit_actions ),
			'bulk_actions' => self::bulk_actions( $noun ),
			'activity'     => [
				'refresh_ms'     => 5000,
				'max_refresh_ms' => 60000,
				'stale_after_ms' => 30000,
				'id_limit'       => 100,
			],
			'messages'     => array_merge( Standard_Messages::views(), Standard_Messages::pager(), Standard_Messages::bulk(), self::messages( $noun ) ),
			'analytics'    => null,
		];
	}

	/**
	 * @param Shipment_Noun $noun Words.
	 * @return array<int,array<string,mixed>>
	 */
	private static function columns( Shipment_Noun $noun ): array {
		return [
			[
				'id'        => 'name',
				'label'     => Posting_Noun::capitalised( $noun->one() ),
				'cell_type' => 'stack',
				'sort_key'  => 'name',
				'required'  => true,
			],
			[
				'id'        => 'state',
				'label'     => __( 'Состояние', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
			],
			[
				'id'        => 'units',
				'label'     => __( 'Товаров', 'woodev-plugin-framework' ),
				'cell_type' => 'number',
				'align'     => 'right',
			],
			[
				'id'        => 'missing',
				'label'     => __( 'Чего не хватает', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
			],
			[
				'id'        => 'shipping',
				'label'     => __( 'Пункт и дата отгрузки', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
			],
			[
				'id'        => 'created',
				'label'     => __( 'Создана', 'woodev-plugin-framework' ),
				'cell_type' => 'date',
				'sort_key'  => 'created',
			],
			[
				'id'        => 'actions',
				'label'     => '',
				'cell_type' => 'actions',
			],
		];
	}

	/**
	 * @param Shipment_Noun $noun Words.
	 * @return array<int,array<string,mixed>>
	 */
	private static function filters( Shipment_Noun $noun ): array {
		return [
			[
				'id'    => 'q',
				/* translators: %s: the marketplace's word for a shipment in the genitive, «поставки». */
				'label' => sprintf( __( 'Поиск по названию, номеру %s или ID заказа', 'woodev-plugin-framework' ), $noun->of_one() ),
				'type'  => 'search',
			],
			[
				'id'      => 'state',
				/* translators: %s: the marketplace's word for a shipment in the genitive, «поставки». */
				'label'   => sprintf( __( 'Состояние %s', 'woodev-plugin-framework' ), $noun->of_one() ),
				'type'    => 'select',
				'views'   => true,
				'options' => [
					[
						'value' => self::STATE_OPEN,
						'label' => __( 'Открытые', 'woodev-plugin-framework' ),
					],
					[
						'value' => self::STATE_CLOSED,
						'label' => __( 'Переданные', 'woodev-plugin-framework' ),
					],
				],
			],
			[
				'id'    => 'reship',
				'label' => __( 'Требуют повторной отгрузки', 'woodev-plugin-framework' ),
				'type'  => 'boolean',
			],
		];
	}

	/**
	 * @param Shipment_Noun                  $noun            Words.
	 * @param Seller_Action_Field[]          $shipping_fields Fields of the shipping dialog.
	 * @param int                            $cargo_places    Most cargo places per request.
	 * @param Shipment_Document_Type[]       $documents       Documents.
	 * @param array<int,array<string,mixed>> $unit_actions    Unit actions.
	 * @return array<int,array<string,mixed>>
	 */
	private static function row_actions( Shipment_Noun $noun, array $shipping_fields, int $cargo_places, array $documents, array $unit_actions ): array {
		$actions = [];

		if ( [] !== $shipping_fields ) {
			$actions[] = self::dialog(
				self::SHIPPING,
				__( 'Выбрать пункт и дату отгрузки', 'woodev-plugin-framework' ),
				__( 'Пункт и дата', 'woodev-plugin-framework' ),
				'location',
				array_map( [ self::class, 'field' ], $shipping_fields ),
				''
			);
		}

		if ( $cargo_places > 0 ) {
			$actions[] = self::dialog(
				self::CARGO,
				/* translators: %s: the marketplace's word for cargo places, «грузовые места». */
				sprintf( __( 'Добавить %s', 'woodev-plugin-framework' ), $noun->places() ),
				'',
				'archive',
				[
					[
						'id'       => self::FIELD_COUNT,
						/* translators: %s: «of cargo places», «грузовых мест». */
						'label'    => sprintf( __( 'Сколько %s добавить', 'woodev-plugin-framework' ), $noun->of_places() ),
						'type'     => 'number',
						'required' => true,
					],
				],
				''
			);
		}

		foreach ( $documents as $document ) {
			$actions[] = [
				'id'           => self::DOCUMENT . $document->id(),
				'label'        => $document->label(),
				'short_label'  => mb_strlen( $document->short_label() ) > 32 ? '' : $document->short_label(),
				'icon'         => $document->icon(),
				'effect'       => 'mutate',
				'interaction'  => 'download',
				'capability'   => self::EDIT,
				'skip_preview' => true,
			];
		}

		$actions[] = array_merge(
			self::dialog(
				self::HAND_OVER,
				__( 'Передать в доставку', 'woodev-plugin-framework' ),
				__( 'Передать', 'woodev-plugin-framework' ),
				'yes-alt',
				[],
				/* translators: %s: the marketplace's word for a shipment, capitalised: «Поставка». */
				sprintf( __( '%s будет передана маркетплейсу. После этого изменить её нельзя.', 'woodev-plugin-framework' ), Posting_Noun::capitalised( $noun->one() ) )
			),
			[ 'tone' => 'primary' ]
		);

		$actions[] = self::dialog(
			self::MOVE,
			/* translators: %s: the marketplace's word for a shipment in the accusative, «поставку». */
			sprintf( __( 'Перенести в другую %s', 'woodev-plugin-framework' ), $noun->acc() ),
			__( 'Перенести', 'woodev-plugin-framework' ),
			'migrate',
			[ self::target_field( $noun ) ],
			''
		);

		return array_merge( $actions, $unit_actions );
	}

	/**
	 * @param Shipment_Noun $noun Words.
	 * @return array<int,array<string,mixed>>
	 */
	private static function bulk_actions( Shipment_Noun $noun ): array {
		/* translators: %s: the marketplace's word for a shipment in the accusative, «поставку». */
		$create = sprintf( __( 'Создать %s', 'woodev-plugin-framework' ), $noun->acc() );

		return [
			[
				'id'             => self::CREATE,
				'label'          => $create,
				'tone'           => 'primary',
				'effect'         => 'mutate',
				'interaction'    => 'dialog',
				'scope'          => 'account',
				'capability'     => self::EDIT,
				/* translators: %s: the marketplace's word for a shipment, «поставка». */
				'scope_sentence' => sprintf( __( 'На маркетплейсе появится новая пустая %s.', 'woodev-plugin-framework' ), $noun->one() ),
				'confirm_label'  => $create,
				'fields'         => [
					[
						'id'       => self::FIELD_NAME,
						/* translators: %s: the marketplace's word for a shipment in the genitive, «поставки». */
						'label'    => sprintf( __( 'Название %s', 'woodev-plugin-framework' ), $noun->of_one() ),
						'type'     => 'text',
						'required' => true,
					],
				],
			],
			[
				'id'             => self::REFRESH,
				'label'          => __( 'Обновить список', 'woodev-plugin-framework' ),
				'effect'         => 'mutate',
				'interaction'    => 'submit',
				'scope'          => 'account',
				'capability'     => self::EDIT,
				'skip_preview'   => true,
				'scope_sentence' => __( 'Список будет прочитан с маркетплейса заново.', 'woodev-plugin-framework' ),
			],
			[
				'id'            => self::BULK_MOVE,
				/* translators: %s: the marketplace's word for a shipment in the accusative, «поставку». */
				'label'         => sprintf( __( 'Перенести в другую %s', 'woodev-plugin-framework' ), $noun->acc() ),
				'effect'        => 'mutate',
				'interaction'   => 'dialog',
				'scope'         => 'selection',
				'capability'    => self::EDIT,
				'confirm_label' => __( 'Перенести', 'woodev-plugin-framework' ),
				'fields'        => [ self::target_field( $noun ) ],
			],
		];
	}

	/**
	 * @param string                         $id      Action id.
	 * @param string                         $label   Label.
	 * @param string                         $short   Short label; empty for none.
	 * @param string                         $icon    Dashicons slug.
	 * @param array<int,array<string,mixed>> $fields  Fields.
	 * @param string                         $scope   Confirmation sentence.
	 * @return array<string,mixed> A row action that runs through a dialog.
	 */
	private static function dialog( string $id, string $label, string $short, string $icon, array $fields, string $scope ): array {
		return [
			'id'             => $id,
			'label'          => $label,
			'short_label'    => $short,
			'icon'           => $icon,
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'capability'     => self::EDIT,
			'scope_sentence' => mb_substr( $scope, 0, Records_Definition::MAX_STRING_LENGTH ),
			'confirm_label'  => '' === $short ? $label : $short,
			'fields'         => $fields,
		];
	}

	/**
	 * @param Shipment_Noun $noun Words.
	 * @return array<string,mixed> The list of open shipments a move offers.
	 */
	private static function target_field( Shipment_Noun $noun ): array {
		return [
			'id'             => self::FIELD_TARGET,
			/* translators: %s: the marketplace's word for a shipment in the accusative, «поставку». */
			'label'          => sprintf( __( 'В какую %s', 'woodev-plugin-framework' ), $noun->acc() ),
			'type'           => 'select',
			'required'       => true,
			'options_source' => true,
			'searchable'     => true,
		];
	}

	/**
	 * One field of the shipping dialog in the engine's shape: a code is a line of text there.
	 *
	 * @param Seller_Action_Field $field Field.
	 * @return array<string,mixed>
	 */
	private static function field( Seller_Action_Field $field ): array {
		$types = [
			Seller_Action_Field::TEXT     => 'text',
			Seller_Action_Field::CODE     => 'text',
			Seller_Action_Field::TEXTAREA => 'textarea',
			Seller_Action_Field::SELECT   => 'select',
		];

		$definition = [
			'id'       => $field->id(),
			'label'    => $field->label(),
			'type'     => $types[ $field->type() ],
			'required' => $field->is_required(),
			'help'     => $field->help(),
		];

		if ( Seller_Action_Field::SELECT === $field->type() ) {
			$definition['options']        = $field->options();
			$definition['options_source'] = $field->has_options_source();
			$definition['searchable']     = $field->has_options_source();
		}

		return $definition;
	}

	/**
	 * Every sentence the page shows besides the standard ones.
	 *
	 * @param Shipment_Noun $noun Words.
	 * @return array<string,string>
	 */
	private static function messages( Shipment_Noun $noun ): array {
		return [
			/* translators: %s: the marketplace's word for shipments in the genitive, «поставок». */
			'empty'                   => sprintf( __( 'Пока нет %s. Создайте первую или добавьте заказы на странице «Заказы».', 'woodev-plugin-framework' ), $noun->of_many() ),
			'empty_filtered'          => __( 'По этим фильтрам ничего нет.', 'woodev-plugin-framework' ),
			'empty_action'            => self::REFRESH,
			'error'                   => __( 'Не удалось загрузить список. Повторите чуть позже.', 'woodev-plugin-framework' ),
			'retry'                   => __( 'Повторить', 'woodev-plugin-framework' ),
			'reset_filters'           => __( 'Сбросить фильтры', 'woodev-plugin-framework' ),
			'actions_column'          => __( 'Действия', 'woodev-plugin-framework' ),
			'filter_any'              => __( 'Любое', 'woodev-plugin-framework' ),
			'select_all'              => __( 'Выбрать все', 'woodev-plugin-framework' ),
			'select_row'              => __( 'Выбрать', 'woodev-plugin-framework' ),
			'selection_limit'         => __( 'Выбрано предельное число товаров. Выполните действие и выберите остальные.', 'woodev-plugin-framework' ),
			'per_page'                => __( 'Строк на странице', 'woodev-plugin-framework' ),
			'expand_column'           => __( 'Товары', 'woodev-plugin-framework' ),
			'expand'                  => __( 'Показать товары', 'woodev-plugin-framework' ),
			'collapse'                => __( 'Скрыть товары', 'woodev-plugin-framework' ),
			'children_loading'        => __( 'Загружаем товары…', 'woodev-plugin-framework' ),
			'children_more'           => __( 'Показать ещё', 'woodev-plugin-framework' ),
			'action_cancel'           => __( 'Отмена', 'woodev-plugin-framework' ),
			'action_close'            => __( 'Закрыть', 'woodev-plugin-framework' ),
			'action_preview'          => __( 'Проверить', 'woodev-plugin-framework' ),
			'action_planning'         => __( 'Проверяем…', 'woodev-plugin-framework' ),
			'action_applying'         => __( 'Выполняем…', 'woodev-plugin-framework' ),
			'action_retry'            => __( 'Повторить', 'woodev-plugin-framework' ),
			'action_requested'        => __( 'Выбрано', 'woodev-plugin-framework' ),
			'action_eligible'         => __( 'Будет обработано', 'woodev-plugin-framework' ),
			'action_skipped'          => __( 'Пропущено', 'woodev-plugin-framework' ),
			'action_accepted'         => __( 'Принято', 'woodev-plugin-framework' ),
			'action_failed'           => __( 'Не удалось', 'woodev-plugin-framework' ),
			'action_status_done'      => __( 'Готово.', 'woodev-plugin-framework' ),
			'action_status_partial'   => __( 'Выполнено частично: причины перечислены ниже.', 'woodev-plugin-framework' ),
			'action_status_queued'    => __( 'Обновление поставлено в очередь.', 'woodev-plugin-framework' ),
			'action_status_failed'    => __( 'Ничего не выполнено: причины перечислены ниже.', 'woodev-plugin-framework' ),
			'action_group_skipped'    => __( 'Пропущено', 'woodev-plugin-framework' ),
			'action_group_failed'     => __( 'Не удалось', 'woodev-plugin-framework' ),
			'action_group_warning'    => __( 'С предупреждением', 'woodev-plugin-framework' ),
			'action_warnings'         => __( 'Предупреждения', 'woodev-plugin-framework' ),
			'action_recovery'         => __( 'Ответа нет: действие могло выполниться. Мы обновили таблицу — проверьте состояние.', 'woodev-plugin-framework' ),
			'action_error'            => __( 'Не удалось выполнить запрос. Повторите чуть позже.', 'woodev-plugin-framework' ),
			'action_download'         => __( 'Скачать файл', 'woodev-plugin-framework' ),
			'action_download_blocked' => __( 'Адрес файла не прошёл проверку, поэтому ссылка не показана.', 'woodev-plugin-framework' ),
			'action_choose'           => __( 'Выберите…', 'woodev-plugin-framework' ),
			'action_options_more'     => __( 'Показать ещё', 'woodev-plugin-framework' ),
			'action_options_error'    => __( 'Не удалось загрузить список.', 'woodev-plugin-framework' ),
			'action_options_loading'  => __( 'Загружаем список…', 'woodev-plugin-framework' ),
			'activity_stale'          => __( 'Данные о ходе работы устарели: обновляем.', 'woodev-plugin-framework' ),
			'activity_failed'         => __( 'Не удаётся получить ход работы. Попробуем ещё раз.', 'woodev-plugin-framework' ),
			'data_status'             => __( 'Статус данных', 'woodev-plugin-framework' ),
		];
	}
}
