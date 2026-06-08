<?php
/**
 * Woodev Shipping — order-edit shipment metabox view.
 *
 * Rendered by {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order::render_metabox()}.
 * Displays the shipment's read-only fields (carrier order id, tracking number, chosen
 * pickup point — already resolved through the plugin's order-meta map) and the
 * export / track / cancel action buttons, which post to `admin-post.php` under the
 * plugin-namespaced action.
 *
 * @var array<int, array{label: string, value: string}> $fields            display fields (label + already-resolved value)
 * @var array<int, array{key: string, label: string, class: string}> $actions action buttons
 * @var string                                           $admin_post_action forward-only admin-post action the form targets
 * @var string                                           $nonce_action      nonce action protecting the form
 * @var int                                              $order_id          the order being edited
 *
 * @since 1.5.0
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="woodev-shipping-order-metabox">

	<table class="widefat striped">
		<tbody>
		<?php foreach ( $fields as $field ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( $field['label'] ); ?></th>
				<td><?php echo '' !== $field['value'] ? esc_html( $field['value'] ) : '&ndash;'; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="woodev-shipping-order-actions">
		<input type="hidden" name="action" value="<?php echo esc_attr( $admin_post_action ); ?>" />
		<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />
		<?php wp_nonce_field( $nonce_action ); ?>

		<p class="woodev-shipping-order-actions-buttons">
			<?php foreach ( $actions as $action ) : ?>
				<button type="submit" name="woodev_shipping_order_action" value="<?php echo esc_attr( $action['key'] ); ?>" class="<?php echo esc_attr( $action['class'] ); ?>"><?php echo esc_html( $action['label'] ); ?></button>
			<?php endforeach; ?>
		</p>
	</form>

</div>
