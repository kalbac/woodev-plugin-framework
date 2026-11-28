/**
 * The order wizard on an order another module owns (#2010): the prefill says `entry: 'delivery'`, and only the
 * recipient, the address and the carrier's tariff are asked and sent.
 *
 * @see src/shipping-orders-page/order-wizard/order-wizard.tsx
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import OrderWizard from '../../src/shipping-orders-page/order-wizard/order-wizard';

jest.mock( '@wordpress/api-fetch' );

const mockedFetch = apiFetch as unknown as jest.Mock;

const ORDERS_ROOT = 'https://example.test/wp-json/woodev/v1/shipping/orders';

const NOTICE = 'Для связи по этому номеру нужен добавочный: 1234.';

const PREFILL = {
	entry: 'delivery',
	notices: [ NOTICE ],
	order: { id: 301, number: '301', status: 'processing', status_name: 'Обработка', is_paid: true, total: '201.00', currency: 'RUB' },
	carrier: '',
	customer: { id: 0, create_account: false },
	billing: { first_name: 'Анна', phone: '+79991111111', email: '' },
	// The module that owns the order stores the address as one line: no country, no city.
	shipping: { first_name: 'Анна', phone: '+79991111111', address_1: 'Москва, Тверская 1' },
	items: [ { item_id: 5, product_id: 12, variation_id: 0, name: 'Кружка', quantity: 1, price: '100' } ],
	shipping_line: null,
	pickup_point: null,
	location: null,
	requires_settlement_selection: false,
	fields: {},
	carrier_fields: {},
	payment_method: '',
	status: 'processing',
};

const RATES = {
	destination: { country: 'RU' },
	needs_shipping: true,
	weight: 1000,
	zone: { id: 1, name: 'Россия' },
	providers: [
		{
			id: 'cdek',
			label: 'СДЭК',
			rates: [ { id: 'cdek_courier:3', method_id: 'cdek_courier', instance_id: 3, label: 'Курьер', cost: 350, delivery_time: '', description: '', is_pickup: false, meta: {} } ],
		},
	],
};

interface Request {
	url: string;
	method?: string;
	data?: Record<string, unknown>;
}

const routeApi = ( prefill: Record<string, unknown> = PREFILL ) => {
	mockedFetch.mockImplementation( ( request: Request ) => {
		if ( request.url.endsWith( '/301/edit' ) ) {
			return Promise.resolve( prefill );
		}

		if ( request.url.endsWith( '/rates' ) ) {
			return Promise.resolve( RATES );
		}

		if ( 'PUT' === request.method ) {
			return Promise.resolve( { id: 301, number: '301', message: 'Заказ №301 сохранён.' } );
		}

		return Promise.resolve( { country: 'RU', pickup: false, fields: [] } );
	} );
};

const mount = () => {
	const onClose = jest.fn();
	const onSaved = jest.fn();

	render( createElement( OrderWizard, { orderId: 301, forDelivery: true, onClose, onSaved } ) );

	return { onClose, onSaved };
};

const stepperLabels = () => Array.from( document.querySelectorAll( '.woodev-stepper > li .woodev-stepper__label' ) ).map( ( el ) => el.textContent );

const type = ( label: string, value: string ) => fireEvent.change( screen.getByLabelText( label ), { target: { value } } );

const next = () => fireEvent.click( screen.getByRole( 'button', { name: 'Далее' } ) );

const requests = ( method: string ): Request[] => mockedFetch.mock.calls.map( ( call ) => call[ 0 ] as Request ).filter( ( request ) => request.method === method );

beforeEach( () => {
	mockedFetch.mockReset();
	( window as unknown as Record<string, unknown> ).woodevShippingOrders = {
		restRoot: ORDERS_ROOT,
		nonce: 'nonce-1',
		providers: [],
		wizard: {
			countries: { RU: 'Россия', KZ: 'Казахстан' },
			states: {},
			defaultCountry: 'RU',
			currency: { code: 'RUB', symbol: '₽' },
		},
	};
	routeApi();
} );

afterEach( () => {
	delete ( window as unknown as Record<string, unknown> ).woodevShippingOrders;
} );

describe( 'delivery for an existing order', () => {
	test( 'opens on the address step with the recipient, the address, the shop country and the notices — two steps only', async () => {
		mount();

		expect( await screen.findByRole( 'dialog', { name: 'Оформить доставку заказа №301' } ) ).toBeInTheDocument();
		expect( stepperLabels() ).toEqual( [ 'Адрес', 'Доставка' ] );
		expect( screen.getByText( 'Кому и куда доставить' ) ).toBeInTheDocument();
		// `Notice` speaks the sentence into a live region too.
		expect( screen.getAllByText( NOTICE ).length ).toBeGreaterThan( 0 );
		expect( screen.getByLabelText( 'Имя получателя' ) ).toHaveValue( 'Анна' );
		expect( screen.getByLabelText( 'Телефон получателя' ) ).toHaveValue( '+79991111111' );
		expect( screen.getByLabelText( 'Улица, дом' ) ).toHaveValue( 'Москва, Тверская 1' );
		expect( screen.getByLabelText( 'Страна' ) ).toHaveValue( 'RU' );
	} );

	test( 'saves the tariff with the delivery keys only; the price is shown as the shop cost, not typed', async () => {
		const { onClose, onSaved } = mount();

		await screen.findByText( 'Кому и куда доставить' );
		type( 'Город или населённый пункт', 'Москва' );
		type( 'Телефон получателя', '+79000000000' );
		next();

		fireEvent.click( await screen.findByRole( 'radio', { name: /Курьер/ } ) );

		expect( screen.getByText( /Ваш расход на доставку: 350,00/ ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Стоимость доставки' ) ).toBeNull();
		expect( screen.queryByRole( 'button', { name: 'Далее' } ) ).toBeNull();

		fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить доставку' } ) );

		await waitFor( () => expect( onSaved ).toHaveBeenCalledWith( expect.objectContaining( { id: 301 } ), 'edit' ) );
		expect( onClose ).toHaveBeenCalled();

		const sent = requests( 'PUT' )[ 0 ];

		expect( sent.url ).toBe( `${ ORDERS_ROOT }/301` );
		expect( Object.keys( sent.data || {} ).sort() ).toEqual( [ 'carrier_fields', 'fields', 'pickup_point', 'shipping', 'shipping_line' ] );
		expect( sent.data?.shipping ).toMatchObject( { first_name: 'Анна', phone: '+79000000000', city: 'Москва', country: 'RU' } );
		expect( sent.data?.shipping_line ).toMatchObject( { method_id: 'cdek_courier', cost: '350' } );
	} );

	test( '«Сохранить доставку» without a tariff stays and says what is missing', async () => {
		mount();

		await screen.findByText( 'Кому и куда доставить' );
		type( 'Город или населённый пункт', 'Москва' );
		next();
		await screen.findByRole( 'radio', { name: /Курьер/ } );

		fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить доставку' } ) );

		expect( screen.getAllByText( 'Выберите способ доставки.' ).length ).toBeGreaterThan( 0 );
		expect( requests( 'PUT' ) ).toHaveLength( 0 );
	} );

	test( 'a problem the server finds in the address brings the wizard back to that step', async () => {
		mount();

		await screen.findByText( 'Кому и куда доставить' );
		type( 'Город или населённый пункт', 'Москва' );
		next();
		fireEvent.click( await screen.findByRole( 'radio', { name: /Курьер/ } ) );

		mockedFetch.mockImplementation( () =>
			Promise.reject( {
				message: 'Проверьте поля заказа.',
				data: { status: 422, errors: [ { field: 'shipping.state', code: 'invalid_state', message: 'Такого региона нет.' } ] },
			} )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить доставку' } ) );

		expect( await screen.findByText( 'Кому и куда доставить' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Такого региона нет.' ) ).toBeInTheDocument();
	} );

	test( 'a delivery already arranged is titled as a change, and a fresh quote replaces the saved cost', async () => {
		routeApi( {
			...PREFILL,
			carrier: 'cdek',
			shipping: { ...PREFILL.shipping, city: 'Москва', country: 'RU' },
			shipping_line: { method_id: 'cdek_courier', instance_id: 3, rate_id: 'cdek_courier:3', label: 'Курьер', cost: '300', meta: {} },
			requires_settlement_selection: false,
		} );
		mount();

		expect( await screen.findByRole( 'dialog', { name: 'Изменить доставку заказа №301' } ) ).toBeInTheDocument();
		next();

		expect( await screen.findByText( /Ваш расход на доставку: 350,00/ ) ).toBeInTheDocument();
	} );
} );
