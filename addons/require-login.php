<?php

/**
 * Require attendees to login to the website before purchasing tickets.
 *
 * todo add a detailed explanation of the goals, workflow, etc
 */
class CampTix_Require_Login extends CampTix_Addon {
	const UNCONFIRMED_USERNAME = '[[ unconfirmed ]]';

	/**
	 * Register hook callbacks
	 */
	public function __construct() {
		add_action( 'template_redirect',                              array( $this, 'block_unauthenticated_actions' ), 7 );    // before CampTix_Plugin->template_redirect()

		// Registration Information front-end screen
		add_filter( 'camptix_register_button_classes',                array( $this, 'hide_register_form_elements' ) );
		add_filter( 'camptix_coupon_link_classes',                    array( $this, 'hide_register_form_elements' ) );
		add_filter( 'camptix_quantity_row_classes',                   array( $this, 'hide_register_form_elements' ) );
		add_action( 'camptix_notices',                                array( $this, 'ticket_form_message' ), 8 );
		add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'add_username_to_attendee_object' ), 10, 3 );
		add_action( 'camptix_checkout_update_post_meta',              array( $this, 'save_checkout_username_meta' ), 10, 2 );
		add_filter( 'camptix_email_tickets_template',                 array( $this, 'use_custom_email_templates' ), 10, 2 );

		// wp-admin
		add_filter( 'camptix_attendee_report_column_value_username',  array( $this, 'get_attendee_username_meta' ), 10, 2 );
		add_filter( 'camptix_save_attendee_post_add_search_meta',     array( $this, 'get_attendee_search_meta' ) );
		add_filter( 'camptix_attendee_report_extra_columns',          array( $this, 'get_attendee_report_extra_columns' ) );
		add_filter( 'camptix_metabox_attendee_info_additional_rows',  array( $this, 'get_attendee_metabox_rows' ), 10, 2 );
		add_filter( 'camptix_custom_email_templates',                 array( $this, 'register_custom_email_templates' ) );
		add_filter( 'camptix_default_options',                        array( $this, 'custom_email_template_default_values' ) );

		// Attendee Information front-end screen
		add_action( 'camptix_form_edit_attendee_custom_error_flags',  array( $this, 'require_unique_usernames' ) );
		add_action( 'camptix_form_start_errors',                      array( $this, 'add_form_start_error_messages' ) );
		add_action( 'camptix_form_edit_attendee_update_post_meta',    array( $this, 'update_attendee_post_meta' ), 10, 2 );

		// Misc
		add_filter( 'camptix_attendees_shortcode_query_args',         array( $this, 'hide_unconfirmed_attendees' ) );
	}

	/**
	 * Block all normal CampTix checkout actions if the user is logged out
	 *
	 * If a logged-out user attempts to submit a request for any action other than 'login',
	 * it will be overriden with the 'login' action so that they first have to login.
	 */
	public function block_unauthenticated_actions() {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		if ( ! is_user_logged_in() && isset( $_REQUEST['tix_action'] ) ) {
			wp_safe_redirect( wp_login_url( add_query_arg( $_REQUEST, $camptix->get_tickets_url() ) ) );
			exit();
		}
	}

	/**
	 * Hide the interactive elements of the Tickets registration form if the user isn't logged in.
	 *
	 * @param $classes
	 * @return array
	 */
	public function hide_register_form_elements( $classes ) {
		if ( ! is_user_logged_in() ) {
			$classes[] = 'tix-hidden';
		}

		return $classes;
	}

	/**
	 * Warn users that they will need to login to purchase a ticket
	 */
	public function ticket_form_message() {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		if ( ! is_user_logged_in() ) {
			$camptix->notice( apply_filters( 'camptix_require_login_please_login_message', sprintf(
				__( 'Please <a href="%s">log in</a> or <a href="%s">create an account</a> to purchase your tickets.', 'camptix' ),
				wp_login_url( add_query_arg( $_REQUEST, $camptix->get_tickets_url() ) ),
				wp_registration_url()
			) ) );
		}
	}

	/**
	 * Add the value of the username to the Attendee object used during checkout
	 *
	 * The current logged in user's username will be assigned to the first ticket and the other tickets will have
	 * an empty field because it will be filled in later when each individual confirms their registration.
	 *
	 * @param stdClass $attendee
	 * @param array $attendee_info
	 * @param int $attendee_order The order of the current attendee with respect to other attendees from the same transaction, starting at 1
	 *
	 * @return stdClass
	 */
	public function add_username_to_attendee_object( $attendee, $attendee_info, $attendee_order ) {
		if ( 1 === $attendee_order ) {
			$current_user       = wp_get_current_user();
			$attendee->username = $current_user->user_login;
		} else {
			$attendee->username = self::UNCONFIRMED_USERNAME;
		}

		return $attendee;
	}

	/**
	 * Save the attendee's username in the database.
	 *
	 * @param int $post_id
	 * @param stdClass $attendee
	 */
	public function save_checkout_username_meta( $post_id, $attendee ) {
		update_post_meta( $post_id, 'tix_username', $attendee->username );
	}

	/**
	 * Retrieve the attendee's username from the database.
	 *
	 * @param array $data
	 * @param WP_Post $attendee
	 * @return string
	 */
	public function get_attendee_username_meta( $data, $attendee ) {
		return get_post_meta( $attendee->ID, 'tix_username', true );
	}

	/**
	 * Add the username to the search meta fields
	 *
	 * @param array $attendee_search_meta
	 * @return array
	 */
	public function get_attendee_search_meta( $attendee_search_meta ) {
		$attendee_search_meta[] = 'tix_username';
		
		return $attendee_search_meta;
	}

	/**
	 * Add the username column to the attendee report.
	 *
	 * @param array $extra_columns
	 * @return array
	 */
	public function get_attendee_report_extra_columns( $extra_columns ) {
		$extra_columns['username'] = __( 'Username', 'camptix' );

		return $extra_columns;
	}

	/**
	 * Add the Username row to the Attendee Info metabox.
	 *
	 * @param array $rows
	 * @param WP_Post $post
	 * @return array
	 */
	public function get_attendee_metabox_rows( $rows, $post ) {
		$rows[] = array( __( 'Username', 'camptix' ), esc_html( get_post_meta( $post->ID, 'tix_username', true ) ) );

		return $rows;
	}

	public function register_custom_email_templates( $templates ) {
		$templates['email_template_multiple_purchase_receipt_unconfirmed_attendees'] = array(
			'title'           => __( 'Multiple Purchase (receipt with unconfirmed attendees)', 'camptix' ),
			'callback_method' => 'field_textarea',
		);

		$templates['email_template_multiple_purchase_unconfirmed_attendee'] = array(
			'title'           => __( 'Multiple Purchase (to unconfirmed attendees)', 'camptix' ),
			'callback_method' => 'field_textarea',
		);

		return $templates;
	}

	/**
	 * Set the default custom e-mail template content.
	 *
	 * @param array $options
	 *
	 * @return array
	 */
	public function custom_email_template_default_values( $options ) {
		$options['email_template_multiple_purchase_receipt_unconfirmed_attendees'] = __( "Hi there!\n\nYou have purchased the following tickets:\n\n[receipt]\n\nYou can view and edit your order at any time before the event, by visiting the following link:\n\n[ticket_url]\n\nThe other attendees that you purchased tickets for will need to confirm their registration by visiting a link that was sent to them by e-mail.\n\nLet us know if you have any questions!", 'camptix' );
		$options['email_template_multiple_purchase_unconfirmed_attendee']          = __( "Hi there!\n\nA ticket to [event_name] has been purchased for you.\n\nTo complete your registration, please confirm your ticket by visiting the following page:\n\n[ticket_url]\n\nLet us know if you have any questions!", 'camptix' );

		return $options;
	}

	/**
	 * Send custom e-mail templates to the purchaser and to unconfirmed attendees.
	 *
	 * @param string $template
	 * @param WP_Post $attendee
	 *
	 * @return string
	 */
	public function use_custom_email_templates( $template, $attendee ) {
		switch ( $template ) {
			case 'email_template_multiple_purchase_receipt':
				$template = 'email_template_multiple_purchase_receipt_unconfirmed_attendees';
				break;

			case 'email_template_multiple_purchase':
				if ( self::UNCONFIRMED_USERNAME == get_post_meta( $attendee->ID, 'tix_username', true ) ) {
					$template = 'email_template_multiple_purchase_unconfirmed_attendee';
				}
				break;
		}

		return $template;
	}

	/**
	 * Ensure that each attendee is mapped to only one username.
	 *
	 * This prevents the buyer of a group of tickets from completing registration for the other attendees.
	 *
	 * @param WP_Post $attendee
	 */
	public function require_unique_usernames( $attendee ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		$current_user = wp_get_current_user();
		$confirmed_usernames = $this->get_confirmed_usernames(
			get_post_meta( $attendee->ID, 'tix_ticket_id', true ),
			get_post_meta( $attendee->ID, 'tix_payment_token', true )
		);

		if ( in_array( $current_user->user_login, $confirmed_usernames ) ) {
			$camptix->error_flag( 'require_login_edit_attendee_duplicate_username' );
			$camptix->redirect_with_error_flags();
		}
	}

	/**
	 * Get all of the usernames of confirmed attendees from group of tickets that was purchased together.
	 *
	 * @param int $ticket_id
	 * @param string $payment_token
	 *
	 * @return array
	 */
	protected function get_confirmed_usernames( $ticket_id, $payment_token ) {
		$usernames = array();

		$other_attendees = get_posts( array(
			'posts_per_page' => -1,
			'post_type'      => 'tix_attendee',
			'post_status'    => array( 'pending', 'publish' ),

			'meta_query'   => array(
				'relation' => 'AND',

				array(
					'key'   => 'tix_ticket_id',
					'value' => $ticket_id,
				),

				array(
					'key'   => 'tix_payment_token',
					'value' => $payment_token,
				)
			)
		) );

		foreach ( $other_attendees as $attendee ) {
			$username = get_post_meta( $attendee->ID, 'tix_username', true );

			if ( ! empty( $username ) && self::UNCONFIRMED_USERNAME != $username ) {
				$usernames[] = $username;
			}
		}

		return $usernames;
	}

	/**
	 * Define the error messages that correspond to our custom error codes.
	 *
	 * @param array $errors
	 */
	public function add_form_start_error_messages( $errors ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		if ( isset( $errors['require_login_edit_attendee_duplicate_username'] ) ) {
			$camptix->error( __( "You cannot edit the requested attendee's information because your user account has already been assigned to another ticket. Please ask the person using this ticket to sign in with their own account and fill out their information.", 'camptix' ) );
		}
	}

	/**
	 * Update the username when saving an Attendee post.
	 *
	 * This fires when a user is editing their individual information, so the current user
	 * should be the person that the ticket was purchased for.
	 *
	 * @param array $new_ticket_info
	 * @param WP_Post $attendee
	 */
	public function update_attendee_post_meta( $new_ticket_info, $attendee ) {
		$current_user = wp_get_current_user();
		update_post_meta( $attendee->ID, 'tix_username', $current_user->user_login );
	}

	/**
	 * Remove unconfirmed attendees from the [attendees] shortcode output.
	 *
	 * @param array $query_args
	 *
	 * @return array
	 */
	public function hide_unconfirmed_attendees( $query_args ) {
		$meta_query = array(
			'key'     => 'tix_username',
			'value'   => self::UNCONFIRMED_USERNAME,
			'compare' => '!='
		);

		if ( isset( $query_args['meta_query'] ) ) {
			$query_args['meta_query'][] = $meta_query;
		} else {
			$query_args['meta_query'] = array( $meta_query );
		}

		return $query_args;
	}
} // CampTix_Require_Login 

camptix_register_addon( 'CampTix_Require_Login' );
