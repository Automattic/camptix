<?php
/**
 * PayPal Express Checkout Payment Method for CampTix
 *
 * This class is a payment method for CampTix which implements
 * PayPal Express Checkout. You can use this as a base to create
 * your own redirect-based payment method for CampTix.
 *
 * @since CampTix 1.2
 */
class CampTix_Payment_Method_PayPal extends CampTix_Payment_Method {

	/**
	 * The following variables are required for every payment method.
	 */
	public $id = 'paypal';
	public $name = 'PayPal';
	public $description = 'PayPal Express Checkout';
	public $supported_currencies = array( 'AUD', 'CAD', 'EUR', 'GBP', 'JPY', 'USD', 'NZD', 'CHF', 'HKD', 'SGD', 'SEK', 
		'DKK', 'PLN', 'NOK', 'HUF', 'CZK', 'ILS', 'MXN', 'BRL', 'MYR', 'PHP', 'TWD', 'THB', 'TRY');

	/**
	 * We can have an array to store our options.
	 * Use $this->get_payment_options() to retrieve them.
	 */
	protected $options = array();

	/**
	 * Runs during camptix_init, loads our options and sets some actions.
	 * @see CampTix_Addon
	 */
	function camptix_init() {
		$this->options = array_merge( array(
			'api_predef' => '',
			'api_username' => '',
			'api_password' => '',
			'api_signature' => '',
			'sandbox' => true,
		), $this->get_payment_options() );

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
	}

	/**
	 * This runs during settings field registration in CampTix for the
	 * payment methods configuration screen. If your payment method has
	 * options, this method is the place to add them to. You can use the
	 * helper function to add typical settings fields. Don't forget to
	 * validate them all in validate_options.
	 */
	function payment_settings_fields() {

		// Allow pre-defined accounts if any are defined by plugins.
		if ( count( $this->get_predefined_accounts() ) > 0 )
			$this->add_settings_field_helper( 'api_predef', __( 'Predefined Account', 'camptix' ), array( $this, 'field_api_predef' ) );

		// Settings fields are not needed when a predefined account is chosen.
		// These settings fields should *never* expose predefined credentials.
		if ( ! $this->get_predefined_account() ) {
			$this->add_settings_field_helper( 'api_username', __( 'API Username', 'camptix' ), array( $this, 'field_text' ) );
			$this->add_settings_field_helper( 'api_password', __( 'API Password', 'camptix' ), array( $this, 'field_text' ) );
			$this->add_settings_field_helper( 'api_signature', __( 'API Signature', 'camptix' ), array( $this, 'field_text' ) );
			$this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode', 'camptix' ), array( $this, 'field_yesno' ),
				sprintf( __( "The PayPal Sandbox is a way to test payments without using real accounts and transactions. If you'd like to use Sandbox Mode, you'll need to create a %s account and obtain the API credentials for your sandbox user.", 'camptix' ), sprintf( '<a href="https://developer.paypal.com/">%s</a>', __( 'PayPal Developer', 'camptix' ) ) )
			);
		}
	}

	/**
	 * Predefined accounts field callback
	 *
	 * Renders a drop-down select with a list of predefined accounts
	 * to select from, as well as some js for better ux.
	 *
	 * @uses $this->get_predefined_accounts()
	 */
	function field_api_predef( $args ) {
		$accounts = $this->get_predefined_accounts();
		if ( empty( $accounts ) )
			return;

		?>
		<select id="camptix-predef-select" name="<?php echo esc_attr( $args['name'] ); ?>">
			<option value=""><?php _e( 'None', 'camptix' ); ?></option>
			<?php foreach ( $accounts as $key => $account ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['value'], $key ); ?>><?php echo esc_html( $account['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<!-- Let's disable the rest of the fields unless None is selected -->
		<script>
		jQuery(document).ready(function($){
			var select = $('#camptix-predef-select')[0];
			$(select).on('change', function(){
				$('[name^="camptix_payment_options_paypal"]').each(function(){
					// Don't disable myself.
					if (this == select)
						return;

					$(this).prop('disabled', select.value.length > 0);
					$(this).toggleClass('disabled', select.value.length > 0);
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Get an array of predefined PayPal accounts
	 *
	 * Runs an empty array through a filter, where one might specifiy a list of
	 * predefined PayPal credentials, through a plugin or something.
	 *
	 * @static $predefs
	 * @return array An array of predefined accounts (or an empty one)
	 */
	function get_predefined_accounts() {
		static $predefs = false;
		if ( false === $predefs )
			$predefs = apply_filters( 'camptix_paypal_predefined_accounts', array() );

		return $predefs;
	}

	/**
	 * Get a predefined account
	 *
	 * If the $key argument is false or not set, this function will look up the active
	 * predefined account, otherwise it'll look up the one under the given key. After a
	 * predefined account is set, PayPal credentials will be overwritten during API
	 * requests, but never saved/exposed. Useful with array_merge().
	 *
	 * @return array An array with credentials, or an empty array if key not found.
	 */
	function get_predefined_account( $key = false ) {
		$accounts = $this->get_predefined_accounts();

		if ( false === $key )
			$key = $this->options['api_predef'];

		if ( ! array_key_exists( $key, $accounts ) )
			return array();

		return $accounts[ $key ];
	}

	/**
	 * Validate the above option. Runs automatically upon options save and is
	 * given an $input array. Expects an $output array of filtered payment method options.
	 */
	function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['api_username'] ) )
			$output['api_username'] = $input['api_username'];

		if ( isset( $input['api_password'] ) )
			$output['api_password'] = $input['api_password'];

		if ( isset( $input['api_signature'] ) )
			$output['api_signature'] = $input['api_signature'];

		if ( isset( $input['sandbox'] ) )
			$output['sandbox'] = (bool) $input['sandbox'];

		if ( isset( $input['api_predef'] ) ) {

			// If a valid predefined account is set, erase the credentials array.
			// We do not store predefined credentials in options, only code.
			if ( $this->get_predefined_account( $input['api_predef'] ) ) {
				$output = array_merge( $output, array(
					'api_username' => '',
					'api_password' => '',
					'api_signature' => '',
					'sandbox' => false,
				) );
			} else {
				$input['api_predef'] = '';
			}

			$output['api_predef'] = $input['api_predef'];
		}

		return $output;
	}

	/**
	 * For PayPal we'll watch for some additional CampTix actions which may be
	 * fired from PayPal either with a redirect (cancel and return) or an IPN (notify).
	 */
	function template_redirect() {

		// Backwards compatibility with CampTix 1.1
		if ( isset( $_GET['tix_paypal_ipn'] ) && $_GET['tix_paypal_ipn'] == 1 )
			$this->payment_notify_back_compat();

		// New version requests.
		if ( ! isset( $_REQUEST['tix_payment_method'] ) || 'paypal' != $_REQUEST['tix_payment_method'] )
			return;

		if ( 'payment_cancel' == get_query_var( 'tix_action' ) )
			$this->payment_cancel();

		if ( 'payment_return' == get_query_var( 'tix_action' ) )
			$this->payment_return();

		if ( 'payment_notify' == get_query_var( 'tix_action' ) )
			$this->payment_notify();
	}

	/**
	 * Runs when PayPal sends an IPN signal with a payment token and a
	 * payload in $_POST. Verify the payload and use $this->payment_result
	 * to signal a transaction result back to CampTix.
	 */
	function payment_notify() {
		global $camptix;

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		// Verify the IPN came from PayPal.
		$payload = stripslashes_deep( $_POST );
		$response = $this->verify_ipn( $payload );
		if ( wp_remote_retrieve_response_code( $response ) != '200' || wp_remote_retrieve_body( $response ) != 'VERIFIED' ) {
			$this->log( 'Could not verify PayPal IPN.', 0, null );
			return;
		}

		// Grab the txn id (or the parent id in case of refunds, cancels, etc)
		$txn_id = isset( $payload['txn_id'] ) && ! empty( $payload['txn_id'] ) ? $payload['txn_id'] : 'None';
		if ( isset( $payload['parent_txn_id'] ) && ! empty( $payload['parent_txn_id'] ) )
			$txn_id = $payload['parent_txn_id'];

		// Make sure we have a status
		if ( empty( $payload['payment_status'] ) ) {
			$this->log( sprintf( 'Received IPN with no payment status %s', $txn_id ), 0, $payload );
			return;
		}

		// Fetch latest transaction details to avoid race conditions.
		$txn_details_payload = array(
			'METHOD' => 'GetTransactionDetails',
			'TRANSACTIONID' => $txn_id,
		);
		$txn_details = wp_parse_args( wp_remote_retrieve_body( $this->request( $txn_details_payload ) ) );
		if ( ! isset( $txn_details['ACK'] ) || $txn_details['ACK'] != 'Success' ) {
			$this->log( sprintf( 'Fetching transaction after IPN failed %s.', $txn_id, 0, $txn_details ) );
			return;
		}

		$this->log( sprintf( 'Payment details for %s via IPN', $txn_id ), null, $txn_details );
		$payment_status = $txn_details['PAYMENTSTATUS'];

		$payment_data = array(
			'transaction_id' => $txn_id,
			'transaction_details' => array(
				// @todo maybe add more info about the payment
				'raw' => $txn_details,
			),
		);

		/**
		 * Returns the payment result back to CampTix. Don't be afraid to return a
		 * payment result twice. In fact, it's typical for payment methods with IPN support.
		 */
		return $this->payment_result( $payment_token, $this->get_status_from_string( $payment_status ), $payment_data );
	}

	/**
	 * Backwards compatible PayPal IPN response.
	 *
	 * In CampTix 1.1 and below, CampTix has already sent requests to PayPal with
	 * the old-style notify URL. This method, runs during template_redirect and
	 * ensures that IPNs on old attendees still work.
	 */
	function payment_notify_back_compat() {
		if ( ! isset( $_REQUEST['tix_paypal_ipn'] ) )
			return;

		$payload = stripslashes_deep( $_POST );
		$transaction_id = ( isset( $payload['txn_id'] ) ) ? $payload['txn_id'] : null;
		if ( isset( $payload['parent_txn_id'] ) && ! empty( $payload['parent_txn_id'] ) )
			$transaction_id = $payload['parent_txn_id'];

		if ( empty( $transaction_id ) ) {
			$this->log( __( 'Received old-style IPN request with an empty transaction id.', 'camptix' ), null, $payload );
			return;
		}

		/**
		 * Find the attendees by transaction id.
		 */
		$attendees = get_posts( array(
			'posts_per_page' => 1,
			'post_type' => 'tix_attendee',
			'post_status' => 'any',
			'meta_query' => array(
				array(
					'key' => 'tix_transaction_id',
					'value' => $transaction_id,
				),
			),
		) );

		if ( ! $attendees ) {
			$this->log( __( 'Received old-style IPN request. Could not match to attendee by transaction id.', 'camptix' ), null, $payload );
			return;
		}

		$payment_token = get_post_meta( $attendees[0]->ID, 'tix_payment_token', true );

		if ( ! $payment_token ) {
			$this->log( __( 'Received old-style IPN request. Could find a payment token by transaction id.', 'camptix' ), null, $payload );
			return;
		}

		// Everything else is okay, so let's run the new notify scenario.
		$_REQUEST['tix_payment_token'] = $payment_token;
		return $this->payment_notify();
	}

	/**
	 * Helps convert payment statuses from PayPal responses, to CampTix payment statuses.
	 */
	function get_status_from_string( $payment_status ) {
		$statuses = array(
			'Completed' => CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
			'Pending' => CampTix_Plugin::PAYMENT_STATUS_PENDING,
			'Cancelled' => CampTix_Plugin::PAYMENT_STATUS_CANCELLED,
			'Failed' => CampTix_Plugin::PAYMENT_STATUS_FAILED,
			'Denied' => CampTix_Plugin::PAYMENT_STATUS_FAILED,
			'Refunded' => CampTix_Plugin::PAYMENT_STATUS_REFUNDED,
			'Reversed' => CampTix_Plugin::PAYMENT_STATUS_REFUNDED,
		);

		// Return pending for unknows statuses.
		if ( ! isset( $statuses[ $payment_status ] ) )
			$payment_status = 'Pending';

		return $statuses[ $payment_status ];
	}

	/**
	 * Runs when the user cancels their payment during checkout at PayPal.
	 * his will simply tell CampTix to put the created attendee drafts into to Cancelled state.
	 */
	function payment_cancel() {
		global $camptix;

		$this->log( sprintf( 'Running payment_cancel. Request data attached.' ), null, $_REQUEST );
		$this->log( sprintf( 'Running payment_cancel. Server data attached.' ), null, $_SERVER );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		$paypal_token = ( isset( $_REQUEST['token'] ) ) ? trim( $_REQUEST['token'] ) : '';

		if ( ! $payment_token || ! $paypal_token )
			die( 'empty token' );

		/**
		 * @todo maybe check tix_paypal_token for security.
		 */

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
			die( 'attendees not found' );

		/**
		 * It might be related to browsers, or it might be not, but PayPal has this thing
		 * where it would complete a payment and then redirect the user to the payment_cancel
		 * page. Here, before actually cancelling an attendee's ticket, we look up their
		 * transaction ID, and if they have one, we check its status with PayPal.
		 */

		// Look for an associated transaction ID, in case this purchase has already been made.
		$transaction_id = get_post_meta( $attendees[0]->ID, 'tix_transaction_id', true );
		$access_token = get_post_meta( $attendees[0]->ID, 'tix_access_token', true );

		if ( ! empty( $transaction_id ) ) {
			$request = $this->request( array(
				'METHOD' => 'GetTransactionDetails',
				'TRANSACTIONID' => $transaction_id,
			) );

			$transaction_details = wp_parse_args( wp_remote_retrieve_body( $request ) );
			if ( isset( $transaction_details['ACK'] ) && $transaction_details['ACK'] == 'Success' ) {
				$status = $this->get_status_from_string( $transaction_details['PAYMENTSTATUS'] );
				if ( in_array( $status, array(
					CampTix_Plugin::PAYMENT_STATUS_PENDING,
					CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
				) ) ) {

					// False alarm. The payment has indeed been made and no need to cancel.
					$this->log( 'False alarm on payment_cancel. This transaction is valid.', 0, $transaction_details );
					wp_safe_redirect( $camptix->get_access_tickets_link( $access_token ) );
					die();
				}
			}
		}

		// Set the associated attendees to cancelled.
		return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED );
	}

	/**
	 * This runs when PayPal redirects the user back after the user has clicked
	 * Pay Now on PayPal. At this point, the user hasn't been charged yet, so we
	 * verify their order once more and fire DoExpressCheckoutPayment to produce
	 * the charge. This method ends with a call to payment_result back to CampTix
	 * which will redirect the user to their tickets page, send receipts, etc.
	 */
	function payment_return() {
		global $camptix;

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		$paypal_token = ( isset( $_REQUEST['token'] ) ) ? trim( $_REQUEST['token'] ) : '';
		$payer_id = ( isset( $_REQUEST['PayerID'] ) ) ? trim( $_REQUEST['PayerID'] ) : '';

		if ( ! $payment_token || ! $paypal_token || ! $payer_id )
			die( 'empty token' );

		$order = $this->get_order( $payment_token );

		if ( ! $order )
			die( 'could not find order' );

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

			$notify_url = add_query_arg( array(
				'tix_action' => 'payment_notify',
				'tix_payment_token' => $payment_token,
				'tix_payment_method' => 'paypal',
			), $this->get_tickets_url() );

			$payload = array(
				'METHOD' => 'DoExpressCheckoutPayment',
				'PAYMENTREQUEST_0_ALLOWEDPAYMENTMETHOD' => 'InstantPaymentOnly', // @todo allow echecks with an option
				'TOKEN' => $paypal_token,
				'PAYERID' => $payer_id,
				'PAYMENTREQUEST_0_NOTIFYURL' => esc_url_raw( $notify_url ),
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

				$this->log( sprintf( 'Payment details for %s', $txn_id ), null, $txn );

				/**
				 * Note that when returning a successful payment, CampTix will be
				 * expecting the transaction_id and transaction_details array keys.
				 */
				$payment_data = array(
					'transaction_id' => $txn_id,
					'transaction_details' => array(
						// @todo maybe add more info about the payment
						'raw' => $txn,
					),
				);

				return $this->payment_result( $payment_token, $this->get_status_from_string( $payment_status ), $payment_data );

			} else {
				$payment_data = array(
					'error' => 'Error during DoExpressCheckoutPayment',
					'data' => $request,
				);
				$this->log( 'Error during DoExpressCheckoutPayment.', null, $request );
				return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
			}
		} else {
			$payment_data = array(
				'error' => 'Error during GetExpressCheckoutDetails',
				'data' => $request,
			);
			$this->log( 'Error during GetExpressCheckoutDetails.', null, $request );
			return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
		}

		die();
	}

	/**
	 * This method is the fire starter. It's called when the user initiates
	 * a checkout process with the selected payment method. In PayPal's case,
	 * if everything's okay, we redirect to the PayPal Express Checkout page with
	 * the details of our transaction. If something's wrong, we return a failed
	 * result back to CampTix immediately.
	 */
	function payment_checkout( $payment_token ) {
		global $camptix;

		if ( ! $payment_token || empty( $payment_token ) )
			return false;

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) )
			die( __( 'The selected currency is not supported by this payment method.', 'camptix' ) );

		$return_url = add_query_arg( array(
			'tix_action' => 'payment_return',
			'tix_payment_token' => $payment_token,
			'tix_payment_method' => 'paypal',
		), $this->get_tickets_url() );

		$cancel_url = add_query_arg( array(
			'tix_action' => 'payment_cancel',
			'tix_payment_token' => $payment_token,
			'tix_payment_method' => 'paypal',
		), $this->get_tickets_url() );

		$payload = array(
			'METHOD' => 'SetExpressCheckout',
			'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
			'PAYMENTREQUEST_0_ALLOWEDPAYMENTMETHOD' => 'InstantPaymentOnly', // @todo allow echecks with an option
			'RETURNURL' => $return_url,
			'CANCELURL' => $cancel_url,
			'ALLOWNOTE' => 0,
			'NOSHIPPING' => 1,
			'SOLUTIONTYPE' => 'Sole',
		);

		// Replace creds from a predefined account if any.
		$options = array_merge( $this->options, $this->get_predefined_account( $this->options['api_predef'] ) );

		$order = $this->get_order( $payment_token );
		$this->fill_payload_with_order( $payload, $order );

		$request = $this->request( $payload );
		$response = wp_parse_args( wp_remote_retrieve_body( $request ) );
		if ( isset( $response['ACK'], $response['TOKEN'] ) && 'Success' == $response['ACK'] ) {
			$token = $response['TOKEN'];
			$url = $options['sandbox'] ? 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_express-checkout' : 'https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout';
			$url = add_query_arg( 'token', $token, $url );
			wp_redirect( esc_url_raw( $url ) );
		} else {
			$this->log( 'Error during SetExpressCheckout.', null, $response );
			$error_code = isset( $response['L_ERRORCODE0'] ) ? $response['L_ERRORCODE0'] : 0;
			$error_message = isset( $response['L_LONGMESSAGE0'] ) ? $response['L_LONGMESSAGE0'] : '';

			if ( ! empty( $error_message ) )
				$camptix->error( sprintf( __( 'PayPal error: %s (%d)', 'camptix' ), $error_message, $error_code ) );

			return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array(
				'error_code' => $error_code,
				'raw' => $request,
			) );
		}
	}

	/**
	 * Helper function for PayPal which fills a $payload array with items from the $order array.
	 */
	function fill_payload_with_order( &$payload, $order ) {
		$event_name = 'Event';
		if ( isset( $this->camptix_options['event_name'] ) )
			$event_name = $this->camptix_options['event_name'];

		$i = 0;
		foreach ( $order['items'] as $item ) {
			$payload['L_PAYMENTREQUEST_0_NAME' . $i] = substr( strip_tags( $event_name . ': ' . $item['name'] ), 0, 127 );
			$payload['L_PAYMENTREQUEST_0_DESC' . $i] = substr( strip_tags( $item['description'] ), 0, 127 );
			$payload['L_PAYMENTREQUEST_0_NUMBER' . $i] = $item['id'];
			$payload['L_PAYMENTREQUEST_0_AMT' . $i] = $item['price'];
			$payload['L_PAYMENTREQUEST_0_QTY' . $i] = $item['quantity'];
			$i++;
		}

		/** @todo add coupon/reservation as a note. **/

		$payload['PAYMENTREQUEST_0_ITEMAMT'] = $order['total'];
		$payload['PAYMENTREQUEST_0_AMT'] = $order['total'];
		$payload['PAYMENTREQUEST_0_CURRENCYCODE'] = $this->camptix_options['currency'];
		return $payload;
	}

	/**
	 * Use this method to fire a POST request to the PayPal API.
	 */
	function request( $payload = array() ) {
		// Replace creds from a predefined account if any.
		$options = array_merge( $this->options, $this->get_predefined_account( $this->options['api_predef'] ) );

		$url = $options['sandbox'] ? 'https://api-3t.sandbox.paypal.com/nvp' : 'https://api-3t.paypal.com/nvp';

		$payload = array_merge( array(
			'USER' => $options['api_username'],
			'PWD' => $options['api_password'],
			'SIGNATURE' => $options['api_signature'],
			'VERSION' => '88.0', // https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_PreviousAPIVersionsNVP
		), (array) $payload );

		return wp_remote_post( $url, array( 'body' => $payload, 'timeout' => apply_filters( 'camptix_paypal_timeout', 20 ) ) );
	}

	/**
	 * Use this method to validate an incoming IPN request.
	 */
	function verify_ipn( $payload = array() ) {
		// Replace creds from a predefined account if any.
		$options = array_merge( $this->options, $this->get_predefined_account( $this->options['api_predef'] ) );

		$url = $options['sandbox'] ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr';
		$payload = 'cmd=_notify-validate&' . http_build_query( $payload );
		return wp_remote_post( $url, array( 'body' => $payload, 'timeout' => apply_filters( 'camptix_paypal_timeout', 20 ) ) );
	}
}

/**
 * The last stage is to register your payment method with CampTix.
 * Since the CampTix_Payment_Method class extends from CampTix_Addon,
 * we use the camptix_register_addon function to register it.
 */
camptix_register_addon( 'CampTix_Payment_Method_PayPal' );
