<?php

/**
 * Shipping-method block for the WooCommerce system status report.
 *
 * Rendered once per enabled shipping method from
 * {@see \Woodev\Framework\Shipping\Shipping_Plugin::add_system_status_information()},
 * therefore within this view `$this` is the owning shipping plugin instance and
 * `$method` is the shipping method being reported on.
 *
 * @var \Woodev\Framework\Shipping\Shipping_Method $method the shipping method being reported
 * @var \Woodev\Framework\Shipping\Shipping_Plugin $this   the owning shipping plugin (include context)
 */

defined( 'ABSPATH' ) || exit;

// The view is included from within the plugin instance, so `$this` is the owning plugin.
$plugin = ( isset( $this ) && $this instanceof \Woodev\Framework\Shipping\Shipping_Plugin ) ? $this : null;

$render_bool = static function ( bool $state ): string {
	return $state
		? '<mark class="yes">&#10004;</mark>'
		: '<mark class="error">&#10005;</mark>';
};

if ( $method->is_courier_shipping() ) {
	$delivery_type_label = __( 'Курьерская доставка', 'woodev-plugin-framework' );
} elseif ( $method->is_pickup_shipping() ) {
	$delivery_type_label = __( 'Самовывоз', 'woodev-plugin-framework' );
} elseif ( $method->is_postal_shipping() ) {
	$delivery_type_label = __( 'Почтовая доставка', 'woodev-plugin-framework' );
} else {
	$delivery_type_label = $method->get_delivery_type();
}

?>

<table class="wc_status_table widefat" cellspacing="0">

	<thead>
	<tr>
		<th colspan="3" data-export-label="">
			<?php echo esc_html( $method->get_method_title() ); ?>
			<?php echo wc_help_tip( __( 'This section contains configuration settings for this shipping method.', 'woodev-plugin-framework' ) ); ?>
		</th>
	</tr>
	</thead>

	<tbody>

	<?php
	/**
	 * Shipping Method System Status Start Action.
	 *
	 * Allow actors to add info the start of the shipping method system status section.
	 *
	 * @since 1.5.0
	 *
	 * @param \Woodev\Framework\Shipping\Shipping_Method $method
	 */
	do_action( 'woodev_shipping_method_' . $method->get_id() . '_system_status_start', $method );
	?>

	<tr>
		<td data-export-label="Method ID"><?php esc_html_e( 'Идентификатор метода', 'woodev-plugin-framework' ); ?>:</td>
		<td class="help"><?php echo wc_help_tip( __( 'The WooCommerce shipping method identifier used in shipping zones.', 'woodev-plugin-framework' ) ); ?></td>
		<td><code><?php echo esc_html( $method->get_method_id() ); ?></code></td>
	</tr>

	<tr>
		<td data-export-label="Delivery Type"><?php esc_html_e( 'Тип доставки', 'woodev-plugin-framework' ); ?>:</td>
		<td class="help"><?php echo wc_help_tip( __( 'How this method delivers orders (courier, pickup or postal).', 'woodev-plugin-framework' ) ); ?></td>
		<td><?php echo esc_html( $delivery_type_label ); ?></td>
	</tr>

	<tr>
		<td data-export-label="Environment"><?php esc_html_e( 'Окружение', 'woodev-plugin-framework' ); ?>:</td>
		<td class="help"><?php echo wc_help_tip( __( 'The WordPress environment type of this site.', 'woodev-plugin-framework' ) ); ?></td>
		<td><?php echo esc_html( wp_get_environment_type() ); ?></td>
	</tr>

	<tr>
		<td data-export-label="Debug Mode"><?php esc_html_e( 'Режим отладки', 'woodev-plugin-framework' ); ?>:</td>
		<td class="help"><?php echo wc_help_tip( __( 'Whether debug logging is enabled for this shipping method.', 'woodev-plugin-framework' ) ); ?></td>
		<td>
			<?php echo $plugin ? wp_kses_post( $render_bool( $plugin->is_debug_enabled() ) ) : esc_html__( 'N/A', 'woodev-plugin-framework' ); ?>
		</td>
	</tr>

	<tr>
		<td data-export-label="Configured"><?php esc_html_e( 'Настроен', 'woodev-plugin-framework' ); ?>:</td>
		<td class="help"><?php echo wc_help_tip( __( 'Whether the method has the credentials/settings it needs to operate.', 'woodev-plugin-framework' ) ); ?></td>
		<td>
			<?php
			// "Configured" state is owned by the Shipping_Integration (the settings
			// object), not by Shipping_Method -- the latter has no is_configured(), so the
			// old method_exists( $method, ... ) guard was always false and this row never
			// rendered. Read it from the plugin's integration handler instead.
			$integration = $plugin ? $plugin->get_integration_handler() : null;

			if ( null !== $integration ) {
				echo wp_kses_post( $render_bool( $integration->is_configured() ) );
			} else {
				esc_html_e( 'N/A', 'woodev-plugin-framework' );
			}
			?>
		</td>
	</tr>

	<tr>
		<td data-export-label="API Available"><?php esc_html_e( 'API доступен', 'woodev-plugin-framework' ); ?>:</td>
		<td class="help"><?php echo wc_help_tip( __( 'Whether the carrier API integration is wired up for this plugin.', 'woodev-plugin-framework' ) ); ?></td>
		<td>
			<?php echo $plugin ? wp_kses_post( $render_bool( null !== $plugin->get_api() ) ) : esc_html__( 'N/A', 'woodev-plugin-framework' ); ?>
		</td>
	</tr>

	<?php
	/**
	 * Shipping Method System Status End Action.
	 *
	 * Allow actors to add info the end of the shipping method system status section.
	 *
	 * @since 1.5.0
	 * @param \Woodev\Framework\Shipping\Shipping_Method $method
	 */
	do_action( 'woodev_shipping_method_' . $method->get_id() . '_system_status_end', $method );
	?>

	</tbody>

</table>
