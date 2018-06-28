<?php

class CampTix_Payment_Method_Stripe extends CampTix_Payment_Method {
	public $id          = 'stripe';
	public $name        = 'Stripe';
	public $description = 'Stripe';

	/**
	 * See https://support.stripe.com/questions/which-currencies-does-stripe-support.
	 *
	 * 1.7
	 * Removing SVC, because it is no longer in circulation and is rarely used. (https://www.xe.com/currency/svc-salvadoran-colon)
	 */
	public $supported_currencies = array(
		'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BMD',
		'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BZD', 'CAD', 'CDF', 'CHF', 'CNY', 'COP', 'CRC', 'CVE', 'CZK', 'DKK',
		'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL',
		'HRK', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'KES', 'KGS', 'KHR', 'KYD', 'KZT', 'LAK', 'LBP',
		'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MKD', 'MMK', 'MNT', 'MOP', 'MRO', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR',
		'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'QAR', 'RON',
		'RSD', 'RUB', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLL', 'SOS', 'SRD', 'STD', 'SZL', 'THB',
		'TJS', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'WST', 'XCD', 'YER', 'ZAR',
		'ZMW',
		// Zero decimal currencies (https://stripe.com/docs/currencies#zero-decimal)
		'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF',
		'XPF',
	);

	public $supported_features = array(
		'refund-single' => true,
		'refund-all'    => true,
	);

	/**
	 * We can have an array to store our options.
	 * Use `$this->get_payment_options()` to retrieve them.
	 */
	protected $options = array();

	/**
	 * Runs during camptix_init, loads our options and sets some actions.
	 *
	 * @see CampTix_Addon
	 */
	public function camptix_init() {
		$this->options = array_merge( array(
			'api_secret_key' => '',
			'api_public_key' => '',
			'api_predef'     => '',
		), $this->get_payment_options() );

		$credentials = $this->get_api_credentials();

		add_filter( 'camptix_register_registration_info_header', array( $this, 'camptix_register_registration_info_header' ) );

		add_filter( 'camptix_payment_result', array( $this, 'camptix_payment_result' ), 10, 3 );
	}

	/**
	 * Get the credentials for the API account.
	 *
	 * If a standard account is setup, this will just use the value that's
	 * already in $this->options. If a predefined account is setup, though, it
	 * will use those instead.
	 *
	 * SECURITY WARNING: This must be called on the fly, and saved in a local
	 * variable instead of $this->options. Storing the predef credentials in
	 * $this->options would result in them being exposed to the user if they
	 * switched from a predefined account to a standard one. That happens because
	 * validate_options() will not strip the predefined credentials when options
	 * are saved in this scenario, so they would be saved to the database.
	 *
	 * validate_options() could be updated to protect against that, but that's
	 * more susceptible to human error. It's simpler, and therefore safer, to
	 * just never let predefined credentials into $this->options to begin with.
	 *
	 * @return array
	 */
	public function get_api_credentials() {
		$options = array_merge( $this->options, $this->get_predefined_account( $this->options['api_predef'] ) );

		return array(
			'api_public_key' => $options['api_public_key'],
			'api_secret_key' => $options['api_secret_key'],
		);
	}

	// Bit hacky, but it'll work.
	public function camptix_register_registration_info_header( $filter ) {
		global $camptix;

		if ( ! $camptix->order['total'] ) {
			return $filter;
		}

		$credentials  = $this->get_api_credentials();
		$description  = '';
		$ticket_count = array_sum( wp_list_pluck( $camptix->order['items'], 'quantity' ) );
		foreach ( $camptix->order['items'] as $item ) {
			$description .= ( $ticket_count > 1 ? (int) $item['quantity'] . 'x ' : '' ) . $item['name'] . "\n";
		}

		wp_register_script( 'stripe-checkout', 'https://checkout.stripe.com/checkout.js', array(), false, true );
		wp_enqueue_script( 'camptix-stripe', plugins_url( 'camptix-stripe.js', __DIR__ . '/camptix-stripe-gateway.php' ), array( 'stripe-checkout', 'jquery' ), '20170322', true );

		try {
			$amount = $this->get_fractional_unit_amount( $this->camptix_options['currency'], $camptix->order['total'] );
		} catch ( Exception $exception ) {
			$amount = null;
		}

		wp_localize_script( 'camptix-stripe', 'CampTixStripeData', array(
			'public_key'    => $credentials['api_public_key'],
			'name'          => $this->camptix_options['event_name'],
			'description'   => trim( $description ),
			'amount'        => $amount,
			'currency'      => $this->camptix_options['currency'],
			'token'         => ! empty( $_POST['tix_stripe_token'] )         ? wp_unslash( $_POST['tix_stripe_token'] )         : '',
			'receipt_email' => ! empty( $_POST['tix_stripe_reciept_email'] ) ? wp_unslash( $_POST['tix_stripe_reciept_email'] ) : '',
		) );

		return $filter;
	}

	/**
	 * Convert an amount in the currency's base unit to its equivalent fractional unit.
	 *
	 * Stripe wants amounts in the fractional unit (e.g., pennies), not the base unit (e.g., dollars). Zero-decimal
	 * currencies are not included yet, see `$supported_currencies`.
	 *
	 * The data here comes from https://en.wikipedia.org/wiki/List_of_circulating_currencies.
	 *
	 * @param string $order_currency
	 * @param int    $base_unit_amount
	 *
	 * @return int
	 * @throws Exception
	 */
	public function get_fractional_unit_amount( $order_currency, $base_unit_amount ) {
		$fractional_amount = null;

		$currency_multipliers = array(
			1    => array(
				'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF',
				'XOF', 'XPF',
			),
			100  => array(
				'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN',
				'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BZD', 'CAD', 'CDF', 'CHF', 'CNY', 'COP',
				'CRC', 'CVE', 'CZK', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP',
				'GBP', 'GEL', 'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR',
				'ILS', 'INR', 'ISK', 'JMD', 'KES', 'KGS', 'KHR', 'KYD', 'KZT',
				'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MKD', 'MMK', 'MNT', 'MRO', 'MOP', 'MUR', 'MVR', 'MWK',
				'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR',
				'PLN', 'QAR', 'RON', 'RSD', 'RUB', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLL',
				'SOS', 'SRD', 'STD', 'SZL', 'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD',
				'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'WST', 'XCD', 'YER', 'ZAR', 'ZMW',
			),
		);

		foreach ( $currency_multipliers as $multiplier => $currencies ) {
			if ( in_array( $order_currency, $currencies, true ) ) {
				$fractional_amount = (int) $base_unit_amount * $multiplier;
			}
		}

		if ( is_null( $fractional_amount ) ) {
			throw new Exception( "Unknown currency multiplier for $order_currency." );
		}

		return $fractional_amount;
	}

	/**
	 * Add payment settings fields
	 *
	 * This runs during settings field registration in CampTix for the
	 * payment methods configuration screen. If your payment method has
	 * options, this method is the place to add them to. You can use the
	 * helper function to add typical settings fields. Don't forget to
	 * validate them all in validate_options.
	 */
	public function payment_settings_fields() {
		// Allow pre-defined accounts if any are defined by plugins.
		if ( count( $this->get_predefined_accounts() ) > 0 ) {
			$this->add_settings_field_helper( 'api_predef', __( 'Predefined Account', 'camptix-stripe-payment-gateway' ), array( $this, 'field_api_predef' ) );
		}

		// Settings fields are not needed when a predefined account is chosen.
		// These settings fields should *never* expose predefined credentials.
		if ( ! $this->get_predefined_account() ) {
			$this->add_settings_field_helper( 'api_secret_key', __( 'Secret Key',      'camptix-stripe-payment-gateway' ), array( $this, 'field_text' ) );
			$this->add_settings_field_helper( 'api_public_key', __( 'Publishable Key', 'camptix-stripe-payment-gateway' ), array( $this, 'field_text' ) );
		}
	}

	/**
	 * Predefined accounts field callback
	 *
	 * Renders a drop-down select with a list of predefined accounts
	 * to select from, as well as some js for better ux.
	 *
	 * @uses $this->get_predefined_accounts()
	 *
	 * @param array $args
	 */
	public function field_api_predef( $args ) {
		$accounts = $this->get_predefined_accounts();

		if ( empty( $accounts ) ) {
			return;
		}

		?>

		<select id="camptix-predef-select" name="<?php echo esc_attr( $args['name'] ); ?>">
			<option value=""><?php _e( 'None', 'camptix-stripe-payment-gateway' ); ?></option>

			<?php foreach ( $accounts as $key => $account ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['value'], $key ); ?>>
					<?php echo esc_html( $account['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<!-- Let's disable the rest of the fields unless None is selected -->
		<script>
			jQuery( document ).ready( function( $ ) {
				var select = $('#camptix-predef-select')[0];

				$( select ).on( 'change', function() {
					$( '[name^="camptix_payment_options_stripe"]' ).each( function() {
						// Don't disable myself.
						if ( this == select ) {
							return;
						}

						$( this ).prop( 'disabled', select.value.length > 0 );
						$( this ).toggleClass( 'disabled', select.value.length > 0 );
					});
				});
			});
		</script>

		<?php
	}

	/**
	 * Get an array of predefined PayPal accounts
	 *
	 * Runs an empty array through a filter, where one might specify a list of
	 * predefined PayPal credentials, through a plugin or something.
	 *
	 * @static $predefs
	 *
	 * @return array An array of predefined accounts (or an empty one)
	 */
	public function get_predefined_accounts() {
		static $predefs = false;

		if ( false === $predefs ) {
			$predefs = apply_filters( 'camptix_stripe_predefined_accounts', array() );
		}

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
	 * @param string $key
	 *
	 * @return array An array with credentials, or an empty array if key not found.
	 */
	public function get_predefined_account( $key = false ) {
		$accounts = $this->get_predefined_accounts();

		if ( false === $key ) {
			$key = $this->options['api_predef'];
		}

		if ( ! array_key_exists( $key, $accounts ) ) {
			return array();
		}

		return $accounts[ $key ];
	}

	/**
	 * Validate options
	 *
	 * @param array $input
	 *
	 * @return array
	 */
	public function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['api_secret_key'] ) ) {
			$output['api_secret_key'] = $input['api_secret_key'];
		}

		if ( isset( $input['api_public_key'] ) ) {
			$output['api_public_key'] = $input['api_public_key'];
		}

		if ( isset( $input['api_predef'] ) ) {
			// If a valid predefined account is set, erase the credentials array.
			// We do not store predefined credentials in options, only code.
			if ( $this->get_predefined_account( $input['api_predef'] ) ) {
				$output = array_merge( $output, array(
					'api_secret_key' => '',
					'api_public_key' => '',
				) );
			} else {
				$input['api_predef'] = '';
			}

			$output['api_predef'] = $input['api_predef'];
		}

		return $output;
	}

	/**
	 * Process a checkout request
	 *
	 * This method is the fire starter. It's called when the user initiates
	 * a checkout process with the selected payment method. In PayPal's case,
	 * if everything's okay, we redirect to the PayPal Express Checkout page with
	 * the details of our transaction. If something's wrong, we return a failed
	 * result back to CampTix immediately.
	 *
	 * @param string $payment_token
	 *
	 * @return int One of the CampTix_Plugin::PAYMENT_STATUS_{status} constants
	 */
	public function payment_checkout( $payment_token ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		if ( empty( $payment_token ) ) {
			return false;
		}

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) ) {
			wp_die( __( 'The selected currency is not supported by this payment method.', 'camptix-stripe-payment-gateway' ) );
		}

		$order = $this->get_order( $payment_token );

		// One final check before charging the user.
		if ( ! $camptix->verify_order( $order ) ) {
			$camptix->log( "Dying because couldn't verify order", $order['attendee_id'] );
			wp_die( 'Something went wrong, order is no longer available.' );
		}

		try {
			$token        = wp_unslash( $_POST['tix_stripe_token'] );
			$description  = '';
			$ticket_count = array_sum( wp_list_pluck( $camptix->order['items'], 'quantity' ) );
			foreach ( $camptix->order['items'] as $item ) {
				$description .= ( $ticket_count > 1 ? (int) $item['quantity'] . 'x ' : '' ) . $item['name'] . "\n";
			}

			$statement_descriptor = $camptix->substr_bytes( strip_tags( $this->camptix_options['event_name'] ), 0, 22 );
			$receipt_email        = isset( $_POST['tix_stripe_reciept_email'] ) ? wp_unslash( $_POST['tix_stripe_reciept_email'] ) : false;
			$charge               = $this->charge( $camptix->order, $payment_token, $token, $receipt_email );
		} catch ( Exception $e ) {
			// A failure happened, since we don't expose the exact details to the user we'll catch every failure here.
			// Remvoe the POST param of the token so it's not used again.
			unset( $_POST['tix_stripe_token'] );

			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array(
				'exception' => $e->getMessage(),
			) );
		}

		$payment_data = array(
			'transaction_id'      => $charge->id,
			'transaction_details' => array(
				'raw' => array(
					'token'  => $token,
					'charge' => (array) $charge,
				),
			),
		);

		return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data );
	}

	/**
	 * Charge the attendee for their ticket via Stripe's API
	 *
	 * @param array  $order
	 * @param string $payment_token
	 * @param string $token_id
	 * @param string $receipt_email
	 *
	 * @return object
	 * @throws Exception
	 */
	protected function charge( $order, $payment_token, $token_id, $receipt_email ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$credentials          = $this->get_api_credentials();
		$statement_descriptor = $camptix->substr_bytes( strip_tags( $this->camptix_options['event_name'] ), 0, 22 );

		$request_args = array(
			'user-agent' => 'CampTix-Stripe/' . $camptix->version . ' (https://github.com/dd32/CampTix-Stripe-Payment-Gateway)',

			'body' => array(
				'amount'               => $this->get_fractional_unit_amount( $this->camptix_options['currency'], $order['total'] ),
				'currency'             => $this->camptix_options['currency'],
				'description'          => $this->camptix_options['event_name'],
				'statement_descriptor' => $statement_descriptor,
				'source'               => $token_id,
				'receipt_email'        => $receipt_email,
			),

			'headers' => array(
				'Authorization'   => 'Bearer ' . $credentials['api_secret_key'],
				'Idempotency-Key' => $payment_token,
			),
		);

		$response = wp_remote_post( 'https://api.stripe.com/v1/charges', $request_args );

		if ( is_wp_error( $response ) ) {
			$camptix->log( 'Error during Charge: ' . $response->get_error_message(), null, $response, 'stripe' );
			throw new Exception( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body->id ) || empty( $body->paid ) || ! $body->paid ) {
			$camptix->log( 'Error during Charge: Unexpected response.', null, $response, 'stripe' );
			throw new Exception( 'Unexpected response, missing charge ID.' );
		}

		return $body;
	}

	/**
	 * Adds a failure reason / code to the post-payment screen whne the payment fails.
	 *
	 * @param string $payment_token
	 * @param int    $result
	 * @param mixed  $data
	 */
	public function camptix_payment_result( $payment_token, $result, $data ) {
		global $camptix;

		if ( $camptix::PAYMENT_STATUS_FAILED == $result && ! empty( $data['transaction_details']['raw']['error'] ) ) {

			$error_data = $data['transaction_details']['raw']['error'];

			$message = $error_data['message'];
			$code    = $error_data['code'];
			if ( isset( $error_data['decline_code'] ) ) {
				$code .= ' ' . $error_data['decline_code'];
			}

			$camptix->error(
				sprintf(
					__( 'Your payment has failed: %1$s (%2$s)', 'camptix-stripe-payment-gateway' ),
					$message,
					$code
				)
			);

			// Unfortunately there's no way to remove the following failure message, but at least ours will display first:
			// A payment error has occurred, looks like chosen payment method is not responding. Please try again later.
		}
	}

	/**
	 * Submits a single, user-initiated refund request to PayPal and returns the result
	 *
	 * @param string $payment_token
	 *
	 * @return int One of the CampTix_Plugin::PAYMENT_STATUS_{status} constants
	 */
	public function payment_refund( $payment_token ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		$result = $this->send_refund_request( $payment_token );

		if ( CampTix_Plugin::PAYMENT_STATUS_REFUNDED != $result['status'] ) {
			$error_message = $result['refund_transaction_details'];

			if ( ! empty( $error_message ) ) {
				$camptix->error( sprintf( __( 'Stripe error: %s', 'camptix-stripe-payment-gateway' ), $error_message ) );
			}
		}

		$refund_data = array(
			'transaction_id'             => $result['transaction_id'],
			'refund_transaction_id'      => $result['refund_transaction_id'],
			'refund_transaction_details' => array(
				'raw' => $result['refund_transaction_details'],
			),
		);

		return $camptix->payment_result( $payment_token, $result['status'], $refund_data );
	}

	/**
	 * Sends a request to PayPal to refund a transaction
	 *
	 * @param string $payment_token
	 *
	 * @return array
	 */
	public function send_refund_request( $payment_token ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		$result = array(
			'token'          => $payment_token,
			'transaction_id' => $camptix->get_post_meta_from_payment_token( $payment_token, 'tix_transaction_id' ),
		);

		try {
			$charge = \Stripe\Refund::create( array(
				'charge' => $result['transaction_id'],
			) );

			$result['refund_transaction_id']      = $charge->id;
			$result['refund_transaction_details'] = $charge;
			$result['status']                     = CampTix_Plugin::PAYMENT_STATUS_REFUNDED;
		} catch ( Exception $e ) {
			$result['refund_transaction_id']      = false;
			$result['refund_transaction_details'] = $e->getMessage();
			$result['status']                     = CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED;

			$camptix->log( 'Error during RefundTransaction.', null, $e->getMessage() );
		}

		return $result;
	}
}

camptix_register_addon( 'CampTix_Payment_Method_Stripe' );
