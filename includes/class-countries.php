<?php
/**
 * The list of countries offered by the Country field.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

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
			'AF' => __( 'Afghanistan', 'launch-forms' ),
			'AX' => __( 'Åland Islands', 'launch-forms' ),
			'AL' => __( 'Albania', 'launch-forms' ),
			'DZ' => __( 'Algeria', 'launch-forms' ),
			'AS' => __( 'American Samoa', 'launch-forms' ),
			'AD' => __( 'Andorra', 'launch-forms' ),
			'AO' => __( 'Angola', 'launch-forms' ),
			'AI' => __( 'Anguilla', 'launch-forms' ),
			'AQ' => __( 'Antarctica', 'launch-forms' ),
			'AG' => __( 'Antigua and Barbuda', 'launch-forms' ),
			'AR' => __( 'Argentina', 'launch-forms' ),
			'AM' => __( 'Armenia', 'launch-forms' ),
			'AW' => __( 'Aruba', 'launch-forms' ),
			'AU' => __( 'Australia', 'launch-forms' ),
			'AT' => __( 'Austria', 'launch-forms' ),
			'AZ' => __( 'Azerbaijan', 'launch-forms' ),
			'BS' => __( 'Bahamas', 'launch-forms' ),
			'BH' => __( 'Bahrain', 'launch-forms' ),
			'BD' => __( 'Bangladesh', 'launch-forms' ),
			'BB' => __( 'Barbados', 'launch-forms' ),
			'BY' => __( 'Belarus', 'launch-forms' ),
			'BE' => __( 'Belgium', 'launch-forms' ),
			'BZ' => __( 'Belize', 'launch-forms' ),
			'BJ' => __( 'Benin', 'launch-forms' ),
			'BM' => __( 'Bermuda', 'launch-forms' ),
			'BT' => __( 'Bhutan', 'launch-forms' ),
			'BO' => __( 'Bolivia', 'launch-forms' ),
			'BQ' => __( 'Bonaire, Sint Eustatius and Saba', 'launch-forms' ),
			'BA' => __( 'Bosnia and Herzegovina', 'launch-forms' ),
			'BW' => __( 'Botswana', 'launch-forms' ),
			'BV' => __( 'Bouvet Island', 'launch-forms' ),
			'BR' => __( 'Brazil', 'launch-forms' ),
			'IO' => __( 'British Indian Ocean Territory', 'launch-forms' ),
			'BN' => __( 'Brunei', 'launch-forms' ),
			'BG' => __( 'Bulgaria', 'launch-forms' ),
			'BF' => __( 'Burkina Faso', 'launch-forms' ),
			'BI' => __( 'Burundi', 'launch-forms' ),
			'CV' => __( 'Cabo Verde', 'launch-forms' ),
			'KH' => __( 'Cambodia', 'launch-forms' ),
			'CM' => __( 'Cameroon', 'launch-forms' ),
			'CA' => __( 'Canada', 'launch-forms' ),
			'KY' => __( 'Cayman Islands', 'launch-forms' ),
			'CF' => __( 'Central African Republic', 'launch-forms' ),
			'TD' => __( 'Chad', 'launch-forms' ),
			'CL' => __( 'Chile', 'launch-forms' ),
			'CN' => __( 'China', 'launch-forms' ),
			'CX' => __( 'Christmas Island', 'launch-forms' ),
			'CC' => __( 'Cocos (Keeling) Islands', 'launch-forms' ),
			'CO' => __( 'Colombia', 'launch-forms' ),
			'KM' => __( 'Comoros', 'launch-forms' ),
			'CG' => __( 'Congo', 'launch-forms' ),
			'CD' => __( 'Congo (Democratic Republic)', 'launch-forms' ),
			'CK' => __( 'Cook Islands', 'launch-forms' ),
			'CR' => __( 'Costa Rica', 'launch-forms' ),
			'CI' => __( 'Côte d\'Ivoire', 'launch-forms' ),
			'HR' => __( 'Croatia', 'launch-forms' ),
			'CU' => __( 'Cuba', 'launch-forms' ),
			'CW' => __( 'Curaçao', 'launch-forms' ),
			'CY' => __( 'Cyprus', 'launch-forms' ),
			'CZ' => __( 'Czechia', 'launch-forms' ),
			'DK' => __( 'Denmark', 'launch-forms' ),
			'DJ' => __( 'Djibouti', 'launch-forms' ),
			'DM' => __( 'Dominica', 'launch-forms' ),
			'DO' => __( 'Dominican Republic', 'launch-forms' ),
			'EC' => __( 'Ecuador', 'launch-forms' ),
			'EG' => __( 'Egypt', 'launch-forms' ),
			'SV' => __( 'El Salvador', 'launch-forms' ),
			'GQ' => __( 'Equatorial Guinea', 'launch-forms' ),
			'ER' => __( 'Eritrea', 'launch-forms' ),
			'EE' => __( 'Estonia', 'launch-forms' ),
			'SZ' => __( 'Eswatini', 'launch-forms' ),
			'ET' => __( 'Ethiopia', 'launch-forms' ),
			'FK' => __( 'Falkland Islands', 'launch-forms' ),
			'FO' => __( 'Faroe Islands', 'launch-forms' ),
			'FJ' => __( 'Fiji', 'launch-forms' ),
			'FI' => __( 'Finland', 'launch-forms' ),
			'FR' => __( 'France', 'launch-forms' ),
			'GF' => __( 'French Guiana', 'launch-forms' ),
			'PF' => __( 'French Polynesia', 'launch-forms' ),
			'TF' => __( 'French Southern Territories', 'launch-forms' ),
			'GA' => __( 'Gabon', 'launch-forms' ),
			'GM' => __( 'Gambia', 'launch-forms' ),
			'GE' => __( 'Georgia', 'launch-forms' ),
			'DE' => __( 'Germany', 'launch-forms' ),
			'GH' => __( 'Ghana', 'launch-forms' ),
			'GI' => __( 'Gibraltar', 'launch-forms' ),
			'GR' => __( 'Greece', 'launch-forms' ),
			'GL' => __( 'Greenland', 'launch-forms' ),
			'GD' => __( 'Grenada', 'launch-forms' ),
			'GP' => __( 'Guadeloupe', 'launch-forms' ),
			'GU' => __( 'Guam', 'launch-forms' ),
			'GT' => __( 'Guatemala', 'launch-forms' ),
			'GG' => __( 'Guernsey', 'launch-forms' ),
			'GN' => __( 'Guinea', 'launch-forms' ),
			'GW' => __( 'Guinea-Bissau', 'launch-forms' ),
			'GY' => __( 'Guyana', 'launch-forms' ),
			'HT' => __( 'Haiti', 'launch-forms' ),
			'HM' => __( 'Heard Island and McDonald Islands', 'launch-forms' ),
			'VA' => __( 'Holy See', 'launch-forms' ),
			'HN' => __( 'Honduras', 'launch-forms' ),
			'HK' => __( 'Hong Kong', 'launch-forms' ),
			'HU' => __( 'Hungary', 'launch-forms' ),
			'IS' => __( 'Iceland', 'launch-forms' ),
			'IN' => __( 'India', 'launch-forms' ),
			'ID' => __( 'Indonesia', 'launch-forms' ),
			'IR' => __( 'Iran', 'launch-forms' ),
			'IQ' => __( 'Iraq', 'launch-forms' ),
			'IE' => __( 'Ireland', 'launch-forms' ),
			'IM' => __( 'Isle of Man', 'launch-forms' ),
			'IL' => __( 'Israel', 'launch-forms' ),
			'IT' => __( 'Italy', 'launch-forms' ),
			'JM' => __( 'Jamaica', 'launch-forms' ),
			'JP' => __( 'Japan', 'launch-forms' ),
			'JE' => __( 'Jersey', 'launch-forms' ),
			'JO' => __( 'Jordan', 'launch-forms' ),
			'KZ' => __( 'Kazakhstan', 'launch-forms' ),
			'KE' => __( 'Kenya', 'launch-forms' ),
			'KI' => __( 'Kiribati', 'launch-forms' ),
			'KP' => __( 'Korea (North)', 'launch-forms' ),
			'KR' => __( 'Korea (South)', 'launch-forms' ),
			'XK' => __( 'Kosovo', 'launch-forms' ),
			'KW' => __( 'Kuwait', 'launch-forms' ),
			'KG' => __( 'Kyrgyzstan', 'launch-forms' ),
			'LA' => __( 'Laos', 'launch-forms' ),
			'LV' => __( 'Latvia', 'launch-forms' ),
			'LB' => __( 'Lebanon', 'launch-forms' ),
			'LS' => __( 'Lesotho', 'launch-forms' ),
			'LR' => __( 'Liberia', 'launch-forms' ),
			'LY' => __( 'Libya', 'launch-forms' ),
			'LI' => __( 'Liechtenstein', 'launch-forms' ),
			'LT' => __( 'Lithuania', 'launch-forms' ),
			'LU' => __( 'Luxembourg', 'launch-forms' ),
			'MO' => __( 'Macao', 'launch-forms' ),
			'MG' => __( 'Madagascar', 'launch-forms' ),
			'MW' => __( 'Malawi', 'launch-forms' ),
			'MY' => __( 'Malaysia', 'launch-forms' ),
			'MV' => __( 'Maldives', 'launch-forms' ),
			'ML' => __( 'Mali', 'launch-forms' ),
			'MT' => __( 'Malta', 'launch-forms' ),
			'MH' => __( 'Marshall Islands', 'launch-forms' ),
			'MQ' => __( 'Martinique', 'launch-forms' ),
			'MR' => __( 'Mauritania', 'launch-forms' ),
			'MU' => __( 'Mauritius', 'launch-forms' ),
			'YT' => __( 'Mayotte', 'launch-forms' ),
			'MX' => __( 'Mexico', 'launch-forms' ),
			'FM' => __( 'Micronesia', 'launch-forms' ),
			'MD' => __( 'Moldova', 'launch-forms' ),
			'MC' => __( 'Monaco', 'launch-forms' ),
			'MN' => __( 'Mongolia', 'launch-forms' ),
			'ME' => __( 'Montenegro', 'launch-forms' ),
			'MS' => __( 'Montserrat', 'launch-forms' ),
			'MA' => __( 'Morocco', 'launch-forms' ),
			'MZ' => __( 'Mozambique', 'launch-forms' ),
			'MM' => __( 'Myanmar', 'launch-forms' ),
			'NA' => __( 'Namibia', 'launch-forms' ),
			'NR' => __( 'Nauru', 'launch-forms' ),
			'NP' => __( 'Nepal', 'launch-forms' ),
			'NL' => __( 'Netherlands', 'launch-forms' ),
			'NC' => __( 'New Caledonia', 'launch-forms' ),
			'NZ' => __( 'New Zealand', 'launch-forms' ),
			'NI' => __( 'Nicaragua', 'launch-forms' ),
			'NE' => __( 'Niger', 'launch-forms' ),
			'NG' => __( 'Nigeria', 'launch-forms' ),
			'NU' => __( 'Niue', 'launch-forms' ),
			'NF' => __( 'Norfolk Island', 'launch-forms' ),
			'MK' => __( 'North Macedonia', 'launch-forms' ),
			'MP' => __( 'Northern Mariana Islands', 'launch-forms' ),
			'NO' => __( 'Norway', 'launch-forms' ),
			'OM' => __( 'Oman', 'launch-forms' ),
			'PK' => __( 'Pakistan', 'launch-forms' ),
			'PW' => __( 'Palau', 'launch-forms' ),
			'PS' => __( 'Palestine', 'launch-forms' ),
			'PA' => __( 'Panama', 'launch-forms' ),
			'PG' => __( 'Papua New Guinea', 'launch-forms' ),
			'PY' => __( 'Paraguay', 'launch-forms' ),
			'PE' => __( 'Peru', 'launch-forms' ),
			'PH' => __( 'Philippines', 'launch-forms' ),
			'PN' => __( 'Pitcairn', 'launch-forms' ),
			'PL' => __( 'Poland', 'launch-forms' ),
			'PT' => __( 'Portugal', 'launch-forms' ),
			'PR' => __( 'Puerto Rico', 'launch-forms' ),
			'QA' => __( 'Qatar', 'launch-forms' ),
			'RE' => __( 'Réunion', 'launch-forms' ),
			'RO' => __( 'Romania', 'launch-forms' ),
			'RU' => __( 'Russia', 'launch-forms' ),
			'RW' => __( 'Rwanda', 'launch-forms' ),
			'BL' => __( 'Saint Barthélemy', 'launch-forms' ),
			'SH' => __( 'Saint Helena, Ascension and Tristan da Cunha', 'launch-forms' ),
			'KN' => __( 'Saint Kitts and Nevis', 'launch-forms' ),
			'LC' => __( 'Saint Lucia', 'launch-forms' ),
			'MF' => __( 'Saint Martin', 'launch-forms' ),
			'PM' => __( 'Saint Pierre and Miquelon', 'launch-forms' ),
			'VC' => __( 'Saint Vincent and the Grenadines', 'launch-forms' ),
			'WS' => __( 'Samoa', 'launch-forms' ),
			'SM' => __( 'San Marino', 'launch-forms' ),
			'ST' => __( 'Sao Tome and Principe', 'launch-forms' ),
			'SA' => __( 'Saudi Arabia', 'launch-forms' ),
			'SN' => __( 'Senegal', 'launch-forms' ),
			'RS' => __( 'Serbia', 'launch-forms' ),
			'SC' => __( 'Seychelles', 'launch-forms' ),
			'SL' => __( 'Sierra Leone', 'launch-forms' ),
			'SG' => __( 'Singapore', 'launch-forms' ),
			'SX' => __( 'Sint Maarten', 'launch-forms' ),
			'SK' => __( 'Slovakia', 'launch-forms' ),
			'SI' => __( 'Slovenia', 'launch-forms' ),
			'SB' => __( 'Solomon Islands', 'launch-forms' ),
			'SO' => __( 'Somalia', 'launch-forms' ),
			'ZA' => __( 'South Africa', 'launch-forms' ),
			'GS' => __( 'South Georgia and the South Sandwich Islands', 'launch-forms' ),
			'SS' => __( 'South Sudan', 'launch-forms' ),
			'ES' => __( 'Spain', 'launch-forms' ),
			'LK' => __( 'Sri Lanka', 'launch-forms' ),
			'SD' => __( 'Sudan', 'launch-forms' ),
			'SR' => __( 'Suriname', 'launch-forms' ),
			'SJ' => __( 'Svalbard and Jan Mayen', 'launch-forms' ),
			'SE' => __( 'Sweden', 'launch-forms' ),
			'CH' => __( 'Switzerland', 'launch-forms' ),
			'SY' => __( 'Syria', 'launch-forms' ),
			'TW' => __( 'Taiwan', 'launch-forms' ),
			'TJ' => __( 'Tajikistan', 'launch-forms' ),
			'TZ' => __( 'Tanzania', 'launch-forms' ),
			'TH' => __( 'Thailand', 'launch-forms' ),
			'TL' => __( 'Timor-Leste', 'launch-forms' ),
			'TG' => __( 'Togo', 'launch-forms' ),
			'TK' => __( 'Tokelau', 'launch-forms' ),
			'TO' => __( 'Tonga', 'launch-forms' ),
			'TT' => __( 'Trinidad and Tobago', 'launch-forms' ),
			'TN' => __( 'Tunisia', 'launch-forms' ),
			'TR' => __( 'Türkiye', 'launch-forms' ),
			'TM' => __( 'Turkmenistan', 'launch-forms' ),
			'TC' => __( 'Turks and Caicos Islands', 'launch-forms' ),
			'TV' => __( 'Tuvalu', 'launch-forms' ),
			'UG' => __( 'Uganda', 'launch-forms' ),
			'UA' => __( 'Ukraine', 'launch-forms' ),
			'AE' => __( 'United Arab Emirates', 'launch-forms' ),
			'GB' => __( 'United Kingdom', 'launch-forms' ),
			'US' => __( 'United States', 'launch-forms' ),
			'UM' => __( 'United States Minor Outlying Islands', 'launch-forms' ),
			'UY' => __( 'Uruguay', 'launch-forms' ),
			'UZ' => __( 'Uzbekistan', 'launch-forms' ),
			'VU' => __( 'Vanuatu', 'launch-forms' ),
			'VE' => __( 'Venezuela', 'launch-forms' ),
			'VN' => __( 'Vietnam', 'launch-forms' ),
			'VG' => __( 'Virgin Islands (British)', 'launch-forms' ),
			'VI' => __( 'Virgin Islands (U.S.)', 'launch-forms' ),
			'WF' => __( 'Wallis and Futuna', 'launch-forms' ),
			'EH' => __( 'Western Sahara', 'launch-forms' ),
			'YE' => __( 'Yemen', 'launch-forms' ),
			'ZM' => __( 'Zambia', 'launch-forms' ),
			'ZW' => __( 'Zimbabwe', 'launch-forms' ),
		);

		/**
		 * Filters the countries offered by Country fields.
		 *
		 * Remove entries to limit the choice, or rename them. Keys are ISO 3166-1 alpha-2 codes.
		 *
		 * @param array<string,string> $countries Code => name.
		 */
		$countries = (array) apply_filters( 'launch_forms_countries', $countries );
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
