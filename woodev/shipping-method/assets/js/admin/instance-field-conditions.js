/**
 * «Show this field when…» for the instance form of a Woodev shipping method.
 *
 * The form is built by PHP (Instance_Field_Conditions): a field that declares `show_if` carries
 * `data-woodev-show-if` on its control, a JSON `{ relation: 'AND'|'OR', conditions: [ { field, operator, value } ] }`
 * where `field` is the id of the controlling control and `operator` is one of `=`, `!=`, `in`, `not_in`. This file only
 * evaluates it, on load and on every change, and hides or shows the field's whole block: the table row on the method's
 * own page, the label and fieldset in the modal. Hiding is presentation only:
 * a hidden control stays in the form and is submitted, so its saved value is kept.
 *
 * Values are compared as strings; a checkbox is `yes` / `no`; a multiselect matches when any chosen value does
 * (`!=` / `not_in`: when none does). A controlling field that is not on the page makes the rule inapplicable and the
 * field stays visible — a broken rule must not hide a setting.
 *
 * It runs on the method's own page and in the modal WooCommerce opens from the zone screen. The modal's form is
 * inserted by Backbone after load and announced with `wc_backbone_modal_loaded` (WooCommerce
 * assets/js/admin/backbone-modal.js, triggered on document.body; verified against WooCommerce 11.1.2).
 *
 * The modal has no table: WooCommerce's reformatSettingsHTML() (assets/js/admin/wc-shipping-zone-methods.js,
 * replaceHTMLTables) swaps `table.form-table` for `div.wc-shipping-zone-method-fields` and re-parses its rows as HTML
 * in a div, which drops the `tr` / `th` / `td` tags. What is left per field is a `label` and a `fieldset` standing next
 * to each other, so the field's block there is that label plus its fieldset.
 *
 * @since 2.0.2
 */
( function ( $ ) {
	'use strict';

	var ATTRIBUTE = 'data-woodev-show-if';

	/** The values a control holds, as strings. */
	function valuesOf( control ) {
		var value;

		if ( 'checkbox' === control.type ) {
			return [ control.checked ? 'yes' : 'no' ];
		}

		value = $( control ).val();

		if ( null === value || undefined === value ) {
			return [ '' ];
		}

		if ( Array.isArray( value ) ) {
			return value.length ? value.map( String ) : [ '' ];
		}

		return [ String( value ) ];
	}

	/** True/false, or null when the controlling field is not on the page. */
	function matches( condition ) {
		var control = document.getElementById( condition.field );
		var current;
		var wanted;
		var hit;

		if ( ! control ) {
			return null;
		}

		current = valuesOf( control );
		wanted = [].concat( condition.value ).map( String );
		hit = current.some( function ( value ) {
			return wanted.indexOf( value ) !== -1;
		} );

		switch ( condition.operator ) {
			case '=':
			case 'in':
				return hit;
			case '!=':
			case 'not_in':
				return ! hit;
			default:
				return null;
		}
	}

	/** Whether the declared rule lets the field show; anything that cannot be evaluated lets it show. */
	function isVisible( rule ) {
		var results;

		if ( ! rule || ! Array.isArray( rule.conditions ) || ! rule.conditions.length ) {
			return true;
		}

		results = rule.conditions.map( matches );

		if ( results.indexOf( null ) !== -1 ) {
			return true;
		}

		return 'OR' === rule.relation ? results.indexOf( true ) !== -1 : results.indexOf( false ) === -1;
	}

	/**
	 * The elements that make up a control's whole field block: the table row on the method's own page, the label
	 * and the fieldset in the zone modal. Empty when the control sits in neither.
	 */
	function blockOf( control ) {
		var row = control.closest( 'tr' );
		var fieldset;
		var label;

		if ( row ) {
			return [ row ];
		}

		fieldset = control.closest( 'fieldset' );

		if ( ! fieldset || ! fieldset.parentElement || ! fieldset.parentElement.classList.contains( 'wc-shipping-zone-method-fields' ) ) {
			return [];
		}

		label = fieldset.previousElementSibling;

		if ( ! label || 'LABEL' !== label.tagName ) {
			label = null;
		}

		return label ? [ label, fieldset ] : [ fieldset ];
	}

	function refresh() {
		document.querySelectorAll( '[' + ATTRIBUTE + ']' ).forEach( function ( control ) {
			var block = blockOf( control );
			var display;
			var rule;

			if ( ! block.length ) {
				return;
			}

			try {
				rule = JSON.parse( control.getAttribute( ATTRIBUTE ) );
			} catch ( error ) {
				return;
			}

			// an inline style, not the `hidden` attribute: a table row's or a modal fieldset's own display rule would win over the latter
			display = isVisible( rule ) ? '' : 'none';
			block.forEach( function ( element ) {
				element.style.display = display;
			} );
		} );
	}

	// jQuery's handler, not addEventListener: selectWoo announces its changes through jQuery only.
	$( document ).on( 'change wc_backbone_modal_loaded', refresh );
	$( refresh );
}( jQuery ) );
