/**
 * A fixture table for the records tests (this card's and the action/activity cards'): one of every cell type and
 * filter type, in the exact shape `Definition_Parser` and `Row_Normalizer` produce. Not imported by any bundle.
 *
 * @package woodev-plugin-framework
 */

import type { ActionDescriptor, AdminRecord, Definition, DefinitionEnvelope, ListEnvelope } from '../types';

function action( id: string, label: string, overrides: Partial< ActionDescriptor > = {} ): ActionDescriptor {
	return {
		id,
		label,
		tone: 'neutral',
		capability: '',
		effect: 'mutate',
		interaction: 'submit',
		scope: 'selection',
		scope_sentence: '',
		confirm_label: '',
		skip_preview: false,
		fields: [],
		...overrides,
	};
}

export function fixtureDefinition( overrides: Partial< Definition > = {} ): Definition {
	return {
		context: {
			id: 'account_id',
			label: 'Аккаунт',
			options: [
				{ value: 'a1', label: 'Основной' },
				{ value: 'a2', label: 'Второй' },
			],
			default: 'a1',
		},
		columns: [
			{
				id: 'name',
				label: 'Товар',
				cell_type: 'link',
				sort_key: 'title',
				required: true,
				visible: true,
				align: 'left',
			},
			{
				id: 'sku',
				label: 'Артикул',
				cell_type: 'text',
				sort_key: null,
				required: false,
				visible: true,
				align: 'left',
			},
			{
				id: 'state',
				label: 'Состояние',
				cell_type: 'badge',
				sort_key: null,
				required: false,
				visible: true,
				align: 'left',
			},
			{
				id: 'stock',
				label: 'Остаток',
				cell_type: 'number',
				sort_key: 'stock',
				required: false,
				visible: true,
				align: 'right',
			},
			{
				id: 'updated',
				label: 'Обновлён',
				cell_type: 'date',
				sort_key: 'updated',
				required: false,
				visible: true,
				align: 'left',
			},
			{
				id: 'details',
				label: 'Детали',
				cell_type: 'stack',
				sort_key: null,
				required: false,
				visible: true,
				align: 'left',
			},
			{
				id: 'sync',
				label: 'Отправка',
				cell_type: 'progress',
				sort_key: null,
				required: false,
				visible: true,
				align: 'left',
			},
			{
				id: 'actions',
				label: '',
				cell_type: 'actions',
				sort_key: null,
				required: true,
				visible: true,
				align: 'right',
			},
		],
		filters: [
			{ id: 'q', label: 'Поиск по товарам', type: 'search', options: [], options_source: false },
			{
				id: 'state',
				label: 'Состояние',
				type: 'select',
				options: [
					{ value: 'linked', label: 'Связан' },
					{ value: 'draft', label: 'Черновик' },
				],
				options_source: false,
			},
			{ id: 'brand', label: 'Бренд', type: 'multi_select', options: [], options_source: true },
			{ id: 'in_stock', label: 'Только в наличии', type: 'boolean', options: [], options_source: false },
			{ id: 'updated', label: 'Период обновления', type: 'date_range', options: [], options_source: false },
		],
		sorts: {
			keys: [ 'title', 'stock', 'updated' ],
			directions: [ 'asc', 'desc' ],
			default: { key: 'updated', direction: 'desc' },
		},
		pagination: { default_per_page: 25, per_page_options: [ 25, 50, 100 ], max_per_page: 100, max_selection: 3 },
		row_actions: [
			action( 'export', 'Выгрузить', { tone: 'primary' } ),
			action( 'unlink', 'Отвязать', { tone: 'danger' } ),
		],
		bulk_actions: [ action( 'bulk_export', 'Выгрузить выбранные' ) ],
		children: { default_per_page: 2, max_per_page: 50 },
		activity: null,
		messages: {
			empty: 'Товаров пока нет',
			empty_filtered: 'По этим условиям ничего не найдено',
			error: 'Не удалось загрузить список',
			retry: 'Повторить',
			reset_filters: 'Сбросить фильтры',
			expand_column: 'Вариации',
			expand: 'Показать вариации',
			collapse: 'Скрыть вариации',
			children_loading: 'Загружаем вариации…',
			children_more: 'Показать ещё',
			actions_column: 'Действия',
			select_all: 'Выбрать все на странице',
			select_row: 'Выбрать строку',
			date_from: 'С',
			date_to: 'По',
		},
		analytics: null,
		...overrides,
	};
}

export function fixtureRow( id: string, name: string, overrides: Partial< AdminRecord > = {} ): AdminRecord {
	return {
		id,
		revision: 'r1',
		cells: {
			name: { label: name, url: `/wp-admin/post.php?post=${ id }&action=edit` },
			sku: `SKU-${ id }`,
			state: { label: 'Связан', tone: 'success' },
			stock: 7,
			updated: '2026-11-09T12:30:00+03:00',
			details: [
				{ type: 'text', value: 'Склад Москва' },
				{ type: 'badge', value: { label: 'FBS', tone: 'info' } },
			],
			sync: { completed: 3, total: 10, percent: 30, text: 'Отправлено 3 из 10' },
			actions: null,
		},
		actions: [
			{ id: 'export', enabled: true, reason: '' },
			{ id: 'unlink', enabled: false, reason: 'Идёт выгрузка — отвязать нельзя' },
		],
		selectable: true,
		has_children: false,
		...overrides,
	};
}

export function fixtureDefinitionEnvelope(
	definition: Definition = fixtureDefinition(),
	account = 'a1'
): DefinitionEnvelope {
	return { schema_version: 1, definition, context: { account_id: account } };
}

export function fixtureList( rows: AdminRecord[], overrides: Partial< ListEnvelope > = {} ): ListEnvelope {
	return {
		rows,
		total: rows.length,
		page: 1,
		per_page: 25,
		total_pages: rows.length === 0 ? 0 : 1,
		facets: {
			brand: [
				{ value: 'acme', label: 'Acme', count: 4 },
				{ value: 'zen', label: 'Zen', count: 1 },
			],
		},
		messages: {},
		...overrides,
	};
}
