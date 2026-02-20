<?php
/**
 * @var string $name
 * @var string $label
 * @var string $value
 *
 */
?>

<tr>
	<th><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
	<td>
		<input class="regular-text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" type="text" /><br/>
		<span class="description"><?php esc_html_e( 'The gateway customer ID for the user. Only edit this if necessary.', 'woodev-plugin-framework' ); ?></span>
	</td>
</tr>
