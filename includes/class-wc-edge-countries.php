<?php
/**
 * ISO 3166-1 country codes.
 *
 * @package Deens_Edge_Payments_For_WooCommerce
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'WC_EDGE_TESTING' ) ) {
		exit;
	}
}

/**
 * Converts the alpha-2 country codes WooCommerce stores into the alpha-3 codes
 * Edge expects.
 *
 * WordPress has no such table and WooCommerce's own is behind a vendored
 * library in an internal namespace with no compatibility promise, so the data
 * lives here. It is the complete ISO 3166-1 set, which covers every code
 * WooCommerce can produce.
 */
final class WC_Edge_Countries {

	/**
	 * Alpha-2 to alpha-3, keyed by uppercase alpha-2.
	 *
	 * @var array<string,string>
	 */
	const ALPHA3 = array(
		'AD' => 'AND',
		'AE' => 'ARE',
		'AF' => 'AFG',
		'AG' => 'ATG',
		'AI' => 'AIA',
		'AL' => 'ALB',
		'AM' => 'ARM',
		'AO' => 'AGO',
		'AQ' => 'ATA',
		'AR' => 'ARG',
		'AS' => 'ASM',
		'AT' => 'AUT',
		'AU' => 'AUS',
		'AW' => 'ABW',
		'AX' => 'ALA',
		'AZ' => 'AZE',
		'BA' => 'BIH',
		'BB' => 'BRB',
		'BD' => 'BGD',
		'BE' => 'BEL',
		'BF' => 'BFA',
		'BG' => 'BGR',
		'BH' => 'BHR',
		'BI' => 'BDI',
		'BJ' => 'BEN',
		'BL' => 'BLM',
		'BM' => 'BMU',
		'BN' => 'BRN',
		'BO' => 'BOL',
		'BQ' => 'BES',
		'BR' => 'BRA',
		'BS' => 'BHS',
		'BT' => 'BTN',
		'BV' => 'BVT',
		'BW' => 'BWA',
		'BY' => 'BLR',
		'BZ' => 'BLZ',
		'CA' => 'CAN',
		'CC' => 'CCK',
		'CD' => 'COD',
		'CF' => 'CAF',
		'CG' => 'COG',
		'CH' => 'CHE',
		'CI' => 'CIV',
		'CK' => 'COK',
		'CL' => 'CHL',
		'CM' => 'CMR',
		'CN' => 'CHN',
		'CO' => 'COL',
		'CR' => 'CRI',
		'CU' => 'CUB',
		'CV' => 'CPV',
		'CW' => 'CUW',
		'CX' => 'CXR',
		'CY' => 'CYP',
		'CZ' => 'CZE',
		'DE' => 'DEU',
		'DJ' => 'DJI',
		'DK' => 'DNK',
		'DM' => 'DMA',
		'DO' => 'DOM',
		'DZ' => 'DZA',
		'EC' => 'ECU',
		'EE' => 'EST',
		'EG' => 'EGY',
		'EH' => 'ESH',
		'ER' => 'ERI',
		'ES' => 'ESP',
		'ET' => 'ETH',
		'FI' => 'FIN',
		'FJ' => 'FJI',
		'FK' => 'FLK',
		'FM' => 'FSM',
		'FO' => 'FRO',
		'FR' => 'FRA',
		'GA' => 'GAB',
		'GB' => 'GBR',
		'GD' => 'GRD',
		'GE' => 'GEO',
		'GF' => 'GUF',
		'GG' => 'GGY',
		'GH' => 'GHA',
		'GI' => 'GIB',
		'GL' => 'GRL',
		'GM' => 'GMB',
		'GN' => 'GIN',
		'GP' => 'GLP',
		'GQ' => 'GNQ',
		'GR' => 'GRC',
		'GS' => 'SGS',
		'GT' => 'GTM',
		'GU' => 'GUM',
		'GW' => 'GNB',
		'GY' => 'GUY',
		'HK' => 'HKG',
		'HM' => 'HMD',
		'HN' => 'HND',
		'HR' => 'HRV',
		'HT' => 'HTI',
		'HU' => 'HUN',
		'ID' => 'IDN',
		'IE' => 'IRL',
		'IL' => 'ISR',
		'IM' => 'IMN',
		'IN' => 'IND',
		'IO' => 'IOT',
		'IQ' => 'IRQ',
		'IR' => 'IRN',
		'IS' => 'ISL',
		'IT' => 'ITA',
		'JE' => 'JEY',
		'JM' => 'JAM',
		'JO' => 'JOR',
		'JP' => 'JPN',
		'KE' => 'KEN',
		'KG' => 'KGZ',
		'KH' => 'KHM',
		'KI' => 'KIR',
		'KM' => 'COM',
		'KN' => 'KNA',
		'KP' => 'PRK',
		'KR' => 'KOR',
		'KW' => 'KWT',
		'KY' => 'CYM',
		'KZ' => 'KAZ',
		'LA' => 'LAO',
		'LB' => 'LBN',
		'LC' => 'LCA',
		'LI' => 'LIE',
		'LK' => 'LKA',
		'LR' => 'LBR',
		'LS' => 'LSO',
		'LT' => 'LTU',
		'LU' => 'LUX',
		'LV' => 'LVA',
		'LY' => 'LBY',
		'MA' => 'MAR',
		'MC' => 'MCO',
		'MD' => 'MDA',
		'ME' => 'MNE',
		'MF' => 'MAF',
		'MG' => 'MDG',
		'MH' => 'MHL',
		'MK' => 'MKD',
		'ML' => 'MLI',
		'MM' => 'MMR',
		'MN' => 'MNG',
		'MO' => 'MAC',
		'MP' => 'MNP',
		'MQ' => 'MTQ',
		'MR' => 'MRT',
		'MS' => 'MSR',
		'MT' => 'MLT',
		'MU' => 'MUS',
		'MV' => 'MDV',
		'MW' => 'MWI',
		'MX' => 'MEX',
		'MY' => 'MYS',
		'MZ' => 'MOZ',
		'NA' => 'NAM',
		'NC' => 'NCL',
		'NE' => 'NER',
		'NF' => 'NFK',
		'NG' => 'NGA',
		'NI' => 'NIC',
		'NL' => 'NLD',
		'NO' => 'NOR',
		'NP' => 'NPL',
		'NR' => 'NRU',
		'NU' => 'NIU',
		'NZ' => 'NZL',
		'OM' => 'OMN',
		'PA' => 'PAN',
		'PE' => 'PER',
		'PF' => 'PYF',
		'PG' => 'PNG',
		'PH' => 'PHL',
		'PK' => 'PAK',
		'PL' => 'POL',
		'PM' => 'SPM',
		'PN' => 'PCN',
		'PR' => 'PRI',
		'PS' => 'PSE',
		'PT' => 'PRT',
		'PW' => 'PLW',
		'PY' => 'PRY',
		'QA' => 'QAT',
		'RE' => 'REU',
		'RO' => 'ROU',
		'RS' => 'SRB',
		'RU' => 'RUS',
		'RW' => 'RWA',
		'SA' => 'SAU',
		'SB' => 'SLB',
		'SC' => 'SYC',
		'SD' => 'SDN',
		'SE' => 'SWE',
		'SG' => 'SGP',
		'SH' => 'SHN',
		'SI' => 'SVN',
		'SJ' => 'SJM',
		'SK' => 'SVK',
		'SL' => 'SLE',
		'SM' => 'SMR',
		'SN' => 'SEN',
		'SO' => 'SOM',
		'SR' => 'SUR',
		'SS' => 'SSD',
		'ST' => 'STP',
		'SV' => 'SLV',
		'SX' => 'SXM',
		'SY' => 'SYR',
		'SZ' => 'SWZ',
		'TC' => 'TCA',
		'TD' => 'TCD',
		'TF' => 'ATF',
		'TG' => 'TGO',
		'TH' => 'THA',
		'TJ' => 'TJK',
		'TK' => 'TKL',
		'TL' => 'TLS',
		'TM' => 'TKM',
		'TN' => 'TUN',
		'TO' => 'TON',
		'TR' => 'TUR',
		'TT' => 'TTO',
		'TV' => 'TUV',
		'TW' => 'TWN',
		'TZ' => 'TZA',
		'UA' => 'UKR',
		'UG' => 'UGA',
		'UM' => 'UMI',
		'US' => 'USA',
		'UY' => 'URY',
		'UZ' => 'UZB',
		'VA' => 'VAT',
		'VC' => 'VCT',
		'VE' => 'VEN',
		'VG' => 'VGB',
		'VI' => 'VIR',
		'VN' => 'VNM',
		'VU' => 'VUT',
		'WF' => 'WLF',
		'WS' => 'WSM',
		'XK' => 'XKX',
		'YE' => 'YEM',
		'YT' => 'MYT',
		'ZA' => 'ZAF',
		'ZM' => 'ZMB',
		'ZW' => 'ZWE',
	);

	/**
	 * Convert an alpha-2 country code to alpha-3.
	 *
	 * Returns an empty string for anything unrecognised rather than throwing:
	 * an unknown country is a checkout validation problem for the caller to
	 * phrase, not an exceptional condition.
	 *
	 * @param string $alpha2 Two letter country code.
	 * @return string Alpha-3 code, or '' when unknown.
	 */
	public static function to_alpha3( $alpha2 ) {
		if ( ! is_scalar( $alpha2 ) ) {
			return '';
		}

		$alpha2 = strtoupper( trim( (string) $alpha2 ) );

		return isset( self::ALPHA3[ $alpha2 ] ) ? self::ALPHA3[ $alpha2 ] : '';
	}

	/**
	 * Whether a value is a country code this table knows in alpha-3 form.
	 *
	 * @param string $alpha3 Three letter country code.
	 * @return bool
	 */
	public static function is_alpha3( $alpha3 ) {
		if ( ! is_scalar( $alpha3 ) ) {
			return false;
		}

		return in_array( strtoupper( trim( (string) $alpha3 ) ), self::ALPHA3, true );
	}
}
