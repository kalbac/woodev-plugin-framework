<?php
/**
 * Woodev Shipping — order-edit shipment metabox view.
 *
 * Rendered by {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order::render_metabox()}.
 * Two states, both decided by the caller from the SAME `is_exported` the «Заказы
 * доставки» table/REST surface use (card #856):
 *
 * - NOT exported: `$info_text` plus whatever action buttons `$actions` holds
 *   (normally just export).
 * - Exported: the non-empty `$fields`, the delivery history, then `$actions`
 *   (update/cancel/carrier extras).
 *
 * KISS (operator, #856): `$fields` already excludes anything the carrier did not
 * supply for this order — this view never fills a gap with a dash. A dash is
 * right in the TABLE, where one column serves every row; here there is no
 * column to keep, so an absent field is just a shorter list.
 *
 * @var bool                                                        $is_exported       whether the order has been exported to the carrier
 * @var string                                                      $info_text         shown only when `$is_exported` is false
 * @var array<int, array{label: string, value: string, url: string|null}> $fields     non-empty display fields, shown only when `$is_exported` is true
 * @var string                                                      $history_html      pre-rendered delivery-history markup ('' when there is none to show)
 * @var array<int, array{action: string, label: string, title: string, destructive: bool}> $actions the shared action set {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::for_order()} built for this order
 * @var string                                                      $admin_post_action forward-only admin-post action the button forms target
 * @var string                                                      $nonce_action      nonce action protecting the button forms
 * @var int                                                         $order_id          the order being edited
 *
 * @since 1.5.0
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="woodev-shipping-order-metabox">

	<?php if ( ! $is_exported ) : ?>
		<p><?php echo esc_html( $info_text ); ?></p>
	<?php else : ?>

		<?php if ( [] !== $fields ) : ?>
			<table class="widefat striped">
				<tbody>
				<?php foreach ( $fields as $field ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $field['label'] ); ?></th>
						<td>
							<?php if ( null !== $field['url'] && '' !== $field['url'] ) : ?>
								<a href="<?php echo esc_url( $field['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $field['value'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $field['value'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( '' !== $history_html ) : ?>
			<h4><?php esc_html_e( 'История доставки', 'woodev-plugin-framework' ); ?></h4>
			<?php
			// The history markup was already built (and escaped) by the caller —
			// either the framework's own default renderer or a carrier plugin's
			// `{prefix}_tracking_admin_display` subscriber replacing it wholesale.
			echo $history_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered, see above.
			?>
		<?php endif; ?>

	<?php endif; ?>

	<?php if ( [] !== $actions ) : ?>
		<p class="woodev-shipping-order-actions-buttons">
			<?php foreach ( $actions as $action ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="woodev-shipping-order-action">
					<?php wp_nonce_field( $nonce_action ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( $admin_post_action ); ?>" />
					<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />
					<input type="hidden" name="woodev_shipping_order_action" value="<?php echo esc_attr( $action['action'] ); ?>" />
					<button
						type="submit"
						class="button<?php echo ! empty( $action['destructive'] ) ? ' button-link-delete' : ''; ?>"
						title="<?php echo esc_attr( (string) ( $action['title'] ?? '' ) ); ?>"
						<?php echo ! empty( $action['destructive'] ) ? 'onclick="return confirm( \'' . esc_js( __( 'Вы уверены?', 'woodev-plugin-framework' ) ) . '\' );"' : ''; ?>
					>
						<?php echo esc_html( $action['label'] ); ?>
					</button>
				</form>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>

</div>
