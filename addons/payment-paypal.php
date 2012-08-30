<?php
/**
 * PayPal Payment Gateway for CampTix
 */

class CampTix_Payment_Gateway extends CampTix_Addon {

	public $id = false;
	public $name = false;
	public $description = false;

	public $supported_currencies = false;

	function __construct() {
		global $camptix;

		parent::__construct();

		add_filter( 'camptix_available_payment_methods', array( $this, '_camptix_available_payment_methods' ) );
		add_filter( 'camptix_validate_options', array( $this, '_camptix_validate_options' ) );
		add_filter( 'camptix_get_payment_method_by_id', array( $this, '_camptix_get_payment_method_by_id' ), 10, 2 );

		if ( ! $this->id )
			die( 'id not specified in a payment gateway' );

		if ( ! $this->name )
			die( 'name not specified in a payment gateway' );

		if ( ! $this->description )
			die( 'description not specified in a payment gateway' );

		if ( ! is_array( $this->supported_currencies ) || count( $this->supported_currencies ) < 1 )
			die( 'supported currencies not specified in a payment gateway' );

		$this->camptix_options = $camptix->get_options();
	}

	function supports_currency( $currency ) {
		return ( in_array( $currency, $this->supported_currencies ) );
	}

	function _camptix_get_payment_method_by_id( $payment_method, $id ) {
		if ( $this->id == $id )
			$payment_method = $this;

		return $payment_method;
	}

	function _camptix_settings_section_callback() {
		echo '<p>' . $this->description . '</p>';
		printf( '<p>' . __( 'Supported currencies: <code>%s</code>.', 'camptix' ) . '</p>', implode( '</code>, <code>', $this->supported_currencies ) );
	}

	function _camptix_settings_enabled_callback( $args = array() ) {
		if ( in_array( $this->camptix_options['currency'], $this->supported_currencies ) )
			return $this->field_yesno( $args );

		?>
		Disabled
		<p class="description"><?php printf( __( '%s is not supported by this payment gateway.', 'camptix' ), '<code>' . $this->camptix_options['currency'] . '</code>' ); ?></p>
		<?php
	}

	function _camptix_validate_options( $camptix_options ) {
		$post_key = "camptix_payment_options_{$this->id}";
		$option_key = "payment_options_{$this->id}";

		if ( ! isset( $_POST[ $post_key ] ) )
			return $camptix_options;

		$input = $_POST[ $post_key ];
		$output = $this->validate_options( $input );
		$camptix_options[ $option_key ] = $output;

		return $camptix_options;
	}

	function validate_options( $input ) {
		return array();
	}

	function payment_checkout( $payment_token ) {
		die( __FUNCTION__ . ' not implemented' );
	}

	function payment_settings_fields() {
		return;
	}

	function _camptix_available_payment_methods( $payment_methods ) {
		if ( $this->id && $this->name && $this->description )
			$payment_methods[ $this->id ] = array(
				'name' => $this->name,
				'description' => $this->description,
			);

		return $payment_methods;
	}

	function payment_result( $payment_token, $result, $data = array() ) {
		global $camptix;
		return $camptix->payment_result( $payment_token, $result, $data );
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

	function log( $message, $post_id = 0, $data = null, $module = 'payment' ) {
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

	/**
	 * A text input for the Settings API, name and value attributes
	 * should be specified in $args. Same goes for the rest.
	 */
	function field_text( $args ) {
		?>
		<input type="text" name="<?php echo esc_attr( $args['name'] ); ?>" value="<?php echo esc_attr( $args['value'] ); ?>" class="regular-text" />
		<?php
	}

	/**
	 * A checkbox field for the Settings API.
	 */
	function field_checkbox( $args ) {
		?>
		<input type="checkbox" name="<?php echo esc_attr( $args['name'] ); ?>" value="1" <?php checked( $args['value'] ); ?> />
		<?php
	}

	/**
	 * A yes-no field for the Settings API.
	 */
	function field_yesno( $args ) {
		?>
		<label class="tix-yes-no description"><input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="1" <?php checked( $args['value'], true ); ?>> <?php _e( 'Yes', 'camptix' ); ?></label>
		<label class="tix-yes-no description"><input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="0" <?php checked( $args['value'], false ); ?>> <?php _e( 'No', 'camptix' ); ?></label>

		<?php if ( isset( $args['description'] ) ) : ?>
		<p class="description"><?php echo $args['description']; ?></p>
		<?php endif; ?>
		<?php
	}

	function settings_field_name_attr( $name ) {
		return esc_attr( "camptix_payment_options_{$this->id}[{$name}]" );
	}

	function add_settings_field_helper( $option_name, $title, $callback, $description = '' ) {
		return add_settings_field( 'camptix_payment_' . $this->id . '_' . $option_name, $title, $callback, 'camptix_options', 'payment_' . $this->id, array(
			'name' => $this->settings_field_name_attr( $option_name ),
			'value' => $this->options[ $option_name ],
			'description' => $description,
		) );
	}

	function get_payment_options() {
		$payment_options = array();
		$option_key = "payment_options_{$this->id}";

		if ( isset( $this->camptix_options[ $option_key ] ) )
			$payment_options = (array) $this->camptix_options[ $option_key ];

		return $payment_options;
	}
}

class CampTix_Payment_Gateway_PayPal extends CampTix_Payment_Gateway {

	public $id = 'paypal';
	public $name = 'PayPal';
	public $description = 'PayPal Express Checkout';
	public $supported_currencies = array( 'USD', 'EUR', 'CAD', 'NOK', 'PLN', 'JPY' );

	protected $options = array();
	protected $error_flags = array();

	/**
	 * Runs during camptix_init, @see CampTix_Addon
	 */
	function camptix_init() {
		global $camptix;

		$this->options = array_merge( array(
			'api_username' => 'seller_1336582765_biz_api1.automattic.com',
			'api_password' => '1336582791',
			'api_signature' => 'AAIC4ZQTUrzRU3RisBfEDkKUjdmwAnhS47jgmW1pnLf4G517HvqUlxkD',
			'sandbox' => true,
		), $this->get_payment_options() );

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
	}

	/**
	 * Add settings to the payment options
	 */
	function payment_settings_fields() {
		$this->add_settings_field_helper( 'api_username', __( 'API Username', 'camptix' ), array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'api_password', __( 'API Password', 'camptix' ), array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'api_signature', __( 'API Signature', 'camptix' ), array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode', 'camptix' ), array( $this, 'field_yesno' ),
			sprintf( __( "The PayPal Sandbox is a way to test payments without using real accounts and transactions. If you'd like to use Sandbox Mode, you'll need to create a %s account and obtain the API credentials for your sandbox user.", 'camptix' ), sprintf( '<a href="https://developer.paypal.com/">%s</a>', __( 'PayPal Developer', 'camptix' ) ) )
		);
	}

	/**
	 * Validate the above
	 */
	function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['api_username'] ) )
			$output['api_username'] = $input['api_username'];

		if ( isset( $input['api_password'] ) )
			$output['api_password'] = $input['api_password'];

		if ( isset( $input['api_signature'] ) )
			$output['api_signature'] = $input['api_signature'];

		return $output;
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

		return $this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_CANCELLED );
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

				$this->log( sprintf( __( 'Payment details for %s', 'camptix'), $txn_id ), null, $txn );

				if ( $payment_status == 'Completed' ) {
					$this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_COMPLETED, array(
						'transaction_id' => $txn_id,
						'transaction_details' => array(
							// @todo maybe add more info about the payment
							'raw' => $txn,
						),
					) );
				} else {
					$this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_PENDING );
				}
			} else {
				$this->log( __( 'Error during DoExpressCheckoutPayment.', 'camptix' ), null, $request );
				$this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_FAILED );
			}
		} else {
			$this->log( __( 'Error during GetExpressCheckoutDetails.', 'camptix' ), null, $request );
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

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) )
			die( __( 'The selected currency is not supported by this payment gateway.', 'camptix' ) );

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
			$this->log( 'Error during SetExpressCheckout.', null, $response );
			$error_code = isset( $response['L_ERRORCODE0'] ) ? $response['L_ERRORCODE0'] : 0;
			return $this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_FAILED, array(
				'error_code' => $error_code,
			) );
		}
	}

	function fill_payload_with_order( &$payload, $order ) {
		$event_name = 'Event';
		if ( isset( $this->camptix_options['event_name'] ) )
			$event_name = $this->camptix_options['event_name'];

		$i = 0;
		foreach ( $order['items'] as $item ) {
			$payload['L_PAYMENTREQUEST_0_NAME' . $i] = substr( $event_name . ': ' . $item['name'], 0, 127 );
			$payload['L_PAYMENTREQUEST_0_DESC' . $i] = substr( $item['description'], 0, 127 );
			$payload['L_PAYMENTREQUEST_0_NUMBER' . $i] = $item['id'];
			$payload['L_PAYMENTREQUEST_0_AMT' . $i] = $item['price'];
			$payload['L_PAYMENTREQUEST_0_QTY' . $i] = $item['quantity'];
			$i++;
		}

		$payload['PAYMENTREQUEST_0_ITEMAMT'] = $order['total'];
		$payload['PAYMENTREQUEST_0_AMT'] = $order['total'];
		$payload['PAYMENTREQUEST_0_CURRENCYCODE'] = $this->camptix_options['currency'];
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

class CampTix_Payment_Gateway_Blackhole extends CampTix_Payment_Gateway {

	public $id = 'blackhole';
	public $name = 'Blackhole';
	public $description = 'Will always result in a successful payment.';
	public $supported_currencies = array( 'USD' );

	protected $options;

	function camptix_init() {
		$this->options = array_merge( array(
			'always_succeed' => true,
		), $this->get_payment_options() );
	}

	/**
	 * Add settings to the payment options
	 */
	function payment_settings_fields() {
		$this->add_settings_field_helper( 'always_succeed', __( 'Always Succeed', 'camptix' ), array( $this, 'field_yesno' ) );
	}

	/**
	 * Validate the above
	 */
	function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['always_succeed'] ) )
			$output['always_succeed'] = (bool) $input['always_succeed'];

		return $output;
	}

	function payment_checkout( $payment_token ) {
		global $camptix;

		// Process $order and do something.
		$order = $this->get_order( $payment_token );
		do_action( 'camptix_before_payment', $payment_token );

		if ( $this->options['always_succeed'] )
			return $this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_COMPLETED );
		else
			return $this->payment_result( $payment_token, $camptix::PAYMENT_STATUS_FAILED );
	}
}

// Register this class as a CampTix Addon.
camptix_register_addon( 'CampTix_Payment_Gateway_PayPal' );
camptix_register_addon( 'CampTix_Payment_Gateway_Blackhole' );