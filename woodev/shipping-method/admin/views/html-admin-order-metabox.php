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
 * NO `<form>` HERE (#1012): this box sits INSIDE WooCommerce's order form, and HTML forbids nested
 * forms — the browser drops the inner opening tag and the inner `</form>` closes the OUTER order
 * form, so every field after it (the status select among them) stopped being submitted. Each action
 * is a `type="button"` carrying its payload as data attributes; `order-metabox-actions.js` builds a
 * detached form outside the order form on click and posts it to the same admin-post handler.
 *
 * @var bool                                                        $is_exported       whether the order has been exported to the carrier
 * @var string                                                      $info_text         shown only when `$is_exported` is false
 * @var array<int, array{label: string, value: string, url: string|null, tone?:string}> $fields non-empty display fields, shown only when `$is_exported` is true
 * @var bool                                                        $shipment_outdated whether the order changed after it was handed to the carrier (#947); the warning is shown only when `$is_exported` is true
 * @var array<int, array{label: string, tone: string, title?: string}> $flags the row's badges {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Row_Flags}; drawn under the details of an exported order
 * @var string                                                      $history_html      pre-rendered delivery-history markup ('' when there is none to show)
 * @var array<int, array{action: string, label: string, title: string, destructive: bool, disabled?: bool, fields?: array<int, array<string, mixed>>, icon?: string, confirm?: string}> $actions the row action set {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::for_row()} built for this order; a locked action carries `disabled` and the lock reason as its `title`
 * @var string                                                      $admin_post_action forward-only admin-post action the buttons post to
 * @var string                                                      $nonce_action      nonce action protecting the buttons' post
 * @var int                                                         $order_id          the order being edited
 * @var string                                                      $documents_route_base REST route (no host) of the order's carrier documents; a document action appends its id
 * @var string                                                      $rest_nonce        `wp_rest` nonce a document download sends
 *
 * @since 1.5.0
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="woodev-shipping-order-metabox">

	<?php if ( ! $is_exported ) : ?>
		<p><?php echo esc_html( $info_text ); ?></p>
	<?php else : ?>

		<?php if ( $shipment_outdated ) : ?>
			<div class="notice notice-warning inline woodev-shipping-order-outdated">
				<p><?php esc_html_e( 'Заказ изменён после передачи в службу доставки — данные в заявке могут не совпадать. Проверьте заявку в личном кабинете службы доставки.', 'woodev-plugin-framework' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( [] !== $fields ) : ?>
			<table class="widefat striped">
				<tbody>
				<?php foreach ( $fields as $field ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $field['label'] ); ?></th>
						<td>
							<?php if ( null !== $field['url'] && '' !== $field['url'] ) : ?>
								<a href="<?php echo esc_url( $field['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $field['value'] ); ?></a>
							<?php elseif ( isset( $field['tone'] ) ) : ?>
								<span class="woodev-orders-status woodev-orders-status--<?php echo esc_attr( $field['tone'] ); ?>"><?php echo esc_html( $field['value'] ); ?></span>
							<?php else : ?>
								<?php echo esc_html( $field['value'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( [] !== $flags ) : ?>
			<ul class="woodev-orders-flags woodev-shipping-order-flags">
				<?php foreach ( $flags as $flag ) : ?>
					<li class="woodev-orders-flag woodev-orders-flag--<?php echo esc_attr( (string) $flag['tone'] ); ?>"<?php echo ! empty( $flag['title'] ) ? ' title="' . esc_attr( (string) $flag['title'] ) . '"' : ''; ?>><?php echo esc_html( (string) $flag['label'] ); ?></li>
				<?php endforeach; ?>
			</ul>
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

	<?php
	// The sentences the input dialog of an action with fields (#1180) shows — the script knows none (Rule 9).
	$field_dialog_labels = [
		'cancel'   => __( 'Отмена', 'woodev-plugin-framework' ),
		'close'    => __( 'Закрыть', 'woodev-plugin-framework' ),
		'from'     => __( 'с', 'woodev-plugin-framework' ),
		'to'       => __( 'до', 'woodev-plugin-framework' ),
		'none'     => __( '— не выбрано —', 'woodev-plugin-framework' ),
		'required' => __( 'обязательное поле', 'woodev-plugin-framework' ),
		'range'    => __( 'Время окончания должно быть позже времени начала.', 'woodev-plugin-framework' ),
	];
	?>

	<?php if ( [] !== $actions ) : ?>
		<?php
		// ONE button group in a single row, however many actions there are. Past two they become icon-only — the sidebar
		// is ~250px wide, so three labelled buttons would wrap; the label then travels in the tooltip and `aria-label`.
		$icon_only = count( $actions ) > 2;
		$doc_label = [
			// The sentences a document download shows — the script knows none (Rule 9). `working` is said while the script
			// keeps asking by itself (#1191); `timeout` when the carrier is still not ready after ~30 s.
			'working' => __( 'Документ формируется…', 'woodev-plugin-framework' ),
			'timeout' => __( 'Документ всё ещё формируется. Попробуйте ещё раз через минуту.', 'woodev-plugin-framework' ),
			'failed'  => __( 'Не удалось получить документ у перевозчика.', 'woodev-plugin-framework' ),
		];
		?>
		<div
			class="woodev-shipping-order-actions-buttons<?php echo $icon_only ? ' woodev-shipping-order-actions-buttons--icons' : ''; ?>"
			role="group"
			data-document-labels="<?php echo esc_attr( (string) wp_json_encode( $doc_label ) ); ?>"
		>
			<?php foreach ( $actions as $action ) : ?>
				<?php
				$is_document = \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::is_document( (string) $action['action'] );
				$icon        = \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::sanitize_icon( $action['icon'] ?? '' );
				$hint        = (string) ( $action['title'] ?? '' );
				$tooltip     = $icon_only ? trim( $action['label'] . ( '' !== $hint && $hint !== $action['label'] ? ' — ' . $hint : '' ) ) : $hint;
				?>
				<button
					type="button"
					class="button woodev-shipping-order-action<?php echo ! empty( $action['destructive'] ) ? ' woodev-shipping-order-action--destructive' : ''; ?>"
					title="<?php echo esc_attr( $tooltip ); ?>"
					data-label="<?php echo esc_attr( (string) $action['label'] ); ?>"
					<?php echo $icon_only ? 'aria-label="' . esc_attr( (string) $action['label'] ) . '"' : ''; ?>
					data-woodev-order-action="<?php echo esc_attr( $action['action'] ); ?>"
					<?php if ( $is_document ) : ?>
						data-document-url="<?php echo esc_url( add_query_arg( 'format', 'json', rest_url( $documents_route_base . rawurlencode( (string) $action['action'] ) ) ) ); ?>"
						data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
					<?php else : ?>
						data-post-url="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						data-post-action="<?php echo esc_attr( $admin_post_action ); ?>"
						data-order-id="<?php echo esc_attr( (string) $order_id ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( $nonce_action ) ); ?>"
					<?php endif; ?>
					<?php echo ! empty( $action['destructive'] ) ? 'data-confirm="' . esc_attr( ! empty( $action['confirm'] ) ? (string) $action['confirm'] : __( 'Вы уверены?', 'woodev-plugin-framework' ) ) . '"' : ''; ?>
					<?php if ( ! empty( $action['fields'] ) ) : ?>
						data-fields="<?php echo esc_attr( (string) wp_json_encode( $action['fields'] ) ); ?>"
						data-labels="<?php echo esc_attr( (string) wp_json_encode( $field_dialog_labels ) ); ?>"
					<?php endif; ?>
					<?php disabled( ! empty( $action['disabled'] ) ); ?>
				>
					<?php if ( $icon_only ) : ?>
						<span class="dashicons dashicons-<?php echo esc_attr( '' !== $icon ? $icon : \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::FALLBACK_ICON ); ?>" aria-hidden="true"></span>
					<?php else : ?>
						<?php echo esc_html( $action['label'] ); ?>
					<?php endif; ?>
				</button>
			<?php endforeach; ?>
		</div>
		<p class="woodev-shipping-order-doc-notice" role="status" hidden></p>
	<?php endif; ?>

</div>
