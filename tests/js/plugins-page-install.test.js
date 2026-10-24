import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import InstallButton from '../../src/plugins-page/install';

describe( 'InstallButton failure detail', () => {
	it( 'shows the REST message returned with the failed install', () => {
		render(
			createElement( InstallButton, {
				state: 'error',
				errorMessage: 'Причина: Download failed.',
			} )
		);

		expect( screen.getByRole( 'button', { name: 'Ошибка, повторить' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Причина: Download failed.' );
	} );

	it( 'does not render a diagnostic line when the response has no message', () => {
		render( createElement( InstallButton, { state: 'error' } ) );

		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
	} );
} );
