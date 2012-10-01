<?php
/**
 * Blackhole Payment Method for CampTix
 *
 * The blackhole payment method is not a real-world method. You shouldn't use this in
 * your projects. It was designed to test payments and give a little example of how
 * real payment methods should be written. PayPal, however, is a better example.
 *
 * @since CampTix 1.2
 */
class CampTix_Payment_Method_Blackhole extends CampTix_Payment_Method {

	/**
	 * The following variables have to be defined for each payment method.
	 */
	public $id = 'blackhole';
	public $name = 'Blackhole';
	public $description = 'Will always result in a successful or failed payment.';
	public $supported_currencies = array( 'USD', 'EUR' );

	/**
	 * You can store options in a class locally, use
	 * $this->get_payment_options() to retrieve them from CampTix.
	 */
	protected $options;

	function camptix_init() {
		$this->options = array_merge( array(
			'always_succeed' => true,
		), $this->get_payment_options() );
	}

	/**
	 * Optionally, a payment method can have one ore more options.
	 * Use the helper function to add fields. See the CampTix_Payment_Method
	 * class for more info.
	 */
	function payment_settings_fields() {
		$this->add_settings_field_helper( 'always_succeed', __( 'Always Succeed', 'camptix' ), array( $this, 'field_yesno' ) );
	}

	/**
	 * If your payment method has options, CampTix will call your
	 * validate_options() method when saving them. Make sure you grab
	 * the $input and produce an $output.
	 */
	function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['always_succeed'] ) )
			$output['always_succeed'] = (bool) $input['always_succeed'];

		return $output;
	}

	/**
	 * This is the main method of your class. It is fired as soon as a user
	 * initiates the checkout process with your payment method selected. At any
	 * point in time, you can use $this->payment_result to return a payment result
	 * back to CampTix. This does not necessarily have to happen in this function.
	 * See the PayPal example for details.
	 */
	function payment_checkout( $payment_token ) {
		global $camptix;

		// Process $order and do something.
		$order = $this->get_order( $payment_token );
		do_action( 'camptix_before_payment', $payment_token );
		update_option( 'camptix_last_purchase_time', time() );

		$payment_data = array(
			'transaction_id' => 'tix-blackhole-' . md5( sprintf( 'tix-blackhole-%s-%s-%s', print_r( $order, true ), time(), rand( 1, 9999 ) ) ),
			'transaction_details' => array(
				// @todo maybe add more info about the payment
				'raw' => array( 'payment_method' => 'blackhole' ),
			),
		);

		if ( $this->options['always_succeed'] )
			return $this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_COMPLETED, $payment_data );
		else
			return $this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_FAILED );
	}
}

/**
 * The last stage is to register your payment method with CampTix.
 * Since the CampTix_Payment_Method class extends from CampTix_Addon,
 * we use the camptix_register_addon function to register it.
 */
camptix_register_addon( 'CampTix_Payment_Method_Blackhole' );