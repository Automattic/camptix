<?php

/**
 * Require attendees to login to the website before purchasing tickets.
 */
class CampTix_Require_Login extends CampTix_Addon {
	const UNCONFIRMED_USERNAME = '[[ unconfirmed ]]';

	/**
	 * Register hook callbacks
	 */
	public function camptix_init() {
		add_action( 'template_redirect',                              array( $this, 'block_unauthenticated_actions' ), 7 );    // before CampTix_Plugin->template_redirect()
		add_filter( 'camptix_register_button_classes',                array( $this, 'hide_register_form_elements' ) );
		add_filter( 'camptix_coupon_link_classes',                    array( $this, 'hide_register_form_elements' ) );
		add_filter( 'camptix_quantity_row_classes',                   array( $this, 'hide_register_form_elements' ) );
		add_action( 'camptix_notices',                                array( $this, 'ticket_form_message' ), 8 );
		add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'add_username_to_attendee_object' ), 10, 3 );
		add_action( 'camptix_checkout_update_post_meta',              array( $this, 'save_checkout_username_meta' ), 10, 2 );
		add_filter( 'camptix_attendee_report_column_value_username',  array( $this, 'get_attendee_username_meta' ), 10, 2 );
		add_filter( 'camptix_save_attendee_post_add_search_meta',     array( $this, 'get_attendee_search_meta' ) );
		add_filter( 'camptix_attendee_report_extra_columns',          array( $this, 'get_attendee_report_extra_columns' ) );
		add_filter( 'camptix_metabox_attendee_info_additional_rows',  array( $this, 'get_attendee_metabox_rows' ), 10, 2 );
		add_action( 'camptix_form_edit_attendee_custom_error_flags',  array( $this, 'require_unique_usernames' ) );
		add_action( 'camptix_form_start_errors',                      array( $this, 'add_form_start_error_messages' ) );
		add_action( 'camptix_form_edit_attendee_update_post_meta',    array( $this, 'update_attendee_post_meta' ), 10, 2 );
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
} // CampTix_Require_Login 

camptix_register_addon( 'CampTix_Require_Login' );
