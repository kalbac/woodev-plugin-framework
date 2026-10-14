/**
 * The dialog of an order action whose `select` loads its options when it opens (Yandex #84): it asks the carrier ONCE
 * on open, says «loading» / «nothing to offer» / the failure (with a retry), and cannot be sent until the list is in.
 *
 * @see src/shipping-orders-page/action-input-modal.tsx
 */

import '@testing-library/jest-dom';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { ActionInputModal } from '../../src/shipping-orders-page/action-input-modal';
import { fetchActionFieldOptions } from '../../src/shipping-orders-page/rest';
import type { OrderActionField, OrderRowAction } from '../../src/shipping-orders-page/rest';

jest.mock( '../../src/shipping-orders-page/rest', () => ( {
	fetchActionFieldOptions: jest.fn(),
} ) );

const fetchOptions = fetchActionFieldOptions as jest.Mock;

const FIELD: OrderActionField = {
	id: 'interval',
	type: 'select',
	label: 'Интервал',
	required: true,
	default: '',
	options: [],
	async_options: true,
};

const ACTION = {
	action: 'pick_interval',
	label: 'Выгрузить с интервалом',
	title: '',
	destructive: false,
	fields: [ FIELD ],
} as OrderRowAction & { fields: OrderActionField[] };

function open( onSubmit = jest.fn() ) {
	render(
		<ActionInputModal orderId={ 123 } action={ ACTION } errors={ [] } busy={ false } onSubmit={ onSubmit } onClose={ jest.fn() } />
	);

	return onSubmit;
}

const submitButton = () => screen.getAllByRole( 'button', { name: 'Выгрузить с интервалом' } ).find( ( b ) => 'submit' === ( b as HTMLButtonElement ).type ) as HTMLButtonElement;

describe( 'ActionInputModal — an async_options select', () => {
	beforeEach( () => {
		fetchOptions.mockReset();
	} );

	it( 'asks the carrier for THIS order\'s options once, when the dialog opens, and is not sendable meanwhile', () => {
		fetchOptions.mockReturnValue( new Promise( () => {} ) );
		const onSubmit = open();

		expect( fetchOptions ).toHaveBeenCalledTimes( 1 );
		expect( fetchOptions ).toHaveBeenCalledWith( 123, 'pick_interval', 'interval' );
		expect( screen.getByText( 'Загружаем варианты…' ) ).toBeInTheDocument();
		expect( submitButton() ).toBeDisabled();
		expect( onSubmit ).not.toHaveBeenCalled();
	} );

	it( 'offers what the carrier answered and submits the chosen value', async () => {
		fetchOptions.mockResolvedValue( { options: [ { value: 'a/b', label: '20 октября, 10:00–14:00' }, { value: 'c/d', label: '21 октября, 10:00–14:00' } ] } );
		const onSubmit = open();

		await screen.findByRole( 'option', { name: '21 октября, 10:00–14:00' } );
		fireEvent.change( screen.getByRole( 'combobox' ), { target: { value: 'c/d' } } );
		fireEvent.click( submitButton() );

		expect( onSubmit ).toHaveBeenCalledWith( { interval: 'c/d' } );
	} );

	it( 'a required field left unchosen is not sent', async () => {
		fetchOptions.mockResolvedValue( { options: [ { value: 'a/b', label: 'A' } ] } );
		const onSubmit = open();

		await screen.findByRole( 'option', { name: 'A' } );
		fireEvent.click( submitButton() );

		expect( onSubmit ).not.toHaveBeenCalled();
		expect( screen.getByText( 'Заполните это поле.' ) ).toBeInTheDocument();
	} );

	it( 'says there is nothing to offer when the list is empty', async () => {
		fetchOptions.mockResolvedValue( { options: [] } );
		open();

		expect( await screen.findByText( 'Нет доступных вариантов.' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'combobox' ) ).toBeDisabled();
	} );

	it( 'shows the server\'s sentence on a failure, and «Повторить» asks again', async () => {
		fetchOptions
			.mockRejectedValueOnce( { message: 'Яндекс Доставка не ответила.' } )
			.mockResolvedValueOnce( { options: [ { value: 'a/b', label: 'A' } ] } );
		open();

		expect( await screen.findByText( /Яндекс Доставка не ответила\./ ) ).toBeInTheDocument();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Повторить' } ) );
		} );

		await waitFor( () => expect( screen.getByRole( 'option', { name: 'A' } ) ).toBeInTheDocument() );
		expect( fetchOptions ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'a field with static options never asks the carrier', () => {
		const action = {
			...ACTION,
			fields: [ { id: 's', type: 'select', label: 'S', required: false, default: 'a', options: [ { value: 'a', label: 'A' } ] } as OrderActionField ],
		};

		render( <ActionInputModal orderId={ 123 } action={ action } errors={ [] } busy={ false } onSubmit={ jest.fn() } onClose={ jest.fn() } /> );

		expect( fetchOptions ).not.toHaveBeenCalled();
	} );
} );
