<?php
/**
 * @package Deens_Edge_Payments_For_WooCommerce
 */

namespace EdgePayments\EdgeWoocommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC_Edge_Countries;

/**
 * @covers \WC_Edge_Countries
 */
class CountriesTest extends TestCase {

	/**
	 * @dataProvider provide_known_countries
	 */
	public function test_converts_alpha2_to_alpha3( string $alpha2, string $expected ): void {
		$this->assertSame( $expected, WC_Edge_Countries::to_alpha3( $alpha2 ) );
	}

	public function provide_known_countries(): array {
		return array(
			'united states' => array( 'US', 'USA' ),
			'united kingdom' => array( 'GB', 'GBR' ),
			'australia'     => array( 'AU', 'AUS' ),
			'canada'        => array( 'CA', 'CAN' ),
			'sri lanka'     => array( 'LK', 'LKA' ),
			'germany'       => array( 'DE', 'DEU' ),
			// Codes whose alpha-3 is not a simple extension of the alpha-2.
			'switzerland'   => array( 'CH', 'CHE' ),
			'netherlands'   => array( 'NL', 'NLD' ),
		);
	}

	public function test_accepts_lowercase_and_padding(): void {
		$this->assertSame( 'USA', WC_Edge_Countries::to_alpha3( '  us  ' ) );
	}

	/**
	 * @dataProvider provide_unknown_values
	 *
	 * @param mixed $value Value to convert.
	 */
	public function test_returns_empty_for_anything_unrecognised( $value ): void {
		$this->assertSame( '', WC_Edge_Countries::to_alpha3( $value ) );
	}

	public function provide_unknown_values(): array {
		return array(
			'blank'        => array( '' ),
			'whitespace'   => array( '   ' ),
			'not a country' => array( 'ZZ' ),
			// Alpha-3 in, alpha-3 out is the caller's job, not this table's.
			'alpha-3'      => array( 'USA' ),
			'null'         => array( null ),
			'array'        => array( array( 'US' ) ),
		);
	}

	public function test_recognises_alpha3_codes(): void {
		$this->assertTrue( WC_Edge_Countries::is_alpha3( 'USA' ) );
		$this->assertTrue( WC_Edge_Countries::is_alpha3( 'gbr' ) );
		$this->assertFalse( WC_Edge_Countries::is_alpha3( 'ZZZ' ) );
		$this->assertFalse( WC_Edge_Countries::is_alpha3( 'US' ) );
	}

	/**
	 * The table stands in for a library, so it has to be complete rather than
	 * merely plausible: 249 officially assigned codes plus the reserved ones
	 * WooCommerce also offers.
	 */
	public function test_covers_the_whole_iso_3166_set(): void {
		$this->assertGreaterThanOrEqual( 249, count( WC_Edge_Countries::ALPHA3 ) );

		foreach ( WC_Edge_Countries::ALPHA3 as $alpha2 => $alpha3 ) {
			$this->assertMatchesRegularExpression( '/^[A-Z]{2}$/', $alpha2 );
			$this->assertMatchesRegularExpression( '/^[A-Z]{3}$/', $alpha3 );
		}

		$this->assertSame(
			count( WC_Edge_Countries::ALPHA3 ),
			count( array_unique( WC_Edge_Countries::ALPHA3 ) ),
			'alpha-3 codes must be unique'
		);
	}
}
