/**
 * Step ④ «Доставка» of the admin order wizard (#970, #710 D1 / D2 / D3, O8 / O10 / O12):
 * the tariffs from the rates route grouped by carrier, the editable delivery price, and the
 * pickup point chosen INSIDE the step — against a mocked `apiFetch` and a mocked picker session.
 *
 * The step is driven through a small harness that owns the wizard state the way the shell does,
 * so what is asserted is the state the step leaves behind (`shipping_line`, `pickup_point`) — the
 * very thing the payload is built from — and what the manager sees.
 *
 * @see src/shipping-orders-page/order-wizard/step-delivery.tsx
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor, within, act } from '@testing-library/react';
import { createElement, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import StepDelivery from '../../src/shipping-orders-page/order-wizard/step-delivery';
import { validateDelivery } from '../../src/shipping-orders-page/order-wizard/validation';
import { emptyWizardData, newItem } from '../../src/shipping-orders-page/order-wizard/wizard-data';
import { createPickupSession } from '../../src/shipping-orders-page/order-wizard/pickup-session';

jest.mock( '@wordpress/api-fetch' );
jest.mock( '../../src/shipping-orders-page/order-wizard/pickup-session', () => ( {
	...jest.requireActual( '../../src/shipping-orders-page/order-wizard/pickup-session' ),
	createPickupSession: jest.fn(),
} ) );

const RATES_URL = 'https://example.test/wp-json/woodev/v1/shipping/orders/rates';

const COURIER = { id: 'cdek_courier:3', method_id: 'cdek_courier', instance_id: 3, label: 'Курьер', cost: 250.5, delivery_time: '2-3 дня', description: 'До двери', is_pickup: false, meta: { tariff_code: 137 } };
const PVZ = { id: 'cdek_pvz:5', method_id: 'cdek_pvz', instance_id: 5, label: 'Пункт выдачи СДЭК', cost: 120, delivery_time: '', description: '', is_pickup: true, meta: {} };

const response = ( providers, extra = {} ) => ( { destination: {}, needs_shipping: true, weight: 3250, zone: { id: 1, name: 'Россия' }, providers, ...extra } );
const CDEK = response( [ { id: 'cdek', label: 'СДЭК', rates: [ COURIER, PVZ ] }, { id: 'yandex', label: 'Яндекс Доставка', rates: [] } ] );

const PICKER_CONFIG = { provider: 'yandex', strategy: 'bulk', restRoot: 'https://example.test/wp-json/woodev/v1/shipping/orders/pickup/cdek/points', i18n: {}, mapConfig: {} };

/** Steps ①–③ done. */
const filled = () => {
	const data = emptyWizardData( 'RU' );

	data.customer = { id: 5, create_account: false, label: 'Анна' };
	data.shipping = { ...data.shipping, country: 'RU', state: 'МОСКВА', city: 'Москва', address_1: 'ул Тверская 1' };
	data.items = [ { ...newItem( { product_id: 12, name: 'Кружка', price: '1000' } ), quantity: '2' } ];

	return data;
};

let probe;

function Harness( { initial, errors = {} } ) {
	const [ data, setData ] = useState( initial );
	const [ open, setOpen ] = useState( true );

	probe.data = data;

	// The shell outlives the step: leaving ④ unmounts the step and nothing else.
	return createElement(
		'div',
		{ 'data-testid': 'shell' },
		createElement( 'button', { type: 'button', onClick: () => setOpen( false ) }, 'leave' ),
		open && createElement( StepDelivery, { data, setData: ( update ) => setData( update ), errors, mode: 'create', order: null, submit: jest.fn(), busy: false } )
	);
}

const mount = ( initial = filled(), errors = {} ) => render( createElement( Harness, { initial, errors } ) );

/**
 * Queries scoped to the step itself: a `Notice` also speaks its text into a live region that
 * sits outside it, so a document-wide text query finds the same sentence twice.
 */
const step = () => within( document.querySelector( '.woodev-order-wizard__step' ) );

/** What the picker does on its own, outside React's event handlers. */
const pick = ( point ) => act( () => createPickupSession.mock.calls[ 0 ][ 0 ].onSelect( point ) );

/** A sentence of the step, waited for inside it (see {@link step}). */
const ready_or = ( text ) => waitFor( () => expect( step().getByText( text ) ).toBeInTheDocument() );

/** The tariffs are in: the courier row exists. */
const ready = () => screen.findByRole( 'radio', { name: /Курьер/ } );

const rateBodies = () => apiFetch.mock.calls.map( ( call ) => call[ 0 ] ).filter( ( request ) => request.url === RATES_URL );

beforeEach( () => {
	probe = { data: null };
	apiFetch.mockReset();
	apiFetch.mockResolvedValue( CDEK );
	createPickupSession.mockReset();
	createPickupSession.mockReturnValue( { setSelectedId: jest.fn(), destroy: jest.fn() } );

	window.woodevShippingOrders = {
		restRoot: 'https://example.test/wp-json/woodev/v1/shipping/orders',
		nonce: 'nonce-1',
		providers: [],
		wizard: { currency: { code: 'RUB', symbol: '₽' }, pickup: { cdek: PICKER_CONFIG } },
	};

	// The three storefront scripts the picker is assembled from; the session itself is mocked above.
	window.WoodevPickupDataSource = () => ( {} );
	window.WoodevPickupPanels = function () {};
	window.WoodevPickupGeo = { groupByPosition: () => [] };
	window.WoodevPickupMapProviders = { yandex: function () {} };
} );

afterEach( () => {
	delete window.woodevShippingOrders;
	delete window.WoodevPickupDataSource;
	delete window.WoodevPickupPanels;
	delete window.WoodevPickupGeo;
	delete window.WoodevPickupMapProviders;
} );

describe( 'the tariffs (D2, O12)', () => {
	test( 'asks the rates route on arrival with the package steps ①–③ built — the edited prices, the destination, the customer', async () => {
		mount();

		expect( screen.getByText( 'Считаем тарифы…' ) ).toBeInTheDocument();
		await ready();

		expect( rateBodies() ).toHaveLength( 1 );

		const [ request ] = rateBodies();

		expect( request.method ).toBe( 'POST' );
		expect( request.headers ).toEqual( { 'X-WP-Nonce': 'nonce-1' } );
		expect( request.data ).toEqual( {
			items: [ { product_id: 12, variation_id: 0, quantity: 2, price: 1000 } ],
			destination: { country: 'RU', state: 'МОСКВА', city: 'Москва', postcode: '', address: 'ул Тверская 1', address_2: '' },
			customer_id: 5,
		} );
	} );

	test( 'lists the tariffs grouped by carrier, with time, hint and price — and says so for a carrier with none', async () => {
		mount();
		await ready();

		expect( screen.getByText( 'СДЭК', { selector: 'legend' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Яндекс Доставка', { selector: 'legend' } ) ).toBeInTheDocument();
		expect( screen.getByText( '2-3 дня · До двери' ) ).toBeInTheDocument();
		expect( screen.getByText( '250,50 ₽' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Для этого адреса и товаров тарифов нет.' ) ).toBeInTheDocument();
		// A pickup tariff is recognisable before it is chosen.
		expect( screen.getByRole( 'radio', { name: /Пункт выдачи СДЭК/ } ).closest( 'label' ) ).toHaveTextContent( 'Пункт выдачи' );
	} );

	test( 'nothing is chosen for the manager: no radio checked, no price yet, no pickup section', async () => {
		mount();
		await ready();

		expect( screen.getAllByRole( 'radio' ).some( ( radio ) => radio.checked ) ).toBe( false );
		expect( screen.queryByLabelText( 'Стоимость доставки' ) ).toBeNull();
		expect( screen.queryByText( 'Пункт выдачи', { selector: 'h4' } ) ).toBeNull();
		expect( probe.data.rest.shipping_line ).toBeNull();
	} );

	test( 'says so when no carrier offers anything for this package', async () => {
		apiFetch.mockResolvedValue( response( [ { id: 'cdek', label: 'СДЭК', rates: [] } ] ) );
		mount();

		await ready_or( 'Перевозчики не предложили тарифов. Проверьте адрес и товары на предыдущих шагах.' );
	} );

	test( 'says so when nothing in the order needs shipping', async () => {
		apiFetch.mockResolvedValue( response( [ { id: 'cdek', label: 'СДЭК', rates: [] } ], { needs_shipping: false } ) );
		mount();

		await ready_or( 'В заказе нет товаров, которым нужна доставка.' );
		expect( screen.queryByRole( 'radio' ) ).toBeNull();
	} );

	test( 'asks nothing while the package cannot be built, and tells the manager what is missing', () => {
		const data = filled();
		data.items = [];
		mount( data );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( step().getByText( 'Чтобы посчитать доставку, укажите на предыдущих шагах страну и товары.' ) ).toBeInTheDocument();
	} );

	test( 'a failed request shows the reason and «Повторить» asks again', async () => {
		apiFetch.mockRejectedValueOnce( { code: 'x', message: 'Товар №12 не найден.', data: { status: 422 } } );
		mount();

		expect( await waitFor( () => step().getByText( /Товар №12 не найден\./ ) ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Повторить' } ) );

		await ready();
		expect( rateBodies() ).toHaveLength( 2 );
		expect( step().queryByText( /Товар №12 не найден\./ ) ).toBeNull();
	} );
} );

describe( 'choosing a tariff and its price (O8)', () => {
	test( 'choosing writes the payload\'s shipping_line at the carrier\'s own price, and shows the price field', async () => {
		mount();
		fireEvent.click( await ready() );

		expect( probe.data.rest.shipping_line ).toEqual( {
			method_id: 'cdek_courier',
			instance_id: 3,
			rate_id: 'cdek_courier:3',
			label: 'Курьер',
			cost: '250.5',
			meta: { tariff_code: 137 },
		} );
		expect( screen.getByLabelText( 'Стоимость доставки' ) ).toHaveValue( 250.5 );
		expect( screen.getByText( /Тариф перевозчика: 250,50 ₽\./ ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Цена изменена.' ) ).toBeNull();
	} );

	test( 'the price is editable: the FINAL number is kept, and the carrier\'s own stays visible with a way back', async () => {
		mount();
		fireEvent.click( await ready() );

		fireEvent.change( screen.getByLabelText( 'Стоимость доставки' ), { target: { value: '0' } } );

		expect( probe.data.rest.shipping_line.cost ).toBe( '0' );
		expect( screen.getByText( 'Цена изменена.' ) ).toBeInTheDocument();
		expect( screen.getByText( /Тариф перевозчика: 250,50 ₽\./ ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Вернуть цену тарифа' } ) );

		expect( probe.data.rest.shipping_line.cost ).toBe( '250.5' );
		expect( screen.getByLabelText( 'Стоимость доставки' ) ).toHaveValue( 250.5 );
		expect( screen.queryByText( 'Цена изменена.' ) ).toBeNull();
	} );

	test( 'a re-click on the chosen tariff keeps the price the manager typed', async () => {
		mount();
		const courier = await ready();

		fireEvent.click( courier );
		fireEvent.change( screen.getByLabelText( 'Стоимость доставки' ), { target: { value: '99' } } );
		fireEvent.click( courier );

		expect( probe.data.rest.shipping_line.cost ).toBe( '99' );
	} );

	test( 'a bad price is reported on the price field, in the server\'s words', async () => {
		mount( filled(), { 'shipping_line.cost': [ 'Стоимость доставки должна быть числом не меньше нуля.' ] } );
		fireEvent.click( await ready() );

		expect( screen.getByText( 'Стоимость доставки должна быть числом не меньше нуля.' ).closest( '.woodev-order-wizard__field' ) ).toContainElement( screen.getByLabelText( 'Стоимость доставки' ) );
	} );

	test( 'a tariff that is no longer offered is dropped with a notice — it never rides into the order silently', async () => {
		const stale = filled();
		stale.rest = {
			...stale.rest,
			shipping_line: { method_id: 'cdek_old', instance_id: 9, rate_id: 'cdek_old:9', label: 'Старый', cost: '80', meta: {} },
			rate_cost: '80',
		};
		mount( stale );

		await ready_or( 'Выбранный тариф больше не подходит для этого адреса и товаров — выберите другой.' );
		expect( probe.data.rest.shipping_line ).toBeNull();
		expect( screen.queryByLabelText( 'Стоимость доставки' ) ).toBeNull();
	} );

	test( 'an order loaded for edit shows its own tariff chosen, at its own saved price', async () => {
		const loaded = filled();
		loaded.rest = {
			...loaded.rest,
			shipping_line: { method_id: 'cdek_courier', instance_id: 3, rate_id: 'cdek_courier:3', label: 'Курьер', cost: '180', meta: {} },
		};
		mount( loaded );
		const courier = await ready();

		await waitFor( () => expect( courier ).toBeChecked() );
		expect( screen.getByLabelText( 'Стоимость доставки' ) ).toHaveValue( 180 );
		expect( screen.getByText( 'Цена изменена.' ) ).toBeInTheDocument();
		expect( screen.getByText( /Тариф перевозчика: 250,50 ₽\./ ) ).toBeInTheDocument();
	} );
} );

describe( 'while the tariffs are being asked (a stale choice must not pass)', () => {
	test( 'the step marks itself pending until the answer lands, and «Далее» is refused meanwhile', async () => {
		let answer;
		apiFetch.mockReturnValue( new Promise( ( resolve ) => ( answer = resolve ) ) );
		const stale = filled();
		stale.rest = { ...stale.rest, shipping_line: { method_id: 'cdek_courier', instance_id: 3, rate_id: 'cdek_courier:3', label: 'Курьер', cost: '250.5', meta: {} }, rate_cost: '250.5' };
		mount( stale );

		await waitFor( () => expect( probe.data.rest.rates_pending ).toBe( true ) );
		expect( validateDelivery( probe.data ) ).toEqual( { shipping_line: [ 'Дождитесь расчёта тарифов.' ] } );

		answer( CDEK );
		await ready();

		await waitFor( () => expect( probe.data.rest.rates_pending ).toBe( false ) );
		expect( validateDelivery( probe.data ) ).toEqual( {} );
	} );

	test( 'a failed request stops waiting: the manager decides with the line they have, or retries', async () => {
		apiFetch.mockRejectedValue( new Error( 'Failed to fetch' ) );
		const stale = filled();
		stale.rest = { ...stale.rest, shipping_line: { method_id: 'cdek_courier', instance_id: 3, rate_id: 'cdek_courier:3', label: 'Курьер', cost: '250.5', meta: {} }, rate_cost: '250.5' };
		mount( stale );

		await screen.findByRole( 'button', { name: 'Повторить' } );

		expect( probe.data.rest.rates_pending ).toBe( false );
	} );

	test( 'leaving the step mid-flight clears the marker, so it cannot stay stuck', async () => {
		apiFetch.mockReturnValue( new Promise( () => {} ) );
		mount();

		await waitFor( () => expect( probe.data.rest.rates_pending ).toBe( true ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'leave' } ) );

		expect( probe.data.rest.rates_pending ).toBe( false );
	} );
} );

describe( 'the pickup point inside the step (D3, O10)', () => {
	const choosePvz = async () => fireEvent.click( await screen.findByRole( 'radio', { name: /Пункт выдачи СДЭК/ } ) );

	test( 'a courier tariff has no pickup section and starts no picker', async () => {
		mount();
		fireEvent.click( await ready() );

		expect( screen.queryByText( 'Пункт выдачи', { selector: 'h4' } ) ).toBeNull();
		expect( createPickupSession ).not.toHaveBeenCalled();
	} );

	test( 'a pickup tariff starts the picker in this step with the explicit context: weight, destination record, payment', async () => {
		const record = { key: 'dadata:77', level: 'settlement' };
		const data = { ...filled(), settlementRecord: record };
		data.rest = { ...data.rest, payment_method: 'cod' };
		mount( data );
		await choosePvz();

		expect( screen.getByText( 'Пункт выдачи', { selector: 'h4' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Пункт ещё не выбран — выберите его в списке или на карте.' ) ).toBeInTheDocument();

		await waitFor( () => expect( createPickupSession ).toHaveBeenCalledTimes( 1 ) );

		const options = createPickupSession.mock.calls[ 0 ][ 0 ];

		expect( options.config ).toBe( PICKER_CONFIG );
		expect( options.host ).toBeInstanceOf( HTMLElement );
		expect( options.nonce ).toBe( 'nonce-1' );
		expect( options.locality ).toBe( 'Москва' );
		expect( options.localityKey ).toBe( 'dadata:77' );
		// Read on every request by the data source; the WEIGHT comes from the rates answer, in grams.
		expect( options.context() ).toEqual( { weight: 3250, payment_method: 'cod', location: record } );
		expect( screen.getByText( 'Пункт выдачи', { selector: 'h4' } ).parentElement.querySelector( '.woodev-order-wizard__pickup-map' ) ).not.toBeNull();
	} );

	test( 'a point chosen in the picker becomes the order\'s pickup point and is shown decoded', async () => {
		mount();
		await choosePvz();
		await waitFor( () => expect( createPickupSession ).toHaveBeenCalled() );

		await pick( { id: 'P-1', name: 'ПВЗ &quot;Ромашка&quot;', address: 'ул Тверская 1' } );

		await waitFor( () => expect( probe.data.rest.pickup_point ).toEqual( { id: 'P-1', name: 'ПВЗ &quot;Ромашка&quot;', address: 'ул Тверская 1' } ) );
		expect( screen.getByText( 'ПВЗ "Ромашка" — ул Тверская 1' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Пункт ещё не выбран — выберите его в списке или на карте.' ) ).toBeNull();
		// The picker is NOT rebuilt by choosing a point in it.
		expect( createPickupSession ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'falls back to a short address when the point has no full one', async () => {
		mount();
		await choosePvz();
		await waitFor( () => expect( createPickupSession ).toHaveBeenCalled() );

		await pick( { id: 'P-2', name: 'Постамат', short_address: 'Арбат 2' } );

		expect( await screen.findByText( 'Постамат — Арбат 2' ) ).toBeInTheDocument();
	} );

	test( 'switching to a courier tariff drops the point and takes the picker down', async () => {
		mount();
		await choosePvz();
		await waitFor( () => expect( createPickupSession ).toHaveBeenCalled() );
		const session = createPickupSession.mock.results[ 0 ].value;

		await pick( { id: 'P-1', name: 'ПВЗ 1' } );
		await waitFor( () => expect( probe.data.rest.pickup_point ).not.toBeNull() );

		fireEvent.click( screen.getByRole( 'radio', { name: /Курьер/ } ) );

		expect( probe.data.rest.pickup_point ).toBeNull();
		expect( screen.queryByText( 'Пункт выдачи', { selector: 'h4' } ) ).toBeNull();
		expect( session.destroy ).toHaveBeenCalled();
	} );

	test( 'a pickup tariff without a point is refused by «Далее», in the validator\'s words, on the point', async () => {
		mount();
		await choosePvz();
		await waitFor( () => expect( probe.data.rest.rates_pending ).toBe( false ) );

		expect( validateDelivery( probe.data ) ).toEqual( { 'pickup_point.id': [ 'Для этого тарифа выберите пункт выдачи.' ] } );

		await pick( { id: 'P-1' } );
		await waitFor( () => expect( probe.data.rest.pickup_point ).not.toBeNull() );

		expect( validateDelivery( probe.data ) ).toEqual( {} );
	} );

	test( 'the point\'s problems (unknown point, refused on save) show under the picker', async () => {
		mount( filled(), { 'pickup_point.id': [ 'Для этого тарифа выберите пункт выдачи.' ] } );
		await choosePvz();

		expect( await screen.findByText( 'Для этого тарифа выберите пункт выдачи.' ) ).toBeInTheDocument();
	} );

	test( 'no picker for this carrier: the point code is typed, and that is what the order carries', async () => {
		window.woodevShippingOrders.wizard.pickup = {};
		mount();
		await choosePvz();

		expect( screen.getByText( 'Карта пунктов выдачи для этого перевозчика недоступна — укажите код пункта вручную.' ) ).toBeInTheDocument();
		expect( createPickupSession ).not.toHaveBeenCalled();

		fireEvent.change( screen.getByLabelText( 'Код пункта выдачи' ), { target: { value: ' MSK-17 ' } } );

		expect( probe.data.rest.pickup_point ).toEqual( { id: 'MSK-17' } );
	} );

	test( 'a script that did not load falls back the same way instead of mounting an empty box', async () => {
		delete window.WoodevPickupPanels;
		mount();
		await choosePvz();

		expect( screen.getByLabelText( 'Код пункта выдачи' ) ).toBeInTheDocument();
		expect( createPickupSession ).not.toHaveBeenCalled();
	} );

	test( 'an order loaded for edit shows its saved point before the picker has said anything', async () => {
		const loaded = filled();
		loaded.rest = {
			...loaded.rest,
			shipping_line: { method_id: 'cdek_pvz', instance_id: 5, rate_id: 'cdek_pvz:5', label: 'Пункт выдачи СДЭК', cost: '120', meta: {} },
			pickup_point: { id: 'P-7', name: 'ПВЗ Арбат', address: 'ул Арбат 7' },
			rate_is_pickup: true,
			rate_cost: '',
		};
		mount( loaded );

		expect( await screen.findByText( 'ПВЗ Арбат — ул Арбат 7' ) ).toBeInTheDocument();
		await waitFor( () => expect( createPickupSession ).toHaveBeenCalled() );
		expect( createPickupSession.mock.calls[ 0 ][ 0 ].selectedId ).toBe( 'P-7' );
	} );
} );
