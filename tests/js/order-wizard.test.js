/**
 * Component tests for the admin order wizard (#969, increment I4b of #710): the shell and
 * steps ①–③, against a mocked `apiFetch`.
 *
 * What is asserted, per the operator's D1: forward only through «Далее» (the stepper jumps
 * back, never ahead); each step checks its own fields before letting the manager on; a 422
 * from the final request lands ON THE FIELD it names, on the earliest step that owns an
 * error; closing with typed input asks first. Step ④ is the real one since I5a (#970; its own
 * behaviour is in order-wizard-delivery.test.js — here it is only walked through); ⑤ is I5b's, so
 * the shell is driven to the end through the `renderers` seam that increment will use.
 *
 * @see src/shipping-orders-page/order-wizard/order-wizard.tsx
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import OrderWizard from '../../src/shipping-orders-page/order-wizard/order-wizard';

jest.mock( '@wordpress/api-fetch' );
// The address step mounts the shared location picker; it only fetches once opened, so it is
// real here and never asked.

const ORDERS_ROOT = 'https://example.test/wp-json/woodev/v1/shipping/orders';

const CUSTOMER_RECORD = {
	id: 5,
	email: 'anna@example.test',
	first_name: 'Анна',
	last_name: 'Ким',
	billing: { first_name: 'Анна', last_name: 'Ким', phone: '+79001112233', email: 'anna@example.test', city: 'Казань', country: 'RU', state: '', address_1: 'ул Баумана 1', postcode: '420111' },
	shipping: {},
};

const PRODUCTS = [
	{ id: 12, name: 'Кружка', type: 'simple', sku: 'MUG', price: '1000' },
	{ id: 20, name: 'Футболка', type: 'variable', price: '500' },
];

const VARIATIONS = [ { id: 21, sku: 'TS-M', price: '550', attributes: [ { name: 'Размер', option: 'M' } ] } ];

/** What the rates route answers unless a test says otherwise: one courier tariff, nothing to pick up. */
const RATES = {
	destination: { country: 'RU' },
	needs_shipping: true,
	weight: 1000,
	zone: { id: 1, name: 'Россия' },
	providers: [
		{
			id: 'cdek',
			label: 'СДЭК',
			rates: [ { id: 'cdek_courier:3', method_id: 'cdek_courier', instance_id: 3, label: 'Курьер', cost: 250.5, delivery_time: '2-3 дня', description: '', is_pickup: false, meta: {} } ],
		},
	],
};

const routeApi = ( overrides = {} ) => {
	apiFetch.mockImplementation( ( request ) => {
		const url = request.url;

		// Ahead of the overrides: a save test's `/shipping/orders` fragment must not swallow this route.
		if ( url.endsWith( '/shipping/orders/rates' ) && ! overrides[ '/shipping/orders/rates' ] ) {
			return Promise.resolve( RATES );
		}

		for ( const [ fragment, handler ] of Object.entries( overrides ) ) {
			if ( url.includes( fragment ) ) {
				return typeof handler === 'function' ? handler( request ) : handler;
			}
		}

		if ( url.includes( '/wc/v3/customers' ) ) {
			return Promise.resolve( [ CUSTOMER_RECORD ] );
		}

		if ( url.includes( '/wc/v3/products/20/variations' ) ) {
			return Promise.resolve( VARIATIONS );
		}

		if ( url.includes( '/wc/v3/products' ) ) {
			return Promise.resolve( PRODUCTS );
		}

		return Promise.reject( { message: `unexpected request ${ url }` } );
	} );
};

const mount = ( props = {} ) => {
	const onClose = jest.fn();
	const onSaved = jest.fn();

	render( createElement( OrderWizard, { onClose, onSaved, ...props } ) );

	return { onClose, onSaved };
};

/** Queries scoped to the wizard's dialog: a `Notice` also speaks into a live region outside it. */
const modal = () => within( screen.getByRole( 'dialog' ) );

const stepperLabels = () => Array.from( document.querySelectorAll( '.woodev-stepper > li' ) ).map( ( li ) => li.textContent );
const stepperButton = ( name ) => screen.queryByRole( 'button', { name, selector: '.woodev-stepper__label' } );
const next = () => fireEvent.click( screen.getByRole( 'button', { name: 'Далее' } ) );

const type = ( label, value ) => fireEvent.change( screen.getByLabelText( label ), { target: { value } } );

/** ① filled, «Далее» pressed. */
const passCustomer = () => {
	type( 'Имя', 'Иван' );
	type( 'Фамилия', 'Петров' );
	next();
};

/** ② filled, «Далее» pressed. */
const passAddress = () => {
	type( 'Регион', 'МОСКВА' );
	type( 'Город или населённый пункт', 'Москва' );
	type( 'Улица, дом', 'ул Тверская 1' );
	next();
};

/** ③: one product found, picked; «Далее» pressed. */
const passItems = async () => {
	type( 'Добавить товар', 'кружка' );
	fireEvent.click( await screen.findByRole( 'option', { name: /Кружка/ } ) );
	next();
};

/** ④: the courier tariff chosen once the rates are in; «Далее» pressed. */
const passDelivery = async () => {
	fireEvent.click( await screen.findByRole( 'radio', { name: /Курьер/ } ) );
	next();
};

beforeEach( () => {
	apiFetch.mockReset();
	window.woodevShippingOrders = {
		restRoot: ORDERS_ROOT,
		nonce: 'nonce-1',
		providers: [],
		wizard: {
			countries: { RU: 'Россия', KZ: 'Казахстан' },
			states: { RU: { МОСКВА: 'Москва', 'МОСКОВСКАЯ ОБЛАСТЬ': 'Московская область' } },
			defaultCountry: 'RU',
			currency: { code: 'RUB', symbol: '₽' },
		},
	};
	routeApi();
} );

afterEach( () => {
	delete window.woodevShippingOrders;
} );

describe( 'shell and navigation (D1: forward only via «Далее»)', () => {
	test( 'opens on step ① with all five steps in the indicator, none ahead of the current one clickable', () => {
		mount();

		expect( screen.getByRole( 'dialog', { name: 'Создать заказ' } ) ).toBeInTheDocument();
		expect( stepperLabels() ).toEqual( [ 'Покупатель', 'Адрес', 'Товары', 'Доставка', 'Оплата' ] );
		expect( document.querySelector( '.woodev-stepper > li.is-active' ) ).toHaveTextContent( 'Покупатель' );

		for ( const name of [ 'Адрес', 'Товары', 'Доставка', 'Оплата' ] ) {
			expect( stepperButton( name ) ).toBeNull();
		}

		expect( screen.queryByRole( 'button', { name: 'Назад' } ) ).toBeNull();
	} );

	test( '«Далее» walks ①→②→③ and the indicator then jumps BACK to a passed step, never ahead', async () => {
		mount();

		passCustomer();
		expect( await screen.findByText( 'Куда доставить' ) ).toBeInTheDocument();
		passAddress();
		expect( await screen.findByText( 'Что в заказе' ) ).toBeInTheDocument();

		// Passed steps are buttons, the ones ahead are labels.
		expect( stepperButton( 'Покупатель' ) ).not.toBeNull();
		expect( stepperButton( 'Адрес' ) ).not.toBeNull();
		expect( stepperButton( 'Доставка' ) ).toBeNull();
		expect( stepperButton( 'Оплата' ) ).toBeNull();

		fireEvent.click( stepperButton( 'Покупатель' ) );
		expect( screen.getByText( 'Кто покупатель' ) ).toBeInTheDocument();
		// Back on ①, ② is now AHEAD again — forward only through «Далее».
		expect( stepperButton( 'Адрес' ) ).toBeNull();
	} );

	test( '«Назад» returns to the previous step with what was typed still there', () => {
		mount();

		passCustomer();
		fireEvent.click( screen.getByRole( 'button', { name: 'Назад' } ) );

		expect( screen.getByLabelText( 'Имя' ) ).toHaveValue( 'Иван' );
	} );

	test( 'step ④ is the real delivery step and ⑤ is a marked placeholder until I5b plugs one in', async () => {
		mount();

		passCustomer();
		passAddress();
		await passItems();

		expect( await screen.findByRole( 'radio', { name: /Курьер/ } ) ).toBeInTheDocument();
		expect( modal().getByText( 'Как доставить', { selector: 'h3' } ) ).toBeInTheDocument();
		expect( modal().queryByText( 'Этот шаг ещё в разработке — он появится в следующем обновлении.' ) ).toBeNull();

		await passDelivery();

		expect( modal().getByText( 'Оплата', { selector: 'h3' } ) ).toBeInTheDocument();
		expect( modal().getByText( 'Этот шаг ещё в разработке — он появится в следующем обновлении.' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Далее' } ) ).toBeNull();
	} );
} );

describe( 'step ① Покупатель', () => {
	test( '«создать аккаунт» without an email stops on ① and says why, on the email field', () => {
		mount();

		fireEvent.click( screen.getByLabelText( 'Создать аккаунт покупателя' ) );
		next();

		expect( screen.queryByText( 'Куда доставить' ) ).toBeNull();
		const alert = screen.getByText( 'Чтобы создать аккаунт, укажите email покупателя.' );
		expect( alert.closest( '.woodev-order-wizard__field' ) ).toContainElement( screen.getByLabelText( 'Электронная почта' ) );

		type( 'Электронная почта', 'ivan@example.test' );
		expect( screen.queryByText( 'Чтобы создать аккаунт, укажите email покупателя.' ) ).toBeNull();
		next();
		expect( screen.getByText( 'Куда доставить' ) ).toBeInTheDocument();
	} );

	test( 'picking a found customer fills the contact fields and the address of step ②', async () => {
		mount();

		type( 'Найти покупателя', 'анна' );
		fireEvent.click( await screen.findByRole( 'option', { name: /Анна Ким/ } ) );

		expect( await screen.findByText( 'Анна Ким (anna@example.test)' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Телефон' ) ).toHaveValue( '+79001112233' );
		expect( screen.getByLabelText( 'Электронная почта' ) ).toHaveValue( 'anna@example.test' );
		// A registered customer is not a new account: the box is gone.
		expect( screen.queryByLabelText( 'Создать аккаунт покупателя' ) ).toBeNull();

		next();
		expect( screen.getByLabelText( 'Город или населённый пункт' ) ).toHaveValue( 'Казань' );
		expect( screen.getByLabelText( 'Улица, дом' ) ).toHaveValue( 'ул Баумана 1' );
		expect( screen.getByLabelText( 'Индекс' ) ).toHaveValue( '420111' );
	} );

	test( 'the customer search goes through WooCommerce REST with the page nonce, and never before 2 characters', async () => {
		mount();

		type( 'Найти покупателя', 'а' );
		expect( screen.getByText( 'Введите минимум 2 символа для поиска' ) ).toBeInTheDocument();
		expect( apiFetch ).not.toHaveBeenCalled();

		type( 'Найти покупателя', 'ан' );
		await screen.findByRole( 'option', { name: /Анна Ким/ } );

		const request = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( request.url ).toContain( 'https://example.test/wp-json/wc/v3/customers?' );
		expect( request.url ).toContain( 'search=%D0%B0%D0%BD' );
		expect( request.headers ).toEqual( { 'X-WP-Nonce': 'nonce-1' } );
	} );

	test( 'a failed or empty search says so instead of showing a silent empty box', async () => {
		routeApi( { '/wc/v3/customers': () => Promise.reject( { message: 'boom' } ) } );
		mount();

		type( 'Найти покупателя', 'анна' );
		expect( await modal().findByText( 'Не удалось выполнить поиск. Попробуйте ещё раз.' ) ).toBeInTheDocument();

		routeApi( { '/wc/v3/customers': () => Promise.resolve( [] ) } );
		type( 'Найти покупателя', 'аннаа' );
		expect( await screen.findByText( 'Ничего не найдено' ) ).toBeInTheDocument();
	} );
} );

describe( 'step ② Адрес', () => {
	const toAddress = () => {
		mount();
		passCustomer();
	};

	test( 'starts on the shop\'s country with its regions offered, and needs a city', () => {
		toAddress();

		expect( screen.getByLabelText( 'Страна' ) ).toHaveValue( 'RU' );
		const region = screen.getByLabelText( 'Регион' );
		expect( within( region ).getByRole( 'option', { name: 'Московская область' } ) ).toBeInTheDocument();

		next();

		const alert = screen.getByText( 'Укажите город или населённый пункт.' );
		expect( alert.closest( '.woodev-order-wizard__field' ) ).toContainElement( screen.getByLabelText( 'Город или населённый пункт' ) );
		expect( screen.queryByText( 'Что в заказе' ) ).toBeNull();
	} );

	test( 'a country without a region list gets a free-text region', () => {
		toAddress();

		fireEvent.change( screen.getByLabelText( 'Страна' ), { target: { value: 'KZ' } } );

		expect( screen.getByLabelText( 'Регион' ).tagName ).toBe( 'INPUT' );
	} );

	test( 'changing the country drops the region picked for the old one', () => {
		toAddress();

		type( 'Регион', 'МОСКВА' );
		fireEvent.change( screen.getByLabelText( 'Страна' ), { target: { value: 'KZ' } } );

		expect( screen.getByLabelText( 'Регион' ) ).toHaveValue( '' );
	} );

	test( 'the location search buttons are offered, and disabled until a country is chosen', () => {
		toAddress();

		expect( screen.getByRole( 'button', { name: 'Найти населённый пункт…' } ) ).toBeEnabled();
		expect( screen.getByRole( 'button', { name: 'Найти адрес…' } ) ).toBeEnabled();

		fireEvent.change( screen.getByLabelText( 'Страна' ), { target: { value: '' } } );

		expect( screen.getByRole( 'button', { name: 'Найти населённый пункт…' } ) ).toBeDisabled();
	} );

	test( 'a picked settlement fills city, the matching region code and postcode', async () => {
		routeApi( {
			'/location/suggest': () =>
				Promise.resolve( {
					suggestions: [
						{
							key: 'dadata:city-1',
							label: 'Московская обл., г Жуковский',
							level: 'settlement',
							record: {
								key: 'dadata:city-1',
								level: 'settlement',
								region: { name: 'Московская', type: 'обл' },
								settlement: { name: 'Жуковский', type: 'г' },
								postcode: '140180',
							},
						},
					],
				} ),
		} );
		toAddress();

		fireEvent.click( screen.getByRole( 'button', { name: 'Найти населённый пункт…' } ) );
		fireEvent.change( await screen.findByPlaceholderText( 'Начните вводить название…' ), { target: { value: 'жуков' } } );
		fireEvent.click( await screen.findByRole( 'option', { name: /Жуковский/ } ) );

		expect( screen.getByLabelText( 'Город или населённый пункт' ) ).toHaveValue( 'Жуковский' );
		expect( screen.getByLabelText( 'Регион' ) ).toHaveValue( 'МОСКОВСКАЯ ОБЛАСТЬ' );
		expect( screen.getByLabelText( 'Индекс' ) ).toHaveValue( '140180' );

		// The search asked the stateless public route, at settlement level, with the nonce.
		const request = apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).find( ( r ) => r.url.includes( '/location/suggest' ) );
		expect( request.url ).toContain( 'https://example.test/wp-json/woodev/v1/location/suggest?' );
		expect( request.url ).toContain( 'level=settlement' );
		expect( request.url ).toContain( 'country=RU' );
		expect( request.headers ).toEqual( { 'X-WP-Nonce': 'nonce-1' } );

		// ④ (#970). The whole flow is ONE test on purpose: a second location search in the same
		// file finds the shared popover layer in the state the first one left it. What step ④
		// needs from the pick is asserted along the way.
		type( 'Улица, дом', 'ул Гагарина 1' );
		next();
		await passItems();
		await screen.findByRole( 'radio', { name: /Курьер/ } );

		const asked = () => apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).filter( ( r ) => r.url.endsWith( '/shipping/orders/rates' ) );

		// The record rides whole, next to the typed fields: a carrier that prices by its own settlement id needs it.
		expect( asked()[ 0 ].data.location ).toEqual( {
			key: 'dadata:city-1',
			level: 'settlement',
			region: { name: 'Московская', type: 'обл' },
			settlement: { name: 'Жуковский', type: 'г' },
			postcode: '140180',
		} );
		expect( asked()[ 0 ].data.destination ).toMatchObject( { country: 'RU', state: 'МОСКОВСКАЯ ОБЛАСТЬ', city: 'Жуковский', postcode: '140180', address: 'ул Гагарина 1' } );

		// Back to ②, another city typed over it: a hand-typed place has no record.
		fireEvent.click( screen.getByRole( 'button', { name: 'Назад' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Назад' } ) );
		type( 'Город или населённый пункт', 'Раменское' );
		next();
		next();
		await screen.findByRole( 'radio', { name: /Курьер/ } );

		expect( asked() ).toHaveLength( 2 );
		expect( asked()[ 1 ].data ).not.toHaveProperty( 'location' );
		expect( asked()[ 1 ].data.destination.city ).toBe( 'Раменское' );
	} );
} );

describe( 'step ③ Товары', () => {
	const toItems = () => {
		mount();
		passCustomer();
		passAddress();
	};

	test( 'an empty order does not pass, with the server\'s sentence', () => {
		toItems();

		next();

		expect( modal().getByText( 'Добавьте в заказ хотя бы один товар.' ) ).toBeInTheDocument();
		expect( modal().queryByText( 'Этот шаг ещё в разработке — он появится в следующем обновлении.' ) ).toBeNull();
	} );

	test( 'a picked product becomes a line with its price, and the subtotal follows quantity and price edits', async () => {
		toItems();

		type( 'Добавить товар', 'кружка' );
		fireEvent.click( await screen.findByRole( 'option', { name: /Кружка/ } ) );

		expect( screen.getByLabelText( 'Кол-во' ) ).toHaveValue( 1 );
		expect( screen.getByLabelText( 'Цена за шт.' ) ).toHaveValue( 1000 );
		expect( document.querySelector( '.woodev-order-wizard__subtotal' ).textContent ).toMatch( /1\s?000,00\s₽/ );

		type( 'Кол-во', '3' );
		type( 'Цена за шт.', '900' );

		expect( document.querySelector( '.woodev-order-wizard__subtotal' ).textContent ).toMatch( /2\s?700,00\s₽/ );
	} );

	test( 'picking the same product again adds one to its quantity, not a second line', async () => {
		toItems();

		for ( let i = 0; i < 2; i += 1 ) {
			type( 'Добавить товар', i ? 'кружк' : 'кружка' );
			fireEvent.click( await screen.findByRole( 'option', { name: /Кружка/ } ) );
		}

		expect( screen.getAllByLabelText( 'Кол-во' ) ).toHaveLength( 1 );
		expect( screen.getByLabelText( 'Кол-во' ) ).toHaveValue( 2 );
	} );

	test( 'a variable product is chosen variation by variation, never as the parent', async () => {
		toItems();

		type( 'Добавить товар', 'футб' );
		fireEvent.click( await screen.findByRole( 'option', { name: /Футболка/ } ) );

		// The parent is replaced by its variations, with a way back.
		expect( await screen.findByRole( 'option', { name: /Футболка — M/ } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: '← К результатам поиска' } ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'option', { name: /Футболка — M/ } ) );

		expect( screen.getByText( 'Футболка — M' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Цена за шт.' ) ).toHaveValue( 550 );
	} );

	test( 'quantity and price are checked per line, each error on its own control', async () => {
		toItems();

		type( 'Добавить товар', 'кружка' );
		fireEvent.click( await screen.findByRole( 'option', { name: /Кружка/ } ) );
		type( 'Кол-во', '0' );
		type( 'Цена за шт.', '-1' );
		next();

		const qty = screen.getByText( 'Количество должно быть не меньше единицы.' );
		expect( qty.closest( '.woodev-order-wizard__field' ) ).toContainElement( screen.getByLabelText( 'Кол-во' ) );
		const price = screen.getByText( 'Цена должна быть числом не меньше нуля.' );
		expect( price.closest( '.woodev-order-wizard__field' ) ).toContainElement( screen.getByLabelText( 'Цена за шт.' ) );

		// Fixing a field takes its problem away at once.
		type( 'Кол-во', '2' );
		expect( screen.queryByText( 'Количество должно быть не меньше единицы.' ) ).toBeNull();
		expect( screen.getByText( 'Цена должна быть числом не меньше нуля.' ) ).toBeInTheDocument();
	} );

	test( 'a line can be removed', async () => {
		toItems();

		type( 'Добавить товар', 'кружка' );
		fireEvent.click( await screen.findByRole( 'option', { name: /Кружка/ } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Убрать: Кружка' } ) );

		expect( screen.queryByLabelText( 'Кол-во' ) ).toBeNull();
	} );
} );

describe( 'step ④ Доставка, reached through the shell (#970)', () => {
	test( '«Далее» is refused until a tariff is chosen, with the server\'s own sentence', async () => {
		mount();
		passCustomer();
		passAddress();
		await passItems();
		await screen.findByRole( 'radio', { name: /Курьер/ } );

		next();

		expect( modal().getByText( 'Выберите способ доставки.' ) ).toBeInTheDocument();
		expect( modal().getByText( 'Как доставить', { selector: 'h3' } ) ).toBeInTheDocument();
	} );

	test( 'going back and changing the package asks the tariffs again on the way forward', async () => {
		mount();
		passCustomer();
		passAddress();
		await passItems();
		await passDelivery();

		fireEvent.click( screen.getByRole( 'button', { name: 'Назад' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Назад' } ) );
		fireEvent.change( screen.getByLabelText( 'Кол-во' ), { target: { value: '3' } } );
		next();
		await screen.findByRole( 'radio', { name: /Курьер/ } );

		const asked = apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).filter( ( r ) => r.url.endsWith( '/shipping/orders/rates' ) );

		// On arrival, on coming back into ④, and once more after the package changed.
		expect( asked ).toHaveLength( 3 );
		expect( asked[ 2 ].data.items[ 0 ].quantity ).toBe( 3 );
		// The tariff chosen before is still offered, so it stays chosen.
		await waitFor( () => expect( screen.getByRole( 'radio', { name: /Курьер/ } ) ).toBeChecked() );
	} );
} );

describe( 'sending the order and server-side validation (422, shown per field)', () => {
	/** ⑤ as I5b will supply it: a step that owns the submit button. */
	const renderers = {
		payment: ( { submit } ) => createElement( 'button', { type: 'button', onClick: submit }, 'Создать' ),
	};

	const toPayment = async ( props = {} ) => {
		const mounted = mount( { renderers, ...props } );

		passCustomer();
		passAddress();
		await passItems();
		await passDelivery();
		await screen.findByRole( 'button', { name: 'Создать' } );

		return mounted;
	};

	test( 'POSTs the validator\'s payload with the page nonce, then reports the order and closes', async () => {
		routeApi( {
			'/shipping/orders': ( request ) => Promise.resolve( { id: 91, number: '91', message: 'Заказ №91 создан.', echo: request.data } ),
		} );
		const { onSaved, onClose } = await toPayment();

		fireEvent.click( screen.getByRole( 'button', { name: 'Создать' } ) );

		await waitFor( () => expect( onSaved ).toHaveBeenCalledTimes( 1 ) );
		expect( onSaved.mock.calls[ 0 ][ 0 ] ).toMatchObject( { id: 91, message: 'Заказ №91 создан.' } );
		expect( onSaved.mock.calls[ 0 ][ 1 ] ).toBe( 'create' );
		expect( onClose ).toHaveBeenCalled();

		const request = apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).find( ( r ) => r.method === 'POST' && r.url === ORDERS_ROOT );
		expect( request.url ).toBe( ORDERS_ROOT );
		expect( request.headers ).toEqual( { 'X-WP-Nonce': 'nonce-1' } );
		expect( request.data.customer ).toEqual( { id: 0, create_account: false } );
		expect( request.data.billing ).toMatchObject( { first_name: 'Иван', city: 'Москва', state: 'МОСКВА', country: 'RU' } );
		expect( request.data.shipping ).toMatchObject( { first_name: 'Иван', address_1: 'ул Тверская 1' } );
		expect( request.data.items ).toEqual( [ { product_id: 12, variation_id: 0, quantity: 1, price: '1000' } ] );
		// ④: the tariff exactly as the rates route returned it, at its own price; UI-only bookkeeping stays home.
		expect( request.data.shipping_line ).toEqual( {
			method_id: 'cdek_courier',
			instance_id: 3,
			rate_id: 'cdek_courier:3',
			label: 'Курьер',
			cost: '250.5',
			meta: {},
		} );
		expect( request.data.pickup_point ).toBeNull();
		expect( JSON.stringify( request.data ) ).not.toMatch( /rate_cost|rate_is_pickup|rates_pending|settlementRecord/ );
	} );

	test( 'a 422 puts each message on the field it names and returns to the earliest step with one', async () => {
		routeApi( {
			'/shipping/orders': () =>
				Promise.reject( {
					code: 'woodev_shipping_order_invalid',
					message: 'Заказ не сохранён: проверьте отмеченные поля.',
					data: {
						status: 422,
						errors: [
							{ field: 'items.0.quantity', code: 'invalid_quantity', message: 'Количество должно быть не меньше единицы.' },
							{ field: 'billing.email', code: 'email_exists', message: 'Покупатель с таким email уже есть — выберите его в списке.' },
						],
					},
				} ),
		} );
		const { onSaved, onClose } = await toPayment();

		fireEvent.click( screen.getByRole( 'button', { name: 'Создать' } ) );

		// The earliest owner is ① — not the items step the other message belongs to.
		const email = await screen.findByText( 'Покупатель с таким email уже есть — выберите его в списке.' );
		expect( email.closest( '.woodev-order-wizard__field' ) ).toContainElement( screen.getByLabelText( 'Электронная почта' ) );
		expect( modal().getByText( 'Заказ не сохранён: проверьте отмеченные поля.' ) ).toBeInTheDocument();
		expect( onSaved ).not.toHaveBeenCalled();
		expect( onClose ).not.toHaveBeenCalled();

		// The other message is waiting on its own step, on its own field.
		next();
		next();
		const qty = screen.getByText( 'Количество должно быть не меньше единицы.' );
		expect( qty.closest( '.woodev-order-wizard__field' ) ).toContainElement( screen.getByLabelText( 'Кол-во' ) );
	} );

	test( 'a non-validation failure (409: exported meanwhile) shows the server\'s sentence and stays open', async () => {
		routeApi( {
			'/shipping/orders': () => Promise.reject( { code: 'x', message: 'Заказ уже выгружен перевозчику.', data: { status: 409 } } ),
		} );
		const { onSaved, onClose } = await toPayment();

		fireEvent.click( screen.getByRole( 'button', { name: 'Создать' } ) );

		expect( await modal().findByText( 'Заказ уже выгружен перевозчику.' ) ).toBeInTheDocument();
		expect( onSaved ).not.toHaveBeenCalled();
		expect( onClose ).not.toHaveBeenCalled();
	} );

	test( 'a network failure without a body says something human, not nothing', async () => {
		routeApi( { '/shipping/orders': () => Promise.reject( new Error( 'Failed to fetch' ) ) } );
		await toPayment();

		fireEvent.click( screen.getByRole( 'button', { name: 'Создать' } ) );

		expect( await modal().findByText( 'Не удалось выполнить запрос. Попробуйте ещё раз.' ) ).toBeInTheDocument();
	} );
} );

describe( 'closing (C3: unsaved input asks first)', () => {
	test( 'an untouched wizard closes at once', () => {
		const { onClose } = mount();

		fireEvent.click( screen.getByRole( 'button', { name: 'Отмена' } ) );

		expect( onClose ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'with typed input «Отмена» asks, «Продолжить» keeps the wizard, «Закрыть без сохранения» closes it', () => {
		const { onClose } = mount();

		type( 'Имя', 'Иван' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Отмена' } ) );

		expect( screen.getByText( 'Закрыть окно? Введённые данные не сохранятся.' ) ).toBeInTheDocument();
		expect( onClose ).not.toHaveBeenCalled();

		fireEvent.click( screen.getByRole( 'button', { name: 'Продолжить оформление' } ) );
		expect( screen.queryByText( 'Закрыть окно? Введённые данные не сохранятся.' ) ).toBeNull();
		expect( screen.getByLabelText( 'Имя' ) ).toHaveValue( 'Иван' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Отмена' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Закрыть без сохранения' } ) );
		expect( onClose ).toHaveBeenCalledTimes( 1 );
	} );

	// `Modal` closes through its exit animation and calls `onRequestClose` afterwards, so the
	// question shows up a beat after the click — never an immediate silent close.
	test( 'the modal\'s own × asks the same way', async () => {
		const { onClose } = mount();

		type( 'Имя', 'Иван' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );

		expect( await modal().findByText( 'Закрыть окно? Введённые данные не сохранятся.' ) ).toBeInTheDocument();
		expect( onClose ).not.toHaveBeenCalled();
	} );

	test( 'Escape asks the same way', async () => {
		const { onClose } = mount();

		type( 'Имя', 'Иван' );
		fireEvent.keyDown( screen.getByRole( 'dialog' ), { key: 'Escape', code: 'Escape' } );

		expect( await modal().findByText( 'Закрыть окно? Введённые данные не сохранятся.' ) ).toBeInTheDocument();
		expect( onClose ).not.toHaveBeenCalled();
	} );
} );

describe( 'edit mode (the load route)', () => {
	const PREFILL = {
		order: { id: 7, number: '7', status: 'processing', status_name: 'В обработке', is_paid: true, total: '2100.00', currency: 'RUB' },
		carrier: 'cdek',
		customer: { id: 5, create_account: false },
		billing: { first_name: 'Анна', last_name: 'Ким', phone: '+79001112233', email: 'anna@example.test', city: 'Казань', country: 'RU', state: '' },
		shipping: { first_name: 'Анна', last_name: 'Ким', city: 'Казань', country: 'RU', state: '', address_1: 'ул Баумана 1' },
		items: [ { item_id: 11, product_id: 12, variation_id: 0, name: 'Кружка', quantity: 2, price: '1000.00' } ],
		shipping_line: { method_id: 'cdek', instance_id: 1, cost: '100' },
		pickup_point: null,
		fields: {},
		carrier_fields: {},
		payment_method: 'cod',
		status: 'processing',
	};

	test( 'loads GET …/{id}/edit, shows the order number, and opens every step prefilled', async () => {
		routeApi( { '/shipping/orders/7/edit': () => Promise.resolve( PREFILL ) } );
		mount( { orderId: 7 } );

		expect( screen.getByText( 'Загружаем заказ…' ) ).toBeInTheDocument();
		expect( await screen.findByRole( 'dialog', { name: 'Редактировать заказ №7' } ) ).toBeInTheDocument();

		const request = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( request.url ).toBe( `${ ORDERS_ROOT }/7/edit` );
		expect( request.method ).toBe( 'GET' );
		expect( request.headers ).toEqual( { 'X-WP-Nonce': 'nonce-1' } );

		expect( screen.getByLabelText( 'Имя' ) ).toHaveValue( 'Анна' );
		expect( screen.getByText( 'Анна Ким (anna@example.test)' ) ).toBeInTheDocument();
		next();
		expect( screen.getByLabelText( 'Улица, дом' ) ).toHaveValue( 'ул Баумана 1' );
		next();
		expect( screen.getByLabelText( 'Кол-во' ) ).toHaveValue( 2 );
		expect( screen.getByLabelText( 'Цена за шт.' ) ).toHaveValue( 1000 );
	} );

	test( 'an untouched edit closes without asking', async () => {
		routeApi( { '/shipping/orders/7/edit': () => Promise.resolve( PREFILL ) } );
		const { onClose } = mount( { orderId: 7 } );

		await screen.findByRole( 'dialog', { name: 'Редактировать заказ №7' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Отмена' } ) );

		expect( onClose ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'PUTs to the order\'s own URL and reports «edit»', async () => {
		routeApi( {
			'/shipping/orders/7/edit': () => Promise.resolve( PREFILL ),
			'/shipping/orders/7': () => Promise.resolve( { id: 7, number: '7', message: 'Заказ №7 сохранён.' } ),
		} );
		const { onSaved } = mount( {
			orderId: 7,
			renderers: { payment: ( { submit } ) => createElement( 'button', { type: 'button', onClick: submit }, 'Сохранить' ) },
		} );

		await screen.findByRole( 'dialog', { name: 'Редактировать заказ №7' } );
		next();
		next();
		next();
		// ④ waits for the tariffs of the current package before it lets the saved line through.
		await screen.findByRole( 'radio', { name: /Курьер/ } );
		next();
		fireEvent.click( await screen.findByRole( 'button', { name: 'Сохранить' } ) );

		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( onSaved.mock.calls[ 0 ][ 1 ] ).toBe( 'edit' );

		const put = apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).find( ( r ) => 'PUT' === r.method );
		expect( put.url ).toBe( `${ ORDERS_ROOT }/7` );
		expect( put.data.items ).toEqual( [ { item_id: 11, product_id: 12, variation_id: 0, quantity: 2, price: '1000.00' } ] );
		expect( put.data.shipping_line ).toEqual( { method_id: 'cdek', instance_id: 1, cost: '100' } );
		expect( put.data.payment_method ).toBe( 'cod' );
	} );

	test( 'an order that cannot be edited (409) shows the server\'s reason and only offers to close', async () => {
		routeApi( {
			'/shipping/orders/7/edit': () => Promise.reject( { code: 'x', message: 'Заказ уже выгружен перевозчику — отмените выгрузку.', data: { status: 409 } } ),
		} );
		const { onClose } = mount( { orderId: 7 } );

		expect( await modal().findByText( 'Заказ уже выгружен перевозчику — отмените выгрузку.' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Далее' } ) ).toBeNull();

		fireEvent.click( screen.getByRole( 'button', { name: 'Закрыть' } ) );
		expect( onClose ).toHaveBeenCalled();
	} );
} );
