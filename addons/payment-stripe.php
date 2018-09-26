<?php

class CampTix_Payment_Method_Stripe extends CampTix_Payment_Method {
	public $id          = 'stripe';
	public $name        = 'Credit Card (Stripe)';
	public $description = 'Credit card processing, powered by Stripe.';

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
		'TJS', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'USD', 'UYU', 'UZS', 'WST', 'XCD', 'YER', 'ZAR',
		'ZMW',
		// Zero decimal currencies (https://stripe.com/docs/currencies#zero-decimal)
		'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
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
		$this->options = array_merge(
			array(
				'api_predef'          => '',
				'api_secret_key'      => '',
				'api_public_key'      => '',
				'api_test_secret_key' => '',
				'api_test_public_key' => '',
				'sandbox'             => true,
			),
			$this->get_payment_options()
		);

		add_action( 'camptix_form_attendee_info_before', array( $this, 'camptix_form_attendee_info_before' ), 10, 2 );
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

		$prefix = 'api_';
		if ( true === $options['sandbox'] ) {
			$prefix = 'api_test_';
		}

		return array(
			'api_public_key' => $options[ $prefix . 'public_key' ],
			'api_secret_key' => $options[ $prefix . 'secret_key' ],
		);
	}

	/**
	 * Set up the data for Stripe and enqueue the assets.
	 *
	 * @param array $order   Data about the current order.
	 * @param array $options CampTix options.
	 */
	public function camptix_form_attendee_info_before( $order, $options ) {
		if ( ! $order['total'] ) {
			return;
		}

		$credentials  = $this->get_api_credentials();

		$item_summary = array();
		foreach ( $order['items'] as $item ) {
			$item_summary[] = sprintf(
				/* translators: 1: Name of ticket; 2: Quantity of ticket; */
				__( '%1$s x %2$d', 'camptix' ),
				esc_js( $item['name'] ),
				absint( $item['quantity'] )
			);
		}

		/* translators: used between list items, there is a space after the comma */
		$description = implode( __( ', ', 'camptix' ), $item_summary );

		wp_enqueue_script(
			'stripe-checkout',
			'https://checkout.stripe.com/checkout.js',
			array(),
			false,
			true
		);

		try {
			$amount = $this->get_fractional_unit_amount( $options['currency'], $order['total'] );
		} catch ( Exception $exception ) {
			$amount = null;
		}

		/**
		 * Filter: Modify the URL of the image used for the Stripe checkout overlay.
		 *
		 * By default, the Site Icon URL will be used for this image if one is available.
		 *
		 * @param string $checkout_image_url
		 */
		$checkout_image_url = apply_filters( 'camptix_stripe_checkout_image_url', get_site_icon_url() );

		wp_localize_script( 'stripe-checkout', 'CampTixStripeData', array(
			'public_key'    => $credentials['api_public_key'],
			'name'          => $options['event_name'],
			'image'         => ( $checkout_image_url ) ? esc_url( $checkout_image_url ) : '',
			'description'   => trim( $description ),
			'amount'        => $amount,
			'currency'      => $options['currency'],
			'token'         => ! empty( $_POST['tix_stripe_token'] )         ? wp_unslash( $_POST['tix_stripe_token'] )         : '',
			'receipt_email' => ! empty( $_POST['tix_stripe_receipt_email'] ) ? wp_unslash( $_POST['tix_stripe_receipt_email'] ) : '',
		) );
	}

	/**
	 * Convert an amount in the currency's base unit to its equivalent fractional unit.
	 *
	 * Stripe wants amounts in the fractional unit (e.g., pennies), not the base unit (e.g., dollars).
	 *
	 * The data here comes from https://stripe.com/docs/currencies
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
			// Zero-decimal currencies
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
				'TZS', 'UAH', 'USD', 'UYU', 'UZS', 'WST', 'XCD', 'YER', 'ZAR', 'ZMW',
			),
		);

		foreach ( $currency_multipliers as $multiplier => $currencies ) {
			if ( in_array( $order_currency, $currencies, true ) ) {
				$fractional_amount = floatval( $base_unit_amount ) * $multiplier;
			}
		}

		if ( is_null( $fractional_amount ) ) {
			throw new Exception( "Unknown currency multiplier for $order_currency." );
		}

		return intval( $fractional_amount );
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
			$this->add_settings_field_helper( 'api_predef', __( 'Predefined Account', 'camptix' ), array( $this, 'field_api_predef' ) );
		}

		// Settings fields are not needed when a predefined account is chosen.
		// These settings fields should *never* expose predefined credentials.
		if ( ! $this->get_predefined_account() ) {
			$this->add_settings_field_helper( 'api_secret_key', __( 'Secret Key',      'camptix' ), array( $this, 'field_text' ) );
			$this->add_settings_field_helper( 'api_public_key', __( 'Publishable Key', 'camptix' ), array( $this, 'field_text' ) );
			$this->add_settings_field_helper( 'api_test_secret_key', __( 'Test Secret Key',      'camptix' ), array( $this, 'field_text' ) );
			$this->add_settings_field_helper( 'api_test_public_key', __( 'Test Publishable Key', 'camptix' ), array( $this, 'field_text' ) );
			$this->add_settings_field_helper( 'sandbox',       __( 'Sandbox Mode',  'camptix' ), array( $this, 'field_yesno' ),
				sprintf(
					__( 'When Sandbox Mode is enabled, the Test keys will be used for transactions. <a href="%s">Read more</a> about testing transactions with Stripe.', 'camptix' ),
					'https://stripe.com/docs/testing'
				)
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
	 *
	 * @param array $args
	 */
	public function field_api_predef( $args ) {
		$accounts = $this->get_predefined_accounts();

		if ( empty( $accounts ) ) {
			return;
		}

		?>

		<select id="camptix-stripe-predef-select" name="<?php echo esc_attr( $args['name'] ); ?>">
			<option value=""><?php _e( 'None', 'camptix' ); ?></option>

			<?php foreach ( $accounts as $key => $account ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['value'], $key ); ?>>
					<?php echo esc_html( $account['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<!-- Let's disable the rest of the fields unless None is selected -->
		<script>
			jQuery( document ).ready( function( $ ) {
				var select = $('#camptix-stripe-predef-select')[0];

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
	 * Get an array of predefined Stripe accounts
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

		if ( isset( $input['api_test_secret_key'] ) ) {
			$output['api_test_secret_key'] = $input['api_test_secret_key'];
		}

		if ( isset( $input['api_public_key'] ) ) {
			$output['api_public_key'] = $input['api_public_key'];
		}

		if ( isset( $input['api_test_public_key'] ) ) {
			$output['api_test_public_key'] = $input['api_test_public_key'];
		}

		if ( isset( $input['sandbox'] ) ) {
			$output['sandbox'] = (bool) $input['sandbox'];
		}

		if ( isset( $input['api_predef'] ) ) {
			// If a valid predefined account is set, erase the credentials array.
			// We do not store predefined credentials in options, only code.
			if ( $this->get_predefined_account( $input['api_predef'] ) ) {
				$output = array_merge( $output, array(
					'api_secret_key'      => '',
					'api_public_key'      => '',
					'api_test_secret_key' => '',
					'api_test_public_key' => '',
				) );
			} else {
				$input['api_predef'] = '';
			}

			$output['api_predef'] = $input['api_predef'];
		}

		return $output;
	}

	/**
	 * Submits a single, user-initiated charge request to Stripe and returns the result.
	 *
	 * @param string $payment_token
	 *
	 * @return int One of the CampTix_Plugin::PAYMENT_STATUS_{status} constants
	 */
	public function payment_checkout( $payment_token ) {
		if ( empty( $payment_token ) ) {
			return false;
		}

		/** @var CampTix_Plugin $camptix */
		global $camptix;

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) ) {
			wp_die( __( 'The selected currency is not supported by this payment method.', 'camptix' ) );
		}

		$order = $this->get_order( $payment_token );

		// One final check before charging the user.
		if ( ! $camptix->verify_order( $order ) ) {
			$camptix->log( "Could not verify order", $order['attendee_id'], array( 'payment_token' => $payment_token ), 'stripe' );
			wp_die( 'Something went wrong, order is not available.' );
		}

		$credentials = $this->get_api_credentials();

		$stripe        = new CampTix_Stripe_API_Client( $payment_token, $credentials['api_secret_key'] );
		$amount        = $this->get_fractional_unit_amount( $this->camptix_options['currency'], $order['total'] );
		$source        = wp_unslash( $_POST['tix_stripe_token'] );
		$description   = $this->camptix_options['event_name'];
		$receipt_email = isset( $_POST['tix_stripe_receipt_email'] ) ? wp_unslash( $_POST['tix_stripe_receipt_email'] ) : false;
		$metadata      = array();

		foreach ( $order['items'] as $item ) {
			$metadata[ $item['name'] ] = $item['quantity'];
		}

		$charge = $stripe->request_charge( $amount, $source, $description, $receipt_email, $metadata );

		if ( is_wp_error( $charge ) ) {
			// A failure happened, since we don't expose the exact details to the user we'll catch every failure here.
			// Remove the POST param of the token so it's not used again.
			unset( $_POST['tix_stripe_token'] );

			$camptix->log( 'Stripe charge failed', $order['attendee_id'], $charge, 'stripe' );

			return $camptix->payment_result(
				$payment_token,
				CampTix_Plugin::PAYMENT_STATUS_FAILED,
				array(
					'errors'     => $charge->errors,
					'error_data' => $charge->error_data,
				)
			);
		}

		// This data shouldn't be stored in a log.
		unset( $charge['source'] );

		$payment_data = array(
			'transaction_id'      => $charge['id'],
			'transaction_details' => array(
				'raw' => array(
					'token'  => $source,
					'charge' => $charge,
				),
			),
		);

		return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data );
	}

	/**
	 * Adds a failure reason / code to the post-payment screen when the payment fails.
	 *
	 * @param string $payment_token
	 * @param int    $result
	 * @param array|WP_Error  $data
	 */
	public function camptix_payment_result( $payment_token, $result, $data ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		if ( CampTix_Plugin::PAYMENT_STATUS_FAILED === $result && ! empty( $data ) ) {
			if ( isset( $data['errors'] ) ) {
				$codes = array_keys( $data['errors'] );

				$camptix->error(
					sprintf(
						__( 'Your payment has failed: %1$s (%2$s)', 'camptix' ),
						esc_html( $data['errors'][ $codes[0] ][0] ),
						esc_html( $codes[0] )
					)
				);
			} elseif ( isset( $data['transaction_details']['raw']['error'] ) ) {
				$error_data = $data['transaction_details']['raw']['error'];

				$message = $error_data['message'];
				$code    = $error_data['code'];
				if ( isset( $error_data['decline_code'] ) ) {
					$code .= ' ' . $error_data['decline_code'];
				}

				$camptix->error(
					sprintf(
						__( 'Your payment has failed: %1$s (%2$s)', 'camptix' ),
						esc_html( $message ),
						esc_html( $code )
					)
				);
			} else {
				$camptix->error(
					__( 'Your payment has failed.', 'camptix' )
				);
			}

			// Unfortunately there's no way to remove the following failure message, but at least ours will display first:
			// A payment error has occurred, looks like chosen payment method is not responding. Please try again later.
		}
	}

	/**
	 * Submits a single, user-initiated refund request to Stripe and returns the result.
	 *
	 * @param string $payment_token
	 *
	 * @return int One of the CampTix_Plugin::PAYMENT_STATUS_{status} constants
	 */
	public function payment_refund( $payment_token ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$result = $this->send_refund_request( $payment_token );

		if ( CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED === $result['status'] ) {
			$camptix->log( 'Stripe refund failed', $order['attendee_id'], $refund, 'stripe' );

			return $camptix->payment_result(
				$payment_token,
				CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED,
				$result
			);
		}

		return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_REFUNDED, $result );
	}

	/**
	 * Send a request to Stripe to refund a transaction.
	 *
	 * @param string $payment_token
	 *
	 * @return array
	 */
	public function send_refund_request( $payment_token ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$result = array(
			'status'                     => CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED,
			'transaction_id'             => '',
			'refund_transaction_id'      => '',
			'refund_transaction_details' => '',
		);

		$order          = $this->get_order( $payment_token );
		$transaction_id = $camptix->get_post_meta_from_payment_token( $payment_token, 'tix_transaction_id' );

		if ( empty( $order ) || ! $transaction_id ) {
			$camptix->log( 'Could not refund because could not find order', null, array( 'payment_token' => $payment_token ), 'stripe' );

			return $result;
		}

		$metadata = array(
			'Refund reason' => filter_input( INPUT_POST, 'tix_refund_request_reason', FILTER_SANITIZE_STRING ),
		);

		// Create a new Idempotency token for the refund request.
		// The same token can't be used for both a charge and a refund.
		$idempotency_token = md5( 'tix-idempotency-token' . $payment_token . time() . rand( 1, 9999 ) );
		$credentials       = $this->get_api_credentials();

		$stripe = new CampTix_Stripe_API_Client( $idempotency_token, $credentials['api_secret_key'] );
		$refund = $stripe->request_refund( $transaction_id, $metadata );

		if ( is_wp_error( $refund ) ) {
			$result['refund_transaction_details'] = array(
				'errors'     => $refund->errors,
				'error_data' => $refund->error_data,
			);

			return $result;
		}

		$result['status']                     = CampTix_Plugin::PAYMENT_STATUS_REFUNDED;
		$result['transaction_id']             = $refund['charge'];
		$result['refund_transaction_id']      = $refund['id'];
		$result['refund_transaction_details'] = array(
			'raw' => array(
				'refund_transaction_id' => $refund['id'],
				'refund'                => $refund,
			),
		);

		return $result;
	}
}

camptix_register_addon( 'CampTix_Payment_Method_Stripe' );

/**
 * Class CampTix_Stripe_API_Client
 *
 * A simple client for the Stripe API to handle the simple needs of CampTix.
 */
class CampTix_Stripe_API_Client {
	/**
	 * @var string
	 */
	protected $payment_token = '';

	/**
	 * @var string
	 */
	protected $api_secret_key = '';

	/**
	 * @var string
	 */
	protected $user_agent = '';

	/**
	 * @var string
	 */
	protected $currency = '';

	/**
	 * CampTix_Stripe_API_Client constructor.
	 *
	 * @param string $payment_token
	 * @param string $api_secret_key
	 */
	public function __construct( $payment_token, $api_secret_key ) {
		/* @var CampTix_Plugin $camptix */
		global $camptix;

		$camptix_options = $camptix->get_options();

		$this->payment_token  = $payment_token;
		$this->api_secret_key = $api_secret_key;
		$this->user_agent     = 'CampTix/' . $camptix->version;
		$this->currency       = $camptix_options['currency'];
	}

	/**
	 * Get the API's endpoint URL for the given request type.
	 *
	 * @param string $request_type 'charge' or 'refund'.
	 *
	 * @return string
	 */
	protected function get_request_url( $request_type ) {
		$request_url = '';

		$api_base = 'https://api.stripe.com/';

		switch ( $request_type ) {
			case 'charge' :
				$request_url = $api_base . 'v1/charges';
				break;
			case 'refund' :
				$request_url = $api_base . 'v1/refunds';
				break;
		}

		return $request_url;
	}

	/**
	 * Send a request to the API and do basic processing on the response.
	 *
	 * @param string $type The type of API request. 'charge' or 'refund'.
	 * @param array  $args Parameters that will populate the body of the request.
	 *
	 * @return array|WP_Error
	 */
	protected function send_request( $type, $args ) {
		$request_url = $this->get_request_url( $type );

		if ( ! $request_url ) {
			return new WP_Error(
				'camptix_stripe_invalid_request_type',
				sprintf(
					__( '%s is not a valid request type.', 'camptix' ),
					esc_html( $type )
				)
			);
		}

		$request_args = array(
			'user-agent' => $this->user_agent,
			'timeout'    => 30, // The default of 5 seconds can result in frequent timeouts.

			'body' => $args,

			'headers' => array(
				'Authorization'   => 'Bearer ' . $this->api_secret_key,
				'Idempotency-Key' => $this->payment_token,
			),
		);

		$response = wp_remote_post( $request_url, $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $response_code ) {
			if ( ! is_array( $response_body ) || ! isset( $response_body['error'] ) ) {
				return new WP_Error(
					'camptix_stripe_unexpected_response',
					__( 'An unexpected error occurred.', 'camptix' ),
					$response
				);
			}

			return $this->handle_error( $response_code, $response_body['error'] );
		}

		return $response_body;
	}

	/**
	 * Parse error codes and messages from the API.
	 *
	 * @param int   $error_code
	 * @param array $error_content
	 *
	 * @return WP_Error
	 */
	protected function handle_error( $error_code, $error_content ) {
		$error = new WP_Error();

		switch ( $error_content['type'] ) {
			case 'card_error' :
				if ( isset( $error_content['message'] ) ) {
					$reason = $error_content['message'];
				} elseif ( isset( $error_content['decline_code'] ) ) {
					$reason = $error_content['decline_code'];
				} elseif ( isset( $error_content['code'] ) ) {
					$reason = $error_content['code'];
				} else {
					$reason = __( 'Unspecified error', 'camptix' );
				}

				$message = sprintf(
					__( 'Card error: %s', 'camptix' ),
					esc_html( $reason )
				);
				break;
			default :
				$message = sprintf( __( '%d error: %s', 'camptix' ), $error_code, esc_html( $error_content['type'] ) );
				break;
		}

		$error->add(
			sprintf( 'camptix_stripe_request_error_%d', $error_code ),
			$message,
			$error_content
		);

		return $error;
	}

	/**
	 * Send a charge request to the API.
	 *
	 * @param int    $amount        The amount to charge. Should already be converted to its fractional unit.
	 * @param string $source        The Stripe token.
	 * @param string $description   The description of the transaction that the charge is for.
	 * @param string $receipt_email The email address to send the receipt to.
	 * @param array  $metadata      Associative array of extra data to store with the transaction.
	 *
	 * @return array|WP_Error
	 */
	public function request_charge( $amount, $source, $description, $receipt_email, $metadata = array() ) {
		$statement_descriptor = sanitize_text_field( $description );
		$statement_descriptor = str_replace( array( '<', '>', '"', "'" ), '', $statement_descriptor );
		$statement_descriptor = $this->trim_string( $statement_descriptor, 22 );

		$args = array(
			'amount'               => $amount,
			'currency'             => $this->currency,
			'description'          => $description,
			'statement_descriptor' => $statement_descriptor,
			'source'               => $source,
			'receipt_email'        => $receipt_email,
		);

		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			$args['metadata'] = $this->clean_metadata( $metadata );
		}

		return $this->send_request( 'charge', $args );
	}

	/**
	 * Send a refund request to the API.
	 *
	 * @param string $transaction_id
	 * @param array  $metadata       Associative array of extra data to store with the transaction.
	 *
	 * @return array|WP_Error
	 */
	public function request_refund( $transaction_id, $metadata = array() ) {
		$args = array(
			'charge' => $transaction_id,
			'reason' => 'requested_by_customer',
		);

		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			$args['metadata'] = $this->clean_metadata( $metadata );
		}

		return $this->send_request( 'refund', $args );
	}

	/**
	 * Trim a string to a certain number of characters.
	 *
	 * @param string $string The original string.
	 * @param int    $chars  The max number of characters for the string.
	 * @param string $suffix A suffix to append if the string exceeds the max.
	 *
	 * @return string
	 */
	protected function trim_string( $string, $chars = 500, $suffix = '...' ) {
		if ( strlen( $string ) > $chars ) {
			if ( function_exists( 'mb_substr' ) ) {
				$string = mb_substr( $string, 0, ( $chars - mb_strlen( $suffix ) ) ) . $suffix;
			} else {
				$string = substr( $string, 0, ( $chars - strlen( $suffix ) ) ) . $suffix;
			}
		}

		return $string;
	}

	/**
	 * Clean up an array of metadata before passing to Stripe.
	 *
	 * @see https://stripe.com/docs/api#metadata
	 *
	 * @param array $metadata An associative array of metadata.
	 *
	 * @return array
	 */
	protected function clean_metadata( $metadata = array() ) {
		$cleaned = array();

		foreach ( $metadata as $key => $val ) {
			// A Stripe transaction can only have 20 metadata keys.
			if ( count( $cleaned ) > 20 ) {
				return $cleaned;
			}

			// Trim the key to 40 chars.
			$key = $this->trim_string( $key, 40, '' );

			// Trim the val to 500 chars.
			$val = $this->trim_string( $val );

			$cleaned[ $key ] = $val;
		}

		return $cleaned;
	}
}
