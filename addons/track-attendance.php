<?php
/**
 * Allows event organizers to track which attendees showed up to the event.
 */

class CampTix_Track_Attendance extends CampTix_Addon {
	/**
	 * Init
	 */
	public function camptix_init() {
		add_action( 'camptix_attendee_submitdiv_misc',               array( $this, 'render_attendance_checkbox' ) );
		add_action( 'save_post',                                     array( $this, 'save_attendance_data' ), 10, 2 );
		add_filter( 'camptix_summary_fields',                        array( $this, 'add_summary_field' ) );
		add_action( 'camptix_summarize_by_attendance',               array( $this, 'summarize_by_attendance' ), 10, 2 );
		add_filter( 'camptix_attendee_report_extra_columns',         array( $this, 'add_extra_report_columns' ) );
		add_filter( 'camptix_attendee_report_column_value_attended', array( $this, 'add_report_value_attended' ), 10, 2 );
	}

	/**
	 * Render the 'Attended the event' checkbox on the Attendee post.
	 *
	 * @param WP_Post $attendee
	 */
	public function render_attendance_checkbox( $attendee ) {
		?>

		<p>
			<input id="tix_attended_<?php esc_attr( $attendee->ID ); ?>" name="tix_attended" type="checkbox" <?php checked( get_post_meta( $attendee->ID, 'tix_attended', true ) ); ?> />
			<label for="tix_attended_<?php esc_attr( $attendee->ID ); ?>"><?php _e( 'Attended the event', 'camptix' ); ?></label>
		</p>

		<?php
	}

	/**
	 * Save the value of the 'Attended the event' checkbox on the Attendee post.
	 *
	 * @param int     $attendee_id
	 * @param WP_Post $attendee
	 */
	public function save_attendance_data( $attendee_id, $attendee ) {
		if ( wp_is_post_revision( $attendee_id ) || 'tix_attendee' != get_post_type( $attendee_id ) ) {
			return;
		}

		if ( isset( $_POST['tix_attended'] ) && 'on' == $_POST['tix_attended'] ) {
			update_post_meta( $attendee_id, 'tix_attended', true );
		} else {
			delete_post_meta( $attendee_id, 'tix_attended' );
		}
	}

	/**
	 * Add the 'Attended the event' field to the Summarize dropdown.
	 *
	 * @param array $fields
	 * @return array
	 */
	public function add_summary_field( $fields ) {
		$fields['attendance'] = __( 'Attended the event', 'camptix' );

		return $fields;
	}

	/**
	 * Count the number of ticket holders who attended the event.
	 *
	 * @param array   $summary
	 * @param WP_Post $attendee
	 */
	public function summarize_by_attendance( &$summary, $attendee ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		if ( get_post_meta( $attendee->ID, 'tix_attended', true ) ) {
			$camptix->increment_summary( $summary, __( 'Attended', 'camptix' ) );
		}
	}

	/**
	 * Add the 'Attended the event' column to the attendee export
	 *
	 * @param array $extra_columns
	 * @return array
	 */
	public function add_extra_report_columns( $extra_columns ) {
		$extra_columns['attended'] = __( 'Attended the event', 'camptix' );

		return $extra_columns;
	}

	/**
	 * Set the value for the 'Attended the Event' column for the given attendee in the attendee export
	 *
	 * @param string $value
	 * @param WP_Post $attendee
	 * @return string
	 */
	public function add_report_value_attended( $value, $attendee ) {
		return get_post_meta( $attendee->ID, 'tix_attended', true ) ? 'Yes' : 'No';
	}
}

// Register this class as a CampTix Addon.
camptix_register_addon( 'CampTix_Track_Attendance' );
