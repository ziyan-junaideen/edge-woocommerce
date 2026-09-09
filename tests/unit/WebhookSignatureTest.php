<?php
/**
 * @package WooCommerce Edge Payments Gateway
 */

namespace EdgePayments\EdgeWoocommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC_Edge_Webhook_Signature;

/**
 * @covers \WC_Edge_Webhook_Signature
 */
class WebhookSignatureTest extends TestCase {

	private const SECRET = 'GShUj7ExampleSubscriptionSigningSecretValue';

	private function sign( string $body, int $timestamp, string $secret = self::SECRET ): string {
		return 't=' . $timestamp . ',v3=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	public function test_parses_a_well_formed_header(): void {
		$parsed = WC_Edge_Webhook_Signature::parse( $this->sign( '{"a":1}', 1788000000 ) );

		$this->assertIsArray( $parsed );
		$this->assertSame( 1788000000, $parsed['timestamp'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $parsed['signature'] );
	}

	public function test_accepts_an_uppercase_digest(): void {
		$header = 't=1788000000,v3=' . strtoupper( hash_hmac( 'sha256', '1788000000.x', self::SECRET ) );

		$this->assertTrue(
			WC_Edge_Webhook_Signature::matches(
				WC_Edge_Webhook_Signature::parse( $header ),
				'x',
				self::SECRET
			)
		);
	}

	/**
	 * Edge documents rolling out a new scheme by emitting it alongside v3 for a
	 * migration window, so an unknown token must not invalidate the header.
	 */
	public function test_ignores_tokens_it_does_not_know(): void {
		$valid  = WC_Edge_Webhook_Signature::parse( $this->sign( 'body', 1788000000 ) );
		$header = $this->sign( 'body', 1788000000 ) . ',v4=' . str_repeat( 'f', 64 );

		$this->assertSame( $valid, WC_Edge_Webhook_Signature::parse( $header ) );
	}

	/**
	 * @dataProvider unusable_headers
	 */
	public function test_refuses_an_unusable_header( $header ): void {
		$this->assertNull( WC_Edge_Webhook_Signature::parse( $header ) );
	}

	public static function unusable_headers(): array {
		return array(
			'empty'              => array( '' ),
			'whitespace'         => array( "  \n" ),
			'not a string'       => array( null ),
			'no timestamp'       => array( 'v3=' . str_repeat( 'a', 64 ) ),
			'no digest'          => array( 't=1788000000' ),
			'digest too short'   => array( 't=1788000000,v3=' . str_repeat( 'a', 63 ) ),
			'digest not hex'     => array( 't=1788000000,v3=' . str_repeat( 'z', 64 ) ),
			'timestamp not int'  => array( 't=later,v3=' . str_repeat( 'a', 64 ) ),
			'legacy header'      => array( 'kRR2ktRxYQqIDLPZbSlPTOZFRIU=' ),
			'only a v4 token'    => array( 't=1788000000,v4=' . str_repeat( 'a', 64 ) ),
		);
	}

	public function test_a_signature_over_the_body_verifies(): void {
		$body   = '{"data":{"id":"evt_1","attributes":{"slug":"updated"}}}';
		$parsed = WC_Edge_Webhook_Signature::parse( $this->sign( $body, 1788000000 ) );

		$this->assertTrue( WC_Edge_Webhook_Signature::matches( $parsed, $body, self::SECRET ) );
	}

	public function test_a_changed_body_does_not_verify(): void {
		$parsed = WC_Edge_Webhook_Signature::parse( $this->sign( '{"amount_cents":100}', 1788000000 ) );

		$this->assertFalse(
			WC_Edge_Webhook_Signature::matches( $parsed, '{"amount_cents":9999}', self::SECRET )
		);
	}

	/**
	 * The timestamp is inside the signed payload, so it cannot be edited without
	 * invalidating the digest.
	 */
	public function test_a_changed_timestamp_does_not_verify(): void {
		$parsed              = WC_Edge_Webhook_Signature::parse( $this->sign( 'body', 1788000000 ) );
		$parsed['timestamp'] = 1788000001;

		$this->assertFalse( WC_Edge_Webhook_Signature::matches( $parsed, 'body', self::SECRET ) );
	}

	public function test_another_subscriptions_secret_does_not_verify(): void {
		$parsed = WC_Edge_Webhook_Signature::parse( $this->sign( 'body', 1788000000 ) );

		$this->assertFalse( WC_Edge_Webhook_Signature::matches( $parsed, 'body', 'a-different-secret' ) );
	}

	public function test_an_empty_secret_never_verifies(): void {
		$parsed = WC_Edge_Webhook_Signature::parse( $this->sign( 'body', 1788000000, '' ) );

		$this->assertFalse( WC_Edge_Webhook_Signature::matches( $parsed, 'body', '' ) );
	}

	public function test_an_empty_body_still_verifies(): void {
		$parsed = WC_Edge_Webhook_Signature::parse( $this->sign( '', 1788000000 ) );

		$this->assertTrue( WC_Edge_Webhook_Signature::matches( $parsed, '', self::SECRET ) );
	}

	public function test_freshness_accepts_clock_skew_in_both_directions(): void {
		$now = 1788000000;

		$this->assertTrue( WC_Edge_Webhook_Signature::is_fresh( $now, $now ) );
		$this->assertTrue( WC_Edge_Webhook_Signature::is_fresh( $now - 299, $now ) );
		$this->assertTrue( WC_Edge_Webhook_Signature::is_fresh( $now + 299, $now ) );
	}

	public function test_freshness_rejects_a_stale_delivery(): void {
		$now = 1788000000;

		$this->assertFalse( WC_Edge_Webhook_Signature::is_fresh( $now - 301, $now ) );
		$this->assertFalse( WC_Edge_Webhook_Signature::is_fresh( $now + 301, $now ) );
	}
}
