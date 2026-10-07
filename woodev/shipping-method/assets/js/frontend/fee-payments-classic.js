/**
 * Classic order form: recalculate shipping when the payment method changes (#1144).
 *
 * WooCommerce's `checkout.js` updates the order review on an address or shipping-method change but not
 * on a gateway change, so a fee that depends on the payment method would keep showing the rate for the
 * previously chosen one. Enqueued by `Fee_Payments::enqueue_classic_script()` only while some shipping
 * method instance limits its fee to payment methods — a shop that does not use it loads nothing.
 *
 * `change` (a customer's own choice), not `payment_method_selected`: the latter also fires once on
 * page load, when WooCommerce ticks the first gateway, and that update is already coming from `init_checkout`.
 */
( function ( $ ) {
	$( 'form.checkout' ).on( 'change', 'input[name="payment_method"]', function () {
		$( document.body ).trigger( 'update_checkout' );
	} );
}( jQuery ) );
