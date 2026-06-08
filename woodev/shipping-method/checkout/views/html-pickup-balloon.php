<?php
/**
 * Woodev Shipping — pickup-point (PVZ) balloon template.
 *
 * Rendered by {@see \Woodev\Framework\Shipping\Checkout\Pickup_Checkout_Handler::render()}
 * (spec §4.1.vi). A client-side micro-template the front-end fills with the chosen
 * point's details after selection: `checkout.js` substitutes the `{{name}}`,
 * `{{address}}` and `{{description}}` placeholders (escaping each value first) to
 * render the at-checkout summary. Provider-agnostic and contract-neutral — class
 * names reuse `assets/css/frontend/pickup-map.css`; no installed-site value is emitted.
 *
 * @var string $template_id DOM id of the `<script type="text/template">` block
 *
 * @since 1.5.0
 */

defined( 'ABSPATH' ) || exit;
?>

<script type="text/template" id="<?php echo esc_attr( $template_id ); ?>">
	<div class="woodev-pickup-popup woodev-pickup-balloon">
		<div class="woodev-pickup-popup__title">{{name}}</div>
		<div class="woodev-pickup-popup__address">{{address}}</div>
		<div class="woodev-pickup-popup__description">{{description}}</div>
	</div>
</script>
