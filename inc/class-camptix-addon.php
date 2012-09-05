<?php
/**
 * If you're writing an addon, make sure you extend from this class.
 *
 * @since 1.1
 */
abstract class CampTix_Addon {
	public function __construct() {
		add_action( 'camptix_init', array( $this, 'camptix_init' ) );
	}
	public function camptix_init() {}
}

function camptix_register_addon( $classname ) {
	return $GLOBALS['camptix']->register_addon( $classname );
}