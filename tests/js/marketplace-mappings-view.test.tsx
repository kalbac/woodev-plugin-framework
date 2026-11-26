/**
 * The mappings and profiles view: it renders what PHP sends, posts what the server reads, and shows the server's sentences.
 *
 * @see src/marketplace-admin/views/mappings/
 */

import '@testing-library/jest-dom';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { applyFilters } from '@wordpress/hooks';
import { PAGE_VIEW_FILTER } from '../../src/marketplace-admin/page-view';
import { MappingsView } from '../../src/marketplace-admin/views/mappings';
import type { ManifestMenu, ManifestPage } from '../../src/marketplace-admin/types';
import type { MappingsState } from '../../src/marketplace-admin/views/mappings/types';
import type { TableCardProps } from '../../src/admin-records/wc-runtime';
import { fixtureDefinition, fixtureDefinitionEnvelope, fixtureList } from '../../src/admin-records/testing/fixture';
import type { AdminRecord, Column, Definition } from '../../src/admin-records/types';

jest.mock( '@wordpress/api-fetch' );

/** The three gestures the tests need, as plain DOM events (the user-event package is not a dependency of this repo). */
const userEvent = {
	click: async ( element: HTMLElement ) => {
		await act( async () => {
			fireEvent.click( element );
		} );
	},
	type: async ( element: HTMLElement, text: string ) => {
		await act( async () => {
			fireEvent.change( element, { target: { value: ( element as HTMLInputElement ).value + text } } );
		} );
	},
	selectOptions: async ( element: HTMLElement, value: string ) => {
		await act( async () => {
			fireEvent.change( element, { target: { value } } );
		} );
	},
};

const BASE = '/woodev/v1/admin/marketplace/ozon/mappings';
const RECORDS = '/woodev/v1/admin/records/ozon/';
const PAGE_URL = 'http://localhost/wp-admin/admin.php?page=wc-admin&path=%2Fozon%2Fmappings';

const MESSAGES: Record< string, string > = {
	tabMappings: 'Сопоставления',
	tabProfiles: 'Профили',
	requestFailed: 'Запрос не удался',
	addMapping: 'Добавить сопоставление',
	addProfile: 'Добавить профиль',
	createFirstMapping: 'Создать первое сопоставление',
	createShopCategory: 'Создать категорию товаров',
	shopCategoriesUrl: 'edit-tags.php?taxonomy=product_cat&post_type=product',
	createFirstProfile: 'Создать первый профиль',
	close: 'Закрыть',
	deleteMappingTitle: 'Удалить сопоставление?',
	deleteMappingText: 'Сопоставление «{name}» будет удалено.',
	deleteProfileTitle: 'Удалить профиль?',
	deleteProfileText: 'Профиль «{name}» будет удалён.',
	profileInUseTitle: 'Профиль «{name}» используется',
	profileInUseText: 'Он выбран в этих сопоставлениях:',
	backToMappings: '← Все сопоставления',
	backToProfiles: '← Все профили',
	newMapping: 'Новое сопоставление',
	newProfile: 'Новый профиль',
	groupCategories: 'Категории',
	groupPrice: 'Цена',
	checkHeading: 'Проверка товаров',
	columnAttribute: 'Характеристика',
	columnSource: 'Откуда брать',
	columnValue: 'Значение',
	mappingName: 'Название сопоставления',
	shopCategories: 'Категории магазина',
	shopCategoriesHelp: 'Одна категория — одно сопоставление',
	shopCategoryTaken: 'Уже входит в сопоставление',
	marketplaceCategory: 'Категория маркетплейса',
	categorySearch: 'Начните вводить',
	categoryChange: 'Выбрать другую',
	categoryUnavailable: 'Категории больше нет',
	noResults: 'Ничего не найдено',
	profile: 'Профиль выгрузки',
	profileNone: 'Не выбрано',
	attributes: 'Характеристики',
	attributesLoading: 'Загружаем характеристики…',
	attributesEmpty: 'Выберите категорию',
	required: 'обязательная',
	sourceNone: 'Не заполнять',
	sourceFixed: 'Одно значение',
	sourceAttribute: 'Атрибут товара',
	sourceMeta: 'Произвольное поле',
	sourceField: 'Поле карточки',
	fixedValue: 'Значение',
	dictionarySearch: 'Введите две буквы',
	save: 'Сохранить',
	saving: 'Сохраняем…',
	delete: 'Удалить',
	confirmDelete: 'Да, удалить',
	cancel: 'Отмена',
	retry: 'Повторить',
	copy: 'Сделать копию',
	check: 'Проверить товары',
	checking: 'Проверяем…',
	checkHelp: 'Проверка сохранённого',
	articlesHeading: 'Артикулы новых карточек',
	articlesHelp: 'Какой артикул получит товар',
	articlesMissing: 'Прочерк — артикул не получается',
	articlesLoading: 'Считаем артикулы…',
	articlesEmpty: 'Новые карточки не нужны',
	articlesMore: 'Показать ещё',
	articlesNoneYet: 'Среди проверенных новые карточки не нужны',
	columnProduct: 'Товар',
	columnVariations: 'Вариации',
	columnArticle: 'Артикул',
	savedMapping: 'Сопоставление сохранено.',
	copiedMapping: 'Копия создана.',
	savedProfile: 'Профиль сохранён.',
	deletedMapping: 'Сопоставление удалено.',
	deletedProfile: 'Профиль удалён.',
	profileName: 'Название профиля',
	sourcesHeading: 'Откуда брать данные',
	customFieldKey: 'Название поля',
	fallbacksHeading: 'Запасные размеры',
	fallbacksHelp: 'Если не заполнено',
	fallbackDimensions: 'Размеры (Ш × В × Г)',
	fallbackWidth: 'Ширина',
	fallbackHeight: 'Высота',
	fallbackDepth: 'Глубина',
	fallbackWeight: 'Вес',
	unitDimension: 'Единица размеров',
	unitWeight: 'Единица веса',
	unitDefaultDimension: 'как в магазине (см)',
	unitDefaultWeight: 'как в магазине (кг)',
	vatRate: 'Ставка НДС',
	currencyHeading: 'Валюта цены',
	currencyAccount: 'Как в кабинете',
	currencyRate: 'По курсу',
	currencyRateInput: 'Курс',
	currencyFixed: 'Фиксированная',
	currencyFieldKey: 'Поле товара',
	priceOverride: 'Своё правило цены',
	priceBase: 'Какую цену брать',
	priceMarkupKind: 'Наценка',
	priceMarkup: 'Размер наценки',
	priceRounding: 'Округление',
	priceOldPrice: 'Старая цена',
	priceMinPercent: 'Минимальная цена',
};

function option( value: string, label: string ) {
	return { value, label };
}

const STATE: MappingsState = {
	messages: MESSAGES,
	mappings: [ { id: 1, name: 'Платья', terms: [ 5 ], category_label: 'Одежда › Платья', profile_id: 3, products: 12 } ],
	profiles: [ { id: 3, name: 'Основной', mappings: 1, properties: [ 'Вес: 300 г', 'НДС: 20%' ], used_in: [ 'Платья' ] } ],
	shop_categories: [
		{ id: 5, label: 'Одежда', mapping_id: 1, mapping_name: 'Платья' },
		{ id: 6, label: 'Обувь', mapping_id: null, mapping_name: '' },
	],
	product_fields: [ option( 'sku', 'Артикул' ) ],
	profile_form: {
		sources: [
			{ setting: 'offer_id_source', title: 'Откуда брать артикул', kinds: [ option( 'sku', 'SKU' ), option( 'meta', 'Поле' ) ], default: 'sku', custom: 'meta' },
		],
		vat_rates: [ option( '', 'Не указывать' ), option( '0.2', '20%' ) ],
		weight_units: [ option( 'g', 'граммы' ) ],
		dimension_units: [ option( 'cm', 'сантиметры' ) ],
		currency_field: null,
		price: {
			base: [ option( 'active', 'Текущая' ) ],
			markup_kind: [ option( 'none', 'Без наценки' ) ],
			rounding: [ option( 'none', 'Не округлять' ) ],
			old_price_rule: [ option( 'on_sale', 'При скидке' ) ],
		},
	},
	categories_loaded: true,
};

const ATTRIBUTES = { label: '1 из 3 !', tone: 'warning', tooltip: 'Не заполнены обязательные атрибуты: 1' } as const;

function column( id: string, label: string, cellType: Column[ 'cell_type' ], overrides: Partial< Column > = {} ): Column {
	return { id, label, cell_type: cellType, sort_key: id, required: false, visible: true, align: 'left', ...overrides };
}

const ACTIONS_COLUMN = column( 'actions', 'Действия', 'actions', { sort_key: null, required: true } );

/** The two definitions as `Mapping_List_Definition` and `Profile_List_Definition` send them. */
function listDefinition( table: 'mappings' | 'profiles' ): Definition {
	const mappings = table === 'mappings';

	return fixtureDefinition( {
		context: null,
		columns: mappings
			? [
					column( 'name', 'Название', 'link', { required: true } ),
					column( 'category', 'Категория маркетплейса', 'text' ),
					column( 'shop', 'Категории магазина', 'text' ),
					column( 'profile', 'Профиль', 'link' ),
					column( 'attributes', 'Атрибуты', 'badge', { help: 'Без обязательных атрибутов товар выгрузить нельзя.' } ),
					column( 'products', 'Товаров', 'number', { align: 'right' } ),
					ACTIONS_COLUMN,
			  ]
			: [ column( 'name', 'Название', 'link', { required: true } ), column( 'properties', 'Свойства профиля', 'text' ), column( 'used_in', 'Используется в', 'text' ), ACTIONS_COLUMN ],
		filters: [],
		sorts: { keys: [ 'name' ], directions: [ 'asc', 'desc' ], default: { key: 'name', direction: 'asc' } },
		pagination: { default_per_page: 20, per_page_options: [ 20, 50, 100 ], max_per_page: 100, max_selection: 1 },
		row_actions: [
			{ id: 'edit', label: 'Редактировать', icon: 'edit', tone: 'neutral', capability: '', effect: 'read', interaction: 'link', scope: 'selection', scope_sentence: '', confirm_label: '', skip_preview: false, fields: [] },
			{ id: 'delete', label: 'Удалить', icon: 'trash', tone: 'danger', capability: '', effect: 'mutate', interaction: 'dialog', scope: 'selection', scope_sentence: '', confirm_label: '', skip_preview: false, fields: [] },
		],
		bulk_actions: [],
		children: null,
		messages: {
			description: mappings ? 'На этой странице настраивается соответствие категорий.' : 'На этой странице хранятся профили выгрузки.',
			empty: mappings ? 'Сопоставлений пока нет' : 'Профилей пока нет',
			actions_column: 'Действия',
			per_page: 'На странице',
			summary_label: mappings ? 'сопоставление' : 'профиль',
		},
	} );
}

function listActions( url: string ): AdminRecord[ 'actions' ] {
	return [
		{ id: 'edit', enabled: true, reason: '', url },
		{ id: 'delete', enabled: true, reason: '' },
	];
}

/** The rows the list tables read: what PHP's providers would make of the state. */
function listRows( state: MappingsState, table: 'mappings' | 'profiles' ): AdminRecord[] {
	if ( table === 'profiles' ) {
		return state.profiles.map( ( profile ) => {
			const url = `${ PAGE_URL }&tab=profiles&profile=${ profile.id }`;

			return {
				id: String( profile.id ),
				revision: '',
				cells: { name: { label: profile.name, url }, properties: profile.properties.join( ' · ' ) || 'Не настроен', used_in: profile.used_in.join( ', ' ) || '—', actions: null },
				actions: listActions( url ),
				selectable: false,
				has_children: false,
			};
		} );
	}

	return state.mappings.map( ( mapping ) => {
		const url = `${ PAGE_URL }&mapping=${ mapping.id }`;
		const profile = state.profiles.find( ( entry ) => entry.id === mapping.profile_id );

		return {
			id: String( mapping.id ),
			revision: '',
			cells: {
				name: { label: mapping.name, url },
				category: mapping.category_label,
				shop: mapping.terms.map( ( term ) => state.shop_categories.find( ( entry ) => entry.id === term )?.label ).join( ', ' ),
				profile: profile ? { label: profile.name, url: `${ PAGE_URL }&tab=profiles&profile=${ profile.id }` } : null,
				attributes: ATTRIBUTES,
				products: mapping.products,
				actions: null,
			},
			actions: listActions( url ),
			selectable: false,
			has_children: false,
		};
	} );
}

/** What the records REST answers for the two list tables (`/definition` and the list read). */
function recordsAnswer( path: string, state: MappingsState ) {
	const route = decodeURIComponent( path ).replace( RECORDS, '' ).split( '?' )[ 0 ];
	const table = route.startsWith( 'profiles' ) ? 'profiles' : 'mappings';

	return route.endsWith( '/definition' )
		? fixtureDefinitionEnvelope( listDefinition( table ), '' )
		: fixtureList( listRows( state, table ), { per_page: 20 } );
}

const ROWS = [
	{ id: '1', name: 'Бренд', required: true, type: 'string', dictionary: false, multi: false, unit: null, source: { source: '', value: '', key: '', dictionary_value_id: '' } },
	{ id: '2', name: 'Цвет', required: false, type: 'dictionary', dictionary: true, multi: false, unit: null, source: { source: '', value: '', key: '', dictionary_value_id: '' } },
];

const PAGE = { id: 'mappings', title: 'Сопоставление', path: '/ozon/mappings', kind: 'custom', tables: [], view: 'mappings', breadcrumbs: [], analytics: null } as ManifestPage;
const MENU = { id: 'ozon', pluginId: 'ozon', title: 'Ozon', domId: 'x', pages: [ PAGE ] } as ManifestMenu;

/** A promise the test settles by hand, to answer requests out of order. */
function deferred< T >() {
	let resolve!: ( value: T ) => void;
	const promise = new Promise< T >( ( done ) => {
		resolve = done;
	} );

	return { promise, resolve };
}

type Call = { path: string; method?: string; data?: Record< string, unknown > };

const mocked = apiFetch as unknown as jest.Mock;

/** Answers every REST call by `METHOD path` and remembers it. */
function serve( answers: Record< string, unknown > ): Call[] {
	const calls: Call[] = [];

	mocked.mockImplementation( ( options: Call ) => {
		calls.push( options );

		if ( options.path.startsWith( RECORDS ) ) {
			const state = answers[ 'GET ' ];

			return Promise.resolve( recordsAnswer( options.path, state instanceof Error || state === undefined ? STATE : ( state as MappingsState ) ) );
		}

		const key = `${ options.method ?? 'GET' } ${ options.path.replace( BASE, '' ) }`;
		const answer = answers[ key ];

		if ( answer === undefined ) {
			return Promise.reject( new Error( `unexpected ${ key }` ) );
		}

		return typeof answer === 'function' ? new Promise( ( resolve ) => resolve( ( answer as () => unknown )() ) ) : answer instanceof Error ? Promise.reject( answer ) : Promise.resolve( answer );
	} );

	return calls;
}

/** WooCommerce's `TableCard` as a double: the title, the header slot and the rows it is given. */
function FakeTableCard( props: TableCardProps & { actions?: React.ReactNode[] } ) {
	return (
		<div data-testid="table-card" className={ props.className }>
			<h2>{ props.title }</h2>
			<div>{ props.actions }</div>
			{ /* No table while the list is loading, like the real card: the table role means "the list is here" (#1892). */ }
			{ ! props.isLoading && (
				<table aria-label={ props.title }>
					<thead>
						<tr>
							{ props.headers.map( ( header ) => (
								<th key={ header.key } className={ header.cellClassName }>
									{ header.label }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ props.rows.map( ( row, index ) => (
							<tr key={ props.rowKey( row, index ) }>
								{ row.map( ( cell, column ) => (
									<td key={ column }>{ cell.display }</td>
								) ) }
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}

type Query = Record< string, string >;

function openView( query: Query = {} ) {
	return render( <MappingsView page={ PAGE } menu={ MENU } query={ query } /> );
}

/**
 * Opens the view the way wc-admin does: the URL's query is the page's `query`, and `wc.navigation` rewrites it and
 * renders the page again. Returns the query history, to see what the page wrote.
 */
function openViewWithUrl( start: Query = {} ) {
	const history: Query[] = [ start ];
	const paths: Array< Record< string, string | undefined > > = [];
	let view: ReturnType< typeof render >;
	const show = ( query: Query ) => <MappingsView page={ PAGE } menu={ MENU } query={ query } />;

	const apply = ( patch: Record< string, string | undefined >, replace: boolean ) => {
		const next: Query = { ...history[ history.length - 1 ] };
		Object.entries( patch ).forEach( ( [ key, value ] ) => {
			if ( value === undefined ) {
				delete next[ key ];
			} else {
				next[ key ] = value;
			}
		} );
		if ( replace ) {
			history[ history.length - 1 ] = next;
		} else {
			history.push( next );
		}
		act( () => view.rerender( show( next ) ) );
	};

	( window as unknown as { wc: Record< string, unknown > } ).wc.navigation = {
		updateQueryString: ( patch: Record< string, string | undefined > ) => apply( patch, false ),
		// wc-admin's way to rewrite the current entry: the path carries the patch (an `undefined` removes a key), the history applies it.
		getNewPath: ( patch: Record< string, string | undefined > ) => {
			paths.push( patch );

			return `/path/${ paths.length - 1 }`;
		},
		getHistory: () => ( { replace: ( path: string ) => apply( paths[ Number( path.split( '/' ).pop() ) ], true ) } ),
	};
	view = render( show( start ) );

	// An async act: the list that comes back reads its table at once, and those reads settle inside it.
	return { history, back: () => act( async () => view.rerender( show( history[ history.length - 2 ] ) ) ) };
}

/** Opens the shop-category dropdown and returns its option of that name. */
async function shopCategoryOption( name: string | RegExp ) {
	await userEvent.click( screen.getByRole( 'button', { name: /Не выбрано|Одежда|Обувь/ } ) );

	return screen.findByRole( 'option', { name } );
}

beforeEach( () => {
	mocked.mockReset();
	( window as unknown as { wc: unknown } ).wc = { components: { TableCard: FakeTableCard } };
} );

afterEach( () => {
	delete ( window as unknown as { wc?: unknown } ).wc;
} );

describe( 'registration', () => {
	test( 'the view answers for the mappings page and for no other', () => {
		const other = { ...PAGE, view: 'tools' } as ManifestPage;

		expect( applyFilters( PAGE_VIEW_FILTER, null, PAGE, MENU ) ).toBe( MappingsView );
		expect( applyFilters( PAGE_VIEW_FILTER, null, other, MENU ) ).toBeNull();
		expect( applyFilters( PAGE_VIEW_FILTER, null, { ...PAGE, kind: 'records' }, MENU ) ).toBeNull();
	} );
} );

describe( 'lists', () => {
	test( 'shows the mappings and profiles PHP sent in records tables, with the section description and the add button in the card header', async () => {
		serve( { 'GET ': STATE } );
		openView();

		const mappings = await screen.findByRole( 'table', { name: 'Сопоставления' } );
		expect( within( mappings ).getByRole( 'link', { name: 'Платья' } ) ).toBeInTheDocument();
		expect( within( mappings ).getByText( 'Одежда › Платья' ) ).toBeInTheDocument();
		expect( within( mappings ).getByText( 'Одежда' ) ).toBeInTheDocument();
		expect( within( mappings ).getByRole( 'link', { name: 'Основной' } ) ).toBeInTheDocument();
		expect( within( mappings ).getByText( '12' ) ).toBeInTheDocument();
		expect( within( mappings ).getByText( '1 из 3 !' ) ).toBeInTheDocument();
		expect( within( mappings ).getByRole( 'columnheader', { name: /Действия/ } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Добавить сопоставление' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'На этой странице настраивается соответствие категорий.' ) ).toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'tab', { name: 'Профили' } ) );
		const profiles = await screen.findByRole( 'table', { name: 'Профили' } );
		expect( within( profiles ).getByRole( 'link', { name: 'Основной' } ) ).toBeInTheDocument();
		expect( within( profiles ).getByText( 'Вес: 300 г · НДС: 20%' ) ).toBeInTheDocument();
		expect( within( profiles ).getByText( 'Платья' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Добавить профиль' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'На этой странице хранятся профили выгрузки.' ) ).toBeInTheDocument();
		// Both lists stand in the wrapper that keeps a table off the page's edge.
		expect( profiles.closest( '.woodev-marketplace-table' ) ).not.toBeNull();
	} );

	test( 'the row buttons are the engine\'s icon group, named by their label: «Редактировать» is a real link, «Удалить» a button', async () => {
		serve( { 'GET ': STATE } );
		openView();

		const mappings = await screen.findByRole( 'table', { name: 'Сопоставления' } );
		const edit = within( mappings ).getByRole( 'link', { name: 'Редактировать' } );
		const remove = within( mappings ).getByRole( 'button', { name: 'Удалить' } );

		expect( edit ).toHaveAttribute( 'href', `${ PAGE_URL }&mapping=1` );
		expect( edit ).toHaveTextContent( '' );
		expect( edit.querySelector( '.dashicons-edit' ) ).not.toBeNull();
		expect( remove.querySelector( '.dashicons-trash' ) ).not.toBeNull();
	} );

	test( 'the profile of a mapping is a link to that profile on the other tab', async () => {
		serve( { 'GET ': STATE, 'GET /profiles/3': { id: 3, name: 'Основной', values: { offer_id_source: 'sku', currency_kind: 'account', price_override: false } } } );
		const { history } = openViewWithUrl();

		const mappings = await screen.findByRole( 'table', { name: 'Сопоставления' } );
		await userEvent.click( within( mappings ).getByRole( 'link', { name: 'Основной' } ) );

		expect( history[ history.length - 1 ] ).toEqual( { tab: 'profiles', profile: '3' } );
		expect( await screen.findByLabelText( 'Название профиля' ) ).toHaveValue( 'Основной' );
	} );

	test( 'a click with a modifier key is left to the browser: the link opens in a tab of its own', async () => {
		serve( { 'GET ': STATE } );
		const { history } = openViewWithUrl();
		// What the browser would do with the click that reaches the document unhandled; jsdom cannot navigate.
		const reached: boolean[] = [];
		const stop = ( event: Event ) => {
			reached.push( ! event.defaultPrevented );
			event.preventDefault();
		};
		document.addEventListener( 'click', stop );

		const mappings = await screen.findByRole( 'table', { name: 'Сопоставления' } );
		await act( async () => {
			fireEvent.click( within( mappings ).getByRole( 'link', { name: 'Платья' } ), { ctrlKey: true } );
		} );
		document.removeEventListener( 'click', stop );

		expect( reached ).toEqual( [ true ] );
		expect( history ).toHaveLength( 1 );
	} );

	test( 'a mapping is deleted from the list in the shared dialog, and only its confirming button deletes', async () => {
		const calls = serve( { 'GET ': STATE, 'DELETE /mappings/1': { id: 1, notices: [] } } );
		openView();

		const mappings = await screen.findByRole( 'table', { name: 'Сопоставления' } );
		await userEvent.click( within( mappings ).getByRole( 'button', { name: 'Удалить' } ) );

		const dialog = await screen.findByRole( 'dialog', { name: 'Удалить сопоставление?' } );
		expect( within( dialog ).getByText( 'Сопоставление «Платья» будет удалено.' ) ).toBeInTheDocument();
		expect( calls.some( ( call ) => call.method === 'DELETE' ) ).toBe( false );

		await userEvent.click( within( dialog ).getByRole( 'button', { name: 'Да, удалить' } ) );

		expect( ( await screen.findAllByText( 'Сопоставление удалено.' ) ).length ).toBeGreaterThan( 0 );
		expect( calls.filter( ( call ) => call.method === 'DELETE' ) ).toHaveLength( 1 );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );

	test( 'once the delete is shown the table is read again', async () => {
		const calls = serve( { 'GET ': STATE, 'DELETE /mappings/1': { id: 1, notices: [] } } );
		openView();

		await userEvent.click( within( await screen.findByRole( 'table', { name: 'Сопоставления' } ) ).getByRole( 'button', { name: 'Удалить' } ) );
		const reads = () => calls.filter( ( call ) => call.path.startsWith( `${ RECORDS }mappings` ) && ! call.path.includes( '/definition' ) ).length;
		const before = reads();
		await userEvent.click( within( await screen.findByRole( 'dialog', { name: 'Удалить сопоставление?' } ) ).getByRole( 'button', { name: 'Да, удалить' } ) );

		await waitFor( () => expect( reads() ).toBeGreaterThan( before ) );
	} );

	test( 'a profile some mapping chooses cannot be deleted: the dialog lists those mappings and offers only «Закрыть»', async () => {
		const calls = serve( { 'GET ': STATE } );
		openView();

		await userEvent.click( await screen.findByRole( 'tab', { name: 'Профили' } ) );
		const profiles = await screen.findByRole( 'table', { name: 'Профили' } );
		await userEvent.click( within( profiles ).getByRole( 'button', { name: 'Удалить' } ) );

		const dialog = await screen.findByRole( 'dialog', { name: 'Профиль «Основной» используется' } );
		expect( within( dialog ).getByText( 'Платья' ) ).toBeInTheDocument();
		expect( within( dialog ).queryByRole( 'button', { name: 'Да, удалить' } ) ).not.toBeInTheDocument();

		// The footer's button (the header's «✕» carries the same name).
		const footer = dialog.querySelector( '.woodev-dialog__footer' ) as HTMLElement;
		expect( within( footer ).getAllByRole( 'button' ) ).toHaveLength( 1 );
		await userEvent.click( within( footer ).getByRole( 'button', { name: 'Закрыть' } ) );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
		expect( calls.some( ( call ) => call.method === 'DELETE' ) ).toBe( false );
	} );

	test( 'a profile nothing uses is deleted from the list after the dialog confirms', async () => {
		const free = { ...STATE, profiles: [ { id: 4, name: 'Запасной', mappings: 0, properties: [], used_in: [] } ] };
		const calls = serve( { 'GET ': free, 'DELETE /profiles/4': { id: 4, notices: [] } } );
		openView();

		await userEvent.click( await screen.findByRole( 'tab', { name: 'Профили' } ) );
		const profiles = await screen.findByRole( 'table', { name: 'Профили' } );
		expect( within( profiles ).getByText( 'Не настроен' ) ).toBeInTheDocument();
		await userEvent.click( within( profiles ).getByRole( 'button', { name: 'Удалить' } ) );
		await userEvent.click( within( await screen.findByRole( 'dialog', { name: 'Удалить профиль?' } ) ).getByRole( 'button', { name: 'Да, удалить' } ) );

		expect( ( await screen.findAllByText( 'Профиль удалён.' ) ).length ).toBeGreaterThan( 0 );
		expect( calls.filter( ( call ) => call.method === 'DELETE' ) ).toHaveLength( 1 );
	} );

	test( 'the category search is a search box (it has the magnifier), not a bare text field', async () => {
		serve( { 'GET ': STATE } );
		openView();

		await userEvent.click( await screen.findByRole( 'button', { name: 'Добавить сопоставление' } ) );

		expect( await screen.findByRole( 'searchbox', { name: 'Категория маркетплейса' } ) ).toBeInTheDocument();
	} );

	test( 'a list with no rows is the PHP explanation and a button that creates the first one, with the add button still in the header', async () => {
		serve( { 'GET ': { ...STATE, mappings: [], profiles: [] } } );
		openView();

		expect( await screen.findByText( 'Сопоставлений пока нет' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Создать первое сопоставление' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Добавить сопоставление' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'tab', { name: 'Профили' } ) );
		expect( await screen.findByText( 'Профилей пока нет' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Создать первый профиль' } ) ).toBeInTheDocument();
	} );

	test( 'a shop with no product categories is sent to create one before the first mapping', async () => {
		serve( { 'GET ': { ...STATE, mappings: [], shop_categories: [] } } );
		openView();

		expect( await screen.findByText( 'Сопоставлений пока нет' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Создать категорию товаров' } ) ).toHaveAttribute( 'href', 'edit-tags.php?taxonomy=product_cat&post_type=product' );
		expect( screen.queryByRole( 'button', { name: 'Создать первое сопоставление' } ) ).not.toBeInTheDocument();
	} );

	test( 'the list is replaced by the form and brought back through the URL: Back, Forward and a bookmark work', async () => {
		serve( {
			'GET ': STATE,
			'GET /mappings/1': { id: 1, name: 'Платья', terms: [ 5 ], profile_id: null, category: null, attributes: [] },
		} );
		const { history, back } = openViewWithUrl();

		await userEvent.click( await screen.findByRole( 'link', { name: 'Платья' } ) );
		expect( history[ history.length - 1 ] ).toEqual( { mapping: '1' } );
		const nameField = await screen.findByLabelText( 'Название сопоставления' );
		expect( nameField ).toHaveValue( 'Платья' );
		expect( nameField ).toHaveAttribute( 'placeholder', 'Например: Футболки' );
		expect( screen.queryByRole( 'table', { name: 'Сопоставления' } ) ).not.toBeInTheDocument();

		await back();
		expect( await screen.findByRole( 'link', { name: 'Платья' } ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Название сопоставления' ) ).not.toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'button', { name: 'Добавить сопоставление' } ) );
		expect( history[ history.length - 1 ] ).toEqual( { mapping: 'new' } );
		expect( await screen.findByText( 'Новое сопоставление' ) ).toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'button', { name: '← Все сопоставления' } ) );
		expect( history[ history.length - 1 ] ).toEqual( {} );
		expect( await screen.findByRole( 'link', { name: 'Платья' } ) ).toBeInTheDocument();
	} );

	test( 'a bookmarked address opens the form, and the profiles tab and its form, straight away', async () => {
		serve( {
			'GET ': STATE,
			'GET /mappings/1': { id: 1, name: 'Платья', terms: [ 5 ], profile_id: null, category: null, attributes: [] },
			'GET /profiles/3': { id: 3, name: 'Основной', values: { offer_id_source: 'sku', currency_kind: 'account', price_override: false } },
		} );
		openViewWithUrl( { mapping: '1' } );

		expect( await screen.findByLabelText( 'Название сопоставления' ) ).toHaveValue( 'Платья' );
		cleanup();

		openViewWithUrl( { tab: 'profiles', profile: '3' } );
		expect( await screen.findByLabelText( 'Название профиля' ) ).toHaveValue( 'Основной' );
		expect( screen.getByRole( 'tab', { name: 'Профили' } ) ).toHaveAttribute( 'aria-selected', 'true' );
	} );

	test( 'the other tab starts from its list, and a nonsense address shows the list', async () => {
		serve( { 'GET ': STATE } );
		const { history } = openViewWithUrl( { mapping: 'abc', profile: '-4' } );

		expect( await screen.findByRole( 'table', { name: 'Сопоставления' } ) ).toBeInTheDocument();
		await userEvent.click( screen.getByRole( 'tab', { name: 'Профили' } ) );
		expect( history[ history.length - 1 ] ).toEqual( { tab: 'profiles' } );
	} );

	test( 'the other tab starts from its own first page: the left list\'s page, sort and size leave the URL', async () => {
		serve( { 'GET ': STATE } );
		const { history } = openViewWithUrl( { paged: '2', orderby: 'name', order: 'desc', per_page: '50' } );

		await screen.findByRole( 'link', { name: 'Платья' } );
		await userEvent.click( screen.getByRole( 'tab', { name: 'Профили' } ) );

		expect( history[ history.length - 1 ] ).toEqual( { tab: 'profiles' } );
		await screen.findByRole( 'link', { name: 'Основной' } );
	} );

	test( 'the tab list is named after the page', async () => {
		serve( { 'GET ': STATE } );
		openView();

		expect( await screen.findByRole( 'tablist', { name: 'Сопоставление' } ) ).toBeInTheDocument();
	} );

	test( 'a failed first load shows the server sentence and nothing else', async () => {
		serve( { 'GET ': Object.assign( new Error( 'Не удалось получить данные маркетплейса: нет связи' ), { data: { errors: [] } } ) } );
		openView();

		expect( ( await screen.findAllByText( /нет связи/ ) ).length ).toBeGreaterThan( 0 );
		expect( screen.queryByRole( 'tab' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'mapping editor', () => {
	test( 'a category another mapping holds is listed with the holder, and ticking it changes nothing', async () => {
		const calls = serve( { 'GET ': STATE, 'POST /mappings': { id: 9, notices: [] } } );
		openView();
		await userEvent.click( await screen.findByRole( 'button', { name: 'Добавить сопоставление' } ) );

		const taken = await shopCategoryOption( /Одежда — Уже входит в сопоставление: Платья/ );
		expect( taken ).toHaveAttribute( 'aria-selected', 'false' );
		expect( screen.getByRole( 'option', { name: 'Обувь' } ) ).toBeInTheDocument();

		await userEvent.click( taken );
		expect( taken ).toHaveAttribute( 'aria-selected', 'false' );

		await userEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );
		await waitFor( () => expect( calls.some( ( call ) => call.method === 'POST' ) ).toBe( true ) );
		expect( calls.find( ( call ) => call.method === 'POST' )?.data ).toMatchObject( { terms: [] } );
	} );

	test( 'the mapping form is a card of titled groups, with the help under the shop categories', async () => {
		serve( { 'GET ': STATE } );
		openView();
		await userEvent.click( await screen.findByRole( 'button', { name: 'Добавить сопоставление' } ) );

		expect( await screen.findByRole( 'heading', { name: 'Новое сопоставление' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'group', { name: 'Категории' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'group', { name: 'Характеристики' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Одна категория — одно сопоставление' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Сделать копию' } ) ).not.toBeInTheDocument();
	} );

	test( 'a saved mapping shows its name as the title and its product check in a card of its own', async () => {
		serve( {
			'GET ': STATE,
			'GET /mappings/1': { id: 1, name: 'Платья', terms: [ 5 ], profile_id: null, category: null, attributes: [] },
		} );
		openView();
		await userEvent.click( await screen.findByRole( 'link', { name: 'Платья' } ) );

		expect( await screen.findByRole( 'heading', { name: '«Платья»' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Проверка товаров' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Сделать копию' } ) ).toBeInTheDocument();
	} );

	test( 'creates a mapping: search, pick the category, fill a row, tick a shop category, save', async () => {
		const calls = serve( {
			'GET ': STATE,
			'GET /categories?search=%D0%BF%D0%BB': { results: [ { key: 'k1', label: 'Одежда › Платья' } ] },
			'GET /fields?category=k1': { attributes: ROWS },
			'POST /mappings': { id: 9, notices: [] },
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'button', { name: 'Добавить сопоставление' } ) );
		await userEvent.type( screen.getByLabelText( 'Название сопоставления' ), 'Новое' );
		await userEvent.click( await shopCategoryOption( 'Обувь' ) );
		await userEvent.type( screen.getByLabelText( 'Категория маркетплейса' ), 'пл' );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Одежда › Платья' } ) );

		const table = await screen.findByRole( 'table' );
		expect( within( table ).getAllByText( /Бренд/ ).length ).toBeGreaterThan( 0 );
		expect( within( table ).getByText( /обязательная/ ) ).toBeInTheDocument();

		await userEvent.selectOptions( within( table ).getByLabelText( 'Бренд: Откуда брать' ), 'fixed' );
		await userEvent.type( within( table ).getByLabelText( 'Бренд: Значение' ), 'Zara' );
		await userEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );

		expect( ( await screen.findAllByText( 'Сопоставление сохранено.' ) ).length ).toBeGreaterThan( 0 );

		const save = calls.find( ( call ) => call.method === 'POST' );
		expect( save?.path ).toBe( `${ BASE }/mappings` );
		expect( save?.data ).toEqual( {
			name: 'Новое',
			terms: [ 6 ],
			category: 'k1',
			profile_id: null,
			attributes: { 1: { source: 'fixed', value: 'Zara', key: '', dictionary_value_id: '' } },
				attributes_category: 'k1',
			} );
	} );

	test( 'a dictionary row stores the chosen value id, not the typed text', async () => {
		const calls = serve( {
			'GET ': STATE,
			'GET /mappings/1': {
				id: 1,
				name: 'Платья',
				terms: [ 5 ],
				profile_id: 3,
				category: { key: 'k1', label: 'Одежда › Платья', available: true },
				attributes: ROWS,
			},
			'GET /dictionary?category=k1&field=2&search=%D0%BA%D1%80': { results: [ { id: 7, text: 'красный — тёплый', name: 'красный' } ] },
			'POST /mappings/1': { id: 1, notices: [] },
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'link', { name: 'Платья' } ) );
		const table = await screen.findByRole( 'table' );
		await userEvent.selectOptions( within( table ).getByLabelText( 'Цвет: Откуда брать' ), 'fixed' );
		await userEvent.type( within( table ).getByLabelText( 'Цвет: Значение' ), 'кр' );
		await userEvent.click( await screen.findByRole( 'button', { name: 'красный — тёплый' } ) );
		await userEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );

		await waitFor( () => expect( calls.some( ( call ) => call.method === 'POST' ) ).toBe( true ) );
		const body = calls.find( ( call ) => call.method === 'POST' )?.data as { attributes: Record< string, unknown >; terms: number[] };
		expect( body.attributes[ '2' ] ).toEqual( { source: 'fixed', value: 'красный', key: '', dictionary_value_id: '7' } );
		expect( body.terms ).toEqual( [ 5 ] );
	} );

	test( 'validation sentences from the server are shown as they came', async () => {
		serve( {
			'GET ': STATE,
			'GET /mappings/1': { id: 1, name: 'Платья', terms: [ 5 ], profile_id: null, category: null, attributes: [] },
			'POST /mappings/1': Object.assign( new Error( 'x' ), { data: { status: 422, errors: [ 'Введите название сопоставления', 'Категория занята' ] } } ),
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'link', { name: 'Платья' } ) );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Сохранить' } ) );

		expect( await screen.findByText( 'Введите название сопоставления' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Категория занята' ) ).toBeInTheDocument();
	} );

	test( 'a save before the table loaded sends no table', async () => {
		const calls = serve( {
			'GET ': STATE,
			'GET /mappings/1': {
				id: 1,
				name: 'Платья',
				terms: [ 5 ],
				profile_id: null,
				category: { key: 'k1', label: 'Одежда › Платья', available: true },
				attributes: ROWS,
			},
			'GET /categories?search=%D0%BE%D0%B1': { results: [ { key: 'k2', label: 'Обувь › Ботинки' } ] },
			'GET /fields?category=k2&mapping_id=1': () => new Promise( () => undefined ),
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'link', { name: 'Платья' } ) );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Выбрать другую' } ) );
		await userEvent.type( screen.getByLabelText( 'Категория маркетплейса' ), 'об' );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Обувь › Ботинки' } ) );

		expect( screen.getByText( 'Загружаем характеристики…' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Сохранить' } ) ).toBeDisabled();
		expect( calls.some( ( call ) => call.method === 'POST' ) ).toBe( false );
	} );

	test( 'a mapping that could not be read offers only a retry, never a blank form to save over it', async () => {
		let attempts = 0;
		const calls = serve( {
			'GET ': STATE,
			'GET /mappings/1': () => {
				attempts += 1;

				if ( attempts === 1 ) {
					throw Object.assign( new Error( 'x' ), { data: { errors: [ 'Маркетплейс не отвечает' ] } } );
				}

				return { id: 1, name: 'Платья', terms: [ 5 ], profile_id: null, category: null, attributes: [] };
			},
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'link', { name: 'Платья' } ) );

		expect( ( await screen.findAllByText( 'Маркетплейс не отвечает' ) ).length ).toBeGreaterThan( 0 );
		expect( screen.queryByRole( 'button', { name: 'Сохранить' } ) ).not.toBeInTheDocument();
		expect( screen.queryByLabelText( 'Название сопоставления' ) ).not.toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'button', { name: 'Повторить' } ) );

		expect( await screen.findByLabelText( 'Название сопоставления' ) ).toHaveValue( 'Платья' );
		expect( screen.getByRole( 'button', { name: 'Сохранить' } ) ).toBeEnabled();
		expect( calls.some( ( call ) => call.method === 'POST' ) ).toBe( false );
	} );

	test( 'a late field response of an earlier category never replaces the table of the current one', async () => {
		const first = deferred< { attributes: typeof ROWS } >();
		const second = deferred< { attributes: typeof ROWS } >();
		const calls = serve( {
			'GET ': STATE,
			'GET /categories?search=%D0%BF%D0%BB': { results: [ { key: 'kA', label: 'Одежда › Платья' } ] },
			'GET /categories?search=%D0%BE%D0%B1': { results: [ { key: 'kB', label: 'Обувь › Ботинки' } ] },
			'GET /fields?category=kA': () => first.promise,
			'GET /fields?category=kB': () => second.promise,
			'POST /mappings': { id: 9, notices: [] },
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'button', { name: 'Добавить сопоставление' } ) );
		await userEvent.type( screen.getByLabelText( 'Название сопоставления' ), 'Новое' );
		await userEvent.type( screen.getByLabelText( 'Категория маркетплейса' ), 'пл' );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Одежда › Платья' } ) );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Выбрать другую' } ) );
		await userEvent.type( screen.getByLabelText( 'Категория маркетплейса' ), 'об' );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Обувь › Ботинки' } ) );

		await act( async () => {
			second.resolve( { attributes: [ ROWS[ 0 ] ] } );
		} );
		await act( async () => {
			first.resolve( { attributes: [ { ...ROWS[ 1 ], id: '9', name: 'Чужая' } ] } );
		} );

		const table = await screen.findByRole( 'table' );
		expect( within( table ).getAllByText( /Бренд/ ).length ).toBeGreaterThan( 0 );
		expect( within( table ).queryByText( /Чужая/ ) ).not.toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );
		await waitFor( () => expect( calls.some( ( call ) => call.method === 'POST' ) ).toBe( true ) );
		expect( calls.find( ( call ) => call.method === 'POST' )?.data ).toMatchObject( { category: 'kB', attributes_category: 'kB' } );
	} );

	test( 'a failed field load leaves the save disabled until a table of the category arrives', async () => {
		const calls = serve( {
			'GET ': STATE,
			'GET /categories?search=%D0%BF%D0%BB': { results: [ { key: 'kA', label: 'Одежда › Платья' } ] },
			'GET /fields?category=kA': Object.assign( new Error( 'x' ), { data: { errors: [ 'Характеристики недоступны' ] } } ),
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'button', { name: 'Добавить сопоставление' } ) );
		await userEvent.type( screen.getByLabelText( 'Категория маркетплейса' ), 'пл' );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Одежда › Платья' } ) );

		expect( ( await screen.findAllByText( 'Характеристики недоступны' ) ).length ).toBeGreaterThan( 0 );
		expect( screen.getByRole( 'button', { name: 'Сохранить' } ) ).toBeDisabled();
		expect( calls.some( ( call ) => call.method === 'POST' ) ).toBe( false );
	} );

	test( 'a failed category search shows its reason, not the empty-results sentence, and a new search clears it', async () => {
		serve( {
			'GET ': STATE,
			'GET /categories?search=%D0%BF%D0%BB': Object.assign( new Error( 'x' ), { data: { errors: [ 'Ключ API отклонён' ] } } ),
			'GET /categories?search=%D0%BF%D0%BB%D0%B0': { results: [] },
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'button', { name: 'Добавить сопоставление' } ) );
		await userEvent.type( screen.getByLabelText( 'Категория маркетплейса' ), 'пл' );

		expect( ( await screen.findAllByText( 'Ключ API отклонён' ) ).length ).toBeGreaterThan( 0 );
		expect( screen.queryByText( 'Ничего не найдено' ) ).not.toBeInTheDocument();

		await userEvent.type( screen.getByLabelText( 'Категория маркетплейса' ), 'а' );

		expect( await screen.findByText( 'Ничего не найдено' ) ).toBeInTheDocument();
		// The screen-reader announcement keeps the old sentence; the visible notice is what must be gone.
		expect( document.querySelector( '.components-notice' ) ).toBeNull();
	} );

	test( 'a failed dictionary search shows its reason, not the empty-results sentence', async () => {
		serve( {
			'GET ': STATE,
			'GET /mappings/1': {
				id: 1,
				name: 'Платья',
				terms: [ 5 ],
				profile_id: null,
				category: { key: 'k1', label: 'Одежда › Платья', available: true },
				attributes: ROWS,
			},
			'GET /dictionary?category=k1&field=2&search=%D0%BA%D1%80': Object.assign( new Error( 'x' ), { data: { errors: [ 'Словарь недоступен' ] } } ),
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'link', { name: 'Платья' } ) );
		const table = await screen.findByRole( 'table' );
		await userEvent.selectOptions( within( table ).getByLabelText( 'Цвет: Откуда брать' ), 'fixed' );
		await userEvent.type( within( table ).getByLabelText( 'Цвет: Значение' ), 'кр' );

		expect( ( await screen.findAllByText( 'Словарь недоступен' ) ).length ).toBeGreaterThan( 0 );
		expect( screen.queryByText( 'Ничего не найдено' ) ).not.toBeInTheDocument();
	} );

	test( 'deleting asks twice and only the second click deletes', async () => {
		const calls = serve( {
			'GET ': STATE,
			'GET /mappings/1': { id: 1, name: 'Платья', terms: [ 5 ], profile_id: null, category: null, attributes: [] },
			'DELETE /mappings/1': { id: 1, notices: [] },
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'link', { name: 'Платья' } ) );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Удалить' } ) );
		expect( calls.some( ( call ) => call.method === 'DELETE' ) ).toBe( false );

		await userEvent.click( screen.getByRole( 'button', { name: 'Да, удалить' } ) );
		expect( ( await screen.findAllByText( 'Сопоставление удалено.' ) ).length ).toBeGreaterThan( 0 );
		expect( calls.filter( ( call ) => call.method === 'DELETE' ) ).toHaveLength( 1 );
	} );

	test( 'a copy opens the copy: the URL moves to the new id, the copied sentence shows, the form reads mapping 2', async () => {
		const calls = serve( {
			'GET ': STATE,
			'GET /mappings/1': { id: 1, name: 'Платья', terms: [ 5 ], profile_id: null, category: null, attributes: [] },
			'POST /mappings/1/copy': { id: 2, notices: [] },
			'GET /mappings/2': { id: 2, name: 'Платья (копия)', terms: [], profile_id: null, category: null, attributes: [] },
		} );
		const { history } = openViewWithUrl( { mapping: '1' } );

		await userEvent.click( await screen.findByRole( 'button', { name: 'Сделать копию' } ) );

		expect( history[ history.length - 1 ] ).toEqual( { mapping: '2' } );
		expect( ( await screen.findAllByText( /Копия создана/ ) ).length ).toBeGreaterThan( 0 );
		expect( await screen.findByLabelText( 'Название сопоставления' ) ).toHaveValue( 'Платья (копия)' );
		expect( calls.filter( ( call ) => call.method === 'POST' ).map( ( call ) => call.path ) ).toEqual( [ `${ BASE }/mappings/1/copy` ] );
	} );

	test( 'saving a new mapping replaces «new» with its id in the history, so Back is the list', async () => {
		serve( {
			'GET ': STATE,
			'POST /mappings': { id: 9, notices: [] },
			'GET /mappings/9': { id: 9, name: 'Новое', terms: [], profile_id: null, category: null, attributes: [] },
		} );
		const { history } = openViewWithUrl();

		await userEvent.click( await screen.findByRole( 'button', { name: 'Добавить сопоставление' } ) );
		await userEvent.type( await screen.findByLabelText( 'Название сопоставления' ), 'Новое' );
		await userEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );

		expect( await screen.findByRole( 'heading', { name: '«Новое»' } ) ).toBeInTheDocument();
		expect( history ).toEqual( [ {}, { mapping: '9' } ] );
	} );

	test( 'saving a saved mapping and deleting it: the save adds nothing to the history, the delete leaves no way back to it', async () => {
		serve( {
			'GET ': STATE,
			'GET /mappings/1': { id: 1, name: 'Платья', terms: [ 5 ], profile_id: null, category: null, attributes: [] },
			'POST /mappings/1': { id: 1, notices: [] },
			'DELETE /mappings/1': { id: 1, notices: [] },
		} );
		const { history } = openViewWithUrl( { mapping: '1' } );

		await userEvent.click( await screen.findByRole( 'button', { name: 'Сохранить' } ) );
		await screen.findAllByText( 'Сопоставление сохранено.' );
		expect( history[ history.length - 1 ] ).toEqual( { mapping: '1' } );

		await userEvent.click( await screen.findByRole( 'button', { name: 'Удалить' } ) );
		await userEvent.click( screen.getByRole( 'button', { name: 'Да, удалить' } ) );
		expect( await screen.findByRole( 'table', { name: 'Сопоставления' } ) ).toBeInTheDocument();
		// The entry of the deleted mapping was rewritten into the list, so Back never reopens it.
		expect( history ).toEqual( [ {} ] );
	} );

	test( 'the product check runs batch after batch and prints the server text', async () => {
		let batch = 0;
		serve( {
			'GET ': STATE,
			'GET /mappings/1': { id: 1, name: 'Платья', terms: [ 5 ], profile_id: null, category: null, attributes: [] },
			'POST /mappings/1/check': () => {
				batch += 1;

				return batch === 1
					? { done: false, next_offset: 200, groups: [], text: 'Проверено 200 из 250…' }
					: { done: true, next_offset: 0, groups: [ { text: '3 товара: нет веса', count: 3 } ], text: 'Проверено товаров: 250, без замечаний: 247.' };
			},
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'link', { name: 'Платья' } ) );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Проверить товары' } ) );

		expect( await screen.findByText( 'Проверено товаров: 250, без замечаний: 247.' ) ).toBeInTheDocument();
		expect( screen.getByText( '3 товара: нет веса' ) ).toBeInTheDocument();
		expect( batch ).toBe( 2 );
	} );
} );

describe( 'article preview', () => {
	const SAVED = { id: 1, name: 'Платья', terms: [ 5 ], profile_id: null, category: null, attributes: [] };
	const WITH_PREVIEW = { ...STATE, article_preview: true };
	const SHIRT = {
		id: 10,
		name: 'Футболка',
		url: 'http://localhost/edit/10',
		articles: [
			{ article: 'tee-red', note: '', variations: 'Красный, S; Красный, M' },
			{ article: '', note: '', variations: 'Синий, S' },
		],
	};
	const CAP = { id: 20, name: 'Кепка', url: '', articles: [ { article: 'cap', note: '', variations: '' } ] };

	const openSaved = async () => {
		openView();
		await userEvent.click( await screen.findByRole( 'link', { name: 'Платья' } ) );
		await screen.findByRole( 'heading', { name: '«Платья»' } );
	};

	test( 'an adapter without a preview shows no card and is asked nothing', async () => {
		const calls = serve( { 'GET ': STATE, 'GET /mappings/1': SAVED } );
		await openSaved();

		expect( screen.queryByRole( 'heading', { name: 'Артикулы новых карточек' } ) ).not.toBeInTheDocument();
		expect( calls.some( ( call ) => call.path.includes( '/articles' ) ) ).toBe( false );
	} );

	const FIRST = 'GET /mappings/1/articles?offset=0&skip=0';
	const reads = ( calls: Call[] ) => calls.filter( ( call ) => call.path.includes( '/articles' ) );

	test( 'opening a mapping makes one read, even when it finds nothing; the rest of the shop is read on demand', async () => {
		const calls = serve( {
			'GET ': WITH_PREVIEW,
			'GET /mappings/1': SAVED,
			[ FIRST ]: { products: [], next: { offset: 100, skip: 0 } },
			'GET /mappings/1/articles?offset=100&skip=0': { products: [ SHIRT ], next: { offset: 100, skip: 2 } },
		} );
		await openSaved();

		expect( await screen.findByText( 'Среди проверенных новые карточки не нужны' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Новые карточки не нужны' ) ).not.toBeInTheDocument();
		expect( reads( calls ) ).toHaveLength( 1 );

		await userEvent.click( screen.getByRole( 'button', { name: 'Показать ещё' } ) );

		const table = await screen.findByRole( 'table' );
		expect( within( table ).getByRole( 'link', { name: 'Футболка' } ) ).toHaveAttribute( 'href', 'http://localhost/edit/10' );
		expect( within( table ).getByText( 'tee-red' ).closest( 'tr' ) ).toHaveTextContent( 'Красный, S; Красный, M' );
		expect( within( table ).getByText( '—' ).closest( 'tr' ) ).toHaveTextContent( 'Синий, S' );
		expect( screen.getByText( 'Прочерк — артикул не получается' ) ).toBeInTheDocument();
		expect( reads( calls ) ).toHaveLength( 2 );
	} );

	test( 'a product that continues in the next read stays one row group, its variations added to their lines', async () => {
		serve( {
			'GET ': WITH_PREVIEW,
			'GET /mappings/1': SAVED,
			[ FIRST ]: { products: [ SHIRT ], next: { offset: 0, skip: 100 } },
			'GET /mappings/1/articles?offset=0&skip=100': {
				products: [
					{
						...SHIRT,
						articles: [
							{ article: 'tee-red', note: '', variations: 'Красный, L' },
							{ article: 'tee-green', note: '', variations: 'Зелёный, S' },
						],
					},
					CAP,
				],
				next: null,
			},
		} );
		await openSaved();

		await userEvent.click( await screen.findByRole( 'button', { name: 'Показать ещё' } ) );

		expect( await screen.findByText( 'cap' ) ).toBeInTheDocument();
		expect( screen.getAllByRole( 'link', { name: 'Футболка' } ) ).toHaveLength( 1 );
		expect( screen.getAllByText( 'tee-red' ) ).toHaveLength( 1 );
		expect( screen.getByText( 'tee-red' ).closest( 'tr' ) ).toHaveTextContent( 'Красный, S; Красный, M; Красный, L' );
		expect( screen.getByText( 'tee-green' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Показать ещё' } ) ).not.toBeInTheDocument();
	} );

	test( 'the reason of a line stands under its article, one article may have two lines, and an explained dash needs no hint', async () => {
		const own = {
			...CAP,
			articles: [
				{ article: 'cap', note: 'Для: «Основной»', variations: '' },
				{ article: 'cap', note: 'Для: «Второй». Артикул вписан в самом товаре', variations: '' },
				{ article: '', note: 'Сопоставление не найдено', variations: '' },
			],
		};
		serve( { 'GET ': WITH_PREVIEW, 'GET /mappings/1': SAVED, 'GET /mappings/1/articles?offset=0&skip=0': { products: [ own ], next: null } } );
		await openSaved();

		const table = await screen.findByRole( 'table' );
		expect( within( table ).getAllByText( 'cap' ) ).toHaveLength( 2 );
		expect( within( table ).getByText( 'Для: «Второй». Артикул вписан в самом товаре' ).closest( 'td' ) ).toHaveTextContent( 'cap' );
		expect( within( table ).getByText( 'Сопоставление не найдено' ).closest( 'td' ) ).toHaveTextContent( '—' );
		expect( screen.queryByText( 'Прочерк — артикул не получается' ) ).not.toBeInTheDocument();
	} );

	test( 'a mapping with nothing to create says so', async () => {
		serve( { 'GET ': WITH_PREVIEW, 'GET /mappings/1': SAVED, 'GET /mappings/1/articles?offset=0&skip=0': { products: [], next: null } } );
		await openSaved();

		expect( await screen.findByText( 'Новые карточки не нужны' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
	} );

	test( 'a failed read shows the server sentence and can be repeated; a save reads again', async () => {
		let answer: () => unknown = () => {
			throw new Error( 'Каталог не загружен.' );
		};
		serve( {
			'GET ': WITH_PREVIEW,
			'GET /mappings/1': SAVED,
			'GET /mappings/1/articles?offset=0&skip=0': () => answer(),
			'POST /mappings/1': { id: 1, notices: [] },
		} );
		await openSaved();

		expect( await screen.findAllByText( 'Каталог не загружен.' ) ).not.toHaveLength( 0 );
		expect( screen.queryByText( 'Новые карточки не нужны' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Среди проверенных новые карточки не нужны' ) ).not.toBeInTheDocument();

		answer = () => ( { products: [ CAP ], next: null } );
		await userEvent.click( screen.getByRole( 'button', { name: 'Повторить' } ) );
		expect( await screen.findByText( 'cap' ) ).toBeInTheDocument();

		answer = () => ( { products: [ { ...CAP, articles: [ { article: 'hat', note: '', variations: '' } ] } ], next: null } );
		await userEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );
		expect( await screen.findByText( 'hat' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'cap' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'profile editor', () => {
	test( 'a profile that could not be read offers only a retry, never a blank form to save over it', async () => {
		let attempts = 0;
		const calls = serve( {
			'GET ': STATE,
			'GET /profiles/3': () => {
				attempts += 1;

				if ( attempts === 1 ) {
					throw Object.assign( new Error( 'x' ), { data: { errors: [ 'Профиль недоступен' ] } } );
				}

				return { id: 3, name: 'Основной', values: { offer_id_source: 'sku', currency_kind: 'account', price_override: false } };
			},
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'tab', { name: 'Профили' } ) );
		await userEvent.click( await screen.findByRole( 'link', { name: 'Основной' } ) );

		expect( ( await screen.findAllByText( 'Профиль недоступен' ) ).length ).toBeGreaterThan( 0 );
		expect( screen.queryByRole( 'button', { name: 'Сохранить' } ) ).not.toBeInTheDocument();
		expect( screen.queryByLabelText( 'Название профиля' ) ).not.toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'button', { name: 'Повторить' } ) );

		expect( await screen.findByLabelText( 'Название профиля' ) ).toHaveValue( 'Основной' );
		expect( calls.some( ( call ) => call.method === 'POST' ) ).toBe( false );
	} );

	test( 'saves an existing profile without the read-only currency code', async () => {
		const calls = serve( {
			'GET ': STATE,
			'GET /profiles/3': {
				id: 3,
				name: 'Основной',
				values: { offer_id_source: 'meta', offer_id_source_key: '_sku2', currency_kind: 'fixed', currency_code: 'EUR', price_override: false, fallback_weight: '2' },
			},
			'POST /profiles/3': { id: 3, notices: [] },
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'tab', { name: 'Профили' } ) );
		await userEvent.click( await screen.findByRole( 'link', { name: 'Основной' } ) );

		expect( await screen.findByLabelText( 'Название поля' ) ).toHaveValue( '_sku2' );
		expect( screen.getByRole( 'option', { name: /Фиксированная: EUR/ } ) ).toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );

		expect( ( await screen.findAllByText( 'Профиль сохранён.' ) ).length ).toBeGreaterThan( 0 );
		const body = calls.find( ( call ) => call.method === 'POST' )?.data ?? {};
		expect( body ).not.toHaveProperty( 'currency_code' );
		expect( body ).toMatchObject( { name: 'Основной', currency_kind: 'fixed', offer_id_source: 'meta', offer_id_source_key: '_sku2', fallback_weight: '2' } );
	} );

	test( 'a new profile starts from the server defaults and offers no marketplace currency rule', async () => {
		const calls = serve( { 'GET ': STATE, 'POST /profiles': { id: 4, notices: [] } } );
		openView();

		await userEvent.click( await screen.findByRole( 'tab', { name: 'Профили' } ) );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Добавить профиль' } ) );
		await userEvent.type( await screen.findByLabelText( 'Название профиля' ), 'Новый' );

		expect( screen.queryByRole( 'option', { name: /Поле товара/ } ) ).not.toBeInTheDocument();
		await userEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );

		await waitFor( () => expect( calls.some( ( call ) => call.method === 'POST' ) ).toBe( true ) );
		expect( calls.find( ( call ) => call.method === 'POST' )?.data ).toMatchObject( { name: 'Новый', offer_id_source: 'sku', currency_kind: 'account', price_override: false } );
	} );

	test( 'the profile form is a card of titled groups; the size row is one row of three boxes and a unit', async () => {
		serve( { 'GET ': STATE } );
		openView();

		await userEvent.click( await screen.findByRole( 'tab', { name: 'Профили' } ) );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Добавить профиль' } ) );

		expect( await screen.findByRole( 'heading', { name: 'Новый профиль' } ) ).toBeInTheDocument();
		const sizes = screen.getByRole( 'group', { name: 'Запасные размеры' } );
		expect( screen.getByText( 'Размеры (Ш × В × Г)' ) ).toBeInTheDocument();

		// Each box keeps its own accessible name; the unit select names the shop's unit as its default.
		[ 'Ширина', 'Высота', 'Глубина', 'Вес' ].forEach( ( name ) => {
			expect( within( sizes ).getByRole( 'textbox', { name } ) ).toBeInTheDocument();
		} );
		expect( within( sizes ).getByRole( 'combobox', { name: 'Единица размеров' } ) ).toHaveDisplayValue( 'как в магазине (см)' );
		expect( within( sizes ).getByRole( 'combobox', { name: 'Единица веса' } ) ).toHaveDisplayValue( 'как в магазине (кг)' );
		expect( sizes.querySelectorAll( '.woodev-mappings-size' ) ).toHaveLength( 2 );
		// The two size rows get the wider control column, so the boxes and the unit stay on one line.
		expect( sizes.querySelectorAll( '.woodev-mappings-wide .woodev-mappings-size' ) ).toHaveLength( 2 );
	} );

	test( 'the VAT rate and the currency are in the price group; the own price rule opens its rows', async () => {
		serve( { 'GET ': STATE } );
		openView();

		await userEvent.click( await screen.findByRole( 'tab', { name: 'Профили' } ) );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Добавить профиль' } ) );

		const price = await screen.findByRole( 'group', { name: 'Цена' } );
		expect( within( price ).getByRole( 'combobox', { name: 'Ставка НДС' } ) ).toBeInTheDocument();
		expect( within( price ).getByRole( 'combobox', { name: 'Валюта цены' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'combobox', { name: 'Какую цену брать' } ) ).not.toBeInTheDocument();

		await userEvent.click( within( price ).getByRole( 'checkbox', { name: 'Своё правило цены' } ) );
		expect( within( price ).getByRole( 'combobox', { name: 'Какую цену брать' } ) ).toBeInTheDocument();
	} );

	test( 'a custom source field asks for its key in a row of its own under the source', async () => {
		serve( { 'GET ': STATE } );
		openView();

		await userEvent.click( await screen.findByRole( 'tab', { name: 'Профили' } ) );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Добавить профиль' } ) );
		expect( screen.queryByLabelText( 'Название поля' ) ).not.toBeInTheDocument();

		await userEvent.selectOptions( await screen.findByRole( 'combobox', { name: 'Откуда брать артикул' } ), 'meta' );

		expect( screen.getByLabelText( 'Название поля' ).closest( '.woodev-mappings-nested' ) ).not.toBeNull();
	} );

	test( 'deleting a profile asks twice and only the second click deletes', async () => {
		const calls = serve( {
			'GET ': STATE,
			'GET /profiles/3': { id: 3, name: 'Основной', values: { offer_id_source: 'sku', currency_kind: 'account', price_override: false } },
			'DELETE /profiles/3': { id: 3, notices: [] },
		} );
		openView();

		await userEvent.click( await screen.findByRole( 'tab', { name: 'Профили' } ) );
		await userEvent.click( await screen.findByRole( 'link', { name: 'Основной' } ) );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Удалить' } ) );
		expect( calls.some( ( call ) => call.method === 'DELETE' ) ).toBe( false );

		await userEvent.click( screen.getByRole( 'button', { name: 'Да, удалить' } ) );
		expect( ( await screen.findAllByText( 'Профиль удалён.' ) ).length ).toBeGreaterThan( 0 );
		expect( calls.filter( ( call ) => call.method === 'DELETE' ) ).toHaveLength( 1 );
		expect( await screen.findByRole( 'table', { name: 'Профили' } ) ).toBeInTheDocument();
	} );
} );
