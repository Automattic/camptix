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
			'AED'=> array(
				'label' => __( 'United Arab Emirates dirham', 'camptix' ),
				'format' => '%s AED',
			),
			'AFN'=> array(
				'label' => __( 'Afghan Afghani', 'camptix' ),
				'format' => 'AFN %s',
			),
			'ALL'=> array(
				'label' => __( 'Albanian Lek', 'camptix' ),
				'format' => 'L %s',
			),
			'AMD'=> array(
				'label' => __( 'Armenian Dram', 'camptix' ),
				'format' => 'AMD %s',
			),
			'ANG'=> array(
				'label' => __( 'Netherlands Antillean Guilder', 'camptix' ),
				'format' => 'ANG %s',
			),
			'AOA'=> array(
				'label' => __( 'Angolan Kwanza', 'camptix' ),
				'format' => 'Kz %s',
			),
			'ARS'=> array(
				'label' => __( 'Argentine Peso', 'camptix' ),
				'format' => 'ARS %s',
			),
			'AUD'=> array(
				'label' => __( 'Australian Dollar', 'camptix' ),
				'locale' => 'en_AU.UTF-8',
			),
			'AWG'=> array(
				'label' => __( 'Aruban Florin', 'camptix' ),
				'format' => 'AWG %s',
			),
			'AZN'=> array(
				'label' => __( 'Azerbaijan Manat', 'camptix' ),
				'format' => 'AZN %s',
			),
			'BAM'=> array(
				'label' => __( 'Convertible Mark', 'camptix' ),
				'format' => 'BAM %s',
			),
			'BBD'=> array(
				'label' => __( 'Barbados Dollar', 'camptix' ),
				'format' => 'BBD %s',
			),
			'BDT'=> array(
				'label' => __( 'Taka', 'camptix' ),
				'format' => 'BDT %s',
			),
			'BGN'=> array(
				'label' => __( 'Bulgarian Lev', 'camptix' ),
				'format' => 'BGN %s',
			),
			'BIF'=> array(
				'label' => __( 'Burundi Franc', 'camptix' ),
				'format' => 'BIF %s',
			),
			'BMD'=> array(
				'label' => __( 'Bermudian Dollar', 'camptix' ),
				'format' => 'BMD %s',
			),
			'BND'=> array(
				'label' => __( 'Brunei Dollar', 'camptix' ),
				'format' => 'BND %s',
			),
			'BOB'=> array(
				'label' => __( 'Boliviano', 'camptix' ),
				'format' => 'BOB %s',
			),
			'BRL'=> array(
				'label' => __( 'Brazilian Real', 'camptix' ),
				'locale' => 'pt_BR.UTF-8',
			),
			'BSD'=> array(
				'label' => __( 'Bahamian Dollar', 'camptix' ),
				'format' => 'BSD %s',
			),
			'BWP'=> array(
				'label' => __( 'Pula', 'camptix' ),
				'format' => 'BWP %s',
			),
			'BZD'=> array(
				'label' => __( 'Belize Dollar', 'camptix' ),
				'format' => 'BZD %s',
			),
			'CAD'=> array(
				'label' => __( 'Canadian Dollar', 'camptix' ),
				'locale' => 'en_CA.UTF-8',
			),
			'CDF'=> array(
				'label' => __( 'Congolese Franc', 'camptix' ),
				'format' => 'CDF %s',
			),
			'CHF'=> array(
				'label' => __( 'Swiss Franc', 'camptix' ),
				'locale' => 'fr_CH.UTF-8',
			),
			'CLP'=> array(
				'label' => __( 'Chilean Peso', 'camptix' ),
				'format' => 'CLP %s',
			),
			'CNY'=> array(
				'label' => __( 'Yuan Renminbi', 'camptix' ),
				'format' => 'CNY %s',
			),
			'COP'=> array(
				'label' => __( 'Colombian Peso', 'camptix' ),
				'format' => 'COP %s',
			),
			'CRC'=> array(
				'label' => __( 'Costa Rican Colon', 'camptix' ),
				'format' => 'CRC %s',
			),
			'CVE'=> array(
				'label' => __( 'Cabo Verde Escudo', 'camptix' ),
				'format' => 'CVE %s',
			),
			'CZK'=> array(
				'label' => __( 'Czech Koruna', 'camptix' ),
				'locale' => 'hcs_CZ.UTF-8',
			),
			'DJF'=> array(
				'label' => __( 'Djibouti Franc', 'camptix' ),
				'format' => 'DJF %s',
			),
			'DKK'=> array(
				'label' => __( 'Danish Krone', 'camptix' ),
				'locale' => 'da_DK.UTF-8',
			),
			'DOP'=> array(
				'label' => __( 'Dominican Peso', 'camptix' ),
				'format' => 'DOP %s',
			),
			'DZD'=> array(
				'label' => __( 'Algerian Dinar', 'camptix' ),
				'format' => 'DZD %s',
			),
			'EGP'=> array(
				'label' => __( 'Egyptian Pound', 'camptix' ),
				'format' => 'EGP %s',
			),
			'ETB'=> array(
				'label' => __( 'Ethiopian Birr', 'camptix' ),
				'format' => 'ETB %s',
			),
			'EUR'=> array(
				'label' => __( 'Euro', 'camptix' ),
				'format' => '€ %s',
			),
			'FJD'=> array(
				'label' => __( 'Fiji Dollar', 'camptix' ),
				'format' => 'FJD %s',
			),
			'FKP'=> array(
				'label' => __( 'Falkland Islands Pound', 'camptix' ),
				'format' => 'FKP %s',
			),
			'GBP'=> array(
				'label' => __( 'Pound Sterling', 'camptix' ),
				'locale' => 'en_GB.UTF-8',
			),
			'GEL'=> array(
				'label' => __( 'Lari', 'camptix' ),
				'format' => 'GEL %s',
			),
			'GIP'=> array(
				'label' => __( 'Gibraltar Pound', 'camptix' ),
				'format' => 'GIP %s',
			),
			'GMD'=> array(
				'label' => __( 'Dalasi', 'camptix' ),
				'format' => 'GMD %s',
			),
			'GNF'=> array(
				'label' => __( 'Guinean Franc', 'camptix' ),
				'format' => 'GNF %s',
			),
			'GTQ'=> array(
				'label' => __( 'Quetzal', 'camptix' ),
				'format' => 'GTQ %s',
			),
			'GYD'=> array(
				'label' => __( 'Guyana Dollar', 'camptix' ),
				'format' => 'GYD %s',
			),
			'HKD'=> array(
				'label' => __( 'Hong Kong Dollar', 'camptix' ),
				'locale' => 'zh_HK.UTF-8',
			),
			'HNL'=> array(
				'label' => __( 'Lempira', 'camptix' ),
				'format' => 'HNL %s',
			),
			'HRK'=> array(
				'label' => __( 'Kuna', 'camptix' ),
				'format' => 'HRK %s',
			),
			'HTG'=> array(
				'label' => __( 'Gourde', 'camptix' ),
				'format' => 'HTG %s',
			),
			'HUF'=> array(
				'label' => __( 'Hungarian Forint', 'camptix' ),
				'locale' => 'hu_HU.UTF-8',
			),
			'IDR'=> array(
				'label' => __( 'Rupiah', 'camptix' ),
				'format' => 'IDR %s',
			),
			'ILS'=> array(
				'label' => __( 'Israeli New Sheqel', 'camptix' ),
				'locale' => 'he_IL.UTF-8',
			),
			'INR'=> array(
				'label' => __( 'Indian Rupee', 'camptix' ),
				'format' => 'INR %s',
			),
			'ISK'=> array(
				'label' => __( 'Iceland Krona', 'camptix' ),
				'format' => 'ISK %s',
			),
			'JMD'=> array(
				'label' => __( 'Jamaican Dollar', 'camptix' ),
				'format' => 'JMD %s',
			),
			'JPY'=> array(
				'label' => __( 'Japanese Yen', 'camptix' ),
				'locale' => 'ja_JP.UTF-8',
			),
			'KES'=> array(
				'label' => __( 'Kenyan Shilling', 'camptix' ),
				'format' => 'KES %s',
			),
			'KGS'=> array(
				'label' => __( 'Som', 'camptix' ),
				'format' => 'KGS %s',
			),
			'KHR'=> array(
				'label' => __( 'Riel', 'camptix' ),
				'format' => 'KHR %s',
			),
			'KMF'=> array(
				'label' => __( 'Comorian Franc ', 'camptix' ),
				'format' => 'KMF %s',
			),
			'KRW'=> array(
				'label' => __( 'Won', 'camptix' ),
				'format' => 'KRW %s',
			),
			'KYD'=> array(
				'label' => __( 'Cayman Islands Dollar', 'camptix' ),
				'format' => 'KYD %s',
			),
			'KZT'=> array(
				'label' => __( 'Tenge', 'camptix' ),
				'format' => 'KZT %s',
			),
			'LAK'=> array(
				'label' => __( 'Lao Kip', 'camptix' ),
				'format' => 'LAK %s',
			),
			'LBP'=> array(
				'label' => __( 'Lebanese Pound', 'camptix' ),
				'format' => 'LBP %s',
			),
			'LKR'=> array(
				'label' => __( 'Sri Lanka Rupee', 'camptix' ),
				'format' => 'LKR %s',
			),
			'LRD'=> array(
				'label' => __( 'Liberian Dollar', 'camptix' ),
				'format' => 'LRD %s',
			),
			'LSL'=> array(
				'label' => __( 'Loti', 'camptix' ),
				'format' => 'LSL %s',
			),
			'MAD'=> array(
				'label' => __( 'Moroccan Dirham', 'camptix' ),
				'format' => 'MAD %s',
			),
			'MDL'=> array(
				'label' => __( 'Moldovan Leu', 'camptix' ),
				'format' => 'MDL %s',
			),
			'MGA'=> array(
				'label' => __( 'Malagasy Ariary', 'camptix' ),
				'format' => 'MGA %s',
			),
			'MKD'=> array(
				'label' => __( 'Denar', 'camptix' ),
				'format' => 'MKD %s',
			),
			'MMK'=> array(
				'label' => __( 'Kyat', 'camptix' ),
				'format' => 'MMK %s',
			),
			'MNT'=> array(
				'label' => __( 'Tugrik', 'camptix' ),
				'format' => 'MNT %s',
			),
			'MOP'=> array(
				'label' => __( 'Pataca', 'camptix' ),
				'format' => 'MOP %s',
			),
			'MRO'=> array(
				'label' => __( 'Mauritanian Ouguiya', 'camptix' ),
				'format' => 'MRO %s',
			),
			'MUR'=> array(
				'label' => __( 'Mauritius Rupee', 'camptix' ),
				'format' => 'MUR %s',
			),
			'MVR'=> array(
				'label' => __( 'Rufiyaa', 'camptix' ),
				'format' => 'MVR %s',
			),
			'MWK'=> array(
				'label' => __( 'Malawi Kwacha', 'camptix' ),
				'format' => 'MWK %s',
			),
			'MXN'=> array(
				'label' => __( 'Mexican Peso', 'camptix' ),
				'format' => '$ %s',
			),
			'MYR'=> array(
				'label' => __( 'Malaysian Ringgit', 'camptix' ),
				'format' => 'RM %s',
			),
			'MZN'=> array(
				'label' => __( 'Mozambique Metical', 'camptix' ),
				'format' => 'MZN %s',
			),
			'NAD'=> array(
				'label' => __( 'Namibia Dollar', 'camptix' ),
				'format' => 'NAD %s',
			),
			'NGN'=> array(
				'label' => __( 'Naira', 'camptix' ),
				'format' => 'NGN %s',
			),
			'NIO'=> array(
				'label' => __( 'Cordoba Oro', 'camptix' ),
				'format' => 'NIO %s',
			),
			'NOK'=> array(
				'label' => __( 'Norwegian Krone', 'camptix' ),
				'locale' => 'no_NO.UTF-8',
			),
			'NPR'=> array(
				'label' => __( 'Nepalese Rupee', 'camptix' ),
				'format' => 'NPR %s',
			),
			'NZD'=> array(
				'label' => __( 'N.Z. Dollar', 'camptix' ),
				'locale' => 'en_NZ.UTF-8',
			),
			'PAB'=> array(
				'label' => __( 'Balboa', 'camptix' ),
				'format' => 'PAB %s',
			),
			'PEN'=> array(
				'label' => __( 'Sol', 'camptix' ),
				'format' => 'PEN %s',
			),
			'PGK'=> array(
				'label' => __( 'Kina', 'camptix' ),
				'format' => 'PGK %s',
			),
			'PHP'=> array(
				'label' => __( 'Philippine Peso', 'camptix' ),
				'format' => '₱ %s',
			),
			'PKR'=> array(
				'label' => __( 'Pakistani Rupee', 'camptix' ),
				'format' => '₨ %s',
			),
			'PLN'=> array(
				'label' => __( 'Polish Zloty', 'camptix' ),
				'locale' => 'pl_PL.UTF-8',
			),
			'PYG'=> array(
				'label' => __( 'Guarani', 'camptix' ),
				'format' => 'PYG %s',
			),
			'QAR'=> array(
				'label' => __( 'Qatari Rial', 'camptix' ),
				'format' => 'QAR %s',
			),
			'RON'=> array(
				'label' => __( 'Romanian Leu', 'camptix' ),
				'format' => 'RON %s',
			),
			'RSD'=> array(
				'label' => __( 'Serbian Dinar', 'camptix' ),
				'format' => 'RSD %s',
			),
			'RUB'=> array(
				'label' => __( 'Russian Ruble', 'camptix' ),
				'format' => 'RUB %s',
			),
			'RWF'=> array(
				'label' => __( 'Rwanda Franc', 'camptix' ),
				'format' => 'RWF %s',
			),
			'SAR'=> array(
				'label' => __( 'Saudi Riyal', 'camptix' ),
				'format' => 'SAR %s',
			),
			'SBD'=> array(
				'label' => __( 'Solomon Islands Dollar', 'camptix' ),
				'format' => 'SBD %s',
			),
			'SCR'=> array(
				'label' => __( 'Seychelles Rupee', 'camptix' ),
				'format' => 'SCR %s',
			),
			'SEK'=> array(
				'label' => __( 'Swedish Krona', 'camptix' ),
				'locale' => 'sv_SE.UTF-8',
			),
			'SGD'=> array(
				'label' => __( 'Singapore Dollar', 'camptix' ),
				'format' => '$ %s',
			),
			'SHP'=> array(
				'label' => __( 'Saint Helena Pound', 'camptix' ),
				'format' => 'SHP %s',
			),
			'SLL'=> array(
				'label' => __( 'Leone', 'camptix' ),
				'format' => 'SLL %s',
			),
			'SOS'=> array(
				'label' => __( 'Somali Shilling', 'camptix' ),
				'format' => 'SOS %s',
			),
			'SRD'=> array(
				'label' => __( 'Surinam Dollar', 'camptix' ),
				'format' => 'SRD %s',
			),
			'SZL'=> array(
				'label' => __( 'Lilangeni', 'camptix' ),
				'format' => 'SZL %s',
			),
			'THB'=> array(
				'label' => __( 'Thai Baht', 'camptix' ),
				'format' => '฿ %s',
			),
			'TJS'=> array(
				'label' => __( 'Somoni', 'camptix' ),
				'format' => 'TJS %s',
			),
			'TOP'=> array(
				'label' => __( 'Pa’anga', 'camptix' ),
				'format' => 'TOP %s',
			),
			'TRY'=> array(
				'label' => __( 'Turkish Lira', 'camptix' ),
				'locale' => 'tr_TR.UTF-8',
			),
			'TTD'=> array(
				'label' => __( 'Trinidad and Tobago Dollar', 'camptix' ),
				'format' => 'TTD %s',
			),
			'TWD'=> array(
				'label' => __( 'New Taiwan Dollar', 'camptix' ),
				'locale' => 'zh_TW.UTF-8',
			),
			'TZS'=> array(
				'label' => __( 'Tanzanian Shilling', 'camptix' ),
				'format' => 'TZS %s',
			),
			'UAH'=> array(
				'label' => __( 'Hryvnia', 'camptix' ),
				'format' => 'UAH %s',
			),
			'UGX'=> array(
				'label' => __( 'Uganda Shilling', 'camptix' ),
				'format' => 'UGX %s',
			),
			'USD'=> array(
				'label' => __( 'U.S. Dollar', 'camptix' ),
				'locale' => 'en_US.UTF-8',
			),
			'UYU'=> array(
				'label' => __( 'Peso Uruguayo', 'camptix' ),
				'format' => 'UYU %s',
			),
			'UZS'=> array(
				'label' => __( 'Uzbekistan Sum', 'camptix' ),
				'format' => 'UZS %s',
			),
			'VND'=> array(
				'label' => __( 'Dong', 'camptix' ),
				'format' => 'VND %s',
			),
			'VUV'=> array(
				'label' => __( 'Vatu', 'camptix' ),
				'format' => 'VUV %s',
			),
			'WST'=> array(
				'label' => __( 'Tala', 'camptix' ),
				'format' => 'WST %s',
			),
			'XAF'=> array(
				'label' => __( 'CFA Franc BEAC', 'camptix' ),
				'format' => 'XAF %s',
			),
			'XCD'=> array(
				'label' => __( 'East Caribbean Dollar', 'camptix' ),
				'format' => 'XCD %s',
			),
			'XOF'=> array(
				'label' => __( 'CFA Franc BCEAO', 'camptix' ),
				'format' => 'XOF %s',
			),
			'XPF'=> array(
				'label' => __( 'CFP Franc', 'camptix' ),
				'format' => 'XPF %s',
			),
			'YER'=> array(
				'label' => __( 'Yemeni Rial', 'camptix' ),
				'format' => 'YER %s',
			),
			'ZAR'=> array(
				'label' => __( 'South African Rand', 'camptix' ),
				'format' => 'R %s',
			),
			'ZMW'=> array(
				'label' => __( 'Zambian Kwacha', 'camptix' ),
				'format' => 'ZMW %s',
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
	 * Returns all the currencies that are supported by loaded payment addons, and which are also defined
	 * in `get_currency_list` method above.
	 *
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