<?php

/**
 * @param $payment_output string HTML output generated for payment options
 * @param $total float  Total amount to be charged
 * @param $payment_methods array Enabled payment methods
 * @param $selected_payment_method string Key for the payment method already selected
 * (Useful when form is refreshed or some error happens while checkout )
 * 
 * @return string HTML output for payment options form 
 */
function generate_payment_options( $payment_output, $total, $payment_methods, $selected_payment_method ) {
	ob_start();
	?>

	<p class="tix-submit">
	<?php if ( $total > 0 ) : ?>
		<select name="tix_payment_method">
			<?php foreach ( $payment_methods as $payment_method_key => $payment_method ) : ?>
				<option 
					<?php 
						selected( 
							! empty( $selected_payment_method ) && $selected_payment_method == $payment_method_key 
						); 
					?> 
					value="<?php echo esc_attr( $payment_method_key ); ?>"><?php echo esc_html( $payment_method['name'] ); ?>

				</option>
			<?php endforeach; ?>
		</select>
		<input type="submit" value="<?php esc_attr_e( 'Checkout &rarr;', 'camptix' ); ?>" />
	<?php else : ?>
		<input type="submit" value="<?php esc_attr_e( 'Claim Tickets &rarr;', 'camptix' ); ?>" />
	<?php endif; ?>
	<br class="tix-clear" />
	</p>

	<?php
	$payment_options_op = ob_get_contents();
	ob_end_clean();
	return $payment_options_op;
}

add_filter( 'tix_render_payment_options', 'generate_payment_options', 10, 4 );
