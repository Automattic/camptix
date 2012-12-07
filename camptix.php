<?php
/*
 * Plugin Name: CampTix Event Ticketing
 * Plugin URI: http://wordcamp.org
 * Description: Simple and flexible event ticketing for WordPress.
 * Version: 1.2.1
 * Author: Automattic
 * Author URI: http://wordcamp.org
 * License: GPLv2
 */

class CampTix_Plugin {
	protected $options;
	protected $notices;
	protected $errors;
	protected $infos;
	protected $admin_notices;

	public $debug;
	public $beta_features_enabled;
	public $version = 20120831;
	public $css_version = 20121004;
	public $js_version = 20121004;
	public $caps;

	public $addons = array();
	public $addons_loaded = array();

	protected $tickets;
	protected $tickets_selected;
	protected $tickets_selected_count;
	protected $form_data;
	protected $coupon;
	protected $error_flags;
	protected $error_data;
	protected $did_template_redirect;
	protected $did_checkout;
	protected $shortcode_contents;

	// Allow others to use this.
	public $filter_post_meta = false;

	const PAYMENT_STATUS_CANCELLED = 1;
	const PAYMENT_STATUS_COMPLETED = 2;
	const PAYMENT_STATUS_PENDING = 3;
	const PAYMENT_STATUS_FAILED = 4;
	const PAYMENT_STATUS_TIMEOUT = 5;
	const PAYMENT_STATUS_REFUNDED = 6;

	/**
	 * Fired as soon as this file is loaded, don't do anything
	 * but filters and actions here.
	 */
	function __construct() {
		do_action( 'camptix_pre_init' );

		require( dirname( __FILE__ ) . '/inc/class-camptix-addon.php' );
		require( dirname( __FILE__ ) . '/inc/class-camptix-payment-method.php' );

		// Addons
		add_action( 'init', array( $this, 'load_addons' ), 8 );
		add_action( 'camptix_load_addons', array( $this, 'load_default_addons' ) );

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'init', array( $this, 'schedule_events' ), 9 );
		add_action( 'shutdown', array( $this, 'shutdown' ) );

		// Load a text domain
		load_plugin_textdomain( 'camptix', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	// The tix_action is a user-facing query var, let's enable it.
	function query_vars( $query_vars ) {
		$query_vars = array_merge( $query_vars, array(
			'tix_action',
		) );
		return $query_vars;
	}

	/**
	 * Fired during init, doh!
	 */
	function init() {
		$this->options = $this->get_options();
		$this->debug = (bool) apply_filters( 'camptix_debug', false );
		$this->beta_features_enabled = (bool) apply_filters( 'camptix_beta_features_enabled', false );

		// Capability mapping.
		$this->caps = apply_filters( 'camptix_capabilities', array(
			'manage_tickets'   => 'manage_options',
			'manage_attendees' => 'manage_options',
			'manage_coupons'   => 'manage_options',
			'manage_tools'     => 'manage_options',
			'manage_options'   => 'manage_options',
			'delete_attendees' => 'manage_options',
		) );

		// Explicitly disable all beta features if beta features is off.
		if ( ! $this->beta_features_enabled )
			foreach ( $this->get_beta_features() as $beta_feature )
				$this->options[$beta_feature] = false;

		// The following three are just different kinds (colors) of user feedback.
		// Don't use directly, instead use $this->notice / error / info methods.
		$this->infos = array();
		$this->notices = array();
		$this->errors = array();

		// Our main shortcode
		add_shortcode( 'camptix', array( $this, 'shortcode_callback' ) );

		// Additional query vars.
		add_filter( 'query_vars', array( $this, 'query_vars' ) );

		// Hack to avoid object caching, see revenue report.
		add_filter( 'get_post_metadata', array( $this, 'get_post_metadata' ), 10, 4 );

		// Stuff that might need to redirect, thus not in [camptix] shortcode.
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 9 ); // earlier than the others.

		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_head', array( $this, 'admin_menu_fix' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'admin_head', array( $this, 'admin_head' ) );

		// Handle meta for our post types.
		add_action( 'save_post', array( $this, 'save_ticket_post' ) );
		add_action( 'save_post', array( $this, 'save_attendee_post' ) );
		add_action( 'save_post', array( $this, 'save_coupon_post' ) );

		// Used to update stats
		add_action( 'transition_post_status', array( $this, 'transition_post_status' ), 10, 3 );

		// Notices, errors and infos, all in one.
		add_action( 'camptix_notices', array( $this, 'do_notices' ) );
		add_action( 'admin_notices', array( $this, 'do_admin_notices' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		// Sort of admin_init but on the Tickets > Tools page only.
		add_action( 'load-tix_ticket_page_camptix_tools', array( $this, 'summarize_extra_fields' ) );
		add_action( 'load-tix_ticket_page_camptix_tools', array( $this, 'summarize_admin_init' ) ); // marked as admin init but not really
		add_action( 'load-tix_ticket_page_camptix_tools', array( $this, 'export_admin_init' ) ); // same here, but close
		add_action( 'load-tix_ticket_page_camptix_tools', array( $this, 'menu_tools_refund_admin_init' ) );

		add_action( 'camptix_question_fields_init', array( $this, 'question_fields_init' ) );
		add_action( 'camptix_init_notify_shortcodes', array( $this, 'init_notify_shortcodes' ), 9 );

		// Other things required during init.
		$this->custom_columns();
		$this->register_post_types();
		$this->register_post_statuses();

		do_action( 'camptix_init' );
	}

	/**
	 * Scheduled events, mainly around e-mail jobs, runs during file load.
	 */
	function schedule_events() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );

		add_action( 'tix_scheduled_every_ten_minutes', array( $this, 'send_emails_batch' ) );
		add_action( 'tix_scheduled_every_ten_minutes', array( $this, 'process_refund_all' ) );

		add_action( 'tix_scheduled_daily', array( $this, 'review_timeout_payments' ) );

		if ( ! wp_next_scheduled( 'tix_scheduled_every_ten_minutes' ) )
			wp_schedule_event( time(), '10-mins', 'tix_scheduled_every_ten_minutes' );

		// wp_clear_scheduled_hook( 'tix_scheduled_hourly' );
		if ( ! wp_next_scheduled( 'tix_scheduled_daily' ) )
			wp_schedule_event( time(), 'daily', 'tix_scheduled_daily' );
	}

	/**
	 * Filters cron_schedules
	 */
	function cron_schedules( $schedules ) {
		$schedules['10-mins'] = array(
			'interval' => 60 * 10,
			'display' => __( 'Once every 10 minutes', 'camptix' ),
		);
		return $schedules;
	}

	/**
	 * Runs during the tix_email_schedule scheduled event, processes e-mail jobs.
	 */
	function send_emails_batch() {
		global $wpdb;

		// Grab only one e-mail job at a time.
		$email = get_posts( array(
			'post_type' => 'tix_email',
			'post_status' => 'pending',
			'order' => 'ASC',
			'posts_per_page' => 1,
			'cache_results' => false,
		) );

		if ( ! $email )
			return;

		$email = array_shift( $email );
		$this->log( 'Executing e-mail job.', $email->ID, null, 'notify' );
		$max = apply_filters( 'camptix_notify_recipients_batch_count', 200 ); // plugins can change this.

		$recipients_data = $wpdb->get_results( $wpdb->prepare( "SELECT SQL_CALC_FOUND_ROWS meta_id, meta_value FROM $wpdb->postmeta WHERE $wpdb->postmeta.post_id = %d AND $wpdb->postmeta.meta_key = %s LIMIT %d;", $email->ID, 'tix_email_recipient_id', $max ) );
		$total = $wpdb->get_var( "SELECT FOUND_ROWS();" );
		$processed = 0;

		$recipients = array();
		foreach ( $recipients_data as $recipient )
			$recipients[$recipient->meta_value] = $recipient->meta_id;

		unset( $recipients_data, $recipient );

		if ( $recipients && is_array( $recipients ) && count( $recipients ) > 0 ) {

			do_action( 'camptix_init_notify_shortcodes' );

			$paged = 1;
			while ( $attendees = get_posts( array(
					'post_type' => 'tix_attendee',
					'post_status' => 'any',
					'post__in' => array_keys( $recipients ),
					'fields' => 'ids', // ! no post objects
					'orderby' => 'ID',
					'order' => 'ASC',
					'paged' => $paged++,
					'posts_per_page' => min( 100, $max ),
					'cache_results' => false, // no caching
			) ) ) {

				// Prepare post metadata, disable object cache.
				$this->filter_post_meta = $this->prepare_metadata_for( $attendees );

				foreach ( $attendees as $attendee_id ) {
					$attendee_email = get_post_meta( $attendee_id, 'tix_email', true );
					$count = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE post_id = %d AND meta_id = %d LIMIT 1;", $email->ID, $recipients[$attendee_id] ) );

					if ( $count > 0 ) {

						$data = array(
							'email_id' => $email->ID,
							'email_title' => $email->post_title,
							'attendee_id' => $attendee_id,
							'attendee_email' => $attendee_email,
						);

						if ( ! is_email( $attendee_email ) ) {
							$this->log( sprintf( '%s is not a valid e-mail, removing from queue.', $attendee_email ), $email->ID, $data, 'notify' );
						} else {

							$this->notify_shortcodes_attendee_id = $attendee_id;
							$email_content = do_shortcode( $email->post_content );
							$email_title = do_shortcode( $email->post_title );

							// Decode entities since the e-mails sent is a plain/text, not html.
							$email_title = html_entity_decode( $email_title );
							$email_content = html_entity_decode( $email_content );

							// Attempt to send an e-mail.
							if ( $this->wp_mail( $attendee_email, $email_title, $email_content ) ) {
								$this->log( sprintf( 'E-mail successfully sent to %s', $attendee_email ), $email->ID, $data, 'notify' );
							} else {
								$this->log( sprintf( 'Could not send e-mail to %s, removing from queue.', $attendee_email ), $email->ID, $data, 'notify' );
							}
						}

						$processed++;
					}
				}

				// Clean post meta cache.
				$this->filter_post_meta = false;
				$this->notify_shortcodes_attendee_id = false;
			}
		}

		//update_post_meta( $email->ID, 'tix_email_recipients', $recipients );
		$this->log( sprintf( 'Processed %d recipients. %d recipients remaining.', $processed, $total - $processed ), $email->ID, null, 'notify' );

		// Let's see if there's anything left.
		if ( $total - $processed < 1 ) {

			// Published tix_email posts means completed jobs.
			wp_update_post( array(
				'ID' => $email->ID,
				'post_status' => 'publish',
			) );

			$this->log( 'Email job complete and published.', $email->ID, null, 'notify' );
		}
	}

	/**
	 * Removes all shortcodes and creates some shortcodes
	 * to be used with CampTix Notify.
	 */
	function init_notify_shortcodes() {
		remove_all_shortcodes();

		add_shortcode( 'first_name', array( $this, 'notify_shortcode_first_name' ) );
		add_shortcode( 'last_name', array( $this, 'notify_shortcode_last_name' ) );
		add_shortcode( 'email', array( $this, 'notify_shortcode_email' ) );
		add_shortcode( 'ticket_url', array( $this, 'notify_shortcode_ticket_url' ) );
	}

	/**
	 * Notify shortcode: returns the attendee first name.
	 */
	function notify_shortcode_first_name( $atts ) {
		if ( $this->notify_shortcodes_attendee_id )
			return get_post_meta( $this->notify_shortcodes_attendee_id, 'tix_first_name', true );
	}

	/**
	 * Notify shortcode: returns the attendee last name.
	 */
	function notify_shortcode_last_name( $atts ) {
		if ( $this->notify_shortcodes_attendee_id )
			return get_post_meta( $this->notify_shortcodes_attendee_id, 'tix_last_name', true );
	}

	/**
	 * Notify shortcode: returns the attendee e-mail address.
	 */
	function notify_shortcode_email( $atts ) {
		if ( $this->notify_shortcodes_attendee_id )
			return get_post_meta( $this->notify_shortcodes_attendee_id, 'tix_email', true );
	}

	/**
	 * Notify shortcode: returns the attendee edit url
	 */
	function notify_shortcode_ticket_url( $atts ) {
		if ( ! $this->notify_shortcodes_attendee_id )
			return;

		$edit_token = get_post_meta( $this->notify_shortcodes_attendee_id, 'tix_edit_token', true );
		return $this->get_edit_attendee_link( $this->notify_shortcodes_attendee_id, $edit_token );
	}

	/**
	 * This is taken out here to illustrate how a third-party plugin or
	 * theme can hook into CampTix to add their own Summarize fields. This method
	 * grabs all the available tickets questions and adds them to Summarize.
	 */
	function summarize_extra_fields() {
		if ( 'summarize' != $this->get_tools_section() )
			return;

		$that = $this;
		$questions = $that->get_all_questions();

		// Adds all questions to Summarize and register the callback that counts all the things.
		add_filter( 'camptix_summary_fields', array( $this, 'camptix_summary_fields_extras' ) );
		add_action( 'camptix_summarize_by_field', array( $this, 'camptix_summarize_by_field_extras' ), 10, 3 );
	}

	/**
	 * Filters camptix_summary_fields to add user-defined
	 * questions to the Summarize list.
	 */
	function camptix_summary_fields_extras( $fields ) {
		$questions = $this->get_all_questions();
		foreach ( $questions as $key => $question )
			$fields['tix_q_' . $key] = $question['field'];

		return $fields;
	}

	/**
	 * Runs during camptix_summarize_by_field, fetches answers from
	 * attendee objects and increments summary.
	 */
	function camptix_summarize_by_field_extras( $summarize_by, $summary, $attendee ) {
		if ( 'tix_q_' != substr( $summarize_by, 0, 6 ) )
			return;

		$key = substr( $summarize_by, 6 );
		$answers = (array) get_post_meta( $attendee->ID, 'tix_questions', true );

		if ( isset( $answers[$key] ) && ! empty( $answers[$key] ) )
			$this->increment_summary( $summary, $answers[$key] );
		else
			$this->increment_summary( $summary, __( 'None', 'camptix' ) );
	}

	/**
	 * Get a CSS file, @todo make it removable through an option.
	 */
	function enqueue_scripts() {
		wp_register_style( 'camptix', plugins_url( 'camptix.css', __FILE__ ), array(), $this->css_version );
		wp_register_script( 'camptix', plugins_url( 'camptix.js', __FILE__ ), array( 'jquery' ), $this->css_version );

		// Let's play by the rules and print this in the <head> section.
		wp_enqueue_style( 'camptix' );
	}

	function admin_enqueue_scripts() {
		global $wp_query;

		if ( ! $wp_query->query_vars ) { // only on singular admin pages
			if ( 'tix_ticket' == get_post_type() || 'tix_coupon' == get_post_type() ) {
			}
		}

		// Let's see whether to include admin.css and admin.js
		if ( is_admin() ) {
			$post_types = array( 'tix_ticket', 'tix_coupon', 'tix_email', 'tix_attendee' );
			$pages = array( 'camptix_options', 'camptix_tools' );
			if (
				( in_array( get_post_type(), $post_types ) ) ||
				( isset( $_REQUEST['post_type'] ) && in_array( $_REQUEST['post_type'], $post_types ) ) ||
				( isset( $_REQUEST['page'] ) && in_array( $_REQUEST['page'], $pages ) )
			) {
				wp_enqueue_script( 'jquery-ui-datepicker' );
				wp_enqueue_style( 'jquery-ui', plugins_url( '/external/jquery-ui.css', __FILE__ ), array(), $this->version );

				wp_enqueue_style( 'camptix-admin', plugins_url( '/admin.css', __FILE__ ), array(), $this->css_version );
				wp_enqueue_script( 'camptix-admin', plugins_url( '/admin.js', __FILE__ ), array( 'jquery', 'jquery-ui-datepicker' ), $this->js_version );
				wp_dequeue_script( 'autosave' );
			}
		}

		$screen = get_current_screen();
		if ( 'tix_ticket_page_camptix_options' == $screen->id ) {
			wp_enqueue_script( 'jquery-ui-datepicker' );
			wp_enqueue_style( 'jquery-ui', plugins_url( '/external/jquery-ui.css', __FILE__ ), array(), $this->version );
		}
	}

	/**
	 * Filters column fields for our new post types, adds extra columns
	 * and registers callback actions to render column callback.
	 */
	function custom_columns() {
		// Ticket columns
		add_filter( 'manage_edit-tix_ticket_columns', array( $this, 'manage_columns_ticket_filter' ) );
		add_action( 'manage_tix_ticket_posts_custom_column', array( $this, 'manage_columns_ticket_action' ), 10, 2 );

		// Attendee columns
		add_filter( 'manage_edit-tix_attendee_columns', array( $this, 'manage_columns_attendee_filter' ) );
		add_action( 'manage_tix_attendee_posts_custom_column', array( $this, 'manage_columns_attendee_action' ), 10, 2 );

		// Coupon columns
		add_filter( 'manage_edit-tix_coupon_columns', array( $this, 'manage_columns_coupon_filter' ) );
		add_action( 'manage_tix_coupon_posts_custom_column', array( $this, 'manage_columns_coupon_action' ), 10, 2 );

		// E-mail columns
		add_filter( 'manage_edit-tix_email_columns', array( $this, 'manage_columns_email_filter' ) );
		add_action( 'manage_tix_email_posts_custom_column', array( $this, 'manage_columns_email_action' ), 10, 2 );

		// Maybe hide some columns.
		add_action( 'load-edit.php', array( $this, 'update_hidden_columns' ) );
	}

	/**
	 * Manage columns filter for ticket post type.
	 */
	function manage_columns_ticket_filter( $columns ) {
		$columns['tix_price'] = __( 'Price', 'camptix' );
		$columns['tix_quantity'] = __( 'Quantity', 'camptix' );
		$columns['tix_purchase_count'] = __( 'Purchased', 'camptix' );
		$columns['tix_remaining'] = __( 'Remaining', 'camptix' );
		$columns['tix_availability'] = __( 'Availability', 'camptix' );
		$date = $columns['date'];
		unset( $columns['date'] );
		$columns['date'] = $date;
		return $columns;
	}

	/**
	 * Manage columns action for ticket post type.
	 */
	function manage_columns_ticket_action( $column, $post_id ) {
		switch ( $column ) {
			case 'tix_price':
				echo $this->append_currency( get_post_meta( $post_id, 'tix_price', true ) );
				break;
			case 'tix_quantity':
				echo intval( get_post_meta( $post_id, 'tix_quantity', true ) );
				break;
			case 'tix_purchase_count':
				$attendees_url = get_admin_url( 0, '/edit.php?post_type=tix_attendee' );
				$attendees_url = add_query_arg( 's', 'tix_ticket_id:' . intval( $post_id ), $attendees_url );
				printf( '<a href="%s">%d</a>', esc_url( $attendees_url ), intval( $this->get_purchased_tickets_count( $post_id ) ) );
				break;
			case 'tix_remaining':
				echo $this->get_remaining_tickets( $post_id );

				if ( $this->options['reservations_enabled'] ) {
					$reserved = 0;
					$reservations = $this->get_reservations( $post_id );
					foreach ( $reservations as $reservation_token => $reservation )
						$reserved += $reservation['quantity'] - $this->get_purchased_tickets_count( $post_id, $reservation_token );

					if ( $reserved > 0 )
						printf( ' ' . __( '(%d reserved)', 'camptix' ), $reserved );
				}

				break;
			case 'tix_availability':
				$start = get_post_meta( $post_id, 'tix_start', true );
				$end = get_post_meta( $post_id, 'tix_end', true );

				if ( ! $start && ! $end ) {
					echo __( 'Auto', 'camptix' );
				} else {
					// translators: 1: "from" date, 2: "to" date
					printf( __( '%1$s &mdash; %2$s', 'camptix' ), $start, $end );
				}

				break;
		}
	}

	/**
	 * Manage columns filter for attendee post type.
	 */
	function manage_columns_attendee_filter( $columns ) {
		$columns['tix_email'] = __( 'E-mail', 'camptix' );
		$columns['tix_ticket'] = __( 'Ticket', 'camptix' );
		$columns['tix_coupon'] = __( 'Coupon', 'camptix' );

		/*$questions = $this->get_all_questions();
		foreach ( $questions as $key => $question ) {

			// Trim the label if it's too long.
			$label = $question['field'];
			if ( mb_strlen( $label ) > 10 )
				$label = trim( mb_substr( $label, 0, 10 ) ) . '...';

			$label = sprintf( '<abbr class="tix-column-label" title="%s">%s</abbr>', esc_attr( $question['field'] ), esc_html( $label ) );
			$columns['tix_q_' . md5( $key )] = $label;
		}*/

		if ( $this->options['reservations_enabled'] )
			$columns['tix_reservation'] = __( 'Reservation', 'camptix' );

		$columns['tix_ticket_price'] = __( 'Ticket Price', 'camptix' );
		$columns['tix_order_total'] = __( 'Order Total', 'camptix' );

		$date = $columns['date'];
		unset( $columns['date'] );

		$columns['date'] = $date;
		return $columns;
	}

	/**
	 * Manage columns action for attendee post type.
	 */
	function manage_columns_attendee_action( $column, $post_id ) {
		switch ( $column ) {
			case 'tix_ticket':
				$ticket_id = intval( get_post_meta( $post_id, 'tix_ticket_id', true ) );
				$ticket = get_post( $ticket_id );
				if ( $ticket ) {
					$attendees_url = get_admin_url( 0, '/edit.php?post_type=tix_attendee' );
					$attendees_url = add_query_arg( 's', 'tix_ticket_id:' . intval( $ticket->ID ), $attendees_url );
					printf( '<a href="%s">%s</a>', esc_url( $attendees_url ), esc_html( $ticket->post_title ) );
				}
				break;
			case 'tix_email':
				echo esc_html( get_post_meta( $post_id, 'tix_email', true ) );
				break;
			case 'tix_coupon':
				$coupon_id = get_post_meta( $post_id, 'tix_coupon_id', true );
				if ( $coupon_id ) {
					$coupon = get_post_meta( $post_id, 'tix_coupon', true );
					$attendees_url = get_admin_url( 0, '/edit.php?post_type=tix_attendee' );
					$attendees_url = add_query_arg( 's', 'tix_coupon_id:' . intval( $coupon_id ), $attendees_url );
					printf( '<a href="%s">%s</a>', esc_url( $attendees_url ), esc_html( $coupon ) );
				}
				break;
			case 'tix_reservation':
				$reservation_id = get_post_meta( $post_id, 'tix_reservation_id', true );
				echo esc_html( $reservation_id );
				break;
			case 'tix_order_total':
				$order_total = (float) get_post_meta( $post_id, 'tix_order_total', true );
				echo $this->append_currency( $order_total );
				break;
			case 'tix_ticket_price':
				$ticket_price = (float) get_post_meta( $post_id, 'tix_ticket_price', true );
				echo $this->append_currency( $ticket_price );
				break;
		}

		/*if ( substr( $column, 0, 6 ) == 'tix_q_' ) {
			$answers = (array) get_post_meta( $post_id, 'tix_questions', true );

			$md5_answers = array();
			foreach ( $answers as $key => $value )
				$md5_answers[md5($key)] = $value;

			$key = substr( $column, 6 );
			if ( isset( $md5_answers[$key] ) ) {
				if ( is_array( $md5_answers[$key] ) )
					$md5_answers[$key] = implode( ', ', (array) $md5_answers[$key] );
				echo esc_html( $md5_answers[$key] );
			}
		}*/
	}

	/**
	 * Manage columns filter for coupon post type.
	 */
	function manage_columns_coupon_filter( $columns ) {
		$columns['tix_quantity'] = __( 'Quantity', 'camptix' );
		$columns['tix_used'] = __( 'Used', 'camptix' );
		$columns['tix_remaining'] = __( 'Remaining', 'camptix' );
		$columns['tix_discount'] = __( 'Discount', 'camptix' );
		$columns['tix_availability'] = __( 'Availability', 'camptix' );
		$columns['tix_tickets'] = __( 'Tickets', 'camptix' );

		$date = $columns['date'];
		unset( $columns['date'] );
		$columns['date'] = $date;
		return $columns;
	}

	/**
	 * Manage coulumns action for coupon post type.
	 */
	function manage_columns_coupon_action( $column, $post_id ) {
		switch ( $column ) {
			case 'tix_quantity':
				echo intval( get_post_meta( $post_id, 'tix_coupon_quantity', true ) );
				break;
			case 'tix_used':
				$attendees_url = get_admin_url( 0, '/edit.php?post_type=tix_attendee' );
				$attendees_url = add_query_arg( 's', 'tix_coupon_id:' . intval( $post_id ), $attendees_url );
				printf( '<a href="%s">%d</a>', esc_url( $attendees_url ), $this->get_used_coupons_count( $post_id ) );
				break;
			case 'tix_remaining':
				echo (int) $this->get_remaining_coupons( $post_id );
				break;
			case 'tix_discount':
				$discount_price = (float) get_post_meta( $post_id, 'tix_discount_price', true );
				$discount_percent = (int) get_post_meta( $post_id, 'tix_discount_percent', true );
				if ( $discount_price > 0 ) {
					echo $this->append_currency( $discount_price );
				} elseif ( $discount_percent > 0 ) {
					echo $discount_percent . '%';
				}
				break;
			case 'tix_tickets':
				$tickets = array();
				$applies_to = get_post_meta( $post_id, 'tix_applies_to' );
				foreach ( $applies_to as $ticket_id )
					if ( $this->is_ticket_valid_for_display( $ticket_id ) )
						edit_post_link( $this->get_ticket_title( $ticket_id ), '', '<br />', $ticket_id );
				break;
			case 'tix_availability':
				$start = get_post_meta( $post_id, 'tix_coupon_start', true );
				$end = get_post_meta( $post_id, 'tix_coupon_end', true );

				if ( ! $start && ! $end ) {
					echo __( 'Auto', 'camptix' );
				} else {
					// translators: 1: "from" date, 2: "to" date
					printf( __( '%1$s &mdash; %2$s', 'camptix' ), $start, $end );
				}

				break;
		}
	}

	/**
	 * Manage columns filter for email post type.
	 */
	function manage_columns_email_filter( $columns ) {
		$columns['tix_sent'] = __( 'Sent', 'camptix' );
		$columns['tix_remaining'] = __( 'Remaining', 'camptix' );
		$columns['tix_total'] = __( 'Total', 'camptix' );
		$date = $columns['date'];
		unset( $columns['date'] );
		$columns['date'] = $date;
		return $columns;
	}

	/**
	 * Manage columns action for email post type.
	 */
	function manage_columns_email_action( $column, $post_id ) {
		switch ( $column ) {
			case 'tix_sent':
				$recipients_backup = get_post_meta( $post_id, 'tix_email_recipients_backup', true );
				$recipients_remaining = (array) get_post_meta( $post_id, 'tix_email_recipient_id' );
				echo count( $recipients_backup ) - count( $recipients_remaining );
				break;
			case 'tix_remaining':
				$recipients_remaining = (array) get_post_meta( $post_id, 'tix_email_recipient_id' );
				echo count( $recipients_remaining );
				break;
			case 'tix_total':
				$recipients_backup = get_post_meta( $post_id, 'tix_email_recipients_backup', true );
				echo count( $recipients_backup );
				break;
		}
	}

	/**
	 * Hooked to load-edit.php, adds user options for hidden columns if absent.
	 */
	function update_hidden_columns() {
		if ( ! empty( $_REQUEST['post_type' ] ) && ! in_array( $_REQUEST['post_type'], array( 'tix_attendee', 'tix_ticket' ) ) )
			return;

		$user = wp_get_current_user();

		// If first time editing, disable advanced items by default.
		if ( false === get_user_option( 'manageedit-tix_attendeecolumnshidden' ) ) {
			update_user_option( $user->ID, 'manageedit-tix_attendeecolumnshidden', array(
				'tix_order_total',
				'tix_ticket_price',
				'tix_reservation',
				'tix_coupon',
			), true );
		}

		if ( false === get_user_option( 'manageedit-tix_ticketcolumnshidden' ) ) {
			update_user_option( $user->ID, 'manageedit-tix_ticketcolumnshidden', array(
				'tix_purchase_count',
				'tix_reserved',
			), true );
		}
	}

	/**
	 * Get all questions. Returns an assoc array where the key is a
	 * sanitized questions (as stored in the database) and the value is
	 * the question array.
	 */
	function get_all_questions() {
		$output = array();
		$tickets = get_posts( array(
			'posts_per_page' => -1,
			'post_type' => 'tix_ticket',
			'post_status' => 'publish',
		) );

		foreach ( $tickets as $ticket ) {
			$questions = $this->get_sorted_questions( $ticket->ID );
			foreach ( $questions as $question )
				$output[sanitize_title_with_dashes($question['field'])] = $question;
		}

		return $output;
	}

	/**
	 * Takes a ticket id and returns a sorted array of questions.
	 */
	function get_sorted_questions( $ticket_ID ) {
		$questions = (array) get_post_meta( $ticket_ID, 'tix_question' );
		usort( $questions, array( $this, 'usort_by_order' ) );
		return $questions;
	}

	/**
	 * Fired during init, registers our new post types. $supports depends
	 * on $this->debug, which if true, renders things like custom fields.
	 */
	function register_post_types() {
		$supports = array( 'title', 'excerpt' );
		if ( $this->debug && current_user_can( $this->caps['manage_options'] ) )
			$supports[] = 'custom-fields';

		register_post_type( 'tix_ticket', array(
			'labels' => array(
				'name' => __( 'Tickets', 'camptix' ),
				'singular_name' => __( 'Ticket', 'camptix' ),
				'add_new' => __( 'New Ticket', 'camptix' ),
				'add_new_item' => __( 'Add New Ticket', 'camptix' ),
				'edit_item' => __( 'Edit Ticket', 'camptix' ),
				'new_item' => __( 'New Ticket', 'camptix' ),
				'all_items' => __( 'Tickets', 'camptix' ),
				'view_item' => __( 'View Ticket', 'camptix' ),
				'search_items' => __( 'Search Tickets', 'camptix' ),
				'not_found' => __( 'No tickets found', 'camptix' ),
				'not_found_in_trash' => __( 'No tickets found in trash', 'camptix' ),
				'menu_name' => __( 'Tickets', 'camptix' ),
			),
			'public' => false,
			'query_var' => false,
			'publicly_queryable' => false,
			'show_ui' => true,
			'show_in_menu' => true,
			'supports' => $supports,
			'capability_type' => 'tix_ticket',
			'capabilities' => array(
				'publish_posts' => $this->caps['manage_tickets'],
				'edit_posts' => $this->caps['manage_tickets'],
				'edit_others_posts' => $this->caps['manage_tickets'],
				'delete_posts' => $this->caps['manage_tickets'],
				'delete_others_posts' => $this->caps['manage_tickets'],
				'read_private_posts' => $this->caps['manage_tickets'],
				'edit_post' => $this->caps['manage_tickets'],
				'delete_post' => $this->caps['manage_tickets'],
				'read_post' => $this->caps['manage_tickets'],
			),
		) );

		$supports = array( 'title' );
		if ( $this->debug && current_user_can( $this->caps['manage_options'] ) ) {
			$supports[] = 'custom-fields';
			$supports[] = 'editor';
		}

		register_post_type( 'tix_attendee', array(
			'labels' => array(
				'name' => __( 'Attendees', 'camptix' ),
				'singular_name' => __( 'Attendee', 'camptix' ),
				'add_new' => __( 'New Attendee', 'camptix' ),
				'add_new_item' => __( 'Add New Attendee', 'camptix' ),
				'edit_item' => __( 'Edit Attendee', 'camptix' ),
				'new_item' => __( 'Add Attendee', 'camptix' ),
				'all_items' => __( 'Attendees', 'camptix' ),
				'view_item' => __( 'View Attendee', 'camptix' ),
				'search_items' => __( 'Search Attendees', 'camptix' ),
				'not_found' => __( 'No attendees found', 'camptix' ),
				'not_found_in_trash' => __( 'No attendees found in trash', 'camptix' ),
				'menu_name' => __( 'Attendees', 'camptix' ),
			),
			'public' => false,
			'query_var' => false,
			'publicly_queryable' => false,
			'show_ui' => true,
			'show_in_menu' => 'edit.php?post_type=tix_ticket',
			'supports' => $supports,
			'capability_type' => 'tix_attendee',
			'capabilities' => array(
				'publish_posts' => $this->caps['manage_attendees'],
				'edit_posts' => $this->caps['manage_attendees'],
				'edit_others_posts' => $this->caps['manage_attendees'],
				'delete_posts' => $this->caps['delete_attendees'],
				'delete_others_posts' => $this->caps['delete_attendees'],
				'read_private_posts' => $this->caps['manage_attendees'],
				'edit_post' => $this->caps['manage_attendees'],
				'delete_post' => $this->caps['delete_attendees'],
				'read_post' => $this->caps['manage_attendees'],
			),
		) );

		$supports = array( 'title' );
		if ( $this->debug && current_user_can( $this->caps['manage_options'] ) )
			$supports[] = 'custom-fields';

		register_post_type( 'tix_coupon', array(
			'labels' => array(
				'name' => __( 'Coupons', 'camptix' ),
				'singular_name' => __( 'Coupon', 'camptix' ),
				'add_new' => __( 'New Coupon', 'camptix' ),
				'add_new_item' => __( 'Add New Coupon', 'camptix' ),
				'edit_item' => __( 'Edit Coupon', 'camptix' ),
				'new_item' => __( 'New Coupon', 'camptix' ),
				'all_items' => __( 'Coupons', 'camptix' ),
				'view_item' => __( 'View Coupon', 'camptix' ),
				'search_items' => __( 'Search Coupons', 'camptix' ),
				'not_found' => __( 'No coupons found', 'camptix' ),
				'not_found_in_trash' => __( 'No coupons found in trash', 'camptix' ),
				'menu_name' => __( 'Coupons', 'camptix' ),
			),
			'public' => false,
			'query_var' => false,
			'publicly_queryable' => false,
			'show_ui' => true,
			'show_in_menu' => 'edit.php?post_type=tix_ticket',
			'supports' => $supports,
			'capability_type' => 'tix_coupon',
			'capabilities' => array(
				'publish_posts' => $this->caps['manage_coupons'],
				'edit_posts' => $this->caps['manage_coupons'],
				'edit_others_posts' => $this->caps['manage_coupons'],
				'delete_posts' => $this->caps['manage_coupons'],
				'delete_others_posts' => $this->caps['manage_coupons'],
				'read_private_posts' => $this->caps['manage_coupons'],
				'edit_post' => $this->caps['manage_coupons'],
				'delete_post' => $this->caps['manage_coupons'],
				'read_post' => $this->caps['manage_coupons'],
			),
		) );

		// tix_email will store e-mail jobs.
		register_post_type( 'tix_email', array(
			'labels' => array(
				'name' => __( 'E-mails', 'camptix' ),
				'singular_name' => __( 'E-mail', 'camptix' ),
				'add_new' => __( 'New E-mail', 'camptix' ),
				'add_new_item' => __( 'Add New E-mail', 'camptix' ),
				'edit_item' => __( 'Edit E-mail', 'camptix' ),
				'new_item' => __( 'New E-mail', 'camptix' ),
				'all_items' => __( 'E-mails', 'camptix' ),
				'view_item' => __( 'View E-mail', 'camptix' ),
				'search_items' => __( 'Search E-mails', 'camptix' ),
				'not_found' => __( 'No e-mails found', 'camptix' ),
				'not_found_in_trash' => __( 'No e-mails found in trash', 'camptix' ),
				'menu_name' => __( 'E-mails (debug)', 'camptix' ),
			),
			'public' => false,
			'query_var' => false,
			'publicly_queryable' => false,
			'show_ui' => ( $this->debug && current_user_can( $this->caps['manage_options'] ) ),
			'show_in_menu' => ( $this->debug && current_user_can( $this->caps['manage_options'] ) ) ? 'edit.php?post_type=tix_ticket' : false,
			'supports' => array( 'title', 'editor', 'custom-fields' ),
		) );
	}

	function register_post_statuses() {
		register_post_status( 'cancel', array(
			'label'                     => _x( 'Cancelled', 'post', 'camptix' ),
			'label_count'               => _nx_noop( 'Cancelled <span class="count">(%s)</span>', 'Cancelled <span class="count">(%s)</span>', 'camptix' ),
			'public' => false,
			'protected' => true,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
		) );

		register_post_status( 'failed', array(
			'label'                     => _x( 'Failed', 'post', 'camptix' ),
			'label_count'               => _nx_noop( 'Failed <span class="count">(%s)</span>', 'Failed <span class="count">(%s)</span>', 'camptix' ),
			'public' => false,
			'protected' => true,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
		) );

		register_post_status( 'timeout', array(
			'label'                     => _x( 'Timeout', 'post', 'camptix' ),
			'label_count'               => _nx_noop( 'Timeout <span class="count">(%s)</span>', 'Timeout <span class="count">(%s)</span>', 'camptix' ),
			'public' => false,
			'protected' => true,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
		) );

		register_post_status( 'refund', array(
			'label'                     => _x( 'Refunded', 'post', 'camptix' ),
			'label_count'               => _nx_noop( 'Refunded <span class="count">(%s)</span>', 'Refunded <span class="count">(%s)</span>', 'camptix' ),
			'public' => false,
			'protected' => true,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
		) );

		add_filter( 'display_post_states', array( $this, 'display_post_states' ) );
	}

	function display_post_states( $states ) {
		global $post;

		if ( $post->post_status == 'timeout' && get_query_var( 'post_status' ) != 'timeout' )
			$states['timeout'] = __( 'Timeout', 'camptix' );

		if ( $post->post_status == 'failed' && get_query_var( 'post_status' ) != 'failed' )
			$states['failed'] = __( 'Failed', 'camptix' );

		if ( $post->post_status == 'cancel' && get_query_var( 'post_status' ) != 'cancel' )
			$states['cancelled'] = __( 'Cancelled', 'camptix' );

		if ( $post->post_status == 'refund' && get_query_var( 'post_status' ) != 'refund' )
			$states['cancelled'] = __( 'Refunded', 'camptix' );

		return $states;
	}

	function get_default_options() {
		return apply_filters( 'camptix_default_options', array(
			'currency' => 'USD',
			'event_name' => get_bloginfo( 'name' ),
			'version' => $this->version,
			'reservations_enabled' => false,
			'refunds_enabled' => false,
			'refund_all_enabled' => false,
			'archived' => false,
			'payment_methods' => array(),
		) );
	}

	/**
	 * Returns an array of options stored in the database, or a set of defaults.
	 */
	function get_options() {

		// Allow other plugins to get CampTix options.
		if ( isset( $this->options ) && is_array( $this->options ) && ! empty( $this->options ) )
			return $this->options;

		$default_options = $this->get_default_options();
 		$options = array_merge( $default_options, get_option( 'camptix_options', array() ) );

		// Allow plugins to hi-jack or read the options.
		$options = apply_filters( 'camptix_options', $options );

		/*$options['version'] = 0;
		update_option( 'camptix_options', $options );
		die();/**/

		// Let's see if we need to run an upgrade scenario.
		if ( $options['version'] < $this->version ) {

			// Lock to prevent concurrent upgrades.
			$doing_upgrade = get_option( 'camptix_doing_upgrade', false );

			if ( ! $doing_upgrade ) {
				update_option( 'camptix_doing_upgrade', true );
				$new_version = $this->upgrade( $options['version'] );
				delete_option( 'camptix_doing_upgrade' );

				// Read options again in case of update options.
				$options = array_merge( $default_options, get_option( 'camptix_options', array() ) );
				$options['version'] = $new_version;
				update_option( 'camptix_options', $options );
			}

		}

		if ( current_user_can( $this->caps['manage_options'] ) && isset( $_GET['tix_reset_version'] ) ) {
			$options['version'] = 0;
			update_option( 'camptix_options', $options );
		}

		if ( current_user_can( $this->caps['manage_options'] ) && isset( $_GET['tix_delete_options'] ) ) {
			delete_option( 'camptix_options' );
			$options = $default_options;
		}

		return $options;
	}

	/**
	 * Runs when get_option decides that the current version is out of date.
	 */
	function upgrade( $from ) {

		set_time_limit( 60*60 ); // Give it an hour to update.
		$this->log( 'Running upgrade script.', 0, null, 'upgrade' );

		/**
		 * Payment Methods Upgrade Routine
		 */
		if ( $from < 20120831 ) {
			$start_20120831 = microtime( true );
			$this->log( sprintf( 'Upgrading from %s to %s.', $from, 20120620 ), 0, null, 'upgrade' );

			// Because these run after get_options.
			$this->register_post_types();
			$this->register_post_statuses();

			/**
			 * Update options.
			 */
			$default_options = $this->get_default_options();
	 		$options = array_merge( $default_options, get_option( 'camptix_options', array() ) );

	 		if ( ! isset( $options['payment_options_paypal'] ) )
	 			$options['payment_options_paypal'] = array();

	 		if ( isset( $options['paypal_api_username'] ) )
				$options['payment_options_paypal']['api_username'] = $options['paypal_api_username'];

			if ( isset( $options['paypal_api_password'] ) )
				$options['payment_options_paypal']['api_password'] = $options['paypal_api_password'];

			if ( isset( $options['paypal_api_signature'] ) )
				$options['payment_options_paypal']['api_signature'] = $options['paypal_api_signature'];

			if ( isset( $options['paypal_currency'] ) )
				$options['currency'] = $options['paypal_currency'];

			if ( isset( $options['paypal_statement_subject'] ) )
				$options['event_name'] = $options['paypal_statement_subject'];

			if ( isset( $options['paypal_sandbox'] ) )
				$options['payment_options_paypal']['sandbox'] = (bool) $options['paypal_sandbox'];

			// Enable PayPal payment method by default.
			$options['payment_methods'] = array( 'paypal' => 1 );

			// Disable refunds (beta).
			$options['refunds_enabled'] = false;
			$options['refund_all_enabled'] = false;

			$this->log( 'Going to update options', null, $options, 'upgrade' );

			// Delete old options.
			/*unset( $options['paypal_api_username'] );
			unset( $options['paypal_api_password'] );
			unset( $options['paypal_api_signature'] );
			unset( $options['paypal_currency'] );
			unset( $options['paypal_statement_subject'] );
			unset( $options['paypal_sandbox'] );*/

			update_option( 'camptix_options', $options );

			/**
			 * Since we're going to wp_update_post attendees, we need the save post handler,
			 * which is loaded during init after the upgrade. Don't forget to remove the action
			 * after updating is complete, to avoid multiple actions.
			 */
			add_action( 'save_post', array( $this, 'save_attendee_post' ) );

			$paged = 1; $count = 0;
			while ( $attendees = get_posts( array(
				'post_type' => 'tix_attendee',
				'posts_per_page' => 200,
				'post_status' => array( 'publish', 'pending', 'failed', 'refund' ),
				'paged' => $paged++,
				'orderby' => 'ID',
			) ) ) {

				foreach ( $attendees as $attendee ) {
					$attendee_id = $attendee->ID;

					$transaction_id = get_post_meta( $attendee_id, 'tix_paypal_transaction_id', true );
					update_post_meta( $attendee_id, 'tix_transaction_id', $transaction_id );

					$transaction_details = get_post_meta( $attendee_id, 'tix_paypal_transaction_details', true );
					update_post_meta( $attendee_id, 'tix_transaction_details', array(
						'raw' => $transaction_details,
					) );

					// A dummy payment token. No need for rands because we don't want to mess up payment tokens in the same purchase.
					$access_token = get_post_meta( $attendee_id, 'tix_access_token', true );
					$payment_token = md5( 'payment-token-from-access-' . $access_token );
					update_post_meta( $attendee_id, 'tix_payment_token', $payment_token );

					// Delete old meta keys
					/*delete_post_meta( $attendee_id, 'tix_paypal_transaction_id' );
					delete_post_meta( $attendee_id, 'tix_paypal_transaction_details' );*/

					// Update post for other actions to kick in (and generate searchable content, etc.)
					wp_update_post( $attendee );

					// Delete caches individually rather than clean_post_cache( $attendee_id ),
					// prevents querying for children posts, saves a bunch of queries :)
					wp_cache_delete( $attendee_id, 'posts' );
					wp_cache_delete( $attendee_id, 'post_meta' );

					$count++;
				}

			}

			// Remove save_post action since we finished with wp_update_post.
			remove_action( 'save_post', array( $this, 'save_attendee_post' ) );

			$end_20120831 = microtime( true );
			$this->log( sprintf( 'Updated %d attendees data in %f seconds.', $count, $end_20120831 - $start_20120831 ), null, null, 'upgrade' );
			$from = 20120831;
		}

		$this->log( sprintf( 'Upgrade complete, current version: %s.', $this->version ), 0, null, 'upgrade' );
		return $this->version;
	}

	/**
	 * Runs during admin_init, mainly for Settings API things.
	 */
	function admin_init() {
		register_setting( 'camptix_options', 'camptix_options', array( $this, 'validate_options' ) );

		// Add settings fields
		$this->menu_setup_controls();

		// Let's add some help tabs.
		require_once dirname( __FILE__ ) . '/help.php';
	}

	function menu_setup_controls() {
		wp_enqueue_script( 'jquery-ui' );
		$section = $this->get_setup_section();

		switch ( $section ) {
			case 'general':
				add_settings_section( 'general', __( 'General Configuration', 'camptix' ), array( $this, 'menu_setup_section_general' ), 'camptix_options' );
				$this->add_settings_field_helper( 'event_name', __( 'Event Name', 'camptix' ), 'field_text' );
				$this->add_settings_field_helper( 'currency', __( 'Currency', 'camptix' ), 'field_currency' );
				break;
			case 'payment':
				// add_settings_section( 'general', __( 'Payment Configuration', 'camptix' ), array( $this, 'menu_setup_section_payment' ), 'camptix_options' );
				foreach ( $this->get_available_payment_methods() as $key => $payment_method ) {
					$payment_method_obj = $this->get_payment_method_by_id( $key );

					add_settings_section( 'payment_' . $key, $payment_method_obj->name, array( $payment_method_obj, '_camptix_settings_section_callback' ), 'camptix_options' );
					add_settings_field( 'payment_method_' . $key . '_enabled', __( 'Enabled', 'camptix' ), array( $payment_method_obj, '_camptix_settings_enabled_callback' ), 'camptix_options', 'payment_' . $key, array(
						'name' => "camptix_options[payment_methods][{$key}]",
						'value' => isset( $this->options['payment_methods'][$key] ) ? (bool) $this->options['payment_methods'][$key] : false,
					) );

					$payment_method_obj->payment_settings_fields();
				}
				break;
			case 'beta':

				if ( ! $this->beta_features_enabled )
					break;

				add_settings_section( 'general', __( 'Beta Features', 'camptix' ), array( $this, 'menu_setup_section_beta' ), 'camptix_options' );

				$this->add_settings_field_helper( 'reservations_enabled', __( 'Enable Reservations', 'camptix' ), 'field_yesno', false,
					__( "Reservations is a way to make sure that a certain group of people, can always purchase their tickets, even if you sell out fast.", 'camptix' )
				);

				$this->add_settings_field_helper( 'refunds_enabled', __( 'Enable Refunds', 'camptix' ), 'field_enable_refunds', false,
					__( "This will allows your customers to refund their tickets purchase by filling out a simple refund form.", 'camptix' )
				);

				$this->add_settings_field_helper( 'refund_all_enabled', __( 'Enable Refund All', 'camptix' ), 'field_yesno', false,
					__( "Allows to refund all purchased tickets by an admin via the Tools menu.", 'camptix' )
				);
				$this->add_settings_field_helper( 'archived', __( 'Archived Event', 'camptix' ), 'field_yesno', false,
					__( "Archived events are read-only.", 'camptix' )
				);
				break;
			default:
		}
	}

	function menu_setup_section_beta() {
		echo '<p>' . __( 'Beta features are things that are being worked on in CampTix, but are not quite finished yet. You can try them out, but we do not recommend doing that in a live environment on a real event. If you have any kind of feedback on any of the beta features, please let us know.', 'camptix' ) . '</p>';
	}

	function menu_setup_section_general() {
		echo '<p>' . __( 'General configuration.', 'camptix' ) . '</p>';
	}

	function menu_setup_section_payment() {
		echo '<p>' . __( 'Booyaga' ) . '</p>';
	}

	/**
	 * I don't like repeating code, so here's a helper for simple fields.
	 */
	function add_settings_field_helper( $key, $title, $callback_method, $section = false, $description = false ) {
		if ( ! $section )
			$section = 'general';

		$args = array(
			'name' => sprintf( 'camptix_options[%s]', $key ),
			'value' => $this->options[$key],
		);

		if ( $description )
			$args['description'] = $description;

		add_settings_field( $key, $title, array( $this, $callback_method ), 'camptix_options', $section, $args );
	}

	/**
	 * Validates options in Tickets > Setup.
	 * @todo actually validate please
	 */
	function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['event_name'] ) )
			$output['event_name'] = sanitize_text_field( $input['event_name'] );

		if ( isset( $input['currency'] ) )
			$output['currency'] = $input['currency'];

		$yesno_fields = array(
			// 'paypal_sandbox',
		);

		// Beta features checkboxes
		if ( $this->beta_features_enabled )
			$yesno_fields = array_merge( $yesno_fields, $this->get_beta_features() );

		foreach ( $yesno_fields as $field )
		if ( isset( $input[$field] ) )
			$output[$field] = (bool) $input[$field];

		if ( isset( $input['refunds_date_end'], $input['refunds_enabled'] ) && (bool) $input['refunds_enabled'] && strtotime( $input['refunds_date_end'] ) )
			$output['refunds_date_end'] = $input['refunds_date_end'];

		if ( isset( $input['version'] ) )
			$output['version'] = $input['version'];

		// Enabled payment methods.
		if ( isset( $input['payment_methods'] ) ) {
			foreach ( $this->get_available_payment_methods() as $key => $method )
				if ( isset( $input['payment_methods'][ $key ] ) )
					$output['payment_methods'][ $key ] = (bool) $input['payment_methods'][ $key ];
		}

		$current_user = wp_get_current_user();
		$log_data = array(
			'old' => $this->options,
			'new' => $output,
			'username' => $current_user->user_login,
		);
		$this->log( 'Options updated.', 0, $log_data );

		$output = apply_filters( 'camptix_validate_options', $output );

		return $output;
	}

	function get_beta_features() {
		return array(
			'reservations_enabled',
			'refunds_enabled',
			'refund_all_enabled',
			'archived',
		);
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

	function field_enable_refunds( $args ) {
		$refunds_enabled = (bool) $this->options['refunds_enabled'];
		$refunds_date_end = isset( $this->options['refunds_date_end'] ) && strtotime( $this->options['refunds_date_end'] ) ? $this->options['refunds_date_end'] : date( 'Y-m-d' );
		?>
		<div id="tix-refunds-enabled-radios">
			<label class="tix-yes-no description"><input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="1" <?php checked( $args['value'], true ); ?>> <?php _e( 'Yes', 'camptix' ); ?></label>
			<label class="tix-yes-no description"><input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="0" <?php checked( $args['value'], false ); ?>> <?php _e( 'No', 'camptix' ); ?></label>
		</div>

		<div id="tix-refunds-date" class="<?php if ( ! $refunds_enabled ) echo 'hide-if-js'; ?>" style="margin: 20px 0;">
			<label><?php _e( 'Allow refunds until:', 'camptix' ); ?></label>
			<input type="text" name="camptix_options[refunds_date_end]" value="<?php echo esc_attr( $refunds_date_end ); ?>" class="tix-date-field" />
		</div>

		<?php if ( isset( $args['description'] ) ) : ?>
		<p class="description"><?php echo $args['description']; ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * The currency field for the Settings API.
	 */
	function field_currency( $args ) {
		?>
		<select name="<?php echo esc_attr( $args['name'] ); ?>">
			<?php foreach ( $this->get_currencies() as $key => $currency ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $args['value'] ); ?>><?php
					echo esc_html( $currency['label'] );
					echo " (" . esc_html( $this->append_currency( 10000, true, $key ) ) . ")";
				?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php _e( 'Make sure you select a currency that is supported by all the payment methods you plan to use.', 'camptix' ); ?></p>
		<?php
	}

	/**
	 * Get available currencies. Returns an assoc array of currencies
	 * where the key is the 3-character ISO-4217 currency code, and the
	 * value is an assoc array with a currency label and format.
	 * @link http://goo.gl/Gp0ri (paypal currency codes)
	 */
	function get_currencies() {
		return apply_filters( 'camptix_currencies', array(
			'USD' => array(
				'label' => __( 'U.S. Dollar', 'camptix' ),
				'format' => '$ %s',
			),
			'EUR' => array(
				'label' => __( 'Euro', 'camptix' ),
				'format' => '€ %s',
			),
			'CAD' => array(
				'label' => __( 'Canadian Dollar', 'camptix' ),
				'format' => 'CAD %s',
			),
			'NOK' => array(
				'label' => __( 'Norwegian Krone', 'camptix' ),
				'format' => 'NOK %s',
			),
			'PLN' => array(
				'label' => __( 'Polish Zloty', 'camptix' ),
				'format' => 'PLN %s',
			),
			'JPY' => array(
				'label' => __( 'Japanese Yen', 'camptix' ),
				'format' => 'JPY %s',
			),
			'GBP' => array(
				'label' => __( 'Pound Sterling', 'camptix' ),
				'format' => '£ %s',
			),
			'ILS' => array(
				'label' => __( 'Israeli New Sheqel', 'camptix' ),
				'format' => '&#8362; %s',
			),
		) );
	}

	/**
	 * Give me a price and I'll format it according to the set currency for
	 * display. Don't send my output anywhere but the screen, because I will
	 * print &nbsp; and other things.
	 */
	function append_currency( $price, $nbsp = true, $currency_key = false ) {
		$currencies = $this->get_currencies();
		$currency = $currencies[$this->options['currency']];
		if ( $currency_key )
			$currency = $currencies[$currency_key];

		if ( ! $currency )
			$currency = array( 'label' => __( 'U.S. Dollar', 'camptix' ), 'format' => '$ %s' );

		$with_currency = sprintf( $currency['format'], number_format( (float) $price, 2 ) );
		if ( $nbsp )
			$with_currency = str_replace( ' ', '&nbsp;', $with_currency );

		return $with_currency;
	}

	/**
	 * Oh the holy admin menu!
	 * @todo find out why New Coupon renders Tickets as the current menu item.
	 */
	function admin_menu() {
		add_submenu_page( 'edit.php?post_type=tix_ticket', __( 'Tools', 'camptix' ), __( 'Tools', 'camptix' ), $this->caps['manage_tools'], 'camptix_tools', array( $this, 'menu_tools' ) );
		add_submenu_page( 'edit.php?post_type=tix_ticket', __( 'Setup', 'camptix' ), __( 'Setup', 'camptix' ), $this->caps['manage_options'], 'camptix_options', array( $this, 'menu_setup' ) );
		remove_submenu_page( 'edit.php?post_type=tix_ticket', 'post-new.php?post_type=tix_ticket' );
	}

	/**
	 * When squeezing several custom post types under one top-level menu item, WordPress
	 * tends to get confused which menu item is currently active, especially around post-new.php.
	 * This function runs during admin_head and hacks into some of the global variables that are
	 * used to construct the menu.
	 */
	function admin_menu_fix() {
		global $self, $parent_file, $submenu_file, $plugin_page, $pagenow, $typenow;

		// Make sure Coupons is selected when adding a new coupon
		if ( 'post-new.php' == $pagenow && 'tix_coupon' == $typenow )
			$submenu_file = 'edit.php?post_type=tix_coupon';

		// Make sure Attendees is selected when adding a new attendee
		if ( 'post-new.php' == $pagenow && 'tix_attendee' == $typenow )
			$submenu_file = 'edit.php?post_type=tix_attendee';

		// Make sure Tickets is selected when creating a new ticket
		if ( 'post-new.php' == $pagenow && 'tix_ticket' == $typenow )
			$submenu_file = 'edit.php?post_type=tix_ticket';
	}

	/**
	 * Runs during admin_head, outputs some icons CSS.
	 */
	function admin_head() {
		$icons_url = plugins_url( 'images/icons.png', __FILE__ );
		?>
		<style>
			#adminmenu #menu-posts-tix_ticket .wp-menu-image {
				background-image: url('<?php echo esc_url( $icons_url ); ?>');
				background-position: 0px 0px;
				background-size: 196px 168px;
			}
			#adminmenu #menu-posts-tix_ticket:hover .wp-menu-image,
			#adminmenu #menu-posts-tix_ticket.wp-has-current-submenu .wp-menu-image {
				background-position: 0px -56px;
			}

			@media only screen and (-webkit-min-device-pixel-ratio: 1.5) {
				#adminmenu #menu-posts-tix_ticket .wp-menu-image {
					background-position: -14px 0;
					background-size: 98px 84px;
				}
				#adminmenu #menu-posts-tix_ticket:hover .wp-menu-image,
				#adminmenu #menu-posts-tix_ticket.wp-has-current-submenu .wp-menu-image {
					background-position: -14px -56px;
				}
			}
		</style>
		<?php
	}

	/**
	 * The Tickets > Setup screen, uses the Settings API.
	 */
	function menu_setup() {
		?>
		<div class="wrap">
			<?php screen_icon(); ?>
			<h2><?php _e( 'CampTix Setup', 'camptix' ); ?></h2>
			<?php settings_errors(); ?>
			<h3 class="nav-tab-wrapper"><?php $this->menu_setup_tabs(); ?></h3>
			<form method="post" action="options.php" class="tix-setup-form">
				<?php
					settings_fields( 'camptix_options' );
					do_settings_sections( 'camptix_options' );
					submit_button();
				?>
			</form>
			<?php if ( $this->debug ) : ?>
			<pre><?php
				print_r( $this->options );
				printf( __( 'Current time on server: %s', 'camptix' ) . PHP_EOL, date( 'r' ) );
				print_r( get_option( 'camptix_stats' ) );
			?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Remember the tabs in Tickets > Tools? This tells
	 * us which tab is currently active.
	 */
	function get_setup_section() {
		if ( isset( $_REQUEST['tix_section'] ) )
			return strtolower( $_REQUEST['tix_section'] );

		return 'general';
	}

	/**
	 * Tabs for Tickets > Tools, outputs the markup.
	 */
	function menu_setup_tabs() {
		$current_section = $this->get_setup_section();
		$sections = array(
			'general' => __( 'General', 'camptix' ),
			'payment' => __( 'Payment', 'camptix' ),
		);

		if ( $this->beta_features_enabled )
			$sections['beta'] = __( 'Beta', 'camptix' );

		foreach ( $sections as $section_key => $section_caption ) {
			$active = $current_section === $section_key ? 'nav-tab-active' : '';
			$url = add_query_arg( 'tix_section', $section_key );
			echo '<a class="nav-tab ' . $active . '" href="' . esc_url( $url ) . '">' . esc_html( $section_caption ) . '</a>';
		}
	}

	/**
	 * The Tickets > Tools screen, doesn't use the settings API, but does use tabs.
	 */
	function menu_tools() {
		?>
		<div class="wrap">
			<?php screen_icon( 'tools' ); ?>
			<h2><?php _e( 'CampTix Tools', 'camptix' ); ?></h2>
			<?php settings_errors(); ?>
			<h3 class="nav-tab-wrapper"><?php $this->menu_tools_tabs(); ?></h3>
			<?php
				$section = $this->get_tools_section();
				if ( $section == 'summarize' )
					$this->menu_tools_summarize();
				elseif ( $section == 'revenue' )
					$this->menu_tools_revenue();
				elseif ( $section == 'export' )
					$this->menu_tools_export();
				elseif ( $section == 'notify' )
					$this->menu_tools_notify();
				elseif ( $section == 'refund' && ! $this->options['archived'] )
					$this->menu_tools_refund();
			?>
		</div>
		<?php
	}

	/**
	 * Remember the tabs in Tickets > Tools? This tells
	 * us which tab is currently active.
	 */
	function get_tools_section() {
		if ( isset( $_REQUEST['tix_section'] ) )
			return strtolower( $_REQUEST['tix_section'] );

		return 'summarize';
	}

	/**
	 * Tabs for Tickets > Tools, outputs the markup.
	 */
	function menu_tools_tabs() {
		$current_section = $this->get_tools_section();
		$sections = array(
			'summarize' => __( 'Summarize', 'camptix' ),
			'revenue' => __( 'Revenue', 'camptix' ),
			'export' => __( 'Export', 'camptix' ),
			'notify' => __( 'Notify', 'camptix' ),
		);

		if ( current_user_can( $this->caps['manage_options'] ) && ! $this->options['archived'] && $this->options['refund_all_enabled'] )
			$sections['refund'] = __( 'Refund', 'camptix' );

		foreach ( $sections as $section_key => $section_caption ) {
			$active = $current_section === $section_key ? 'nav-tab-active' : '';
			$url = add_query_arg( 'tix_section', $section_key );
			echo '<a class="nav-tab ' . $active . '" href="' . esc_url( $url ) . '">' . esc_html( $section_caption ) . '</a>';
		}
	}

	/**
	 * Tools > Summarize, the screen that outputs the summary tables,
	 * provides an export option, powered by the summarize_admin_init method,
	 * hooked (almost) at admin_init, because of additional headers. Doesn't use
	 * the Settings API so check for nonces/referrers and caps.
	 * @see summarize_admin_init()
	 */
	function menu_tools_summarize() {
		$summarize_by = isset( $_POST['tix_summarize_by'] ) ? $_POST['tix_summarize_by'] : 'ticket';
		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'tix_summarize', 1 ) ); ?>">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php _e( 'Summarize by', 'camptix' ); ?></th>
						<td>
							<select name="tix_summarize_by">
								<?php foreach ( $this->get_available_summary_fields() as $value => $caption ) : ?>
									<?php
										if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) )
											$caption = mb_strlen( $caption ) > 30 ? mb_substr( $caption, 0, 30 ) . '...' : $caption;
										else
											$caption = strlen( $caption ) > 30 ? substr( $caption, 0, 30 ) . '...' : $caption;
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $summarize_by ); ?>><?php echo esc_html( $caption ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<?php wp_nonce_field( 'tix_summarize' ); ?>
				<input type="hidden" name="tix_summarize_submit" value="1" />
				<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Show Summary', 'camptix' ); ?>" />
				<input type="submit" name="tix_export_summary" value="<?php esc_attr_e( 'Export Summary to CSV', 'camptix' ); ?>" class="button" />
			</p>
		</form>

		<?php if ( isset( $_POST['tix_summarize_submit'] ) && check_admin_referer( 'tix_summarize' ) && array_key_exists( $summarize_by, $this->get_available_summary_fields() ) ) : ?>
		<?php
			$fields = $this->get_available_summary_fields();
			$summary = $this->get_summary( $summarize_by );
			$summary_title = $fields[$summarize_by];
			$alt = '';

			$rows = array();
			foreach ( $summary as $entry )
				$rows[] = array(
					esc_html( $summary_title ) => esc_html( $entry['label'] ),
					__( 'Count', 'camptix' ) => esc_html( $entry['count'] )
				);

			// Render the widefat table.
			$this->table( $rows, 'widefat tix-summarize' );
		?>

		<?php endif; // summarize_submit ?>
		<?php
	}

	/**
	 * Hooked at (almost) admin_init, fired if one requested a
	 * Summarize export. Serves the download file.
	 * @see menu_tools_summarize()
	 */
	function summarize_admin_init() {
		if ( ! current_user_can( $this->caps['manage_tools'] ) || 'summarize' != $this->get_tools_section() )
			return;

		if ( isset( $_POST['tix_export_summary'], $_POST['tix_summarize_by'] ) && check_admin_referer( 'tix_summarize' ) ) {
			$summarize_by = $_POST['tix_summarize_by'];
			if ( ! array_key_exists( $summarize_by, $this->get_available_summary_fields() ) )
				return;

			$fields = $this->get_available_summary_fields();
			$summary = $this->get_summary( $summarize_by );
			$summary_title = $fields[$summarize_by];
			$filename = sprintf( 'camptix-summary-%s-%s.csv', sanitize_title_with_dashes( $summary_title ), date( 'Y-m-d' ) );

			header( 'Content-Type: text/csv' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( "Cache-control: private" );
			header( 'Pragma: private' );
			header( "Expires: Mon, 26 Jul 1997 05:00:00 GMT" );

			$stream = fopen( "php://output", 'w' );

			fputcsv( $stream, array( $summary_title, __( 'Count', 'camptix' ) ) );
			foreach ( $summary as $entry )
				fputcsv( $stream, $entry, ',', '"' );

			fclose( $stream );
			die();
		}
	}

	/**
	 * Returns a summary of all attendees. A lot of @magic here and
	 * watch out for actions and filters.
	 * @see increment_summary(), get_available_summary_fields()
	 */
	function get_summary( $summarize_by = 'ticket' ) {
		global $post;

		$summary = array();
		if ( ! array_key_exists( $summarize_by, $this->get_available_summary_fields() ) )
			return $summary;

		$paged = 1;
		while ( $attendees = get_posts( array(
			'post_type' => 'tix_attendee',
			'post_status' => array( 'publish', 'pending' ),
			'posts_per_page' => 200,
			'paged' => $paged++,
			'orderby' => 'ID',
			'order' => 'ASC',
			'cache_results' => false, // no caching
		) ) ) {

			$attendee_ids = array();
			foreach ( $attendees as $attendee )
				$attendee_ids[] = $attendee->ID;

			/**
			 * Magic here, to by-pass object caching. See Revenue report for more info.
			 */
			$this->filter_post_meta = $this->prepare_metadata_for( $attendee_ids );
			unset( $attendee_ids, $attendee );

			foreach ( $attendees as $attendee ) {

				if ( $summarize_by == 'ticket' ) {
					$ticket_id = get_post_meta( $attendee->ID, 'tix_ticket_id', true );
					if ( $this->is_ticket_valid_for_display( $ticket_id ) ) {
						$ticket = get_post( $ticket_id );
						$this->increment_summary( $summary, $ticket->post_title );
					} else {
						$this->increment_summary( $summary, 'None' );
					}
				} elseif ( $summarize_by == 'purchase_date' ) {
					$date = mysql2date( 'F, jS Y', $attendee->post_date );
					$this->increment_summary( $summary, $date );
				} elseif ( $summarize_by == 'purchase_time' ) {
					$date = mysql2date( 'H:00', $attendee->post_date );
					$this->increment_summary( $summary, $date );
				} elseif ( $summarize_by == 'purchase_datetime' ) {
					$date = mysql2date( 'F, jS Y \a\t H:00', $attendee->post_date );
					$this->increment_summary( $summary, $date );
				} elseif ( $summarize_by == 'purchase_dayofweek' ) {
					$date = mysql2date( 'l', $attendee->post_date );
					$this->increment_summary( $summary, $date );
				} elseif ( $summarize_by == 'coupon' ) {
					$coupon = get_post_meta( $attendee->ID, 'tix_coupon', true );
					if ( ! $coupon )
						$coupon = __( 'None', 'camptix' );
					$this->increment_summary( $summary, $coupon );
				} else {

					// Let other folks summarize too.
					do_action_ref_array( 'camptix_summarize_by_' . $summarize_by, array( &$summary, $attendee ) );
					do_action_ref_array( 'camptix_summarize_by_field', array( $summarize_by, &$summary, $attendee ) );
				}
			}
		}

		// Sort the summary by count.
		uasort( $summary, array( $this, 'usort_by_count' ) );
		return $summary;
	}

	/**
	 * Returns an array of available Summarize reports.
	 */
	function get_available_summary_fields() {
		return apply_filters( 'camptix_summary_fields', array(
			'ticket' => __( 'Ticket type', 'camptix' ),
			'coupon' => __( 'Coupon code', 'camptix' ),
			'purchase_date' => __( 'Purchase date', 'camptix' ),
			'purchase_time' => __( 'Purchase time', 'camptix' ),
			'purchase_datetime' => __( 'Purchase date and time', 'camptix' ),
			'purchase_dayofweek' => __( 'Purchase day of week', 'camptix' ),
		) );
	}

	/**
	 * Some more magic here.
	 * @see get_summary
	 * @todo let outsiders use this.
	 * @warning $summary is passed byref.
	 */
	function increment_summary( &$summary, $label ) {

		// For checkboxes
		if ( is_array( $label ) )
			$label = implode( ', ', (array) $label );

		$key = 'tix_' . md5( $label );
		if ( isset( $summary[$key] ) )
			$summary[$key]['count']++;
		else
			$summary[$key] = array( 'label' => $label, 'count' => 1 );
	}

	/**
	 * Updates a stats value.
	 */
	function update_stats( $key, $value ) {
		$stats = get_option( 'camptix_stats', array() );
		$stats[$key] = $value;
		update_option( 'camptix_stats', $stats );
		return;
	}

	/**
	 * Increments a stats value.
	 */
	function increment_stats( $key, $step = 1 ) {
		$stats = get_option( 'camptix_stats', array() );
		if ( ! isset( $stats[$key] ) )
			$stats[$key] = 0;

		$stats[$key] += $step;
		update_option( 'camptix_stats', $stats );
		return;
	}

	/**
	 * Returns an existing stats value or zero.
	 */
	function get_stats( $key ) {
		$stats = get_option( 'camptix_stats', array() );
		if ( isset( $stats[ $key ] ) )
			return $stats[ $key ];

		return 0;
	}

	/**
	 * Runs during any post status transition. Mainly used to increment
	 * stats for better network reporting.
	 */
	function transition_post_status( $new, $old, $post ) {

		// Just in case.
		if ( $new == $old )
			return;

		if ( $post->post_type == 'tix_attendee' ) {

			$multiplier = 0;

			// Publish or pending was set
			if ( $new == 'publish' || $new == 'pending' )
				if ( $old != 'publish' && $old != 'pending' )
					$multiplier = 1;

			// Publish or pending was removed
			if ( $old == 'publish' || $old == 'pending' )
				if ( $new != 'publish' && $new != 'pending' )
					$multiplier = -1;

			if ( $multiplier != 0 ) {
				$this->increment_stats( 'sold', 1 * $multiplier );
				$this->increment_stats( 'remaining', -1 * $multiplier );

				$price = (float) get_post_meta( $post->ID, 'tix_ticket_price', true );
				$discounted_price = (float) get_post_meta( $post->ID, 'tix_ticket_discounted_price', true );
				$discounted = $price - $discounted_price;

				$this->increment_stats( 'subtotal', $price * $multiplier );
				$this->increment_stats( 'discounted', $discounted * $multiplier );
				$this->increment_stats( 'revenue', $discounted_price * $multiplier );

				// Bust page/object cache to get accurate remaining counts
				$this->flush_tickets_page();
			}
		}
	}

	/**
	 * Returns a (huge) array of metadata for passed in object IDs. Use with caution.
	 */
	function prepare_metadata_for( $ids_array ) {
		global $wpdb;

		$object_ids = array_map( 'intval', $ids_array );
		$id_list = join( ',', $object_ids );
		$table = _get_meta_table( 'post' );
		$meta_list = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM $table WHERE post_id IN ( $id_list )" ) );
		$metadata = array();
		foreach ( $meta_list as $row )
			$metadata[$row->post_id][$row->meta_key][] = $row->meta_value;

		unset( $meta_list, $id_list, $object_ids, $ids_array );
		return $metadata;
	}

	/**
	 * Filters on get_post_metadata, checks $this->filter_post_meta for object ID and if
	 * it exists will serve the result from the array (or false) to by-pass object caching.
	 */
	function get_post_metadata( $return, $object_id, $meta_key, $single ) {
		if ( isset( $this->filter_post_meta ) && isset( $this->filter_post_meta[$object_id] ) ) {
			$meta = $this->filter_post_meta[$object_id];
			if ( isset( $meta[$meta_key] ) ) {
				$meta = $meta[$meta_key];

				if ( $single )
					return array( 0 => maybe_unserialize( $meta[0] ) );
				else
					return array_map( 'maybe_unserialize', $meta );
			}
			return false;
		}
		return $return;
	}

	function menu_tools_revenue() {
		global $post;

		$start_time = microtime( true );

		$tickets = array();
		$totals = new stdClass;
		$totals->sold = 0;
		$totals->remaining = 0;
		$totals->sub_total = 0;
		$totals->discounted = 0;
		$totals->revenue = 0;

		// This will hold all our transactions.
		$transactions = array();

		$tickets_query = new WP_Query( array(
			'post_type' => 'tix_ticket',
			'posts_per_page' => -1,
			'post_status' => 'any',
		) );

		while ( $tickets_query->have_posts() ) {
			$tickets_query->the_post();
			$post->tix_price = get_post_meta( $post->ID, 'tix_price', true );
			$post->tix_remaining = $this->get_remaining_tickets( $post->ID );
			$post->tix_sold_count = 0;
			$post->tix_discounted = 0;
			$tickets[$post->ID] = $post;
		}

		$paged = 1;
		while ( $attendees = get_posts( array(
			'post_type' => 'tix_attendee',
			'posts_per_page' => 200,
			'post_status' => array( 'publish', 'pending' ),
			'paged' => $paged++,
			'fields' => 'ids', // ! no post objects
			'orderby' => 'ID',
			'order' => 'ASC',
			'cache_results' => false, // no caching
		) ) ) {

			/**
			 * TL;DR: Use prepare_metadata_for to preload meta, set $this->filter_post_meta = false; when done.
			 *
			 * Let's talk about performance. As seen from the get_posts query above, we definitely
			 * don't want to cache any of our attendees for this loop, nor do we want to put them into
			 * object cache and delete them soon afterwards, which works, but not when a persistent
			 * object caching plugin is active. We don't want to waste 5000 memcached puts and 5000
			 * memcached deletes. So, wanna see a magic trick? If $this->filter_post_meta is set to an
			 * array, it'll activate the get_post_metadata filter which will look for the requested metadata
			 * in that array and never touch the database or object cache. We use $this->prepare_metadata_for( $attendees )
			 * to preload that data from the database with an SQL query, again, by-passing any sort of object caching.
			 * Future calls to get_post_meta with a post ID that is present in $this->filter_post_meta will use that
			 * short circuit. Don't forget to clean up with $this->filter_post_meta = false; when you're done.
			 */
			$this->filter_post_meta = $this->prepare_metadata_for( $attendees );

			foreach ( $attendees as $attendee_id ) {

				$ticket_id = get_post_meta( $attendee_id, 'tix_ticket_id', true );
				if ( isset( $tickets[$ticket_id] ) ) {
					$tickets[$ticket_id]->tix_sold_count++;

					$order_total = (float) get_post_meta( $attendee_id, 'tix_order_total', true );
					$txn = get_post_meta( $attendee_id, 'tix_transaction_id', true );
					if ( ! empty( $txn ) && ! isset( $transactions[$txn] ) )
						$transactions[$txn] = $order_total;

					$coupon_id = get_post_meta( $attendee_id, 'tix_coupon_id', true );
					if ( $coupon_id ) {
						$discount_price = get_post_meta( $coupon_id, 'tix_discount_price', true );
						$discount_percent = get_post_meta( $coupon_id, 'tix_discount_percent', true );
						if ( $discount_price > 0 ) {
							if ( $discount_price > $tickets[$ticket_id]->tix_price )
								$discount_price = $tickets[$ticket_id]->tix_price;

							$tickets[$ticket_id]->tix_discounted += $discount_price;
						} elseif ( $discount_percent > 0 ) {
							$original = $tickets[$ticket_id]->tix_price;
							$discounted = $tickets[$ticket_id]->tix_price - ( $tickets[$ticket_id]->tix_price * $discount_percent / 100 );
							$discounted = $original - $discounted;
							$tickets[$ticket_id]->tix_discounted += $discounted;
						}
					}
				}

				// Commented out because we're not doing any caching.
				// Delete caches individually rather than clean_post_cache( $attendee_id ),
				// prevents querying for children posts, saves a bunch of queries :)
				// wp_cache_delete( $attendee_id, 'posts' );
				// wp_cache_delete( $attendee_id, 'post_meta' );
			}

			// Clear prepared metadata.
			$this->filter_post_meta = false;
		}

		$actual_total = array_sum( $transactions );
		unset( $transactions, $attendees );

		$rows = array();
		foreach ( $tickets as $ticket ) {
			$totals->sold += $ticket->tix_sold_count;
			$totals->discounted += $ticket->tix_discounted;
			$totals->sub_total += $ticket->tix_sold_count * $ticket->tix_price;
			$totals->revenue += $ticket->tix_sold_count * $ticket->tix_price - $ticket->tix_discounted;
			$totals->remaining += $ticket->tix_remaining;

			$rows[] = array(
				__( 'Ticket type', 'camptix' ) => esc_html( $ticket->post_title ),
				__( 'Sold', 'camptix' ) => $ticket->tix_sold_count,
				__( 'Remaining', 'camptix' ) => $ticket->tix_remaining,
				__( 'Sub-Total', 'camptix' ) => $this->append_currency( $ticket->tix_sold_count * $ticket->tix_price ),
				__( 'Discounted', 'camptix' ) => $this->append_currency( $ticket->tix_discounted ),
				__( 'Revenue', 'camptix' ) => $this->append_currency( $ticket->tix_sold_count * $ticket->tix_price - $ticket->tix_discounted ),
			);
		}
		$rows[] = array(
			__( 'Ticket type', 'camptix' ) => 'Total',
			__( 'Sold', 'camptix' ) => $totals->sold,
			__( 'Remaining', 'camptix' ) => $totals->remaining,
			__( 'Sub-Total', 'camptix' ) => $this->append_currency( $totals->sub_total ),
			__( 'Discounted', 'camptix' ) => $this->append_currency( $totals->discounted ),
			__( 'Revenue', 'camptix' ) => $this->append_currency( $totals->revenue ),
		);

		if ( $totals->revenue != $actual_total ) {
			printf( '<div class="updated settings-error below-h2"><p>%s</p></div>', sprintf( __( '<strong>Woah!</strong> The revenue total does not match with the transactions total. The actual total is: <strong>%s</strong>. Something somewhere has gone wrong, please report this.', 'camptix' ), $this->append_currency( $actual_total ) ) );
		}

		$this->table( $rows, 'widefat tix-revenue-summary' );
		printf( '<p><span class="description">' . __( 'Revenue report generated in %s seconds.', 'camptix' ) . '</span></p>', number_format( microtime( true ) - $start_time, 3 ) );

		// Update stats
		$this->update_stats( 'sold', $totals->sold );
		$this->update_stats( 'remaining', $totals->remaining );
		$this->update_stats( 'subtotal', $totals->sub_total );
		$this->update_stats( 'discounted', $totals->discounted );
		$this->update_stats( 'revenue', $totals->revenue );
	}

	/**
	 * Export tools menu, nothing funky here.
	 * @see export_admin_init()
	 */
	function menu_tools_export() {
		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'tix_export', 1 ) ); ?>">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php _e( 'Export all attendees data to', 'camptix' ); ?></th>
						<td>
							<select name="tix_export_to">
								<option value="csv">CSV</option>
								<option value="xml">XML</option>
								<option disabled="disabled" value="pdf">PDF <?php _e( '(coming soon)', 'camptix' ); ?></option>
							</select>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<?php wp_nonce_field( 'tix_export' ); ?>
				<input type="hidden" name="tix_export_submit" value="1" />
				<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Export', 'camptix' ); ?>" />
			</p>
		</form>
		<?php
	}

	/**
	 * Fired at almost admin_init, used to serve the export download file.
	 * @see menu_tools_export()
	 */
	function export_admin_init() {
		global $post;

		if ( ! current_user_can( $this->caps['manage_tools'] ) || 'export' != $this->get_tools_section() )
			return;

		if ( isset( $_POST['tix_export_submit'], $_POST['tix_export_to'] ) && check_admin_referer( 'tix_export' ) ) {

			$format = strtolower( trim( $_POST['tix_export_to'] ) );
			if ( ! in_array( $format, array( 'xml', 'csv' ) ) ) {
				add_settings_error( 'tix', 'error', __( 'Format not supported.', 'camptix' ), 'error' );
				return;
			}

			$time_start = microtime( true );

			$content_types = array(
				'xml' => 'text/xml',
				'csv' => 'text/csv',
			);

			$filename = sprintf( 'camptix-export-%s.%s', date( 'Y-m-d' ), $format );
			$questions = $this->get_all_questions();

			header( 'Content-Type: ' . $content_types[$format] );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( "Cache-control: private" );
			header( 'Pragma: private' );
			header( "Expires: Mon, 26 Jul 1997 05:00:00 GMT" );

			$columns = array(
				'id' => __( 'Attendee ID', 'camptix' ),
				'ticket' => __( 'Ticket Type', 'camptix' ),
				'first_name' => __( 'First Name', 'camptix' ),
				'last_name' => __( 'Last Name', 'camptix' ),
				'email' => __( 'E-mail Address', 'camptix' ),
				'date' => __( 'Purchase date', 'camptix' ),
				'status' => __( 'Status', 'camptix' ),
				'txn_id' => __( 'Transaction ID', 'camptix' ),
				'coupon' => __( 'Coupon', 'camptix' ),
			);
			foreach ( $questions as $key => $question )
				$columns['tix_q_' . $key] = $question['field'];

			if ( 'csv' == $format ) {
				$stream = fopen( "php://output", 'w' );
				fputcsv( $stream, $columns );
			}

			if ( 'xml' == $format )
				echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL . '<attendees>' . PHP_EOL;

			$paged = 1;
			while ( $attendees = get_posts( array(
				'post_type' => 'tix_attendee',
				'post_status' => array( 'publish', 'pending' ),
				'posts_per_page' => 200,
				'paged' => $paged++,
				'orderby' => 'ID',
				'order' => 'ASC',
				'cache_results' => false,
			) ) ) {

				$attendee_ids = array();
				foreach ( $attendees as $attendee )
					$attendee_ids[] = $attendee->ID;

				/**
				 * Magic here, to by-pass object caching. See Revenue report for more info.
				 */
				$this->filter_post_meta = $this->prepare_metadata_for( $attendee_ids );
				unset( $attendee_ids, $attendee );

				foreach ( $attendees as $attendee ) {
					$attendee_id = $attendee->ID;

					$line = array(
						'id' => $attendee_id,
						'ticket' => $this->get_ticket_title( intval( get_post_meta( $attendee_id, 'tix_ticket_id', true ) ) ),
						'first_name' => get_post_meta( $attendee_id, 'tix_first_name', true ),
						'last_name' => get_post_meta( $attendee_id, 'tix_last_name', true ),
						'email' => get_post_meta( $attendee_id, 'tix_email', true ),
						'date' => mysql2date( 'Y-m-d', $attendee->post_date ),
						'status' => ucfirst( $attendee->post_status ),
						'txn_id' => get_post_meta( $attendee_id, 'tix_transaction_id', true ),
						'coupon' => get_post_meta( $attendee_id, 'tix_coupon', true ),
					);

					$answers = (array) get_post_meta( $attendee_id, 'tix_questions', true );

					foreach ( $questions as $key => $question ) {

						// For multiple checkboxes
						if ( isset( $answers[$key] ) && is_array( $answers[$key] ) )
							$answers[$key] = implode( ', ', (array) $answers[$key] );

						$line['tix_q_' . $key] = ( isset( $answers[$key] ) ) ? $answers[$key] : '';
					}

					// Make sure every column is printed.
					$clean_line = array();
					foreach ( $columns as $key => $caption )
						$clean_line[$key] = isset( $line[$key] ) ? $line[$key] : '';

					if ( 'csv' == $format )
						fputcsv( $stream, $clean_line );

					if ( 'xml' == $format ) {
						echo "\t<attendee>" . PHP_EOL;
						foreach ( $clean_line as $tag => $value ) {
							printf( "\t\t<%s>%s</%s>" . PHP_EOL, $tag, esc_html( $value ), $tag );
						}
						echo "\t</attendee>" . PHP_EOL;
					}

					// The following was commented out because object caching was disabled with filter_post_meta.
					// Delete caches individually rather than clean_post_cache( $attendee_id ),
					// prevents querying for children posts, saves a bunch of queries :)
					// wp_cache_delete( $attendee_id, 'posts' );
					// wp_cache_delete( $attendee_id, 'post_meta' );
				}

				/**
				 * Don't forget to clear up the used meta sort-of cache.
				 */
				$this->filter_post_meta = false;
			}

			if ( 'csv' == $format )
				fclose( $stream );

			if ( 'xml' == $format )
				echo '</attendees>';

			$this->log( sprintf( 'Finished %s data export in %s seconds.', $format, microtime(true) - $time_start ) );
			die();
		}
	}

	/**
	 * Notify tools menu, allows to create, preview and send an e-mail
	 * to all attendees. See also: notify shortcodes.
	 */
	function menu_tools_notify() {
		global $post, $shortcode_tags;

		// Use this array to store existing form data.
		$form_data = array(
			'subject' => '',
			'body' => '',
			'tickets' => array(),
		);

		if ( isset( $_POST['tix_notify_attendees'] ) && check_admin_referer( 'tix_notify_attendees' ) ) {
			$errors = array();

			// Error handling.
			if ( empty( $_POST['tix_notify_subject'] ) )
				$errors[] = __( 'Please enter a subject line.', 'camptix' );

			if ( empty( $_POST['tix_notify_body'] ) )
				$errors[] = __( 'Please enter the e-mail body.', 'camptix' );

			if ( ! isset( $_POST['tix_notify_tickets'] ) || count( (array) $_POST['tix_notify_tickets'] ) < 1 )
				$errors[] = __( 'Please select at least one ticket group.', 'camptix' );

			// If everything went well.
			if ( count( $errors ) == 0 && isset( $_POST['tix_notify_submit'] ) && $_POST['tix_notify_submit'] ) {
				$tickets = (array) $_POST['tix_notify_tickets'];
				$subject = $_POST['tix_notify_subject'];
				$body = $_POST['tix_notify_body'];
				$recipients = array();

				$paged = 1;
				while ( $attendees = get_posts( array(
					'post_type' => 'tix_attendee',
					'posts_per_page' => 200,
					'post_status' => array( 'publish', 'pending' ),
					'paged' => $paged++,
					'fields' => 'ids', // ! no post objects
					'orderby' => 'ID',
					'order' => 'ASC',
					'cache_results' => false, // no caching
				) ) ) {

					// Disables object caching, see Revenue report for details.
					$this->filter_post_meta = $this->prepare_metadata_for( $attendees );

					foreach ( $attendees as $attendee_id )
						if ( array_key_exists( get_post_meta( $attendee_id, 'tix_ticket_id', true ), $tickets ) )
							$recipients[] = $attendee_id;

					// Enable object caching for post meta back on.
					$this->filter_post_meta = false;
				}

				unset( $attendees );

				// Create a new e-mail job.
				$email_id = wp_insert_post( array(
					'post_type' => 'tix_email',
					'post_status' => 'pending',
					'post_title' => $subject,
					'post_content' => $body,
				) );

				// Add recipients as post meta.
				if ( $email_id ) {
					add_settings_error( 'camptix', 'none', sprintf( __( 'Your e-mail job has been queued for %s recipients.', 'camptix' ), count( $recipients ) ), 'updated' );
					$this->log( sprintf( 'Created e-mail job with %s recipients.', count( $recipients ) ), $email_id, null, 'notify' );

					foreach ( $recipients as $recipient_id )
						add_post_meta( $email_id, 'tix_email_recipient_id', $recipient_id );

					update_post_meta( $email_id, 'tix_email_recipients_backup', $recipients ); // for logging purposes
					unset( $recipients );
				}
			} else { // errors or preview

				if ( count( $errors ) > 0 )
					foreach ( $errors as $error )
						add_settings_error( 'camptix', false, $error );

				// Keep form data.
				$form_data['subject'] = stripslashes( $_POST['tix_notify_subject'] );
				$form_data['body'] = stripslashes( $_POST['tix_notify_body'] );
				if ( isset( $_POST['tix_notify_tickets'] ) )
					$form_data['tickets'] = $_POST['tix_notify_tickets'];
			}
		}
		?>
		<?php settings_errors( 'camptix' ); ?>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'tix_notify_attendees', 1 ) ); ?>">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php _e( 'To', 'camptix' ); ?></th>
						<td>
							<?php
								$tickets_query = new WP_Query( array(
									'post_type' => 'tix_ticket',
									'post_status' => 'any',
									'posts_per_page' => -1,
								) );
							?>
							<?php while ( $tickets_query->have_posts() ) : $tickets_query->the_post(); ?>
							<label><input type="checkbox" <?php checked( array_key_exists( get_the_ID(), $form_data['tickets'] ) ); ?> name="tix_notify_tickets[<?php the_ID(); ?>]" value="1" /> <?php the_title(); ?></label><br />
							<?php endwhile; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e( 'Subject', 'camptix' ); ?></th>
						<td>
							<input type="text" name="tix_notify_subject" value="<?php echo esc_attr( $form_data['subject'] ); ?>" class="large-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e( 'Message', 'camptix' ); ?></th>
						<td>
							<textarea rows="10" name="tix_notify_body" id="tix-notify-body" class="large-text"><?php echo esc_textarea( $form_data['body'] ); ?></textarea><br />
							<?php do_action( 'camptix_init_notify_shortcodes' ); ?>
							<?php if ( ! empty( $shortcode_tags ) ) : ?>
							<p class=""><?php _e( 'You can use the following shortcodes:', 'camptix' ); ?>
								<?php foreach ( $shortcode_tags as $key => $tag ) : ?>
								<a href="#" class="tix-notify-shortcode"><code>[<?php echo esc_html( $key ); ?>]</code></a>
								<?php endforeach; ?>
							</p>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( isset( $_POST['tix_notify_preview'], $form_data ) ) : ?>
					<?php
						$attendees_ids = get_posts( array(
							'post_type' => 'tix_attendee',
							'post_status' => array( 'publish', 'pending' ),
							'posts_per_page' => 1,
							'orderby' => 'rand',
							'fields' => 'ids',
						) );

						if ( $attendees_ids )
							$this->notify_shortcodes_attendee_id = array_shift( $attendees_ids );

						$subject = do_shortcode( $form_data['subject'] );
						$content = do_shortcode( $form_data['body'] );

						unset( $this->notify_shortcodes_attendee_id );
					?>
					<tr>
						<th scope="row">Preview</th>
						<td>
							<div id="tix-notify-preview">
								<p><strong><?php echo esc_html( $subject ); ?></strong></p>
								<p style="margin-bottom: 0;"><?php echo nl2br( esc_html( $content ) ); ?></p>
							</div>
						</td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<p class="submit">
				<?php wp_nonce_field( 'tix_notify_attendees' ); ?>
				<input type="hidden" name="tix_notify_attendees" value="1" />

				<div style="position: absolute; left: -9999px;">
					<?php /* Hit Preview, not Send, if the form is submitted with Enter. */ ?>
					<?php submit_button( __( 'Preview', 'camptix' ), 'button', 'tix_notify_preview', false ); ?>
				</div>
				<?php submit_button( __( 'Send E-mails', 'camptix' ), 'primary', 'tix_notify_submit', false ); ?>
				<?php submit_button( __( 'Preview', 'camptix' ), 'button', 'tix_notify_preview', false ); ?>
			</p>
		</form>

		<?php
		$history_query = new WP_Query( array(
			'post_type' => 'tix_email',
			'post_status' => 'any',
			'posts_per_page' => -1,
			'order' => 'ASC',
		) );

		if ( $history_query->have_posts() ) {
			echo '<h3>' . __( 'History', 'camptix' ) . '</h3>';
			$rows = array();
			while ( $history_query->have_posts() ) {
				$history_query->the_post();
				$rows[] = array(
					__( 'Subject', 'camptix' ) => get_the_title(),
					__( 'Updated', 'camptix' ) => sprintf( __( '%1$s at %2$s', 'camptix' ), get_the_date(), get_the_time() ),
					__( 'Author', 'camptix' ) => get_the_author(),
					__( 'Status', 'camptix' ) => $post->post_status,
				);
			}
			$this->table( $rows, 'widefat tix-email-history' );
		}
	}

	function menu_tools_refund() {
		if ( ! $this->options['refund_all_enabled'] )
			return;

		if ( get_option( 'camptix_doing_refunds', false ) )
			return $this->menu_tools_refund_busy();
		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'tix_refund_all', 1 ) ); ?>">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php _e( 'Refund all transactions', 'camptix' ); ?></th>
						<td>
							<label><input name="tix_refund_checkbox_1" value="1" type="checkbox" /> <?php _e( 'Refund all transactions', 'camptix' ); ?></label><br />
							<label><input name="tix_refund_checkbox_2" value="1" type="checkbox" /> <?php _e( 'Seriously, refund them all', 'camptix' ); ?></label><br />
							<label><input name="tix_refund_checkbox_3" value="1" type="checkbox" /> <?php _e( "I know what I'm doing, please refund", 'camptix' ); ?></label><br />
							<label><input name="tix_refund_checkbox_4" value="1" type="checkbox" /> <?php _e( 'I know this may result in money loss, refund anyway', 'camptix' ); ?></label><br />
							<label><input name="tix_refund_checkbox_5" value="1" type="checkbox" /> <?php _e( 'I will not blame Konstantin if something goes wrong', 'camptix' ); ?></label><br />
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<?php wp_nonce_field( 'tix_refund_all' ); ?>
				<input type="hidden" name="tix_refund_all_submit" value="1" />
				<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Refund Transactions', 'camptix' ); ?>" />
			</p>
		</form>
		<?php
	}

	/**
	 * Runs before the page markup is printed so can add settings errors.
	 */
	function menu_tools_refund_admin_init() {
		if ( ! current_user_can( $this->caps['manage_tools'] ) || 'refund' != $this->get_tools_section() )
			return;

		if ( ! isset( $_POST['tix_refund_all_submit'] ) )
			return;

		check_admin_referer( 'tix_refund_all' );

		$checkboxes = array(
			'tix_refund_checkbox_1',
			'tix_refund_checkbox_2',
			'tix_refund_checkbox_3',
			'tix_refund_checkbox_4',
			'tix_refund_checkbox_5',
		);

		foreach ( $checkboxes as $checkbox ) {
			if ( ! isset( $_POST[$checkbox] ) || $_POST[$checkbox] != '1' ) {
				add_settings_error( 'camptix', 'none', __( 'Looks like you have missed a checkbox or two. Try again!', 'camptix' ), 'error' );
				return;
			}
		}

		$current_user = wp_get_current_user();
		$this->log( sprintf( 'Setting all transactions to refund, thanks %s.', $current_user->user_login ), 0, null, 'refund' );
		update_option( 'camptix_doing_refunds', true );

		$count = 0;
		$paged = 1;
		while ( $attendees = get_posts( array(
			'post_type' => 'tix_attendee',
			'posts_per_page' => 200,
			'post_status' => array( 'publish' ),
			'paged' => $paged++,
			'orderby' => 'ID',
			'fields' => 'ids',
			'order' => 'ASC',
			'cache_results' => 'false',
		) ) ) {

			// Mark attendee for refund
			foreach ( $attendees as $attendee_id ) {
				update_post_meta( $attendee_id, 'tix_pending_refund', 1 );
				$this->log( sprintf( 'Attendee set to refund by %s', $current_user->user_login ), $attendee_id, null, 'refund' );
				$count++;
			}
		}

		add_settings_error( 'camptix', 'none', sprintf( __( 'A refund job has been queued for %d attendees.', 'camptix' ), $count ), 'updated' );
	}

	/**
	 * Runs on Refund tab if a refund job is in progress.
	 */
	function menu_tools_refund_busy() {
		$query = new WP_Query( array(
			'post_type' => 'tix_attendee',
			'posts_per_page' => 1,
			'post_status' => array( 'publish' ),
			'orderby' => 'ID',
			'order' => 'ASC',
			'meta_query' => array(
				array(
					'key' => 'tix_pending_refund',
					'compare' => '=',
					'value' => 1,
				),
			),
		) );
		$found_posts = $query->found_posts;
		?>
		<p><?php printf( __( 'A refund job is in progress, with %d attendees left in the queue. Next run in %d seconds.', 'camptix' ), $found_posts, wp_next_scheduled( 'tix_scheduled_every_ten_minutes' ) - time() ); ?></p>
		<?php
	}

	/**
	 * Runs by WP_Cron, refunds attendees set to refund.
	 * @todo do :)
	 */
	function process_refund_all() {
		die( 'needs implementation' );
		if ( $this->options['archived'] )
			return;

		if ( ! get_option( 'camptix_doing_refunds', false ) )
			return;

		$attendees = get_posts( array(
			'post_type' => 'tix_attendee',
			'posts_per_page' => 50,
			'post_status' => array( 'publish' ),
			'orderby' => 'ID',
			'order' => 'DESC',
			'meta_query' => array(
				array(
					'key' => 'tix_pending_refund',
					'compare' => '=',
					'value' => 1,
				),
			),
		) );

		if ( ! $attendees ) {
			$this->log( 'Refund all job complete.', 0, null, 'refund' );
			delete_option( 'camptix_doing_refunds' );
		}

		foreach ( $attendees as $attendee ) {
			// If another cron instace has this, or same txn has been refunded.
			if ( ! get_post_meta( $attendee->ID, 'tix_pending_refund', true ) )
				continue;

			delete_post_meta( $attendee->ID, 'tix_pending_refund' );
			$transaction_id = get_post_meta( $attendee->ID, 'tix_transaction_id', true );

			if ( $transaction_id && ! empty( $transaction_id ) && trim( $transaction_id ) ) {

				// Related attendees have the same transaction id, we'll use this query to find them.
				$rel_attendees_query = array(
					'post_type' => 'tix_attendee',
					'posts_per_page' => 50,
					'post_status' => array( 'publish' ),
					'orderby' => 'ID',
					'order' => 'DESC',
					'post__not_in' => array( $attendee->ID ),
					'meta_query' => array(
						array(
							'key' => 'tix_pending_refund',
							'compare' => '=',
							'value' => 1,
						),
						array(
							'key' => 'tix_transaction_id',
							'compare' => '=',
							'value' => $transaction_id,
						),
					),
				);

				$payload = array(
					'METHOD' => 'RefundTransaction',
					'TRANSACTIONID' => $transaction_id,
					'REFUNDTYPE' => 'Full',
				);

				// Tell PayPal to refund our transaction.
				$txn = wp_parse_args( wp_remote_retrieve_body( $this->paypal_request( $payload ) ) );
				if ( isset( $txn['ACK'], $txn['REFUNDTRANSACTIONID'] ) && $txn['ACK'] == 'Success' ) {
					$this->log( sprintf( 'Refunded transaction %s.', $transaction_id ), $attendee->ID, $txn, 'refund' );
					$attendee->post_status = 'refund';
					wp_update_post( $attendee );

					// Remove refund flag and set status to refunded for related attendees.
					while ( $rel_attendees = get_posts( $rel_attendees_query ) ) {
						foreach ( $rel_attendees as $rel_attendee ) {
							$this->log( sprintf( 'Refunded transaction %s.', $transaction_id ), $rel_attendee->ID, $txn, 'refund' );
							delete_post_meta( $rel_attendee->ID, 'tix_pending_refund' );
							$rel_attendee->post_status = 'refund';
							wp_update_post( $rel_attendee );
							clean_post_cache( $rel_attendee->ID );
						}
					}

				} else {
					$this->log( sprintf( 'Could not refund %s.', $transaction_id ), $attendee->ID, $txn, 'refund' );

					// Let other attendees know they can not be refunded too.
					while ( $rel_attendees = get_posts( $rel_attendees_query ) ) {
						foreach ( $rel_attendees as $rel_attendee ) {
							$this->log( sprintf( 'Could not refund %s.', $transaction_id ), $rel_attendee->ID, $txn, 'refund' );
							delete_post_meta( $rel_attendee->ID, 'tix_pending_refund' );
							clean_post_cache( $rel_attendee->ID );
						}
					}
				}
			} else {
				$this->log( 'No transaction id for this attendee, not refunding.', $attendee->ID, null, 'refund' );
			}
		}
	}

	/**
	 * Adds various new metaboxes around the new post types.
	 */
	function add_meta_boxes() {
		add_meta_box( 'tix_ticket_options', __( 'Ticket Options', 'camptix' ), array( $this, 'metabox_ticket_options' ), 'tix_ticket', 'side' );
		add_meta_box( 'tix_ticket_availability', __( 'Availability', 'camptix' ), array( $this, 'metabox_ticket_availability' ), 'tix_ticket', 'side' );
		add_meta_box( 'tix_ticket_questions', __( 'Questions', 'camptix' ), array( $this, 'metabox_ticket_questions' ), 'tix_ticket' );

		if ( $this->options['reservations_enabled'] )
			add_meta_box( 'tix_ticket_reservations', __( 'Reservations', 'camptix' ), array( $this, 'metabox_ticket_reservations' ), 'tix_ticket' );

		add_meta_box( 'tix_coupon_options', __( 'Coupon Options', 'camptix' ), array( $this, 'metabox_coupon_options' ), 'tix_coupon', 'side' );
		add_meta_box( 'tix_coupon_availability', __( 'Availability', 'camptix' ), array( $this, 'metabox_coupon_availability' ), 'tix_coupon', 'side' );

		add_meta_box( 'tix_attendee_info', __( 'Attendee Information', 'camptix' ), array( $this, 'metabox_attendee_info' ), 'tix_attendee', 'normal' );

		add_meta_box( 'tix_attendee_submitdiv', __( 'Publish', 'camptix' ), array( $this, 'metabox_attendee_submitdiv' ), 'tix_attendee', 'side' );
		remove_meta_box( 'submitdiv', 'tix_attendee', 'side' );

		do_action( 'camptix_add_meta_boxes' );
	}

	function metabox_attendee_submitdiv() {
			global $action, $post;

			$post_type = $post->post_type;
			$post_type_object = get_post_type_object( $post_type );
			$post_status_object = get_post_status_object( $post->post_status );
			$can_publish = current_user_can( $post_type_object->cap->publish_posts );
			$email = get_post_meta( $post->ID, 'tix_email', true );
		?>
		<div class="submitbox" id="submitpost">

			<div id="minor-publishing">
				<div style="display:none;">
				<?php submit_button( __( 'Save', 'camptix' ), 'button', 'save' ); ?>
				</div>

				<div id="misc-publishing-actions">
					<div class="misc-pub-section">
						<div style="text-align: center;">
						<?php echo get_avatar( $email, 100 ); ?>
						</div>
					</div>

					<div class="misc-pub-section">
						<label for="post_status"><?php _e('Status:') ?></label>
						<span id="post-status-display">
							<?php if ( $post_status_object ) : ?>
							<?php echo $post_status_object->label; ?>
							<?php else: ?>
								<?php _e( 'Unknown status', 'camptix' ); ?>
							<?php endif; ?>
						</span>
					</div>

					<?php
					$datef = __( 'M j, Y @ G:i' );
					if ( 0 != $post->ID ) {
						$stamp = __( 'Created: <b>%1$s</b>', 'camptix' );
						$date = date_i18n( $datef, strtotime( $post->post_date ) );
					} else {
						$stamp = __( 'Publish <b>immediately</b>', 'camptix' );
						$date = date_i18n( $datef, strtotime( current_time('mysql') ) );
					}
					?>

					<?php if ( $can_publish ) : ?>
					<div class="misc-pub-section curtime">
						<span id="timestamp"><?php printf( $stamp, $date ); ?></span>
					</div>
					<?php endif; // $can_publish ?>

					<div class="misc-pub-section">
						<?php
							$edit_token = get_post_meta( $post->ID, 'tix_edit_token', true );
							$edit_link = $this->get_edit_attendee_link( $post->ID, $edit_token );
						?>
						<span><a href="<?php echo esc_url( $edit_link ); ?>"><?php _e( 'Edit Attendee Info', 'camptix' ); ?></a></span>
					</div>

				</div><!-- #misc-publishing-actions -->
				<div class="clear"></div>
			</div><!-- #minor-publishing -->

			<div id="major-publishing-actions">
				<div id="delete-action">
				<?php
				if ( current_user_can( 'delete_post', $post->ID ) ) {
					if ( !EMPTY_TRASH_DAYS )
						$delete_text = __( 'Delete Permanently', 'camptix' );
					else
						$delete_text = __( 'Move to Trash', 'camptix' );
					?>
				<a class="submitdelete deletion" href="<?php echo get_delete_post_link( $post->ID ); ?>"><?php echo $delete_text; ?></a><?php
				} ?>
				</div>

				<div id="publishing-action">
					<?php submit_button( __( 'Save Attendee', 'camptix' ), 'primary', 'save', false, array( 'tabindex' => '5', 'accesskey' => 'p' ) ); ?>
				</div>
				<div class="clear"></div>
			</div>
		</div><!-- #submitpost -->
		<?php
	}

	/**
	 * Metabox callback for ticket options.
	 */
	function metabox_ticket_options() {
		$reserved = 0;
		$reservations = $this->get_reservations( get_the_ID() );
		foreach ( $reservations as $reservation_token => $reservation )
			$reserved += $reservation['quantity'] - $this->get_purchased_tickets_count( get_the_ID(), $reservation_token );

		$purchased = $this->get_purchased_tickets_count( get_the_ID() );
		$min_quantity = $reserved + $purchased;
		?>
		<div class="misc-pub-section">
			<span class="left"><?php _e( 'Price:', 'camptix' ); ?></span>
			<?php if ( $purchased <= 0 ) : ?>
			<input type="text" name="tix_price" class="small-text" value="<?php echo esc_attr( number_format( (float) get_post_meta( get_the_ID(), 'tix_price', true ), 2, '.', '' ) ); ?>" autocomplete="off" /> <?php echo esc_html( $this->options['currency'] ); ?>
			<?php else: ?>
			<span><?php echo esc_html( $this->append_currency( get_post_meta( get_the_ID(), 'tix_price', true ) ) ); ?></span><br />
			<p class="description" style="margin-top: 10px;"><?php _e( 'You can not change the price because one or more tickets have already been purchased.', 'camptix' ); ?></p>
			<?php endif; ?>
		</div>
		<div class="misc-pub-section">
			<span class="left"><?php _e( 'Quantity:', 'camptix' ); ?></span>
			<input type="number" min="<?php echo intval( $min_quantity ); ?>" name="tix_quantity" class="small-text" value="<?php echo esc_attr( intval( get_post_meta( get_the_ID(), 'tix_quantity', true ) ) ); ?>" autocomplete="off" />
			<?php if ( $purchased > 0 ) : ?>
			<p class="description" style="margin-top: 10px;"><?php _e( 'You can not set the quantity to less than the number of purchased tickets.', 'camptix' ); ?></p>
			<?php endif; ?>
		</div>
		<div class="clear"></div>
		<?php
	}

	/**
	 * Metabox callback for ticket availability.
	 */
	function metabox_ticket_availability() {
		$start = get_post_meta( get_the_ID(), 'tix_start', true );
		$end = get_post_meta( get_the_ID(), 'tix_end', true );
		?>
		<div class="misc-pub-section curtime">
			<span id="timestamp"><?php _e( 'Leave blank for auto-availability', 'camptix' ); ?></span>
		</div>
		<div class="misc-pub-section">
			<span class="left"><?php _e( 'Start:', 'camptix' ); ?></span>
			<input type="text" name="tix_start" id="tix-date-from" class="regular-text date" value="<?php echo esc_attr( $start ); ?>" />
		</div>
		<div class="misc-pub-section">
			<span class="left"><?php _e( 'End:', 'camptix' ); ?></span>
			<input type="text" name="tix_end" id="tix-date-to" class="regular-text date" value="<?php echo esc_attr( $end ); ?>" />
		</div>
		<div class="clear"></div>
		<?php
	}

	/**
	 * Returns all reservations for all available (published) tickets.
	 */
	function get_all_reservations() {
		$reservations = array();

		if ( ! $this->options['reservations_enabled'] )
			return $reservations;

		$tickets = get_posts( array(
			'post_type' => 'tix_ticket',
			'post_status' => 'publish',
			'posts_per_page' => -1,
		) );

		foreach ( $tickets as $ticket ) {
			$reservations = array_merge( $reservations, $this->get_reservations( $ticket->ID ) );
		}

		return $reservations;
	}

	/**
	 * Returns reservations for one single ticket by id.
	 */
	function get_reservations( $ticket_id ) {
		$reservations = array();

		if ( ! $this->options['reservations_enabled'] )
			return $reservations;

		$meta = (array) get_post_meta( $ticket_id, 'tix_reservation' );
		foreach ( $meta as $reservation )
			if ( isset( $reservation['token'] ) )
				$reservations[$reservation['token']] = $reservation;

		return $reservations;
	}

	/**
	 * Returns one single reservation by token.
	 */
	function get_reservation( $token ) {

		if ( ! $this->options['reservations_enabled'] )
			return false;

		$reservations = $this->get_all_reservations();
		if ( isset( $reservations[$token] ) )
			return $reservations[$token];

		return false;
	}

	/**
	 * Returns a URL, visiting which, one could use a reservation to purchase a ticket.
	 */
	function get_reservation_link( $id, $token ) {
		if ( ! $this->options['reservations_enabled'] )
			return;

		return add_query_arg( array(
			'tix_reservation_id' => $id,
			'tix_reservation_token' => $token,
		), $this->get_tickets_url() ) . '#tix';
	}

	/**
	 * Returns true, if a reservation is valid, and can be used to purchase a ticket.
	 */
	function is_reservation_valid_for_use( $token ) {
		$reservation = $this->get_reservation( $token );
		if ( ! $reservation )
			return false;

		$count = $this->get_purchased_tickets_count( $reservation['ticket_id'], $reservation['token'] );
		if ( $count < $reservation['quantity'] )
			return true;

		return false;
	}

	/**
	 * Renders the Reservations section in the edit ticket screen.
	 */
	function metabox_ticket_reservations() {
		$reservations = $this->get_reservations( get_the_ID() );
		?>

		<?php if ( $reservations ) : ?>
			<div id="postcustomstuff" class="tix-ticket-reservations">
			<table>
				<thead>
				<tr>
					<th><?php _e( 'Name', 'camptix' ); ?></th>
					<th><?php _e( 'Quantity', 'camptix' ); ?></th>
					<th><?php _e( 'Used', 'camptix' ); ?></th>
					<th><?php _e( 'Token', 'camptix' ); ?></th>
					<th><?php _e( 'Actions', 'camptix' ); ?></th>
				</tr>
				</thead>
				<tbody>
			<?php foreach ( $reservations as $reservation ) : ?>
				<tr>
					<td><span><?php echo esc_html( isset( $reservation['name'] ) ? $reservation['name'] : urldecode( $reservation['id'] ) ); ?></span></td>
					<td class="column-quantity"><span><?php echo intval( $reservation['quantity'] ); ?></span></td>
					<td class="column-used"><span><?php echo $this->get_purchased_tickets_count( get_the_ID(), $reservation['token'] ); ?></span></td>
					<td class="column-token"><span><a href="<?php echo esc_url( $this->get_reservation_link( $reservation['id'], $reservation['token'] ) ); ?>"><?php echo $reservation['token']; ?></a></span></td>
					<td class="column-actions"><span>
						<input type="submit" class="button" name="tix_reservation_release[<?php echo $reservation['token']; ?>]" value="<?php esc_attr_e( 'Release', 'camptix' ); ?>" />
						<input type="submit" class="button" name="tix_reservation_cancel[<?php echo $reservation['token']; ?>]" value="<?php esc_attr_e( 'Cancel', 'camptix' ); ?>" />
					</span></td>
				</tr>
			<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>

		<p><strong><?php _e( 'Create a New Reservation:', 'camptix' ); ?></strong></p>
		<p>
			<input type="hidden" name="tix_doing_reservations" value="1" />
			<label><?php _e( 'Reservation Name', 'camptix' ); ?></label>
			<input type="text" name="tix_reservation_name" autocomplete="off" />
			<label><?php _e( 'Quantity', 'camptix' ); ?></label>
			<input type="text" name="tix_reservation_quantity" autocomplete="off" />
			<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Create Reservation', 'camptix' ); ?>" />
		</p>
		<p class="description"><?php _e( "If you create a reservation with more quantity than available by the total ticket quantity, we'll bump the ticket quantity for you.", 'camptix' ); ?></p>
		<?php
	}

	/**
	 * Returns all available ticket types, you can
	 * extend this with filters and actions.
	 */
	function get_question_field_types() {
		return apply_filters( 'camptix_question_field_types', array(
			'text' => __( 'Text input', 'camptix' ),
			'textarea' => __( 'Text area', 'camptix' ),
			'select' => __( 'Dropdown select', 'camptix' ),
			'radio' => __( 'Radio select', 'camptix' ),
			'checkbox' => __( 'Checkbox', 'camptix' ),
		) );
	}

	/**
	 * Runs before question fields are printed, initialize controls actions here.
	 */
	function question_fields_init() {
		add_action( 'camptix_question_field_text', array( $this, 'question_field_text' ), 10, 2 );
		add_action( 'camptix_question_field_select', array( $this, 'question_field_select' ), 10, 3 );
		add_action( 'camptix_question_field_checkbox', array( $this, 'question_field_checkbox' ), 10, 3 );
		add_action( 'camptix_question_field_textarea', array( $this, 'question_field_textarea' ), 10, 2 );
		add_action( 'camptix_question_field_radio', array( $this, 'question_field_radio' ), 10, 3 );
	}

	/**
	 * A text input for a question.
	 */
	function question_field_text( $name, $value ) {
		?>
		<input name="<?php echo esc_attr( $name ); ?>" type="text" value="<?php echo esc_attr( $value ); ?>" />
		<?php
	}

	/**
	 * A drop-down select for a question.
	 */
	function question_field_select( $name, $user_value, $question ) {
		?>
		<select name="<?php echo esc_attr( $name ); ?>" />
			<?php foreach ( (array) $question['values'] as $question_value ) : ?>
				<option <?php selected( $question_value, $user_value ); ?> value="<?php echo esc_attr( $question_value ); ?>"><?php echo esc_html( $question_value ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * A single or multiple checkbox for a question.
	 */
	function question_field_checkbox( $name, $user_value, $question ) {
		?>
		<?php if ( (array) $question['values'] ) : ?>
			<?php foreach ( $question['values'] as $question_value ) : ?>
				<label><input <?php checked( in_array( $question_value, (array) $user_value ) ); ?> name="<?php echo esc_attr( $name ); ?>[<?php echo sanitize_title_with_dashes( $question_value ); ?>]" type="checkbox" value="<?php echo esc_attr( $question_value ); ?>" /> <?php echo esc_html( $question_value ); ?></label><br />
			<?php endforeach; ?>
		<?php else : ?>
			<label><input <?php checked( $user_value, 'Yes' ); ?> name="<?php echo esc_attr( $name ); ?>" type="checkbox" value="Yes" /> <?php _e( 'Yes', 'camptix' ); ?></label>
		<?php endif; ?>
		<?php
	}

	/**
	 * A textarea input for questions.
	 */
	function question_field_textarea( $name, $value ) {
		?>
		<textarea name="<?php echo esc_attr( $name ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
		<?php
	}

	/**
	 * A radio input for questions.
	 */
	function question_field_radio( $name, $user_value, $question ) {
		?>
		<?php foreach ( (array) $question['values'] as $question_value ) : ?>
			<label><input <?php checked( $question_value, $user_value ); ?> name="<?php echo esc_attr( $name ); ?>" type="radio" value="<?php echo esc_attr( $question_value ); ?>" /> <?php echo esc_html( $question_value ); ?></label><br />
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Metabox callback for ticket questions.
	 */
	function metabox_ticket_questions() {
		$types = $this->get_question_field_types();
		?>
		<div class="tix-ticket-questions">
			<div class="tix-ui-sortable">
				<div class="tix-item tix-item-required">
					<div>
						<input type="hidden" class="tix-field-order" value="0" />

						<div class="tix-item-inner-left">
							<span class="tix-field-type"><?php _e( 'Default', 'camptix' ); ?></span>
						</div>
						<div class="tix-item-inner-middle">
							<span class="tix-field-name"><?php _e( 'First name, last name and e-mail address', 'camptix' ); ?></span>
							<span class="tix-field-required-star">*</span>
							<span class="tix-field-values"></span>
						</div>
					</div>
				</div>
				<?php
					$questions = $this->get_sorted_questions( get_the_ID() );
					$i = 0;
				?>
				<?php foreach ( $questions as $question ) : ?>
				<?php
					$i++;
					$is_required = isset( $question['required'] ) && $question['required'] ? true : false;
					$item_class = $is_required ? 'tix-item-required' : '';
				?>
				<div class="tix-item tix-item-sortable <?php echo esc_attr( $item_class ); ?>">
					<div class="tix-item-inner">
						<input type="hidden" class="tix-field-type" name="tix_questions[<?php echo $i; ?>][type]" value="<?php echo esc_attr( $question['type'] ); ?>" />
						<input type="hidden" class="tix-field-name" name="tix_questions[<?php echo $i; ?>][field]" value="<?php echo esc_attr( $question['field'] ); ?>" />
						<input type="hidden" class="tix-field-values" name="tix_questions[<?php echo $i; ?>][values]" value="<?php echo esc_attr( implode( ', ', $question['values'] ) ); ?>" />
						<input type="hidden" class="tix-field-required" name="tix_questions[<?php echo $i; ?>][required]" value="<?php echo intval( $question['required'] ); ?>" />
						<input type="hidden" class="tix-field-order" name="tix_questions[<?php echo $i; ?>][order]" value="<?php echo $i; ?>" />

						<div class="tix-item-inner-left">
							<span class="tix-field-type"><?php echo esc_html( $question['type'] ); ?></span>
						</div>
						<div class="tix-item-inner-right">
							<a href="#" class="tix-item-sort-handle" title="<?php esc_attr_e( 'Move', 'camptix' ); ?>" style="font-size: 27px; position: relative; top: 3px;">&equiv;</a>
							<a href="#" class="tix-item-delete" title="<?php esc_attr_e( 'Delete', 'camptix' ); ?>" style="font-size: 18px;">&times;</a>
						</div>
						<div class="tix-item-inner-middle">
							<span class="tix-field-name"><?php echo esc_html( $question['field'] ); ?></span>
							<span class="tix-field-required-star">*</span>
							<span class="tix-field-values"><?php echo esc_html( implode( ', ', $question['values'] ) ); ?></span>
						</div>
					</div>
					<div class="tix-clear"></div>
				</div>
				<?php endforeach; ?>
			</div>

			<div class="tix-add-question" style="border-top: solid 1px white; background: #f9f9f9;">
				<span id="tix-add-question-action">
					<?php printf( __( 'Add a %1$s or an %2$s.', 'camptix' ),
									sprintf( '<a id="tix-add-question-new" style="font-weight: bold;" href="#">%s</a>', __( 'new question', 'camptix' ) ),
									sprintf( '<a id="tix-add-question-existing" style="font-weight: bold;" href="#">%s</a>', __( 'existing one', 'camptix' ) )
								);
					?>
					</span>
				<div id="tix-add-question-new-form">
					<div class="tix-item tix-item-sortable tix-prototype tix-new">
						<div class="tix-item-inner">
							<input type="hidden" class="tix-field-type" value="" />
							<input type="hidden" class="tix-field-name" value="" />
							<input type="hidden" class="tix-field-values" value="" />
							<input type="hidden" class="tix-field-required" value="" />
							<input type="hidden" class="tix-field-order" value="" />

							<div class="tix-item-inner-left">
								<span class="tix-field-type"><?php _e( 'Type', 'camptix' ); ?></span>
							</div>
							<div class="tix-item-inner-right">
								<a href="#" class="tix-item-sort-handle" title="<?php esc_attr_e( 'Move', 'camptix' ); ?>" style="font-size: 27px; position: relative; top: 3px;">&equiv;</a>
								<a href="#" class="tix-item-delete" title="<?php esc_attr_e( 'Delete', 'camptix' ); ?>" style="font-size: 18px;">&times;</a>
							</div>
							<div class="tix-item-inner-middle">
								<span class="tix-field-name"><?php _e( 'Field', 'camptix' ); ?></span>
								<span class="tix-field-required-star">*</span>
								<span class="tix-field-values"><?php _e( 'Values', 'camptix' ); ?></span>
							</div>
						</div>
						<div class="tix-clear"></div>
					</div>

					<h4 class="title"><?php _e( 'Add a new question:', 'camptix' ); ?></h4>

					<table class="form-table">
						<tr valign="top">
							<th scope="row">
								<label><?php _e( 'Type', 'camptix' ); ?></label>
							</th>
							<td>
								<select id="tix-add-question-type">
									<?php foreach ( $types as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label><?php _e( 'Question', 'camptix' ); ?></label>
							</th>
							<td>
								<input id="tix-add-question-name" class="regular-text" type="text" />
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label><?php _e( 'Values', 'camptix' ); ?></label>
							</th>
							<td>
								<input id="tix-add-question-values" class="regular-text" type="text" />
								<p class="description"><?php _e( 'Separate multiple values with a comma.', 'camptix' ); ?></p>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label><?php _e( 'Required', 'camptix' ); ?></label>
							</th>
							<td>
								<label><input id="tix-add-question-required" type="checkbox" /> <?php _e( 'This field is required', 'camptix' ); ?></label>
							</td>
						</tr>
					</table>
					<p class="submit">
						<a href="#" id="tix-add-question-submit" class="button"><?php _e( 'Add Question', 'camptix' ); ?></a>
						<a href="#" id="tix-add-question-new-form-cancel" class="button"><?php _e( 'Close', 'camptix' ); ?></a>
						<span class="description"><?php _e( 'Do not forget to update the ticket post to save changes.', 'camptix' ); ?></span>
					</p>
				</div>
				<div id="tix-add-question-existing-form">
					<h4 class="title"><?php _e( 'Add an existing question:', 'camptix' ); ?></h4>

					<div class="categorydiv" id="tix-add-question-existing-list">
							<ul id="category-tabs" class="category-tabs">
								<li class="tabs"><?php _e( 'Available Questions', 'camptix' ); ?></li>
							</ul>

							<div class="tabs-panel">
								<ul id="categorychecklist" class="list:category categorychecklist form-no-clear">
									<?php foreach ( $this->get_all_questions() as $question ) : ?>
									<li class="tix-existing-question">
										<label class="selectit">
											<input type="checkbox" class="tix-existing-checkbox">
											<?php echo esc_html( $question['field'] ); ?>

											<input type="hidden" class="tix-field-type" value="<?php echo esc_attr( $question['type'] ); ?>" />
											<input type="hidden" class="tix-field-name" value="<?php echo esc_attr( $question['field'] ); ?>" />
											<input type="hidden" class="tix-field-required" value="<?php echo intval( $question['required'] ); ?>" />
											<input type="hidden" class="tix-field-values" value="<?php echo esc_attr( implode( ', ', $question['values'] ) ); ?>" />
										</label>
									</li>
									<?php endforeach; ?>
								</ul>
							</div>

					</div>

					<p class="submit">
						<a href="#" id="tix-add-question-existing-form-add" class="button"><?php _e( 'Add Selected', 'camptix' ); ?></a>
						<a href="#" id="tix-add-question-existing-form-cancel" class="button"><?php _e( 'Close', 'camptix' ); ?></a>
						<span class="description"><?php _e( 'Do not forget to update the ticket post to save changes.', 'camptix' ); ?></span>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Metabox callback for coupon options.
	 */
	function metabox_coupon_options() {
		global $post, $wp_query;

		// We'll use this to restore post data.
		$original_post = $post;

		$discount_price = number_format( (float) get_post_meta( $post->ID, 'tix_discount_price', true ), 2, '.', '' );
		if ( $discount_price == 0 )
			$discount_price = '';

		$discount_percent = (int) get_post_meta( $post->ID, 'tix_discount_percent', true );
		if ( $discount_percent == 0 )
			$discount_percent = '';

		$quantity = intval( get_post_meta( $post->ID, 'tix_coupon_quantity', true ) );
		$used = intval( $this->get_used_coupons_count( $post->ID ) );
		$applies_to = (array) get_post_meta( $post->ID, 'tix_applies_to' );
		?>
		<div class="misc-pub-section">
			<span class="left"><?php _e( 'Discount:', 'camptix' ); ?></span>
			<?php if ( $used <= 0 ) : ?>
				<input type="text" name="tix_discount_price" class="small-text" style="width: 57px;" value="<?php echo esc_attr( $discount_price ); ?>" autocomplete="off" /> <?php echo esc_html( $this->options['currency'] ); ?><br />
				<span class="left">&nbsp;</span>
				<input type="number" min="0" name="tix_discount_percent" style="margin-top: 2px;" class="small-text" value="<?php echo esc_attr( $discount_percent ); ?>" autocomplete="off" /> %
			<?php else: ?>
				<span>
				<?php if ( $discount_price ) : ?>
					<?php echo $this->append_currency( $discount_price ); ?>
				<?php else : ?>
					<?php echo $discount_percent; ?>%
				<?php endif; ?>
				</span>
				<p class="description" style="margin-top: 10px;"><?php _e( 'You can not change the discount because one or more tickets have already been purchased using this coupon.', 'camptix' ); ?></p>
			<?php endif; ?>
		</div>
		<div class="misc-pub-section">
			<span class="left"><?php _e( 'Quantity:', 'camptix' ); ?></span>
			<input type="number" min="<?php echo intval( $used ); ?>" name="tix_coupon_quantity" class="small-text" value="<?php echo esc_attr( $quantity ); ?>" autocomplete="off" />
			<?php if ( $used > 0 ) : ?>
				<p class="description" style="margin-top: 10px;"><?php _e( 'The quantity can not be less than the number of coupons already used.', 'camptix' ); ?></p>
			<?php endif; ?>
		</div>
		<div class="misc-pub-section tix-applies-to">
			<span class="left"><?php _e( 'Applies to:', 'camptix' ); ?></span>
			<div class="tix-checkbox-group">
				<label style="margin-bottom: 8px;"><a id="tix-applies-to-all" href="#"><?php _e( 'All', 'camptix' ); ?></a> / <a id="tix-applies-to-none" href="#"><?php _e( 'None', 'camptix' ); ?></a></label>
				<?php
					$q = new WP_Query( array(
						'post_type' => 'tix_ticket',
						'posts_per_page' => -1,
					) );
				?>
				<?php while ( $q->have_posts() ) : $q->the_post(); ?>
				<label><input <?php checked( in_array( $post->ID, $applies_to ) ); ?> type="checkbox" class="tix-applies-to-checkbox" name="tix_applies_to[]" value="<?php the_ID(); ?>" /> <?php the_title(); ?></label>
				<?php endwhile; ?>
				<input type="hidden" name="tix_applies_to_submit" value="1" />
			</div>
		</div>
		<div class="clear"></div>
		<?php

		// Restore the original post.
		$post = $original_post;
	}

	/**
	 * Metabox callback for coupon availability.
	 */
	function metabox_coupon_availability() {
		$start = get_post_meta( get_the_ID(), 'tix_coupon_start', true );
		$end = get_post_meta( get_the_ID(), 'tix_coupon_end', true );
		?>
		<div class="misc-pub-section curtime">
			<span id="timestamp"><?php _e( 'Leave blank for auto-availability', 'camptix' ); ?></span>
		</div>
		<div class="misc-pub-section">
			<span class="left"><?php _e( 'Start:', 'camptix' ); ?></span>
			<input type="text" name="tix_coupon_start" id="tix-date-from" class="regular-text date" value="<?php echo esc_attr( $start ); ?>" />
		</div>
		<div class="misc-pub-section">
			<span class="left"><?php _e( 'End:', 'camptix' ); ?></span>
			<input type="text" name="tix_coupon_end" id="tix-date-to" class="regular-text date" value="<?php echo esc_attr( $end ); ?>" />
		</div>
		<div class="clear"></div>
		<?php
	}

	/**
	 * Generates an attendee info table.
	 */
	function metabox_attendee_info() {
		global $post;
		$ticket_id = get_post_meta( $post->ID, 'tix_ticket_id', true );
		$ticket = get_post( $ticket_id );
		if ( ! $ticket ) return;

		$access_token = get_post_meta( $post->ID, 'tix_access_token', true );
		$edit_token = get_post_meta( $post->ID, 'tix_edit_token', true );
		$rows = array();
		$roows = array();

		// General
		$rows[] = array( __( 'General', 'camptix' ), '' );
		$rows[] = array( __( 'Status', 'camptix' ), esc_html( ucwords( $post->post_status ) ) );
		$rows[] = array( __( 'First Name', 'camptix' ), esc_html( get_post_meta( $post->ID, 'tix_first_name', true ) ) );
		$rows[] = array( __( 'Last Name', 'camptix' ), esc_html( get_post_meta( $post->ID, 'tix_last_name', true ) ) );
		$rows[] = array( __( 'E-mail', 'camptix' ), esc_html( get_post_meta( $post->ID, 'tix_email', true ) ) );
		$rows[] = array( __( 'Ticket', 'camptix' ), sprintf( '<a href="%s">%s</a>', get_edit_post_link( $ticket->ID ), $ticket->post_title ) );
		$rows[] = array( __( 'Edit Token', 'camptix' ), sprintf( '<a href="%s">%s</a>', $this->get_edit_attendee_link( $post->ID, $edit_token ), $edit_token ) );
		$rows[] = array( __( 'Access Token', 'camptix' ), sprintf( '<a href="%s">%s</a>', $this->get_access_tickets_link( $access_token ), $access_token ) );

		// Transaction
		$rows[] = array( __( 'Transaction', 'camptix' ), '' );
		$txn_id = get_post_meta( $post->ID, 'tix_transaction_id', true );
		if ( $txn_id ) {
			$txn = get_post_meta( $post->ID, 'tix_transaction_details', true );
			$txn_url = get_admin_url( 0, '/edit.php?post_type=tix_attendee' );
			$txn_url = add_query_arg( 's', $txn_id, $txn_url );

			$rows[] = array( __( 'Transaction ID', 'camptix' ), sprintf( '<a href="%s">%s</a>', $txn_url, $txn_id ) );

			/*if ( isset( $txn['PAYMENTINFO_0_PENDINGREASON'] ) && $status == 'Pending' )
				$rows[] = array( __( 'Pending Reason', 'camptix' ), $txn['PAYMENTINFO_0_PENDINGREASON'] );
			if ( isset( $txn['PENDINGREASON'] ) && $status == 'Pending' )
				$rows[] = array( __( 'Pending Reason', 'camptix' ), $txn['PENDINGREASON'] );

			if ( isset( $txn['EMAIL'] ) )
				$rows[] = array( __( 'Buyer E-mail', 'camptix' ), esc_html( $txn['EMAIL'] ) );
			*/
		}

		$coupon_id = get_post_meta( $post->ID, 'tix_coupon_id', true );
		if ( $coupon_id ) {
			$coupon = get_post( $coupon_id );
			$rows[] = array( __( 'Coupon', 'camptix' ), sprintf( '<a href="%s">%s</a>', get_edit_post_link( $coupon->ID ), $coupon->post_title ) );
		}

		$rows[] = array( __( 'Order Total', 'camptix' ), $this->append_currency( get_post_meta( $post->ID, 'tix_order_total', true ) ) );

		// Reservation
		if ( $this->options['reservations_enabled'] ) {
			$reservation_id = get_post_meta( $post->ID, 'tix_reservation_id', true );
			$reservation_token = get_post_meta( $post->ID, 'tix_reservation_token', true );
			$reservation_url = get_admin_url( 0, '/edit.php?post_type=tix_attendee' );
			$reservation_url = add_query_arg( 's', 'tix_reservation_id:' . $reservation_id, $reservation_url );
			if ( $reservation_id && $reservation_token )
				$rows[] = array( __( 'Reservation', 'camptix' ), sprintf( '<a href="%s">%s</a>', esc_url( $reservation_url ), esc_html( $reservation_id ) ) );
		}

		// Questions
		$rows[] = array( __( 'Questions', 'camptix' ), '' );
		$questions = $this->get_sorted_questions( $ticket_id );
		$answers = get_post_meta( $post->ID, 'tix_questions', true );

		foreach ( $questions as $question ) {
			$question_key = sanitize_title_with_dashes( $question['field'] );
			if ( isset( $answers[$question_key] ) ) {
				$answer = $answers[$question_key];
				if ( is_array( $answer ) )
					$answer = implode( ', ', $answer );
				$rows[] = array( $question['field'], nl2br( esc_html( $answer ) ) );
			}
		}
		$this->table( $rows, 'tix-attendees-info' );
	}

	/**
	 * Saves ticket post meta, runs during save_post, which runs whenever
	 * the post type is saved, and not necessarily from the admin, which is why the nonce check.
	 */
	function save_ticket_post( $post_id ) {

		if ( ! is_admin() )
			return;

		if ( wp_is_post_revision( $post_id ) || 'tix_ticket' != get_post_type( $post_id ) )
			return;

		// Stuff here is submittable via POST only.
		if ( ! isset( $_POST['action'] ) || 'editpost' != $_POST['action'] )
			return;

		/**
		 * @todo figure out security issue with .org
		 */
		// Security check.
		// $nonce_action = 'update-tix_ticket_' . $post_id; // see edit-form-advanced.php
		// check_admin_referer( $nonce_action );

		if ( isset( $_POST['tix_price'] ) )
			update_post_meta( $post_id, 'tix_price', $_POST['tix_price'] );

		if ( isset( $_POST['tix_quantity'] ) )
			update_post_meta( $post_id, 'tix_quantity', intval( $_POST['tix_quantity'] ) );

		if ( isset( $_POST['tix_start'] ) )
			update_post_meta( $post_id, 'tix_start', $_POST['tix_start'] );

		if ( isset( $_POST['tix_end'] ) )
			update_post_meta( $post_id, 'tix_end', $_POST['tix_end'] );

		// Questions
		if ( isset( $_POST['tix_questions'] ) ) {

			delete_post_meta( $post_id, 'tix_question' );
			$questions = (array) $_POST['tix_questions'];
			$questions_clean = array();

			usort( $questions, array( $this, 'usort_by_order' ) );

			foreach ( $questions as $order => $question ) {
				if ( empty( $question['field'] ) || strlen( trim( $question['field'] ) ) < 1 )
					continue;

				if ( ! array_key_exists( $question['type'], $this->get_question_field_types() ) )
					continue;

				if ( ! empty( $question['values'] ) )
					$question_values = array_map( 'strip_tags', array_map( 'trim', explode( ',', $question['values'] ) ) );
				else
					$question_values = array();

				$clean_question = array(
					'order' => intval( $order ),
					'field' => strip_tags( $question['field'] ),
					'type' => $question['type'],
					'values' => $question_values,
					'required' => isset( $question['required'] ),
				);

				$clean_question['required'] = (bool) $question['required'];

				// Save serialized value.
				add_post_meta( $post_id, 'tix_question', $clean_question );
			}
		}

		// Reservations
		if ( isset( $_POST['tix_doing_reservations'] ) && $this->options['reservations_enabled'] ) {

			// Make a new reservation
			if ( isset( $_POST['tix_reservation_name'], $_POST['tix_reservation_quantity'] )
				&& ! empty( $_POST['tix_reservation_name'] ) && intval( $_POST['tix_reservation_quantity'] ) > 0 ) {

				$reservation_id = sanitize_title_with_dashes( $_POST['tix_reservation_name'] );
				$reservation_name = $_POST['tix_reservation_name'];
				$reservation_quantity = intval( $_POST['tix_reservation_quantity'] );
				$reservation_token = md5( 'caMptix-r353rv4t10n' . rand( 1, 9999 ) . time() . $reservation_id . $post_id );
				$reservation = array(
					'id' => $reservation_id,
					'name' => $reservation_name,
					'quantity' => $reservation_quantity,
					'token' => $reservation_token,
					'ticket_id' => $post_id,
				);

				// Bump the ticket quantity if remaining less than we want to reserve.
				$remaining = $this->get_remaining_tickets( $post_id );
				if ( $remaining < $reservation_quantity ) {
					$ticket_quantity = intval( get_post_meta( $post_id, 'tix_quantity', true ) );
					$ticket_quantity += $reservation_quantity - $remaining;
					update_post_meta( $post_id, 'tix_quantity', $ticket_quantity );
				}

				// Create the reservation.
				add_post_meta( $post_id, 'tix_reservation', $reservation );
				$this->log( 'Created a new reservation.', $post_id, $reservation );
			}

			// Release a reservation.
			if ( isset( $_POST['tix_reservation_release'] ) && is_array( $_POST['tix_reservation_release'] ) ) {
				$release = $_POST['tix_reservation_release'];
				$release_token = array_shift( array_keys( $release ) );

				$reservations = $this->get_reservations( $post_id );
				if ( isset( $reservations[$release_token] ) ) {
					delete_post_meta( $post_id, 'tix_reservation', $reservations[$release_token] );
					$this->log( 'Released a reservation.', $post_id, $reservations[$release_token] );
				}
			}

			// Cancel a reservation: same as release, but decreases quantity.
			if ( isset( $_POST['tix_reservation_cancel'] ) && is_array( $_POST['tix_reservation_cancel'] ) ) {
				$cancel = $_POST['tix_reservation_cancel'];
				$cancel_token = array_shift( array_keys( $cancel ) );

				$reservations = $this->get_reservations( $post_id );
				if ( isset( $reservations[$cancel_token] ) ) {
					$reservation = $reservations[$cancel_token];
					$reservation_quantity = intval( $reservation['quantity'] );
					$reservation_used = $this->get_purchased_tickets_count( $post_id, $reservation['token'] );

					$ticket_quantity = intval( get_post_meta( $post_id, 'tix_quantity', true ) );
					$ticket_quantity -= ( $reservation_quantity - $reservation_used );
					update_post_meta( $post_id, 'tix_quantity', $ticket_quantity );

					delete_post_meta( $post_id, 'tix_reservation', $reservations[$cancel_token] );
					$this->log( 'Cancelled a reservation.', $post_id, $reservations[$cancel_token] );
				}
			}
		}

		$this->log( 'Saved ticket post with form data.', $post_id, $_POST );

		// Purge tickets page cache.
		$this->flush_tickets_page();
	}

	/**
	 * Saves attendee post meta, runs during save_post, also
	 * populates the attendee content field with data for search.
	 */
	function save_attendee_post( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || 'tix_attendee' != get_post_type( $post_id ) )
			return;

		$search_meta_fields = array(
			'tix_first_name',
			'tix_last_name',
			'tix_email',
			'tix_transaction_id',
			'tix_questions',
			'tix_coupon',
			'tix_coupon_id',
			'tix_reservation_id',
			'tix_ticket_id',
			'tix_access_token',
			'tix_edit_token',
			'tix_payment_token',
			'tix_payment_method',
		);
		$data = array( 'timestamp' => time() );

		foreach ( $search_meta_fields as $key )
			if ( get_post_meta( $post_id, $key, true ) )
				$data[$key] = sprintf( "%s:%s", $key, maybe_serialize( get_post_meta( $post_id, $key, true ) ) );

		$first_name = get_post_meta( $post_id, 'tix_first_name', true );
		$last_name = get_post_meta( $post_id, 'tix_last_name', true );

		// No infinite loops please.
		remove_action( 'save_post', array( $this, __FUNCTION__ ) );

		wp_update_post( array(
			'ID' => $post_id,
			'post_content' => maybe_serialize( $data ),
			'post_title' => "$first_name $last_name",
		) );

		// There might be others in need of processing.
		add_action( 'save_post', array( $this, __FUNCTION__ ) );

		if ( isset( $_POST ) && ! empty( $_POST ) && is_admin() )
			$this->log( 'Saved attendee post with post data.', $post_id, $_POST );
	}

	/**
	 * Saves coupon post meta, runs during save_post and not always in/by the admin.
	 */
	function save_coupon_post( $post_id ) {
		if ( ! is_admin() )
			return;

		if ( wp_is_post_revision( $post_id ) || 'tix_coupon' != get_post_type( $post_id ) )
			return;

		// Stuff here is submittable via POST only.
		if ( ! isset( $_POST['action'] ) || 'editpost' != $_POST['action'] )
			return;

		/**
		 * @todo figure out security issue with .org
		 */
		// Security check.
		// $nonce_action = 'update-tix_coupon_' . $post_id; // see edit-form-advanced.php
		// check_admin_referer( $nonce_action );

		if ( isset( $_POST['tix_discount_price'], $_POST['tix_discount_percent'] ) ) {
			$price = (float) $_POST['tix_discount_price'];
			$percent = intval( $_POST['tix_discount_percent'] );
			if ( $price > 0 ) { // a price discount has priority over % discount.
				update_post_meta( $post_id, 'tix_discount_price', $price );
				delete_post_meta( $post_id, 'tix_discount_percent' );
			} elseif ( $percent > 0 ) {
				update_post_meta( $post_id, 'tix_discount_percent', $percent );
				delete_post_meta( $post_id, 'tix_discount_price' );
			} else {
				delete_post_meta( $post_id, 'tix_discount_percent' );
				delete_post_meta( $post_id, 'tix_discount_price' );
			}
		}

		if ( isset( $_POST['tix_coupon_quantity'] ) ) {
			update_post_meta( $post_id, 'tix_coupon_quantity', intval( $_POST['tix_coupon_quantity'] ) );
		}

		if ( isset( $_POST['tix_applies_to_submit'] ) ) {
			delete_post_meta( $post_id, 'tix_applies_to' );

			if ( isset( $_POST['tix_applies_to'] ) )
				foreach ( (array) $_POST['tix_applies_to'] as $ticket_id )
					if ( $this->is_ticket_valid_for_display( $ticket_id ) )
						add_post_meta( $post_id, 'tix_applies_to', $ticket_id );
		}

		if ( isset( $_POST['tix_coupon_start'] ) ) {
			update_post_meta( $post_id, 'tix_coupon_start', $_POST['tix_coupon_start'] );
		}

		if ( isset( $_POST['tix_coupon_end'] ) ) {
			update_post_meta( $post_id, 'tix_coupon_end', $_POST['tix_coupon_end'] );
		}

		$this->log( 'Saved coupon post with form data.', $post_id, $_POST );
	}

	/**
	 * A bunch of magic is happening here.
	 */
	function template_redirect() {
		global $post;
		if ( ! is_page() || ! stristr( $post->post_content, '[camptix]' ) )
			return;

		$this->error_flags = array();

		// Allow third-party forms to initiate a ticket purchase.
		if ( isset( $_POST['tix_single_ticket_purchase'] ) ) {
			$_POST['tix_tickets_selected'] = array( $_POST['tix_single_ticket_purchase'] => 1 );
		}

		if ( isset( $_POST ) && ! empty( $_POST ) )
			$this->form_data = $_POST;

		$this->tickets = array();
		$this->tickets_selected = array();
		$coupon_used_count = 0;
		$via_reservation = false;

		if ( count( $this->get_enabled_payment_methods() ) < 1 )
			$this->error_flags['no_payment_methods'] = true;

		// Find the coupon.
		if ( isset( $_REQUEST['tix_coupon'] ) && ! empty( $_REQUEST['tix_coupon'] ) ) {
			$coupon = $this->get_coupon_by_code( $_REQUEST['tix_coupon'] );
			if ( $coupon && $this->is_coupon_valid_for_use( $coupon->ID ) ) {
				$coupon->tix_coupon_remaining = $this->get_remaining_coupons( $coupon->ID );
				$coupon->tix_discount_price = (float) get_post_meta( $coupon->ID, 'tix_discount_price', true );
				$coupon->tix_discount_percent = (int) get_post_meta( $coupon->ID, 'tix_discount_percent', true );
				$coupon->tix_applies_to = (array) get_post_meta( $coupon->ID, 'tix_applies_to' );
				$this->coupon = $coupon;
			} else {
				$this->error_flags['invalid_coupon'] = true;
			}
			unset( $coupon );
		}

		// Have we got a reservation?
		if ( isset( $_REQUEST['tix_reservation_id'], $_REQUEST['tix_reservation_token'] ) ) {
			$reservation = $this->get_reservation( $_REQUEST['tix_reservation_token'] );

			if ( $reservation && $reservation['id'] == strtolower( $_REQUEST['tix_reservation_id'] ) && $this->is_reservation_valid_for_use( $reservation['token'] ) ) {
				$this->reservation = $reservation;
				$via_reservation = $this->reservation['token'];
			} else {
				$this->error_flags['invalid_reservation'] = true;
			}
		}

		if ( ! $this->options['archived'] ) {
			$tickets = get_posts( array(
				'post_type' => 'tix_ticket',
				'post_status' => 'publish',
				'posts_per_page' => -1,
			) );
		} else {
			// No tickets for archived events.
			$tickets = array();
		}

		// Get the tickets.
		foreach ( $tickets as $ticket ) {
			$ticket->tix_price = (float) get_post_meta( $ticket->ID, 'tix_price', true );
			$ticket->tix_remaining = $this->get_remaining_tickets( $ticket->ID, $via_reservation );
			$ticket->tix_coupon_applied = false;
			$ticket->tix_discounted_price = $ticket->tix_price;

			// Check each ticket against coupon.
			if ( $this->coupon && in_array( $ticket->ID, $this->coupon->tix_applies_to ) ) {
				$ticket->tix_coupon_applied = true;
				$ticket->tix_discounted_text = '';

				if ( $this->coupon->tix_discount_price > 0 ) {
					$ticket->tix_discounted_price = number_format( $ticket->tix_price - $this->coupon->tix_discount_price, 2, '.', '' );
					$ticket->tix_discounted_text = sprintf( __( 'Discounted %s', 'camptix' ), $this->append_currency( $this->coupon->tix_discount_price ) );
				} elseif ( $this->coupon->tix_discount_percent > 0 ) {
					$ticket->tix_discounted_price = number_format( $ticket->tix_price - ( $ticket->tix_price * $this->coupon->tix_discount_percent / 100 ), 2, '.', '' );
					$ticket->tix_discounted_text = sprintf( __( 'Discounted %s%%', 'camptix' ), $this->coupon->tix_discount_percent );
				}

				if ( $ticket->tix_discounted_price < 0 )
					$ticket->tix_discounted_price = 0;
			}

			$this->tickets[$ticket->ID] = $ticket;
		}

		unset( $tickets, $ticket );

		// Populate selected tickets from $_POST!
		if ( isset( $_POST['tix_tickets_selected'] ) )
			foreach ( $_POST['tix_tickets_selected'] as $ticket_id => $count )
				if ( isset( $this->tickets[$ticket_id] ) && $count > 0 )
					$this->tickets_selected[$ticket_id] = intval( $count );

		// Make an order.
		$this->order = array( 'items' => array(), 'total' => 0 );
		if ( isset( $_POST['tix_tickets_selected'] ) ) {
			foreach ( $_POST['tix_tickets_selected'] as $ticket_id => $count ) {
				$ticket = $this->tickets[ $ticket_id ];
				$item = array(
					'id' => $ticket->ID,
					'name' => $ticket->post_title,
					'description' => $ticket->post_excerpt,
					'quantity' => $count,
					'price' => $ticket->tix_discounted_price,
				);
				$this->order['items'][] = $item;
				$this->order['total'] += $item['price'] * $item['quantity'];
			}
		}

		if ( isset( $_REQUEST['tix_coupon'] ) )
			$this->order['coupon'] = $_REQUEST['tix_coupon'];

		if ( isset( $_REQUEST['tix_reservation_id'], $_REQUEST['tix_reservation_token'] ) ) {
			$this->order['reservation_id'] = $_REQUEST['tix_reservation_id'];
			$this->order['reservation_token'] = $_REQUEST['tix_reservation_token'];
		}

		// Check whether this is a valid order.
		if ( ! empty( $this->order['items'] ) )
			$this->verify_order( $this->order );

		// Check selected tickets.
		$tickets_excess = 0;
		$coupons_applied = 0;
		foreach ( $this->tickets_selected as $ticket_id => $count ) {
			$ticket = $this->tickets[$ticket_id];

			// Don't allow more than 10 tickets of each type to be purchased in bulk.
			if ( $count > 10 && $ticket->tix_remaining > 10 ) {
				$this->tickets_selected[$ticket_id] = 10;
				$count = 10;
				$tickets_excess += $count - 10;
			}

			// ref: #1001
			if ( $count > $ticket->tix_remaining ) {
				$this->tickets_selected[$ticket_id] = $ticket->tix_remaining;
				$tickets_excess += $count - $ticket->tix_remaining;

				// Remove the ticket if count is 0.
				if ( $this->tickets_selected[$ticket_id] < 1 )
					unset( $this->tickets_selected[$ticket_id] );
			}

			// ref: #1002
			if ( $ticket->tix_coupon_applied )
				$coupons_applied += $count;
		}

		$this->tickets_selected_count = 0;
		foreach ( $this->tickets_selected as $ticket_id => $count )
			$this->tickets_selected_count += $count;

		// ref: #1001
		if ( $tickets_excess > 0 )
			$this->error_flags['tickets_excess'] = true;

		// ref: #1002 @todo maybe strip the cheaper ones instead?
		if ( $this->coupon && $coupons_applied > $this->coupon->tix_coupon_remaining ) {
			$this->error_flags['coupon_excess'] = true;

			$extra = $coupons_applied - $this->coupon->tix_coupon_remaining;
			foreach ( array_reverse( $this->tickets_selected, true ) as $ticket_id => $count ) {
				if ( $this->tickets[$ticket_id]->tix_coupon_applied ) {
					if ( $extra >= $count && $extra > 0 ) {
						unset( $this->tickets_selected[$ticket_id] );
						$extra -= $count;
					} elseif ( $extra > 0 ) {
						$this->tickets_selected[$ticket_id] -= $extra;
						$extra -= $count;
					}
				}
			}

			if ( $extra > 0 )
				$this->log( 'Something is terribly wrong, extra > 0 after stripping extra coupons', 0, null, 'critical' );
		}

		if ( isset( $_POST['tix_tickets_selected'] ) ) {
			$this->error_flags['no_tickets_selected'] = true;
			foreach ( $this->tickets_selected as $ticket_id => $count )
				if ( $count > 0 ) unset( $this->error_flags['no_tickets_selected'] );
		}

		$this->did_template_redirect = true;

		// Don't go past the start form if no payment methods are enabled.
		if ( isset( $this->error_flags['no_payment_methods'] ) )
			return $this->shortcode_contents = $this->form_start();

		if ( 'attendee_info' == get_query_var( 'tix_action' ) && isset( $_POST['tix_coupon_submit'], $_POST['tix_coupon'] ) && ! empty( $_POST['tix_coupon'] ) )
			return $this->shortcode_contents = $this->form_start();

		if ( 'attendee_info' == get_query_var( 'tix_action' ) && isset( $this->error_flags['no_tickets_selected'] ) )
			return $this->shortcode_contents = $this->form_start();

		if ( 'attendee_info' == get_query_var( 'tix_action' ) )
			return $this->shortcode_contents = $this->form_attendee_info();

		if ( 'checkout' == get_query_var( 'tix_action' ) )
			return $this->shortcode_contents = $this->form_checkout();

		if ( 'access_tickets' == get_query_var( 'tix_action' ) )
			return $this->shortcode_contents = $this->form_access_tickets();

		if ( 'edit_attendee' == get_query_var( 'tix_action' ) )
			return $this->shortcode_contents = $this->form_edit_attendee();

		if ( 'refund_request' == get_query_var( 'tix_action' ) && $this->options['refunds_enabled'] )
			return $this->shortcode_contents = $this->form_refund_request();

		if ( ! get_query_var( 'tix_action' ) )
			return $this->shortcode_contents = $this->form_start();

		return $this->shortcode_contets = 'Hmmm.';
	}

	/**
	 * Returns $this->shortcode_contents
	 */
	function shortcode_callback( $atts ) {
		if ( ! $this->did_template_redirect ) {
			$this->log( 'Something is seriously wrong, did_template_redirect is false.', 0, null, 'critical' );
			return __( 'An error has occurred.', 'camptix' );
		}

		wp_enqueue_script( 'camptix' ); // js in footer
		return $this->shortcode_contents;
	}

	/**
	 * Step 1: shows the available tickets table.
	 */
	function form_start() {

		$available_tickets = 0;
		foreach ( $this->tickets as $ticket )
			if ( $this->is_ticket_valid_for_purchase( $ticket->ID ) )
				$available_tickets++;

		if ( isset( $this->error_flags['invalid_coupon'] ) )
			$this->error( __( 'Sorry, but the coupon you have entered seems to be invalid or expired.', 'camptix' ) );

		if ( isset( $this->error_flags['invalid_reservation'] ) )
			$this->error( __( 'Sorry, but the reservation you are trying to use seems to be invalid or expired.', 'camptix' ) );

		if ( ! $available_tickets )
			$this->notice( __( 'Sorry, but there are currently no tickets for sale. Please try again later.', 'camptix' ) );

		if ( $available_tickets && isset( $this->reservation ) && $this->reservation )
			$this->info( __( 'You are using a reservation, cool!', 'camptix' ) );

		if ( ! isset( $_POST['tix_coupon_submit'], $_POST['tix_coupon'] ) || empty( $_POST['tix_coupon'] ) )
			if ( isset( $this->error_flags['no_tickets_selected'] ) && 'attendee_info' == get_query_var( 'tix_action' )  )
				$this->error( __( 'Please select at least one ticket.', 'camptix' ) );

		if ( 'checkout' == get_query_var( 'tix_action' ) && isset( $this->error_flags['no_tickets_selected'] ) )
			$this->error( __( 'It looks like somebody took that last ticket before you, sorry! You try a different ticket.', 'camptix' ) );

		if ( isset( $this->error_flags['no_payment_methods'] ) ) {
			$this->notice( __( 'Payment methods have not been configured yet. Please try again later.', 'camptix' ) );
			$available_tickets = 0; // Don't bother to show the ticketing form.
		}

		$redirected_error_flags = isset( $_REQUEST['tix_errors'] ) ? array_flip( (array) $_REQUEST['tix_errors'] ) : array();

		if ( isset( $redirected_error_flags['payment_failed'] ) ) {
			/** @todo explain error */
			$this->error( __( 'An error has occured and your payment has failed. Please try again later.', 'camptix' ) );
		}

		if ( isset( $redirected_error_flags['tickets_excess'] ) )
			$this->error( __( 'It looks like somebody grabbed those tickets before you could complete the purchase. You have not been charged, please try again.', 'camptix' ) );

		if ( isset( $redirected_error_flags['coupon_excess'] ) )
			$this->error( __( 'It looks like somebody has used the coupon before you could complete your purchase. You have not been charged, please try again.', 'camptix' ) );

		if ( isset( $redirected_error_flags['invalid_coupon'] ) )
			$this->error( __( 'It looks like the coupon you are trying to use has expired before you could complete your purchase. You have not been charged, please try again.', 'camptix' ) );

		if ( isset( $redirected_error_flags['invalid_access_token'] ) )
			$this->error( __( 'Your access token does not seem to be valid.', 'camptix' ) );

		if ( isset( $redirected_error_flags['payment_cancelled'] ) )
			$this->error( __( 'Your payment has been cancelled. Feel free to try again!', 'camptix' ) );

		if ( isset( $redirected_error_flags['invalid_edit_token'] ) )
			$this->error( __( 'The edit link you are trying to use is either invalid or has expired.', 'camptix' ) );

		if ( isset( $redirected_error_flags['cannot_refund'] ) )
			$this->error( __( 'Your refund request can not be processed. Please try again later or contact support.', 'camptix' ) );

		if ( isset( $redirected_error_flags['invalid_reservation'] ) )
			$this->error( __( 'Sorry, but the reservation you are trying to use has been cancelled or has expired.', 'camptix' ) );

		ob_start();
		?>
		<div id="tix">
			<?php do_action( 'camptix_notices' ); ?>
			<?php if ( $available_tickets ) : ?>
			<form action="<?php echo esc_url( add_query_arg( 'tix_action', 'attendee_info', $this->get_tickets_url() ) ); ?>#tix" method="POST">

			<?php if ( isset( $this->reservation ) && $this->reservation ) : ?>
				<input type="hidden" name="tix_reservation_id" value="<?php echo esc_attr( $this->reservation['id'] ); ?>" />
				<input type="hidden" name="tix_reservation_token" value="<?php echo esc_attr( $this->reservation['token'] ); ?>" />
			<?php endif; ?>

			<table class="tix_tickets_table">
				<thead>
					<tr>
						<th class="tix-column-description"><?php _e( 'Description', 'camptix' ); ?></th>
						<th class="tix-column-price"><?php _e( 'Price', 'camptix' ); ?></th>
						<th class="tix-column-remaining"><?php _e( 'Remaining', 'camptix' ); ?></th>
						<th class="tix-column-quantity"><?php _e( 'Quantity', 'camptix' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $this->tickets as $ticket ) : ?>
						<?php
							if ( ! $this->is_ticket_valid_for_purchase( $ticket->ID ) )
								continue;

							$price = $ticket->tix_price;
							$discounted = '';

							$max = min( $ticket->tix_remaining, 10 );
							$selected = ( 1 == count( $this->tickets ) ) ? 1 : 0;
							if ( isset( $this->tickets_selected[$ticket->ID] ) )
								$selected = intval( $this->tickets_selected[$ticket->ID] );

							// Recount selects, change price.
							if ( $ticket->tix_coupon_applied ) {
								$max = min( $this->coupon->tix_coupon_remaining, $ticket->tix_remaining, 10 );
								if ( $selected > $this->coupon->tix_coupon_remaining )
									$selected = $this->coupon->tix_coupon_remaining;

								$price = $ticket->tix_discounted_price;
							}
						?>
						<tr>
							<td class="tix-column-description">
								<strong class="tix-ticket-title"><?php echo $ticket->post_title; ?></strong>
								<?php if ( $ticket->post_excerpt ) : ?>
								<br /><span class="tix-ticket-excerpt"><?php echo $ticket->post_excerpt; ?></span>
								<?php endif; ?>
								<?php if ( $ticket->tix_coupon_applied ) : ?>
								<br /><small class="tix-discount"><?php echo esc_html( $ticket->tix_discounted_text ); ?></small>
								<?php endif; ?>
							</td>
							<td class="tix-column-price" style="vertical-align: middle;">
								<?php if ( $price > 0 ) : ?>
								<?php echo $this->append_currency( $price ); ?>
								<?php else : ?>
									Free
								<?php endif; ?>
							</td>
							<td class="tix-column-remaining" style="vertical-align: middle;"><?php echo $ticket->tix_remaining; ?></td>
							<td class="tix-column-quantity" style="vertical-align: middle;">
								<select name="tix_tickets_selected[<?php echo $ticket->ID; ?>]">
									<?php foreach ( range( 0, $max ) as $value ) : ?>
									<option <?php selected( $selected, $value ); ?> value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $value ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
						<?php if ( $this->have_coupons() ) : ?>
						<tr>
							<td colspan="4" style="text-align: right;">
								<?php if ( $this->coupon ) : ?>
									<input type="hidden" name="tix_coupon" value="<?php echo esc_attr( $this->coupon->post_title ); ?>" />
									<?php
										$discount_price = (float) $this->coupon->tix_discount_price;
										$discount_percent = (float) $this->coupon->tix_discount_percent;
										if ( $discount_price > 0 ) {
											$discount_text = $this->append_currency( $discount_price );
										} elseif ( $discount_percent > 0 ) {
											$discount_text = $discount_percent . '%';
										}
									?>
									<?php printf( __( 'Coupon Applied: <strong>%s</strong>, %s discount', 'camptix' ), esc_html( $this->coupon->post_title ), $discount_text ); ?>
								<?php else : ?>
								<a href="#" id="tix-coupon-link"><?php _e( 'Click here to enter a coupon code', 'camptix' ); ?></a>
								<div id="tix-coupon-container" style="display: none;">
									<input type="text" id="tix-coupon-input" name="tix_coupon" value="" />
									<input type="submit" name="tix_coupon_submit" value="<?php esc_attr_e( 'Apply Coupon', 'camptix' ); ?>" />
								</div>
								<script>
									// Hide the link and show the coupon form on click.
									var link_el = document.getElementById( 'tix-coupon-link' );
									link_el.onclick = function() {
										this.style.display = 'none';
										document.getElementById( 'tix-coupon-container' ).style.display = 'block';
										document.getElementById( 'tix-coupon-input' ).focus();
										return false;
									};
								</script>
								<?php endif; // doing coupon && valid ?>
							</td>
						</tr>
						<?php endif; ?>
				</tbody>
			</table>

			<p>
				<input type="submit" value="<?php esc_attr_e( 'Register &rarr;', 'camptix' ); ?>" style="float: right; cursor: pointer;" />
				<br class="tix-clear" />
			</p>
			</form>
			<?php endif; ?>
		</div><!-- #tix -->
		<?php
		wp_reset_postdata();
		$contents = ob_get_contents();
		ob_end_clean();
		return $contents;
	}

	/**
	 * Step 2: asks for attendee information on chosen tickets.
	 */
	function form_attendee_info() {
		if ( isset( $this->error_flags['no_tickets_selected'] ) && 'checkout' == get_query_var( 'tix_action' ) )
			return $this->form_start();

		if ( isset( $this->error_flags['tickets_excess'] ) )
			if ( 'attendee_info' == get_query_var( 'tix_action' ) )
				$this->notice( __( 'It looks like you have chosen more tickets than we have left! We have stripped the extra ones.', 'camptix' ) );
			elseif ( 'checkout' == get_query_var( 'tix_action' ) )
				$this->error( __( 'It looks like somebody purchased a ticket before you could finish your purchase. Please review your order and try again.', 'camptix' ) );

		if ( isset( $this->error_flags['coupon_excess'] ) )
			if ( 'attendee_info' == get_query_var( 'tix_action' ) )
				$this->notice( __( 'You have exceeded the coupon limits, so we have stripped down the extra tickets.', 'camptix' ) );
			elseif ( 'checkout' == get_query_var( 'tix_action' ) )
				$this->error( __( 'It looks like somebody used the same coupon before you could finish your purchase. Please review your order and try again.', 'camptix' ) );

		if ( isset( $this->error_flags['required_fields'] ) )
			$this->error( __( 'Please fill in all required fields.', 'camptix' ) );

		if ( isset( $this->error_flags['invalid_email'] ) )
			$this->error( __( 'The e-mail address you have entered seems to be invalid.', 'camptix' ) );

		if ( isset( $this->error_flags['no_receipt_email'] ) )
			$this->error( __( 'The chosen receipt e-mail address is either empty or invalid.', 'camptix' ) );

		if ( isset( $this->error_flags['payment_failed'] ) )
			$this->error( __( 'An payment error has occurred, looks like chosen payment method is not responding. Please try again later.', 'camptix' ) );

		if ( isset( $this->error_flags['invalid_payment_method'] ) )
			$this->error( __( 'You have selected an invalid payment method. Please try again.', 'camptix' ) );

		if ( isset( $this->error_flags['invalid_coupon'] ) )
			$this->notice( __( "Looks like you're trying to use an invalid or expired coupon.", 'camptix' ) );

		ob_start();
		$total = 0;
		$i = 1;
		?>
		<div id="tix" class="tix-has-dynamic-receipts">
			<?php do_action( 'camptix_notices' ); ?>
			<form action="<?php echo esc_url( add_query_arg( 'tix_action', 'checkout' ), $this->get_tickets_url() ); ?>#tix" method="POST">

				<?php if ( $this->coupon ) : ?>
					<input type="hidden" name="tix_coupon" value="<?php echo esc_attr( $this->coupon->post_title ); ?>" />
				<?php endif; ?>

				<?php if ( isset( $this->reservation ) && $this->reservation ) : ?>
					<input type="hidden" name="tix_reservation_id" value="<?php echo esc_attr( $this->reservation['id'] ); ?>" />
					<input type="hidden" name="tix_reservation_token" value="<?php echo esc_attr( $this->reservation['token'] ); ?>" />
				<?php endif; ?>

				<?php foreach ( $this->tickets_selected as $ticket_id => $count ) : ?>
					<input type="hidden" name="tix_tickets_selected[<?php echo intval( $ticket_id ); ?>]" value="<?php echo intval( $count ); ?>" />
				<?php endforeach; ?>

				<h2><?php _e( 'Order Summary', 'camptix' ); ?></h2>
				<table class="tix_tickets_table tix-order-summary">
					<thead>
						<tr>
							<th class="tix-column-description"><?php _e( 'Description', 'camptix' ); ?></th>
							<th class="tix-column-per-ticket"><?php _e( 'Per Ticket', 'camptix' ); ?></th>
							<th class="tix-column-quantity"><?php _e( 'Quantity', 'camptix' ); ?></th>
							<th class="tix-column-price"><?php _e( 'Price', 'camptix' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $this->tickets_selected as $ticket_id => $count ) : ?>
							<?php
								$ticket = $this->tickets[$ticket_id];
								$price = ( $ticket->tix_coupon_applied ) ? $ticket->tix_discounted_price : $ticket->tix_price;
								$total += $price * $count;
							?>
							<tr>
								<td class="tix-column-description">
									<strong><?php echo $ticket->post_title; ?></strong>
									<?php if ( $ticket->tix_coupon_applied ) : ?>
									<br /><small><?php echo $ticket->tix_discounted_text; ?></small>
									<?php endif; ?>
								</td>
								<td class="tix-column-per-ticket">
								<?php if ( $price > 0 ) : ?>
									<?php echo $this->append_currency( $price ); ?>
								<?php else : ?>
									Free
								<?php endif; ?>
								</td>
								<td class="tix-column-quantity"><?php echo intval( $count ); ?></td>
								<td class="tix-column-price"><?php echo $this->append_currency( $price  * intval( $count ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<td colspan="3" style="text-align: right">
								<?php if ( $this->coupon ) : ?>
									<?php
										$discount_price = (float) $this->coupon->tix_discount_price;
										$discount_percent = (float) $this->coupon->tix_discount_percent;
										if ( $discount_price > 0 ) {
											$discount_text = $this->append_currency( $discount_price );
										} elseif ( $discount_percent > 0 ) {
											$discount_text = $discount_percent . '%';
										}
									?>
									<small><?php printf( __( 'Coupon Applied: <strong>%s</strong>, %s discount', 'camptix' ), esc_html( $this->coupon->post_title ), $discount_text ); ?></small>
								<?php endif; ?>
							</td>
							<td><strong><?php echo $this->append_currency( $total ); ?></strong></td>
						</tr>
					</tbody>
				</table>

				<h2 id="tix-registration-information"><?php _e( 'Registration Information', 'camptix' ); ?></h2>
				<?php foreach ( $this->tickets_selected as $ticket_id => $count ) : ?>
					<?php foreach ( range( 1, $count ) as $looping_count_times ) : ?>

						<?php
							$ticket = $this->tickets[$ticket_id];
							$questions = $this->get_sorted_questions( $ticket->ID );
						?>
						<input type="hidden" name="tix_attendee_info[<?php echo $i; ?>][ticket_id]" value="<?php echo intval( $ticket->ID ); ?>" />
						<table class="tix_tickets_table tix-attendee-form">
							<tbody>
								<tr>
									<th colspan="2">
										<?php echo $i; ?>. <?php echo $ticket->post_title; ?>
									</th>
								</tr>
								<tr class="tix-row-first-name">
									<td class="tix-required tix-left"><?php _e( 'First Name', 'camptix' ); ?> <span class="tix-required-star">*</span></td>
									<?php $value = isset( $this->form_data['tix_attendee_info'][$i]['first_name'] ) ? $this->form_data['tix_attendee_info'][$i]['first_name'] : ''; ?>
									<td class="tix-right"><input name="tix_attendee_info[<?php echo $i; ?>][first_name]" type="text" value="<?php echo esc_attr( $value ); ?>" /></td>
								</tr>
								<tr class="tix-row-last-name">
									<td class="tix-required tix-left"><?php _e( 'Last Name', 'camptix' ); ?> <span class="tix-required-star">*</span></td>
									<?php $value = isset( $this->form_data['tix_attendee_info'][$i]['last_name'] ) ? $this->form_data['tix_attendee_info'][$i]['last_name'] : ''; ?>
									<td class="tix-right"><input name="tix_attendee_info[<?php echo $i; ?>][last_name]" type="text" value="<?php echo esc_attr( $value ); ?>" /></td>
								</tr>
								<tr class="tix-row-email">
									<td class="tix-required tix-left"><?php _e( 'E-mail', 'camptix' ); ?> <span class="tix-required-star">*</span></td>
									<?php $value = isset( $this->form_data['tix_attendee_info'][$i]['email'] ) ? $this->form_data['tix_attendee_info'][$i]['email'] : ''; ?>
									<td class="tix-right">
										<input class="tix-field-email" name="tix_attendee_info[<?php echo $i; ?>][email]" type="text" value="<?php echo esc_attr( $value ); ?>" />
										<?php
											$tix_receipt_email = isset( $this->form_data['tix_receipt_email'] ) ? $this->form_data['tix_receipt_email'] : 1;
										?>
										<?php if ( $this->tickets_selected_count > 1 ) : ?>
											<div class="tix-hide-if-js">
												<label><input name="tix_receipt_email" <?php checked( $tix_receipt_email, $i ); ?> value="<?php echo $i; ?>" type="radio" /> <?php _e( 'Send the receipt to this address', 'camptix' ); ?></label>
											</div>
										<?php else: ?>
											<input name="tix_receipt_email" type="hidden" value="1" />
										<?php endif; ?>
									</td>
								</tr>

								<?php
									do_action( 'camptix_question_fields_init' );
									$question_num = 0; // Used for questions class names.
								?>
								<?php foreach ( $questions as $question ) : ?>

									<?php
										$question_key = sanitize_title_with_dashes( $question['field'] );
										$name = sprintf( 'tix_attendee_questions[%d][%s]', $i, $question_key );
										$value = isset( $this->form_data['tix_attendee_questions'][$i][$question_key] ) ? $this->form_data['tix_attendee_questions'][$i][$question_key] : '';
										$question_type = $question['type'];
										$class_name = 'tix-row-question-' . ++$question_num;
									?>
									<tr class="<?php echo esc_attr( $class_name ); ?>">
										<td class="<?php if ( $question['required'] ) echo 'tix-required'; ?> tix-left"><?php echo esc_html( $question['field'] ); ?><?php if ( $question['required'] ) echo ' <span class="tix-required-star">*</span>'; ?></td>
										<td class="tix-right">
											<?php do_action( "camptix_question_field_$question_type", $name, $value, $question ); ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php $i++; ?>

					<?php endforeach; // range ?>
				<?php endforeach; // tickets_selected ?>

				<?php if ( $this->tickets_selected_count > 1 ) : ?>
				<div class="tix-show-if-js">
				<table class="tix-receipt-form">
					<tr>
						<th colspan="2"><?php _e( 'Receipt', 'camptix' ); ?></th>
					</tr>
					<tr>
						<td class="tix-left tix-required"><?php _e( 'E-mail the receipt to', 'camptix' ); ?> <span class="tix-required-star">*</span></td>
						<td class="tix-right" id="tix-receipt-emails-list">
							<?php if ( isset( $this->form_data['tix_receipt_email_js'] ) && is_email( $this->form_data['tix_receipt_email_js'] ) ) : ?>
								<label><input name="tix_receipt_email_js" checked="checked" value="<?php echo esc_attr( $this->form_data['tix_receipt_email_js'] ); ?>" type="radio" /> <?php echo esc_html( $this->form_data['tix_receipt_email_js'] ); ?></label><br />
							<?php endif; ?>
						</td>
					</tr>
				</table>
				</div>
				<?php endif; ?>

				<p class="tix-submit">
					<?php if ( $total > 0 ) : ?>
					<select name="tix_payment_method">
						<?php foreach ( $this->get_enabled_payment_methods() as $payment_method_key => $payment_method ) : ?>
							<option <?php selected( ! empty( $this->form_data['tix_payment_method'] ) && $this->form_data['tix_payment_method'] == $payment_method_key ); ?> value="<?php echo esc_attr( $payment_method_key ); ?>"><?php echo esc_html( $payment_method['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="submit" value="<?php esc_attr_e( 'Checkout &rarr;', 'camptix' ); ?>" />
					<?php else : ?>
						<input type="submit" value="<?php esc_attr_e( 'Claim Tickets &rarr;', 'camptix' ); ?>" />
					<?php endif; ?>
					<br class="tix-clear" />
				</p>
			</form>
		</div><!-- #tix -->
		<?php
		$contents = ob_get_contents();
		ob_end_clean();
		return $contents;
	}

	/**
	 * Allows buyer to access all purchased tickets.
	 */
	function form_access_tickets() {
		global $post;
		ob_start();

		if ( ! isset( $_REQUEST['tix_access_token'] ) || empty( $_REQUEST['tix_access_token'] ) ) {
			$this->error_flags['invalid_access_token'] = true;
			$this->redirect_with_error_flags();
			die();
		}

		$access_token = $_REQUEST['tix_access_token'];
		$is_refundable = false;

		// Let's get one attendee
 		$attendees = get_posts( array(
			'posts_per_page' => 1,
			'post_type' => 'tix_attendee',
			'post_status' => array( 'publish', 'pending' ),
			'meta_query' => array(
				array(
					'key' => 'tix_access_token',
					'value' => $access_token,
					'compare' => '=',
					'type' => 'CHAR',
				),
			),
			'cache_results' => false,
		) );

		if ( ! $attendees ) {
			$this->error_flags['invalid_access_token'] = true;
			$this->redirect_with_error_flags();
			die();
		}

		if ( $attendees[0]->post_status == 'pending' )
			$this->notice( __( 'Please note that the payment for this set of tickets is still pending.', 'camptix' ) );
		?>
		<div id="tix">
		<?php do_action( 'camptix_notices' ); ?>
		<table class="tix-ticket-form">
			<thead>
				<tr>
					<th><?php _e( 'Tickets Summary', 'camptix' ); ?></th>
					<th><?php _e( 'Purchase Date', 'camptix' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$paged = 1; $count = 0;
			while ( $attendees = get_posts( array(
				'posts_per_page' => 200,
				'paged' => $paged++,
				'post_type' => 'tix_attendee',
				'post_status' => array( 'publish', 'pending' ),
				'meta_query' => array(
					array(
						'key' => 'tix_access_token',
						'value' => $access_token,
						'compare' => '=',
						'type' => 'CHAR',
					),
				),
				'cache_results' => false,
			) ) ) :

				$attendee_ids = array();
				foreach ( $attendees as $attendee )
					$attendee_ids[] = $attendee->ID;

				/**
				 * Magic here, to by-pass object caching. See Revenue report for more info.
				 * @todo perhaps this magic is not needed here, there won't be bulk purchases with 2k tickets.
				 */
				$this->filter_post_meta = $this->prepare_metadata_for( $attendee_ids );
				unset( $attendee_ids, $attendee );
			?>

				<?php foreach ( $attendees as $attendee ) : $count++; ?>

					<?php
						$edit_token = get_post_meta( $attendee->ID, 'tix_edit_token', true );
						$edit_link = $this->get_edit_attendee_link( $attendee->ID, $edit_token );
						$first_name = get_post_meta( $attendee->ID, 'tix_first_name', true );
						$last_name = get_post_meta( $attendee->ID, 'tix_last_name', true );

						if ( $this->is_refundable( $attendee->ID ) )
							$is_refundable = true;
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( sprintf( "%s %s", $first_name, $last_name ) ); ?></strong><br />
							<?php echo $this->get_ticket_title( intval( get_post_meta( $attendee->ID, 'tix_ticket_id', true ) ) ); ?>
						</td>
						<td>
							<?php echo mysql2date( get_option( 'date_format' ), $attendee->post_date ); ?>
						</td>
						<td>
							<a href="<?php echo esc_url( $edit_link ); ?>"><?php _e( 'Edit information', 'camptix' ); ?></a>
						</td>
					</tr>

					<?php
					// Delete caches individually rather than clean_post_cache( $attendee_id ),
					// prevents querying for children posts, saves a bunch of queries :)
					// wp_cache_delete( $attendee->ID, 'posts' );
					// wp_cache_delete( $attendee->ID, 'post_meta' );
					?>
				<?php endforeach; ?>
				<?php $this->filter_post_meta = false; // Cleanup the prepared data ?>
			<?php endwhile; ?>

			</tbody>
		</table>
		<?php if ( $is_refundable ) : ?>
		<p><?php printf( __( "Change of plans? Made a mistake? Don't worry, you can %s.", 'camptix' ), '<a href="' . esc_url( $this->get_refund_tickets_link( $access_token ) ) . '">' . __( 'request a refund', 'camptix' ) . '</a>' ); ?></p>
		<?php endif; ?>
		</div><!-- #tix -->
		<?php
		$contents = ob_get_contents();
		ob_end_clean();
		return $contents;
	}

	/**
	 * Allows attendees to edit their information.
	 */
	function form_edit_attendee() {
		global $post;
		ob_start();
		if ( ! isset( $_REQUEST['tix_edit_token'] ) || empty( $_REQUEST['tix_edit_token'] ) ) {
			$this->error_flags['invalid_edit_token'] = true;
			$this->redirect_with_error_flags();
		}

		if ( ! isset( $_REQUEST['tix_attendee_id'] ) || empty( $_REQUEST['tix_attendee_id'] ) ) {
			$this->error_flags['invalid_edit_token'] = true;
			$this->redirect_with_error_flags();
		}

		$attendee_id = intval( $_REQUEST['tix_attendee_id'] );
		$attendee = get_post( $attendee_id );
		$edit_token = $_REQUEST['tix_edit_token'];

		if ( ! $attendee || $attendee->post_type != 'tix_attendee' ) {
			$this->error_flags['invalid_edit_token'] = true;
			$this->redirect_with_error_flags();
		}

		if ( $edit_token !== get_post_meta( $attendee->ID, 'tix_edit_token', true ) ) {
			$this->error_flags['invalid_edit_token'] = true;
			$this->redirect_with_error_flags();
		}

		if ( $attendee->post_status != 'publish' && $attendee->post_status != 'pending' ) {
			if ( current_user_can( $this->caps['manage_options'] ) ) {
				$this->notice( __( 'This attendee is not published.', 'camptix' ) );
			} else {
				$this->error_flags['invalid_edit_token'] = true;
				$this->redirect_with_error_flags();
			}
		}

		$ticket_id = get_post_meta( $attendee->ID, 'tix_ticket_id', true );
		if ( ! $this->is_ticket_valid_for_display( $ticket_id ) ) {
			$this->error_flags['invalid_edit_token'] = true;
			$this->redirect_with_error_flags();
		}

		if ( $attendee->post_status == 'pending' )
			$this->notice( __( 'Please note that the payment for this ticket is still pending.', 'camptix' ) );

		$ticket = get_post( $ticket_id );
		$questions = $this->get_sorted_questions( $ticket->ID );
		$answers = (array) get_post_meta( $attendee->ID, 'tix_questions', true );
		$ticket_info = array(
			'first_name' => get_post_meta( $attendee->ID, 'tix_first_name', true ),
			'last_name' => get_post_meta( $attendee->ID, 'tix_last_name', true ),
			'email' => get_post_meta( $attendee->ID, 'tix_email', true ),
		);

		if ( isset( $_POST['tix_attendee_save'] ) ) {
			$errors = array();
			$posted = stripslashes_deep( $_POST );

			$new_ticket_info = $posted['tix_ticket_info'];
			$new_ticket_info = array_map( 'trim', $new_ticket_info );

			// todo validate new attendee data here, maybe wrap data validation.
			if ( empty( $new_ticket_info['first_name'] ) || empty( $new_ticket_info['last_name'] ) )
				$errors[] = __( 'Please fill in all required fields.', 'camptix' );

			if ( ! is_email( $new_ticket_info['email'] ) )
				$errors[] = __( 'You have entered an invalid e-mail, please try again.', 'camptix' );

			$new_answers = array();
			foreach ( $questions as $question ) {
				$question_key = sanitize_title_with_dashes( $question['field'] );
				if ( isset( $_POST['tix_ticket_questions'][$question_key] ) ) {
					$new_answers[$question_key] = stripslashes_deep( $posted['tix_ticket_questions'][$question_key] );
				}

				// @todo maybe check $user_values against $type and $question_values

				if ( $question['required'] && ( ! isset( $new_answers[$question_key] ) || empty( $new_answers[$question_key] ) ) ) {
					$errors[] = __( 'Please fill in all required fields.', 'camptix' );
				}
			}

			if ( count( $errors ) > 0 ) {
				$this->error( __( 'Your information has not been changed!', 'camptix' ) );
				foreach ( $errors as $error )
					$this->error( $error );

				// @todo maybe leave fields as $_POST'ed
			} else {

				// Save info
				update_post_meta( $attendee->ID, 'tix_first_name', $new_ticket_info['first_name'] );
				update_post_meta( $attendee->ID, 'tix_last_name', $new_ticket_info['last_name'] );
				update_post_meta( $attendee->ID, 'tix_email', $new_ticket_info['email'] );
				update_post_meta( $attendee->ID, 'tix_questions', $new_answers );

				wp_update_post( $attendee ); // triggers save_attendee

				$this->info( __( 'Your information has been saved!', 'camptix' ) );
				$this->log( 'Changed attendee data from frontend.', $attendee->ID, $_POST );
				$ticket_info = $new_ticket_info;
				$answers = $new_answers;
			}
		}
		?>
		<div id="tix">
			<?php do_action( 'camptix_notices' ); ?>
			<form action="<?php echo esc_url( add_query_arg( 'tix_action', 'edit_attendee' ) ); ?>#tix" method="POST">
				<input type="hidden" name="tix_attendee_save" value="1" />

				<h2><?php _e( 'Attendee Information', 'camptix' ); ?></h2>
				<table class="tix_tickets_table tix-attendee-form">
					<tbody>
						<tr>
							<th colspan="2">
								<?php echo $ticket->post_title; ?>
							</th>
						</tr>
						<tr>
							<td class="tix-required tix-left"><?php _e( 'First Name', 'camptix' ); ?> <span class="tix-required-star">*</span></td>
							<td class="tix-right"><input name="tix_ticket_info[first_name]" type="text" value="<?php echo esc_attr( $ticket_info['first_name'] ); ?>" /></td>
						</tr>
						<tr>
							<td class="tix-required tix-left"><?php _e( 'Last Name', 'camptix' ); ?> <span class="tix-required-star">*</span></td>
							<td class="tix-right"><input name="tix_ticket_info[last_name]" type="text" value="<?php echo esc_attr( $ticket_info['last_name'] ); ?>" /></td>
						</tr>
						<tr>
							<td class="tix-required tix-left"><?php _e( 'E-mail', 'camptix' ); ?> <span class="tix-required-star">*</span></td>
							<td class="tix-right"><input name="tix_ticket_info[email]" type="text" value="<?php echo esc_attr( $ticket_info['email'] ); ?>" /></td>
						</tr>

						<?php do_action( 'camptix_question_fields_init' ); ?>
						<?php foreach ( $questions as $question ) : ?>

							<?php
								$question_key = sanitize_title_with_dashes( $question['field'] );
								$question_type = $question['type'];
								$name = sprintf( 'tix_ticket_questions[%s]', sanitize_title_with_dashes( $question['field'] ) );
								$value = ( isset( $answers[$question_key] ) ) ? $answers[$question_key] : '';
							?>
							<tr>
								<td class="tix-left <?php if ( $question['required'] ) echo 'tix-required'; ?>"><?php echo esc_html( $question['field'] ); ?><?php if ( $question['required'] ) echo ' <span class="tix-required-star">*</span>'; ?></td>
								<td class="tix-right">
									<?php do_action( "camptix_question_field_$question_type", $name, $value, $question ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<input type="submit" value="<?php esc_attr_e( 'Save Attendee Information', 'camptix' ); ?>" style="float: right; cursor: pointer;" />
					<br class="tix-clear" />
				</p>
			</form>
		</div><!-- #tix -->
		<?php
		$contents = ob_get_contents();
		ob_end_clean();
		return $contents;
	}

	function form_refund_request() {
		die( 'needs implementation' );
		if ( ! $this->options['refunds_enabled'] || ! isset( $_REQUEST['tix_access_token'] ) || empty( $_REQUEST['tix_access_token'] ) ) {
			$this->error_flags['invalid_access_token'] = true;
			$this->redirect_with_error_flags();
			die();
		}

		$today = date( 'Y-m-d' );
		$refunds_until = $this->options['refunds_date_end'];
		if ( ! strtotime( $refunds_until ) || strtotime( $refunds_until ) < strtotime( $today ) ) {
			$this->error_flags['cannot_refund'] = true;
			$this->redirect_with_error_flags();
			die();
		}

		$access_token = $_REQUEST['tix_access_token'];

		// Let's get one attendee
 		$attendees = get_posts( array(
			'posts_per_page' => -1,
			'post_type' => 'tix_attendee',
			'post_status' => array( 'publish', 'pending' ),
			'meta_query' => array(
				array(
					'key' => 'tix_access_token',
					'value' => $access_token,
					'compare' => '=',
					'type' => 'CHAR',
				),
			),
		) );

		if ( ! $attendees ) {
			$this->error_flags['invalid_access_token'] = true;
			$this->redirect_with_error_flags();
			die();
		}

		$transactions = array();
		$is_refundable = false;
		$order_total = 0;
		$tickets = array();

		foreach ( $attendees as $attendee ) {
			$txn_id = get_post_meta( $attendee->ID, 'tix_paypal_transaction_id', true );
			if ( $txn_id ) {
				$transactions[$txn_id] = get_post_meta( $attendee->ID, 'tix_paypal_transaction_details', true );
				$order_total = get_post_meta( $attendee->ID, 'tix_order_total', true );
			}
			$ticket_id = get_post_meta( $attendee->ID, 'tix_ticket_id', true );

			if ( isset( $tickets[$ticket_id] ) )
				$tickets[$ticket_id]++;
			else
				$tickets[$ticket_id] = 1;

		}

		if ( count( $transactions ) != 1 || $order_total <= 0 ) {
			$this->error_flags['cannot_refund'] = true;
			$this->redirect_with_error_flags();
			die();
		}

		$transaction = array_shift( $transactions );
		if ( ! isset( $transaction['EMAIL'], $transaction['TRANSACTIONID'], $transaction['PAYMENTSTATUS'], $transaction['AMT'], $transaction['CURRENCYCODE'] ) ) {
			$this->error_flags['cannot_refund'] = true;
			$this->redirect_with_error_flags();
			die();
		}

		// Has a refund request been submitted?
		$reason = '';
		if ( isset( $_POST['tix_refund_request_submit'] ) ) {
			$reason = esc_html( $_POST['tix_refund_request_reason'] );
			$check = isset( $_POST['tix_refund_request_confirmed'] ) ? $_POST['tix_refund_request_confirmed'] : false;

			if ( ! $check ) {
				$this->error( __( 'You have to agree to the terms to request a refund.', 'camptix' ) );
			} else {

				$payload = array(
					'METHOD' => 'RefundTransaction',
					'TRANSACTIONID' => $transaction['TRANSACTIONID'],
					'REFUNDTYPE' => 'Full',
				);

				$txn = wp_parse_args( wp_remote_retrieve_body( $this->paypal_request( $payload ) ) );
				if ( isset( $txn['ACK'], $txn['REFUNDTRANSACTIONID'] ) && $txn['ACK'] == 'Success' ) {
					$refund_txn_id = $txn['REFUNDTRANSACTIONID'];
					foreach ( $attendees as $attendee ) {
						$this->log( sprintf( 'Refunded %s by user request in %s.', $transaction['TRANSACTIONID'], $refund_txn_id ), $attendee->ID, $txn, 'refund' );
						$this->log( 'Refund reason attached with data.', $attendee->ID, $reason, 'refund' );
						$attendee->post_status = 'refund';
						wp_update_post( $attendee );
					}

					$this->info( __( 'Your tickets have been successfully refunded.', 'camptix' ) );
					ob_end_clean();
					return $this->form_refund_success();
				} else {
					$this->error( __( 'Can not refund the transaction at this time. Please try again later.', 'camptix' ) );
				}
			}
		}

		ob_start();
		?>
		<div id="tix">
			<?php do_action( 'camptix_notices' ); ?>
			<form action="<?php echo esc_url( add_query_arg( 'tix_action', 'refund_request' ) ); ?>#tix" method="POST">
				<input type="hidden" name="tix_refund_request_submit" value="1" />

				<h2><?php _e( 'Refund Request', 'camptix' ); ?></h2>
				<table class="tix_tickets_table tix-attendee-form">
					<tbody>
						<tr>
							<th colspan="2">
								<?php _e( 'Request Details', 'camptix' ); ?>
							</th>
						</tr>
						<tr>
							<td class="tix-left"><?php _e( 'E-mail', 'camptix' ); ?></td>
							<td class="tix-right"><?php echo esc_html( $transaction['EMAIL'] ); ?></td>
						</tr>
						<tr>
							<td class="tix-left"><?php _e( 'Original Payment', 'camptix' ); ?></td>
							<td class="tix-right"><?php printf( "%s %s", $transaction['CURRENCYCODE'], $transaction['AMT'] ); ?></td>
						</tr>
						<tr>
							<td class="tix-left"><?php _e( 'Purchased Tickets', 'camptix' ); ?></td>
							<td class="tix-right">
								<?php foreach ( $tickets as $ticket_id => $count ) : ?>
									<?php echo esc_html( sprintf( "%s x%d", $this->get_ticket_title( $ticket_id ), $count ) ); ?><br />
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<td class="tix-left"><?php _e( 'Refund Amount', 'camptix' ); ?></td>
							<td class="tix-right"><?php printf( "%s %s", $transaction['CURRENCYCODE'], $transaction['AMT'] ); ?></td>
						</tr>
						<tr>
							<td class="tix-left"><?php _e( 'Refund Reason', 'camptix' ); ?></td>
							<td class="tix-right"><textarea name="tix_refund_request_reason"><?php echo esc_textarea( $reason ); ?></textarea></td>
						</tr>

					</tbody>
				</table>
				<p class="tix-description"><?php _e( 'Refunds can take up to several days to process. All purchased tickets will be cancelled. Partial refunds and refunds to a different account that the original purchaser, are unavailable. You have to agree to these terms before requesting a refund.', 'camptix' ); ?></p>
				<p class="tix-submit">
					<label><input type="checkbox" name="tix_refund_request_confirmed" value="1"> <?php _e( 'I agree to the above terms', 'camptix' ); ?></label>
					<input type="submit" value="<?php esc_attr_e( 'Send Request', 'camptix' ); ?>" />
					<br class="tix-clear" />
				</p>
			</form>
		</div><!-- #tix -->
		<?php
		$contents = ob_get_contents();
		ob_end_clean();
		return $contents;
	}

	function form_refund_success() {
		ob_start();
		?>
		<div id="tix">
			<?php do_action( 'camptix_notices' ); ?>
		</div>
		<?php
		$contents = ob_get_contents();
		ob_end_clean();
		return $contents;
	}

	/**
	 * Return true if an attendee_id is refundable.
	 * @todo implement
	 */
	function is_refundable( $attendee_id ) {
		return false;

		if ( ! $this->options['refunds_enabled'] )
			return false;

		$today = date( 'Y-m-d' );
		$refunds_until = $this->options['refunds_date_end'];

		if ( ! strtotime( $refunds_until ) )
			return false;

		if ( strtotime( $refunds_until ) < strtotime( $today ) )
			return false;

		$attendee = get_post( $attendee_id );
		if ( $attendee->post_status == 'publish' && (float) get_post_meta( $attendee->ID, 'tix_order_total', true ) > 0 && get_post_meta( $attendee->ID, 'tix_paypal_transaction_id', true ) )
			return true;

		return false;
	}

	/**
	 * Return the tickets page URL.
	 */
	function get_tickets_url() {
		$tickets_url = home_url();

		if ( isset( $this->tickets_url ) && esc_url( $this->tickets_url ) )
			return $this->tickets_url;

		$tickets_url = get_permalink( $this->get_tickets_post_id() );
		if ( ! $tickets_url )
			$tickets_url = home_url();

		// "Cache" for the request and return.
		$this->tickets_url = $tickets_url;
		return $tickets_url;
	}

	/**
	 * Looks for the [camptix] page and returns the page's id.
	 */
	function get_tickets_post_id() {
		$posts = get_posts( array(
			'post_type' => 'page',
			'post_status' => 'publish',
			's' => '[camptix]',
			'posts_per_page' => 1,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		) );

		if ( $posts )
			return $posts[0]->ID;

		return false;
	}

	/**
	 * Use this function to purge tickets page cache and update all counts.
	 * It sets a flag, but actual flushing happens only once during shutdown.
	 */
	function flush_tickets_page() {
		$this->flush_tickets_page = true;
	}

	function flush_tickets_page_seriously() {
		if ( ! isset( $this->flush_tickets_page ) || ! $this->flush_tickets_page )
			return;

		$tickets_post_id = $this->get_tickets_post_id();

		if ( ! $tickets_post_id )
			return;

		$page = get_post( $tickets_post_id );
		wp_update_post( $page );
		clean_post_cache( $tickets_post_id );

		// Super-cache compatibility.
		if ( function_exists( 'wp_cache_post_id_gc' ) )
			wp_cache_post_id_gc( $this->get_tickets_url(), $tickets_post_id );
	}

	function get_edit_attendee_link( $attendee_id, $edit_token ) {
		$tickets_url = $this->get_tickets_url();
		$edit_link = add_query_arg( array(
			'tix_action' => 'edit_attendee',
			'tix_attendee_id' => $attendee_id,
			'tix_edit_token' => $edit_token,
		), $tickets_url );

		// Anchor!
		$edit_link .= '#tix';
		return $edit_link;
	}

	function get_access_tickets_link( $access_token ) {
		$tickets_url = $this->get_tickets_url();
		$edit_link = add_query_arg( array(
			'tix_action' => 'access_tickets',
			'tix_access_token' => $access_token,
		), $tickets_url );

		$edit_link .= '#tix';
		return $edit_link;
	}

	function get_refund_tickets_link( $access_token ) {
		$tickets_url = $this->get_tickets_url();
		$edit_link = add_query_arg( array(
			'tix_action' => 'refund_request',
			'tix_access_token' => $access_token,
		), $tickets_url );

		$edit_link .= '#tix';
		return $edit_link;
	}

	function is_ticket_valid_for_display( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return false;
		if ( $post->post_type != 'tix_ticket' ) return false;
		return true;
	}

	/**
	 * Returns true if a ticket is valid for purchase.
	 */
	function is_ticket_valid_for_purchase( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return false;
		if ( $post->post_type != 'tix_ticket' ) return false;
		if ( $post->post_status != 'publish' ) return false;

		$via_reservation = false;
		if ( isset( $this->reservation ) && $this->reservation )
			$via_reservation = $this->reservation['token'];

		if ( $this->get_remaining_tickets( $post_id, $via_reservation ) < 1 ) return false;

		$start = get_post_meta( $post_id, 'tix_start', true );
		$end = get_post_meta( $post_id, 'tix_end', true );

		// Not started yet
		if ( ! empty( $start ) && strtotime( $start ) > time() )
			return false;

		// Already ended.
		if ( ! empty( $end ) && strtotime( $end . ' +1 day' ) < time() )
			return false;

		return true;
	}

	function get_ticket_title( $post_id ) {
		if ( $this->is_ticket_valid_for_display( $post_id ) && $post = get_post( $post_id ) )
			return $post->post_title;
	}

	/**
	 * Returns the number of remaining tickets according to number of published attendees.
	 * @todo maybe cache values and bust in purchase process.
	 */
	function get_remaining_tickets( $post_id, $via_reservation = false ) {
		$remaining = 0;
		if ( $this->is_ticket_valid_for_display( $post_id ) ) {
			$quantity = intval( get_post_meta( $post_id, 'tix_quantity', true ) );
			$remaining = $quantity - $this->get_purchased_tickets_count( $post_id );
		}

		// Look for reservations
		$reservations = $this->get_reservations( $post_id );
		foreach ( $reservations as $reservation ) {

			// If it's a reservation, don't subtract tickets.
			if ( $via_reservation && $reservation['token'] == $via_reservation && $reservation['ticket_id'] == $post_id )
				continue;

			// Subtract ones already purchased
			$reserved_tickets = $reservation['quantity'] - $this->get_purchased_tickets_count( $post_id, $reservation['token'] );
			$remaining -= $reserved_tickets;
		}

		return $remaining;
	}

	function get_purchased_tickets_count( $post_id, $via_reservation = false ) {
		$purchased = 0;

		$meta_query = array( array(
			'key' => 'tix_ticket_id',
			'value' => $post_id,
			'compare' => '=',
			'type' => 'CHAR',
		) );

		if ( $via_reservation ) {
			$meta_query[] = array(
				'key' => 'tix_reservation_token',
				'value' => $via_reservation,
				'compare' => '=',
				'type' => 'CHAR',
			);
		}

		$attendees = new WP_Query( array(
			'post_type' => 'tix_attendee',
			'posts_per_page' => 1,
			'post_status' => array( 'publish', 'pending' ),
			'meta_query' => $meta_query,
		) );

		if ( $attendees->found_posts > 0 )
			$purchased = $attendees->found_posts;

		return $purchased;
	}

	/**
	 * Return a coupon object by the coupon name (title).
	 */
	function get_coupon_by_code( $code ) {
		$code = trim( $code );
		if ( empty( $code ) )
			return false;

		$coupon = get_page_by_title( trim( $code ), OBJECT, 'tix_coupon' );
		if ( $coupon && $coupon->post_type == 'tix_coupon' ) {
			return $coupon;
		}

		return false;
	}

	/**
	 * Returns true if one con use a coupon.
	 */
	function is_coupon_valid_for_use( $coupon_id ) {
		$coupon = get_post( $coupon_id );
		if ( $coupon->post_type != 'tix_coupon' ) return false;
		if ( $coupon->post_status != 'publish' ) return false;
		if ( $this->get_remaining_coupons( $coupon->ID ) < 1 ) return false;

		$start = get_post_meta( $coupon->ID, 'tix_coupon_start', true );
		$end = get_post_meta( $coupon->ID, 'tix_coupon_end', true );

		if ( ! empty( $start ) && strtotime( $start ) > time() )
			return false;

		if ( ! empty( $end ) && strtotime( $end . ' +1 day' ) < time() )
			return false;

		return true;
	}

	/**
	 * Returns an array of all published coupons.
	 */
	function get_all_coupons() {
		$coupons = (array) get_posts( array(
			'post_type' => 'tix_coupon',
			'post_status' => 'publish',
			'posts_per_page' => -1,
		) );

		return $coupons;
	}

	/**
	 * Return true if there's at least one coupon you can use.
	 */
	function have_coupons() {
		$coupons = $this->get_all_coupons();
		foreach ( $coupons as $coupon )
			if ( $this->is_coupon_valid_for_use( $coupon->ID ) )
				return true;

		return false;
	}

	/**
	 * Returns the number of available coupons by coupon_id
	 */
	function get_remaining_coupons( $coupon_id ) {
		$remaining = 0;
		$coupon = get_post( $coupon_id );
		if ( $coupon && $coupon->post_type == 'tix_coupon' ) {
			$quantity = intval( get_post_meta( $coupon->ID, 'tix_coupon_quantity', true ) );
			$remaining = $quantity;

			$used = $this->get_used_coupons_count( $coupon_id );
			$remaining -= $used;
		}
		return $remaining;
	}

	function get_used_coupons_count( $coupon_id ) {
		$used = 0;
		$coupon = get_post( $coupon_id );
		if ( $coupon && $coupon->post_type == 'tix_coupon' ) {
			$attendees = new WP_Query( array(
				'post_type' => 'tix_attendee',
				'posts_per_page' => 1,
				'post_status' => array( 'publish', 'pending' ),
				'meta_query' => array(
					array(
						'key' => 'tix_coupon_id',
						'value' => $coupon_id,
						'compare' => '=',
						'type' => 'CHAR',
					)
				),
			) );

			if ( $attendees->found_posts > 0 )
				$used += $attendees->found_posts;
		}
		return $used;
	}

	/**
	 * Review Timeout Payments
	 *
	 * This routine looks up old draft attendee posts and puts
	 * their status into Timeout.
	 */
	function review_timeout_payments() {

		// Nothing to do for archived sites.
		if ( $this->options['archived'] )
			return;

		$processed = 0;
		$current_loop = 1;
		$max_loops = 500;

		while ( $attendees = get_posts( array(
			'post_type' => 'tix_attendee',
			'post_status' => 'draft',
			'posts_per_page' => 100,
			'cache_results' => false,
			'meta_query' => array(
				array(
					'key' => 'tix_timestamp',
					'compare' => '<',
					'value' => time() - 60 * 60 * 24, // 24 hours ago
					'type' => 'NUMERIC',
				),
				array(
					'key' => 'tix_timestamp',
					'compare' => '>',
					'value' => 0,
					'type' => 'NUMERIC',
				),
			),
		) ) ) {

			foreach ( $attendees as $attendee ) {
				$attendee->post_status = 'timeout';
				wp_update_post( $attendee );
				$processed++;
			}

			// Just in case we get stuck in here
			if ( $current_loop++ >= $max_loops )
				break;
		}

		$this->log( sprintf( 'Reviewed timeout payments and set %d attendees to timeout status.', $processed ) );
	}

	/**
	 * Step 3: Uses a payment method to perform a checkout.
	 */
	function form_checkout() {

		$attendees = array();
		$errors = array();
		$receipt_email = false;
		$payment_method = false;

		if ( isset( $_POST['tix_payment_method'] ) && array_key_exists( $_POST['tix_payment_method'], $this->get_enabled_payment_methods() ) )
			$payment_method = $_POST['tix_payment_method'];
		elseif ( ! empty( $this->order['price'] ) && $this->order['price'] > 0 ) {
			$this->error_flags['invalid_payment_method'] = true;
		}

		foreach( (array) $_POST['tix_attendee_info'] as $i => $attendee_info ) {
			$attendee = new stdClass;

			if ( ! isset( $attendee_info['ticket_id'] ) || ! array_key_exists( $attendee_info['ticket_id'], $this->tickets_selected ) ) {
				$this->error_flags['no_ticket_id'] = true;
				continue;
			}

			$ticket = $this->tickets[$attendee_info['ticket_id']];
			if ( ! $this->is_ticket_valid_for_purchase( $ticket->ID ) ) {
				$this->error_flags['tickets_excess'] = true;
				continue;
			}

			$attendee_info = array_map( 'trim', $attendee_info );

			if ( empty( $attendee_info['first_name'] ) || empty( $attendee_info['last_name'] ) )
				$this->error_flags['required_fields'] = true;

			if ( ! is_email( $attendee_info['email'] ) )
				$this->error_flags['invalid_email'] = true;

			$answers = array();
			if ( isset( $_POST['tix_attendee_questions'][$i] ) ) {
				$questions = $this->get_sorted_questions( $ticket->ID );

				foreach ( $questions as $question ) {
					$question_key = sanitize_title_with_dashes( $question['field'] );
					if ( isset( $_POST['tix_attendee_questions'][$i][$question_key] ) )
						$answers[$question_key] = $_POST['tix_attendee_questions'][$i][$question_key];

					if ( $question['required'] && ( ! isset( $answers[$question_key] ) || empty( $answers[$question_key] ) ) ) {
						$this->error_flags['required_fields'] = true;
						break;
					}
				}
			}

			// @todo make more checks here

			$attendee->ticket_id = $ticket->ID;
			$attendee->first_name = $attendee_info['first_name'];
			$attendee->last_name = $attendee_info['last_name'];
			$attendee->email = $attendee_info['email'];
			$attendee->answers = $answers;

			if ( isset( $_POST['tix_receipt_email'] ) && $_POST['tix_receipt_email'] == $i )
				$receipt_email = $attendee->email;

			$attendees[] = $attendee;

			unset( $attendee, $answers, $questions, $ticket );
		}

		// @todo maybe check if email is one of the attendees emails
		if ( isset( $_POST['tix_receipt_email_js'] ) && is_email( $_POST['tix_receipt_email_js']) )
			$receipt_email = $_POST['tix_receipt_email_js'];

		if ( ! is_email( $receipt_email ) )
			$this->error_flags['no_receipt_email'] = true;

		// If there's at least one error, don't proceed with checkout.
		if ( $this->error_flags ) {
			return $this->form_attendee_info();
		}

		$this->verify_order( $this->order );

		$reservation_quantiny = 0;
		if ( isset( $this->reservation ) && $this->reservation )
			$reservation_quantiny = $this->reservation['quantity'];

		$log_data = array(
			'post' => $_POST,
			'server' => $_SERVER,
		);

		$access_token = md5( 'tix-access-token' . print_r( $_POST, true ) . time() . rand( 1, 9999 ) );
		$payment_token = md5( 'tix-payment-token' . $access_token . time() . rand( 1, 9999 ) );

		foreach ( $attendees as $attendee ) {
			$post_id = wp_insert_post( array(
				'post_title' => $attendee->first_name . " " . $attendee->last_name,
				'post_type' => 'tix_attendee',
				'post_status' => 'draft',
			) );

			if ( $post_id ) {
				$this->log( 'Created attendee draft.', $post_id, $log_data );

				$edit_token = md5( sprintf( 'tix-edit-token-%d-%s-%s', $post_id, $access_token, time() ) );

				update_post_meta( $post_id, 'tix_access_token', $access_token );
				update_post_meta( $post_id, 'tix_payment_token', $payment_token );
				update_post_meta( $post_id, 'tix_edit_token', $edit_token );
				update_post_meta( $post_id, 'tix_payment_method', $payment_method );
				update_post_meta( $post_id, 'tix_order', $this->order );

				update_post_meta( $post_id, 'tix_timestamp', time() );
				update_post_meta( $post_id, 'tix_ticket_id', $attendee->ticket_id );
				update_post_meta( $post_id, 'tix_first_name', $attendee->first_name );
				update_post_meta( $post_id, 'tix_last_name', $attendee->last_name );
				update_post_meta( $post_id, 'tix_email', $attendee->email );
				update_post_meta( $post_id, 'tix_tickets_selected', $this->tickets_selected );
				update_post_meta( $post_id, 'tix_receipt_email', $receipt_email );

				// Cash
				update_post_meta( $post_id, 'tix_order_total', (float) $this->order['total'] );
				update_post_meta( $post_id, 'tix_ticket_price', (float) $this->tickets[$attendee->ticket_id]->tix_price );
				update_post_meta( $post_id, 'tix_ticket_discounted_price', (float) $this->tickets[$attendee->ticket_id]->tix_discounted_price );

				// @todo sanitize questions
				update_post_meta( $post_id, 'tix_questions', $attendee->answers );

				if ( $this->coupon && in_array( $attendee->ticket_id, $this->coupon->tix_applies_to ) ) {
					update_post_meta( $post_id, 'tix_coupon_id', $this->coupon->ID );
					update_post_meta( $post_id, 'tix_coupon', $this->coupon->post_title );
				}

				if ( isset( $this->reservation ) && $this->reservation && $this->reservation['ticket_id'] == $attendee->ticket_id ) {
					if ( $reservation_quantiny > 0 ) {
						update_post_meta( $post_id, 'tix_reservation_id', $this->reservation['id'] );
						update_post_meta( $post_id, 'tix_reservation_token', $this->reservation['token'] );
						$reservation_quantiny--;
					}
				}

				// Write post content (triggers save_post).
				wp_update_post( array( 'ID' => $post_id ) );
				$attendee->post_id = $post_id;
			}
		}

		$attendees_posts = array();
		foreach ( $attendees as $attendee )
			$attendees_posts[] = get_post( $attendee->post_id );

		$attendees = $attendees_posts;
		unset( $attendees_posts, $attendee );

		// Do we need to pay?
		if ( $this->order['total'] > 0 ) {

			$payment_method_obj = $this->get_payment_method_by_id( $payment_method );

			// Bail if a payment method does not exist.
			if ( ! $payment_method_obj ) {
				$payment_data = array(
					'error' => 'Invalid payment method.',
					'data' => $_POST,
				);

				$this->payment_result( $payment_token, self::PAYMENT_STATUS_FAILED, $payment_data );
				return;
			}

			$payment_method_obj->payment_checkout( $payment_token );

			// Check whether there were any immediate payment errors.
			if ( $this->error_flags )
				return $this->form_attendee_info();

		} else { // free beer for everyone!
			$this->payment_result( $payment_token, self::PAYMENT_STATUS_COMPLETED );
		}
	}

	/**
	 * @todo implement
	 */
	function verify_order( &$order = array() ) {
		$tickets_objects = get_posts( array(
			'post_type' => 'tix_ticket',
			'post_status' => 'publish',
			'posts_per_page' => -1,
		) );

		$coupon = null;
		$reservation = null;
		$via_reservation = false;

		// Let's check the coupon first.
		if ( isset( $order['coupon'] ) && ! empty( $order['coupon'] ) ) {
			$coupon = $this->get_coupon_by_code( $order['coupon'] );
			if ( $coupon && $this->is_coupon_valid_for_use( $coupon->ID ) ) {
				$coupon->tix_coupon_remaining = $this->get_remaining_coupons( $coupon->ID );
				$coupon->tix_discount_price = (float) get_post_meta( $coupon->ID, 'tix_discount_price', true );
				$coupon->tix_discount_percent = (int) get_post_meta( $coupon->ID, 'tix_discount_percent', true );
				$coupon->tix_applies_to = (array) get_post_meta( $coupon->ID, 'tix_applies_to' );
			} else {
				$order['coupon'] = null;
				$coupon = null;
				$this->error_flag( 'invalid_coupon' );
			}
		} else {
			$order['coupon'] = null;
			$coupon = null;
		}

		// Then check the reservation.
		if ( isset( $order['reservation_id'], $order['reservation_token'] ) ) {
			$reservation = $this->get_reservation( $order['reservation_token'] );

			if ( $reservation && $reservation['id'] == strtolower( $order['reservation_id'] ) && $this->is_reservation_valid_for_use( $reservation['token'] ) ) {
				$via_reservation = $reservation['token'];
			} else {
				$this->error_flags['invalid_reservation'] = true;
				$reservation = null;
				$via_reservation = false;
			}
		}

		$tickets = array();
		foreach ( $tickets_objects as $ticket ) {
			$ticket->tix_price = (float) get_post_meta( $ticket->ID, 'tix_price', true );
			$ticket->tix_remaining = $this->get_remaining_tickets( $ticket->ID, $via_reservation );
			$ticket->tix_coupon_applied = false;
			$ticket->tix_discounted_price = $ticket->tix_price;

			if ( $coupon && in_array( $ticket->ID, $coupon->tix_applies_to ) ) {
				$ticket->tix_coupon_applied = true;
				$ticket->tix_discounted_text = '';

				if ( $coupon->tix_discount_price > 0 ) {
					$ticket->tix_discounted_price = number_format( $ticket->tix_price - $coupon->tix_discount_price, 2, '.', '' );
				} elseif ( $coupon->tix_discount_percent > 0 ) {
					$ticket->tix_discounted_price = number_format( $ticket->tix_price - ( $ticket->tix_price * $coupon->tix_discount_percent / 100 ), 2, '.', '' );
				}

				if ( $ticket->tix_discounted_price < 0 )
					$ticket->tix_discounted_price = 0;
			}

			$tickets[ $ticket->ID ] = $ticket;
		}

		unset( $tickets_objects, $ticket );
		$coupon_used = 0;

		$items_clean = array();
		foreach ( $order['items'] as $item ) {

			/**
			 * @todo check items, reservation, coupon.
			 */

			if ( ! isset( $tickets[ $item['id'] ] ) ) {
				$this->error_flag( 'invalid_ticket_id' );
				continue;
			}

			$ticket = $tickets[ $item['id'] ];

			if ( $ticket->tix_remaining < 1 ) {
				$this->error_flag( 'tickets_excess' );
				echo 'setting tickets excess';
				continue;
			}

			if ( $ticket->tix_remaining < $item['quantity'] ) {
				$item['quantity'] = $ticket->tix_remaining;
				$this->error_flag( 'tickets_excess' );
			}

			if ( $item['quantity'] > 10 ) {
				$item['quantity'] = min( 10, $ticket->tix_remaining );
				$this->error_flag( 'tickets_excess' );
			}

			// Track coupons usage quantity.
			if ( $ticket->tix_coupon_applied ) {
				$coupon_used += $item['quantity'];
				if ( $coupon_used > $coupon->tix_coupon_remaining ) {

					// How much more coupons are we allowed to use?
					$quantity_allowed = $coupon->tix_coupon_remaining - ( $coupon_used - $item['quantity'] );

					// Revert the # of used coupons.
					$coupon_used = ( $coupon_used - $item['quantity'] );

					// Set the new allowed quantity and add it to used coupons.
					$item['quantity'] = $quantity_allowed;
					$coupon_used += $item['quantity'];

					$this->error_flag( 'coupon_excess' );
				}
			}

			// Don't add empty items.
			if ( $item['quantity'] < 1 )
				continue;

			// Check pricing
			if ( (float) $item['price'] != (float) $ticket->tix_discounted_price ) {
				$this->error_flag( 'tickets_price_error' );
				continue;
			}

			$items_clean[] = $item;
		}

		// Clean up the original array.
		$order['items'] = $items_clean;
		unset( $items_clean );

		if ( count( $order['items'] ) < 1 )
			$this->error_flag( 'no_tickets_selected' );

		// Recount the total.
		$order['total'] = 0;
		foreach ( $order['items'] as $item )
			$order['total'] += $item['price'] * $item['quantity'];

		if ( ! empty( $this->error_flags ) ) {

			if ( 'attendee_info' == get_query_var( 'tix_action' ) ) {
				// print_r($this->error_flags);
			} elseif( 'checkout' == get_query_var( 'tix_action' ) ) {
				// print_r($this->error_flags);
			} else {
				$this->redirect_with_error_flags();
			}
		}

		return true;
	}

	/**
	 * Returns a payment method class object by id/key.
	 */
	function get_payment_method_by_id( $id ) {
		$payment_method = apply_filters( 'camptix_get_payment_method_by_id', null, $id );
		return $payment_method;
	}

	function get_available_payment_methods() {
		return (array) apply_filters( 'camptix_available_payment_methods', array() );
	}

	function get_enabled_payment_methods() {
		$enabled = array();
		foreach ( $this->get_available_payment_methods() as $key => $method )
			if ( isset( $this->options['payment_methods'][ $key ] ) && $this->options['payment_methods'][ $key ] )
				if ( $this->get_payment_method_by_id( $key )->supports_currency( $this->options['currency'] ) )
					$enabled[ $key ] = $method;

		return $enabled;
	}

	function payment_result( $payment_token, $result, $data = array() ) {
		if ( empty( $payment_token ) )
			die( 'Do not call payment_result without a payment token.' );

		$attendees = get_posts( array(
			'posts_per_page' => -1,
			'post_type' => 'tix_attendee',
			'post_status' => array( 'draft', 'pending', 'publish', 'cancel', 'refund', 'failed' ),
			'meta_query' => array(
				array(
					'key' => 'tix_payment_token',
					'compare' => '=',
					'value' => $payment_token,
					'type' => 'CHAR',
				),
			),
		) );

		if ( ! $attendees ) {
			$this->log( 'Could not find attendees by payment token', null, $_POST );
			die();
		}

		$transaction_id = null;
		$transaction_details = null;
		$attendees_status = $attendees[0]->post_status;
		$status_changed = false;

		// If this is not the first payment result, let's get the old txn details before updating.
		if ( $attendees_status != 'draft' ) {
			$transaction_id = get_post_meta( $attendees[0]->ID, 'tix_transaction_id', true );
			$transaction_details = get_post_meta( $attendees[0]->ID, 'tix_transaction_details', true );
		}

		if ( ! empty( $data['transaction_id'] ) )
			$transaction_id = $data['transaction_id'];

		if ( ! empty( $data['transaction_details'] ) )
			$transaction_details = $data['transaction_details'];

		foreach ( $attendees as $attendee ) {

			$old_post_status = $attendee->post_status;

			update_post_meta( $attendee->ID, 'tix_transaction_id', $transaction_id );
			update_post_meta( $attendee->ID, 'tix_transaction_details', $transaction_details );

			if ( self::PAYMENT_STATUS_CANCELLED == $result ) {
				$attendee->post_status = 'cancel';
				wp_update_post( $attendee );
			}

			if ( self::PAYMENT_STATUS_FAILED == $result ) {
				$attendee->post_status = 'failed';
				wp_update_post( $attendee );
			}

			if ( self::PAYMENT_STATUS_COMPLETED == $result ) {
				$attendee->post_status = 'publish';
				wp_update_post( $attendee );
			}

			if ( self::PAYMENT_STATUS_PENDING == $result ) {
				$attendee->post_status = 'pending';
				wp_update_post( $attendee );
			}

			if ( self::PAYMENT_STATUS_REFUNDED == $result ) {
				$attendee->post_status = 'refund';
				wp_update_post( $attendee );
			}

			$this->log( sprintf( 'Payment result for %s.', $transaction_id ), $attendee->ID, $data );

			if ( $old_post_status != $attendee->post_status ) {
				$status_changed = true;
				$this->log( sprintf( 'Attendee status has been changed to %s', $attendee->post_status ), $attendee->ID );
			} else {
				$this->log( sprintf( 'Received payment result for %s but status has not changed.', $transaction_id ), $attendee->ID );
			}
		}

		// We'll need these for proper e-mail notifications.
		$from_status = $attendees_status;
		$to_status = $attendees[0]->post_status;

		// If the status hasn't changed, there's nothing much we can do here.
		if ( ! $status_changed ) {
			if ( in_array( $to_status, array( 'pending', 'publish' ) ) ) {
				// Show the purchased tickets.
				$access_token = get_post_meta( $attendees[0]->ID, 'tix_access_token', true );
				$url = add_query_arg( array( 'tix_action' => 'access_tickets', 'tix_access_token' => $access_token ), $this->get_tickets_url() );
				wp_safe_redirect( $url . '#tix' );
				die();
			}
			return;
		}

		// Send out the tickets and receipt if necessary.
		$this->email_tickets( $payment_token, $from_status, $to_status );

		// Let's make a clean exit out of all of this.
		switch ( $result ) :

			case self::PAYMENT_STATUS_CANCELLED :
				$this->error_flag( 'payment_cancelled' );
				$this->redirect_with_error_flags();
				die();
				break;

			case self::PAYMENT_STATUS_COMPLETED :

				// Show the purchased tickets.
				$access_token = get_post_meta( $attendees[0]->ID, 'tix_access_token', true );
				$url = add_query_arg( array( 'tix_action' => 'access_tickets', 'tix_access_token' => $access_token ), $this->get_tickets_url() );
				wp_safe_redirect( $url . '#tix' );
				die();
				break;

			case self::PAYMENT_STATUS_FAILED :
				$error_code = 0;
				if ( ! empty( $data['error_code'] ) )
					$error_code = $data['error_code'];

				// If payment errors were immediate (right on the checkout page), return.
				if ( 'checkout' == get_query_var( 'tix_action' ) ) {
					$this->error_flag( 'payment_failed' );
					// $this->error_data['boogie'] = 'woogie'; // @todo Add error data and parse it
					return;

				} else {
					$this->error_flag( 'payment_failed' );
					$this->redirect_with_error_flags();
					die();
				}
				break;

			case self::PAYMENT_STATUS_PENDING :

				// Show the purchased tickets.
				$access_token = get_post_meta( $attendees[0]->ID, 'tix_access_token', true );
				$url = add_query_arg( array( 'tix_action' => 'access_tickets', 'tix_access_token' => $access_token ), $this->get_tickets_url() );
				wp_safe_redirect( $url . '#tix' );
				die();
				break;

			case self::PAYMENT_STATUS_REFUNDED :
				// @todo what do we do when a purchase is refunded?
				die();
				break;

			default:
				break;

		endswitch;
	}

	function email_tickets( $payment_token = false, $from_status = 'draft', $to_status = 'publish' ) {
		if ( ! $payment_token )
			return;

		$attendees = get_posts( array(
			'posts_per_page' => -1,
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
			return;

		$access_token = get_post_meta( $attendees[0]->ID, 'tix_access_token', true );
		$receipt_email = get_post_meta( $attendees[0]->ID, 'tix_receipt_email', true );
		$order = get_post_meta( $attendees[0]->ID, 'tix_order', true );

		$receipt_content = '';
		foreach ( $order['items'] as $item ) {
			$ticket = get_post( $item['id'] );
			$receipt_content .= sprintf( "* %s (%s) x%d = %s\n", $ticket->post_title, $this->append_currency( $item['price'], false ), $item['quantity'], $this->append_currency( $item['price'] * $item['quantity'], false ) );
		}

		if ( isset( $order['coupon'] ) && $order['coupon'] )
			$receipt_content .= sprintf( '* ' . __( 'Coupon used: %s') . "\n", $order['coupon'] );

		$receipt_content .= sprintf( "* " . __( 'Total: %s', 'camptix' ), $this->append_currency( $order['total'], false ) );
		$signature = apply_filters( 'camptix_ticket_email_signature', __( 'Let us know if you have any questions!', 'camptix' ) );

		/**
		 * If there's more than one attendee we should e-mail a separate ticket to each attendee,
		 * but only if the payment was from draft to completed or pending.For non-draft to ... tickets
		 * we send out a receipt only.
		 */
		if ( count( $attendees ) > 1 && $from_status == 'draft' && ( in_array( $to_status, array( 'publish', 'pending' ) ) ) ) {
			foreach ( $attendees as $attendee ) {
				$attendee_email = get_post_meta( $attendee->ID, 'tix_email', true );
				$edit_token = get_post_meta( $attendee->ID, 'tix_edit_token', true );
				$edit_link = $this->get_edit_attendee_link( $attendee->ID, $edit_token );

				$content = sprintf( __( "Hi there!\n\nThank you so much for purchasing a ticket and hope to see you soon at our event. You can edit your information at any time before the event, by visiting the following link:\n\n%s\n\n%s", 'camptix' ), $edit_link, $signature );
				$subject = sprintf( __( "Your Ticket to %s", 'camptix' ), $this->options['event_name'] );

				$this->log( sprintf( 'Sent ticket e-mail to %s and receipt to %s.', $attendee_email, $receipt_email ), $attendee->ID );
				$this->wp_mail( $attendee_email, $subject, $content );

				do_action( 'camptix_ticket_emailed', $attendee->ID );
			}
		}

		/**
		 * Let's now e-mail the receipt, directly after a purchas has been made.
		 */
		if ( $from_status == 'draft' && ( in_array( $to_status, array( 'publish', 'pending' ) ) ) ) {
			$edit_link = $this->get_access_tickets_link( $access_token );

			$payment_status = '';

			// If the status is pending, let the buyer know about that in the receipt.
			if ( 'pending' == $to_status )
				$payment_status =  sprintf( __( 'Your payment status is: %s. You will receive a notification e-mail once your payment is completed.', 'camptix' ), 'pending' ) . "\n\n";

			if ( count( $attendees ) == 1 ) {

				$content = sprintf( __( "Hey there!\n\nYou have purchased the following ticket:\n\n%s\n\nYou can edit the information for the purchased ticket at any time before the event, by visiting the following link:\n\n%s\n\n%s%s", 'camptix' ), $receipt_content, $edit_link, $payment_status, $signature );
				$subject = sprintf( __( "Your Ticket to %s", 'camptix' ), $this->options['event_name'] );

				$this->log( sprintf( 'Sent a ticket and receipt to %s.', $receipt_email ), $attendees[0]->ID );
				$this->wp_mail( $receipt_email, $subject, $content );

				do_action( 'camptix_ticket_emailed', $attendees[0]->ID );

			} elseif ( count( $attendees ) > 1 ) {

				$content = sprintf( __( "Hey there!\n\nYou have purchased the following tickets:\n\n%s\n\nYou can edit the information for all the purchased tickets at any time before the event, by visiting the following link:\n\n%s\n\n%s%s", 'camptix' ), $receipt_content, $edit_link, $payment_status, $signature );
				$subject = sprintf( __( "Your Tickets to %s", 'camptix' ), $this->options['event_name'] );

				$this->log( sprintf( 'Sent a receipt to %s.', $receipt_email ), $attendees[0]->ID );
				$this->wp_mail( $receipt_email, $subject, $content );
			}
		}

		/**
		 * This is mainly for notifications that would set the status after an IPN.
		 */
		if ( $from_status == 'pending' && $to_status == 'publish' ) {
			$edit_link = $this->get_access_tickets_link( $access_token );
			$subject = sprintf( __( "Your Payment for %s", 'camptix' ), $this->options['event_name'] );
			$content = sprintf( __( "Hey there!\n\nYour payment for %s has been completed, looking forward to seeing you at the event! You can access and change your tickets information by visiting the following link:\n\n%s\n\nLet us know if you need any help!", 'camptix' ), $this->options['event_name'], $edit_link );

			$this->log( sprintf( 'Sending completed e-mail notification after IPN to %s.', $receipt_email ), $attendees[0]->ID );
			$this->wp_mail( $receipt_email, $subject, $content );
		}

		if ( $from_status == 'pending' && $to_status == 'failed' ) {
			$subject = sprintf( __( "Your Payment for %s", 'camptix' ), $this->options['event_name'] );
			$content = sprintf( __( "Hey there!\n\nWe're so sorry, but it looks like your payment for %s has failed! Please check your payment transactions for more details. If you still wish to attend the event, feel free to purchase a new ticket using the following link:\n\n%s\n\nLet us know if you need any help!", 'camptix' ), $this->options['event_name'], $this->get_tickets_url() );

			$this->log( sprintf( 'Sending failed e-mail notification after IPN to %s.', $receipt_email ), $attendees[0]->ID );
			$this->wp_mail( $receipt_email, $subject, $content );
		}
	}

	function redirect_with_error_flags( $query_args = array() ) {
		$query_args['tix_error'] = 1;
		$query_args['tix_errors'] = array();
		$query_args['tix_error_data'] = array();

		foreach ( (array) $this->error_flags as $key => $value )
			if ( $value ) $query_args['tix_errors'][] = $key;

		foreach ( (array) $this->error_data as $key => $value )
			$query_args['tix_error_data'][$key] = $value;

		$url = esc_url_raw( add_query_arg( $query_args, $this->get_tickets_url() ) . '#tix' );
		wp_safe_redirect( $url );
		die();
	}

	function error_flag( $flag ) {
		$this->error_flags[ $flag ] = true;
		return;
	}

	/**
	 * Sorts an array by the 'order' key.
	 */
	private function usort_by_order( $a, $b ) {
		$a = intval( $a['order'] );
		$b = intval( $b['order'] );
		if ( $a == $b ) return 0;
		return ( $a < $b ) ? -1 : 1;
	}

	/**
	 * Sorts an array by the 'count' keys.
	 */
	private function usort_by_count( $a, $b ) {
		$a = $a['count'];
		$b = $b['count'];

		if ( $a == $b ) return 0;
		return ( $a < $b ) ? 1 : -1;
	}

	public function notice( $notice ) {
		$this->notices[] = $notice;
	}

	public function error( $error ) {
		$this->errors[] = $error;
	}

	public function info( $info ) {
		$this->infos[] = $info;
	}

	protected function admin_notice( $notice ) {
		$this->admin_notices[] = $notice;
	}

	function do_notices() {

		$printed = array();
		if ( count( $this->errors ) > 0 ) {
			echo '<div id="tix-errors">';
			foreach ( $this->errors as $message ) {
				if ( in_array( $message, $printed ) ) continue;

				$printed[] = $message;
				echo '<p class="tix-error">' . esc_html( $message ) . '</p>';
			}
			echo '</div><!-- #tix-errors -->';
		}

		if ( count( $this->notices ) > 0 ) {
			echo '<div id="tix-notices">';
			foreach ( $this->notices as $message ) {
				if ( in_array( $message, $printed ) ) continue;

				$printed[] = $message;
				echo '<p class="tix-notice">' . esc_html( $message ) . '</p>';
			}
			echo '</div><!-- #tix-notices -->';
		}

		if ( count( $this->infos ) > 0 ) {
			echo '<div id="tix-infos">';
			foreach ( $this->infos as $message ) {
				if ( in_array( $message, $printed ) ) continue;

				$printed[] = $message;
				echo '<p class="tix-info">' . esc_html( $message ) . '</p>';
			}
			echo '</div><!-- #tix-infos -->';
		}
	}

	/**
	 * Runs during admin_notices
	 */
	function do_admin_notices() {
		do_action( 'camptix_admin_notices' );

		// Signal when archived.
		if ( $this->options['archived'] )
			echo '<div class="updated"><p>' . __( 'CampTix is in <strong>archive mode</strong>. Please do not make any changes.', 'camptix' ) . '</p></div>';

		if ( is_array( $this->admin_notices ) && ! empty( $this->admin_notices) )
		foreach ( $this->admin_notices as $notice )
			printf( '<div class="updated"><p>%s</p></div>', $notice );
	}

	/**
	 * Add something to the CampTix log. This function does nothing out of the box,
	 * but you can easily use an addon or create your own addon for logging. It's fairly
	 * easy, check out the addons directory.
	 */
	function log( $message, $post_id = 0, $data = null, $module = 'general' ) {
		do_action( 'camptix_log_raw', $message, $post_id, $data, $module );
	}

	function __destruct() {
	}

	function shutdown() {
		$this->flush_tickets_page_seriously();
	}

	/**
	 * Helper function to create admin tables, give me a
	 * $rows array and I'll do the rest.
	 */
	function table( $rows, $classes='widefat' ) {

		if ( ! is_array( $rows ) || ! isset( $rows[0] ) )
			return;

		$alt = '';
		?>
		<table class="tix-table <?php echo $classes; ?>">
			<?php if ( ! is_numeric( implode( '', array_keys( $rows[0] ) ) ) ) : ?>
			<thead>
			<tr>
				<?php foreach ( array_keys( $rows[0] ) as $column ) : ?>
					<th class="tix-<?php echo sanitize_title_with_dashes( $column ); ?>"><?php echo $column; ?></th>
				<?php endforeach; ?>
			</tr>
			</thead>
			<?php endif; ?>

			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $alt = ( $alt == '' ) ? 'alternate' : ''; ?>
					<tr class="<?php echo $alt; ?> tix-row-<?php echo sanitize_title_with_dashes( array_shift( array_values( $row ) ) ); ?>">
						<?php foreach ( $row as $column => $value ) : ?>
						<td class="tix-<?php echo sanitize_title_with_dashes( $column ); ?>"><span><?php echo $value; ?></span></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	function wp_mail( $to, $subject, $message, $headers = array(), $attachments = '' ) {
		if ( is_email( get_option( 'admin_email' ) ) && is_array( $headers ) )
			$headers[] = sprintf( 'From: %s <%s>', $this->options['event_name'], get_option( 'admin_email' ) );

		$this->log( sprintf( 'Sent e-mail to %s.', $to ), null, array( 'subject' => $subject, 'message' => $message ) );
		return wp_mail( $to, $subject, $message, $headers, $attachments );
	}

	/**
	 * Fired before $this->init()
	 * @todo maybe check $classname's inheritance tree and signal if it's not a CampTix_Addon
	 */
	function load_addons() {
		do_action( 'camptix_load_addons' );
		foreach ( $this->addons as $classname )
			if ( class_exists( $classname ) )
				$addons_loaded[] = new $classname;
	}

	/**
	 * Runs during camptix_load_addons, includes the necessary files to register default addons.
	 */
	function load_default_addons() {
		$default_addons = apply_filters( 'camptix_default_addons', array(
			'field-twitter'  => $this->get_default_addon_path( 'field-twitter.php' ),
			'field-url'      => $this->get_default_addon_path( 'field-url.php' ),
			'shortcodes'     => $this->get_default_addon_path( 'shortcodes.php' ),
			'payment-paypal' => $this->get_default_addon_path( 'payment-paypal.php' ),
			'logging-meta'  => $this->get_default_addon_path( 'logging-meta.php' ),

			/**
			 * The following addons are available but inactive by default. Do not uncomment
			 * but rather filter 'camptix_default_addons', otherwise your changes may be overwritten
			 * during an update to the plugin.
			 */

			// 'logging-file'  => $this->get_default_addon_path( 'logging-file.php' ),
			// 'logging-json'  => $this->get_default_addon_path( 'logging-file-json.php' ),
		) );

		foreach ( $default_addons as $filename )
			include_once $filename;
	}

	function get_default_addon_path( $filename ) {
		return plugin_dir_path( __FILE__ ) . 'addons/' . $filename;
	}

	/**
	 * Registers an addon class which is later loaded in $this->load_addons.
	 */
	public function register_addon( $classname ) {
		if ( did_action( 'camptix_init' ) ) {
			trigger_error( __( 'Please register your CampTix addons before CampTix is initialized.', 'camptix' ) );
			return false;
		}

		if ( ! class_exists( $classname ) ) {
			trigger_error( __( 'The CampTix addon you are trying to register does not exist.', 'camptix' ) );
			return false;
		}

		$this->addons[] = $classname;
	}
}

// Initialize the $camptix global.
$GLOBALS['camptix'] = new CampTix_Plugin;
