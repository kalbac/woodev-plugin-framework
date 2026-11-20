import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import TabsNav from '../../src/components/tabs-nav';
import StackedView, { isStackedTab } from '../../src/settings-page/stacked-view';

const section = ( id, label, extra = {} ) => ( { id, label, fields: {}, ...extra } );

// The stacked view draws no heading itself: it hands each section's title and subtitle to `renderSection`, which draws
// them INSIDE the section's card (src/settings-page/app.js). The stub records what it was given.
const view = ( sections ) => {
	const headings = {};

	render( createElement( StackedView, {
		tab: { id: 'tab', sections },
		renderSection: ( tab, id, heading ) => {
			headings[ id ] = heading;

			return createElement( 'div', { 'data-testid': `block-${ id }` } );
		},
	} ) );

	return headings;
};

test( 'a tab is stacked when a section is flagged', () => {
	expect( isStackedTab( { sections: [ section( 'a', 'A', { stacked: true } ) ] } ) ).toBe( true );
	expect( isStackedTab( { sections: [ section( 'a', 'A' ) ] } ) ).toBe( false );
} );

test( 'two accounts are two separate blocks, each handed its name and the identifier beside it', () => {
	const headings = view( [
		section( 'account_params_1', 'Shop one', { stacked: true, subtitle: 'Client-Id: 4180001' } ),
		section( 'account_params_2', 'Shop two', { stacked: true, subtitle: 'Client-Id: 4180002' } ),
	] );

	expect( headings ).toEqual( {
		account_params_1: { title: 'Shop one', subtitle: 'Client-Id: 4180001' },
		account_params_2: { title: 'Shop two', subtitle: 'Client-Id: 4180002' },
	} );
	expect( screen.queryByRole( 'heading' ) ).toBeNull(); // the heading belongs to the card, not to this wrapper

	const blocks = screen.getAllByTestId( /^block-/ );
	expect( blocks.map( ( b ) => b.dataset.testid ) ).toEqual( [ 'block-account_params_1', 'block-account_params_2' ] );
	expect( blocks[ 0 ].closest( 'section' ) ).not.toBe( blocks[ 1 ].closest( 'section' ) );
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

	expect( await screen.findAllByTestId( /^block-/ ) ).toHaveLength( 2 );
	expect( container.querySelector( '.woodev-subtabs' ) ).toBeNull();
	expect( screen.getByTestId( 'block-notifications_webhooks' ) ).toBeInTheDocument();
	expect( screen.getByTestId( 'block-notifications_account_1' ) ).toBeInTheDocument();
} );

test( 'the description sits above the stacked cards, outside the column that spaces them', () => {
	const { container } = render( createElement( StackedView, {
		tab: { id: 'tab', description: 'Что делает вкладка', sections: [ section( 'a', 'A', { stacked: true } ) ] },
		renderSection: () => createElement( 'div' ),
	} ) );

	const description = container.querySelector( '.woodev-settings__tab-desc' );

	expect( description ).toHaveTextContent( 'Что делает вкладка' );
	expect( container.querySelector( '.woodev-stacked' ) ).not.toContainElement( description );
	expect( description.parentElement ).toHaveClass( 'woodev-stacked-tab' );
} );
