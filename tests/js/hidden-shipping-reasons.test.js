/** @jest-environment jsdom */

describe( 'admin-only block checkout shipping reasons', () => {
	let render;

	beforeEach( () => {
		document.body.innerHTML = '<div class="wc-block-components-shipping-rates-control"></div>';
		window.wp = {
			data: {
				select: jest.fn(),
				subscribe: ( callback ) => {
					render = callback;
				},
			},
		};
		jest.resetModules();
		require( '../../woodev/shipping-method/assets/js/frontend/hidden-shipping-reasons' );
	} );

	it( 'renders Store API messages as text below the shipping rates', () => {
		window.wp.data.select.mockReturnValue( {
			getCartData: () => ( {
				extensions: {
					'woodev-shipping-hidden-reasons': { messages: [ '<script>secret()</script>', 'No carrier service.' ] },
				},
			} ),
		} );

		render();

		const notice = document.querySelector( '.woodev-hidden-shipping-reasons' );
		expect( notice ).not.toBeNull();
		expect( notice.textContent ).toContain( '<script>secret()</script>' );
		expect( notice.querySelector( 'script' ) ).toBeNull();
		expect( notice.parentElement ).toBe( document.querySelector( '.wc-block-components-shipping-rates-control' ).parentElement );
	} );

	it( 'removes a prior notice when the Store API has no reasons', () => {
		window.wp.data.select.mockReturnValue( {
			getCartData: () => ( {
				extensions: {
					'woodev-shipping-hidden-reasons': { messages: [ 'No carrier service.' ] },
				},
			} ),
		} );
		render();
		expect( document.querySelector( '.woodev-hidden-shipping-reasons' ) ).not.toBeNull();

		window.wp.data.select.mockReturnValue( { getCartData: () => ( { extensions: {} } ) } );
		render();
		expect( document.querySelector( '.woodev-hidden-shipping-reasons' ) ).toBeNull();
	} );
} );
