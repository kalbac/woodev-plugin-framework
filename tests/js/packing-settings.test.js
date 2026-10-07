import $ from 'jquery';

global.jQuery = $;
require( '../../woodev/shipping-method/assets/js/admin/packing-settings.js' );

beforeEach( () => {
	document.body.innerHTML = '<table><tr><td><select id="woocommerce_fixture_packing_algorithm"><option value="default">Default</option><option value="boxes">Boxes</option><option value="single">Single</option></select></td></tr><tr id="leftovers"><td><select id="woocommerce_fixture_unpacked_algorithm" data-woodev-packing-default="boxes"></select></td></tr></table>';
} );
afterEach( () => { document.body.innerHTML = ''; } );

test( 'zone leftovers are visible only for effective boxes, including the plugin default', () => {
	$( document ).trigger( 'wc_backbone_modal_loaded' );
	expect( document.getElementById( 'leftovers' ).hidden ).toBe( false );
	$( '#woocommerce_fixture_packing_algorithm' ).val( 'single' ).trigger( 'change' );
	expect( document.getElementById( 'leftovers' ).hidden ).toBe( true );
	$( '#woocommerce_fixture_packing_algorithm' ).val( 'boxes' ).trigger( 'change' );
	expect( document.getElementById( 'leftovers' ).hidden ).toBe( false );
	document.querySelector( '[data-woodev-packing-default]' ).setAttribute( 'data-woodev-packing-default', 'separately' );
	$( '#woocommerce_fixture_packing_algorithm' ).val( 'default' ).trigger( 'change' );
	expect( document.getElementById( 'leftovers' ).hidden ).toBe( true );
} );
