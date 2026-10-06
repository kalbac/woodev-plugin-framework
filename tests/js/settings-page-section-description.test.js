import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import SectionView from '../../src/settings-page/section-view';

jest.mock( '../../src/settings-page/rest', () => ( { testConnection: jest.fn() } ) );

const renderDescription = ( isConnection = false ) => render( createElement( SectionView, {
	providerId: 'carrier',
	section: {
		id: 'general',
		description: '<a href="https://example.com">carrier help</a>',
		fields: {},
		is_connection: isConnection,
		supports_test: false,
	},
	values: {},
	onFieldChange: jest.fn(),
	onFieldRevert: jest.fn(),
} ) );

test.each( [ false, true ] )( 'renders section description markup (connection=%s)', ( isConnection ) => {
	renderDescription( isConnection );

	expect( screen.getByRole( 'link', { name: 'carrier help' } ) ).toHaveAttribute( 'href', 'https://example.com' );
	expect( document.querySelector( 'script' ) ).not.toBeInTheDocument();
} );
