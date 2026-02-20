<?php

/**
 * @var Woodev_Payment_Gateway $gateway
 * @var string $environment
 */
?>

<table class="wc_status_table widefat" cellspacing="0">

	<thead>
		<tr>
			<th colspan="3" data-export-label="">
				<?php echo esc_html( $gateway->get_method_title() ); ?>
				<?php echo wc_help_tip( __( 'This section contains configuration settings for this gateway.', 'woodev-plugin-framework' ) ); ?>
			</th>
		</tr>
	</thead>

	<tbody>

		<?php
			/**
			 * Payment Gateway System Status Start Action.
			 *
			 * Allow actors to add info the start of the gateway system status section.
			 *
			 * @param Woodev_Payment_Gateway $gateway
			 */
			do_action( 'wc_payment_gateway_' . $gateway->get_id() . '_system_status_start', $gateway );
		?>

		<tr>
			<td data-export-label="Environment"><?php esc_html_e( 'Environment', 'woodev-plugin-framework' ); ?>:</td>
			<td class="help"><?php echo wc_help_tip( __( 'The transaction environment for this gateway.', 'woodev-plugin-framework' ) ); ?></td>
			<td><?php echo esc_html( $environment ); ?></td>
		</tr>

		<?php if ( $gateway->supports_tokenization() ) : ?>

			<tr>
				<td data-export-label="Tokenization Enabled"><?php esc_html_e( 'Tokenization Enabled', 'woodev-plugin-framework' ); ?>:</td>
				<td class="help"><?php echo wc_help_tip( __( 'Displays whether or not tokenization is enabled for this gateway.', 'woodev-plugin-framework' ) ); ?></td>
				<td>
					<?php if ( $gateway->tokenization_enabled() ) : ?>
						<mark class="yes">&#10004;</mark>
					<?php else : ?>
						<mark class="no">&ndash;</mark>
					<?php endif; ?>
				</td>
			</tr>

		<?php endif; ?>

		<tr>
			<td data-export-label="Debug Mode"><?php esc_html_e( 'Debug Mode', 'woodev-plugin-framework' ); ?>:</td>
			<td class="help"><?php echo wc_help_tip( __( 'Displays whether or not debug logging is enabled for this gateway.', 'woodev-plugin-framework' ) ); ?></td>
			<td>
				<?php if ( $gateway->debug_log() && $gateway->debug_checkout() ) : ?>
					<?php echo esc_html__( 'Display at Checkout & Log', 'woodev-plugin-framework' ); ?>
				<?php elseif ( $gateway->debug_checkout() ) : ?>
					<?php echo esc_html__( 'Display at Checkout', 'woodev-plugin-framework' ); ?>
				<?php elseif ( $gateway->debug_log() ) : ?>
					<?php echo esc_html__( 'Save to Log', 'woodev-plugin-framework' ); ?>
				<?php else : ?>
					<?php echo esc_html__( 'Off', 'woodev-plugin-framework' ); ?>
				<?php endif; ?>
			</td>
		</tr>

		<?php
			/**
			 * Payment Gateway System Status End Action.
			 *
			 * Allow actors to add info the end of the gateway system status section.
			 *
			 * @param Woodev_Payment_Gateway $gateway
			 */
			do_action( 'wc_payment_gateway_' . $gateway->get_id() . '_system_status_end', $gateway );
		?>

	</tbody>

</table>
