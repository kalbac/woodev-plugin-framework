<?php
/**
 * Buyer-facing tracking block, printed under the order details (My Account, order-received page).
 *
 * Override in a theme: {theme}/woocommerce/woodev/shipping/order/tracking.php
 *
 * @since 2.0.25
 *
 * @package Woodev\Framework\Shipping
 * @var WC_Order                                                                          $order
 * @var \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider                           $provider
 * @var array<int,array{caption:string,text:string,url:?string,status:string}> $lines one row per parcel.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="woocommerce-order-tracking woodev-tracking">
	<h2 class="woocommerce-order-details__title"><?php echo esc_html__( 'Delivery tracking', 'woodev-plugin-framework' ); ?></h2>
	<table class="woocommerce-table shop_table woodev-tracking__table">
		<tbody>
			<?php foreach ( $lines as $line ) : ?>
				<tr class="woodev-tracking__row">
					<th scope="row"><?php echo esc_html( $line['caption'] ); ?></th>
					<td>
						<?php if ( null !== $line['url'] ) : ?>
							<a href="<?php echo esc_url( $line['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $line['text'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $line['text'] ); ?>
						<?php endif; ?>
						<?php if ( '' !== $line['status'] ) : ?>
							<div class="woodev-tracking__status"><?php echo esc_html( $line['status'] ); ?></div>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</section>
