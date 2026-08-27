<?php
/**
 * @var string $title
 * @var array  $columns
 * @var int    $id
 * @var int    $user_id
 * @var string $rendered_marker_name hidden marker input name, {@see Woodev_Payment_Gateway_Admin_Payment_Token_Editor::get_rendered_marker_name()}
 */
?>

<tr>

	<th><?php echo esc_html( $title ); ?></th>

	<td class="forminp">

		<?php
		/*
		 * Issue #393: says "this editor WAS on the form", which the token rows themselves
		 * cannot. An editor rendered with zero rows posts no token inputs at all — exactly
		 * like an editor that was never rendered — so without this marker `save()` could not
		 * tell "the admin deleted the last card" from "this table was skipped", and treated
		 * both as the former. See that method's docblock for the data loss that produced.
		 */
		?>
		<input type="hidden" name="<?php echo esc_attr( $rendered_marker_name ); ?>" value="1" />

		<table class="woodev_payment_gateway_token_editor widefat" data-gateway-id="<?php echo esc_attr( $id ); ?>">

			<thead>
				<tr>

					<?php
					// Display a column for each token field
					foreach ( $columns as $column_id => $column_title ) :
						?>
						<th class="token-<?php echo esc_attr( $column_id ); ?>"><?php echo esc_html( $column_title ); ?></th>
					<?php endforeach; ?>

				</tr>
			</thead>

			<tbody class="tokens">

				<?php
				/** Fire inside the payment gateway token editor.
				 *
				 * @param int $user_id the current user ID
				 */
				do_action( 'wc_payment_gateway_' . $id . '_token_editor_tokens', $user_id );
				?>

			</tbody>

			<tbody class="meta">
				<tr class="no-tokens">
					<td colspan="<?php echo count( $columns ); ?>"><?php esc_html_e( 'No saved payment tokens', 'woodev-plugin-framework' ); ?></td>
				</tr>
			</tbody>

			<?php
			// Editor actions
			if ( ! empty( $actions ) ) :
				?>

				<tfoot>
					<tr>
						<th class="actions" colspan="<?php echo count( $columns ); ?>">

							<?php foreach ( $actions as $action => $label ) : ?>

									<?php $button_class = 'save' === $action ? 'button-primary' : 'button'; ?>

									<button class="woodev-payment-gateway-token-editor-action-button <?php echo sanitize_html_class( $button_class ); ?>" data-action="<?php echo esc_attr( $action ); ?>" data-user-id="<?php echo esc_attr( $user_id ); ?>">
										<?php echo esc_attr( $label ); ?>
									</button>

							<?php endforeach; ?>

						</th>
					</tr>
				</tfoot>

			<?php endif; ?>

		</table>

	</td>

</tr>
