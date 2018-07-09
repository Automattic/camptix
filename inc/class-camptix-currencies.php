<?php

class CampTix_Currency {
	/**
	 * @var int
	 */
	public $version = 20180627;

	/**
	 * Generate a canonical list of currencies and their properties.
	 *
	 * TODO: Decide on the format of currency, if we want to show localized format or a standard format, and
	 * then sort the currencies in alphabetical order so that they are easier to find.
	 *
	 * @return array An associative array of currencies.
	 *               Key = ISO 4217 currency code. Value = Array of currency properties.
	 */
	public static function get_currency_list() {
		return array(
			'AED' => array(
				'label'         => __( 'United Arab Emirates Dirham', 'camptix' ),
				'format'        => '%s AED',
				'decimal_point' => 2,
			),
			'AFN' => array(
				'label'         => __( 'Afghan Afghani', 'camptix' ),
				'format'        => 'AFN %s',
				'decimal_point' => 2,
			),
			'ALL' => array(
				'label'         => __( 'Albanian Lek', 'camptix' ),
				'format'        => 'L %s',
				'decimal_point' => 2,
			),
			'AMD' => array(
				'label'         => __( 'Armenian Dram', 'camptix' ),
				'format'        => 'AMD %s',
				'decimal_point' => 2,
			),
			'ANG' => array(
				'label'         => __( 'Netherlands Antillean Guilder', 'camptix' ),
				'format'        => 'ANG %s',
				'decimal_point' => 2,
			),
			'AOA' => array(
				'label'         => __( 'Angolan Kwanza', 'camptix' ),
				'format'        => 'Kz %s',
				'decimal_point' => 2,
			),
			'ARS' => array(
				'label'         => __( 'Argentine Peso', 'camptix' ),
				'format'        => 'ARS %s',
				'decimal_point' => 2,
			),
			'AUD' => array(
				'label'         => __( 'Australian Dollar', 'camptix' ),
				'locale'        => 'en_AU.UTF-8',
				'decimal_point' => 2,
			),
			'AWG' => array(
				'label'         => __( 'Aruban Florin', 'camptix' ),
				'format'        => 'AWG %s',
				'decimal_point' => 2,
			),
			'AZN' => array(
				'label'         => __( 'Azerbaijan Manat', 'camptix' ),
				'format'        => 'AZN %s',
				'decimal_point' => 2,
			),
			'BAM' => array(
				'label'         => __( 'Convertible Mark', 'camptix' ),
				'format'        => 'BAM %s',
				'decimal_point' => 2,
			),
			'BBD' => array(
				'label'         => __( 'Barbados Dollar', 'camptix' ),
				'format'        => 'BBD %s',
				'decimal_point' => 2,
			),
			'BDT' => array(
				'label'         => __( 'Taka', 'camptix' ),
				'format'        => 'BDT %s',
				'decimal_point' => 2,
			),
			'BGN' => array(
				'label'         => __( 'Bulgarian Lev', 'camptix' ),
				'format'        => 'BGN %s',
				'decimal_point' => 2,
			),
			'BIF' => array(
				'label'         => __( 'Burundi Franc', 'camptix' ),
				'format'        => 'BIF %s',
				'decimal_point' => 0,
			),
			'BMD' => array(
				'label'         => __( 'Bermudian Dollar', 'camptix' ),
				'format'        => 'BMD %s',
				'decimal_point' => 2,
			),
			'BND' => array(
				'label'         => __( 'Brunei Dollar', 'camptix' ),
				'format'        => 'BND %s',
				'decimal_point' => 2,
			),
			'BOB' => array(
				'label'         => __( 'Boliviano', 'camptix' ),
				'format'        => 'BOB %s',
				'decimal_point' => 2,
			),
			'BRL' => array(
				'label'         => __( 'Brazilian Real', 'camptix' ),
				'locale'        => 'pt_BR.UTF-8',
				'decimal_point' => 2,
			),
			'BSD' => array(
				'label'         => __( 'Bahamian Dollar', 'camptix' ),
				'format'        => 'BSD %s',
				'decimal_point' => 2,
			),
			'BWP' => array(
				'label'         => __( 'Pula', 'camptix' ),
				'format'        => 'BWP %s',
				'decimal_point' => 2,
			),
			'BZD' => array(
				'label'         => __( 'Belize Dollar', 'camptix' ),
				'format'        => 'BZD %s',
				'decimal_point' => 2,
			),
			'CAD' => array(
				'label'         => __( 'Canadian Dollar', 'camptix' ),
				'locale'        => 'en_CA.UTF-8',
				'decimal_point' => 2,
			),
			'CDF' => array(
				'label'         => __( 'Congolese Franc', 'camptix' ),
				'format'        => 'CDF %s',
				'decimal_point' => 2,
			),
			'CHF' => array(
				'label'         => __( 'Swiss Franc', 'camptix' ),
				'locale'        => 'fr_CH.UTF-8',
				'decimal_point' => 2,
			),
			'CLP' => array(
				'label'         => __( 'Chilean Peso', 'camptix' ),
				'format'        => 'CLP %s',
				'decimal_point' => 0,
			),
			'CNY' => array(
				'label'         => __( 'Yuan Renminbi', 'camptix' ),
				'format'        => 'CNY %s',
				'decimal_point' => 2,
			),
			'COP' => array(
				'label'         => __( 'Colombian Peso', 'camptix' ),
				'format'        => 'COP %s',
				'decimal_point' => 2,
			),
			'CRC' => array(
				'label'         => __( 'Costa Rican Colon', 'camptix' ),
				'format'        => 'CRC %s',
				'decimal_point' => 2,
			),
			'CVE' => array(
				'label'         => __( 'Cabo Verde Escudo', 'camptix' ),
				'format'        => 'CVE %s',
				'decimal_point' => 2,
			),
			'CZK' => array(
				'label'         => __( 'Czech Koruna', 'camptix' ),
				'locale'        => 'hcs_CZ.UTF-8',
				'decimal_point' => 2,
			),
			'DJF' => array(
				'label'         => __( 'Djibouti Franc', 'camptix' ),
				'format'        => 'DJF %s',
				'decimal_point' => 0,
			),
			'DKK' => array(
				'label'         => __( 'Danish Krone', 'camptix' ),
				'locale'        => 'da_DK.UTF-8',
				'decimal_point' => 2,
			),
			'DOP' => array(
				'label'         => __( 'Dominican Peso', 'camptix' ),
				'format'        => 'DOP %s',
				'decimal_point' => 2,
			),
			'DZD' => array(
				'label'         => __( 'Algerian Dinar', 'camptix' ),
				'format'        => 'DZD %s',
				'decimal_point' => 2,
			),
			'EGP' => array(
				'label'         => __( 'Egyptian Pound', 'camptix' ),
				'format'        => 'EGP %s',
				'decimal_point' => 2,
			),
			'ETB' => array(
				'label'         => __( 'Ethiopian Birr', 'camptix' ),
				'format'        => 'ETB %s',
				'decimal_point' => 2,
			),
			'EUR' => array(
				'label'         => __( 'Euro', 'camptix' ),
				'format'        => '€ %s',
				'decimal_point' => 2,
			),
			'FJD' => array(
				'label'         => __( 'Fiji Dollar', 'camptix' ),
				'format'        => 'FJD %s',
				'decimal_point' => 2,
			),
			'FKP' => array(
				'label'         => __( 'Falkland Islands Pound', 'camptix' ),
				'format'        => 'FKP %s',
				'decimal_point' => 2,
			),
			'GBP' => array(
				'label'         => __( 'Pound Sterling', 'camptix' ),
				'locale'        => 'en_GB.UTF-8',
				'decimal_point' => 2,
			),
			'GEL' => array(
				'label'         => __( 'Lari', 'camptix' ),
				'format'        => 'GEL %s',
				'decimal_point' => 2,
			),
			'GIP' => array(
				'label'         => __( 'Gibraltar Pound', 'camptix' ),
				'format'        => 'GIP %s',
				'decimal_point' => 2,
			),
			'GMD' => array(
				'label'         => __( 'Dalasi', 'camptix' ),
				'format'        => 'GMD %s',
				'decimal_point' => 2,
			),
			'GNF' => array(
				'label'         => __( 'Guinean Franc', 'camptix' ),
				'format'        => 'GNF %s',
				'decimal_point' => 0,
			),
			'GTQ' => array(
				'label'         => __( 'Quetzal', 'camptix' ),
				'format'        => 'GTQ %s',
				'decimal_point' => 2,
			),
			'GYD' => array(
				'label'         => __( 'Guyana Dollar', 'camptix' ),
				'format'        => 'GYD %s',
				'decimal_point' => 2,
			),
			'HKD' => array(
				'label'         => __( 'Hong Kong Dollar', 'camptix' ),
				'locale'        => 'zh_HK.UTF-8',
				'decimal_point' => 2,
			),
			'HNL' => array(
				'label'         => __( 'Lempira', 'camptix' ),
				'format'        => 'HNL %s',
				'decimal_point' => 2,
			),
			'HRK' => array(
				'label'         => __( 'Kuna', 'camptix' ),
				'format'        => 'HRK %s',
				'decimal_point' => 2,
			),
			'HTG' => array(
				'label'         => __( 'Gourde', 'camptix' ),
				'format'        => 'HTG %s',
				'decimal_point' => 2,
			),
			'HUF' => array(
				'label'         => __( 'Hungarian Forint', 'camptix' ),
				'locale'        => 'hu_HU.UTF-8',
				'decimal_point' => 2,
			),
			'IDR' => array(
				'label'         => __( 'Rupiah', 'camptix' ),
				'format'        => 'IDR %s',
				'decimal_point' => 2,
			),
			'ILS' => array(
				'label'         => __( 'Israeli New Sheqel', 'camptix' ),
				'locale'        => 'he_IL.UTF-8',
				'decimal_point' => 2,
			),
			'INR' => array(
				'label'         => __( 'Indian Rupee', 'camptix' ),
				'format'        => '₹ %s',
				'decimal_point' => 2,
			),
			'ISK' => array(
				'label'         => __( 'Iceland Krona', 'camptix' ),
				'format'        => 'ISK %s',
				'decimal_point' => 0,
			),
			'JMD' => array(
				'label'         => __( 'Jamaican Dollar', 'camptix' ),
				'format'        => 'JMD %s',
				'decimal_point' => 2,
			),
			'JPY' => array(
				'label'         => __( 'Japanese Yen', 'camptix' ),
				'locale'        => 'ja_JP.UTF-8',
				'decimal_point' => 0,
			),
			'KES' => array(
				'label'         => __( 'Kenyan Shilling', 'camptix' ),
				'format'        => 'KES %s',
				'decimal_point' => 2,
			),
			'KGS' => array(
				'label'         => __( 'Som', 'camptix' ),
				'format'        => 'KGS %s',
				'decimal_point' => 2,
			),
			'KHR' => array(
				'label'         => __( 'Riel', 'camptix' ),
				'format'        => 'KHR %s',
				'decimal_point' => 2,
			),
			'KMF' => array(
				'label'         => __( 'Comorian Franc', 'camptix' ),
				'format'        => 'KMF %s',
				'decimal_point' => 0,
			),
			'KRW' => array(
				'label'         => __( 'Won', 'camptix' ),
				'format'        => 'KRW %s',
				'decimal_point' => 0,
			),
			'KYD' => array(
				'label'         => __( 'Cayman Islands Dollar', 'camptix' ),
				'format'        => 'KYD %s',
				'decimal_point' => 2,
			),
			'KZT' => array(
				'label'         => __( 'Tenge', 'camptix' ),
				'format'        => 'KZT %s',
				'decimal_point' => 2,
			),
			'LAK' => array(
				'label'         => __( 'Lao Kip', 'camptix' ),
				'format'        => 'LAK %s',
				'decimal_point' => 2,
			),
			'LBP' => array(
				'label'         => __( 'Lebanese Pound', 'camptix' ),
				'format'        => 'LBP %s',
				'decimal_point' => 2,
			),
			'LKR' => array(
				'label'         => __( 'Sri Lanka Rupee', 'camptix' ),
				'format'        => 'LKR %s',
				'decimal_point' => 2,
			),
			'LRD' => array(
				'label'         => __( 'Liberian Dollar', 'camptix' ),
				'format'        => 'LRD %s',
				'decimal_point' => 2,
			),
			'LSL' => array(
				'label'         => __( 'Loti', 'camptix' ),
				'format'        => 'LSL %s',
				'decimal_point' => 2,
			),
			'MAD' => array(
				'label'         => __( 'Moroccan Dirham', 'camptix' ),
				'format'        => 'MAD %s',
				'decimal_point' => 2,
			),
			'MDL' => array(
				'label'         => __( 'Moldovan Leu', 'camptix' ),
				'format'        => 'MDL %s',
				'decimal_point' => 2,
			),
			'MGA' => array(
				'label'         => __( 'Malagasy Ariary', 'camptix' ),
				'format'        => 'MGA %s',
				'decimal_point' => 2,
			),
			'MKD' => array(
				'label'         => __( 'Denar', 'camptix' ),
				'format'        => 'MKD %s',
				'decimal_point' => 2,
			),
			'MMK' => array(
				'label'         => __( 'Kyat', 'camptix' ),
				'format'        => 'MMK %s',
				'decimal_point' => 2,
			),
			'MNT' => array(
				'label'         => __( 'Tugrik', 'camptix' ),
				'format'        => 'MNT %s',
				'decimal_point' => 2,
			),
			'MOP' => array(
				'label'         => __( 'Pataca', 'camptix' ),
				'format'        => 'MOP %s',
				'decimal_point' => 2,
			),
			'MRO' => array(
				'label'         => __( 'Mauritanian Ouguiya', 'camptix' ),
				'format'        => 'MRO %s',
				'decimal_point' => 2,
			),
			'MUR' => array(
				'label'         => __( 'Mauritius Rupee', 'camptix' ),
				'format'        => 'MUR %s',
				'decimal_point' => 2,
			),
			'MVR' => array(
				'label'         => __( 'Rufiyaa', 'camptix' ),
				'format'        => 'MVR %s',
				'decimal_point' => 2,
			),
			'MWK' => array(
				'label'         => __( 'Malawi Kwacha', 'camptix' ),
				'format'        => 'MWK %s',
				'decimal_point' => 2,
			),
			'MXN' => array(
				'label'         => __( 'Mexican Peso', 'camptix' ),
				'format'        => '$ %s',
				'decimal_point' => 2,
			),
			'MYR' => array(
				'label'         => __( 'Malaysian Ringgit', 'camptix' ),
				'format'        => 'RM %s',
				'decimal_point' => 2,
			),
			'MZN' => array(
				'label'         => __( 'Mozambique Metical', 'camptix' ),
				'format'        => 'MZN %s',
				'decimal_point' => 2,
			),
			'NAD' => array(
				'label'         => __( 'Namibia Dollar', 'camptix' ),
				'format'        => 'NAD %s',
				'decimal_point' => 2,
			),
			'NGN' => array(
				'label'         => __( 'Naira', 'camptix' ),
				'format'        => 'NGN %s',
				'decimal_point' => 2,
			),
			'NIO' => array(
				'label'         => __( 'Cordoba Oro', 'camptix' ),
				'format'        => 'NIO %s',
				'decimal_point' => 2,
			),
			'NOK' => array(
				'label'         => __( 'Norwegian Krone', 'camptix' ),
				'locale'        => 'no_NO.UTF-8',
				'decimal_point' => 2,
			),
			'NPR' => array(
				'label'         => __( 'Nepalese Rupee', 'camptix' ),
				'format'        => 'NPR %s',
				'decimal_point' => 2,
			),
			'NZD' => array(
				'label'         => __( 'N.Z. Dollar', 'camptix' ),
				'locale'        => 'en_NZ.UTF-8',
				'decimal_point' => 2,
			),
			'PAB' => array(
				'label'         => __( 'Balboa', 'camptix' ),
				'format'        => 'PAB %s',
				'decimal_point' => 2,
			),
			'PEN' => array(
				'label'         => __( 'Sol', 'camptix' ),
				'format'        => 'PEN %s',
				'decimal_point' => 2,
			),
			'PGK' => array(
				'label'         => __( 'Kina', 'camptix' ),
				'format'        => 'PGK %s',
				'decimal_point' => 2,
			),
			'PHP' => array(
				'label'         => __( 'Philippine Peso', 'camptix' ),
				'format'        => '₱ %s',
				'decimal_point' => 2,
			),
			'PKR' => array(
				'label'         => __( 'Pakistani Rupee', 'camptix' ),
				'format'        => '₨ %s',
				'decimal_point' => 2,
			),
			'PLN' => array(
				'label'         => __( 'Polish Zloty', 'camptix' ),
				'locale'        => 'pl_PL.UTF-8',
				'decimal_point' => 2,
			),
			'PYG' => array(
				'label'         => __( 'Guarani', 'camptix' ),
				'format'        => 'PYG %s',
				'decimal_point' => 0,
			),
			'QAR' => array(
				'label'         => __( 'Qatari Rial', 'camptix' ),
				'format'        => 'QAR %s',
				'decimal_point' => 2,
			),
			'RON' => array(
				'label'         => __( 'Romanian Leu', 'camptix' ),
				'format'        => 'RON %s',
				'decimal_point' => 2,
			),
			'RSD' => array(
				'label'         => __( 'Serbian Dinar', 'camptix' ),
				'format'        => 'RSD %s',
				'decimal_point' => 2,
			),
			'RUB' => array(
				'label'         => __( 'Russian Ruble', 'camptix' ),
				'format'        => 'RUB %s',
				'decimal_point' => 2,
			),
			'RWF' => array(
				'label'         => __( 'Rwanda Franc', 'camptix' ),
				'format'        => 'RWF %s',
				'decimal_point' => 0,
			),
			'SAR' => array(
				'label'         => __( 'Saudi Riyal', 'camptix' ),
				'format'        => 'SAR %s',
				'decimal_point' => 2,
			),
			'SBD' => array(
				'label'         => __( 'Solomon Islands Dollar', 'camptix' ),
				'format'        => 'SBD %s',
				'decimal_point' => 2,
			),
			'SCR' => array(
				'label'         => __( 'Seychelles Rupee', 'camptix' ),
				'format'        => 'SCR %s',
				'decimal_point' => 2,
			),
			'SEK' => array(
				'label'         => __( 'Swedish Krona', 'camptix' ),
				'locale'        => 'sv_SE.UTF-8',
				'decimal_point' => 2,
			),
			'SGD' => array(
				'label'         => __( 'Singapore Dollar', 'camptix' ),
				'format'        => '$ %s',
				'decimal_point' => 2,
			),
			'SHP' => array(
				'label'         => __( 'Saint Helena Pound', 'camptix' ),
				'format'        => 'SHP %s',
				'decimal_point' => 2,
			),
			'SLL' => array(
				'label'         => __( 'Leone', 'camptix' ),
				'format'        => 'SLL %s',
				'decimal_point' => 2,
			),
			'SOS' => array(
				'label'         => __( 'Somali Shilling', 'camptix' ),
				'format'        => 'SOS %s',
				'decimal_point' => 2,
			),
			'SRD' => array(
				'label'         => __( 'Surinam Dollar', 'camptix' ),
				'format'        => 'SRD %s',
				'decimal_point' => 2,
			),
			'SZL' => array(
				'label'         => __( 'Lilangeni', 'camptix' ),
				'format'        => 'SZL %s',
				'decimal_point' => 2,
			),
			'THB' => array(
				'label'         => __( 'Thai Baht', 'camptix' ),
				'format'        => '฿ %s',
				'decimal_point' => 2,
			),
			'TJS' => array(
				'label'         => __( 'Somoni', 'camptix' ),
				'format'        => 'TJS %s',
				'decimal_point' => 2,
			),
			'TOP' => array(
				'label'         => __( 'Pa’anga', 'camptix' ),
				'format'        => 'TOP %s',
				'decimal_point' => 2,
			),
			'TRY' => array(
				'label'         => __( 'Turkish Lira', 'camptix' ),
				'locale'        => 'tr_TR.UTF-8',
				'decimal_point' => 2,
			),
			'TTD' => array(
				'label'         => __( 'Trinidad and Tobago Dollar', 'camptix' ),
				'format'        => 'TTD %s',
				'decimal_point' => 2,
			),
			'TWD' => array(
				'label'         => __( 'New Taiwan Dollar', 'camptix' ),
				'locale'        => 'zh_TW.UTF-8',
				'decimal_point' => 2,
			),
			'TZS' => array(
				'label'         => __( 'Tanzanian Shilling', 'camptix' ),
				'format'        => 'TZS %s',
				'decimal_point' => 2,
			),
			'UAH' => array(
				'label'         => __( 'Hryvnia', 'camptix' ),
				'format'        => 'UAH %s',
				'decimal_point' => 2,
			),
			'UGX' => array(
				'label'         => __( 'Uganda Shilling', 'camptix' ),
				'format'        => 'UGX %s',
				'decimal_point' => 0,
			),
			'USD' => array(
				'label'         => __( 'U.S. Dollar', 'camptix' ),
				'locale'        => 'en_US.UTF-8',
				'decimal_point' => 2,
			),
			'UYU' => array(
				'label'         => __( 'Peso Uruguayo', 'camptix' ),
				'format'        => 'UYU %s',
				'decimal_point' => 2,
			),
			'UZS' => array(
				'label'         => __( 'Uzbekistan Sum', 'camptix' ),
				'format'        => 'UZS %s',
				'decimal_point' => 2,
			),
			'VND' => array(
				'label'         => __( 'Dong', 'camptix' ),
				'format'        => 'VND %s',
				'decimal_point' => 0,
			),
			'VUV' => array(
				'label'         => __( 'Vatu', 'camptix' ),
				'format'        => 'VUV %s',
				'decimal_point' => 0,
			),
			'WST' => array(
				'label'         => __( 'Tala', 'camptix' ),
				'format'        => 'WST %s',
				'decimal_point' => 2,
			),
			'XAF' => array(
				'label'         => __( 'CFA Franc BEAC', 'camptix' ),
				'format'        => 'XAF %s',
				'decimal_point' => 0,
			),
			'XCD' => array(
				'label'         => __( 'East Caribbean Dollar', 'camptix' ),
				'format'        => 'XCD %s',
				'decimal_point' => 2,
			),
			'XOF' => array(
				'label'         => __( 'CFA Franc BCEAO', 'camptix' ),
				'format'        => 'XOF %s',
				'decimal_point' => 0,
			),
			'XPF' => array(
				'label'         => __( 'CFP Franc', 'camptix' ),
				'format'        => 'XPF %s',
				'decimal_point' => 0,
			),
			'YER' => array(
				'label'         => __( 'Yemeni Rial', 'camptix' ),
				'format'        => 'YER %s',
				'decimal_point' => 2,
			),
			'ZAR' => array(
				'label'         => __( 'South African Rand', 'camptix' ),
				'format'        => 'R %s',
				'decimal_point' => 2,
			),
			'ZMW' => array(
				'label'         => __( 'Zambian Kwacha', 'camptix' ),
				'format'        => 'ZMW %s',
				'decimal_point' => 2,
			),
		);
	}

	/**
	 * Get the list of ISO currency codes supported by currently-enabled payment methods.
	 *
	 * Supported currencies are added via filter by different payment gateway addons/plugins. Addons should have
	 * `$supported_currencies` variable defined with list of currencies that they support in ISO format.
	 *
	 * @return array The list of currency codes.
	 */
	protected static function get_supported_currency_list() {
		return apply_filters( 'camptix_supported_currencies', array() );
	}

	/**
	 * Get an associative array of currencies and their properties that are supported by currently-enabled payment gateways.
	 *
	 * Returns all the currencies that are supported by loaded payment addons, which are also defined
	 * in `get_currency_list` method above.
	 *
	 * @return array The list of currencies, with their properties, which are currently supported.
	 */
	public static function get_currencies() {
		// from https://stackoverflow.com/a/4260168/1845153
		$supported_currencies = array_intersect_key(
			self::get_currency_list(),
			array_flip( self::get_supported_currency_list() )
		);

		$currencies = apply_filters( 'camptix_currencies', $supported_currencies );

		return $currencies;
	}
}