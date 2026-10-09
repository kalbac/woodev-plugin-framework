/**
 * The dialog behind a carrier's toolbar button (s164): a form tab submitted once for several orders, list tabs with
 * row buttons, and what each answer does to the dialog. `./rest` is mocked — these drive the dialog's own wiring.
 *
 * @see src/shipping-orders-page/toolbar-dialog.tsx
 */

import '@testing-library/jest-dom';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { ToolbarDialog } from '../../src/shipping-orders-page/toolbar-dialog';
import { fetchToolbarDialog, performToolbarRowAction, submitToolbarAction } from '../../src/shipping-orders-page/rest';

jest.mock( '../../src/shipping-orders-page/rest', () => ( {
	fetchToolbarDialog: jest.fn(),
	submitToolbarAction: jest.fn(),
	performToolbarRowAction: jest.fn(),
} ) );

const BUTTON = { id: 'call_courier', label: 'Вызвать курьера', title: '', icon: 'car', count: 3 };

const ORDERS = {
	id: 'orders',
	type: 'orders',
	label: 'Заказы',
	required: true,
	default: [ '1047', '1050', '1051' ],
	options: [
		{ value: '1047', label: '#1047 · Екатеринбург' },
		{ value: '1050', label: '#1050 · Москва' },
		{ value: '1051', label: '#1051 · Казань' },
	],
};

const FORM_TAB = {
	id: 'call',
	type: 'form',
	label: 'Вызов',
	submit_label: 'Вызвать',
	fields: [
		ORDERS,
		{ id: 'day', type: 'date', label: 'День', required: true, default: '2026-10-12', help: 'На один адрес — один вызов в день.' },
		{ id: 'comment', type: 'textarea', label: 'Комментарий', required: false, default: '', maxlength: 200 },
	],
};

const LIST_TAB = {
	id: 'intakes',
	type: 'list',
	label: 'Заявки',
	columns: [
		{ id: 'number', label: '№' },
		{ id: 'day', label: 'День' },
	],
	rows: [
		{
			id: 'uuid-1',
			cells: { number: '13312783', day: '12.10' },
			actions: [ { action: 'cancel', label: 'Отменить', title: '', destructive: true, icon: '', confirm: 'После отмены СДЭК может не принять новый вызов.' } ],
		},
		{
			id: 'uuid-2',
			cells: { number: '13312784', day: '13.10' },
			actions: [ { action: 'refresh', label: 'Обновить', title: '', destructive: false, icon: '' } ],
		},
	],
	empty: 'Заявок пока нет.',
};

const dialogOf = ( tabs = [ FORM_TAB, LIST_TAB ], extra = {} ) => ( { title: 'Вызвать курьера', tabs, ...extra } );

function setup( dialog = dialogOf() ) {
	fetchToolbarDialog.mockResolvedValue( { id: BUTTON.id, dialog } );

	const onClose = jest.fn();
	const onChanged = jest.fn();

	render( <ToolbarDialog button={ BUTTON } onClose={ onClose } onChanged={ onChanged } /> );

	return { onClose, onChanged };
}

const submitButton = () => screen.findByRole( 'button', { name: 'Вызвать' } );

beforeEach( () => {
	jest.clearAllMocks();

	const { dispatch, select } = require( '@wordpress/data' );
	const { store } = require( '@wordpress/notices' );

	select( store )
		.getNotices()
		.forEach( ( notice ) => dispatch( store ).removeNotice( notice.id ) );
} );

describe( 'opening', () => {
	test( 'asks the server for THIS action\'s dialog and shows its tabs', async () => {
		setup();

		expect( await screen.findByRole( 'dialog' ) ).toBeInTheDocument();
		expect( fetchToolbarDialog ).toHaveBeenCalledWith( 'call_courier' );
		expect( await screen.findByRole( 'tab', { name: 'Вызов' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'tab', { name: 'Заявки' } ) ).toBeInTheDocument();
	} );

	test( 'the form tab opens with every order selected and each field\'s help under it', async () => {
		setup();

		expect( await screen.findByText( 'Выбрано заказов: 3 из 3' ) ).toBeInTheDocument();
		expect( screen.getByText( '#1047 · Екатеринбург' ) ).toBeInTheDocument();
		expect( screen.getByText( 'На один адрес — один вызов в день.' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( /День/ ) ).toHaveValue( '2026-10-12' );
	} );

	test( 'a one-tab dialog draws its tab without a tab bar', async () => {
		setup( dialogOf( [ FORM_TAB ] ) );

		await submitButton();

		expect( screen.queryByRole( 'tab' ) ).not.toBeInTheDocument();
	} );

	test( 'a dialog description sits above the tabs', async () => {
		setup( dialogOf( [ FORM_TAB ], { description: 'Курьер приедет в выбранный день.' } ) );

		expect( await screen.findByText( 'Курьер приедет в выбранный день.' ) ).toBeInTheDocument();
	} );

	test( 'a dialog that cannot be fetched says so and offers to close', async () => {
		fetchToolbarDialog.mockRejectedValue( { message: 'Это действие сейчас недоступно.' } );

		const onClose = jest.fn();

		render( <ToolbarDialog button={ BUTTON } onClose={ onClose } onChanged={ jest.fn() } /> );

		expect( await within( await screen.findByRole( 'dialog' ) ).findByText( 'Это действие сейчас недоступно.' ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Закрыть' } ) );

		expect( onClose ).toHaveBeenCalled();
	} );
} );

describe( 'submitting the form', () => {
	test( 'sends the chosen orders and the shared values once', async () => {
		submitToolbarAction.mockResolvedValue( { action: 'call_courier', requested: 3, succeeded: 3, failed: 0, results: [], messages: { success: 'Выполнено 3 из 3' }, dialog: null } );
		setup();

		fireEvent.change( await screen.findByLabelText( /Комментарий/ ), { target: { value: 'Позвонить за час' } } );
		fireEvent.click( await submitButton() );

		await waitFor( () => expect( submitToolbarAction ).toHaveBeenCalledTimes( 1 ) );
		expect( submitToolbarAction ).toHaveBeenCalledWith( 'call_courier', {
			orders: [ '1047', '1050', '1051' ],
			day: '2026-10-12',
			comment: 'Позвонить за час',
		} );
	} );

	test( 'un-ticking an order leaves it out of the run', async () => {
		submitToolbarAction.mockResolvedValue( { action: 'call_courier', requested: 2, succeeded: 2, failed: 0, results: [], messages: {}, dialog: null } );
		setup();

		await screen.findByText( '#1050 · Москва' );
		fireEvent.click( screen.getAllByRole( 'button', { name: /Remove|Удалить/ } )[ 1 ] );
		fireEvent.click( await submitButton() );

		await waitFor( () => expect( submitToolbarAction ).toHaveBeenCalled() );
		expect( submitToolbarAction.mock.calls[ 0 ][ 1 ].orders ).toEqual( [ '1047', '1051' ] );
	} );

	test( 'a missing required value is caught in the browser: nothing is sent', async () => {
		setup();

		fireEvent.change( await screen.findByLabelText( /День/ ), { target: { value: '' } } );
		fireEvent.click( await submitButton() );

		expect( await screen.findByText( 'Заполните это поле.' ) ).toBeInTheDocument();
		expect( submitToolbarAction ).not.toHaveBeenCalled();
	} );

	test( 'no order chosen is caught in the browser too', async () => {
		setup();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Снять все' } ) );
		fireEvent.click( await submitButton() );

		expect( await screen.findByText( 'Выберите хотя бы один заказ.' ) ).toBeInTheDocument();
		expect( submitToolbarAction ).not.toHaveBeenCalled();
	} );

	test( 'every order through: the dialog closes, the page is told, and the merchant sees the sentence', async () => {
		submitToolbarAction.mockResolvedValue( {
			action: 'call_courier',
			requested: 3,
			succeeded: 3,
			failed: 0,
			results: [],
			messages: { success: 'Выполнено 3 из 3' },
			dialog: dialogOf(),
		} );

		const { onClose, onChanged } = setup();

		fireEvent.click( await submitButton() );

		await waitFor( () => expect( onClose ).toHaveBeenCalled() );
		expect( onChanged ).toHaveBeenCalled();
		const { select } = require( '@wordpress/data' );
		const { store } = require( '@wordpress/notices' );

		expect( select( store ).getNotices().map( ( notice ) => [ notice.status, notice.content ] ) ).toEqual( [ [ 'success', 'Выполнено 3 из 3' ] ] );
	} );

	describe( 'some orders failed', () => {
		const partial = {
			action: 'call_courier',
			requested: 3,
			succeeded: 2,
			failed: 1,
			results: [
				{ id: 1047, order_number: '1047', ok: false, message: 'СДЭК: На этот адрес вызов уже есть' },
				{ id: 1050, order_number: '1050', ok: true, message: '' },
				{ id: 1051, order_number: '1051', ok: true, message: 'СДЭК: Заявка № 13312784' },
			],
			messages: { success: 'Выполнено 2 из 3', error: 'Не удалось выполнить действие для 1 из 3' },
			// 1050 and 1051 went through and are no longer eligible; 1047 is still on offer.
			dialog: dialogOf( [ { ...FORM_TAB, fields: [ { ...ORDERS, options: [ ORDERS.options[ 0 ] ], default: [ '1047' ] }, ...FORM_TAB.fields.slice( 1 ) ] }, LIST_TAB ] ),
		};

		test( 'the dialog stays open and says what happened to each order', async () => {
			submitToolbarAction.mockResolvedValue( partial );

			const { onClose, onChanged } = setup();

			fireEvent.click( await submitButton() );

			const dialog = await screen.findByRole( 'dialog' );

			expect( await within( dialog ).findByText( 'Не удалось выполнить действие для 1 из 3' ) ).toBeInTheDocument();
			expect( within( dialog ).getByText( /СДЭК: На этот адрес вызов уже есть/ ) ).toBeInTheDocument();
			expect( within( dialog ).getByText( /СДЭК: Заявка № 13312784/ ) ).toBeInTheDocument();
			expect( onClose ).not.toHaveBeenCalled();
			expect( onChanged ).toHaveBeenCalled();
		} );

		test( 'only the orders still on offer remain, and the failed one is still selected for a retry', async () => {
			submitToolbarAction.mockResolvedValue( partial );
			setup();

			fireEvent.click( await submitButton() );

			expect( await screen.findByText( 'Выбрано заказов: 1 из 1' ) ).toBeInTheDocument();
			expect( screen.getAllByText( '#1047 · Екатеринбург' ).length ).toBeGreaterThan( 0 );
			expect( screen.queryByText( '#1050 · Москва' ) ).not.toBeInTheDocument();
		} );

		test( 'what the merchant typed survives the refresh', async () => {
			submitToolbarAction.mockResolvedValue( partial );
			setup();

			fireEvent.change( await screen.findByLabelText( /Комментарий/ ), { target: { value: 'Позвонить за час' } } );
			fireEvent.change( screen.getByLabelText( /День/ ), { target: { value: '2026-10-15' } } );
			fireEvent.click( await submitButton() );

			await screen.findByText( 'Выбрано заказов: 1 из 1' );

			expect( screen.getByLabelText( /Комментарий/ ) ).toHaveValue( 'Позвонить за час' );
			expect( screen.getByLabelText( /День/ ) ).toHaveValue( '2026-10-15' );
		} );
	} );

	test( 'a 422 puts each message under its own field and keeps the dialog open', async () => {
		submitToolbarAction.mockRejectedValue( {
			code: 'woodev_shipping_orders_invalid_payload',
			message: 'Проверьте заполнение полей.',
			data: { status: 422, errors: [ { field: 'day', code: 'out_of_range', message: 'СДЭК не присылает курьера в этот день.' } ] },
		} );

		const { onClose } = setup();

		fireEvent.click( await submitButton() );

		expect( await screen.findByText( 'СДЭК не присылает курьера в этот день.' ) ).toBeInTheDocument();
		expect( onClose ).not.toHaveBeenCalled();
	} );

	test( 'a failure that is not about one field is shown in the dialog, which keeps its values', async () => {
		submitToolbarAction.mockRejectedValue( { code: 'rest_forbidden', message: 'Недостаточно прав.' } );

		const { onClose } = setup();

		fireEvent.change( await screen.findByLabelText( /Комментарий/ ), { target: { value: 'Позвонить' } } );
		fireEvent.click( await submitButton() );

		expect( await within( await screen.findByRole( 'dialog' ) ).findByText( 'Недостаточно прав.' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( /Комментарий/ ) ).toHaveValue( 'Позвонить' );
		expect( onClose ).not.toHaveBeenCalled();
	} );
} );

describe( 'the list tab', () => {
	async function openList( dialog = dialogOf() ) {
		const handlers = setup( dialog );

		fireEvent.click( await screen.findByRole( 'tab', { name: 'Заявки' } ) );
		await screen.findByText( '13312783' );

		return handlers;
	}

	test( 'draws the columns, one line per row and each row\'s buttons', async () => {
		await openList();

		expect( screen.getByRole( 'columnheader', { name: '№' } ) ).toBeInTheDocument();
		expect( screen.getByText( '13312784' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Отменить' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Обновить' } ) ).toBeInTheDocument();
	} );

	test( 'a harmless button runs at once', async () => {
		performToolbarRowAction.mockResolvedValue( { message: 'Обновлено', dialog: dialogOf() } );
		await openList();

		fireEvent.click( screen.getByRole( 'button', { name: 'Обновить' } ) );

		await waitFor( () => expect( performToolbarRowAction ).toHaveBeenCalledWith( 'call_courier', 'intakes', 'uuid-2', 'refresh' ) );
	} );

	test( 'a destructive one asks the carrier\'s own sentence first and runs nothing until «Да»', async () => {
		performToolbarRowAction.mockResolvedValue( { message: 'Вызов отменён', dialog: dialogOf( [ FORM_TAB, { ...LIST_TAB, rows: [ LIST_TAB.rows[ 1 ] ] } ] ) } );
		const { onChanged } = await openList();

		fireEvent.click( screen.getByRole( 'button', { name: 'Отменить' } ) );

		expect( await screen.findByText( 'После отмены СДЭК может не принять новый вызов.' ) ).toBeInTheDocument();
		expect( performToolbarRowAction ).not.toHaveBeenCalled();

		fireEvent.click( screen.getByRole( 'button', { name: 'Да' } ) );

		await waitFor( () => expect( performToolbarRowAction ).toHaveBeenCalledWith( 'call_courier', 'intakes', 'uuid-1', 'cancel' ) );
		// The refreshed dialog replaces the list: the cancelled row is gone, the page is told.
		await waitFor( () => expect( screen.queryByText( '13312783' ) ).not.toBeInTheDocument() );
		expect( screen.getByText( '13312784' ) ).toBeInTheDocument();
		expect( onChanged ).toHaveBeenCalled();
	} );

	test( '«Нет» runs nothing', async () => {
		await openList();

		fireEvent.click( screen.getByRole( 'button', { name: 'Отменить' } ) );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Нет' } ) );

		expect( performToolbarRowAction ).not.toHaveBeenCalled();
		expect( screen.queryByText( 'После отмены СДЭК может не принять новый вызов.' ) ).not.toBeInTheDocument();
	} );

	test( 'a destructive button that declared no sentence gets the generic question', async () => {
		const bare = { ...LIST_TAB, rows: [ { ...LIST_TAB.rows[ 0 ], actions: [ { ...LIST_TAB.rows[ 0 ].actions[ 0 ], confirm: undefined } ] } ] };

		await openList( dialogOf( [ FORM_TAB, bare ] ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Отменить' } ) );

		expect( await screen.findByText( 'Вы уверены, что хотите выполнить «Отменить»?' ) ).toBeInTheDocument();
	} );

	test( 'a refusal is shown in the list and the rows stay', async () => {
		performToolbarRowAction.mockRejectedValue( { message: 'Заявка уже в работе' } );
		await openList();

		fireEvent.click( screen.getByRole( 'button', { name: 'Обновить' } ) );

		const alerts = await screen.findAllByText( 'Заявка уже в работе' );

		expect( alerts.length ).toBeGreaterThan( 0 );
		expect( screen.getByText( '13312783' ) ).toBeInTheDocument();
	} );

	test( 'the carrier no longer supplying a dialog after a run closes it', async () => {
		performToolbarRowAction.mockResolvedValue( { message: 'Вызов отменён', dialog: null } );
		const { onClose } = await openList();

		fireEvent.click( screen.getByRole( 'button', { name: 'Обновить' } ) );

		await waitFor( () => expect( onClose ).toHaveBeenCalled() );
	} );
} );

describe( 'an empty list', () => {
	test( 'says the carrier\'s sentence, or the page\'s own', async () => {
		setup( dialogOf( [ { ...LIST_TAB, rows: [] } ] ) );

		expect( await screen.findByText( 'Заявок пока нет.' ) ).toBeInTheDocument();
	} );

	test( 'falls back to a generic sentence', async () => {
		setup( dialogOf( [ { ...LIST_TAB, rows: [], empty: '' } ] ) );

		const empty = await screen.findByText( 'Пока ничего нет.' );

		expect( within( empty.parentElement ).queryByRole( 'table' ) ).toBeNull();
	} );
} );
