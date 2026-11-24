<?php
/**
 * The list of countries offered by the Country field.
 *
 * @package LaunchInteractiveForms
 */

namespace LaunchInteractiveForms;

defined( 'ABSPATH' ) || exit;

/**
 * Country names keyed by ISO 3166-1 alpha-2 code.
 */
class Countries {

	/**
	 * All countries, sorted by name in the site's language.
	 *
	 * The country's name is what a submission stores, so it reads naturally in emails, the
	 * submissions screen, CSV exports and Mailchimp.
	 *
	 * @return array<string,string> Code => name.
	 */
	public static function all() {
		$countries = array(
			'AF' => __( 'Afghanistan', 'launch-interactive-forms' ),
			'AX' => __( 'Åland Islands', 'launch-interactive-forms' ),
			'AL' => __( 'Albania', 'launch-interactive-forms' ),
			'DZ' => __( 'Algeria', 'launch-interactive-forms' ),
			'AS' => __( 'American Samoa', 'launch-interactive-forms' ),
			'AD' => __( 'Andorra', 'launch-interactive-forms' ),
			'AO' => __( 'Angola', 'launch-interactive-forms' ),
			'AI' => __( 'Anguilla', 'launch-interactive-forms' ),
			'AQ' => __( 'Antarctica', 'launch-interactive-forms' ),
			'AG' => __( 'Antigua and Barbuda', 'launch-interactive-forms' ),
			'AR' => __( 'Argentina', 'launch-interactive-forms' ),
			'AM' => __( 'Armenia', 'launch-interactive-forms' ),
			'AW' => __( 'Aruba', 'launch-interactive-forms' ),
			'AU' => __( 'Australia', 'launch-interactive-forms' ),
			'AT' => __( 'Austria', 'launch-interactive-forms' ),
			'AZ' => __( 'Azerbaijan', 'launch-interactive-forms' ),
			'BS' => __( 'Bahamas', 'launch-interactive-forms' ),
			'BH' => __( 'Bahrain', 'launch-interactive-forms' ),
			'BD' => __( 'Bangladesh', 'launch-interactive-forms' ),
			'BB' => __( 'Barbados', 'launch-interactive-forms' ),
			'BY' => __( 'Belarus', 'launch-interactive-forms' ),
			'BE' => __( 'Belgium', 'launch-interactive-forms' ),
			'BZ' => __( 'Belize', 'launch-interactive-forms' ),
			'BJ' => __( 'Benin', 'launch-interactive-forms' ),
			'BM' => __( 'Bermuda', 'launch-interactive-forms' ),
			'BT' => __( 'Bhutan', 'launch-interactive-forms' ),
			'BO' => __( 'Bolivia', 'launch-interactive-forms' ),
			'BQ' => __( 'Bonaire, Sint Eustatius and Saba', 'launch-interactive-forms' ),
			'BA' => __( 'Bosnia and Herzegovina', 'launch-interactive-forms' ),
			'BW' => __( 'Botswana', 'launch-interactive-forms' ),
			'BV' => __( 'Bouvet Island', 'launch-interactive-forms' ),
			'BR' => __( 'Brazil', 'launch-interactive-forms' ),
			'IO' => __( 'British Indian Ocean Territory', 'launch-interactive-forms' ),
			'BN' => __( 'Brunei', 'launch-interactive-forms' ),
			'BG' => __( 'Bulgaria', 'launch-interactive-forms' ),
			'BF' => __( 'Burkina Faso', 'launch-interactive-forms' ),
			'BI' => __( 'Burundi', 'launch-interactive-forms' ),
			'CV' => __( 'Cabo Verde', 'launch-interactive-forms' ),
			'KH' => __( 'Cambodia', 'launch-interactive-forms' ),
			'CM' => __( 'Cameroon', 'launch-interactive-forms' ),
			'CA' => __( 'Canada', 'launch-interactive-forms' ),
			'KY' => __( 'Cayman Islands', 'launch-interactive-forms' ),
			'CF' => __( 'Central African Republic', 'launch-interactive-forms' ),
			'TD' => __( 'Chad', 'launch-interactive-forms' ),
			'CL' => __( 'Chile', 'launch-interactive-forms' ),
			'CN' => __( 'China', 'launch-interactive-forms' ),
			'CX' => __( 'Christmas Island', 'launch-interactive-forms' ),
			'CC' => __( 'Cocos (Keeling) Islands', 'launch-interactive-forms' ),
			'CO' => __( 'Colombia', 'launch-interactive-forms' ),
			'KM' => __( 'Comoros', 'launch-interactive-forms' ),
			'CG' => __( 'Congo', 'launch-interactive-forms' ),
			'CD' => __( 'Congo (Democratic Republic)', 'launch-interactive-forms' ),
			'CK' => __( 'Cook Islands', 'launch-interactive-forms' ),
			'CR' => __( 'Costa Rica', 'launch-interactive-forms' ),
			'CI' => __( 'Côte d\'Ivoire', 'launch-interactive-forms' ),
			'HR' => __( 'Croatia', 'launch-interactive-forms' ),
			'CU' => __( 'Cuba', 'launch-interactive-forms' ),
			'CW' => __( 'Curaçao', 'launch-interactive-forms' ),
			'CY' => __( 'Cyprus', 'launch-interactive-forms' ),
			'CZ' => __( 'Czechia', 'launch-interactive-forms' ),
			'DK' => __( 'Denmark', 'launch-interactive-forms' ),
			'DJ' => __( 'Djibouti', 'launch-interactive-forms' ),
			'DM' => __( 'Dominica', 'launch-interactive-forms' ),
			'DO' => __( 'Dominican Republic', 'launch-interactive-forms' ),
			'EC' => __( 'Ecuador', 'launch-interactive-forms' ),
			'EG' => __( 'Egypt', 'launch-interactive-forms' ),
			'SV' => __( 'El Salvador', 'launch-interactive-forms' ),
			'GQ' => __( 'Equatorial Guinea', 'launch-interactive-forms' ),
			'ER' => __( 'Eritrea', 'launch-interactive-forms' ),
			'EE' => __( 'Estonia', 'launch-interactive-forms' ),
			'SZ' => __( 'Eswatini', 'launch-interactive-forms' ),
			'ET' => __( 'Ethiopia', 'launch-interactive-forms' ),
			'FK' => __( 'Falkland Islands', 'launch-interactive-forms' ),
			'FO' => __( 'Faroe Islands', 'launch-interactive-forms' ),
			'FJ' => __( 'Fiji', 'launch-interactive-forms' ),
			'FI' => __( 'Finland', 'launch-interactive-forms' ),
			'FR' => __( 'France', 'launch-interactive-forms' ),
			'GF' => __( 'French Guiana', 'launch-interactive-forms' ),
			'PF' => __( 'French Polynesia', 'launch-interactive-forms' ),
			'TF' => __( 'French Southern Territories', 'launch-interactive-forms' ),
			'GA' => __( 'Gabon', 'launch-interactive-forms' ),
			'GM' => __( 'Gambia', 'launch-interactive-forms' ),
			'GE' => __( 'Georgia', 'launch-interactive-forms' ),
			'DE' => __( 'Germany', 'launch-interactive-forms' ),
			'GH' => __( 'Ghana', 'launch-interactive-forms' ),
			'GI' => __( 'Gibraltar', 'launch-interactive-forms' ),
			'GR' => __( 'Greece', 'launch-interactive-forms' ),
			'GL' => __( 'Greenland', 'launch-interactive-forms' ),
			'GD' => __( 'Grenada', 'launch-interactive-forms' ),
			'GP' => __( 'Guadeloupe', 'launch-interactive-forms' ),
			'GU' => __( 'Guam', 'launch-interactive-forms' ),
			'GT' => __( 'Guatemala', 'launch-interactive-forms' ),
			'GG' => __( 'Guernsey', 'launch-interactive-forms' ),
			'GN' => __( 'Guinea', 'launch-interactive-forms' ),
			'GW' => __( 'Guinea-Bissau', 'launch-interactive-forms' ),
			'GY' => __( 'Guyana', 'launch-interactive-forms' ),
			'HT' => __( 'Haiti', 'launch-interactive-forms' ),
			'HM' => __( 'Heard Island and McDonald Islands', 'launch-interactive-forms' ),
			'VA' => __( 'Holy See', 'launch-interactive-forms' ),
			'HN' => __( 'Honduras', 'launch-interactive-forms' ),
			'HK' => __( 'Hong Kong', 'launch-interactive-forms' ),
			'HU' => __( 'Hungary', 'launch-interactive-forms' ),
			'IS' => __( 'Iceland', 'launch-interactive-forms' ),
			'IN' => __( 'India', 'launch-interactive-forms' ),
			'ID' => __( 'Indonesia', 'launch-interactive-forms' ),
			'IR' => __( 'Iran', 'launch-interactive-forms' ),
			'IQ' => __( 'Iraq', 'launch-interactive-forms' ),
			'IE' => __( 'Ireland', 'launch-interactive-forms' ),
			'IM' => __( 'Isle of Man', 'launch-interactive-forms' ),
			'IL' => __( 'Israel', 'launch-interactive-forms' ),
			'IT' => __( 'Italy', 'launch-interactive-forms' ),
			'JM' => __( 'Jamaica', 'launch-interactive-forms' ),
			'JP' => __( 'Japan', 'launch-interactive-forms' ),
			'JE' => __( 'Jersey', 'launch-interactive-forms' ),
			'JO' => __( 'Jordan', 'launch-interactive-forms' ),
			'KZ' => __( 'Kazakhstan', 'launch-interactive-forms' ),
			'KE' => __( 'Kenya', 'launch-interactive-forms' ),
			'KI' => __( 'Kiribati', 'launch-interactive-forms' ),
			'KP' => __( 'Korea (North)', 'launch-interactive-forms' ),
			'KR' => __( 'Korea (South)', 'launch-interactive-forms' ),
			'XK' => __( 'Kosovo', 'launch-interactive-forms' ),
			'KW' => __( 'Kuwait', 'launch-interactive-forms' ),
			'KG' => __( 'Kyrgyzstan', 'launch-interactive-forms' ),
			'LA' => __( 'Laos', 'launch-interactive-forms' ),
			'LV' => __( 'Latvia', 'launch-interactive-forms' ),
			'LB' => __( 'Lebanon', 'launch-interactive-forms' ),
			'LS' => __( 'Lesotho', 'launch-interactive-forms' ),
			'LR' => __( 'Liberia', 'launch-interactive-forms' ),
			'LY' => __( 'Libya', 'launch-interactive-forms' ),
			'LI' => __( 'Liechtenstein', 'launch-interactive-forms' ),
			'LT' => __( 'Lithuania', 'launch-interactive-forms' ),
			'LU' => __( 'Luxembourg', 'launch-interactive-forms' ),
			'MO' => __( 'Macao', 'launch-interactive-forms' ),
			'MG' => __( 'Madagascar', 'launch-interactive-forms' ),
			'MW' => __( 'Malawi', 'launch-interactive-forms' ),
			'MY' => __( 'Malaysia', 'launch-interactive-forms' ),
			'MV' => __( 'Maldives', 'launch-interactive-forms' ),
			'ML' => __( 'Mali', 'launch-interactive-forms' ),
			'MT' => __( 'Malta', 'launch-interactive-forms' ),
			'MH' => __( 'Marshall Islands', 'launch-interactive-forms' ),
			'MQ' => __( 'Martinique', 'launch-interactive-forms' ),
			'MR' => __( 'Mauritania', 'launch-interactive-forms' ),
			'MU' => __( 'Mauritius', 'launch-interactive-forms' ),
			'YT' => __( 'Mayotte', 'launch-interactive-forms' ),
			'MX' => __( 'Mexico', 'launch-interactive-forms' ),
			'FM' => __( 'Micronesia', 'launch-interactive-forms' ),
			'MD' => __( 'Moldova', 'launch-interactive-forms' ),
			'MC' => __( 'Monaco', 'launch-interactive-forms' ),
			'MN' => __( 'Mongolia', 'launch-interactive-forms' ),
			'ME' => __( 'Montenegro', 'launch-interactive-forms' ),
			'MS' => __( 'Montserrat', 'launch-interactive-forms' ),
			'MA' => __( 'Morocco', 'launch-interactive-forms' ),
			'MZ' => __( 'Mozambique', 'launch-interactive-forms' ),
			'MM' => __( 'Myanmar', 'launch-interactive-forms' ),
			'NA' => __( 'Namibia', 'launch-interactive-forms' ),
			'NR' => __( 'Nauru', 'launch-interactive-forms' ),
			'NP' => __( 'Nepal', 'launch-interactive-forms' ),
			'NL' => __( 'Netherlands', 'launch-interactive-forms' ),
			'NC' => __( 'New Caledonia', 'launch-interactive-forms' ),
			'NZ' => __( 'New Zealand', 'launch-interactive-forms' ),
			'NI' => __( 'Nicaragua', 'launch-interactive-forms' ),
			'NE' => __( 'Niger', 'launch-interactive-forms' ),
			'NG' => __( 'Nigeria', 'launch-interactive-forms' ),
			'NU' => __( 'Niue', 'launch-interactive-forms' ),
			'NF' => __( 'Norfolk Island', 'launch-interactive-forms' ),
			'MK' => __( 'North Macedonia', 'launch-interactive-forms' ),
			'MP' => __( 'Northern Mariana Islands', 'launch-interactive-forms' ),
			'NO' => __( 'Norway', 'launch-interactive-forms' ),
			'OM' => __( 'Oman', 'launch-interactive-forms' ),
			'PK' => __( 'Pakistan', 'launch-interactive-forms' ),
			'PW' => __( 'Palau', 'launch-interactive-forms' ),
			'PS' => __( 'Palestine', 'launch-interactive-forms' ),
			'PA' => __( 'Panama', 'launch-interactive-forms' ),
			'PG' => __( 'Papua New Guinea', 'launch-interactive-forms' ),
			'PY' => __( 'Paraguay', 'launch-interactive-forms' ),
			'PE' => __( 'Peru', 'launch-interactive-forms' ),
			'PH' => __( 'Philippines', 'launch-interactive-forms' ),
			'PN' => __( 'Pitcairn', 'launch-interactive-forms' ),
			'PL' => __( 'Poland', 'launch-interactive-forms' ),
			'PT' => __( 'Portugal', 'launch-interactive-forms' ),
			'PR' => __( 'Puerto Rico', 'launch-interactive-forms' ),
			'QA' => __( 'Qatar', 'launch-interactive-forms' ),
			'RE' => __( 'Réunion', 'launch-interactive-forms' ),
			'RO' => __( 'Romania', 'launch-interactive-forms' ),
			'RU' => __( 'Russia', 'launch-interactive-forms' ),
			'RW' => __( 'Rwanda', 'launch-interactive-forms' ),
			'BL' => __( 'Saint Barthélemy', 'launch-interactive-forms' ),
			'SH' => __( 'Saint Helena, Ascension and Tristan da Cunha', 'launch-interactive-forms' ),
			'KN' => __( 'Saint Kitts and Nevis', 'launch-interactive-forms' ),
			'LC' => __( 'Saint Lucia', 'launch-interactive-forms' ),
			'MF' => __( 'Saint Martin', 'launch-interactive-forms' ),
			'PM' => __( 'Saint Pierre and Miquelon', 'launch-interactive-forms' ),
			'VC' => __( 'Saint Vincent and the Grenadines', 'launch-interactive-forms' ),
			'WS' => __( 'Samoa', 'launch-interactive-forms' ),
			'SM' => __( 'San Marino', 'launch-interactive-forms' ),
			'ST' => __( 'Sao Tome and Principe', 'launch-interactive-forms' ),
			'SA' => __( 'Saudi Arabia', 'launch-interactive-forms' ),
			'SN' => __( 'Senegal', 'launch-interactive-forms' ),
			'RS' => __( 'Serbia', 'launch-interactive-forms' ),
			'SC' => __( 'Seychelles', 'launch-interactive-forms' ),
			'SL' => __( 'Sierra Leone', 'launch-interactive-forms' ),
			'SG' => __( 'Singapore', 'launch-interactive-forms' ),
			'SX' => __( 'Sint Maarten', 'launch-interactive-forms' ),
			'SK' => __( 'Slovakia', 'launch-interactive-forms' ),
			'SI' => __( 'Slovenia', 'launch-interactive-forms' ),
			'SB' => __( 'Solomon Islands', 'launch-interactive-forms' ),
			'SO' => __( 'Somalia', 'launch-interactive-forms' ),
			'ZA' => __( 'South Africa', 'launch-interactive-forms' ),
			'GS' => __( 'South Georgia and the South Sandwich Islands', 'launch-interactive-forms' ),
			'SS' => __( 'South Sudan', 'launch-interactive-forms' ),
			'ES' => __( 'Spain', 'launch-interactive-forms' ),
			'LK' => __( 'Sri Lanka', 'launch-interactive-forms' ),
			'SD' => __( 'Sudan', 'launch-interactive-forms' ),
			'SR' => __( 'Suriname', 'launch-interactive-forms' ),
			'SJ' => __( 'Svalbard and Jan Mayen', 'launch-interactive-forms' ),
			'SE' => __( 'Sweden', 'launch-interactive-forms' ),
			'CH' => __( 'Switzerland', 'launch-interactive-forms' ),
			'SY' => __( 'Syria', 'launch-interactive-forms' ),
			'TW' => __( 'Taiwan', 'launch-interactive-forms' ),
			'TJ' => __( 'Tajikistan', 'launch-interactive-forms' ),
			'TZ' => __( 'Tanzania', 'launch-interactive-forms' ),
			'TH' => __( 'Thailand', 'launch-interactive-forms' ),
			'TL' => __( 'Timor-Leste', 'launch-interactive-forms' ),
			'TG' => __( 'Togo', 'launch-interactive-forms' ),
			'TK' => __( 'Tokelau', 'launch-interactive-forms' ),
			'TO' => __( 'Tonga', 'launch-interactive-forms' ),
			'TT' => __( 'Trinidad and Tobago', 'launch-interactive-forms' ),
			'TN' => __( 'Tunisia', 'launch-interactive-forms' ),
			'TR' => __( 'Türkiye', 'launch-interactive-forms' ),
			'TM' => __( 'Turkmenistan', 'launch-interactive-forms' ),
			'TC' => __( 'Turks and Caicos Islands', 'launch-interactive-forms' ),
			'TV' => __( 'Tuvalu', 'launch-interactive-forms' ),
			'UG' => __( 'Uganda', 'launch-interactive-forms' ),
			'UA' => __( 'Ukraine', 'launch-interactive-forms' ),
			'AE' => __( 'United Arab Emirates', 'launch-interactive-forms' ),
			'GB' => __( 'United Kingdom', 'launch-interactive-forms' ),
			'US' => __( 'United States', 'launch-interactive-forms' ),
			'UM' => __( 'United States Minor Outlying Islands', 'launch-interactive-forms' ),
			'UY' => __( 'Uruguay', 'launch-interactive-forms' ),
			'UZ' => __( 'Uzbekistan', 'launch-interactive-forms' ),
			'VU' => __( 'Vanuatu', 'launch-interactive-forms' ),
			'VE' => __( 'Venezuela', 'launch-interactive-forms' ),
			'VN' => __( 'Vietnam', 'launch-interactive-forms' ),
			'VG' => __( 'Virgin Islands (British)', 'launch-interactive-forms' ),
			'VI' => __( 'Virgin Islands (U.S.)', 'launch-interactive-forms' ),
			'WF' => __( 'Wallis and Futuna', 'launch-interactive-forms' ),
			'EH' => __( 'Western Sahara', 'launch-interactive-forms' ),
			'YE' => __( 'Yemen', 'launch-interactive-forms' ),
			'ZM' => __( 'Zambia', 'launch-interactive-forms' ),
			'ZW' => __( 'Zimbabwe', 'launch-interactive-forms' ),
		);

		/**
		 * Filters the countries offered by Country fields.
		 *
		 * Remove entries to limit the choice, or rename them. Keys are ISO 3166-1 alpha-2 codes.
		 *
		 * @param array<string,string> $countries Code => name.
		 */
		$countries = (array) apply_filters( 'liforms_countries', $countries );
		$countries = array_filter( array_map( 'sanitize_text_field', array_filter( $countries, 'is_scalar' ) ), 'strlen' );
		uasort( $countries, array( self::class, 'compare' ) );
		return $countries;
	}

	/**
	 * Country names only.
	 *
	 * @return string[]
	 */
	public static function names() {
		return array_values( self::all() );
	}

	/**
	 * Whether a value is one of the offered countries.
	 *
	 * @param string $value Submitted value.
	 * @return bool
	 */
	public static function is_valid( $value ) {
		return in_array( (string) $value, self::names(), true );
	}

	/**
	 * Sort names alphabetically, ignoring accents (so Åland sits with the A's).
	 *
	 * @param string $a Name.
	 * @param string $b Name.
	 * @return int
	 */
	private static function compare( $a, $b ) {
		return strcasecmp( remove_accents( $a ), remove_accents( $b ) );
	}
}
