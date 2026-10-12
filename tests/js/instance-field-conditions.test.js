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

/*
 * The zone modal. Markup source: WooCommerce 11.1.2, includes/abstracts/abstract-wc-settings-api.php
 * (generate_text_html / generate_select_html emit `tr > th > label` + `td > fieldset`) run through
 * assets/js/admin/wc-shipping-zone-methods.js reformatSettingsHTML() → replaceHTMLTables(), which replaces
 * `table.form-table` with `div.wc-shipping-zone-method-fields` filled with the table's inner HTML, so the browser drops
 * the tr / th / td tags and leaves `label` and `fieldset` as siblings.
 */
const wcRow = ( id, title, control ) =>
	'<tr valign="top"><th scope="row" class="titledesc"><label for="' + id + '">' + title + ' </label></th>' +
	'<td class="forminp"><fieldset><legend class="screen-reader-text"><span>' + title + '</span></legend>' +
	control + '<p class="description">Help for ' + title + '</p></fieldset></td></tr>';

/** What WooCommerce's replaceHTMLTables() does to a settings table. */
function modalHtml( tableHtml ) {
	const content = $( '<div>' + tableHtml + '</div>' );

	content.find( 'table.form-table' ).each( function () {
		const table = $( this );
		const div = $( '<div class="wc-shipping-zone-method-fields" />' );

		div.html( table.html() );
		table.replaceWith( div );
	} );

	return content.prop( 'outerHTML' );
}

const modalRows = ( dependentRule ) =>
	wcRow( 'woocommerce_cdek_tariff', 'Tariff', '<select id="woocommerce_cdek_tariff" name="woocommerce_cdek_tariff"><option value="137">A</option><option value="139">B</option></select>' ) +
	wcRow( 'woocommerce_cdek_target', 'Target', '<input type="text" id="woocommerce_cdek_target" value="saved" data-woodev-show-if="' + attr( dependentRule ) + '" />' ) +
	wcRow( 'woocommerce_cdek_other', 'Other', '<input type="text" id="woocommerce_cdek_other" />' );

function modal( dependentRule ) {
	document.body.innerHTML = modalHtml( '<div><table class="form-table"><tbody>' + modalRows( dependentRule ) + '</tbody></table></div>' );
}

const tariffIs139 = () => rule( [ { field: 'woocommerce_cdek_tariff', operator: '=', value: '139' } ] );
const targetBlock = () => [
	document.querySelector( 'label[for="woocommerce_cdek_target"]' ),
	document.getElementById( 'woocommerce_cdek_target' ).closest( 'fieldset' ),
];
const shown = ( elements ) => elements.map( ( element ) => element.style.display );

test( 'the modal markup has no table row, only label and fieldset siblings', () => {
	modal( tariffIs139() );

	expect( document.querySelector( 'tr' ) ).toBeNull();
	expect( targetBlock()[ 0 ].nextElementSibling ).toBe( targetBlock()[ 1 ] );
} );

test( 'in the modal the whole field block is hidden — label, input and description — and shown again', () => {
	modal( tariffIs139() );
	$( document.body ).trigger( 'wc_backbone_modal_loaded' );

	expect( shown( targetBlock() ) ).toEqual( [ 'none', 'none' ] );
	expect( targetBlock()[ 1 ].querySelector( '.description' ) ).not.toBeNull();

	choose( 'woocommerce_cdek_tariff', '139' );
	expect( shown( targetBlock() ) ).toEqual( [ '', '' ] );

	choose( 'woocommerce_cdek_tariff', '137' );
	expect( shown( targetBlock() ) ).toEqual( [ 'none', 'none' ] );
} );

test( 'in the modal only the dependent field is touched', () => {
	modal( tariffIs139() );
	$( document.body ).trigger( 'wc_backbone_modal_loaded' );

	[ 'woocommerce_cdek_tariff', 'woocommerce_cdek_other' ].forEach( ( id ) => {
		expect( document.querySelector( 'label[for="' + id + '"]' ).style.display ).toBe( '' );
		expect( document.getElementById( id ).closest( 'fieldset' ).style.display ).toBe( '' );
	} );
} );

test( 'a modal form injected after init is handled when the modal reports loaded', () => {
	document.body.innerHTML = '';
	$( document ).trigger( 'change' );

	modal( tariffIs139() );
	expect( shown( targetBlock() ) ).toEqual( [ '', '' ] );

	$( document.body ).trigger( 'wc_backbone_modal_loaded' );
	expect( shown( targetBlock() ) ).toEqual( [ 'none', 'none' ] );
} );

test( 'in the modal hiding keeps the control in the form with its value', () => {
	modal( tariffIs139() );
	$( document.body ).trigger( 'wc_backbone_modal_loaded' );

	expect( document.getElementById( 'woocommerce_cdek_target' ).value ).toBe( 'saved' );
	expect( document.getElementById( 'woocommerce_cdek_target' ).disabled ).toBe( false );
} );

test( 'a fieldset outside the modal wrapper and outside a table is left alone', () => {
	document.body.innerHTML =
		'<div><label for="woocommerce_cdek_target">x</label><fieldset><input id="woocommerce_cdek_target" data-woodev-show-if="' + attr( tariffIs139() ) + '" />' +
		'<select id="woocommerce_cdek_tariff"><option value="137">A</option></select></fieldset></div>';
	$( document.body ).trigger( 'wc_backbone_modal_loaded' );

	expect( document.querySelector( 'fieldset' ).style.display ).toBe( '' );
	expect( document.querySelector( 'label' ).style.display ).toBe( '' );
} );
