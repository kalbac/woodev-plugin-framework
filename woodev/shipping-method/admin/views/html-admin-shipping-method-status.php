<?php

/**
 * @var \Woodev\Framework\Shipping\Shipping_Method $method
 */

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
		<td data-export-label="Placeholder"><?php esc_html_e( 'Placeholder', 'woodev-plugin-framework' ); ?>:</td>
		<td class="help"><?php echo wc_help_tip( __( 'N/A.', 'woodev-plugin-framework' ) ); ?></td>
		<td>Если вы видите этот текст, разработчик не доделал этот блок. Вы можете сообщить об этом на сайте https://woodev.ru/support</td>
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