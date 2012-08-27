<?php
/**
 * PayPal Payment Gateway for CampTix
 */

class CampTix_Payment_Gateway extends CampTix_Addon {

	public $id = false;
	public $name = false;
	public $description = false;

	function __construct() {
		parent::__construct();

		add_filter( 'camptix_available_payment_methods', array( $this, '_camptix_available_payment_methods' ) );
		add_action( 'camptix_payment_checkout', array( $this, '_camptix_payment_checkout' ), 10, 2 );
	}

	function _camptix_payment_checkout( $payment_method, $payment_token ) {
		if ( $this->id == $payment_method )
			$this->payment_checkout( $payment_token );
	}

	function payment_checkout( $payment_token ) {
		die( __FUNCTION__ . ' not implemented' );
	}

	function _camptix_available_payment_methods( $payment_methods ) {
		if ( $this->id && $this->name && $this->description )
			$payment_methods[ $this->id ] = array(
				'name' => $this->name,
				'description' => $this->description,
			);

		return $payment_methods;
	}

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

	function get_order( $payment_token = false ) {
		if ( ! $payment_token )
			return array();

		$attendees = get_posts( array(
			'posts_per_page' => 1,
			'post_type' => 'tix_attendee',
			'post_status' => 'any',
			'meta_query' => array(
				array(
					'key' => 'tix_payment_token',
					'compare' => '=',
					'value' => $payment_token,
					'type' => 'CHAR',
				),
			),
		) );

		if ( ! $attendees )
			return array();

		return (array) get_post_meta( $attendees[0]->ID, 'tix_order', true );
	}
}

class CampTix_Payment_Gateway_Blackhole extends CampTix_Payment_Gateway {

	public $id = 'blackhole';
	public $name = 'Blackhole';
	public $description = 'Will always result in a successful payment.';

	function payment_checkout( $payment_token ) {
		global $camptix;

		// Process $order and do something.
		$order = $this->get_order( $payment_token );
		do_action( 'camptix_before_payment', $payment_token );
		$this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_COMPLETED );
		die();
	}
}

class CampTix_Payment_Gateway_PayPal extends CampTix_Payment_Gateway {

	public $id = 'paypal';
	public $name = 'PayPal';
	public $description = 'PayPal Express Checkout';

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

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		$paypal_token = ( isset( $_REQUEST['token'] ) ) ? trim( $_REQUEST['token'] ) : '';

		if ( ! $payment_token || ! $paypal_token )
			die( 'empty token' );

		/**
		 * @todo maybe check tix_paypal_token for security.
		 */

		$this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_CANCELLED );
		die();
	}

	function payment_return() {
		global $camptix;

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		$paypal_token = ( isset( $_REQUEST['token'] ) ) ? trim( $_REQUEST['token'] ) : '';
		$payer_id = ( isset( $_REQUEST['PayerID'] ) ) ? trim( $_REQUEST['PayerID'] ) : '';

		if ( ! $payment_token || ! $paypal_token || ! $payer_id )
			die( 'empty token' );

		$order = $this->get_order( $payment_token );

		/**
		 * @todo maybe check tix_paypal_token for security.
		 */

		$payload = array(
			'METHOD' => 'GetExpressCheckoutDetails',
			'TOKEN' => $paypal_token,
		);

		$request = $this->request( $payload );
		$checkout_details = wp_parse_args( wp_remote_retrieve_body( $request ) );

		if ( isset( $checkout_details['ACK'] ) && $checkout_details['ACK'] == 'Success' ) {

			$payload = array(
				'METHOD' => 'DoExpressCheckoutPayment',
				'PAYMENTREQUEST_0_ALLOWEDPAYMENTMETHOD' => 'InstantPaymentOnly',
				'TOKEN' => $paypal_token,
				'PAYERID' => $payer_id,
				'PAYMENTREQUEST_0_NOTIFYURL' => esc_url_raw( add_query_arg( 'tix_paypal_ipn', 1, trailingslashit( home_url() ) ) ),
			);

			$this->fill_payload_with_order( $payload, $order );

			if ( (float) $checkout_details['PAYMENTREQUEST_0_AMT'] != $order['total'] ) {
				echo __( "Unexpected total!", 'camptix' );
				die();
			}

			// One final check before charging the user.
			if ( ! $camptix->verify_order( $order ) ) {
				die( 'Something went wrong, order is no longer available.' );
			}

			// Get money money, get money money money!
			$request = $this->request( $payload );
			$txn = wp_parse_args( wp_remote_retrieve_body( $request ) );

			if ( isset( $txn['ACK'], $txn['PAYMENTINFO_0_PAYMENTSTATUS'] ) && $txn['ACK'] == 'Success' ) {
				$txn_id = $txn['PAYMENTINFO_0_TRANSACTIONID'];
				$payment_status = $txn['PAYMENTINFO_0_PAYMENTSTATUS'];

				$this->log( sprintf( __( 'Payment details for %s', 'camptix'), $txn_id ), null, $txn, 'payment' );

				if ( $payment_status == 'Completed' ) {
					$this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_COMPLETED );
				} else {
					$this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_PENDING );
				}
			} else {
				$this->log( __( 'Payment cancelled due to an HTTP error during DoExpressCheckoutPayment.', 'camptix' ), null, $request, 'payment' );
				$this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_FAILED );
			}
		} else {
			$this->log( __( 'Payment cancelled due to an HTTP error during GetExpressCheckoutDetails.', 'camptix' ), null, $request, 'payment' );
			$this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_FAILED );
		}

		die();
	}

	/**
	 * Called when the checkout process is initiated.
	 *
	 * $order = array(
	 *     'items' => array(
	 *         'id' => 123,
	 *         'name' => 'Item Name',
	 *         'description' => 'Item description',
	 *         'price' => 10.99,
	 *         'quantity' => 3,
	 *     ),
	 *     'coupon' => 'xyz',
	 *     'total' => 123.45,
	 * );
	 */
	function payment_checkout( $payment_token ) {
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

		$order = $this->get_order( $payment_token );
		$this->fill_payload_with_order( $payload, $order );

		$request = $this->request( $payload );
		$response = wp_parse_args( wp_remote_retrieve_body( $request ) );
		if ( isset( $response['ACK'], $response['TOKEN'] ) && 'Success' == $response['ACK'] ) {
			$token = $response['TOKEN'];

			/*foreach ( $attendees as $attendee ) {
				update_post_meta( $attendee->ID, 'tix_paypal_token', $token );
			}*/

			$url = $this->options['sandbox'] ? 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_express-checkout' : 'https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout';
			$url = add_query_arg( 'token', $token, $url );
			wp_redirect( esc_url_raw( $url ) );
		} else {
			echo 'error';
			print_r($response);
		}

		die();
	}

	function fill_payload_with_order( &$payload, $order ) {
		$i = 0;
		foreach ( $order['items'] as $item ) {
			$payload['L_PAYMENTREQUEST_0_NAME' . $i] = substr( 'Statement: ' . $item['name'], 0, 127 );
			$payload['L_PAYMENTREQUEST_0_DESC' . $i] = substr( $item['description'], 0, 127 );
			$payload['L_PAYMENTREQUEST_0_NUMBER' . $i] = $item['id'];
			$payload['L_PAYMENTREQUEST_0_AMT' . $i] = $item['price'];
			$payload['L_PAYMENTREQUEST_0_QTY' . $i] = $item['quantity'];
			$i++;
		}

		$payload['PAYMENTREQUEST_0_ITEMAMT'] = $order['total'];
		$payload['PAYMENTREQUEST_0_AMT'] = $order['total'];
		$payload['PAYMENTREQUEST_0_CURRENCYCODE'] = $this->options['currency'];
		return $payload;
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
// camptix_register_addon( 'CampTix_Payment_Gateway_Blackhole' );