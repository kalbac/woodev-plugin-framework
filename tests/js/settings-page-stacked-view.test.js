import '@testing-library/jest-dom';
import { render, screen, within } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import TabsNav from '../../src/components/tabs-nav';
import StackedView, { isStackedTab } from '../../src/settings-page/stacked-view';

const section = ( id, label, extra = {} ) => ( { id, label, fields: {}, ...extra } );

const view = ( sections ) => render( createElement( StackedView, {
	tab: { id: 'tab', sections },
	renderSection: ( tab, id ) => createElement( 'div', { 'data-testid': `block-${ id }` } ),
} ) );

test( 'a tab is stacked when a section is flagged', () => {
	expect( isStackedTab( { sections: [ section( 'a', 'A', { stacked: true } ) ] } ) ).toBe( true );
	expect( isStackedTab( { sections: [ section( 'a', 'A' ) ] } ) ).toBe( false );
} );

test( 'two accounts are two separate blocks, each under its name with the identifier beside it', () => {
	view( [
		section( 'account_params_1', 'Shop one', { stacked: true, subtitle: 'Client-Id: 4180001' } ),
		section( 'account_params_2', 'Shop two', { stacked: true, subtitle: 'Client-Id: 4180002' } ),
	] );

	const headings = screen.getAllByRole( 'heading', { level: 3 } );

	expect( headings.map( ( h ) => h.firstChild.textContent ) ).toEqual( [ 'Shop one', 'Shop two' ] );
	expect( within( headings[ 0 ] ).getByText( 'Client-Id: 4180001' ) ).toBeInTheDocument();
	expect( within( headings[ 1 ] ).getByText( 'Client-Id: 4180002' ) ).toBeInTheDocument();

	const blocks = screen.getAllByTestId( /^block-/ );
	expect( blocks.map( ( b ) => b.dataset.testid ) ).toEqual( [ 'block-account_params_1', 'block-account_params_2' ] );
	expect( headings[ 0 ].closest( 'section' ) ).not.toBe( headings[ 1 ].closest( 'section' ) );
} );

test( 'the notifications tab is the switches and then a row per account, with no sub-tabs', async () => {
	const tab = {
		id: 'notifications',
		label: 'Уведомления',
		sections: [
			section( 'notifications_webhooks', 'Какие уведомления получать от Ozon', { stacked: true } ),
			section( 'notifications_account_1', 'Уведомления Ozon — «4180680»', { stacked: true } ),
		],
	};
	const { container } = render( createElement( TabsNav, {
		tabs: [ tab ],
		renderSection: ( t ) => createElement( StackedView, {
			tab: t,
			renderSection: ( x, id ) => createElement( 'div', { 'data-testid': `block-${ id }` } ),
		} ),
	} ) );

	expect( await screen.findAllByRole( 'heading', { level: 3 } ) ).toHaveLength( 2 );
	expect( container.querySelector( '.woodev-subtabs' ) ).toBeNull();
	expect( screen.getByTestId( 'block-notifications_webhooks' ) ).toBeInTheDocument();
	expect( screen.getByTestId( 'block-notifications_account_1' ) ).toBeInTheDocument();
} );
