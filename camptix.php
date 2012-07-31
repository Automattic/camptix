<?php
/*
 * Plugin Name: CampTix Event Ticketing
 * Plugin URI: http://wordcamp.org
 * Description: Simple and flexible event ticketing for WordPress.
 * Version: 1.0
 * Author: Automattic
 * Author URI: http://wordcamp.org
 * License: GPLv2
 */

class CampTix_Plugin {
	protected $options;
	protected $notices;
	protected $errors;
	protected $infos;

	public $debug;
	public $beta_features_enabled;
	public $version = 20120703;
	public $css_version = 20120727;
	public $js_version = 20120727;
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

	/**
	 * Fired as soon as this file is loaded, don't do anything
	 * but filters and actions here.
	 */
	function __construct() {
		do_action( 'camptix_pre_init' );

		// Addons
		add_action( 'init', array( $this, 'load_addons' ), 8 );
		add_action( 'camptix_load_addons', array( $this, 'load_default_addons' ) );

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'init', array( $this, 'schedule_events' ), 9 );
		add_action( 'shutdown', array( $this, 'shutdown' ) );
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
			'manage_tickets' => 'manage_options',
			'manage_attendees' => 'manage_options',
			'manage_coupons' => 'manage_options',
			'manage_tools' => 'manage_options',
			'manage_options' => 'manage_options',
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

		// Our shortcodes
		add_shortcode( 'camptix', array( $this, 'shortcode_callback' ) );
		add_shortcode( 'camptix_attendees', array( $this, 'shortcode_attendees' ) );

		add_shortcode( 'camptix_private', array( $this, 'shortcode_private' ) );
		add_action( 'template_redirect', array( $this, 'shortcode_private_template_redirect' ) );

		// Additional query vars.
		add_filter( 'query_vars', array( $this, 'query_vars' ) );

		// Hack to avoid object caching, see revenue report.
		add_filter( 'get_post_metadata', array( $this, 'get_post_metadata' ), 10, 4 );

		// Stuff that might need to redirect, thus not in [camptix] shortcode.
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 9 ); // earlier than the others.

		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
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
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		add_action( 'wp_ajax_tix_get_questions', array( $this, 'ajax_get_questions' ) );
		add_action( 'wp_ajax_tix_get_question_values', array( $this, 'ajax_get_question_values' ) );

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

		$this->paypal_ipn();
	}

	/**
	 * Scheduled events, mainly around e-mail jobs, runs during file load.
	 */
	function schedule_events() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );

		add_action( 'tix_scheduled_every_ten_minutes', array( $this, 'send_emails_batch' ) );
		add_action( 'tix_scheduled_every_ten_minutes', array( $this, 'process_refund_all' ) );

		// add_action( 'tix_scheduled_daily', array( $this, 'paypal_review_pending_payments' ) );
		add_action( 'tix_scheduled_daily', array( $this, 'paypal_review_timeout_payments' ) );

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
			'display' => 'Once every 10 minutes',
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
							$this->log( sprintf( '%s is not a valid e-mail, removing from queue.', $attendee_email, $email->ID ), $email->ID, $data, 'notify' );
						} else {

							$this->notify_shortcodes_attendee_id = $attendee_id;
							$email_content = do_shortcode( $email->post_content );
							$email_title = do_shortcode( $email->post_title );

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

		// Adds all questions to Summarize (as available fields)
		add_filter( 'camptix_summary_fields', function( $fields ) use ( $questions ) {
			foreach ( $questions as $key => $question )
				$fields['tix_q_' . $key] = $question['field'];

			return $fields;
		} );

		// Adds actions for each new field to carry out the sorting.
		foreach ( $questions as $key => $question ) {
			add_action( 'camptix_summarize_by_tix_q_' . $key, function( $summary, $post ) use ( $that, $key ) {
				$answers = (array) get_post_meta( $post->ID, 'tix_questions', true );
				if ( isset( $answers[$key] ) && ! empty( $answers[$key] ) )
					$that->increment_summary( $summary, $answers[$key] );
				else
					$that->increment_summary( $summary, 'None' );
			}, 10, 2 );
		}
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
		$columns['tix_price'] = 'Price';
		$columns['tix_quantity'] = 'Quantity';
		$columns['tix_purchase_count'] = 'Purchased';
		$columns['tix_remaining'] = 'Remaining';
		$columns['tix_availability'] = 'Availability';
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
				echo $this->get_purchased_tickets_count( $post_id );
				break;
			case 'tix_remaining':
				echo $this->get_remaining_tickets( $post_id );
				
				if ( $this->options['reservations_enabled'] ) {
					$reserved = 0;
					$reservations = $this->get_reservations( $post_id );
					foreach ( $reservations as $reservation_token => $reservation )
						$reserved += $reservation['quantity'] - $this->get_purchased_tickets_count( $post_id, $reservation_token );
					
					if ( $reserved > 0 )
						printf( " (%d reserved)", $reserved );
				}
				
				break;
			case 'tix_availability':
				$start = get_post_meta( $post_id, 'tix_start', true );
				$end = get_post_meta( $post_id, 'tix_end', true );

				if ( ! $start && ! $end )
					echo "Auto";
				else
					echo "$start &mdash; $end";

				break;
		}
	}

	/**
	 * Manage columns filter for attendee post type.
	 */
	function manage_columns_attendee_filter( $columns ) {
		$columns['tix_email'] = 'E-mail';
		$columns['tix_ticket'] = 'Ticket';
		$columns['tix_coupon'] = 'Coupon';

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
			$columns['tix_reservation'] = 'Reservation';

		$columns['tix_ticket_price'] = 'Ticket Price';
		$columns['tix_order_total'] = 'Order Total';

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
				edit_post_link( $ticket->post_title, '', '', $ticket_id );
				break;
			case 'tix_email':
				echo esc_html( get_post_meta( $post_id, 'tix_email', true ) );
				break;
			case 'tix_coupon':
				$coupon_id = get_post_meta( $post_id, 'tix_coupon_id', true );
				if ( $coupon_id ) {
					$coupon = get_post_meta( $post_id, 'tix_coupon', true );
					edit_post_link( $coupon, '', '', $coupon_id );
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
		$columns['tix_quantity'] = 'Quantity';
		$columns['tix_remaining'] = 'Remaining';
		$columns['tix_discount'] = 'Discount';
		$columns['tix_availability'] = 'Availability';
		$columns['tix_tickets'] = 'Tickets';

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

				if ( ! $start && ! $end )
					echo "Auto";
				else
					echo "$start &mdash; $end";

				break;
		}
	}

	/**
	 * Manage columns filter for email post type.
	 */
	function manage_columns_email_filter( $columns ) {
		$columns['tix_sent'] = 'Sent';
		$columns['tix_remaining'] = 'Remaining';
		$columns['tix_total'] = 'Total';
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
		if ( ! in_array( $_REQUEST['post_type'], array( 'tix_attendee', 'tix_ticket' ) ) )
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
				'name' => 'Tickets',
				'singular_name' => 'Ticket',
				'add_new' => 'New Ticket',
				'add_new_item' => 'Add New Ticket',
				'edit_item' => 'Edit Ticket',
				'new_item' => 'New Ticket',
				'all_items' => 'Tickets',
				'view_item' => 'View Ticket',
				'search_items' => 'Search Tickets',
				'not_found' => 'No tickets found',
				'not_found_in_trash' => 'No tickets found in trash',
				'menu_name' => 'Tickets',
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
				'name' => 'Attendees',
				'singular_name' => 'Attendee',
				'add_new' => 'New Attendee',
				'add_new_item' => 'Add New Attendee',
				'edit_item' => 'Edit Attendee',
				'new_item' => 'Add Attendee',
				'all_items' => 'Attendees',
				'view_item' => 'View Attendee',
				'search_items' => 'Search Attendees',
				'not_found' => 'No attendees found',
				'not_found_in_trash' => 'No attendees found in trash',
				'menu_name' => 'Attendees',
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
				'name' => 'Coupons',
				'singular_name' => 'Coupon',
				'add_new' => 'New Coupon',
				'add_new_item' => 'Add New Coupon',
				'edit_item' => 'Edit Coupon',
				'new_item' => 'New Coupon',
				'all_items' => 'Coupons',
				'view_item' => 'View Coupon',
				'search_items' => 'Search Coupons',
				'not_found' => 'No coupons found',
				'not_found_in_trash' => 'No coupons found in trash',
				'menu_name' => 'Coupons',
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
				'name' => 'E-mails',
				'singular_name' => 'E-mail',
				'add_new' => 'New E-mail',
				'add_new_item' => 'Add New E-mail',
				'edit_item' => 'Edit E-mail',
				'new_item' => 'New E-mail',
				'all_items' => 'E-mails',
				'view_item' => 'View E-mail',
				'search_items' => 'Search E-mails',
				'not_found' => 'No e-mails found',
				'not_found_in_trash' => 'No e-mails found in trash',
				'menu_name' => 'E-mails (debug)',
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
			'label'                     => _x( 'Cancelled', 'post', 'tix' ),
			'label_count'               => _nx_noop( 'Cancelled <span class="count">(%s)</span>', 'Cancelled <span class="count">(%s)</span>', 'tix' ),
			'public' => false,
			'protected' => true,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
		) );

		register_post_status( 'failed', array(
			'label'                     => _x( 'Failed', 'post', 'tix' ),
			'label_count'               => _nx_noop( 'Failed <span class="count">(%s)</span>', 'Failed <span class="count">(%s)</span>', 'tix' ),
			'public' => false,
			'protected' => true,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
		) );

		register_post_status( 'timeout', array(
			'label'                     => _x( 'Timeout', 'post', 'tix' ),
			'label_count'               => _nx_noop( 'Timeout <span class="count">(%s)</span>', 'Timeout <span class="count">(%s)</span>', 'tix' ),
			'public' => false,
			'protected' => true,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
		) );

		register_post_status( 'refund', array(
			'label'                     => _x( 'Refunded', 'post', 'tix' ),
			'label_count'               => _nx_noop( 'Refunded <span class="count">(%s)</span>', 'Refunded <span class="count">(%s)</span>', 'tix' ),
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
			$states['timeout'] = 'Timeout';

		if ( $post->post_status == 'failed' && get_query_var( 'post_status' ) != 'failed' )
			$states['failed'] = 'Failed';

		if ( $post->post_status == 'cancel' && get_query_var( 'post_status' ) != 'cancel' )
			$states['cancelled'] = 'Cancelled';

		if ( $post->post_status == 'refund' && get_query_var( 'post_status' ) != 'refund' )
			$states['cancelled'] = 'Refunded';

		return $states;
	}

	/**
	 * Returns an array of options stored in the database, or a set of defaults.
	 */
	function get_options() {

		// Allow other plugins to get CampTix options.
		if ( isset( $this->options ) && is_array( $this->options ) && ! empty( $this->options ) )
			return $this->options;

 		$options = array_merge( apply_filters( 'camptix_default_options', array(
			'paypal_api_username' => '',
			'paypal_api_password' => '',
			'paypal_api_signature' => '',
			'paypal_currency' => 'USD',
			'paypal_sandbox' => true,
			'paypal_statement_subject' => get_bloginfo( 'name' ),
			'version' => $this->version,
			'reservations_enabled' => false,
			'refunds_enabled' => false,
			'refund_all_enabled' => false,
			'questions_v2' => false,
			'archived' => false,
		) ), get_option( 'camptix_options', array() ) );

		// Allow plugins to hi-jack or read the options.
		$options = apply_filters( 'camptix_options', $options );

		/*$options['version'] = 0;
		update_option( 'camptix_options', $options );*/

		// Let's see if we need to run an upgrade scenario.
		if ( $options['version'] < $this->version ) {

			if ( current_user_can( $this->caps['manage_options'] ) && isset( $_GET['tix_do_upgrade'] ) ) {
				$new_version = $this->upgrade( $options['version'] );
				if ( $new_version > $options['version'] ) {
					$options['version'] = $new_version;
					update_option( 'camptix_options', $options );

					add_action( 'admin_notices', function() use ( $new_version ) {
						printf( '<div class="updated"><p>CampTix upgrade successful. Current version: %s.</p></div>', $new_version );
					});
				}
			} else {
				add_action( 'admin_notices', function() {
					$more = current_user_can( $this->caps['manage_options'] ) ? sprintf( ' <a href="%s">Click here to upgrade now.</a>', esc_url( add_query_arg( 'tix_do_upgrade', 1, admin_url( 'index.php' ) ) ) ) : '';
					printf( '<div class="updated"><p>CampTix upgrade required!%s</p></div>', $more );
				});
			}
		}

		if ( current_user_can( $this->caps['manage_options'] ) && isset( $_GET['tix_reset_version'] ) ) {
			$options['version'] = 0;
			update_option( 'camptix_options', $options );
		}

		return $options;
	}

	function is_upgraded() {
		return $this->options['version'] == $this->version;
	}

	/**
	 * Runs when get_option decides that the current version is out of date.
	 */
	function upgrade( $from ) {
		if ( ! current_user_can( $this->caps['manage_options'] ) )
			return;

		$current_user = wp_get_current_user();
		$this->log( sprintf( 'Running upgrade script, thanks %s.', $current_user->user_login ), 0, null, 'upgrade' );

		if ( $from < 20120620 ) {
			$this->log( sprintf( 'Upgrading from %s to %s.', $from, 20120620 ), 0, null, 'upgrade' );

			/*
			 * Post statuses were introduced, and a scenario that timeouts old drafts,
			 * but each attendee needs a tix_timestamp post meta.
			 */
			$paged = 1;
			while ( $attendees = get_posts( array(
				'post_status' => 'any',
				'post_type' => 'tix_attendee',
				'posts_per_page' => 100,
				'paged' => $paged++,
				'cache_results' => false,
			) ) ) {
				foreach ( $attendees as $attendee ) {
					update_post_meta( $attendee->ID, 'tix_timestamp', strtotime( $attendee->post_date ) );
					clean_post_cache( $attendee->ID );
				}
			}

			$from = 20120620;
		}

		if ( $from < 20120703 ) {
			// Run through all attendees and get transaction details from PayPal.
			set_time_limit(1200); // *way* more than enough for 1k txns

	 		$this->options = array_merge( apply_filters( 'camptix_default_options', array(
				'paypal_api_username' => '',
				'paypal_api_password' => '',
				'paypal_api_signature' => '',
				'paypal_currency' => 'USD',
				'paypal_sandbox' => true,
				'paypal_statement_subject' => get_bloginfo( 'name' ),
				'version' => $this->version,
				'reservations_enabled' => false,
				'refunds_enabled' => false,
				'refund_all_enabled' => false,
				'questions_v2' => false,
			) ), get_option( 'camptix_options', array() ) );

			if ( empty( $this->options['paypal_api_username'] ) )
				$this->log( 'Could not upgrade to 20120703, invalid paypal username.', 0, null, 'upgrades' );

			$count = 0;
			$processed = 0;
			$paged = 1;
			while ( $attendees = get_posts( array(
				'post_status' => 'any',
				'post_type' => 'tix_attendee',
				'posts_per_page' => 100,
				'paged' => $paged++,
				'update_term_cache' => false,
				'orderby' => 'ID',
				'order' => 'ASC',
			) ) ) {
				foreach ( $attendees as $attendee ) {
					$txn_id = get_post_meta( $attendee->ID, 'tix_paypal_transaction_id', true );
					if ( ! $txn_id ) continue;

					$count++;
					$payload = array(
						'METHOD' => 'GetTransactionDetails',
						'TRANSACTIONID' => $txn_id,
					);
					$txn = wp_parse_args( wp_remote_retrieve_body( $this->paypal_request( $payload ) ) );

					if ( isset( $txn['ACK'], $txn['PAYMENTSTATUS'] ) && $txn['ACK'] == 'Success' ) {
						$processed++;
						$this->log( sprintf( 'Processed %s. (%d)', $txn_id, $count ), 0, null, 'upgrade' );
						update_post_meta( $attendee->ID, 'tix_paypal_transaction_details', $txn );
					} else {
						$this->log( sprintf( 'Could not process %s. (%d)', $txn_id, $count ), 0, $txn, 'upgrade' );
					}
					clean_post_cache( $attendee->ID );
				}
			}

			$this->log( sprintf( 'Processed %d out of %d transactions.', $processed, $count ), 0, null, 'upgrade' );

			// Clean up
			$this->options = array();
			$from = 20120703;
		}

		$from = $this->version;

		$this->log( sprintf( 'Upgrade complete, current version: %s.', $from ), 0, null, 'upgrade' );
		return $from;
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
			case 'paypal':
				add_settings_section( 'general', 'PayPal Configuration', array( $this, 'menu_setup_section_paypal' ), 'camptix_options' );
				$this->add_settings_field_helper( 'paypal_api_username', 'API Username', 'field_text' );
				$this->add_settings_field_helper( 'paypal_api_password', 'API Password', 'field_text' );
				$this->add_settings_field_helper( 'paypal_api_signature', 'API Signature', 'field_text' );
				$this->add_settings_field_helper( 'paypal_statement_subject', 'Statement Subject', 'field_text' );
				$this->add_settings_field_helper( 'paypal_currency', 'Currency', 'field_currency' );
				$this->add_settings_field_helper( 'paypal_sandbox', 'Sandbox Mode', 'field_yesno', false,
					"The PayPal Sandbox is a way to test payments without using real accounts and transactions. If you'd like to use Sandbox Mode, you'll need to create a <a href='https://developer.paypal.com/'>PayPal Developer</a> account and obtain the API credentials for your sandbox user."
				);
				break;
			case 'beta':
				
				if ( ! $this->beta_features_enabled )
					break;

				add_settings_section( 'general', 'Beta Features', array( $this, 'menu_setup_section_beta' ), 'camptix_options' );

				$this->add_settings_field_helper( 'reservations_enabled', 'Enable Reservations', 'field_yesno', false,
					"Reservations is a way to make sure that a certain group of people, can always purchase their tickets, even if you sell out fast. <a href='#'>Learn more</a>."
				);

				$this->add_settings_field_helper( 'refunds_enabled', 'Enable Refunds', 'field_enable_refunds', false,
					"This will allows your customers to refund their tickets purchase by filling out a simple refund form. <a href='#'>Learn more</a>."
				);

				$this->add_settings_field_helper( 'refund_all_enabled', 'Enable Refund All', 'field_yesno', false,
					"Allows to refund all purchased tickets by an admin via the Tools menu. <a href='#'>Learn more</a>."
				);
				$this->add_settings_field_helper( 'questions_v2', 'Enable Questions v2', 'field_yesno', false,
					"A new interface for managing questions, allows sorting, adding existing questions and more. <a href='#'>Learn more</a>."
				);
				$this->add_settings_field_helper( 'archived', 'Archived Event', 'field_yesno', false,
					"Archived events are read-only. <a href='#'>Learn more</a>."
				);
				break;
			default:
		}
	}

	function menu_setup_section_beta() {
		echo '<p>Beta features are things that are being worked on in CampTix, but are not quite finished yet. You can try them out, but we do not recommend doing that in a live environment on a real event. If you have any kind of feedback on any of the beta features, please let us know.</p>';
	}

	function menu_setup_section_paypal() {
		echo '<p>Enter your PayPal API credentials in the form below. Note, that these <strong>are not</strong> your PayPal username and password. Learn more about <a href="https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_ECAPICredentials">Obtaining API Credentials</a> at PayPal.</p>';
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

		if ( isset( $input['paypal_api_username'] ) )
			$output['paypal_api_username'] = $input['paypal_api_username'];

		if ( isset( $input['paypal_api_password'] ) )
			$output['paypal_api_password'] = $input['paypal_api_password'];

		if ( isset( $input['paypal_api_signature'] ) )
			$output['paypal_api_signature'] = $input['paypal_api_signature'];

		if ( isset( $input['paypal_statement_subject'] ) )
			$output['paypal_statement_subject'] = sanitize_text_field( $input['paypal_statement_subject'] );

		if ( isset( $input['paypal_currency'] ) )
			$output['paypal_currency'] = $input['paypal_currency'];

		$yesno_fields = array(
			'paypal_sandbox',
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

		$current_user = wp_get_current_user();
		$log_data = array(
			'old' => $this->options,
			'new' => $output,
			'username' => $current_user->user_login,
		);
		$this->log( 'Options updated.', 0, $log_data );

		return $output;
	}

	function get_beta_features() {
		return array(
			'reservations_enabled',
			'refunds_enabled',
			'refund_all_enabled',
			'questions_v2',
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
		<label class="tix-yes-no description"><input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="1" <?php checked( $args['value'], true ); ?>> Yes</label>
		<label class="tix-yes-no description"><input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="0" <?php checked( $args['value'], false ); ?>> No</label>

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
			<label class="tix-yes-no description"><input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="1" <?php checked( $args['value'], true ); ?>> Yes</label>
			<label class="tix-yes-no description"><input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="0" <?php checked( $args['value'], false ); ?>> No</label>
		</div>

		<div id="tix-refunds-date" class="<?php if ( ! $refunds_enabled ) echo 'hide-if-js'; ?>" style="margin: 20px 0;">
			<label>Allow refunds until:</label>
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
				'label' => 'U.S. Dollar',
				'format' => '$ %s',
			),
			'EUR' => array(
				'label' => 'Euro',
				'format' => '€ %s',
			),
			'CAD' => array(
				'label' => 'Canadian Dollar',
				'format' => 'CAD %s',
			),
			'NOK' => array(
				'label' => 'Norwegian Krone',
				'format' => 'NOK %s',
			),
			'PLN' => array(
				'label' => 'Polish Zloty',
				'format' => 'PLN %s',
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
		$currency = $currencies[$this->options['paypal_currency']];
		if ( $currency_key )
			$currency = $currencies[$currency_key];

		if ( ! $currency )
			$currency = array( 'label' => 'U.S. Dollar', 'format' => '$ %s' );

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
		add_submenu_page( 'edit.php?post_type=tix_ticket', 'Tools', 'Tools', $this->caps['manage_tools'], 'camptix_tools', array( $this, 'menu_tools' ) );
		add_submenu_page( 'edit.php?post_type=tix_ticket', 'Setup', 'Setup', $this->caps['manage_options'], 'camptix_options', array( $this, 'menu_setup' ) );
		remove_submenu_page( 'edit.php?post_type=tix_ticket', 'post-new.php?post_type=tix_ticket' );
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
			<h2>CampTix Setup</h2>
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
				printf( 'Current time on server: %s' . PHP_EOL, date( 'r' ) );
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

		return 'paypal';
	}

	/**
	 * Tabs for Tickets > Tools, outputs the markup.
	 */
	function menu_setup_tabs() {
		$current_section = $this->get_setup_section();
		$sections = array(
			'paypal' => 'PayPal',
		);

		if ( $this->beta_features_enabled )
			$sections['beta'] = 'Beta';

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
			<h2>CampTix Tools</h2>
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
			'summarize' => 'Summarize',
			'revenue' => 'Revenue',
			'export' => 'Export',
			'notify' => 'Notify',
		);

		if ( current_user_can( $this->caps['manage_options'] ) && ! $this->options['archived'] && $this->options['refund_all_enabled'] )
			$sections['refund'] = 'Refund';

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
						<th scope="row">Summarize by</th>
						<td>
							<select name="tix_summarize_by">
								<?php foreach ( $this->get_available_summary_fields() as $value => $caption ) : ?>
									<?php $caption = mb_strlen( $caption ) > 30 ? mb_substr( $caption, 0, 30 ) . '...' : $caption; ?>
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
				<input type="submit" class="button-primary" value="Show Summary" />
				<input type="submit" name="tix_export_summary" value="Export Summary to CSV" class="button" />
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
					'Count' => esc_html( $entry['count'] )
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

			fputcsv( $stream, array( $summary_title, 'Count' ) );
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
						$coupon = 'None';
					$this->increment_summary( $summary, $coupon );
				} else {

					// Let other folks summarize too.
					do_action_ref_array( 'camptix_summarize_by_' . $summarize_by, array( &$summary, $attendee ) );
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
			'ticket' => 'Ticket type',
			'coupon' => 'Coupon code',
			'purchase_date' => 'Purchase date',
			'purchase_time' => 'Purchase time',
			'purchase_datetime' => 'Purchase date and time',
			'purchase_dayofweek' => 'Purchase day of week',
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
					$txn = get_post_meta( $attendee_id, 'tix_paypal_transaction_id', true );
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
				'Ticket type' => esc_html( $ticket->post_title ),
				'Sold' => $ticket->tix_sold_count,
				'Remaining' => $ticket->tix_remaining,
				'Sub-Total' => $this->append_currency( $ticket->tix_sold_count * $ticket->tix_price ),
				'Discounted' => $this->append_currency( $ticket->tix_discounted ),
				'Revenue' => $this->append_currency( $ticket->tix_sold_count * $ticket->tix_price - $ticket->tix_discounted ),
			);
		}
		$rows[] = array(
			'Ticket type' => 'Total',
			'Sold' => $totals->sold,
			'Remaining' => $totals->remaining,
			'Sub-Total' => $this->append_currency( $totals->sub_total ),
			'Discounted' => $this->append_currency( $totals->discounted ),
			'Revenue' => $this->append_currency( $totals->revenue ),
		);

		if ( $totals->revenue != $actual_total ) {
			printf( '<div class="updated settings-error below-h2"><p>%s</p></div>', sprintf( '<strong>Woah!</strong> The revenue total does not match with the PayPal transactions total. The actual total is: <strong>%s</strong>. Something somewhere has gone wrong, please report this.', $this->append_currency( $actual_total ) ) );
		}

		$this->table( $rows, 'widefat tix-revenue-summary' );
		printf( '<p><span class="description">Revenue report generated in %s seconds.</span></p>', number_format( microtime( true ) - $start_time, 3 ) );

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
						<th scope="row">Export all attendees data to</th>
						<td>
							<select name="tix_export_to">
								<option value="csv">CSV</option>
								<option value="xml">XML</option>
								<option disabled="disabled" value="pdf">PDF (coming soon)</option>
							</select>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<?php wp_nonce_field( 'tix_export' ); ?>
				<input type="hidden" name="tix_export_submit" value="1" />
				<input type="submit" class="button-primary" value="Export" />
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
				add_settings_error( 'tix', 'error', 'Format not supported.', 'error' );
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
				'id' => 'Attendee ID',
				'ticket' => 'Ticket Type',
				'first_name' => 'First Name',
				'last_name' => 'Last Name',
				'email' => 'E-mail Address',
				'date' => 'Purchase date',
				'status' => 'Status',
				'txn_id' => 'Transaction ID',
				'coupon' => 'Coupon',
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
						'txn_id' => get_post_meta( $attendee_id, 'tix_paypal_transaction_id', true ),
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
				$errors[] = 'Please enter a subject line.';

			if ( empty( $_POST['tix_notify_body'] ) )
				$errors[] = 'Please enter the e-mail body.';

			if ( ! isset( $_POST['tix_notify_tickets'] ) || count( (array) $_POST['tix_notify_tickets'] ) < 1 )
				$errors[] = 'Please select at least one ticket group.';

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
					add_settings_error( 'camptix', 'none', sprintf( 'Your e-mail job has been queued for %s recipients.', count( $recipients ) ), 'updated' );
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
						<th scope="row">To</th>
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
						<th scope="row">Subject</th>
						<td>
							<input type="text" name="tix_notify_subject" value="<?php echo esc_attr( $form_data['subject'] ); ?>" class="large-text" />
						</td>
					</tr>
					<tr>
						<th scope="row">Message</th>
						<td>
							<textarea rows="10" name="tix_notify_body" id="tix-notify-body" class="large-text"><?php echo esc_textarea( $form_data['body'] ); ?></textarea><br />
							<?php do_action( 'camptix_init_notify_shortcodes' ); ?>
							<?php if ( ! empty( $shortcode_tags ) ) : ?>
							<p class="">You can use the following shortcodes:
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
					<?php submit_button( 'Preview', 'button', 'tix_notify_preview', false ); ?>
				</div>
				<?php submit_button( 'Send E-mails', 'primary', 'tix_notify_submit', false ); ?>
				<?php submit_button( 'Preview', 'button', 'tix_notify_preview', false ); ?>
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
			echo '<h3>History</h3>';
			$rows = array();
			while ( $history_query->have_posts() ) {
				$history_query->the_post();
				$rows[] = array(
					'Subject' => get_the_title(),
					'Updated' => get_the_date() . ' at ' . get_the_time(),
					'Author' => get_the_author(),
					'Status' => $post->post_status,
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
						<th scope="row">Refund all transactions</th>
						<td>
							<label><input name="tix_refund_checkbox_1" value="1" type="checkbox" /> Refund all transactions</label><br />
							<label><input name="tix_refund_checkbox_2" value="1" type="checkbox" /> Seriously, refund them all</label><br />
							<label><input name="tix_refund_checkbox_3" value="1" type="checkbox" /> I know what I'm doing, please refund</label><br />
							<label><input name="tix_refund_checkbox_4" value="1" type="checkbox" /> I know this may result in money loss, refund anyway</label><br />
							<label><input name="tix_refund_checkbox_5" value="1" type="checkbox" /> I will not blame Konstantin if something goes wrong</label><br />
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<?php wp_nonce_field( 'tix_refund_all' ); ?>
				<input type="hidden" name="tix_refund_all_submit" value="1" />
				<input type="submit" class="button-primary" value="Refund Transactions" />
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
				add_settings_error( 'camptix', 'none', 'Looks like you have missed a checkbox or two. Try again!', 'error' );
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

		add_settings_error( 'camptix', 'none', sprintf( 'A refund job has been queued for %d attendees.', $count ), 'updated' );
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
		<p>A refund job is in progress, with <?php echo $found_posts; ?> attendees left in the queue. Next run in <?php echo wp_next_scheduled( 'tix_scheduled_every_ten_minutes' ) - time(); ?> seconds.</p>
		<?php
	}

	/**
	 * Runs by WP_Cron, refunds attendees set to refund.
	 */
	function process_refund_all() {
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
			$transaction_id = get_post_meta( $attendee->ID, 'tix_paypal_transaction_id', true );

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
							'key' => 'tix_paypal_transaction_id',
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
		add_meta_box( 'tix_ticket_options', 'Ticket Options', array( $this, 'metabox_ticket_options' ), 'tix_ticket', 'side' );
		add_meta_box( 'tix_ticket_availability', 'Availability', array( $this, 'metabox_ticket_availability' ), 'tix_ticket', 'side' );

		if ( isset( $this->options['questions_v2'] ) && $this->options['questions_v2'] )
			add_meta_box( 'tix_ticket_questions_2', 'Questions v2 (beta)', array( $this, 'metabox_ticket_questions_2' ), 'tix_ticket' );
		else
			add_meta_box( 'tix_ticket_questions', 'Questions', array( $this, 'metabox_ticket_questions' ), 'tix_ticket' );

		if ( $this->options['reservations_enabled'] )
			add_meta_box( 'tix_ticket_reservations', 'Reservations', array( $this, 'metabox_ticket_reservations' ), 'tix_ticket' );

		add_meta_box( 'tix_coupon_options', 'Coupon Options', array( $this, 'metabox_coupon_options' ), 'tix_coupon', 'side' );
		add_meta_box( 'tix_coupon_availability', 'Availability', array( $this, 'metabox_coupon_availability' ), 'tix_coupon', 'side' );

		add_meta_box( 'tix_attendee_info', 'Attendee Information', array( $this, 'metabox_attendee_info' ), 'tix_attendee', 'normal' );

		add_meta_box( 'tix_attendee_submitdiv', 'Publish', array( $this, 'metabox_attendee_submitdiv' ), 'tix_attendee', 'side' );
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
				<?php submit_button( __( 'Save' ), 'button', 'save' ); ?>
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
								Unknown status
							<?php endif; ?>
						</span>
					</div>

					<?php
					$datef = __( 'M j, Y @ G:i' );
					if ( 0 != $post->ID ) {
						$stamp = __( 'Created: <b>%1$s</b>' );
						$date = date_i18n( $datef, strtotime( $post->post_date ) );
					} else {
						$stamp = __('Publish <b>immediately</b>');
						$date = date_i18n( $datef, strtotime( current_time('mysql') ) );
					}
					?>
					
					<?php if ( $can_publish ) : ?>
					<div class="misc-pub-section curtime">
						<span id="timestamp"><?php printf( $stamp, $date ); ?></span>
					</div>
					<?php endif; // $can_publish ?>

				</div><!-- #misc-publishing-actions -->
				<div class="clear"></div>
			</div><!-- #minor-publishing -->

			<div id="major-publishing-actions">
				<div id="delete-action">
				<?php
				if ( current_user_can( 'delete_post', $post->ID ) ) {
					if ( !EMPTY_TRASH_DAYS )
						$delete_text = __( 'Delete Permanently' );
					else
						$delete_text = __( 'Move to Trash' );
					?>
				<a class="submitdelete deletion" href="<?php echo get_delete_post_link( $post->ID ); ?>"><?php echo $delete_text; ?></a><?php
				} ?>
				</div>

				<div id="publishing-action">
					<?php submit_button( __( 'Save Attendee' ), 'primary', 'save', false, array( 'tabindex' => '5', 'accesskey' => 'p' ) ); ?>
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
			<span class="left">Price:</span>
			<?php if ( $purchased <= 0 ) : ?>
			<input type="text" name="tix_price" class="small-text" value="<?php echo esc_attr( number_format( (float) get_post_meta( get_the_ID(), 'tix_price', true ), 2, '.', '' ) ); ?>" autocomplete="off" /> <?php echo esc_html( $this->options['paypal_currency'] ); ?>
			<?php else: ?>
			<span><?php echo esc_html( $this->append_currency( get_post_meta( get_the_ID(), 'tix_price', true ) ) ); ?></span><br />
			<p class="description" style="margin-top: 10px;">You can not change the price because one or more tickets have already been purchased.</p>
			<?php endif; ?>
		</div>
		<div class="misc-pub-section">
			<span class="left">Quantity:</span>
			<input type="number" min="<?php echo intval( $min_quantity ); ?>" name="tix_quantity" class="small-text" value="<?php echo esc_attr( intval( get_post_meta( get_the_ID(), 'tix_quantity', true ) ) ); ?>" autocomplete="off" />
			<?php if ( $purchased > 0 ) : ?>
			<p class="description" style="margin-top: 10px;">You can not set the quantity to less than the number of purchased tickets.</p>
			<?php endif; ?>
		</div>
		<div class="clear"></div>
		<?
	}

	/**
	 * Metabox callback for ticket availability.
	 */
	function metabox_ticket_availability() {
		$start = get_post_meta( get_the_ID(), 'tix_start', true );
		$end = get_post_meta( get_the_ID(), 'tix_end', true );
		?>
		<div class="misc-pub-section curtime">
			<span id="timestamp">Leave blank for auto-availability</span>
		</div>
		<div class="misc-pub-section">
			<span class="left">Start:</span>
			<input type="text" name="tix_start" id="tix-date-from" class="regular-text date" value="<?php echo esc_attr( $start ); ?>" />
		</div>
		<div class="misc-pub-section">
			<span class="left">End:</span>
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
					<th>Name</th>
					<th>Quantity</th>
					<th>Used</th>
					<th>Token</th>
					<th>Actions</th>
				</tr>
				</thead>
				<tbody>
			<?php foreach ( $reservations as $reservation ) : ?>
				<tr>
					<td><span><?php echo esc_html( $reservation['id'] ); ?></span></td>
					<td class="column-quantity"><span><?php echo intval( $reservation['quantity'] ); ?></span></td>
					<td class="column-used"><span><?php echo $this->get_purchased_tickets_count( get_the_ID(), $reservation['token'] ); ?></span></td>
					<td class="column-token"><span><a href="<?php echo esc_url( $this->get_reservation_link( $reservation['id'], $reservation['token'] ) ); ?>"><?php echo $reservation['token']; ?></a></span></td>
					<td class="column-actions"><span>
						<input type="submit" class="button" name="tix_reservation_release[<?php echo $reservation['token']; ?>]" value="Release" />
						<input type="submit" class="button" name="tix_reservation_cancel[<?php echo $reservation['token']; ?>]" value="Cancel" />
					</span></td>
				</tr>
			<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>

		<p><strong>Create a New Reservation:</strong></p>
		<p>
			<input type="hidden" name="tix_doing_reservations" value="1" />
			<label>Reservation Name</label>
			<input type="text" name="tix_reservation_id" autocomplete="off" />
			<label>Quantity</label>
			<input type="text" name="tix_reservation_quantity" autocomplete="off" />
			<input type="submit" class="button-primary" value="Create Reservation" />
		</p>
		<p class="description">If you create a reservation with more quantity than available by the total ticket quantity, we'll bump the ticket quantity for you.</p>
		<?php
	}

	/**
	 * Returns all available ticket types, you can
	 * extend this with filters and actions.
	 */
	function get_question_field_types() {
		return apply_filters( 'camptix_question_field_types', array(
			'text' => 'Text input',
			'select' => 'Dropdown Select',
			'checkbox' => 'Checkbox',
		) );
	}

	/**
	 * Runs before question fields are printed, initialize controls actions here.
	 */
	function question_fields_init() {
		add_action( 'camptix_question_field_text', array( $this, 'question_field_text' ), 10, 2 );
		add_action( 'camptix_question_field_select', array( $this, 'question_field_select' ), 10, 3 );
		add_action( 'camptix_question_field_checkbox', array( $this, 'question_field_checkbox' ), 10, 3 );
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
			<label><input <?php checked( $user_value, 'Yes' ); ?> name="<?php echo esc_attr( $name ); ?>" type="checkbox" value="Yes" /> Yes</label>
		<?php endif; ?>
		<?php
	}

	/**
	 * Metabox callback for ticket questions.
	 */
	function metabox_ticket_questions() {
		?>
		<div id="postcustomstuff" class="tix-ticket-questions tix-ticket-questions-v1">
		<table class="">
			<thead>
				<tr>
					<th>Field</th>
					<th>Type</th>
					<th>Values</th>
					<th>Required</th>
				</tr>
			</thead>
			<tbody>
				<tr class="alternate">
					<td><span>First Name</span></td>
					<td><span>Text input</span></td>
					<td></td>
					<td class="column-required"><input type="checkbox" checked="checked" disabled="disabled" /></td>
				</tr>
				<tr>
					<td><span>Last Name</span></td>
					<td><span>Text input</span></td>
					<td></td>
					<td class="column-required"><input type="checkbox" checked="checked" disabled="disabled" /></td>
				</tr>
				<tr class="alternate">
					<td><span>E-mail</span></td>
					<td><span>Text input</span></td>
					<td></td>
					<td class="column-required"><input type="checkbox" checked="checked" disabled="disabled" /></td>
				</tr>

				<?php
					$questions = $this->get_sorted_questions( get_the_ID() );
					$types = $this->get_question_field_types();
					$i = 0;
					$alt = 'alternate';
				?>
				<?php foreach ( $questions as $question ) : $i++; $alt = $alt == '' ? 'alternate' : ''; ?>
				<tr class="<?php echo $alt; ?>">
					<td><input class="suggest-questions" type="text" name="tix_questions[<?php echo $i; ?>][field]" value="<?php echo esc_attr( $question['field'] ); ?>" /></td>
					<td>
						<select name="tix_questions[<?php echo $i; ?>][type]">
							<?php foreach ( $types as $key => $label ) : ?>
							<option <?php selected( $question['type'], $key ); ?> value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><input class="suggest-values" name="tix_questions[<?php echo $i; ?>][values]" type="text" value="<?php echo esc_attr( implode( ', ', $question['values'] ) ); ?>" /></td>
					<td class="column-required"><input <?php checked( $question['required'] ); ?> name="tix_questions[<?php echo $i; ?>][required]" value="1" type="checkbox" /></td>
				</tr>
				<?php endforeach; ?>

				<?php $i++; ?>
				<tr class="tix-question-new">
					<td><input class="suggest-questions" type="text" name="tix_questions[<?php echo $i; ?>][field]" value="" /></td>
					<td>
						<select name="tix_questions[<?php echo $i; ?>][type]">
							<?php foreach ( $types as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><input class="suggest-values" name="tix_questions[<?php echo $i; ?>][values]" type="text" value="" /></td>
					<td class="column-required"><input name="tix_questions[<?php echo $i; ?>][required]" value="1" type="checkbox" /></td>
				</tr>
			</tbody>

			<?php $i++; ?>
			<tfoot class="tix-question-prototype" style="display: none;">
			<tr class="tix-question-new">
				<td><input class="suggest-questions" type="text" name="tix_questions[q_id][field]" value="" /></td>
				<td>
					<select name="tix_questions[q_id][type]">
						<?php foreach ( $types as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<td><input class="suggest-values" name="tix_questions[q_id][values]" type="text" value="" /></td>
				<td class="column-required"><input name="tix_questions[q_id][required]" value="1" type="checkbox" /></td>
			</tr>
			</tfoot>
			<script>
			jQuery(document).ready(function($) {
				var i = $( '.suggest-questions' ).length;

				$( '.suggest-questions' ).live( 'focus', function() {
					if ( $(this).data( 'has-suggest' ) ) return;
					$(this).data( 'has-suggest', 1 );
					$(this).suggest( ajaxurl + '?action=tix_get_questions', {
						delay: 500,
						minchars: 2,
						multiple: false,
					} );
				} );

				$( '.suggest-values' ).live( 'focus', function() {
					if ( $(this).data( 'has-suggest' ) ) return;
					$(this).data( 'has-suggest', 1 );
					$(this).suggest( ajaxurl + '?action=tix_get_question_values', {
						delay: 500,
						minchars: 2,
						multiple: false,
					} );
				} );

				$( '.tix-question-new .suggest-questions' ).live( 'keyup', function() {
					if ( $(this).val().length < 1 ) return;

					var tr = $(this).parents('tr');
					$(tr).removeClass('tix-question-new');
					var tbody = $(this).parents('tbody');
					var new_tr = $.clone($('.tix-question-prototype tr')[0]);

					$(new_tr).find('[name="tix_questions[q_id][field]"]').attr('name', 'tix_questions[' + i + '][field]');
					$(new_tr).find('[name="tix_questions[q_id][type]"]').attr('name', 'tix_questions[' + i + '][type]');
					$(new_tr).find('[name="tix_questions[q_id][values]"]').attr('name', 'tix_questions[' + i + '][values]');
					$(new_tr).find('[name="tix_questions[q_id][required]"]').attr('name', 'tix_questions[' + i + '][required]');

					$(tbody).append(new_tr);

					i++;
				});
			});
			</script>
		</table>
		</div>
		<?php
	}

	/**
	 * Metabox callback for ticket questions.
	 */
	function metabox_ticket_questions_2() {
		$types = $this->get_question_field_types();
		?>
		<style>
		#tix_ticket_questions_2 .inside {
			margin: 0;
			padding: 0;
		}
		</style>
		<div class="tix-ticket-questions">
			<div class="tix-ui-sortable">
				<div class="tix-item tix-item-required">
					<div>
						<span class="tix-field-type">Default</span>
						<span class="tix-field-name">First name, last name and e-mail address</span>
						<span class="tix-field-required-star">*</span>
						<input type="hidden" class="tix-field-order" value="0" />
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
					<div class="tagchecklist tix-field-delete"><span><a class="ntdelbutton">X</a></span></div>
					<div class="tix-item-inner">
						<input type="hidden" class="tix-field-type" name="tix_questions[<?php echo $i; ?>][type]" value="<?php echo esc_attr( $question['type'] ); ?>" />
						<input type="hidden" class="tix-field-name" name="tix_questions[<?php echo $i; ?>][field]" value="<?php echo esc_attr( $question['field'] ); ?>" />
						<input type="hidden" class="tix-field-values" name="tix_questions[<?php echo $i; ?>][values]" value="<?php echo esc_attr( implode( ', ', $question['values'] ) ); ?>" />
						<input type="hidden" class="tix-field-required" name="tix_questions[<?php echo $i; ?>][required]" value="<?php echo intval( $question['required'] ); ?>" />
						<input type="hidden" class="tix-field-order" name="tix_questions[<?php echo $i; ?>][order]" value="<?php echo $i; ?>" />

						<span class="tix-field-type"><?php echo esc_html( $question['type'] ); ?></span>
						<span class="tix-field-name"><?php echo esc_html( $question['field'] ); ?></span>
						<span class="tix-field-required-star">*</span>
						<span class="tix-field-values"><?php echo esc_html( implode( ', ', $question['values'] ) ); ?></span>
					</div>
					<div class="tix-clear"></div>
				</div>
				<?php endforeach; ?>
			</div>

			<div class="tix-add-question" style="border-top: solid 1px white; background: #f9f9f9;">
				<span id="tix-add-question-action" style="margin-left: 69px;">
					Add a <a id="tix-add-question-new" style="font-weight: bold;" href="#">new question</a> or an <a id="tix-add-question-existing" style="font-weight: bold;" href="#">existing one</a>.
					</span>
				<div id="tix-add-question-new-form" style="margin-left: 69px;">
					<div class="tix-item tix-item-sortable tix-prototype tix-new">
						<div class="tagchecklist tix-field-delete"><span><a class="ntdelbutton">X</a></span></div>
						<div class="tix-item-inner">
							<input type="hidden" class="tix-field-type" value="" />
							<input type="hidden" class="tix-field-name" value="" />
							<input type="hidden" class="tix-field-values" value="" />
							<input type="hidden" class="tix-field-required" value="" />
							<input type="hidden" class="tix-field-order" value="" />

							<span class="tix-field-type">Type</span>
							<span class="tix-field-name">Field</span>
							<span class="tix-field-values">Values</span>
							<span class="tix-field-required-star">*</span>
						</div>
						<div class="tix-clear"></div>
					</div>

					<h4 class="title">Add a new question:</h4>

					<table class="form-table">
						<tr valign="top">
							<th scope="row">
								<label>Type</label>
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
								<label>Question</label>
							</th>
							<td>
								<input id="tix-add-question-name" class="regular-text" type="text" />
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label>Values</label>
							</th>
							<td>
								<input id="tix-add-question-values" class="regular-text" type="text" />
								<p class="description">Separate multiple values with a comma.</p>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label>Required</label>
							</th>
							<td>
								<label><input id="tix-add-question-required" type="checkbox" /> This field is required</label>
							</td>
						</tr>
					</table>
					<p class="submit">
						<a href="#" id="tix-add-question-submit" class="button">Add Question</a>
						<a href="#" id="tix-add-question-new-form-cancel" class="button">Close</a>
						<span class="description">Do not forget to update the ticket post to save changes.</span>
					</p>
				</div>
				<div id="tix-add-question-existing-form" style="margin-left: 69px;">
					<h4 class="title">Add an existing question:</h4>

					<div class="categorydiv" id="tix-add-question-existing-list">
							<ul id="category-tabs" class="category-tabs">
								<li class="tabs">Available Questions</li>
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
						<a href="#" id="tix-add-question-existing-form-add" class="button">Add Selected</a>
						<a href="#" id="tix-add-question-existing-form-cancel" class="button">Close</a>
						<span class="description">Do not forget to update the ticket post to save changes.</span>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Ajax search through question fields.
	 */
	function ajax_get_questions() {
		$search = $_REQUEST['q'];
		$matches = array();
		$questions = $this->get_all_questions();
		foreach ( $questions as $question )
			if ( stristr( $question['field'], $search ) )
				$matches[] = $question['field'];

		echo implode( "\n", $matches );
		die();
	}

	/**
	 * Ajax search through question values.
	 */
	function ajax_get_question_values() {
		$search = $_REQUEST['q'];
		$matches = array();
		$questions = $this->get_all_questions();
		foreach ( $questions as $question ) {
			foreach ( $question['values'] as $value ) {
				if ( stristr( $value, $search ) ) {
					$matches[] = implode( ', ', $question['values'] );
					break;
				}
			}
		}

		echo implode( "\n", $matches );
		die();
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
			<span class="left">Discount:</span>
			<?php if ( $used <= 0 ) : ?>
				<input type="text" name="tix_discount_price" class="small-text" style="width: 57px;" value="<?php echo esc_attr( $discount_price ); ?>" autocomplete="off" /> <?php echo esc_html( $this->options['paypal_currency'] ); ?><br />
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
				<p class="description" style="margin-top: 10px;">You can not change the discount because one or more tickets have already been purchased using this coupon.</p>
			<?php endif; ?>
		</div>
		<div class="misc-pub-section">
			<span class="left">Quantity:</span>
			<input type="number" min="<?php echo intval( $used ); ?>" name="tix_coupon_quantity" class="small-text" value="<?php echo esc_attr( $quantity ); ?>" autocomplete="off" />
			<?php if ( $used > 0 ) : ?>
				<p class="description" style="margin-top: 10px;">The quantity can not be less than the number of coupons already used.</p>
			<?php endif; ?>
		</div>
		<div class="misc-pub-section tix-applies-to">
			<span class="left">Applies to:</span>
			<div class="tix-checkbox-group">
				<label style="margin-bottom: 8px;"><a id="tix-applies-to-all" href="#">All</a> / <a id="tix-applies-to-none" href="#">None</a></label>
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
			<span id="timestamp">Leave blank for auto-availability</span>
		</div>
		<div class="misc-pub-section">
			<span class="left">Start:</span>
			<input type="text" name="tix_coupon_start" id="tix-date-from" class="regular-text date" value="<?php echo esc_attr( $start ); ?>" />
		</div>
		<div class="misc-pub-section">
			<span class="left">End:</span>
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
		$rows[] = array( 'General', '' );
		$rows[] = array( 'Status', esc_html( ucwords( $post->post_status ) ) );
		$rows[] = array( 'First Name', esc_html( get_post_meta( $post->ID, 'tix_first_name', true ) ) );
		$rows[] = array( 'Last Name', esc_html( get_post_meta( $post->ID, 'tix_last_name', true ) ) );
		$rows[] = array( 'E-mail', esc_html( get_post_meta( $post->ID, 'tix_email', true ) ) );
		$rows[] = array( 'Ticket', sprintf( '<a href="%s">%s</a>', get_edit_post_link( $ticket->ID ), $ticket->post_title ) );
		$rows[] = array( 'Edit Token', sprintf( '<a href="%s">%s</a>', $this->get_edit_attendee_link( $post->ID, $edit_token ), $edit_token ) );
		$rows[] = array( 'Access Token', sprintf( '<a href="%s">%s</a>', $this->get_access_tickets_link( $access_token ), $access_token ) );

		// Transaction
		$rows[] = array( 'Transaction', '' );
		$txn_id = get_post_meta( $post->ID, 'tix_paypal_transaction_id', true );
		if ( $txn_id ) {
			$txn = get_post_meta( $post->ID, 'tix_paypal_transaction_details', true );
			$txn_url = get_admin_url( 0, '/edit.php?post_type=tix_attendee' );
			$txn_url = add_query_arg( 's', $txn_id, $txn_url );

			$status = isset( $txn['PAYMENTSTATUS'] ) ? $txn['PAYMENTSTATUS'] : $txn['PAYMENTINFO_0_PAYMENTSTATUS'];
			$rows[] = array( 'Transaction ID', sprintf( '<a href="%s">%s</a>', $txn_url, $txn_id ) );
			$rows[] = array( 'Payment Status', $status );

			if ( isset( $txn['PAYMENTINFO_0_PENDINGREASON'] ) && $status == 'Pending' )
				$rows[] = array( 'Pending Reason', $txn['PAYMENTINFO_0_PENDINGREASON'] );
			if ( isset( $txn['PENDINGREASON'] ) && $status == 'Pending' )
				$rows[] = array( 'Pending Reason', $txn['PENDINGREASON'] );

			if ( isset( $txn['EMAIL'] ) )
				$rows[] = array( 'Buyer E-mail', esc_html( $txn['EMAIL'] ) );
		}

		$coupon_id = get_post_meta( $post->ID, 'tix_coupon_id', true );
		if ( $coupon_id ) {
			$coupon = get_post( $coupon_id );
			$rows[] = array( 'Coupon', sprintf( '<a href="%s">%s</a>', get_edit_post_link( $coupon->ID ), $coupon->post_title ) );
		}

		$rows[] = array( 'Order Total', $this->append_currency( get_post_meta( $post->ID, 'tix_order_total', true ) ) );

		// Reservation
		if ( $this->options['reservations_enabled'] ) {
			$reservation_id = get_post_meta( $post->ID, 'tix_reservation_id', true );
			$reservation_token = get_post_meta( $post->ID, 'tix_reservation_token', true );
			$reservation_url = get_admin_url( 0, '/edit.php?post_type=tix_attendee' );
			$reservation_url = add_query_arg( 's', 'tix_reservation_id:' . $reservation_id, $reservation_url );
			if ( $reservation_id && $reservation_token )
				$rows[] = array( 'Reservation', sprintf( '<a href="%s">%s</a>', esc_url( $reservation_url ), esc_html( $reservation_id ) ) );
		}

		// Questions
		$rows[] = array( 'Questions', '' );
		$questions = $this->get_sorted_questions( $ticket_id );
		$answers = get_post_meta( $post->ID, 'tix_questions', true );

		foreach ( $questions as $question ) {
			$question_key = sanitize_title_with_dashes( $question['field'] );
			if ( isset( $answers[$question_key] ) ) {
				$answer = $answers[$question_key];
				if ( is_array( $answer ) )
					$answer = implode( ', ', $answer );
				$rows[] = array( $question['field'], esc_html( $answer ) );
			}
		}
		$this->table( $rows, 'tix-attendees-info' );
	}

	/**
	 * Saves ticket post meta, runs during save_post, which runs whenever 
	 * the post type is saved, and not necessarily from the admin, which is why the nonce check.
	 */
	function save_ticket_post( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || 'tix_ticket' != get_post_type( $post_id ) )
			return;

		// Stuff here is submittable via POST only.
		if ( ! isset( $_POST['action'] ) || 'editpost' != $_POST['action'] )
			return;

		// Security check.
		$nonce_action = 'update-tix_ticket_' . $post_id; // see edit-form-advanced.php
		check_admin_referer( $nonce_action );

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

			if ( isset( $this->options['questions_v2'] ) && $this->options['questions_v2'] )
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

				if ( isset( $this->options['questions_v2'] ) && $this->options['questions_v2'] ) {
					$clean_question['required'] = (bool) $question['required'];
				}

				// Save serialized value.
				add_post_meta( $post_id, 'tix_question', $clean_question );
			}
		}

		// Reservations
		if ( isset( $_POST['tix_doing_reservations'] ) && $this->options['reservations_enabled'] ) {

			// Make a new reservation
			if ( isset( $_POST['tix_reservation_id'], $_POST['tix_reservation_quantity'] )
				&& ! empty( $_POST['tix_reservation_id'] ) && intval( $_POST['tix_reservation_quantity'] ) > 0 ) {

				$reservation_id = sanitize_title_with_dashes( $_POST['tix_reservation_id'] );
				$reservation_quantity = intval( $_POST['tix_reservation_quantity'] );
				$reservation_token = md5( 'caMptix-r353rv4t10n' . rand( 1, 9999 ) . time() . $reservation_id . $post_id );
				$reservation = array(
					'id' => $reservation_id,
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
			'tix_paypal_transaction_id',
			'tix_paypal_payer_id',
			'tix_paypal_token',
			'tix_questions',
			'tix_coupon',
			'tix_reservation_id',
			'tix_reservation_token',
		);
		$data = array( 'timestamp' => time() );

		foreach ( $search_meta_fields as $key )
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
		if ( wp_is_post_revision( $post_id ) || 'tix_coupon' != get_post_type( $post_id ) )
			return;

		// Stuff here is submittable via POST only.
		if ( ! isset( $_POST['action'] ) || 'editpost' != $_POST['action'] )
			return;

		// Security check.
		$nonce_action = 'update-tix_coupon_' . $post_id; // see edit-form-advanced.php
		check_admin_referer( $nonce_action );

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

		if ( isset( $_POST ) && ! empty( $_POST ) )
			$this->form_data = $_POST;

		$this->tickets = array();
		$this->tickets_selected = array();
		$coupon_used_count = 0;
		$via_reservation = false;

		if ( 'paypal_return' == get_query_var( 'tix_action' ) && isset( $_REQUEST['token'] ) && ! empty( $_REQUEST['token'] ) ) {

			// Get all attendees for this return.
			$token = $_REQUEST['token'];
			$attendees = get_posts( array(
				'posts_per_page' => -1,
				'post_type' => 'tix_attendee',
				'post_status' => array( 'draft' ),
				'meta_query' => array(
					array(
						'key' => 'tix_paypal_token',
						'value' => $token,
						'compare' => '=',
						'type' => 'CHAR',
					)
				),
			) );

			if ( $attendees ) {

				// Set the coupon request for paypal return.
				if ( $paypal_return_coupon = get_post_meta( $attendees[0]->ID, 'tix_coupon', true ) )
					$_REQUEST['tix_coupon'] = $paypal_return_coupon;

				// Set the selected tickets for this paypal return.
				$_POST['tix_tickets_selected'] = (array) get_post_meta( $attendees[0]->ID, 'tix_tickets_selected', true );

				// Let's dig into some reservations here.
				foreach ( $attendees as $attendee ) {
					$reservation_token = get_post_meta( $attendee->ID, 'tix_reservation_token', true );
					if ( $reservation_token && $this->get_reservation( $reservation_token ) ) {
						$reservation = $this->get_reservation( $reservation_token );
						$_REQUEST['tix_reservation_id'] = $reservation['id'];
						$_REQUEST['tix_reservation_token'] = $reservation['token'];
					}
				}
			}

			unset( $attendees, $paypal_return_coupon );
		}

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
					$ticket->tix_discounted_text = sprintf( 'Discounted %s', $this->append_currency( $this->coupon->tix_discount_price ) );
				} elseif ( $this->coupon->tix_discount_percent > 0 ) {
					$ticket->tix_discounted_price = number_format( $ticket->tix_price - ( $ticket->tix_price * $this->coupon->tix_discount_percent / 100 ), 2, '.', '' );
					$ticket->tix_discounted_text = sprintf( 'Discounted %s%%', $this->coupon->tix_discount_percent );
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

		$this->error_flags['no_tickets_selected'] = true;
		foreach ( $this->tickets_selected as $ticket_id => $count )
			if ( $count > 0 ) unset( $this->error_flags['no_tickets_selected'] );

		$this->did_template_redirect = true;

		if ( 'attendee_info' == get_query_var( 'tix_action' ) && isset( $_POST['tix_coupon_submit'], $_POST['tix_coupon'] ) && ! empty( $_POST['tix_coupon'] ) )
			return $this->shortcode_contents = $this->form_start();

		if ( 'attendee_info' == get_query_var( 'tix_action' ) && isset( $this->error_flags['no_tickets_selected'] ) )
			return $this->shortcode_contents = $this->form_start();

		if ( 'attendee_info' == get_query_var( 'tix_action' ) )
			return $this->shortcode_contents = $this->form_attendee_info();

		if ( 'checkout' == get_query_var( 'tix_action' ) )
			return $this->shortcode_contents = $this->form_checkout();

		if ( 'paypal_return' == get_query_var( 'tix_action' ) )
			return $this->shortcode_contents = $this->paypal_return();

		if ( 'paypal_cancel' == get_query_var( 'tix_action' ) )
			return $this->shortcode_contents = $this->paypal_cancel();

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
			return 'An error has occurred.';
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

		if ( $this->options['paypal_sandbox'] || empty( $this->options['paypal_api_username'] ) )
			$this->notice( 'Ticket sales are in sandbox mode. All purchases during sandbox mode will be deleted.' );

		if ( isset( $this->error_flags['invalid_coupon'] ) )
			$this->error( 'Sorry, but the coupon you have entered seems to be invalid or expired.' );

		if ( isset( $this->error_flags['invalid_reservation'] ) )
			$this->error( 'Sorry, but the reservation you are trying to use seems to be invalid or expired.' );

		if ( ! $available_tickets )
			$this->notice( 'Sorry, but there are currently no tickets for sale. Please try again later.' );

		if ( $available_tickets && $this->coupon )
			$this->info( 'Your coupon has been applied, awesome!' );

		if ( $available_tickets && isset( $this->reservation ) && $this->reservation )
			$this->info( 'You are using a reservation, cool!' );

		if ( ! isset( $_POST['tix_coupon_submit'], $_POST['tix_coupon'] ) || empty( $_POST['tix_coupon'] ) )
			if ( isset( $this->error_flags['no_tickets_selected'] ) && 'attendee_info' == get_query_var( 'tix_action' )  )
				$this->error( 'Please select at least one ticket.' );

		if ( 'checkout' == get_query_var( 'tix_action' ) && isset( $this->error_flags['no_tickets_selected'] ) )
			$this->error( 'It looks like somebody took that last ticket before you, sorry! You try a different ticket.' );

		$redirected_error_flags = isset( $_REQUEST['tix_errors'] ) ? array_flip( (array) $_REQUEST['tix_errors'] ) : array();
		if ( isset( $redirected_error_flags['paypal_http_error'] ) ) {
			if ( isset( $_REQUEST['tix_error_data']['paypal_http_error_code'] ) )
				$this->error( sprintf( 'PayPal Error: %s', $this->paypal_error( $_REQUEST['tix_error_data']['paypal_http_error_code'] ) ) );
			else
				$this->error( 'An HTTP error has occurred, looks like PayPal is not responding. Please try again later.' );
		}

		if ( isset( $redirected_error_flags['tickets_excess'] ) )
			$this->error( 'It looks like somebody grabbed those tickets before you could complete the purchase. You have not been charged, please try again.' );

		if ( isset( $redirected_error_flags['coupon_excess'] ) )
			$this->error( 'It looks like somebody has used the coupon before you could complete your purchase. You have not been charged, please try again.' );

		if ( isset( $redirected_error_flags['invalid_coupon'] ) )
			$this->error( 'It looks like the coupon you are trying to use has expired before you could complete your purchase. You have not been charged, please try again.' );

		if ( isset( $redirected_error_flags['invalid_access_token'] ) )
			$this->error( "Your access token does not seem to be valid." );

		if ( isset( $redirected_error_flags['cancelled'] ) )
			$this->error( 'It looks like you have cancelled your PayPal transaction. Feel free to try again!' );

		if ( isset( $redirected_error_flags['invalid_edit_token'] ) )
			$this->error( 'The edit link you are trying to use is either invalid or has expired.' );

		if ( isset( $redirected_error_flags['cannot_refund'] ) )
			$this->error( 'Your refund request can not be processed. Please try again later or contact support.' );

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
						<th class="tix-column-description">Description</th>
						<th class="tix-column-price">Price</th>
						<th class="tix-column-remaining">Remaining</th>
						<th class="tix-column-quantity">Quantity</th>
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
							$selected = 0;
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
									Using coupon: <strong><?php echo esc_html( $this->coupon->post_title ); ?></strong>
								<?php else : ?>
								<a href="#" id="tix-coupon-link">Click here to enter a coupon code</a>
								<div id="tix-coupon-container" style="display: none;">
									<input type="text" id="tix-coupon-input" name="tix_coupon" value="" />
									<input type="submit" name="tix_coupon_submit" value="Apply Coupon" />
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
				<input type="submit" value="Register &rarr;" style="float: right; cursor: pointer;" />
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
				$this->notice( 'It looks like you have chosen more tickets than we have left! We have stripped the extra ones.' );
			elseif ( 'checkout' == get_query_var( 'tix_action' ) )
				$this->error( 'It looks like somebody purchased a ticket before you could finish your purchase. Please review your order and try again.' );

		if ( isset( $this->error_flags['coupon_excess'] ) )
			if ( 'attendee_info' == get_query_var( 'tix_action' ) )
				$this->notice( 'You have exceeded the coupon limits, so we have stripped down the extra tickets.' );
			elseif ( 'checkout' == get_query_var( 'tix_action' ) )
				$this->error( 'It looks like somebody used the same coupon before you could finish your purchase. Please review your order and try again.' );

		if ( isset( $this->error_flags['required_fields'] ) )
			$this->error( 'Please fill in all required fields.' );

		if ( isset( $this->error_flags['invalid_email'] ) )
			$this->error( 'The e-mail address you have entered seems to be invalid.' );

		if ( isset( $this->error_flags['no_receipt_email'] ) )
			$this->error( 'The chosen receipt e-mail address is either empty or invalid.' );

		if ( isset( $this->error_flags['paypal_http_error'] ) )
			$this->error( 'An HTTP error has occurred, looks like PayPal is not responding. Please try again later.' );

		if ( 'checkout' == get_query_var( 'tix_action' ) && isset( $this->error_flags['invalid_coupon'] ) )
			$this->notice( "Looks like you're trying to use an invalid or expired coupon." );

		if ( 'attendee_info' == get_query_var( 'tix_action' ) && $this->coupon )
			$this->info( "You're using a coupon, cool!" );

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

				<h2>Order Summary</h2>
				<table class="tix_tickets_table tix-order-summary">
					<thead>
						<tr>
							<th class="tix-column-description">Description</th>
							<th class="tix-column-per-ticket">Per Ticket</th>
							<th class="tix-column-quantity">Quantity</th>
							<th class="tix-column-price">Price</th>
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
									<small>Using coupon: <strong><?php echo esc_html( $this->coupon->post_title ); ?></strong></small>
								<?php endif; ?>
							</td>
							<td><strong><?php echo $this->append_currency( $total ); ?></strong></td>
						</tr>
					</tbody>
				</table>

				<h2 id="tix-registration-information">Registration Information</h2>
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
									<td class="tix-required tix-left">First Name <span class="tix-required-star">*</span></td>
									<?php $value = isset( $this->form_data['tix_attendee_info'][$i]['first_name'] ) ? $this->form_data['tix_attendee_info'][$i]['first_name'] : ''; ?>
									<td class="tix-right"><input name="tix_attendee_info[<?php echo $i; ?>][first_name]" type="text" value="<?php echo esc_attr( $value ); ?>" /></td>
								</tr>
								<tr class="tix-row-last-name">
									<td class="tix-required tix-left">Last Name <span class="tix-required-star">*</span></td>
									<?php $value = isset( $this->form_data['tix_attendee_info'][$i]['last_name'] ) ? $this->form_data['tix_attendee_info'][$i]['last_name'] : ''; ?>
									<td class="tix-right"><input name="tix_attendee_info[<?php echo $i; ?>][last_name]" type="text" value="<?php echo esc_attr( $value ); ?>" /></td>
								</tr>
								<tr class="tix-row-email">
									<td class="tix-required tix-left">E-mail <span class="tix-required-star">*</span></td>
									<?php $value = isset( $this->form_data['tix_attendee_info'][$i]['email'] ) ? $this->form_data['tix_attendee_info'][$i]['email'] : ''; ?>
									<td class="tix-right">
										<input class="tix-field-email" name="tix_attendee_info[<?php echo $i; ?>][email]" type="text" value="<?php echo esc_attr( $value ); ?>" />
										<?php
											$tix_receipt_email = isset( $this->form_data['tix_receipt_email'] ) ? $this->form_data['tix_receipt_email'] : 1;
										?>
										<?php if ( $this->tickets_selected_count > 1 ) : ?>
											<div class="tix-hide-if-js">
												<label><input name="tix_receipt_email" <?php checked( $tix_receipt_email, $i ); ?> value="<?php echo $i; ?>" type="radio" /> Send the receipt to this address</label>
											</div>
										<?php else: ?>
											<input name="tix_receipt_email" type="hidden" value="1" />
										<?php endif; ?>
									</td>
								</tr>

								<?php do_action( 'camptix_question_fields_init' ); ?>
								<?php foreach ( $questions as $question ) : ?>

									<?php
										$question_key = sanitize_title_with_dashes( $question['field'] );
										$name = sprintf( 'tix_attendee_questions[%d][%s]', $i, $question_key );
										$value = isset( $this->form_data['tix_attendee_questions'][$i][$question_key] ) ? $this->form_data['tix_attendee_questions'][$i][$question_key] : '';
										$question_type = $question['type'];
									?>
									<tr class="tix-row-<?php echo $question_key; ?>">
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
						<th colspan="2">Receipt</th>
					</tr>
					<tr>
						<td class="tix-left tix-required">E-mail the receipt to <span class="tix-required-star">*</span></td>
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
					<input type="submit" value="" style="background: transparent url('https://www.paypal.com/en_US/i/btn/btn_xpressCheckout.gif') 0 0 no-repeat; border: none; width: 145px; height: 42px;" />
					<?php else : ?>
						<input type="submit" value="Claim Tickets &rarr;" />
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
			$this->notice( 'Please note that the payment for this set of tickets is still pending.' );
		?>
		<div id="tix">
		<?php do_action( 'camptix_notices' ); ?>
		<table class="tix-ticket-form">
			<thead>
				<tr>
					<th>Tickets Summary</th>
					<th>Purchase Date</th>
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
							<a href="<?php echo esc_url( $edit_link ); ?>">Edit information</a>
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
		<p>Change of plans? Made a mistake? Don't worry, you can <a href="<?php echo esc_url( $this->get_refund_tickets_link( $access_token ) ); ?>">request a refund</a>.</p>
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
				$this->notice( 'This attendee is not published.' );
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
			$this->notice( 'Please note that the payment for this ticket is still pending.' );

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

			// todo validate new attendee data here, maybe wrap data validation.
			if ( empty( $new_ticket_info['first_name'] ) || empty( $new_ticket_info['last_name'] ) )
				$errors[] = 'Please fill in all required fields.';

			if ( ! is_email( $new_ticket_info['email'] ) )
				$errors[] = 'You have entered an invalid e-mail, please try again.';

			$new_answers = array();
			foreach ( $questions as $question ) {
				$question_key = sanitize_title_with_dashes( $question['field'] );
				if ( isset( $_POST['tix_ticket_questions'][$question_key] ) ) {
					$new_answers[$question_key] = stripslashes_deep( $posted['tix_ticket_questions'][$question_key] );
				}

				// @todo maybe check $user_values against $type and $question_values

				if ( $question['required'] && ( ! isset( $new_answers[$question_key] ) || empty( $new_answers[$question_key] ) ) ) {
					$errors[] = 'Please fill in all required fields.';
				}
			}

			if ( count( $errors ) > 0 ) {
				$this->error( 'Your information has not been changed!' );
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

				$this->info( 'Your information has been saved!' );
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

				<h2>Attendee Information</h2>
				<table class="tix_tickets_table tix-attendee-form">
					<tbody>
						<tr>
							<th colspan="2">
								<?php echo $ticket->post_title; ?>
							</th>
						</tr>
						<tr>
							<td class="tix-required tix-left">First Name <span class="tix-required-star">*</span></td>
							<td class="tix-right"><input name="tix_ticket_info[first_name]" type="text" value="<?php echo esc_attr( $ticket_info['first_name'] ); ?>" /></td>
						</tr>
						<tr>
							<td class="tix-required tix-left">Last Name <span class="tix-required-star">*</span></td>
							<td class="tix-right"><input name="tix_ticket_info[last_name]" type="text" value="<?php echo esc_attr( $ticket_info['last_name'] ); ?>" /></td>
						</tr>
						<tr>
							<td class="tix-required tix-left">E-mail <span class="tix-required-star">*</span></td>
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
					<input type="submit" value="Save Attendee Information" style="float: right; cursor: pointer;" />
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
				$this->error( 'You have to agree to the terms to request a refund.' );
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

					$this->info( 'Your tickets have been successfully refunded.' );
					ob_end_clean();
					return $this->form_refund_success();
				} else {
					$this->error( 'Can not refund the transaction at this time. Please try again later.' );
				}
			}
		}

		ob_start();
		?>
		<div id="tix">
			<?php do_action( 'camptix_notices' ); ?>
			<form action="<?php echo esc_url( add_query_arg( 'tix_action', 'refund_request' ) ); ?>#tix" method="POST">
				<input type="hidden" name="tix_refund_request_submit" value="1" />

				<h2>Refund Request</h2>
				<table class="tix_tickets_table tix-attendee-form">
					<tbody>
						<tr>
							<th colspan="2">
								Request Details
							</th>
						</tr>
						<tr>
							<td class="tix-left">E-mail</td>
							<td class="tix-right"><?php echo esc_html( $transaction['EMAIL'] ); ?></td>
						</tr>
						<tr>
							<td class="tix-left">Original Payment</td>
							<td class="tix-right"><?php printf( "%s %s", $transaction['CURRENCYCODE'], $transaction['AMT'] ); ?></td>
						</tr>
						<tr>
							<td class="tix-left">Purchased Tickets</td>
							<td class="tix-right">
								<?php foreach ( $tickets as $ticket_id => $count ) : ?>
									<?php echo esc_html( sprintf( "%s x%d", $this->get_ticket_title( $ticket_id ), $count ) ); ?><br />
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<td class="tix-left">Refund Amount</td>
							<td class="tix-right"><?php printf( "%s %s", $transaction['CURRENCYCODE'], $transaction['AMT'] ); ?></td>
						</tr>
						<tr>
							<td class="tix-left">Refund Reason</td>
							<td class="tix-right"><textarea name="tix_refund_request_reason"><?php echo esc_textarea( $reason ); ?></textarea></td>
						</tr>

					</tbody>
				</table>
				<p class="tix-description">Refunds can take up to several days to process. All purchased tickets will be cancelled. Partial refunds and refunds to a different account that the original purchaser, are unavailable. You have to agree to these terms before requesting a refund.</p>
				<p class="tix-submit">
					<label><input type="checkbox" name="tix_refund_request_confirmed" value="1"> I agree to the above terms</label>
					<input type="submit" value="Send Request" />
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
	 */
	function is_refundable( $attendee_id ) {
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
	 * Use this method to clear up the pending queue, runs hourly via cron.
	 */
	function paypal_review_pending_payments() {
		global $post;

		$q = new WP_Query( array(
			'posts_per_page' => 10,
			'post_type' => 'tix_attendee',
			'post_status' => array( 'pending' ),
			'update_post_term_cache' => false,
		) );

		while ( $q->have_posts() ) {
			$q->the_post();
			$txn_id = get_post_meta( $post->ID, 'tix_paypal_transaction_id', true );
			$payload = array(
				'METHOD' => 'GetTransactionDetails',
				'TRANSACTIONID' => $txn_id,
			);
			$txn = wp_parse_args( wp_remote_retrieve_body( $this->paypal_request( $payload ) ) );
			if ( isset( $txn['ACK'], $txn['PAYMENTSTATUS'] ) && $txn['ACK'] == 'Success' ) {

				// Record the new txn and publish attendee if completed.
				update_post_meta( $post->ID, 'tix_paypal_transaction_details', $txn );
				if ( $txn['PAYMENTSTATUS'] == 'Completed' ) {
					$post->post_status = 'publish';
					wp_update_post( $post );
				}

				if ( $txn['PAYMENTSTATUS'] == 'Failed' ) {
					$post->post_status = 'failed';
					wp_update_post( $post );
				}

				$this->log( sprintf( 'Reviewing PayPal transaction, payment status: %s', $txn['PAYMENTSTATUS'] ), $post->ID, $txn );
			} else {
				$this->log( sprintf( 'Could not review PayPal transaction: %s', $txn_id ), $post->ID, $txn );
			}
		}
	}

	/**
	 * Review Timeout Payments
	 *
	 * This routine looks up old draft attendee posts and puts
	 * their status into Timeout.
	 */
	function paypal_review_timeout_payments() {

		// Nothing to do for archived sites.
		if ( $this->options['archived'] )
			return;

		// No cool stuff for non upgraded sites.
		if ( ! $this->is_upgraded() )
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
	 * PayPal Return. This function is fired when the user has accepted the
	 * payment at PayPal and is being returned here for the final confirmation.
	 */
	function paypal_return() {
		global $post;
		if ( get_query_var( 'tix_action' ) != 'paypal_return' )
			return;

		if ( ! isset( $_REQUEST['token'], $_REQUEST['PayerID'] ) )
			return;

		$token = $_REQUEST['token'];
		$payer_id = $_REQUEST['PayerID'];

		$attendees = get_posts( array(
			'posts_per_page' => -1,
			'post_type' => 'tix_attendee',
			'post_status' => array( 'draft' ),
			'meta_query' => array(
				array(
					'key' => 'tix_paypal_token',
					'value' => $token,
					'compare' => '=',
					'type' => 'CHAR',
				)
			),
		) );

		if ( $attendees ) {
			$expected_total = (float) get_post_meta( $attendees[0]->ID, 'tix_order_total', true );
			$receipt_email = get_post_meta( $attendees[0]->ID, 'tix_receipt_email', true );
			$receipt_content = '';

			$payload = array(
				'METHOD' => 'GetExpressCheckoutDetails',
				'TOKEN' => $token,
			);
			$request = $this->paypal_request( $payload );
			$checkout_details = wp_parse_args( wp_remote_retrieve_body( $request ) );

			if ( isset( $checkout_details['ACK'] ) && $checkout_details['ACK'] == 'Success' ) {

				if ( (float) $checkout_details['PAYMENTREQUEST_0_AMT'] != $expected_total ) {
					echo "Unexpected total!";
					die();
				}

				if ( count( $this->error_flags ) > 0 ) {
					$this->redirect_with_error_flags();
					die();
				}

				foreach ( $this->tickets_selected as $ticket_id => $count ) {
					$ticket = $this->tickets[$ticket_id];
					$price = $ticket->tix_coupon_applied ? $ticket->tix_discounted_price : $ticket->tix_price;

					// * Ticket Name ($1.00) x3 = $3.00
					$receipt_content .= sprintf( "* %s (%s) x%d = %s\n", $ticket->post_title, $this->append_currency( $price, false ), $count, $this->append_currency( $price * $count, false ) );
				}

				if ( $this->coupon )
					$receipt_content .= sprintf( "* Coupon used: %s\n", $this->coupon->post_title );

				$receipt_content .= sprintf( "* Total: %s", $this->append_currency( $expected_total, false ) );

				$payload = array(
					'METHOD' => 'DoExpressCheckoutPayment',
					'TOKEN' => $token,
					'PAYERID' => $payer_id,
					'PAYMENTREQUEST_0_AMT' => number_format( (float) $expected_total, 2, '.', '' ),
					'PAYMENTREQUEST_0_ITEMAMT' => number_format( (float) $expected_total, 2, '.', '' ),
					'PAYMENTREQUEST_0_CURRENCYCODE' => $this->options['paypal_currency'],
					'PAYMENTREQUEST_0_NOTIFYURL' => esc_url_raw( add_query_arg( 'tix_paypal_ipn', 1, trailingslashit( home_url() ) ) ),
				);

				if ( $this->coupon )
					$payload['PAYMENTREQUEST_0_CUSTOM'] = substr( sprintf( 'Using coupon: %s', $this->coupon->post_title ), 0, 255 );

				$i = 0; $total = 0;
				foreach ( $this->tickets_selected as $ticket_id => $count ) {
					$ticket = $this->tickets[$ticket_id];

					$name = sprintf( '%s: %s', $this->options['paypal_statement_subject'], $ticket->post_title );
					$desc = $ticket->post_excerpt;

					$payload['L_PAYMENTREQUEST_0_NAME' . $i] = substr( $name, 0, 127 );
					$payload['L_PAYMENTREQUEST_0_DESC' . $i] = substr( $desc, 0, 127 );
					$payload['L_PAYMENTREQUEST_0_NUMBER' . $i] = $ticket->ID;
					$price = ( $this->coupon ) ? $ticket->tix_discounted_price : $ticket->tix_price;
					$payload['L_PAYMENTREQUEST_0_AMT' . $i] = $price;
					$payload['L_PAYMENTREQUEST_0_QTY' . $i] = $count;
					$i++;
				}

				$request = $this->paypal_request( $payload );
				$txn = wp_parse_args( wp_remote_retrieve_body( $request ) );

				if ( isset( $txn['ACK'], $txn['PAYMENTINFO_0_PAYMENTSTATUS'] ) && $txn['ACK'] == 'Success' ) {

					// Used to access these tickets at a later stage.
					$access_token = md5( wp_hash( $payer_id . $txn['PAYMENTINFO_0_TRANSACTIONID'] . time(), 'nonce' ) );
					$txn_id = $txn['PAYMENTINFO_0_TRANSACTIONID'];

					// Store transaction details.
					$payload = array(
						'METHOD' => 'GetTransactionDetails',
						'TRANSACTIONID' => $txn_id,
					);
					$request = $this->paypal_request( $payload );
					$txn_details = wp_parse_args( wp_remote_retrieve_body( $request ) );

					foreach ( $attendees as $attendee ) {

						$edit_token = md5( wp_hash( $access_token . $attendee->ID . time(), 'nonce' ) );

						$this->log( sprintf( 'Returned from PayPal with status: %s', $txn['PAYMENTINFO_0_PAYMENTSTATUS'] ), $attendee->ID, $txn );
						update_post_meta( $attendee->ID, 'tix_paypal_payer_id', $payer_id );
						update_post_meta( $attendee->ID, 'tix_paypal_checkout_details', $checkout_details );
						update_post_meta( $attendee->ID, 'tix_paypal_transaction_details', $txn_details );
						update_post_meta( $attendee->ID, 'tix_paypal_transaction_id', $txn_id );
						update_post_meta( $attendee->ID, 'tix_access_token', $access_token );
						update_post_meta( $attendee->ID, 'tix_edit_token', $edit_token );

						if ( is_email( $receipt_email ) )
							$this->log( sprintf( 'Receipt has been sent to %s', $receipt_email ), $attendee->ID );

						if ( $txn['PAYMENTINFO_0_PAYMENTSTATUS'] == 'Completed' ) {
							$attendee->post_status = 'publish';
						} else {
							// Don't confuse with PAYMENTSTATUS = Pending, we just set it to pending.
							// https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_DoExpressCheckoutPayment
							// IPN will do the rest.
							$attendee->post_status = 'pending';
						}

						wp_update_post( $attendee );

						// If there's more than 1 ticket, send e-mails to all
						if ( $this->tickets_selected_count > 1 )
							$this->email_ticket_to_attendee( $attendee->ID );
					}

					if ( is_email( $receipt_email ) ) {

						// If there is more than 1 ticket, we'll send a receipt for all + individual
						// ticket to each, otherwise we'll send only one receipt, which is the ticket anyway.
						if ( $this->tickets_selected_count > 1 ) {

							$edit_link = $this->get_access_tickets_link( $access_token );
							$content = sprintf( "Hey there!\n\nYou have purchased the following tickets:\n\n%s\n\nYou can edit the information for all the purchased tickets at any time before the event, by visiting the following link:\n\n%s\n\nLet us know if you have any questions!", $receipt_content, $edit_link );
							$subject = sprintf( "Your Tickets to %s", $this->options['paypal_statement_subject'] );

						} else { // only one recipient

							// Give them the edit attendee link instead
							$edit_link = $this->get_access_tickets_link( $access_token );
							$content = sprintf( "Hey there!\n\nYou have purchased the following ticket:\n\n%s\n\nYou can edit the information for the purchased ticket at any time before the event, by visiting the following link:\n\n%s\n\nLet us know if you have any questions!", $receipt_content, $edit_link );
							$subject = sprintf( "Your Ticket to %s", $this->options['paypal_statement_subject'] );
							$this->log( 'Single purchase, so sent ticket and receipt in one e-mail.', $attendees[0]->ID );
						}

						if ( $txn['PAYMENTINFO_0_PAYMENTSTATUS'] != 'Completed' )
							$content .= sprintf( "\n\nYour payment status is: %s. You will receive a notification e-mail once your payment is completed.", strtolower( $txn['PAYMENTINFO_0_PAYMENTSTATUS'] ) );

						$this->wp_mail( $receipt_email, $subject, $content );
					}
					// Show the purchased tickets.
					$url = add_query_arg( array( 'tix_action' => 'access_tickets', 'tix_access_token' => $access_token ), $this->get_tickets_url() );
					wp_safe_redirect( $url . '#tix' );
					die();

				} else {

					// Log this error
					foreach ( $attendees as $attendee )
						$this->log( 'Payment cancelled due to an HTTP error during DoExpressCheckoutPayment.', $attendee->ID, $request );

					if ( isset( $txn['L_ERRORCODE0'] ) )
						$this->error_data['paypal_http_error_code'] = intval( $txn['L_ERRORCODE0'] );

					$this->error_flags['paypal_http_error'] = true;
					$this->redirect_with_error_flags();
					die();
				}
			} else {

				// Log this error
				foreach ( $attendees as $attendee )
					$this->log( 'Payment cancelled due to an HTTP error during GetExpressCheckoutDetails.', $attendee->ID, $request );

				$this->error_flags['paypal_http_error'] = true;
				$this->redirect_with_error_flags();
				die();
			}
		} else {
			$this->log( 'Attendee not found in paypal_return.' );
		}

		// echo 'doing paypal return';
		die();
	}

	function paypal_cancel() {
		if ( ! isset( $_REQUEST['token'] ) || empty( $_REQUEST['token'] ) )
			return;

		$token = $_REQUEST['token'];

		$attendees = get_posts( array(
			'posts_per_page' => -1,
			'post_type' => 'tix_attendee',
			'post_status' => array( 'draft' ),
			'meta_query' => array(
				array(
					'key' => 'tix_paypal_token',
					'value' => $token,
					'compare' => '=',
					'type' => 'CHAR',
				)
			),
		) );

		foreach ( $attendees as $attendee ) {
			$attendee->post_status = 'cancel';
			wp_update_post( $attendee );
			$this->log( 'Transaction was cancelled at PayPal.', $attendee->ID );
		}

		$this->error_flags['cancelled'] = true;
		$this->redirect_with_error_flags();
		die();
	}

	function paypal_ipn() {
		if ( ! isset( $_REQUEST['tix_paypal_ipn'] ) )
			return;

		// Verify the IPN came from PayPal.
		$payload = stripslashes_deep( $_POST );
		$response = $this->paypal_verify_ipn( $payload );
		if ( wp_remote_retrieve_response_code( $response ) != '200' || wp_remote_retrieve_body( $response ) != 'VERIFIED' ) {
			$this->log( 'Could not verify PayPal IPN.', 0, null, 'ipn' );
			return;
		}

		// Grab the txn id (or the parent id in case of refunds, cancels, etc)
		$txn_id = isset( $payload['txn_id'] ) && ! empty( $payload['txn_id'] ) ? $payload['txn_id'] : 'None';
		if ( isset( $payload['parent_txn_id'] ) && ! empty( $payload['parent_txn_id'] ) )
			$txn_id = $payload['parent_txn_id'];

		$attendees = $this->get_attendees_by_txn_id( $txn_id );

		if ( ! is_array( $attendees ) || ! $attendees ) {
			$this->log( sprintf( 'Received IPN without attendees association %s', $txn_id ) );
			return;
		}

		// Case created, etc are subject to IPN too.
		if ( ! isset( $payload['payment_status'] ) ) {
			$this->log( sprintf( 'Received IPN with no payment status %s', $txn_id ), 0, $payload );
			return;
		}

		// Get most recent transaction details.
		$txn_details_payload = array(
			'METHOD' => 'GetTransactionDetails',
			'TRANSACTIONID' => $txn_id,
		);
		$txn_details = wp_parse_args( wp_remote_retrieve_body( $this->paypal_request( $txn_details_payload ) ) );
		if ( ! isset( $txn_details['ACK'] ) || $txn_details['ACK'] != 'Success' ) {
			$txn_details = false;
			$this->log( sprintf( 'Fetching transaction after IPN failed %s.', $txn_id, 0, $txn_details ) );
		}

		$log = array();

		// Let's do notifications.
		$attendee = $attendees[0];
		if ( $attendee->post_status != 'publish' && $txn_details['PAYMENTSTATUS'] == 'Completed' ) {
			$receipt_subject = sprintf( "Your Payment for %s", $this->options['paypal_statement_subject'] );
			$receipt_email = get_post_meta( $attendee->ID, 'tix_receipt_email', true );
			$access_token = get_post_meta( $attendee->ID, 'tix_access_token', true );
			$edit_link = $this->get_access_tickets_link( $access_token );

			$receipt_content = sprintf( "Hey there!\n\nYour payment for %s has been completed, looking forward to seeing you at the event! You can access and change your tickets information by visiting the following link:\n\n%s\n\nLet us know if you need any help!", $this->options['paypal_statement_subject'], $edit_link );

			$this->wp_mail( $receipt_email, $receipt_subject, $receipt_content );
			$log[] = sprintf( 'Sending completed notification after ipn to %s.', $receipt_email );
		}

		if ( $attendee->post_status != 'failed' && $txn_details['PAYMENTSTATUS'] == 'Failed' ) {
			$receipt_subject = sprintf( "Your Payment for %s", $this->options['paypal_statement_subject'] );
			$receipt_email = get_post_meta( $attendee->ID, 'tix_receipt_email', true );
			$access_token = get_post_meta( $attendee->ID, 'tix_access_token', true );
			$edit_link = $this->get_access_tickets_link( $access_token );

			$receipt_content = sprintf( "Hey there!\n\nWe're so sorry, but it looks like your payment for %s has failed! Please check your PayPal transactions for more details. If you still wish to attend the event, feel free to purchase a new ticket using the following link:\n\n%s\n\nLet us know if you need any help!", $this->options['paypal_statement_subject'], $this->get_tickets_url() );

			$this->wp_mail( $receipt_email, $receipt_subject, $receipt_content );
			$log[] = sprintf( 'Sent failed notification after ipn to %s.', $receipt_email );
		}

		foreach ( $attendees as $attendee ) {
			$old_status = $attendee->post_status;

			// Change status.
			switch ( $txn_details['PAYMENTSTATUS'] ) {
				case 'Completed':
					$attendee->post_status = 'publish';
					break;
				case 'Pending':
					$attendee->post_status = 'pending';
					break;
				case 'Cancelled':
					$attendee->post_status = 'cancel';
					break;
				case 'Failed':
				case 'Denied':
					$attendee->post_status = 'failed';
					break;
				case 'Refunded':
				case 'Reversed':
					$attendee->post_status = 'refund';
					break;
				default:
					// $attendee->post_status = 'pending';
					break;
			}

			$updating_status = ( $old_status == $attendee->post_status ) ? 'status not updated' : 'updating status';
			$this->log( sprintf( 'IPN result via payload: %s (via txn: %s), %s.', $payload['payment_status'], $txn_details['PAYMENTSTATUS'], $updating_status ), $attendee->ID, $payload, 'ipn' );
			if ( isset( $txn_details ) ) {
				update_post_meta( $attendee->ID, 'tix_paypal_transaction_details', $txn_details );
				$this->log( sprintf( 'Updated transaction details for %s.', $txn_id ), $attendee->ID, $txn_details );
			}

			wp_update_post( $attendee );
			foreach ( $log as $entry )
				$this->log( $entry, $attendee->ID );
		}

		die();
	}

	function get_attendees_by_txn_id( $txn_id ) {
		$attendees = get_posts( array(
			'post_type' => 'tix_attendee',
			'posts_per_page' => -1,
			'post_status' => 'any',
			'meta_query' => array(
				array(
					'key' => 'tix_paypal_transaction_id',
					'compare' => '=',
					'value' => $txn_id,
					'type' => 'CHAR',
				),
			),
		) );
		return $attendees;
	}

	/**
	 * The first e-mail sent to the attendee upon ticket purchase.
	 * @todo maybe e-mail templates.
	 */
	function email_ticket_to_attendee( $attendee_id ) {
		$attendee_email = get_post_meta( $attendee_id, 'tix_email', true );
		$edit_token = get_post_meta( $attendee_id, 'tix_edit_token', true );
		if ( is_email( $attendee_email ) ) {
			$edit_link = $this->get_edit_attendee_link( $attendee_id, $edit_token );
			$content = sprintf( "Hi there!\n\nThank you so much for purchasing a ticket and hope to see you soon at our event. You can edit your information at any time before the event, by visiting the following link:\n\n%s\n\nLet us know if you have any questions!", $edit_link );

			$this->wp_mail( $attendee_email, sprintf( "Your Ticket to %s", $this->options['paypal_statement_subject'] ), $content );
			$this->log( sprintf( 'Sent ticket e-mail to %s', $attendee_email ), $attendee_id );
		}
	}

	/**
	 * Step 3: redirects to PayPal, returns to $this->paypal_return
	 */
	function form_checkout() {

		$attendees = array();
		$errors = array();
		$receipt_email = false;

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

		$payload = array(
			'METHOD' => 'SetExpressCheckout',
			'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
			'RETURNURL' => add_query_arg( 'tix_action', 'paypal_return', $this->get_tickets_url() ),
			'CANCELURL' => add_query_arg( 'tix_action', 'paypal_cancel', $this->get_tickets_url() ),
			'ALLOWNOTE' => 0,
			'NOSHIPPING' => 1,
			'SOLUTIONTYPE' => 'Sole',
		);

		$i = 0; $total = 0;
		foreach ( $this->tickets_selected as $ticket_id => $count ) {
			$ticket = $this->tickets[$ticket_id];

			$name = sprintf( '%s: %s', $this->options['paypal_statement_subject'], $ticket->post_title );
			$desc = $ticket->post_excerpt;

			$payload['L_PAYMENTREQUEST_0_NAME' . $i] = substr( $name, 0, 127 );
			$payload['L_PAYMENTREQUEST_0_DESC' . $i] = substr( $desc, 0, 127 );
			$payload['L_PAYMENTREQUEST_0_NUMBER' . $i] = $ticket->ID;
			$price = ( $this->coupon ) ? $ticket->tix_discounted_price : $ticket->tix_price;
			$payload['L_PAYMENTREQUEST_0_AMT' . $i] = $price;
			$payload['L_PAYMENTREQUEST_0_QTY' . $i] = $count;
			$total += $price * $count;
			$i++;
		}

		$reservation_quantiny = 0;
		if ( isset( $this->reservation ) && $this->reservation )
			$reservation_quantiny = $this->reservation['quantity'];

		$log_data = array(
			'post' => $_POST,
			'server' => $_SERVER,
		);

		foreach ( $attendees as $attendee ) {
			$post_id = wp_insert_post( array(
				'post_title' => $attendee->first_name . " " . $attendee->last_name,
				'post_type' => 'tix_attendee',
				'post_status' => 'draft',
			) );

			if ( $post_id ) {
				$this->log( 'Created attendee draft.', $post_id, $log_data );

				update_post_meta( $post_id, 'tix_timestamp', time() );
				update_post_meta( $post_id, 'tix_ticket_id', $attendee->ticket_id );
				update_post_meta( $post_id, 'tix_first_name', $attendee->first_name );
				update_post_meta( $post_id, 'tix_last_name', $attendee->last_name );
				update_post_meta( $post_id, 'tix_email', $attendee->email );
				update_post_meta( $post_id, 'tix_tickets_selected', $this->tickets_selected );
				update_post_meta( $post_id, 'tix_receipt_email', $receipt_email );

				// Cash
				update_post_meta( $post_id, 'tix_order_total', (float) $total );
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

		// Do we need to pay?
		if ( $total > 0 ) {
			// Totals
			$payload['PAYMENTREQUEST_0_ITEMAMT'] = $total;
			$payload['PAYMENTREQUEST_0_AMT'] = $total;
			$payload['PAYMENTREQUEST_0_CURRENCYCODE'] = $this->options['paypal_currency'];

			$request = $this->paypal_request( $payload );
			$response = wp_parse_args( wp_remote_retrieve_body( $request ) );
			if ( isset( $response['ACK'], $response['TOKEN'] ) && $response['ACK'] == 'Success' ) {
				$token = $response['TOKEN'];

				// Add the token to all attendees.
				foreach ( $attendees as $attendee )
					update_post_meta( $attendee->post_id, 'tix_paypal_token', $token );

				$url = $this->options['paypal_sandbox'] ? 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_express-checkout' : 'https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout';
				$url = add_query_arg( 'token', $token, $url );
				wp_redirect( esc_url_raw( $url ) );
				die();
			} else {
				$this->error_flags['paypal_http_error'] = true;
				return $this->form_attendee_info();
			}
		} else { // free beer for everyone!

			// Create the access token used to access the tickets later.
			$access_token = md5( wp_hash( 'free beer, wohoo!' . time(), 'nonce' ) );
			foreach ( $attendees as $attendee ) {
				$edit_token = md5( wp_hash( $access_token . $attendee->post_id . time(), 'nonce' ) );

				update_post_meta( $attendee->post_id, 'tix_access_token', $access_token );
				update_post_meta( $attendee->post_id, 'tix_edit_token', $edit_token );
				$attendee_post = get_post( $attendee->post_id );
				$attendee_post->post_status = 'publish';
				wp_update_post( $attendee_post );

				$this->log( 'Attendee published due to order total being 0.', $attendee->post_id );
				if ( is_email( $receipt_email ) )
					$this->log( sprintf( 'Receipt has been sent to %s', $receipt_email ), $attendee->post_id );

				// If there's more than 1 ticket, send e-mails to all
				if ( $this->tickets_selected_count > 1 )
					$this->email_ticket_to_attendee( $attendee->post_id );
			}

			if ( is_email( $receipt_email ) ) {
				$receipt_content = '';

				// Let's check what the user is trying to purchase.
				foreach ( $this->tickets_selected as $ticket_id => $count ) {
					$ticket = $this->tickets[$ticket_id];
					$price = $ticket->tix_coupon_applied ? $ticket->tix_discounted_price : $ticket->tix_price;
					// * Ticket Name ($1.00) x3 = $3.00
					$receipt_content .= sprintf( "* %s (%s) x%d = %s\n", $ticket->post_title, $this->append_currency( $price, false ), $count, $this->append_currency( $price * $count, false ) );
				}

				if ( $this->coupon )
					$receipt_content .= sprintf( "* Coupon used: %s\n", $this->coupon->post_title );

				$receipt_content .= sprintf( "* Total: %s", $this->append_currency( 0, false ) );

				if ( $this->tickets_selected_count > 1 ) {

					$edit_link = $this->get_access_tickets_link( $access_token );
					$content = sprintf( "Hey there!\n\nYou have purchased the following tickets:\n\n%s\n\nYou can edit the information for all the purchased tickets at any time before the event, by visiting the following link:\n\n%s\n\nLet us know if you have any questions!", $receipt_content, $edit_link );
					$subject = sprintf( "Your Tickets to %s", $this->options['paypal_statement_subject'] );

				} else {

					$edit_link = $this->get_access_tickets_link( $access_token );
					$content = sprintf( "Hey there!\n\nYou have purchased the following ticket:\n\n%s\n\nYou can edit the information for the purchased ticket at any time before the event, by visiting the following link:\n\n%s\n\nLet us know if you have any questions!", $receipt_content, $edit_link );
					$subject = sprintf( "Your Ticket to %s", $this->options['paypal_statement_subject'] );
					$this->log( 'Single purchase, so sent ticket and receipt in one e-mail.', $attendees[0]->post_id );
				}

				$this->wp_mail( $receipt_email, $subject, $content );
			}

			// Let's see those new shiny tickets!
			$url = add_query_arg( 'tix_action', 'access_tickets' );
			$url = add_query_arg( 'tix_access_token', $access_token, $url );
			wp_safe_redirect( $url );
			die();
		}
	}

	/**
	 * Fire a POST request to PayPal.
	 */
	function paypal_request( $payload = array() ) {
		$url = $this->options['paypal_sandbox'] ? 'https://api-3t.sandbox.paypal.com/nvp' : 'https://api-3t.paypal.com/nvp';
		$payload = array_merge( array(
			'USER' => $this->options['paypal_api_username'],
			'PWD' => $this->options['paypal_api_password'],
			'SIGNATURE' => $this->options['paypal_api_signature'],
			'VERSION' => '88.0', // https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_PreviousAPIVersionsNVP
		), (array) $payload );

		return wp_remote_post( $url, array( 'body' => $payload, 'timeout' => 20 ) );
	}

	function paypal_verify_ipn( $payload = array() ) {
		$url = $this->options['paypal_sandbox'] ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr';
		$payload = 'cmd=_notify-validate&' . http_build_query( $payload );
		return wp_remote_post( $url, array( 'body' => $payload, 'timeout' => 20 ) );
	}

	/**
	 * Returns an error string from a PayPal error code
	 * @see https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_errorcodes
	 */
	function paypal_error( $error_code ) {
		$errors = array(
			0 =>     __( 'An unknown error has occurred. Please try again later.', 'camptix' ),

			10422 => __( 'Please return to PayPal to select new funding sources.', 'camptix' ),
			10417 => __( 'The transaction cannot complete successfully. Please use an alternative payment method.', 'camptix' ),
			10445 => __( 'This transaction cannot be processed at this time. Please try again later.', 'camptix' ),
			10421 => __( 'This Express Checkout session belongs to a different customer. Please try a new purchase.', 'camptix' ),
		);

		$error_code = absint( $error_code );
		if ( isset( $errors[ $error_code ] ) )
			return $errors[ $error_code ];

		return $errors[0];
	}

	/**
	 * Displays attendees in a list.
	 */
	function shortcode_attendees( $atts ) {
		global $post;
		extract( shortcode_atts( array(
			'attr' => 'value',
			'order' => 'ASC',
			'orderby' => 'title',
			'posts_per_page' => 10000,
			'tickets' => false,
		), $atts ) );

		// Lazy load the camptix js.
		wp_enqueue_script( 'camptix' );

		$start = microtime(true);
		$transient_key = md5( 'tix-attendees' . print_r( $atts, true ) );
		if ( false !== ( $cached = get_transient( $transient_key ) ) )
			return $cached;

		// Cache for a month if archived or less if active.
		$cache_time = ( $this->options['archived'] ) ? 60 * 60 * 24 * 30 : 60 * 60;
		$query_args = array();
		ob_start();

		// @todo validate atts here
		if ( ! in_array( strtolower( $order ), array( 'asc', 'desc' ) ) )
			$order = 'asc';

		if ( ! in_array( strtolower( $orderby ), array( 'title', 'date' ) ) )
			$orderby = 'title';

		if ( $tickets ) {
			$tickets = array_map( 'intval', explode( ',', $tickets ) );
			if ( count( $tickets ) > 0 ) {
				$query_args['meta_query'] = array( array(
					'key' => 'tix_ticket_id',
					'compare' => 'IN',
					'value' => $tickets,
				) );
			}
		}

		$paged = 0;
		$printed = 0;
		do_action( 'camptix_attendees_shortcode_init' );
		?>

		<div id="tix-attendees">
			<ul class="tix-attendee-list">
				<?php
					while ( true && $printed < $posts_per_page ) {
						$paged++;
						$attendees = get_posts( array_merge( array(
							'post_type' => 'tix_attendee',
							'posts_per_page' => 200,
							'post_status' => array( 'publish', 'pending' ),
							'paged' => $paged,
							'order' => $order,
							'orderby' => $orderby,
							'fields' => 'ids', // ! no post objects
							'cache_results' => false,
						), $query_args ) );

						if ( ! is_array( $attendees ) || count( $attendees ) < 1 )
							break; // life saver!

						// Disable object cache for prepared metadata.
						$this->filter_post_meta = $this->prepare_metadata_for( $attendees );

						foreach ( $attendees as $attendee_id ) {
							if ( $printed > $posts_per_page )
								break;

							// Skip attendees marked as private.
							$privacy = get_post_meta( $attendee_id, 'tix_privacy', true );
							if ( $privacy == 'private' )
								continue;

							echo '<li>';

							$first = get_post_meta( $attendee_id, 'tix_first_name', true );
							$last = get_post_meta( $attendee_id, 'tix_last_name', true );

							echo get_avatar( get_post_meta( $attendee_id, 'tix_email', true ) );
							printf( '<h2 class="tix-field tix-attendee-name">%s %s</h2>', esc_html( $first ), esc_html( $last ) );
							do_action( 'camptix_attendees_shortcode_item', $attendee_id );
							echo '</li>';

							// clean_post_cache( $attendee_id );
							// wp_cache_delete( $attendee_id, 'posts');
							// wp_cache_delete( $attendee_id, 'post_meta');
							$printed++;

						} // foreach

						$this->filter_post_meta = false; // cleanup
					} // while true
				?>
			</ul>
		</div>
		<br class="tix-clear" />
		<?php
		$this->log( sprintf( 'Generated attendees list in %s seconds', microtime(true) - $start ) );
		wp_reset_postdata();
		$content = ob_get_contents();
		ob_end_clean();
		set_transient( $transient_key, $content, $cache_time );
		return $content;
	}

	/**
	 * Executes during template_redirect, watches for the private
	 * shortcode form submission, searches attendees, sets view token cookies.
	 *
	 * @see shortcode_private
	 */
	function shortcode_private_template_redirect() {

		// Indicates this function did run, nothing more.
		$this->did_shortcode_private_template_redirect = 1;

		if ( isset( $_POST['tix_private_shortcode_submit'] ) ) {
			$first_name = isset( $_POST['tix_first_name'] ) ? trim( $_POST['tix_first_name'] ) : '';
			$last_name = isset( $_POST['tix_last_name'] ) ? trim( $_POST['tix_last_name'] ) : '';
			$email = isset( $_POST['tix_email'] ) ? trim( $_POST['tix_email'] ) : '';

			// Remove cookies if a previous one was set.
			if ( isset( $_COOKIE['tix_view_token'] ) ) {
				setcookie( 'tix_view_token', '', time() - 60*60, COOKIEPATH, COOKIE_DOMAIN, false );
				unset( $_COOKIE['tix_view_token'] );
			}

			if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) )
				return $this->error( 'Please fill in all fields.' );

			if ( ! is_email( $email ) )
				return $this->error( 'The e-mail address you have entered does not seem to be valid.' );

			$attendees = get_posts( array(
				'posts_per_page' => 50, // sane enough?
				'post_type' => 'tix_attendee',
				'post_status' => 'publish',
				'meta_query' => array(
					array(
						'key' => 'tix_first_name',
						'value' => $first_name,
					),
					array(
						'key' => 'tix_last_name',
						'value' => $last_name,
					),
					array(
						'key' => 'tix_email',
						'value' => $email,
					),
				),
			) );

			if ( $attendees ) {
				$attendee = $attendees[0];

				$view_token = $this->generate_view_token_for_attendee( $attendee->ID );
				setcookie( 'tix_view_token', $view_token, time() + 60*60*48, COOKIEPATH, COOKIE_DOMAIN, false );
				$_COOKIE['tix_view_token'] = $view_token;

				foreach ( $attendees as $attendee ) {
					update_post_meta( $attendee->ID, 'tix_view_token', $view_token );
					$count = get_post_meta( $attendee->ID, 'tix_private_form_submit_count', true );
					if ( ! $count ) $count = 0;
					$count++;
					update_post_meta( $attendee->ID, 'tix_private_form_submit_count', $count );
					add_post_meta( $attendee->ID, 'tix_private_form_submit_entry', $_SERVER );
					$this->log( sprintf( 'Viewing private content using %s', @$_SERVER['REMOTE_ADDR'] ), $attendee->ID, $_SERVER, 'private-content' );
				}
			} else {
				$this->error( 'The information you have entered is incorrect. Please try again.' );
			}
		}
	}

	/**
	 * [camptix_private] shortcode callback, depends on the template redirect
	 * part to set cookies, looks for attendee by post by view token, compares
	 * requested ticket ids and shows content or login form.
	 *
	 * @see shortcode_private_template_redirect
	 * @see shortcode_private_login_form
	 * @see shortcode_private_display_content
	 */
	function shortcode_private( $atts, $content ) {
		if ( ! isset( $this->did_shortcode_private_template_redirect ) )
			return 'An error has occured.';

		// Lazy load the camptix js.
		wp_enqueue_script( 'camptix' );

		// Don't cache this page.
		if ( ! defined( 'DONOTCACHEPAGE' ) )
			define( 'DONOTCACHEPAGE', true );

		$args = shortcode_atts( array(
			'ticket_ids' => null,
		), $atts );

		$can_view_content = false;
		$error = false;

		// If we have a view token cookie, we cas use that to search for attendees.
		if ( isset( $_COOKIE['tix_view_token'] ) && ! empty( $_COOKIE['tix_view_token'] ) ) {
			$view_token = $_COOKIE['tix_view_token'];
			$attendees = get_posts( array(
				'posts_per_page' => 50, // sane?
				'post_type' => 'tix_attendee',
				'post_status' => 'publish',
				'meta_query' => array(
					array(
						'key' => 'tix_view_token',
						'value' => $view_token,
					),
				),
			) );

			// Having attendees is one piece of the puzzle.
			// Making sure they have the right tickets is the other.
			if ( $attendees ) {
				$attendee = $attendees[0];

				// Let's try and recreate the view token and see if it was generated for this user.
				$expected_view_token = $this->generate_view_token_for_attendee( $attendee->ID );
				if ( $expected_view_token != $view_token ) {
					$this->error( 'Looks like you logged in from a different computer. Please log in again.' );
					$error = true;
				}

				/** @todo: maybe cleanup the nested ifs **/
				if ( ! $error ) {
					if ( $args['ticket_ids'] )
						$args['ticket_ids'] = array_map( 'intval', explode( ',', $args['ticket_ids'] ) );
					else
						$can_view_content = true;

					// If at least one ticket is found, break.
					if ( $args['ticket_ids'] ) {
						foreach ( $attendees as $attendee ) {
							if ( in_array( get_post_meta( $attendee->ID, 'tix_ticket_id', true ), $args['ticket_ids'] ) ) {
								$can_view_content = true;
								break;
							}
						}
					}

					if ( ! $can_view_content && isset( $_POST['tix_private_shortcode_submit'] ) ) {
						$this->error( 'Sorry, but your ticket does not allow you to view this content.' );
					}
				}

			} else {
				 if ( isset( $_POST['tix_private_shortcode_submit'] ) )
					$this->error( 'Sorry, but your ticket does not allow you to view this content.' );
			}
		}

		if ( $can_view_content && $attendee ) {
			if ( isset( $_POST['tix_private_shortcode_submit'] ) )
				$this->info( 'Success! Enjoy your content!' );

			return $this->shortcode_private_display_content( $atts, $content );
		} else {
			if ( ! isset( $_POST['tix_private_shortcode_submit'] ) && ! $error )
				$this->notice( 'The content on this page is private. Please log in using the form below.' );

			return $this->shortcode_private_login_form( $atts, $content );
		}
	}

	/**
	 * [camptix_private] shortcode, displays the login form.
	 */
	function shortcode_private_login_form( $atts, $content ) {
		$first_name = isset( $_POST['tix_first_name'] ) ? $_POST['tix_first_name'] : '';
		$last_name = isset( $_POST['tix_last_name'] ) ? $_POST['tix_last_name'] : '';
		$email = isset( $_POST['tix_email'] ) ? $_POST['tix_email'] : '';
		ob_start();
		?>
		<div id="tix">
			<?php do_action( 'camptix_notices' ); ?>
			<form method="POST" action="<?php add_query_arg( null, null ); ?>#tix">
				<input type="hidden" name="tix_private_shortcode_submit" value="1" />
				<input type="hidden" name="tix_post_id" value="<?php the_ID(); ?>" />
				<table class="tix-private-form">
					<tr>
						<td class="tix-left">First Name</td>
						<td class="tix-right"><input name="tix_first_name" value="<?php echo esc_attr( $first_name ); ?>" type="text" /></td>
					</tr>
					<tr>
						<td class="tix-left">Last Name</td>
						<td class="tix-right"><input name="tix_last_name" value="<?php echo esc_attr( $last_name ); ?>" type="text" /></td>
					</tr>
					<tr>
						<td class="tix-left">E-mail</td>
						<td class="tix-right"><input name="tix_email" value="<?php echo esc_attr( $email ); ?>" type="text" /></td>
					</tr>
				</table>
				<p class="tix-submit">
					<input type="submit" value="Login &rarr;">
					<br class="tix-clear">
				</p>
			</form>
		</div>
		<?php
		$content = ob_get_contents();
		ob_end_clean();
		return $content;
	}

	/**
	 * [camptix_private] shortcode, this part displays the actual content in a #tix div
	 * with notices.
	 */
	function shortcode_private_display_content( $atts, $content ) {
		ob_start();
		echo '<div id="tix">';
		do_action( 'camptix_notices' );

		echo $content;

		echo '</div>';
		$content = ob_get_contents();
		ob_end_clean();
		return $content;
	}

	function generate_view_token_for_attendee( $attendee_id ) {
		$first_name = get_post_meta( $attendee_id, 'tix_first_name', true );
		$last_name = get_post_meta( $attendee_id, 'tix_last_name', true );
		$email = get_post_meta( $attendee_id, 'tix_email', true );
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';

		$view_token = md5( 'tix-view-token-' . strtolower( $first_name . $last_name . $email . $ip ) );
		return $view_token;
	}

	function redirect_with_error_flags( $query_args = array() ) {
		$query_args['tix_error'] = 1;
		$query_args['tix_errors'] = array();
		$query_args['tix_error_data'] = array();

		foreach ( $this->error_flags as $key => $value )
			if ( $value ) $query_args['tix_errors'][] = $key;

		foreach ( $this->error_data as $key => $value )
			$query_args['tix_error_data'][$key] = $value;

		$url = esc_url_raw( add_query_arg( $query_args, $this->get_tickets_url() ) . '#tix' );
		wp_safe_redirect( $url );
		die();
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

	protected function notice( $notice ) {
		$this->notices[] = $notice;
	}

	protected function error( $error ) {
		$this->errors[] = $error;
	}

	protected function info( $info ) {
		$this->infos[] = $info;
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
	function admin_notices() {
		do_action( 'camptix_admin_notices' );

		// Signal when archived.
		if ( $this->options['archived'] )
			echo '<div class="updated"><p>CampTix is in <strong>archive mode</strong>. Please do not make any changes.</p></div>';
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
			$headers[] = sprintf( 'From: %s <%s>', $this->options['paypal_statement_subject'], get_option( 'admin_email' ) );

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
			'field-twitter' => $this->get_default_addon_path( 'field-twitter.php' ),
			'field-url'     => $this->get_default_addon_path( 'field-url.php' ),

			/**
			 * The following addons are available but inactive by default. Do not uncomment
			 * but rather filter 'camptix_default_addons', otherwise your changes may be overwritten
			 * during an update to the plugin.
			 */

			// 'logging-meta'  => $this->get_default_addon_path( 'logging-meta.php' ),
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
			trigger_error( 'Please register your CampTix addons before CampTix is initialized.' );
			return false;
		}

		if ( ! class_exists( $classname ) ) {
			trigger_error( 'The CampTix addon you are trying to register does not exist.' );
			return false;
		}

		$this->addons[] = $classname;
	}
}

// Initialize the $camptix global.
$GLOBALS['camptix'] = new CampTix_Plugin;

function camptix_register_addon( $classname ) {
	return $GLOBALS['camptix']->register_addon( $classname );
}

/**
 * If you're writing an addon, make sure you extend from this class.
 */
abstract class CampTix_Addon {
	public function __construct() {
		add_action( 'camptix_init', array( $this, 'camptix_init' ) );
	}
	public function camptix_init() {}
}