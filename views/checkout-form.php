<?php

function render_checkout_form( $total ) {

	?>
	<div class="tix-submit">
		<?php if ( $total > 0 ) : ?>
			<div class="tix-payment-method" role="tabs">
				<?php render_tab_bar(); ?>
			</div>
			<div class="tix-payment-method-container">
			<?php render_alternate_payment_options(); ?>
			<input class="tix-checkout-button" type="submit" value="<?php esc_attr_e( 'Checkout &rarr;', 'camptix' ); ?>" />
				<?php else : ?>
					<input class="tix-checkout-button" type="submit" value="<?php esc_attr_e( 'Claim Tickets &rarr;', 'camptix' ); ?>" />
				<?php endif; ?>
			</div>
			<br class="tix-clear" />
	</div>
	<?php
}

function render_tab_bar() {
	global $camptix;

	$has_preferred_payments_tab = false;
	$options = $camptix->get_options();
	if ( isset( $options['preferred_payment_method'] )
	     && array_key_exists( $options['preferred_payment_method'], $camptix->get_enabled_payment_methods() ) ) {
		$has_preferred_payments_tab = true;
		$preferred_payment_option = $camptix->get_enabled_payment_methods()[ $options['preferred_payment_method'] ];
		?>
		<input type="radio" role="tab" name="tix-payment-method" id="tix-preferred-payment-option"
		       value="<?php echo esc_attr( $options['preferred_payment_method'] ); ?>" >
		<label for="tix-preferred-payment-option" class="tix-payment-tab tix-tab-selected">
			<?php echo esc_html__( 'Pay with ', 'camptix' ) . $preferred_payment_option[ 'name' ] ?>
		</label>
		<?php
	}

	?>
	<button role="tab" class="tix_other_payment_options tix-payment-tab" type="button">
		<?php if ( $has_preferred_payments_tab ) {
			esc_html_e( 'Other payment methods', 'camptix' );
		} else {
			esc_html_e( 'Payment methods', 'camptix' );
		}
		?>
	</button>
	<?php

}
function render_preferred_payment_option() {

}

function render_alternate_payment_options() {

	global $camptix;

	foreach ( $camptix->get_enabled_payment_methods() as $payment_method_key => $payment_method ) {
		$options = $camptix->get_options();
		if ( isset( $options['preferred_payment_method'] ) && $options['preferred_payment_method'] === $payment_method_key )
			continue;
		?>

		<div class="tix-alternate-payment-option">
			<input role="tab" type="radio" name="tix-payment-method"
			       id="tix-payment-method_<?php echo esc_attr( $payment_method_key ); ?>"
			       value="<?php echo esc_attr( $payment_method_key ); ?>">

			<label for="tix-payment-method_<?php echo esc_attr( $payment_method_key ); ?>">
				<?php echo esc_html( $payment_method['name'] ); ?>
			</label>
		</div>

	<?php
	}
}