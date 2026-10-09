/**
 * The orders multi-select of a toolbar dialog (s164): the label ⇄ id mapping the `FormTokenField` is driven through,
 * and the control itself — chosen orders as chips, a counter, «Выбрать все» / «Снять все».
 *
 * @see src/shipping-orders-page/orders-select.tsx
 */

import '@testing-library/jest-dom';
import { fireEvent, render, screen } from '@testing-library/react';
import { OrdersSelect, buildOrderTokens, valuesOfTokens } from '../../src/shipping-orders-page/orders-select';

const OPTIONS = [
	{ value: '1047', label: '#1047 · Екатеринбург' },
	{ value: '1050', label: '#1050 · Москва' },
	{ value: '1051', label: '#1051 · Казань' },
];

describe( 'buildOrderTokens', () => {
	it( 'a token is the order\'s label, so typing a city finds it', () => {
		const tokens = buildOrderTokens( OPTIONS );

		expect( tokens.labels ).toEqual( [ '#1047 · Екатеринбург', '#1050 · Москва', '#1051 · Казань' ] );
		expect( tokens.labelOf[ '1050' ] ).toBe( '#1050 · Москва' );
		expect( tokens.valueOf[ '#1050 · Москва' ] ).toBe( '1050' );
	} );

	it( 'two orders with one label stay two tokens — the later one carries its id', () => {
		const tokens = buildOrderTokens( [
			{ value: '1', label: 'Москва' },
			{ value: '2', label: 'Москва' },
		] );

		expect( tokens.labels ).toEqual( [ 'Москва', 'Москва (2)' ] );
		expect( tokens.valueOf[ 'Москва (2)' ] ).toBe( '2' );
		expect( tokens.valueOf.Москва ).toBe( '1' );
	} );
} );

describe( 'valuesOfTokens', () => {
	const { valueOf } = buildOrderTokens( OPTIONS );

	it( 'maps tokens back to ids, in the order given, strings or token objects alike', () => {
		expect( valuesOfTokens( [ '#1050 · Москва', { value: '#1047 · Екатеринбург' } ], valueOf ) ).toEqual( [ '1050', '1047' ] );
	} );

	it( 'drops a token no order carries, and a repeat', () => {
		expect( valuesOfTokens( [ 'выдуманный', '#1050 · Москва', '#1050 · Москва' ], valueOf ) ).toEqual( [ '1050' ] );
	} );
} );

describe( 'OrdersSelect', () => {
	function setup( value: string[], disabled = false ) {
		const onChange = jest.fn();

		render( <OrdersSelect label="Заказы" options={ OPTIONS } value={ value } disabled={ disabled } onChange={ onChange } /> );

		return onChange;
	}

	it( 'draws every chosen order as a chip and counts them', () => {
		setup( [ '1047', '1051' ] );

		expect( screen.getByText( '#1047 · Екатеринбург' ) ).toBeInTheDocument();
		expect( screen.getByText( '#1051 · Казань' ) ).toBeInTheDocument();
		expect( screen.queryByText( '#1050 · Москва' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Выбрано заказов: 2 из 3' ) ).toBeInTheDocument();
	} );

	it( '«Выбрать все» chooses every offered order; «Снять все» none', () => {
		const onChange = setup( [ '1047' ] );

		fireEvent.click( screen.getByRole( 'button', { name: 'Выбрать все' } ) );
		expect( onChange ).toHaveBeenLastCalledWith( [ '1047', '1050', '1051' ] );

		fireEvent.click( screen.getByRole( 'button', { name: 'Снять все' } ) );
		expect( onChange ).toHaveBeenLastCalledWith( [] );
	} );

	it( '«Выбрать все» is off when all are chosen and «Снять все» when none is', () => {
		const { unmount } = render( <OrdersSelect label="Заказы" options={ OPTIONS } value={ [ '1047', '1050', '1051' ] } onChange={ jest.fn() } /> );

		expect( screen.getByRole( 'button', { name: 'Выбрать все' } ) ).toBeDisabled();
		unmount();

		render( <OrdersSelect label="Заказы" options={ OPTIONS } value={ [] } onChange={ jest.fn() } /> );

		expect( screen.getByRole( 'button', { name: 'Снять все' } ) ).toBeDisabled();
	} );

	it( 'removing a chip reports the remaining ids', () => {
		const onChange = setup( [ '1047', '1050' ] );

		fireEvent.click( screen.getAllByRole( 'button', { name: /Remove|Удалить/ } )[ 0 ] );

		expect( onChange ).toHaveBeenCalledWith( [ '1050' ] );
	} );

	it( 'a value the options do not know is simply not drawn', () => {
		setup( [ '9999', '1050' ] );

		expect( screen.getByText( '#1050 · Москва' ) ).toBeInTheDocument();
	} );
} );
