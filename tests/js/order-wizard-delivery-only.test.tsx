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
import { buildFinalQuoteRequest } from '../../src/shipping-orders-page/order-wizard/delivery-state';
import { deliveryPrefillToData } from '../../src/shipping-orders-page/order-wizard/wizard-data';
import type { OrderPrefill } from '../../src/shipping-orders-page/order-wizard/types';

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

/** A tariff as the rates route answers it: `cost` is the customer's price, `carrier_cost` what the carrier asks. */
const tariff = ( id: string, cost: number, carrierCost: number, extra: Record<string, unknown> = {} ) => ( {
	id: `${ id }:3`,
	method_id: id,
	instance_id: 3,
	label: 'cdek_pickup' === id ? 'До пункта выдачи' : 'Курьер',
	cost,
	carrier_cost: carrierCost,
	delivery_time: '',
	description: '',
	is_pickup: 'cdek_pickup' === id,
	meta: {},
	...extra,
} );

const answer = ( rates: unknown[] ) => ( { ...RATES, providers: [ { id: 'cdek', label: 'СДЭК', rates } ] } );

/**
 * Routes the rates by what the request asks: a point gets its own price, anything else the representative one.
 *
 * @param {Function} pointAnswer what the route answers a request that names a point.
 * @param {Array}    plain       the tariffs without a point.
 */
const routePickup = ( pointAnswer: ( request: Request ) => Promise<unknown>, plain: unknown[] = [ tariff( 'cdek_pickup', 300, 300 ) ] ) => {
	mockedFetch.mockImplementation( ( request: Request ) => {
		if ( request.url.endsWith( '/301/edit' ) ) {
			return Promise.resolve( PREFILL );
		}

		if ( request.url.endsWith( '/rates' ) ) {
			return request.data?.pickup_point ? pointAnswer( request ) : Promise.resolve( answer( plain ) );
		}

		if ( 'PUT' === request.method ) {
			return Promise.resolve( { id: 301, number: '301', message: 'Заказ №301 сохранён.' } );
		}

		return Promise.resolve( { country: 'RU', pickup: false, fields: [] } );
	} );
};

const toDeliveryStep = async () => {
	await screen.findByText( 'Кому и куда доставить' );
	type( 'Город или населённый пункт', 'Москва' );
	next();
};

const save = () => fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить доставку' } ) );

describe( 'the shop pays for the delivery', () => {
	test.each( [
		[ 'free delivery from an order amount', 0 ],
		[ 'a markup', 400 ],
	] )( 'the carrier price is shown and saved, not the customer price after %s', async ( _rule, customerPrice ) => {
		routePickup( () => Promise.reject( new Error( 'no point here' ) ), [ tariff( 'cdek_courier', customerPrice as number, 350 ) ] );
		const { onSaved } = mount();

		await toDeliveryStep();
		fireEvent.click( await screen.findByRole( 'radio', { name: /Курьер/ } ) );

		expect( screen.getByRole( 'radio', { name: /Курьер/ } ).closest( 'label' ) ).toHaveTextContent( '350,00' );
		expect( screen.getByText( /Ваш расход на доставку: 350,00/ ) ).toBeInTheDocument();

		save();

		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( requests( 'PUT' )[ 0 ].data?.shipping_line ).toMatchObject( { cost: '350' } );
	} );

	test( 'a chosen pickup point is quoted on its own, and that quote is saved', async () => {
		routePickup( () => Promise.resolve( answer( [ tariff( 'cdek_pickup', 0, 450, { meta: { quoted_for: 'POINT-B' } } ) ] ) ) );
		const { onSaved } = mount();

		await toDeliveryStep();
		fireEvent.click( await screen.findByRole( 'radio', { name: /До пункта выдачи/ } ) );
		expect( screen.getByText( /Ваш расход на доставку: 300,00/ ) ).toBeInTheDocument();

		type( 'Код пункта выдачи', 'POINT-B' );

		// Saving now would record the price of no particular point.
		save();
		expect( screen.getAllByText( 'Дождитесь расчёта тарифов.' ).length ).toBeGreaterThan( 0 );
		expect( requests( 'PUT' ) ).toHaveLength( 0 );

		expect( await screen.findByText( /Ваш расход на доставку: 450,00/ ) ).toBeInTheDocument();
		expect( screen.getByRole( 'radio', { name: /До пункта выдачи/ } ).closest( 'label' ) ).toHaveTextContent( '450,00' );
		save();
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );

		const quoted = requests( 'POST' ).filter( ( request ) => request.data?.pickup_point );

		expect( quoted ).toHaveLength( 1 );
		expect( quoted[ 0 ].data ).toMatchObject( { method_id: 'cdek_pickup', pickup_point: 'POINT-B' } );
		expect( requests( 'PUT' )[ 0 ].data?.shipping_line ).toMatchObject( { cost: '450', meta: { quoted_for: 'POINT-B' } } );
		expect( requests( 'PUT' )[ 0 ].data?.pickup_point ).toEqual( { id: 'POINT-B' } );
	} );

	test( 'a point that cannot be quoted holds the save back until the quote is repeated', async () => {
		let refuse = true;

		routePickup( () =>
			refuse
				? Promise.reject( { message: 'Этот пункт не принимает заказ.', data: { status: 422 } } )
				: Promise.resolve( answer( [ tariff( 'cdek_pickup', 450, 450 ) ] ) )
		);
		const { onSaved } = mount();

		await toDeliveryStep();
		fireEvent.click( await screen.findByRole( 'radio', { name: /До пункта выдачи/ } ) );
		type( 'Код пункта выдачи', 'POINT-B' );

		expect( ( await screen.findAllByText( /Этот пункт не принимает заказ/ ) ).length ).toBeGreaterThan( 0 );

		save();
		expect( screen.getAllByText( /Стоимость доставки не посчитана/ ).length ).toBeGreaterThan( 0 );
		expect( requests( 'PUT' ) ).toHaveLength( 0 );

		refuse = false;
		fireEvent.click( screen.getByRole( 'button', { name: 'Повторить' } ) );

		expect( await screen.findByText( /Ваш расход на доставку: 450,00/ ) ).toBeInTheDocument();
		save();
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
	} );
} );

describe( 'switching tariffs after a quote with the order payment method', () => {
	/** Two couriers: what each costs in the plain list, and what it costs once the payment method is known. */
	const couriers = ( first: number, second: number ) => [
		tariff( 'cdek_courier', first, first, { label: 'Курьер А' } ),
		tariff( 'other_courier', second, second, { label: 'Курьер Б' } ),
	];

	test( 'the newly chosen tariff is quoted again, and that quote is saved', async () => {
		mockedFetch.mockImplementation( ( request: Request ) => {
			if ( request.url.endsWith( '/301/edit' ) ) {
				return Promise.resolve( { ...PREFILL, payment_method: 'cod' } );
			}

			if ( request.url.endsWith( '/rates' ) ) {
				return Promise.resolve( answer( request.data?.payment_method ? couriers( 400, 500 ) : couriers( 300, 350 ) ) );
			}

			if ( 'PUT' === request.method ) {
				return Promise.resolve( { id: 301, number: '301', message: 'Заказ №301 сохранён.' } );
			}

			return Promise.resolve( { country: 'RU', pickup: false, fields: [] } );
		} );
		( window as unknown as { woodevShippingOrders: { wizard: Record<string, unknown> } } ).woodevShippingOrders.wizard.paymentMethods = { cod: 'При получении' };

		const { onSaved } = mount();

		await toDeliveryStep();
		fireEvent.click( await screen.findByRole( 'radio', { name: /Курьер А/ } ) );
		expect( await screen.findByText( /Ваш расход на доставку: 400,00/ ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'radio', { name: /Курьер Б/ } ) );

		// The plain list's 350 is not this order's cost: nothing is saved before the new quote answers.
		save();
		expect( requests( 'PUT' ) ).toHaveLength( 0 );

		expect( await screen.findByText( /Ваш расход на доставку: 500,00/ ) ).toBeInTheDocument();
		save();

		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( requests( 'PUT' ) ).toHaveLength( 1 );
		expect( requests( 'PUT' )[ 0 ].data?.shipping_line ).toMatchObject( { method_id: 'other_courier', cost: '500' } );
	} );
} );

describe( 'buildFinalQuoteRequest', () => {
	const data = ( paymentMethod: string ) =>
		deliveryPrefillToData(
			{
				...( PREFILL as unknown as OrderPrefill ),
				shipping_line: { method_id: 'cdek_courier', instance_id: 3, rate_id: 'cdek_courier:3', label: 'Курьер', cost: '350', meta: {} },
				payment_method: paymentMethod,
			},
			'RU'
		);

	test( 'asks with the order payment method when the shop offers it', () => {
		expect( buildFinalQuoteRequest( data( 'cod' ), { cod: 'При получении' } ) ).toMatchObject( { payment_method: 'cod' } );
	} );

	test( 'asks nothing more for a method the shop does not offer, or none', () => {
		expect( buildFinalQuoteRequest( data( 'gone' ), { cod: 'При получении' } ) ).toBeNull();
		expect( buildFinalQuoteRequest( data( '' ), { cod: 'При получении' } ) ).toBeNull();
	} );
} );

