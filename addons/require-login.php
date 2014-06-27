<?php

/**
 * Require attendees to login to the website before purchasing tickets.
 */
class CampTix_Require_Login extends CampTix_Addon {

	/**
	 * Register hook callbacks
	 */
	public function camptix_init() {
		add_action( 'template_redirect',                              array( $this, 'block_unauthenticated_actions' ), 7 );    // before CampTix_Plugin->template_redirect()
		add_action( 'camptix_notices',                                array( $this, 'ticket_form_message' ), 8 );
		add_action( 'camptix_attendee_form_additional_info',          array( $this, 'render_attendee_form_username_row' ), 10, 3 );
		add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'add_username_to_attendee_object' ), 10, 2 );
		add_action( 'camptix_checkout_update_post_meta',              array( $this, 'save_checkout_username_meta' ), 10, 2 );
		add_filter( 'camptix_attendee_report_column_value_username',  array( $this, 'get_attendee_username_meta' ), 10, 2 );
		add_filter( 'camptix_save_attendee_post_add_search_meta',     array( $this, 'get_attendee_search_meta' ) );
		add_filter( 'camptix_attendee_report_extra_columns',          array( $this, 'get_attendee_report_extra_columns' ) );
		add_filter( 'camptix_metabox_attendee_info_additional_rows',  array( $this, 'get_attendee_metabox_rows' ), 10, 2 );
		add_action( 'camptix_form_edit_attendee_update_post_meta',    array( $this, 'update_attendee_post_meta' ), 10, 2 );
		add_action( 'camptix_form_edit_attendee_additional_info',     array( $this, 'render_edit_attendee_username_row' ) );
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
	 * Warn users that they will need to login to purchase a ticket
	 */
	public function ticket_form_message() {
		/** @var $camptix CampTix_Plugin */
		global $camptix;
		
		if ( ! is_user_logged_in() ) {
			$camptix->notice( sprintf(
				__( 'Please <a href="%s">login</a> to purchase your tickets.', 'camptix' ),
				wp_login_url( add_query_arg( $_REQUEST, $camptix->get_tickets_url() ) )
			) );
		}
	}

	/**
	 * Render the table row in the Registration form for collecting usernames.
	 *
	 * @param array $form_data
	 * @param int $current_iteration
	 * @param int $total_count
	 */
	public function render_attendee_form_username_row( $form_data, $current_iteration, $total_count ) {
		if ( isset( $form_data['tix_attendee_info'][ $current_iteration ]['username'] ) ) {
			$username = $form_data['tix_attendee_info'][ $current_iteration ]['username'];
		} else if ( 1 == $current_iteration ) {
			$current_user = wp_get_current_user();
			$username     = $current_user->user_login;
		} else {
			$username = '';
		}
		
		?>
		
		<tr class="tix-row-username">
			<td class="tix-required tix-left">
				<label for="tix_attendee_info_<?php echo esc_attr( $current_iteration ); ?>_username">
					<?php echo esc_html( apply_filters( 'camptix_require_login_username_label', __( 'Username', 'camptix' ) ) ); ?>
				</label>
				<span class="tix-required-star">*</span>
			</td>
			
			<td class="tix-right">
				<input id="tix_attendee_info_<?php echo esc_attr( $current_iteration ); ?>_username" name="tix_attendee_info[<?php echo $current_iteration; ?>][username]" type="text" value="<?php echo esc_attr( $username ); ?>" />
			</td>
		</tr>
		
		<?php
	}

	/**
	 * Add the value of the username to the Attendee object used during checkout
	 *
	 * @param object $attendee
	 * @param array $attendee_info
	 * @return object
	 */
	public function add_username_to_attendee_object( $attendee, $attendee_info ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;
		$attendee->username = sanitize_text_field( $attendee_info['username'] );

		if ( ! username_exists( $attendee->username ) ) {
			$camptix->error_flag( 'require_login' );    // CampTix_Plugin doesn't have an error message for this, but will still redirect the user back to the form
			$camptix->error( apply_filters( 'camptix_require_login_invalid_username_error', __( 'Please enter a valid username.', 'camptix' ) ) );
		}

		return $attendee;
	}

	/**
	 * Save the attendee's username in the database.
	 *
	 * @param int $post_id
	 * @param object $attendee
	 */
	public function save_checkout_username_meta( $post_id, $attendee ) {
		update_post_meta( $post_id, 'tix_username', $attendee->username );
	}

	/**
	 * Retrieve the attendee's username from the database.
	 *
	 * @param $data
	 * @param $attendee
	 * @return mixed
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
	 * Update the username when saving an Attendee post.
	 * 
	 * @param array $new_ticket_info
	 * @param WP_Post $attendee
	 */
	public function update_attendee_post_meta( $new_ticket_info, $attendee ) {
		update_post_meta( $attendee->ID, 'tix_username', sanitize_text_field( $new_ticket_info['username'] ) );
	}

	/**
	 * Render the Username row on the Attendee Information edit form
	 *
	 * @param $attendee
	 */
	public function render_edit_attendee_username_row( $attendee ) {
		?>
		
		<tr>
			<td class="tix-required tix-left">
				<label for="tix_ticket_info_<?php echo esc_attr( $attendee->ID ); ?>_username">
					<?php _e( 'Username', 'camptix' ); ?>
				</label>
				<span class="tix-required-star">*</span>
			</td>

			<td class="tix-right">
				<input id="tix_ticket_info_<?php echo esc_attr( $attendee->ID ); ?>_username" name="tix_ticket_info[username]" type="text" value="<?php echo esc_attr( get_post_meta( $attendee->ID, 'tix_username', true ) ); ?>" />
			</td>
		</tr>
		
		<?php
	}
} // CampTix_Require_Login 

camptix_register_addon( 'CampTix_Require_Login' );
