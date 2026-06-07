<?php
/**
 * Woodev Shipping — pickup-point (PVZ) map modal shell.
 *
 * Rendered by {@see \Woodev\Framework\Shipping\Checkout\Pickup_Checkout_Handler::render()}
 * (spec §4.1.vi). Ships only the empty modal shell + an empty map container; the
 * front-end (`assets/js/frontend/checkout.js` driving `pickup-map.js`) toggles the
 * `is-open` state and the active map adapter fills the container at runtime. Class
 * names match `assets/css/frontend/pickup-map.css`; no installed-site contract value
 * is emitted here.
 *
 * @var string $modal_id DOM id of the modal shell (front-end toggles its `is-open` state)
 * @var string $map_id   DOM id of the map container the adapter renders into
 * @var string $title    modal heading
 *
 * @since 1.5.0
 */

defined( 'ABSPATH' ) || exit;
?>

<div id="<?php echo esc_attr( $modal_id ); ?>" class="woodev-pickup-map-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-label="<?php echo esc_attr( $title ); ?>">
	<div class="woodev-pickup-map-modal__dialog" role="document">
		<div class="woodev-pickup-map-modal__header">
			<h2 class="woodev-pickup-map-modal__title"><?php echo esc_html( $title ); ?></h2>
			<button type="button" class="woodev-pickup-map-modal__close" data-woodev-pickup-close aria-label="<?php esc_attr_e( 'Close', 'woodev-plugin-framework' ); ?>">&times;</button>
		</div>
		<div class="woodev-pickup-map-modal__body">
			<div id="<?php echo esc_attr( $map_id ); ?>" class="woodev-pickup-map"></div>
		</div>
	</div>
</div>
