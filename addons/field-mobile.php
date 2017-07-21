<?php
/**
 * Mobile Field Addon for CampTix
 *
 * A few of the payment platforms require the mobile/phone field to complete the 
 * transaction. By adding it as a field, the payment class can explicitly call this 
 * field type to pass the variable to the payment platform.
 *
 */
class CampTix_Addon_Mobile_Field extends CampTix_Addon {

	/**
	 * Register hook callbacks
	 */
	function camptix_init() {
		add_filter( 'camptix_question_field_types',  array( $this, 'question_field_types'  ) );
		add_action( 'camptix_question_field_mobile', array( $this, 'question_field_mobile' ), 10, 3 );
	}

	/**
	 * Add Country to the list of question types.
	 *
	 * @param array $types
	 *
	 * @return array
	 */
	function question_field_types( $types ) {
		return array_merge( $types, array(
			'mobile' => 'Mobile',
		) );
	}

	/**
	 * A url input for a question.
	 */
	function question_field_mobile( $name, $value ) {
		?>
		<input name="<?php echo esc_attr( $name ); ?>" type="tel" value="<?php echo esc_attr( $value ); ?>" />
		<?php
	}
}

// Register this class as a CampTix Addon.
camptix_register_addon( 'CampTix_Addon_Mobile_Field' );
