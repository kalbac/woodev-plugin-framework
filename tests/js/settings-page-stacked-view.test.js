import '@testing-library/jest-dom';
import { render, screen, fireEvent } from '@testing-library/react';
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

// The per-account blocks (`Settings_Section::account_tab()`) are tabs named after the account; the stub records what each block
// was handed, because a block draws its own heading inside its card.
const account = ( n, extra = {} ) => section( `account_params_${ n }`, `Shop ${ n }`, { stacked: true, account_tab: true, ...extra } );

test( 'two accounts are tabs named after them and only the open account is drawn, with no heading of its own', () => {
	const headings = view( [ account( 1 ), account( 2 ) ] );

	const tabs = screen.getAllByRole( 'button' );
	expect( tabs.map( ( t ) => t.textContent ) ).toEqual( [ 'Shop 1', 'Shop 2' ] );
	expect( tabs[ 0 ] ).toHaveClass( 'woodev-subtabs__link', 'is-active' );
	expect( screen.getByTestId( 'block-account_params_1' ) ).toBeInTheDocument();
	expect( screen.queryByTestId( 'block-account_params_2' ) ).toBeNull();
	expect( headings.account_params_1 ).toBeUndefined(); // no «<name> Client-Id: …» line over the block
	expect( screen.queryByText( /Client-Id/ ) ).toBeNull();
} );

test( 'a tab opens its account\'s block and drops the other one', () => {
	view( [ account( 1 ), account( 2 ) ] );

	fireEvent.click( screen.getByRole( 'button', { name: 'Shop 2' } ) );

	expect( screen.queryByTestId( 'block-account_params_1' ) ).toBeNull();
	expect( screen.getByTestId( 'block-account_params_2' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'button', { name: 'Shop 2' } ) ).toHaveClass( 'is-active' );
} );

test( 'one account has no tabs: its block is drawn directly', () => {
	const { container } = render( createElement( StackedView, {
		tab: { id: 'tab', sections: [ account( 1 ) ] },
		renderSection: ( tab, id ) => createElement( 'div', { 'data-testid': `block-${ id }` } ),
	} ) );

	expect( container.querySelector( '.woodev-subtabs' ) ).toBeNull();
	expect( screen.getByTestId( 'block-account_params_1' ) ).toBeInTheDocument();
} );

test( 'the notifications tab is the shared switches under their heading, then the account tabs', async () => {
	const headings = {};
	const tab = {
		id: 'notifications',
		label: 'Уведомления',
		sections: [
			section( 'notifications_webhooks', 'Какие уведомления получать от Ozon', { stacked: true } ),
			section( 'notifications_account_1', 'Основной', { stacked: true, account_tab: true } ),
			section( 'notifications_account_2', 'Второй', { stacked: true, account_tab: true } ),
		],
	};
	const { container } = render( createElement( TabsNav, {
		tabs: [ tab ],
		renderSection: ( t ) => createElement( StackedView, {
			tab: t,
			renderSection: ( x, id, heading ) => {
				headings[ id ] = heading;

				return createElement( 'div', { 'data-testid': `block-${ id }` } );
			},
		} ),
	} ) );

	expect( await screen.findAllByTestId( /^block-/ ) ).toHaveLength( 2 ); // the switches and the open account
	expect( headings.notifications_webhooks.title ).toBe( 'Какие уведомления получать от Ozon' );
	expect( headings.notifications_account_1 ).toBeUndefined();
	expect( screen.getByTestId( 'block-notifications_webhooks' ) ).toBeInTheDocument();
	expect( screen.getByTestId( 'block-notifications_account_1' ) ).toBeInTheDocument();
	expect( screen.queryByTestId( 'block-notifications_account_2' ) ).toBeNull();

	// The switches come first, then the account tabs: the folder tab itself adds no sub-tab bar (stacked tab).
	const bars = container.querySelectorAll( '.woodev-subtabs' );
	expect( bars ).toHaveLength( 1 );
	expect( screen.getByTestId( 'block-notifications_webhooks' ).compareDocumentPosition( bars[ 0 ] ) & Node.DOCUMENT_POSITION_FOLLOWING ).toBeTruthy();
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
