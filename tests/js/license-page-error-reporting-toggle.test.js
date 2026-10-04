/**
 * The «Отправлять отчёты об ошибках» consent checkbox on the Woodev → Лицензии page (#130).
 *
 * Pins the contract the operator set: nothing is rendered when no report receiver is configured
 * (a checkbox that changes nothing is not offered), the box mirrors the stored choice, ticking it
 * POSTs `{ enabled }` to the consent route and shows the server's answer, and a failed save leaves
 * the box where it was and says so.
 *
 * @see src/license-page/error-reporting-toggle.tsx
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ErrorReportingToggle from '../../src/license-page/error-reporting-toggle';

jest.mock( '@wordpress/api-fetch' );

const LABEL = 'Отправлять отчёты об ошибках';

afterEach( () => {
	jest.resetAllMocks();
} );

describe( 'ErrorReportingToggle', () => {
	it( 'renders nothing when the site has no report receiver', () => {
		const { container } = render( createElement( ErrorReportingToggle, { initialState: { enabled: false, available: false } } ) );

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'renders nothing when the framework copy sends no state at all', () => {
		const { container } = render( createElement( ErrorReportingToggle, {} ) );

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'is OFF by default and says what is sent in plain words', () => {
		render( createElement( ErrorReportingToggle, { initialState: { enabled: false, available: true } } ) );

		expect( screen.getByLabelText( LABEL ) ).not.toBeChecked();
		const help = screen.getByText( /Тексты сообщений об ошибках, данные покупателей и адрес сайта не передаются/ );
		expect( help ).toBeInTheDocument();
		expect( help.textContent ).toMatch( /файл, строку и названия функций/ );
		expect( help.textContent ).not.toMatch( /текст ошибки/ );
	} );

	it( 'mirrors a stored consent', () => {
		render( createElement( ErrorReportingToggle, { initialState: { enabled: true, available: true } } ) );

		expect( screen.getByLabelText( LABEL ) ).toBeChecked();
	} );

	it( 'POSTs the choice to the consent route and shows the stored answer', async () => {
		apiFetch.mockResolvedValue( { enabled: true, available: true } );
		render( createElement( ErrorReportingToggle, { initialState: { enabled: false, available: true } } ) );

		fireEvent.click( screen.getByLabelText( LABEL ) );

		await waitFor( () => expect( screen.getByLabelText( LABEL ) ).toBeChecked() );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/woodev/v1/error-reporting',
			method: 'POST',
			data: { enabled: true },
		} );
	} );

	it( 'keeps the box where it was and reports a failed save', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		render( createElement( ErrorReportingToggle, { initialState: { enabled: false, available: true } } ) );

		fireEvent.click( screen.getByLabelText( LABEL ) );

		// Notice also mirrors its text into an a11y live region, so the message appears twice.
		await waitFor( () => expect( screen.getAllByText( 'Не удалось сохранить настройку. Попробуйте ещё раз.' ).length ).toBeGreaterThan( 0 ) );
		expect( screen.getByLabelText( LABEL ) ).not.toBeChecked();
	} );
} );
