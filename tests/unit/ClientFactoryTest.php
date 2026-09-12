<?php
/**
 * @package Deens_Edge_Payments_For_WooCommerce
 */

namespace EdgePayments\EdgeWoocommerce\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WC_Edge_API_Client;
use WC_Edge_Client_Factory;

/**
 * @covers \WC_Edge_Client_Factory
 */
class ClientFactoryTest extends TestCase {

	private const SECRET      = 'ept_sandbox_sAbCdEfGhIjKlMnOpQrStUvWxYz0123456789';
	private const PUBLISHABLE = 'ept_sandbox_bAbCdEfGhIjKlMnOpQrStUvWxYz0123456789';

	/**
	 * A publishable key is accepted by the API as a bearer token, but with
	 * different permissions - so the failure would surface much later and far
	 * from its cause. Refuse it at the boundary instead.
	 */
	public function test_refuses_to_authenticate_with_a_publishable_key(): void {
		$this->expectException( InvalidArgumentException::class );
		WC_Edge_Client_Factory::client( self::PUBLISHABLE );
	}

	public function test_refuses_garbage_keys(): void {
		$this->expectException( InvalidArgumentException::class );
		WC_Edge_Client_Factory::client( 'not-a-key' );
	}

	public function test_builds_a_client_pointed_at_the_v2_api(): void {
		$client = WC_Edge_Client_Factory::client( self::SECRET );

		$this->assertInstanceOf( WC_Edge_API_Client::class, $client );
		$this->assertSame( 'https://api.tryedge.io/v2/', $client->base_uri() );
	}

	public function test_defaults_to_the_v2_production_api(): void {
		$this->assertSame( 'https://api.tryedge.io/v2/', WC_Edge_Client_Factory::api_base_uri() );
		$this->assertSame( 'https://dashboard.tryedge.io', WC_Edge_Client_Factory::dashboard_host() );
	}

	/**
	 * @dataProvider provide_production_hosts
	 */
	public function test_recognises_production_hosts( string $uri, bool $expected ): void {
		$this->assertSame( $expected, WC_Edge_Client_Factory::is_production_host( $uri ) );
	}

	public function provide_production_hosts(): array {
		return array(
			'api'              => array( 'https://api.tryedge.io/v2/', true ),
			'dashboard'        => array( 'https://dashboard.tryedge.io', true ),
			'apex'             => array( 'https://tryedge.io', true ),
			'uppercase'        => array( 'https://API.TRYEDGE.IO/v2/', true ),
			'local dev'        => array( 'https://api.tryedge.test:4001/v2/', false ),
			'localhost'        => array( 'http://localhost:4001', false ),
			// Must not be fooled by a lookalike domain that merely ends in the
			// same characters.
			'suffix lookalike' => array( 'https://api.nottryedge.io', false ),
			'domain in path'   => array( 'https://evil.example/tryedge.io', false ),
			'unparseable'      => array( 'not a url', true ),
			'empty'            => array( '', true ),
		);
	}

	/**
	 * Without the opt-in constant, verification is always on.
	 */
	public function test_tls_verification_is_on_by_default(): void {
		$this->assertTrue( WC_Edge_Client_Factory::should_verify_tls() );
	}

	/**
	 * The API marks User-Agent required on every operation, and this is the
	 * whole header value rather than a suffix, so it has to identify us.
	 */
	public function test_user_agent_identifies_the_integration(): void {
		$this->assertStringContainsString(
			'EdgeWooCommerce/' . WC_EDGE_VERSION,
			WC_Edge_Client_Factory::user_agent()
		);
	}
}
