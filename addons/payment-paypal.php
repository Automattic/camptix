<?php
/**
 * PayPal Payment Gateway for CampTix
 */

class CampTix_Payment_Gateway extends CampTix_Addon {
	function payment_result( $payment_token, $result ) {
		global $camptix;
		return $camptix->payment_result( $payment_token, $result );
	}

	function redirect_with_error_flags( $query_args = array() ) {
		global $camptix;
		$camptix->redirect_with_error_flags( $query_args );
	}

	function error_flag( $flag ) {
		global $camptix;
		$camptix->error_flag( $flag );
	}

	function get_tickets_url() {
		global $camptix;
		return $camptix->get_tickets_url();
	}

	function log( $message, $post_id = 0, $data = null, $module = 'general' ) {
		global $camptix;
		return $camptix->log( $message, $post_id, $data, $module );
	}
}

class CampTix_Payment_Gateway_PayPal extends CampTix_Payment_Gateway {
	protected $options = array();
	protected $error_flags = array();

	/**
	 * Runs during camptix_init, @see CampTix_Addon
	 */
	function camptix_init() {

		$this->options = array(
			'api_username' => 'seller_1336582765_biz_api1.automattic.com',
			'api_password' => '1336582791',
			'api_signature' => 'AAIC4ZQTUrzRU3RisBfEDkKUjdmwAnhS47jgmW1pnLf4G517HvqUlxkD',
			'sandbox' => true,
			'currency' => 'USD',
		);

		add_filter( 'camptix_available_payment_methods', array( $this, 'camptix_available_payment_methods' ) );
		add_action( 'camptix_payment_checkout_paypal', array( $this, 'camptix_payment_checkout_paypal' ), 10, 3 );

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
	}

	function template_redirect() {
		if ( ! isset( $_REQUEST['tix_payment_gateway'] ) || 'paypal' != $_REQUEST['tix_payment_gateway'] )
			return;

		if ( 'payment_cancel' == get_query_var( 'tix_action' ) )
			$this->payment_cancel();

		if ( 'payment_return' == get_query_var( 'tix_action' ) )
			$this->payment_return();
	}

	function payment_cancel() {
		global $camptix;

		if ( ! isset( $_REQUEST['tix_payment_token'] ) || empty( $_REQUEST['tix_payment_token'] ) )
			die( 'empty payment token' );

		if ( ! isset( $_REQUEST['token'] ) || empty( $_REQUEST['token'] ) )
			die( 'empty paypal token' );

		$payment_token = trim( $_REQUEST['tix_payment_token'] );
		$paypal_token = trim( $_REQUEST['token'] );

		if ( ! $payment_token || ! $paypal_token )
			die( 'empty token' );

		$attendees = get_posts( array(
			'posts_per_page' => -1,
			'post_type' => 'tix_attendee',
			'post_status' => array( 'draft' ),
			'meta_query' => array(
				array(
					'key' => 'tix_payment_token',
					'compare' => '=',
					'value' => $payment_token,
					'type' => 'CHAR',
				),
			),
		) );

		foreach ( $attendees as $attendee )
			if ( get_post_meta( $attendee->ID, 'tix_paypal_token', true ) != $paypal_token )
				die( 'paypal token does not match!' );

		$this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_CANCELLED );
		die();
	}

	function payment_return() {
		global $camptix;

		if ( ! isset( $_REQUEST['tix_payment_token'] ) || empty( $_REQUEST['tix_payment_token'] ) )
			die( 'empty payment token' );

		if ( ! isset( $_REQUEST['token'] ) || empty( $_REQUEST['token'] ) )
			die( 'empty paypal token' );

		$payment_token = trim( $_REQUEST['tix_payment_token'] );
		$paypal_token = trim( $_REQUEST['token'] );
		$payer_id = trim( $_REQUEST['PayerID'] );

		if ( ! $payment_token || ! $paypal_token )
			die( 'empty token' );

		$attendees = get_posts( array(
			'posts_per_page' => -1,
			'post_type' => 'tix_attendee',
			'post_status' => array( 'draft' ),
			'meta_query' => array(
				array(
					'key' => 'tix_payment_token',
					'compare' => '=',
					'value' => $payment_token,
					'type' => 'CHAR',
				),
			),
		) );

		foreach ( $attendees as $attendee )
			if ( get_post_meta( $attendee->ID, 'tix_paypal_token', true ) != $paypal_token )
				die( 'paypal token does not match!' );

		$expected_total = (float) get_post_meta( $attendees[0]->ID, 'tix_order_total', true );

		$payload = array(
			'METHOD' => 'GetExpressCheckoutDetails',
			'TOKEN' => $paypal_token,
		);

		$request = $this->paypal_request( $payload );
		$checkout_details = wp_parse_args( wp_remote_retrieve_body( $request ) );

		if ( isset( $checkout_details['ACK'] ) && $checkout_details['ACK'] == 'Success' ) {

			if ( (float) $checkout_details['PAYMENTREQUEST_0_AMT'] != $expected_total ) {
				echo __( "Unexpected total!", 'camptix' );
				die();
			}

			/**
			 * @todo Check whether the tickets are still available at this stage.
			 */
			$payload = array(
				'METHOD' => 'DoExpressCheckoutPayment',
				'PAYMENTREQUEST_0_ALLOWEDPAYMENTMETHOD' => 'InstantPaymentOnly',
				'TOKEN' => $paypal_token,
				'PAYERID' => $payer_id,
				'PAYMENTREQUEST_0_AMT' => number_format( (float) $expected_total, 2, '.', '' ),
				'PAYMENTREQUEST_0_ITEMAMT' => number_format( (float) $expected_total, 2, '.', '' ),
				'PAYMENTREQUEST_0_CURRENCYCODE' => $this->options['currency'],
				'PAYMENTREQUEST_0_NOTIFYURL' => esc_url_raw( add_query_arg( 'tix_paypal_ipn', 1, trailingslashit( home_url() ) ) ),
			);
		}

		die();
	}

	function camptix_available_payment_methods( $payment_methods ) {
		$payment_methods['paypal'] = array(
			'name' => 'PayPal',
			'description' => 'PayPal Express Checkout',
		);
		return $payment_methods;
	}

	/**
	 * Called when the checkout process is initiated.
	 *
	 * $order = array(
	 *     array(
	 *         'id' => 123,
	 *         'name' => 'Item Name',
	 *         'description' => 'Item description',
	 *         'price' => 10.99,
	 *         'quantity' => 3,
	 *     ),
	 * );
	 */
	function camptix_payment_checkout_paypal( $order, $payment_token, $attendees ) {
		global $camptix;

		if ( ! $payment_token || empty( $payment_token ) )
			return false;

		$return_url = add_query_arg( array(
			'tix_action' => 'payment_return',
			'tix_payment_token' => $payment_token,
			'tix_payment_gateway' => 'paypal',
		), $this->get_tickets_url() );

		$cancel_url = add_query_arg( array(
			'tix_action' => 'payment_cancel',
			'tix_payment_token' => $payment_token,
			'tix_payment_gateway' => 'paypal',
		), $this->get_tickets_url() );

		$payload = array(
			'METHOD' => 'SetExpressCheckout',
			'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
			'PAYMENTREQUEST_0_ALLOWEDPAYMENTMETHOD' => 'InstantPaymentOnly',
			'RETURNURL' => $return_url,
			'CANCELURL' => $cancel_url,
			'ALLOWNOTE' => 0,
			'NOSHIPPING' => 1,
			'SOLUTIONTYPE' => 'Sole',
		);

		$i = 0; $total = 0;
		foreach ( $order as $item ) {
			$payload['L_PAYMENTREQUEST_0_NAME' . $i] = substr( 'Statement: ' . $item['name'], 0, 127 );
			$payload['L_PAYMENTREQUEST_0_DESC' . $i] = substr( $item['description'], 0, 127 );
			$payload['L_PAYMENTREQUEST_0_NUMBER' . $i] = $item['id'];
			$payload['L_PAYMENTREQUEST_0_AMT' . $i] = $item['price'];
			$payload['L_PAYMENTREQUEST_0_QTY' . $i] = $item['quantity'];
			$total += $item['price'] * $item['quantity'];
			$i++;
		}

		$payload['PAYMENTREQUEST_0_ITEMAMT'] = $total;
		$payload['PAYMENTREQUEST_0_AMT'] = $total;
		$payload['PAYMENTREQUEST_0_CURRENCYCODE'] = $this->options['currency'];

		$request = $this->request( $payload );
		$response = wp_parse_args( wp_remote_retrieve_body( $request ) );
		if ( isset( $response['ACK'], $response['TOKEN'] ) && 'Success' == $response['ACK'] ) {
			$token = $response['TOKEN'];

			foreach ( $attendees as $attendee ) {
				update_post_meta( $attendee->ID, 'tix_paypal_token', $token );
			}

			$url = $this->options['sandbox'] ? 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_express-checkout' : 'https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout';
			$url = add_query_arg( 'token', $token, $url );
			wp_redirect( esc_url_raw( $url ) );
			die();
		} else {
			echo 'error';
			print_r($response);
			die();
		}
	}

	/**
	 * Fire a POST request to PayPal.
	 */
	function request( $payload = array() ) {
		$url = $this->options['sandbox'] ? 'https://api-3t.sandbox.paypal.com/nvp' : 'https://api-3t.paypal.com/nvp';
		$payload = array_merge( array(
			'USER' => $this->options['api_username'],
			'PWD' => $this->options['api_password'],
			'SIGNATURE' => $this->options['api_signature'],
			'VERSION' => '88.0', // https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_PreviousAPIVersionsNVP
		), (array) $payload );

		return wp_remote_post( $url, array( 'body' => $payload, 'timeout' => 20 ) );
	}
}

// Register this class as a CampTix Addon.
camptix_register_addon( 'CampTix_Payment_Gateway_PayPal' );