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

const MESSAGES: Record< string, string > = {
	tabMappings: 'Сопоставления',
	tabProfiles: 'Профили',
	requestFailed: 'Запрос не удался',
	addMapping: 'Добавить сопоставление',
	addProfile: 'Добавить профиль',
	emptyMappings: 'Сопоставлений пока нет',
	emptyProfiles: 'Профилей пока нет',
	backToMappings: '← Все сопоставления',
	backToProfiles: '← Все профили',
	newMapping: 'Новое сопоставление',
	newProfile: 'Новый профиль',
	groupCategories: 'Категории',
	groupPrice: 'Цена',
	checkHeading: 'Проверка товаров',
	columnName: 'Название',
	columnCategory: 'Категория маркетплейса',
	columnProfile: 'Профиль',
	noCategory: 'Не выбрана',
	columnProducts: 'Товаров',
	columnMappings: 'Сопоставлений',
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
	profileNone: '— не выбран —',
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
	savedMapping: 'Сопоставление сохранено.',
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
	profiles: [ { id: 3, name: 'Основной', mappings: 1 } ],
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
			<table aria-label={ props.title }>
				<thead>
					<tr>
						{ props.headers.map( ( header ) => (
							<th key={ header.key }>{ header.label }</th>
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
	let view: ReturnType< typeof render >;
	const show = ( query: Query ) => <MappingsView page={ PAGE } menu={ MENU } query={ query } />;

	( window as unknown as { wc: Record< string, unknown > } ).wc.navigation = {
		updateQueryString: ( patch: Record< string, string | undefined > ) => {
			const next: Query = { ...history[ history.length - 1 ] };
			Object.entries( patch ).forEach( ( [ key, value ] ) => {
				if ( value === undefined ) {
					delete next[ key ];
				} else {
					next[ key ] = value;
				}
			} );
			history.push( next );
			act( () => view.rerender( show( next ) ) );
		},
	};
	view = render( show( start ) );

	return { history, back: () => act( () => view.rerender( show( history[ history.length - 2 ] ) ) ) };
}

/** Opens the shop-category dropdown and returns its option of that name. */
async function shopCategoryOption( name: string | RegExp ) {
	await userEvent.click( screen.getByRole( 'button', { name: /Выберите…|Одежда|Обувь/ } ) );

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
	test( 'shows the mappings and profiles PHP sent, each in a table card with its add button in the header', async () => {
		serve( { 'GET ': STATE } );
		openView();

		const mappings = await screen.findByRole( 'table', { name: 'Сопоставления' } );
		expect( within( mappings ).getByRole( 'button', { name: 'Платья' } ) ).toBeInTheDocument();
		expect( within( mappings ).getByText( 'Одежда › Платья' ) ).toBeInTheDocument();
		expect( within( mappings ).getByText( 'Основной' ) ).toBeInTheDocument();
		expect( within( mappings ).getByText( '12' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Добавить сопоставление' } ) ).toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'tab', { name: 'Профили' } ) );
		const profiles = await screen.findByRole( 'table', { name: 'Профили' } );
		expect( within( profiles ).getByRole( 'button', { name: 'Основной' } ) ).toBeInTheDocument();
		expect( within( profiles ).getByText( '1' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Добавить профиль' } ) ).toBeInTheDocument();
	} );

	test( 'a list with no rows is a card with the PHP sentence and the add button, not a table', async () => {
		serve( { 'GET ': { ...STATE, mappings: [], profiles: [] } } );
		openView();

		expect( await screen.findByText( 'Сопоставлений пока нет' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Добавить сопоставление' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'tab', { name: 'Профили' } ) );
		expect( await screen.findByText( 'Профилей пока нет' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Добавить профиль' } ) ).toBeInTheDocument();
	} );

	test( 'the list is replaced by the form and brought back through the URL: Back, Forward and a bookmark work', async () => {
		serve( {
			'GET ': STATE,
			'GET /mappings/1': { id: 1, name: 'Платья', terms: [ 5 ], profile_id: null, category: null, attributes: [] },
		} );
		const { history, back } = openViewWithUrl();

		await userEvent.click( await screen.findByRole( 'button', { name: 'Платья' } ) );
		expect( history[ history.length - 1 ] ).toEqual( { mapping: '1' } );
		expect( await screen.findByLabelText( 'Название сопоставления' ) ).toHaveValue( 'Платья' );
		expect( screen.queryByRole( 'table', { name: 'Сопоставления' } ) ).not.toBeInTheDocument();

		await back();
		expect( await screen.findByRole( 'table', { name: 'Сопоставления' } ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Название сопоставления' ) ).not.toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'button', { name: 'Добавить сопоставление' } ) );
		expect( history[ history.length - 1 ] ).toEqual( { mapping: 'new' } );
		expect( await screen.findByText( 'Новое сопоставление' ) ).toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'button', { name: '← Все сопоставления' } ) );
		expect( history[ history.length - 1 ] ).toEqual( {} );
		expect( await screen.findByRole( 'table', { name: 'Сопоставления' } ) ).toBeInTheDocument();
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
		await userEvent.click( await screen.findByRole( 'button', { name: 'Платья' } ) );

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

		await userEvent.click( await screen.findByRole( 'button', { name: 'Платья' } ) );
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

		await userEvent.click( await screen.findByRole( 'button', { name: 'Платья' } ) );
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

		await userEvent.click( await screen.findByRole( 'button', { name: 'Платья' } ) );
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

		await userEvent.click( await screen.findByRole( 'button', { name: 'Платья' } ) );

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

		await userEvent.click( await screen.findByRole( 'button', { name: 'Платья' } ) );
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

		await userEvent.click( await screen.findByRole( 'button', { name: 'Платья' } ) );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Удалить' } ) );
		expect( calls.some( ( call ) => call.method === 'DELETE' ) ).toBe( false );

		await userEvent.click( screen.getByRole( 'button', { name: 'Да, удалить' } ) );
		expect( ( await screen.findAllByText( 'Сопоставление удалено.' ) ).length ).toBeGreaterThan( 0 );
		expect( calls.filter( ( call ) => call.method === 'DELETE' ) ).toHaveLength( 1 );
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

		await userEvent.click( await screen.findByRole( 'button', { name: 'Платья' } ) );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Проверить товары' } ) );

		expect( await screen.findByText( 'Проверено товаров: 250, без замечаний: 247.' ) ).toBeInTheDocument();
		expect( screen.getByText( '3 товара: нет веса' ) ).toBeInTheDocument();
		expect( batch ).toBe( 2 );
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
		await userEvent.click( await screen.findByRole( 'button', { name: 'Основной' } ) );

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
		await userEvent.click( await screen.findByRole( 'button', { name: 'Основной' } ) );

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
		await userEvent.click( await screen.findByRole( 'button', { name: 'Основной' } ) );
		await userEvent.click( await screen.findByRole( 'button', { name: 'Удалить' } ) );
		expect( calls.some( ( call ) => call.method === 'DELETE' ) ).toBe( false );

		await userEvent.click( screen.getByRole( 'button', { name: 'Да, удалить' } ) );
		expect( ( await screen.findAllByText( 'Профиль удалён.' ) ).length ).toBeGreaterThan( 0 );
		expect( calls.filter( ( call ) => call.method === 'DELETE' ) ).toHaveLength( 1 );
		expect( await screen.findByRole( 'table', { name: 'Профили' } ) ).toBeInTheDocument();
	} );
} );
