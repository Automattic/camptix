<?php
/**
 * T-shirt Field Addon for CampTix
 */
class CampTix_Addon_Tshirt_Field extends CampTix_Addon {

	/**
	 * Runs during camptix_init, @see CampTix_Addon
	 */
	function camptix_init() {
		global $camptix;

		// Enable on testing only for now.
		if ( $_SERVER['HTTP_HOST'] != 'testing.wordcamp.org' )
			return;

		add_filter( 'camptix_question_field_types', array( $this, 'question_field_types' ) );
		add_action( 'camptix_question_field_tshirt', array( $this, 'question_field_tshirt' ), 10, 3 );
	}

	function question_field_types( $types ) {
		return array_merge( $types, array(
			'tshirt' => 'T-Shirt Size (public)',
		) );
	}

	function question_field_tshirt( $name, $value, $question ) {
		$values = get_post_meta( $question->ID, 'tix_values', true );
		?>
		<select name="<?php echo esc_attr( $name ); ?>" />
			<?php foreach ( (array) $values as $question_value ) : ?>
				<option <?php selected( $question_value, $value ); ?> value="<?php echo esc_attr( $question_value ); ?>"><?php echo esc_html( $question_value ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}

// Register this class as a CampTix Addon.
camptix_register_addon( 'CampTix_Addon_Tshirt_Field' );