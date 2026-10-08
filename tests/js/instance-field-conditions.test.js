import $ from 'jquery';

global.jQuery = $;
require( '../../woodev/shipping-method/assets/js/admin/instance-field-conditions.js' );

const rule = ( conditions, relation = 'AND' ) => JSON.stringify( { relation, conditions } );
const attr = ( value ) => value.replace( /"/g, '&quot;' );

/** A form with a tariff select, an insurance checkbox, a multiselect, and one dependent row per test. */
function form( dependentRule ) {
	document.body.innerHTML =
		'<table>' +
		'<tr><td><select id="woocommerce_cdek_tariff"><option value="137">A</option><option value="139">B</option><option value="999">C</option></select></td></tr>' +
		'<tr><td><input type="checkbox" id="woocommerce_cdek_insure" /></td></tr>' +
		'<tr><td><select id="woocommerce_cdek_zones" multiple><option value="1">1</option><option value="2">2</option></select></td></tr>' +
		'<tr id="row"><td><input type="text" id="woocommerce_cdek_target" value="saved" data-woodev-show-if="' + attr( dependentRule ) + '" /></td></tr>' +
		'</table>';
}

const row = () => document.getElementById( 'row' );
const visible = () => '' === row().style.display;
const choose = ( id, value ) => $( '#' + id ).val( value ).trigger( 'change' );

afterEach( () => {
	document.body.innerHTML = '';
} );

test( 'evaluates on load: a select value in the list shows the row, outside it hides it', () => {
	form( rule( [ { field: 'woocommerce_cdek_tariff', operator: 'in', value: [ '139' ] } ] ) );
	$( document ).trigger( 'wc_backbone_modal_loaded' );
	expect( visible() ).toBe( false );

	choose( 'woocommerce_cdek_tariff', '139' );
	expect( visible() ).toBe( true );
} );

test( 'follows a change of the controlling select, in both directions', () => {
	form( rule( [ { field: 'woocommerce_cdek_tariff', operator: 'not_in', value: [ '139' ] } ] ) );
	$( document ).trigger( 'wc_backbone_modal_loaded' );
	expect( visible() ).toBe( true );

	choose( 'woocommerce_cdek_tariff', '139' );
	expect( visible() ).toBe( false );

	choose( 'woocommerce_cdek_tariff', '999' );
	expect( visible() ).toBe( true );
} );

test( 'a select whose value was set programmatically is read as it stands', () => {
	form( rule( [ { field: 'woocommerce_cdek_tariff', operator: '=', value: '999' } ] ) );
	document.getElementById( 'woocommerce_cdek_tariff' ).value = '999';
	$( document ).trigger( 'wc_backbone_modal_loaded' );
	expect( visible() ).toBe( true );
} );

test( 'a checkbox is yes when checked and no when not', () => {
	form( rule( [ { field: 'woocommerce_cdek_insure', operator: '=', value: 'yes' } ] ) );
	$( document ).trigger( 'wc_backbone_modal_loaded' );
	expect( visible() ).toBe( false );

	$( '#woocommerce_cdek_insure' ).prop( 'checked', true ).trigger( 'change' );
	expect( visible() ).toBe( true );

	$( '#woocommerce_cdek_insure' ).prop( 'checked', false ).trigger( 'change' );
	expect( visible() ).toBe( false );
} );

test( 'a multiselect matches when any chosen value does', () => {
	form( rule( [ { field: 'woocommerce_cdek_zones', operator: 'in', value: [ '2' ] } ] ) );
	$( document ).trigger( 'wc_backbone_modal_loaded' );
	expect( visible() ).toBe( false );

	choose( 'woocommerce_cdek_zones', [ '1', '2' ] );
	expect( visible() ).toBe( true );
} );

test( 'AND needs every condition, OR needs one', () => {
	const conditions = [
		{ field: 'woocommerce_cdek_tariff', operator: '=', value: '139' },
		{ field: 'woocommerce_cdek_insure', operator: '=', value: 'yes' },
	];

	form( rule( conditions, 'AND' ) );
	choose( 'woocommerce_cdek_tariff', '139' );
	expect( visible() ).toBe( false );

	form( rule( conditions, 'OR' ) );
	choose( 'woocommerce_cdek_tariff', '139' );
	expect( visible() ).toBe( true );
} );

test( 'a form inserted later is picked up by wc_backbone_modal_loaded', () => {
	document.body.innerHTML = '';
	$( document ).trigger( 'change' );

	form( rule( [ { field: 'woocommerce_cdek_tariff', operator: '=', value: '139' } ] ) );
	expect( visible() ).toBe( true );

	$( document.body ).trigger( 'wc_backbone_modal_loaded' );
	expect( visible() ).toBe( false );
} );

test( 'a missing controlling field leaves the row visible', () => {
	form( rule( [ { field: 'woocommerce_cdek_gone', operator: '=', value: 'x' } ] ) );
	$( document ).trigger( 'wc_backbone_modal_loaded' );
	expect( visible() ).toBe( true );
} );

test( 'a broken rule leaves the row visible and an unknown operator is not applied', () => {
	form( '{not json' );
	$( document ).trigger( 'wc_backbone_modal_loaded' );
	expect( visible() ).toBe( true );

	form( rule( [ { field: 'woocommerce_cdek_tariff', operator: '>', value: '1' } ] ) );
	$( document ).trigger( 'wc_backbone_modal_loaded' );
	expect( visible() ).toBe( true );
} );

test( 'hiding keeps the control in the form with its value', () => {
	form( rule( [ { field: 'woocommerce_cdek_tariff', operator: '=', value: '139' } ] ) );
	$( document ).trigger( 'wc_backbone_modal_loaded' );

	expect( visible() ).toBe( false );
	expect( document.getElementById( 'woocommerce_cdek_target' ).value ).toBe( 'saved' );
	expect( document.getElementById( 'woocommerce_cdek_target' ).disabled ).toBe( false );
} );
