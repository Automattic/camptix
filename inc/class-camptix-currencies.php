<?php

class CampTix_Currency {

	public $version = 20180627;

	/**
	 * TODO: Take a decision on the format of currency, if we want to show localized format or a standard format, and
	 * then sort the currencies in alphabetical order so that they are easy to find.
	*/
	/**
	 * @return array List of currencies with their labels. We are keeping combination
	 * of all currencies supported by union of payment gateways to have a single source of truth.
	 */
	private static function get_currency_list() {
		return array(
			'AED' => array(
				'label'  => __( 'United Arab Emirates dirham', 'camptix' ),
				'format' => '%s AED',
			),
			'AFN' => array(
				'label'  => __( 'Afghan Afghani', 'camptix' ),
				'format' => 'AFN %s',
			),
			'ALL' => array(
				'label'  => __( 'Albanian Lek', 'camptix' ),
				'format' => 'L %s',
			),
			'AMD' => array(
				'label'  => __( 'Armenian Dram', 'camptix' ),
				'format' => 'AMD %s',
			),
			'ANG' => array(
				'label'  => __( 'Netherlands Antillean Guilder', 'camptix' ),
				'format' => 'ANG %s',
			),
			'AOA' => array(
				'label'  => __( 'Angolan Kwanza', 'camptix' ),
				'format' => 'Kz %s',
			),
			'AUD' => array(
				'label'  => __( 'Australian Dollar', 'camptix' ),
				'locale' => 'en_AU.UTF-8',
			),
			'CAD' => array(
				'label'  => __( 'Canadian Dollar', 'camptix' ),
				'locale' => 'en_CA.UTF-8',
			),
			'EUR' => array(
				'label'  => __( 'Euro', 'camptix' ),
				'format' => '€ %s',
			),
			'GBP' => array(
				'label'  => __( 'Pound Sterling', 'camptix' ),
				'locale' => 'en_GB.UTF-8',
			),
			'JPY' => array(
				'label'  => __( 'Japanese Yen', 'camptix' ),
				'locale' => 'ja_JP.UTF-8',
			),
			'USD' => array(
				'label'  => __( 'U.S. Dollar', 'camptix' ),
				'locale' => 'en_US.UTF-8',
			),
			'NZD' => array(
				'label'  => __( 'N.Z. Dollar', 'camptix' ),
				'locale' => 'en_NZ.UTF-8',
			),
			'CHF' => array(
				'label'  => __( 'Swiss Franc', 'camptix' ),
				'locale' => 'fr_CH.UTF-8',
			),
			'HKD' => array(
				'label'  => __( 'Hong Kong Dollar', 'camptix' ),
				'locale' => 'zh_HK.UTF-8',
			),
			'SGD' => array(
				'label'  => __( 'Singapore Dollar', 'camptix' ),
				'format' => '$ %s',
			),
			'SEK' => array(
				'label'  => __( 'Swedish Krona', 'camptix' ),
				'locale' => 'sv_SE.UTF-8',
			),
			'DKK' => array(
				'label'  => __( 'Danish Krone', 'camptix' ),
				'locale' => 'da_DK.UTF-8',
			),
			'PLN' => array(
				'label'  => __( 'Polish Zloty', 'camptix' ),
				'locale' => 'pl_PL.UTF-8',
			),
			'NOK' => array(
				'label'  => __( 'Norwegian Krone', 'camptix' ),
				'locale' => 'no_NO.UTF-8',
			),
			'HUF' => array(
				'label'  => __( 'Hungarian Forint', 'camptix' ),
				'locale' => 'hu_HU.UTF-8',
			),
			'CZK' => array(
				'label'  => __( 'Czech Koruna', 'camptix' ),
				'locale' => 'hcs_CZ.UTF-8',
			),
			'ILS' => array(
				'label'  => __( 'Israeli New Sheqel', 'camptix' ),
				'locale' => 'he_IL.UTF-8',
			),
			'MXN' => array(
				'label'  => __( 'Mexican Peso', 'camptix' ),
				'format' => '$ %s',
			),
			'BRL' => array(
				'label'  => __( 'Brazilian Real', 'camptix' ),
				'locale' => 'pt_BR.UTF-8',
			),
			'MYR' => array(
				'label'  => __( 'Malaysian Ringgit', 'camptix' ),
				'format' => 'RM %s',
			),
			'PHP' => array(
				'label'  => __( 'Philippine Peso', 'camptix' ),
				'format' => '₱ %s',
			),
			'PKR' => array(
				'label'  => __( 'Pakistani Rupee', 'camptix' ),
				'format' => '₨ %s',
			),
			'TWD' => array(
				'label'  => __( 'New Taiwan Dollar', 'camptix' ),
				'locale' => 'zh_TW.UTF-8',
			),
			'THB' => array(
				'label'  => __( 'Thai Baht', 'camptix' ),
				'format' => '฿ %s',
			),
			'TRY' => array(
				'label'  => __( 'Turkish Lira', 'camptix' ),
				'locale' => 'tr_TR.UTF-8',
			),
			'ZAR' => array(
				'label'  => __( 'South African Rand', 'camptix' ),
				'format' => 'R %s',
			),
			'DZD' => array(
				'label'  => __( 'Algerian Dinar', 'camptix' ),
				'format' => 'DZD %s',
			),
			'XCD' => array(
				'label'  => __( 'East Caribbean Dollar', 'camptix' ),
				'format' => 'XCD %s',
			),
			'ARS' => array(
				'label'  => __( 'Argentine Peso', 'camptix' ),
				'format' => 'ARS %s',
			),
			'AWG' => array(
				'label'  => __( 'Aruban Florin', 'camptix' ),
				'format' => 'AWG %s',
			),
			'AZN' => array(
				'label'  => __( 'Azerbaijan Manat', 'camptix' ),
				'format' => 'AZN %s',
			),
			'BSD' => array(
				'label'  => __( 'Bahamian Dollar', 'camptix' ),
				'format' => 'BSD %s',
			),
			'BDT' => array(
				'label'  => __( 'Taka', 'camptix' ),
				'format' => 'BDT %s',
			),
			'BBD' => array(
				'label'  => __( 'Barbados Dollar', 'camptix' ),
				'format' => 'BBD %s',
			),
			'BZD' => array(
				'label'  => __( 'Belize Dollar', 'camptix' ),
				'format' => 'BZD %s',
			),
			'XOF' => array(
				'label'  => __( 'CFA Franc BCEAO', 'camptix' ),
				'format' => 'XOF %s',
			),
			'BMD' => array(
				'label'  => __( 'Bermudian Dollar', 'camptix' ),
				'format' => 'BMD %s',
			),
			'INR' => array(
				'label'  => __( 'Indian Rupee', 'camptix' ),
				'format' => 'INR %s',
			),
			'BOB' => array(
				'label'  => __( 'Boliviano', 'camptix' ),
				'format' => 'BOB %s',
			),
			'BAM' => array(
				'label'  => __( 'Convertible Mark', 'camptix' ),
				'format' => 'BAM %s',
			),
			'BWP' => array(
				'label'  => __( 'Pula', 'camptix' ),
				'format' => 'BWP %s',
			),
			'BND' => array(
				'label'  => __( 'Brunei Dollar', 'camptix' ),
				'format' => 'BND %s',
			),
			'BGN' => array(
				'label'  => __( 'Bulgarian Lev', 'camptix' ),
				'format' => 'BGN %s',
			),
			'BIF' => array(
				'label'  => __( 'Burundi Franc', 'camptix' ),
				'format' => 'BIF %s',
			),
			'CVE' => array(
				'label'  => __( 'Cabo Verde Escudo', 'camptix' ),
				'format' => 'CVE %s',
			),
			'KHR' => array(
				'label'  => __( 'Riel', 'camptix' ),
				'format' => 'KHR %s',
			),
			'XAF' => array(
				'label'  => __( 'CFA Franc BEAC', 'camptix' ),
				'format' => 'XAF %s',
			),
			'KYD' => array(
				'label'  => __( 'Cayman Islands Dollar', 'camptix' ),
				'format' => 'KYD %s',
			),
			'CLP' => array(
				'label'  => __( 'Chilean Peso', 'camptix' ),
				'format' => 'CLP %s',
			),
			'CNY' => array(
				'label'  => __( 'Yuan Renminbi', 'camptix' ),
				'format' => 'CNY %s',
			),
			'COP' => array(
				'label'  => __( 'Colombian Peso', 'camptix' ),
				'format' => 'COP %s',
			),
			'KMF' => array(
				'label'  => __( 'Comorian Franc ', 'camptix' ),
				'format' => 'KMF %s',
			),
			'CDF' => array(
				'label'  => __( 'Congolese Franc', 'camptix' ),
				'format' => 'CDF %s',
			),
			'CRC' => array(
				'label'  => __( 'Costa Rican Colon', 'camptix' ),
				'format' => 'CRC %s',
			),
			'HRK' => array(
				'label'  => __( 'Kuna', 'camptix' ),
				'format' => 'HRK %s',
			),
			'DJF' => array(
				'label'  => __( 'Djibouti Franc', 'camptix' ),
				'format' => 'DJF %s',
			),
			'DOP' => array(
				'label'  => __( 'Dominican Peso', 'camptix' ),
				'format' => 'DOP %s',
			),
			'EGP' => array(
				'label'  => __( 'Egyptian Pound', 'camptix' ),
				'format' => 'EGP %s',
			),
			'ETB' => array(
				'label'  => __( 'Ethiopian Birr', 'camptix' ),
				'format' => 'ETB %s',
			),
			'FKP' => array(
				'label'  => __( 'Falkland Islands Pound', 'camptix' ),
				'format' => 'FKP %s',
			),
			'FJD' => array(
				'label'  => __( 'Fiji Dollar', 'camptix' ),
				'format' => 'FJD %s',
			),
			'XPF' => array(
				'label'  => __( 'CFP Franc', 'camptix' ),
				'format' => 'XPF %s',
			),
			'GMD' => array(
				'label'  => __( 'Dalasi', 'camptix' ),
				'format' => 'GMD %s',
			),
			'GEL' => array(
				'label'  => __( 'Lari', 'camptix' ),
				'format' => 'GEL %s',
			),
			'GIP' => array(
				'label'  => __( 'Gibraltar Pound', 'camptix' ),
				'format' => 'GIP %s',
			),
			'GTQ' => array(
				'label'  => __( 'Quetzal', 'camptix' ),
				'format' => 'GTQ %s',
			),
			'GNF' => array(
				'label'  => __( 'Guinean Franc', 'camptix' ),
				'format' => 'GNF %s',
			),
			'GYD' => array(
				'label'  => __( 'Guyana Dollar', 'camptix' ),
				'format' => 'GYD %s',
			),
			'HTG' => array(
				'label'  => __( 'Gourde', 'camptix' ),
				'format' => 'HTG %s',
			),
			'HNL' => array(
				'label'  => __( 'Lempira', 'camptix' ),
				'format' => 'HNL %s',
			),
			'ISK' => array(
				'label'  => __( 'Iceland Krona', 'camptix' ),
				'format' => 'ISK %s',
			),
			'IDR' => array(
				'label'  => __( 'Rupiah', 'camptix' ),
				'format' => 'IDR %s',
			),
			'JMD' => array(
				'label'  => __( 'Jamaican Dollar', 'camptix' ),
				'format' => 'JMD %s',
			),
			'KZT' => array(
				'label'  => __( 'Tenge', 'camptix' ),
				'format' => 'KZT %s',
			),
			'KES' => array(
				'label'  => __( 'Kenyan Shilling', 'camptix' ),
				'format' => 'KES %s',
			),
			'KRW' => array(
				'label'  => __( 'Won', 'camptix' ),
				'format' => 'KRW %s',
			),
			'KGS' => array(
				'label'  => __( 'Som', 'camptix' ),
				'format' => 'KGS %s',
			),
			'LAK' => array(
				'label'  => __( 'Lao Kip', 'camptix' ),
				'format' => 'LAK %s',
			),
			'LBP' => array(
				'label'  => __( 'Lebanese Pound', 'camptix' ),
				'format' => 'LBP %s',
			),
			'LSL' => array(
				'label'  => __( 'Loti', 'camptix' ),
				'format' => 'LSL %s',
			),
			'LRD' => array(
				'label'  => __( 'Liberian Dollar', 'camptix' ),
				'format' => 'LRD %s',
			),
			'MOP' => array(
				'label'  => __( 'Pataca', 'camptix' ),
				'format' => 'MOP %s',
			),
			'MKD' => array(
				'label'  => __( 'Denar', 'camptix' ),
				'format' => 'MKD %s',
			),
			'MGA' => array(
				'label'  => __( 'Malagasy Ariary', 'camptix' ),
				'format' => 'MGA %s',
			),
			'MWK' => array(
				'label'  => __( 'Malawi Kwacha', 'camptix' ),
				'format' => 'MWK %s',
			),
			'MVR' => array(
				'label'  => __( 'Rufiyaa', 'camptix' ),
				'format' => 'MVR %s',
			),
			'MUR' => array(
				'label'  => __( 'Mauritius Rupee', 'camptix' ),
				'format' => 'MUR %s',
			),
			'MDL' => array(
				'label'  => __( 'Moldovan Leu', 'camptix' ),
				'format' => 'MDL %s',
			),
			'MNT' => array(
				'label'  => __( 'Tugrik', 'camptix' ),
				'format' => 'MNT %s',
			),
			'MAD' => array(
				'label'  => __( 'Moroccan Dirham', 'camptix' ),
				'format' => 'MAD %s',
			),
			'MZN' => array(
				'label'  => __( 'Mozambique Metical', 'camptix' ),
				'format' => 'MZN %s',
			),
			'MMK' => array(
				'label'  => __( 'Kyat', 'camptix' ),
				'format' => 'MMK %s',
			),
			'NAD' => array(
				'label'  => __( 'Namibia Dollar', 'camptix' ),
				'format' => 'NAD %s',
			),
			'NPR' => array(
				'label'  => __( 'Nepalese Rupee', 'camptix' ),
				'format' => 'NPR %s',
			),
			'NIO' => array(
				'label'  => __( 'Cordoba Oro', 'camptix' ),
				'format' => 'NIO %s',
			),
			'NGN' => array(
				'label'  => __( 'Naira', 'camptix' ),
				'format' => 'NGN %s',
			),
			'PAB' => array(
				'label'  => __( 'Balboa', 'camptix' ),
				'format' => 'PAB %s',
			),
			'PGK' => array(
				'label'  => __( 'Kina', 'camptix' ),
				'format' => 'PGK %s',
			),
			'PYG' => array(
				'label'  => __( 'Guarani', 'camptix' ),
				'format' => 'PYG %s',
			),
			'PEN' => array(
				'label'  => __( 'Sol', 'camptix' ),
				'format' => 'PEN %s',
			),
			'QAR' => array(
				'label'  => __( 'Qatari Rial', 'camptix' ),
				'format' => 'QAR %s',
			),
			'RON' => array(
				'label'  => __( 'Romanian Leu', 'camptix' ),
				'format' => 'RON %s',
			),
			'RUB' => array(
				'label'  => __( 'Russian Ruble', 'camptix' ),
				'format' => 'RUB %s',
			),
			'RWF' => array(
				'label'  => __( 'Rwanda Franc', 'camptix' ),
				'format' => 'RWF %s',
			),
			'SHP' => array(
				'label'  => __( 'Saint Helena Pound', 'camptix' ),
				'format' => 'SHP %s',
			),
			'WST' => array(
				'label'  => __( 'Tala', 'camptix' ),
				'format' => 'WST %s',
			),
			'SAR' => array(
				'label'  => __( 'Saudi Riyal', 'camptix' ),
				'format' => 'SAR %s',
			),
			'RSD' => array(
				'label'  => __( 'Serbian Dinar', 'camptix' ),
				'format' => 'RSD %s',
			),
			'SCR' => array(
				'label'  => __( 'Seychelles Rupee', 'camptix' ),
				'format' => 'SCR %s',
			),
			'SLL' => array(
				'label'  => __( 'Leone', 'camptix' ),
				'format' => 'SLL %s',
			),
			'SBD' => array(
				'label'  => __( 'Solomon Islands Dollar', 'camptix' ),
				'format' => 'SBD %s',
			),
			'SOS' => array(
				'label'  => __( 'Somali Shilling', 'camptix' ),
				'format' => 'SOS %s',
			),
			'LKR' => array(
				'label'  => __( 'Sri Lanka Rupee', 'camptix' ),
				'format' => 'LKR %s',
			),
			'SRD' => array(
				'label'  => __( 'Surinam Dollar', 'camptix' ),
				'format' => 'SRD %s',
			),
			'SZL' => array(
				'label'  => __( 'Lilangeni', 'camptix' ),
				'format' => 'SZL %s',
			),
			'TJS' => array(
				'label'  => __( 'Somoni', 'camptix' ),
				'format' => 'TJS %s',
			),
			'TZS' => array(
				'label'  => __( 'Tanzanian Shilling', 'camptix' ),
				'format' => 'TZS %s',
			),
			'TOP' => array(
				'label'  => __( 'Pa’anga', 'camptix' ),
				'format' => 'TOP %s',
			),
			'TTD' => array(
				'label'  => __( 'Trinidad and Tobago Dollar', 'camptix' ),
				'format' => 'TTD %s',
			),
			'UGX' => array(
				'label'  => __( 'Uganda Shilling', 'camptix' ),
				'format' => 'UGX %s',
			),
			'UAH' => array(
				'label'  => __( 'Hryvnia', 'camptix' ),
				'format' => 'UAH %s',
			),
			'UYU' => array(
				'label'  => __( 'Peso Uruguayo', 'camptix' ),
				'format' => 'UYU %s',
			),
			'UZS' => array(
				'label'  => __( 'Uzbekistan Sum', 'camptix' ),
				'format' => 'UZS %s',
			),
			'VUV' => array(
				'label'  => __( 'Vatu', 'camptix' ),
				'format' => 'VUV %s',
			),
			'VND' => array(
				'label'  => __( 'Dong', 'camptix' ),
				'format' => 'VND %s',
			),
			'YER' => array(
				'label'  => __( 'Yemeni Rial', 'camptix' ),
				'format' => 'YER %s',
			),
			'ZMW' => array(
				'label'  => __( 'Zambian Kwacha', 'camptix' ),
				'format' => 'ZMW %s',
			),
			'MRO' => array(
				'label'  => __( 'Mauritanian Ouguiya', 'camptix' ),
				'format' => 'MRO %s',
			),
		);
	}

	/**
	 * Supported currencies are added via filter by different payment gateway addons/plugins
	 * Addons should have `$supported_currencies` variable defined with list of currencies that they support in
	 * ISO format
	 *
	 * @return array currency code of all supported currencies.
	 */
	private static function get_supported_currency_list() {
		$supported_currencies = array();
		return apply_filters( 'camptix_supported_currencies', $supported_currencies );
	}

	/**
	 * @return array list of currencies with their labels, which are currently supported.
	 */
	public static function get_currencies() {
		// from https://stackoverflow.com/a/4260168/1845153
		$supported_currency_labels = array_intersect_key(
			self::get_currency_list(),
			array_flip( self::get_supported_currency_list() )
		);
		$currencies = apply_filters( 'camptix_currencies', $supported_currency_labels );
		return $currencies;
	}
}