/**
 * Step ⑤ «Оплата» of the admin order wizard (#971, increment I5b of #710): the summary of ①–④,
 * the payment method and status selects, the totals, the point re-check (D3) and the button that
 * sends the order.
 *
 * Two levels. The step is rendered on its own, with state built by the wizard's own helpers, for
 * everything that is about ⑤ itself; a few tests then walk the WHOLE shell to the real step to
 * prove the seams: the payload the send button produces, a 422 that belongs to ⑤ landing on ⑤,
 * and the «Изменить» links going back with the typed data intact.
 *
 * @see src/shipping-orders-page/order-wizard/step-payment.tsx
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor, within, act } from '@testing-library/react';
import { createElement, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import OrderWizard from '../../src/shipping-orders-page/order-wizard/order-wizard';
import StepPayment from '../../src/shipping-orders-page/order-wizard/step-payment';
import { emptyWizardData, newItem } from '../../src/shipping-orders-page/order-wizard/wizard-data';

jest.mock( '@wordpress/api-fetch' );

const ORDERS_ROOT = 'https://example.test/wp-json/woodev/v1/shipping/orders';
const POINTS_ROOT = 'https://example.test/wp-json/woodev/v1/shipping/orders/pickup/cdek/points';

const WIZARD = {
	countries: { RU: 'Россия' },
	states: { RU: { МОСКВА: 'Москва' } },
	defaultCountry: 'RU',
	currency: { code: 'RUB', symbol: '₽' },
	paymentMethods: { cod: 'Наложенный платёж', yookassa: 'ЮKassa' },
	orderStatuses: { pending: 'Ожидает оплаты', processing: 'В обработке', 'on-hold': 'На удержании', completed: 'Выполнен' },
	finalStatuses: [ 'completed', 'cancelled', 'refunded', 'failed' ],
	exportableStatuses: [ 'pending', 'on-hold', 'processing' ],
	taxesEnabled: false,
	pickup: { cdek: { restRoot: POINTS_ROOT } },
};

/** The text of a node with every kind of space (NBSP, narrow NBSP) folded to a plain one. */
const plain = ( node ) => node.textContent.replace( /\s/g, ' ' );

/** A state that has been through ①–④: one mug at 1000, a courier tariff at 250.5. */
const filledState = ( patch = {} ) => {
	const base = emptyWizardData( 'RU' );

	return {
		...base,
		billing: { ...base.billing, first_name: 'Иван', last_name: 'Петров', email: 'ivan@example.test', phone: '+79001112233' },
		shipping: { ...base.shipping, country: 'RU', state: 'МОСКВА', city: 'Москва', address_1: 'ул Тверская 1', postcode: '125009' },
		items: [ newItem( { product_id: 12, name: 'Кружка', price: '1000' } ) ],
		...patch,
		rest: {
			...base.rest,
			shipping_line: { method_id: 'cdek_courier', instance_id: 3, rate_id: 'cdek_courier:3', label: 'Курьер', cost: '250.5', meta: {} },
			rate_cost: '250.5',
			...( patch.rest || {} ),
		},
	};
};

/** The step with real state behind it, so a select's change comes back through `setData` like in the shell. */
function Harness( { initial, mode = 'create', order = null, baselineTotal = null, errors = {}, submit, busy = false, goToStep, onData } ) {
	const [ data, setData ] = useState( initial );

	onData( data );

	return createElement( StepPayment, {
		data,
		setData: ( update ) => setData( ( current ) => update( current ) ),
		errors,
		mode,
		order,
		baselineTotal,
		submit,
		busy,
		goToStep,
	} );
}

const mountStep = ( props = {} ) => {
	const submit = jest.fn( () => Promise.resolve() );
	const goToStep = jest.fn();
	let latest = null;

	render( createElement( Harness, { initial: filledState(), submit, goToStep, onData: ( d ) => ( latest = d ), ...props } ) );

	return { submit, goToStep, data: () => latest };
};

beforeEach( () => {
	apiFetch.mockReset();
	window.woodevShippingOrders = { restRoot: ORDERS_ROOT, nonce: 'nonce-1', providers: [], wizard: WIZARD };
} );

afterEach( () => {
	delete window.woodevShippingOrders;
} );

/**
 * Fake timers for a `describe` whose tests walk the WHOLE shell: they pick a product through the
 * debounced search box (300 ms of real wall-clock on real timers — #1042). RTL's `findBy*` /
 * `waitFor` notice Jest's fake clock and advance it while they poll, so the tests themselves are
 * unchanged. Scoped to those blocks on purpose: the step-on-its-own tests above never wait on a
 * timer, and two of them (the point re-check) fail under a frozen clock for reasons of their own.
 */
const withFakeTimers = () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );
};

describe( 'summary and totals', () => {
	test( 'the summary reads back ①–④ and each row has its «Изменить»', () => {
		const { goToStep } = mountStep();
		const summary = document.querySelector( '.woodev-order-wizard__summary' );

		expect( plain( summary ) ).toContain( 'Иван Петров · ivan@example.test · +79001112233' );
		expect( summary ).toHaveTextContent( 'Гость' );
		expect( plain( summary ) ).toContain( 'ул Тверская 1, Москва, Москва, 125009, Россия' );
		expect( plain( summary ) ).toContain( 'Кружка — 1 шт. × 1 000,00 ₽' );
		expect( plain( summary ) ).toContain( 'Курьер — 250,50 ₽' );

		const links = within( summary ).getAllByRole( 'button', { name: 'Изменить' } );
		expect( links ).toHaveLength( 4 );

		links.forEach( ( link ) => fireEvent.click( link ) );
		expect( goToStep.mock.calls.map( ( c ) => c[ 0 ] ) ).toEqual( [ 'customer', 'address', 'items', 'delivery' ] );
	} );

	test( 'a chosen pickup point and a registered customer are named', () => {
		const base = filledState();

		mountStep( {
			initial: filledState( {
				customer: { id: 5, create_account: false, label: 'Анна Ким (anna@example.test)' },
				rest: { ...base.rest, rate_is_pickup: true, pickup_point: { id: 'P1', name: 'ПВЗ &quot;Центр&quot;', address: 'ул Арбат 3' } },
			} ),
		} );

		expect( screen.getByText( 'Зарегистрированный покупатель: Анна Ким (anna@example.test)' ) ).toBeInTheDocument();
		// The point's fields arrive HTML-escaped from the server; the step shows them as text.
		expect( screen.getByText( 'ПВЗ "Центр" — ул Арбат 3' ) ).toBeInTheDocument();
	} );

	test( 'a guest who gets an account says so', () => {
		mountStep( { initial: filledState( { customer: { id: 0, create_account: true, label: '' } } ) } );

		expect( screen.getByText( 'Гость; при создании заказа заведём аккаунт' ) ).toBeInTheDocument();
	} );

	test( 'totals are items + delivery at the prices the manager set', () => {
		mountStep( {
			initial: filledState( {
				items: [ newItem( { product_id: 12, name: 'Кружка', price: '1000' } ), newItem( { product_id: 13, name: 'Блюдце', price: '199.5' } ) ],
			} ),
		} );
		const totals = document.querySelector( '.woodev-order-wizard__totals' );

		expect( plain( totals ) ).toContain( 'Товары1 199,50 ₽' );
		expect( plain( totals ) ).toContain( 'Доставка250,50 ₽' );
		expect( plain( totals ) ).toContain( 'Итого1 450,00 ₽' );
	} );

	test( 'with taxes on, the step admits the total in the order may be bigger', () => {
		window.woodevShippingOrders.wizard = { ...WIZARD, taxesEnabled: true };
		mountStep();

		expect( screen.getByText( /Налоги WooCommerce добавит сам/ ) ).toBeInTheDocument();
	} );

	test( 'with taxes off there is no such note', () => {
		mountStep();

		expect( screen.queryByText( /Налоги WooCommerce добавит сам/ ) ).toBeNull();
	} );
} );

describe( 'payment method and status (O7)', () => {
	test( 'the methods are «Не указан» plus the enabled gateways; picking one lands in the state', () => {
		const { data } = mountStep();
		const select = screen.getByLabelText( 'Способ оплаты' );

		expect( Array.from( select.options ).map( ( o ) => o.textContent ) ).toEqual( [ 'Не указан', 'Наложенный платёж', 'ЮKassa' ] );
		expect( select ).toHaveValue( '' );

		fireEvent.change( select, { target: { value: 'cod' } } );

		expect( data().rest.payment_method ).toBe( 'cod' );
	} );

	test( 'a gateway the order carries but the shop switched off stays selectable under its own id', () => {
		const base = filledState();

		mountStep( { initial: filledState( { rest: { ...base.rest, payment_method: 'bacs' } } ) } );

		expect( screen.getByLabelText( 'Способ оплаты' ) ).toHaveValue( 'bacs' );
	} );

	test( 'a new order shows «Ожидает оплаты» — what the server creates when no status is sent — and sends none until one is picked', () => {
		const { data } = mountStep();
		const select = screen.getByLabelText( 'Статус заказа' );

		expect( select ).toHaveValue( 'pending' );
		expect( Array.from( select.options ).map( ( o ) => o.value ) ).toEqual( [ 'pending', 'processing', 'on-hold', 'completed' ] );
		expect( data().rest.status ).toBe( '' );

		fireEvent.change( select, { target: { value: 'processing' } } );
		expect( data().rest.status ).toBe( 'processing' );
	} );

	test( 'an edit does not offer a final status as a target (the validator refuses it), and keeps the current one', () => {
		const base = filledState();

		mountStep( { mode: 'edit', initial: filledState( { rest: { ...base.rest, status: 'on-hold' } } ) } );
		const select = screen.getByLabelText( 'Статус заказа' );

		expect( select ).toHaveValue( 'on-hold' );
		expect( Array.from( select.options ).map( ( o ) => o.value ) ).toEqual( [ 'pending', 'processing', 'on-hold' ] );
	} );

	test( 'the step tells the manager where emails and stock come from instead of offering a switch', () => {
		mountStep();

		expect( screen.getByText( /Деньги не списываются\. От статуса зависит/ ) ).toBeInTheDocument();
	} );

	test( 'the server\'s words for payment_method / status show under their own selects, an unmapped one in the general list', () => {
		mountStep( {
			errors: {
				payment_method: [ 'Такого способа оплаты нет в магазине.' ],
				status: [ 'Такого статуса заказа нет.' ],
				something_else: [ 'Что-то ещё не так.' ],
			},
		} );

		expect( screen.getByText( 'Такого способа оплаты нет в магазине.' ).closest( '.woodev-order-wizard__field' ) ).toContainElement( screen.getByLabelText( 'Способ оплаты' ) );
		expect( screen.getByText( 'Такого статуса заказа нет.' ).closest( '.woodev-order-wizard__field' ) ).toContainElement( screen.getByLabelText( 'Статус заказа' ) );
		expect( screen.getByText( 'Что-то ещё не так.' ) ).toBeInTheDocument();
	} );
} );

describe( 'editing a PAID order that changes its total (O14, #972)', () => {
	/** The order as the load route reports it: one mug at 1000 and a courier at 250.5. */
	const PAID = { id: 7, number: '7', status: 'processing', status_name: 'В обработке', is_paid: true, total: '1250.50', currency: 'RUB' };
	const BASELINE = 1250.5;
	const dearer = ( state, price = '1100' ) => ( { ...state, items: [ { ...state.items[ 0 ], price } ] } );
	const warning = () => document.querySelector( '.woodev-order-wizard__paid-warning' );

	test( 'shows «было X, стало Y» and where the refund is made, and leaves the button working', () => {
		mountStep( { mode: 'edit', order: PAID, baselineTotal: BASELINE, initial: dearer( filledState() ) } );

		expect( warning() ).not.toBeNull();
		expect( plain( warning() ) ).toContain( 'Заказ уже оплачен, а его сумма меняется: было 1 250,50 ₽, стало 1 350,50 ₽.' );
		expect( plain( warning() ) ).toContain( 'Возврат или доплату сделайте средствами WooCommerce' );
		expect( plain( warning() ) ).toContain( 'в заказ добавится заметка' );
		expect( screen.getByRole( 'button', { name: 'Сохранить' } ) ).toBeEnabled();
	} );

	test( 'says nothing while the total is what it was', () => {
		mountStep( { mode: 'edit', order: PAID, baselineTotal: BASELINE, initial: filledState() } );

		expect( warning() ).toBeNull();
	} );

	test( 'says nothing for an order nobody paid', () => {
		mountStep( { mode: 'edit', order: { ...PAID, is_paid: false }, baselineTotal: BASELINE, initial: dearer( filledState() ) } );

		expect( warning() ).toBeNull();
	} );

	test( 'says nothing on a create', () => {
		mountStep( { mode: 'create', order: null, baselineTotal: null, initial: dearer( filledState() ) } );

		expect( warning() ).toBeNull();
	} );

	test( 'the new total moves with the price', () => {
		const { data } = mountStep( { mode: 'edit', order: PAID, baselineTotal: BASELINE, initial: dearer( filledState(), '1200' ) } );

		expect( plain( warning() ) ).toContain( 'стало 1 450,50 ₽' );
		expect( data().items[ 0 ].price ).toBe( '1200' );
	} );

	test( 'with taxes on, the new total is only an estimate and says so', () => {
		window.woodevShippingOrders.wizard = { ...WIZARD, taxesEnabled: true };

		mountStep( { mode: 'edit', order: PAID, baselineTotal: BASELINE, initial: dearer( filledState() ) } );

		expect( plain( warning() ) ).toContain( 'было 1 250,50 ₽, станет примерно 1 350,50 ₽.' );
	} );
} );

describe( 'the send button', () => {
	test( '«Создать заказ» on create calls the shell\'s submit', () => {
		const { submit } = mountStep();

		fireEvent.click( screen.getByRole( 'button', { name: 'Создать заказ' } ) );
		expect( submit ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'on edit the button reads «Сохранить»', () => {
		mountStep( { mode: 'edit' } );

		expect( screen.getByRole( 'button', { name: 'Сохранить' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Создать заказ' } ) ).toBeNull();
	} );

	test( 'while a request is in flight the button is disabled and the way back is closed', () => {
		mountStep( { busy: true } );

		expect( screen.getByRole( 'button', { name: 'Создать заказ' } ) ).toBeDisabled();
		within( document.querySelector( '.woodev-order-wizard__summary' ) )
			.getAllByRole( 'button', { name: 'Изменить' } )
			.forEach( ( link ) => expect( link ).toBeDisabled() );
	} );
} );

describe( 'immediate export — «Сразу выгрузить перевозчику» (O9, D6, #974)', () => {
	const BOX = 'Сразу выгрузить перевозчику';

	test( 'a create offers the box, unticked, with the promise that the order is made either way', () => {
		mountStep();

		expect( screen.getByLabelText( BOX ) ).not.toBeChecked();
		expect( screen.getByText( /Заказ создастся в любом случае/ ) ).toBeInTheDocument();
	} );

	test( 'an edit never exports (O4): there is no box', () => {
		mountStep( { mode: 'edit' } );

		expect( screen.queryByLabelText( BOX ) ).toBeNull();
	} );

	test( 'ticking it lands in the state and the button says what will happen', () => {
		const { data } = mountStep();

		fireEvent.click( screen.getByLabelText( BOX ) );

		expect( data().rest.export_now ).toBe( true );
		expect( screen.getByRole( 'button', { name: 'Создать и выгрузить' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Создать заказ' } ) ).toBeNull();
	} );

	test( 'a status the export is not offered in takes the tick back and closes the box, naming the statuses that work', () => {
		const { data } = mountStep();

		fireEvent.click( screen.getByLabelText( BOX ) );
		fireEvent.change( screen.getByLabelText( 'Статус заказа' ), { target: { value: 'completed' } } );

		expect( data().rest.export_now ).toBe( false );
		expect( screen.getByLabelText( BOX ) ).toBeDisabled();
		expect( screen.getByLabelText( BOX ) ).not.toBeChecked();
		expect( screen.getByText( 'Выгрузить можно заказ в статусах: Ожидает оплаты, На удержании, В обработке.' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Создать заказ' } ) ).toBeInTheDocument();

		// Back to a status that works: the box opens again, unticked — the manager decides anew.
		fireEvent.change( screen.getByLabelText( 'Статус заказа' ), { target: { value: 'processing' } } );

		expect( screen.getByLabelText( BOX ) ).not.toBeDisabled();
		expect( screen.getByLabelText( BOX ) ).not.toBeChecked();
	} );

	test( 'while a request is in flight the box is locked', () => {
		mountStep( { busy: true } );

		expect( screen.getByLabelText( BOX ) ).toBeDisabled();
	} );
} );

describe( 'the chosen pickup point vs the payment method (D3: ⑤ re-validates)', () => {
	const pickupState = ( check = { provider: 'cdek', weight: 3250 } ) => {
		const base = filledState();

		return filledState( { rest: { ...base.rest, rate_is_pickup: true, pickup_point: { id: 'P/1', name: 'Центр', address: 'ул Арбат 3' }, pickup_check: check } } );
	};

	// `Notice` also speaks its sentence into a live region outside the step, so a lookup on the whole document can find two.
	const inStep = () => within( document.querySelector( '.woodev-order-wizard__step' ) );
	const pointCalls = () => apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).filter( ( r ) => r.url.startsWith( POINTS_ROOT ) );

	test( 'nothing is asked until a payment method is chosen', () => {
		mountStep( { initial: pickupState() } );

		expect( pointCalls() ).toHaveLength( 0 );
		expect( screen.getByRole( 'button', { name: 'Создать заказ' } ) ).toBeEnabled();
	} );

	test( 'choosing a method asks the admin detail route with the weight and the method, and a refusal stops the order', async () => {
		apiFetch.mockResolvedValue( { id: 'P/1', selectable: { allowed: false, reason: 'Пункт не принимает наложенный платёж.' } } );
		const { goToStep } = mountStep( { initial: pickupState() } );

		fireEvent.change( screen.getByLabelText( 'Способ оплаты' ), { target: { value: 'cod' } } );

		const notice = await inStep().findByText( /не подходит для способа оплаты «Наложенный платёж»/ );
		expect( notice ).toHaveTextContent( 'Пункт не принимает наложенный платёж.' );
		expect( screen.getByRole( 'button', { name: 'Создать заказ' } ) ).toBeDisabled();

		const [ request ] = pointCalls();
		expect( request.url ).toBe( `${ POINTS_ROOT }/P%2F1?weight=3250&payment_method=cod` );
		expect( request.method ).toBe( 'GET' );
		expect( request.headers ).toEqual( { 'X-WP-Nonce': 'nonce-1' } );

		fireEvent.click( within( notice.closest( '.components-notice' ) ).getByRole( 'button', { name: 'Выбрать другой пункт' } ) );
		expect( goToStep ).toHaveBeenCalledWith( 'delivery' );
	} );

	test( 'the destination record step ② picked travels with the check as PHP-style nested params (#959)', async () => {
		apiFetch.mockResolvedValue( { id: 'P/1', selectable: { allowed: true } } );
		const record = { provider_id: 'cdek', country: 'RU', settlement: { id: '44', name: 'Москва', empty: '', gone: null }, flags: [ 'a', 'b' ] };
		mountStep( { initial: { ...pickupState(), settlementRecord: record } } );

		fireEvent.change( screen.getByLabelText( 'Способ оплаты' ), { target: { value: 'cod' } } );

		await waitFor( () => expect( pointCalls() ).toHaveLength( 1 ) );

		const { url } = pointCalls()[ 0 ];
		const query = url.slice( url.indexOf( '?' ) + 1 );

		// The flattening of the storefront's `pickup-datasource.js`: brackets kept, empty / null skipped.
		expect( query ).toBe(
			'weight=3250&payment_method=cod&location[provider_id]=cdek&location[country]=RU&location[settlement][id]=44' +
				`&location[settlement][name]=${ encodeURIComponent( 'Москва' ) }&location[flags][0]=a&location[flags][1]=b`
		);
	} );

	test( 'a new destination re-asks the route; a hand-typed city sends no location', async () => {
		apiFetch.mockResolvedValue( { id: 'P/1', selectable: { allowed: true } } );
		const base = pickupState();
		const props = { setData: jest.fn(), errors: {}, mode: 'create', order: null, baselineTotal: null, submit: jest.fn(), busy: false, goToStep: jest.fn() };
		const withRecord = ( settlementRecord ) => createElement( StepPayment, { ...props, data: { ...base, rest: { ...base.rest, payment_method: 'cod' }, settlementRecord } } );
		const { rerender } = render( withRecord( { provider_id: 'cdek', settlement: { id: '44' } } ) );

		await waitFor( () => expect( pointCalls() ).toHaveLength( 1 ) );
		expect( pointCalls()[ 0 ].url ).toContain( 'location[settlement][id]=44' );

		rerender( withRecord( null ) );

		await waitFor( () => expect( pointCalls() ).toHaveLength( 2 ) );
		expect( pointCalls()[ 1 ].url ).toBe( `${ POINTS_ROOT }/P%2F1?weight=3250&payment_method=cod` );
	} );

	test( 'changing the method to one the point accepts lifts the stop', async () => {
		apiFetch.mockImplementation( ( request ) =>
			Promise.resolve( { selectable: request.url.includes( 'payment_method=cod' ) ? { allowed: false, reason: '' } : { allowed: true } } )
		);
		mountStep( { initial: pickupState() } );

		fireEvent.change( screen.getByLabelText( 'Способ оплаты' ), { target: { value: 'cod' } } );
		await inStep().findByText( /не подходит для способа оплаты/ );

		fireEvent.change( screen.getByLabelText( 'Способ оплаты' ), { target: { value: 'yookassa' } } );

		// (`Notice` also speaks into a live region outside the step, which keeps the old sentence.)
		await waitFor( () => expect( document.querySelector( '.components-notice' ) ).toBeNull() );
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Создать заказ' } ) ).toBeEnabled() );
	} );

	test( 'a point the route no longer knows (404) is refused with a sentence of its own', async () => {
		apiFetch.mockRejectedValue( { code: 'woodev_pickup_point_not_found', message: 'x', data: { status: 404 } } );
		mountStep( { initial: pickupState() } );

		fireEvent.change( screen.getByLabelText( 'Способ оплаты' ), { target: { value: 'cod' } } );

		expect( await inStep().findByText( /Пункт выдачи не найден — выберите другой\./ ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Создать заказ' } ) ).toBeDisabled();
	} );

	test( 'an answer without a verdict, or a failed request, never blocks — the checker is permissive by omission', async () => {
		apiFetch.mockResolvedValueOnce( { id: 'P/1' } );
		mountStep( { initial: pickupState() } );

		fireEvent.change( screen.getByLabelText( 'Способ оплаты' ), { target: { value: 'cod' } } );
		await waitFor( () => expect( pointCalls() ).toHaveLength( 1 ) );
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Создать заказ' } ) ).toBeEnabled() );

		apiFetch.mockRejectedValueOnce( new Error( 'Failed to fetch' ) );
		fireEvent.change( screen.getByLabelText( 'Способ оплаты' ), { target: { value: 'yookassa' } } );
		await waitFor( () => expect( pointCalls() ).toHaveLength( 2 ) );
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Создать заказ' } ) ).toBeEnabled() );
		expect( document.querySelector( '.components-notice' ) ).toBeNull();
	} );

	test( 'M1: while the check is in flight the button is off and does not send; once the check allows, it sends', async () => {
		let answer;
		apiFetch.mockImplementation( () => new Promise( ( resolve ) => ( answer = resolve ) ) );
		const { submit } = mountStep( { initial: pickupState() } );

		fireEvent.change( screen.getByLabelText( 'Способ оплаты' ), { target: { value: 'cod' } } );

		const button = screen.getByRole( 'button', { name: 'Создать заказ' } );

		await waitFor( () => expect( pointCalls() ).toHaveLength( 1 ) );
		expect( button ).toBeDisabled();
		fireEvent.click( button );
		expect( submit ).not.toHaveBeenCalled();

		answer( { id: 'P/1', selectable: { allowed: true } } );

		await waitFor( () => expect( button ).toBeEnabled() );
		fireEvent.click( button );
		expect( submit ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'M1: a refusal that arrives after the wait still blocks; a FAILED check ends the wait and stays permissive', async () => {
		let answer;
		apiFetch.mockImplementation( () => new Promise( ( resolve, reject ) => ( answer = { resolve, reject } ) ) );
		const { submit } = mountStep( { initial: pickupState() } );

		fireEvent.change( screen.getByLabelText( 'Способ оплаты' ), { target: { value: 'cod' } } );
		await waitFor( () => expect( pointCalls() ).toHaveLength( 1 ) );
		answer.resolve( { selectable: { allowed: false, reason: '' } } );
		await inStep().findByText( /не подходит для способа оплаты/ );
		expect( screen.getByRole( 'button', { name: 'Создать заказ' } ) ).toBeDisabled();

		fireEvent.change( screen.getByLabelText( 'Способ оплаты' ), { target: { value: 'yookassa' } } );
		await waitFor( () => expect( pointCalls() ).toHaveLength( 2 ) );
		expect( screen.getByRole( 'button', { name: 'Создать заказ' } ) ).toBeDisabled();
		answer.reject( new Error( 'Failed to fetch' ) );

		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Создать заказ' } ) ).toBeEnabled() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Создать заказ' } ) );
		expect( submit ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'no check for a courier tariff', () => {
		mountStep( { initial: filledState( { rest: { ...filledState().rest, payment_method: 'cod' } } ) } );

		expect( pointCalls() ).toHaveLength( 0 );
	} );

	test( 'a pickup tariff whose carrier has no picker config is not asked about', () => {
		const state = pickupState( { provider: 'boxberry', weight: 500 } );

		mountStep( { initial: { ...state, rest: { ...state.rest, payment_method: 'cod' } } } );

		expect( pointCalls() ).toHaveLength( 0 );
	} );

	test( 'a pickup state the rates have not answered for yet (no check info) is not asked about either', () => {
		const state = pickupState( null );

		mountStep( { initial: { ...state, rest: { ...state.rest, payment_method: 'cod' } } } );

		expect( pointCalls() ).toHaveLength( 0 );
	} );
} );

// --- the whole shell, up to the real step ⑤ ---------------------------------------------------

const RATES = {
	destination: { country: 'RU' },
	needs_shipping: true,
	weight: 1000,
	zone: { id: 1, name: 'Россия' },
	providers: [
		{
			id: 'cdek',
			label: 'СДЭК',
			rates: [ { id: 'cdek_courier:3', method_id: 'cdek_courier', instance_id: 3, label: 'Курьер', cost: 250.5, delivery_time: '', description: '', is_pickup: false, meta: {} } ],
		},
	],
};

const routeApi = ( save, edit = null ) => {
	apiFetch.mockImplementation( ( request ) => {
		if ( request.url.endsWith( '/shipping/orders/rates' ) ) {
			return Promise.resolve( RATES );
		}

		if ( edit && request.url === `${ ORDERS_ROOT }/7/edit` ) {
			return Promise.resolve( edit.prefill );
		}

		if ( edit && request.url === `${ ORDERS_ROOT }/7` && 'PUT' === request.method ) {
			return Promise.resolve( edit.saved );
		}

		if ( request.url.includes( '/wc/v3/products' ) ) {
			return Promise.resolve( [ { id: 12, name: 'Кружка', type: 'simple', sku: 'MUG', price: '1000' } ] );
		}

		if ( request.url === ORDERS_ROOT && save ) {
			return save( request );
		}

		return Promise.reject( { message: `unexpected request ${ request.url }` } );
	} );
};

const type = ( label, value ) => fireEvent.change( screen.getByLabelText( label ), { target: { value } } );
const next = () => fireEvent.click( screen.getByRole( 'button', { name: 'Далее' } ) );

const walkToPayment = async () => {
	const onClose = jest.fn();
	const onSaved = jest.fn();

	render( createElement( OrderWizard, { onClose, onSaved } ) );

	type( 'Имя', 'Иван' );
	type( 'Фамилия', 'Петров' );
	next();
	type( 'Регион', 'МОСКВА' );
	type( 'Город или населённый пункт', 'Москва' );
	type( 'Улица, дом', 'ул Тверская 1' );
	next();
	type( 'Добавить товар', 'кружка' );
	fireEvent.click( await screen.findByRole( 'option', { name: /Кружка/ } ) );
	next();
	fireEvent.click( await screen.findByRole( 'radio', { name: /Курьер/ } ) );
	next();
	await screen.findByRole( 'button', { name: 'Создать заказ' } );

	return { onClose, onSaved };
};

describe( 'a point code typed by hand is checked before «Далее» leaves ④ (m6)', () => {
	withFakeTimers();

	const PVZ_RATE = { id: 'cdek_pvz:5', method_id: 'cdek_pvz', instance_id: 5, label: 'Пункт СДЭК', cost: 120, delivery_time: '', description: '', is_pickup: true, meta: {} };
	const pointCalls = () => apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).filter( ( r ) => r.url.startsWith( POINTS_ROOT ) );

	// No picker scripts on the page in this file, so ④ falls back to a typed point code.
	const walkToDelivery = async ( points ) => {
		apiFetch.mockImplementation( ( request ) => {
			if ( request.url.endsWith( '/shipping/orders/rates' ) ) {
				return Promise.resolve( { ...RATES, providers: [ { id: 'cdek', label: 'СДЭК', rates: [ PVZ_RATE ] } ] } );
			}

			if ( request.url.includes( '/wc/v3/products' ) ) {
				return Promise.resolve( [ { id: 12, name: 'Кружка', type: 'simple', sku: 'MUG', price: '1000' } ] );
			}

			if ( request.url.startsWith( POINTS_ROOT ) ) {
				return points( request );
			}

			return Promise.reject( { message: `unexpected request ${ request.url }` } );
		} );
		render( createElement( OrderWizard, { onClose: jest.fn(), onSaved: jest.fn() } ) );

		type( 'Имя', 'Иван' );
		type( 'Фамилия', 'Петров' );
		next();
		type( 'Регион', 'МОСКВА' );
		type( 'Город или населённый пункт', 'Москва' );
		type( 'Улица, дом', 'ул Тверская 1' );
		next();
		type( 'Добавить товар', 'кружка' );
		fireEvent.click( await screen.findByRole( 'option', { name: /Кружка/ } ) );
		next();
		fireEvent.click( await screen.findByRole( 'radio', { name: /Пункт СДЭК/ } ) );
		await screen.findByLabelText( 'Код пункта выдачи' );
	};

	test( 'an unknown point stays on ④ with a field error; a known one goes on to ⑤', async () => {
		await walkToDelivery( ( request ) =>
			request.url.includes( '/NOPE?' )
				? Promise.reject( { code: 'woodev_pickup_point_not_found', message: 'x', data: { status: 404 } } )
				: Promise.resolve( { id: 'GOOD', selectable: { allowed: true } } )
		);

		type( 'Код пункта выдачи', 'NOPE' );
		next();

		expect( await screen.findByText( 'Пункт выдачи не найден — выберите другой.' ) ).toBeInTheDocument();
		expect( pointCalls() ).toHaveLength( 1 );
		expect( screen.getByLabelText( 'Код пункта выдачи' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Создать заказ' } ) ).toBeNull();

		type( 'Код пункта выдачи', 'GOOD' );
		next();

		await screen.findByRole( 'button', { name: 'Создать заказ' } );
		expect( pointCalls()[ 1 ].url ).toContain( '/GOOD?weight=1000' );
	} );

	test( 'a code changed while its check is in flight: the old answer neither advances nor blocks, and «Далее» asks about the new one', async () => {
		let releaseGood;

		await walkToDelivery( ( request ) => {
			if ( request.url.includes( '/GOOD?' ) ) {
				return new Promise( ( resolve ) => {
					releaseGood = () => resolve( { id: 'GOOD', selectable: { allowed: true } } );
				} );
			}

			return Promise.reject( { code: 'woodev_pickup_point_not_found', message: 'x', data: { status: 404 } } );
		} );

		type( 'Код пункта выдачи', 'GOOD' );
		next();
		await waitFor( () => expect( pointCalls() ).toHaveLength( 1 ) );

		// The input is still editable while the answer is on its way.
		type( 'Код пункта выдачи', 'BAD' );
		await act( async () => {
			releaseGood();
		} );

		// The answer was about GOOD: ④ stays, BAD is not accepted, and the old answer put no error on BAD.
		expect( screen.getByLabelText( 'Код пункта выдачи' ) ).toHaveValue( 'BAD' );
		expect( screen.queryByRole( 'button', { name: 'Создать заказ' } ) ).toBeNull();
		expect( screen.queryByText( 'Пункт выдачи не найден — выберите другой.' ) ).toBeNull();

		next();

		expect( await screen.findByText( 'Пункт выдачи не найден — выберите другой.' ) ).toBeInTheDocument();
		expect( pointCalls()[ 1 ].url ).toContain( '/BAD?weight=1000' );
		expect( screen.queryByRole( 'button', { name: 'Создать заказ' } ) ).toBeNull();
	} );

	test( 'a check that fails (network) never holds the manager on ④', async () => {
		await walkToDelivery( () => Promise.reject( new Error( 'Failed to fetch' ) ) );

		type( 'Код пункта выдачи', 'ANY' );
		next();

		await screen.findByRole( 'button', { name: 'Создать заказ' } );
	} );
} );

describe( 'the real step inside the shell', () => {
	withFakeTimers();

	test( 'the send button POSTs the whole order with the payment method and status picked on ⑤, then reports and closes', async () => {
		routeApi( () => Promise.resolve( { id: 91, number: '91', message: 'Заказ №91 создан.' } ) );
		const { onSaved, onClose } = await walkToPayment();

		fireEvent.change( screen.getByLabelText( 'Способ оплаты' ), { target: { value: 'cod' } } );
		fireEvent.change( screen.getByLabelText( 'Статус заказа' ), { target: { value: 'processing' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Создать заказ' } ) );

		await waitFor( () => expect( onSaved ).toHaveBeenCalledTimes( 1 ) );
		expect( onSaved.mock.calls[ 0 ][ 0 ] ).toMatchObject( { id: 91, message: 'Заказ №91 создан.' } );
		expect( onSaved.mock.calls[ 0 ][ 1 ] ).toBe( 'create' );
		expect( onClose ).toHaveBeenCalled();

		const posts = apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).filter( ( r ) => 'POST' === r.method && r.url === ORDERS_ROOT );

		expect( posts ).toHaveLength( 1 );
		expect( posts[ 0 ].data ).toMatchObject( { payment_method: 'cod', status: 'processing', items: [ { product_id: 12, variation_id: 0, quantity: 1, price: '1000' } ] } );
		expect( posts[ 0 ].data.shipping_line ).toMatchObject( { rate_id: 'cdek_courier:3', cost: '250.5' } );
		expect( JSON.stringify( posts[ 0 ].data ) ).not.toMatch( /pickup_check|rate_cost|rates_pending/ );
	} );

	test( 'untouched selects send no status — the server\'s own default — and the empty payment method', async () => {
		routeApi( () => Promise.resolve( { id: 92, number: '92', message: 'Заказ №92 создан.' } ) );
		const { onSaved } = await walkToPayment();

		fireEvent.click( screen.getByRole( 'button', { name: 'Создать заказ' } ) );

		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		const post = apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).find( ( r ) => 'POST' === r.method && r.url === ORDERS_ROOT );

		expect( post.data ).not.toHaveProperty( 'status' );
		expect( post.data.payment_method ).toBe( '' );
	} );

	test( 'the ticked box sends export_now beside the order, and the outcome — the carrier\'s own text — reaches the host with the created order', async () => {
		routeApi( () =>
			Promise.resolve( {
				id: 93,
				number: '93',
				message: 'Заказ №93 создан, но не выгружен. СДЭК: Неверный индекс получателя',
				export: { success: false, message: 'СДЭК: Неверный индекс получателя' },
			} )
		);
		const { onSaved, onClose } = await walkToPayment();

		fireEvent.click( screen.getByLabelText( 'Сразу выгрузить перевозчику' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Создать и выгрузить' } ) );

		await waitFor( () => expect( onSaved ).toHaveBeenCalledTimes( 1 ) );

		// The order was created either way: the wizard closes, the host reports the carrier's refusal.
		expect( onSaved.mock.calls[ 0 ][ 0 ] ).toMatchObject( { id: 93, export: { success: false, message: 'СДЭК: Неверный индекс получателя' } } );
		expect( onClose ).toHaveBeenCalled();

		const post = apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).find( ( r ) => 'POST' === r.method && r.url === ORDERS_ROOT );

		expect( post.data.export_now ).toBe( true );
		expect( post.data ).not.toHaveProperty( 'status' );
	} );

	test( 'an unticked box sends no export_now at all', async () => {
		routeApi( () => Promise.resolve( { id: 94, number: '94', message: 'Заказ №94 создан.' } ) );
		const { onSaved } = await walkToPayment();

		fireEvent.click( screen.getByRole( 'button', { name: 'Создать заказ' } ) );

		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		const post = apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).find( ( r ) => 'POST' === r.method && r.url === ORDERS_ROOT );

		expect( post.data ).not.toHaveProperty( 'export_now' );
	} );

	test( 'a 422 about the status stays on ⑤ and shows under the status select', async () => {
		routeApi( () =>
			Promise.reject( {
				code: 'woodev_shipping_order_invalid',
				message: 'Заказ не сохранён: проверьте отмеченные поля.',
				data: { status: 422, errors: [ { field: 'status', code: 'unknown_status', message: 'Такого статуса заказа нет.' } ] },
			} )
		);
		const { onSaved, onClose } = await walkToPayment();

		fireEvent.click( screen.getByRole( 'button', { name: 'Создать заказ' } ) );

		const message = await screen.findByText( 'Такого статуса заказа нет.' );
		expect( message.closest( '.woodev-order-wizard__field' ) ).toContainElement( screen.getByLabelText( 'Статус заказа' ) );
		expect( screen.getAllByText( 'Заказ не сохранён: проверьте отмеченные поля.' ).length ).toBeGreaterThan( 0 );
		expect( screen.getByRole( 'button', { name: 'Создать заказ' } ) ).toBeEnabled();
		expect( onSaved ).not.toHaveBeenCalled();
		expect( onClose ).not.toHaveBeenCalled();
	} );

	test( 'a 422 about the delivery goes back to ④ and shows on its field', async () => {
		routeApi( () =>
			Promise.reject( {
				code: 'woodev_shipping_order_invalid',
				message: 'Заказ не сохранён: проверьте отмеченные поля.',
				data: { status: 422, errors: [ { field: 'shipping_line', code: 'unknown_rate', message: 'Такой тариф больше не предлагается.' } ] },
			} )
		);
		await walkToPayment();

		fireEvent.click( screen.getByRole( 'button', { name: 'Создать заказ' } ) );

		expect( await screen.findByText( 'Такой тариф больше не предлагается.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Как доставить', { selector: 'h3' } ) ).toBeInTheDocument();
	} );

	test( '«Изменить» on the summary returns to that step with what was typed still there', async () => {
		routeApi();
		await walkToPayment();

		fireEvent.click( within( document.querySelector( '.woodev-order-wizard__summary' ) ).getAllByRole( 'button', { name: 'Изменить' } )[ 0 ] );

		expect( screen.getByLabelText( 'Имя' ) ).toHaveValue( 'Иван' );
		expect( screen.getByLabelText( 'Фамилия' ) ).toHaveValue( 'Петров' );
	} );

	test( 'the wizard is not left busy after a failed send: the button works again', async () => {
		let calls = 0;

		routeApi( () => {
			calls += 1;

			return 1 === calls
				? Promise.reject( { code: 'x', message: 'Временная ошибка.', data: { status: 500 } } )
				: Promise.resolve( { id: 93, number: '93', message: 'Заказ №93 создан.' } );
		} );
		const { onSaved } = await walkToPayment();

		fireEvent.click( screen.getByRole( 'button', { name: 'Создать заказ' } ) );
		expect( await within( screen.getByRole( 'dialog' ) ).findByText( 'Временная ошибка.' ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Создать заказ' } ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalledTimes( 1 ) );
	} );
} );

describe( 'editing an order through the whole shell (#972)', () => {
	withFakeTimers();

	/** `Order_Editor::build_prefill()` for a PAID order: one mug at 1000 and the courier at 250.5, saved at 1250.50. */
	const PREFILL = {
		order: { id: 7, number: '7', status: 'processing', status_name: 'В обработке', is_paid: true, total: '1250.50', currency: 'RUB' },
		carrier: 'cdek',
		customer: { id: 0, create_account: false },
		billing: { first_name: 'Анна', last_name: 'Ким', phone: '+79001112233', email: 'anna@example.test', country: 'RU', state: 'МОСКВА', city: 'Москва', address_1: 'ул Тверская 1' },
		shipping: { first_name: 'Анна', last_name: 'Ким', country: 'RU', state: 'МОСКВА', city: 'Москва', address_1: 'ул Тверская 1' },
		items: [ { item_id: 11, product_id: 12, variation_id: 0, name: 'Кружка', quantity: 1, price: '1000.00' } ],
		shipping_line: { method_id: 'cdek_courier', instance_id: 3, rate_id: 'cdek_courier:3', label: 'Курьер', cost: '250.5', meta: { Items: 'Кружка × 1' } },
		pickup_point: null,
		fields: {},
		carrier_fields: {},
		payment_method: 'cod',
		status: 'processing',
	};

	const SAVED = { id: 7, number: '7', message: 'Заказ №7 сохранён.' };

	/** Opens the order and walks ①→⑤ (the saved tariff must come back from the rates before ④ lets it through). */
	const walkEdit = async ( prefill, beforeDelivery = () => {} ) => {
		routeApi( null, { prefill, saved: SAVED } );

		const onClose = jest.fn();
		const onSaved = jest.fn();

		render( createElement( OrderWizard, { orderId: 7, onClose, onSaved } ) );

		await screen.findByRole( 'dialog', { name: 'Редактировать заказ №7' } );
		next(); // ① → ②
		next(); // ② → ③
		beforeDelivery();
		next(); // ③ → ④
		await waitFor( () => expect( screen.getByRole( 'radio', { name: /Курьер/ } ) ).toBeChecked() );
		next(); // ④ → ⑤
		await screen.findByRole( 'button', { name: 'Сохранить' } );

		return { onClose, onSaved };
	};

	test( 'the saved tariff is preselected on ④ at its saved price, and ⑤ opens on the order\'s payment method and status', async () => {
		await walkEdit( PREFILL );

		expect( screen.getByLabelText( 'Способ оплаты' ) ).toHaveValue( 'cod' );
		expect( screen.getByLabelText( 'Статус заказа' ) ).toHaveValue( 'processing' );
		// Totals are recalculated from the lines, at the saved prices.
		expect( plain( document.querySelector( '.woodev-order-wizard__totals-sum' ) ) ).toContain( '1 250,50 ₽' );
	} );

	test( 'an untouched paid order shows no warning, and PUTs the order back as it was', async () => {
		const { onSaved } = await walkEdit( PREFILL );

		expect( document.querySelector( '.woodev-order-wizard__paid-warning' ) ).toBeNull();

		fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalledTimes( 1 ) );

		const put = apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).find( ( r ) => 'PUT' === r.method );

		expect( put.url ).toBe( `${ ORDERS_ROOT }/7` );
		expect( put.data.items ).toEqual( [ { item_id: 11, product_id: 12, variation_id: 0, quantity: 1, price: '1000.00' } ] );
		expect( put.data.shipping_line ).toMatchObject( { rate_id: 'cdek_courier:3', cost: '250.5' } );
		expect( onSaved.mock.calls[ 0 ][ 1 ] ).toBe( 'edit' );
	} );

	test( 'a price changed on ③ reaches ⑤ as «было X, стало Y» and the total there is recalculated', async () => {
		await walkEdit( PREFILL, () => fireEvent.change( screen.getByLabelText( 'Цена за шт.' ), { target: { value: '1100' } } ) );

		const warning = document.querySelector( '.woodev-order-wizard__paid-warning' );

		expect( warning ).not.toBeNull();
		expect( plain( warning ) ).toContain( 'было 1 250,50 ₽, стало 1 350,50 ₽' );
		expect( plain( document.querySelector( '.woodev-order-wizard__totals-sum' ) ) ).toContain( '1 350,50 ₽' );
	} );

	test( 'the same change on an order nobody paid shows no warning', async () => {
		await walkEdit(
			{ ...PREFILL, order: { ...PREFILL.order, is_paid: false } },
			() => fireEvent.change( screen.getByLabelText( 'Цена за шт.' ), { target: { value: '1100' } } )
		);

		expect( document.querySelector( '.woodev-order-wizard__paid-warning' ) ).toBeNull();
	} );

	test( 'looking through an edit and pressing «Отмена» never asks «Закрыть окно?» — the re-quote is not an edit', async () => {
		const { onClose } = await walkEdit( PREFILL );

		fireEvent.click( screen.getByRole( 'button', { name: 'Отмена' } ) );

		expect( screen.queryByText( 'Закрыть окно? Введённые данные не сохранятся.' ) ).toBeNull();
		expect( onClose ).toHaveBeenCalledTimes( 1 );
	} );
} );
