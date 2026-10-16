/**
 * The license key field while an activation request is in flight (#1336).
 *
 * A key corrected during the request used to be overwritten by the response's key and the field turned
 * read-only without a word; the field is now disabled for as long as the card is busy.
 *
 * @see src/license-page/license-card.js
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import LicenseCard from '../../src/license-page/license-card';

jest.mock( '@wordpress/api-fetch' );

const KEY_LABEL = 'Лицензионный ключ';

const INITIAL_STATE = {
	plugin_id: 'demo',
	plugin_name: 'Demo',
	license_key: '',
	status: '',
	is_need_license: true,
	beta_enabled: false,
};

afterEach( () => {
	jest.resetAllMocks();
} );

describe( 'LicenseCard key field', () => {
	it( 'is disabled while an activation is pending and shows the activated key afterwards', async () => {
		let reply;
		apiFetch.mockReturnValue( new Promise( ( resolve ) => {
			reply = resolve;
		} ) );
		render( createElement( LicenseCard, { initialState: INITIAL_STATE } ) );

		const input = screen.getByLabelText( KEY_LABEL );
		expect( input ).toBeEnabled();

		fireEvent.change( input, { target: { value: 'KEY-A' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Активировать' } ) );

		// The request carries KEY-A and, until it answers, the field cannot be edited.
		await waitFor( () => expect( screen.getByLabelText( KEY_LABEL ) ).toBeDisabled() );
		expect( apiFetch ).toHaveBeenCalledWith( expect.objectContaining( { data: { license_key: 'KEY-A' } } ) );

		reply( { ...INITIAL_STATE, license_key: 'KEY-A', status: 'valid' } );

		await waitFor( () => expect( screen.getByLabelText( KEY_LABEL ) ).toBeEnabled() );
		expect( screen.getByLabelText( KEY_LABEL ) ).toHaveAttribute( 'readonly' );

		// The card's TextControl has no `__next40pxDefaultSize` yet — a deprecation notice, not this test's subject.
		expect( console ).toHaveWarned();
	} );
} );
