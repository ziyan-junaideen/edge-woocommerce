<?php
/**
 * @package WooCommerce Edge Payments Gateway
 */

namespace EdgePayments\EdgeWoocommerce\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WC_Edge_API_Client;
use WC_Edge_API_Exception;
use WC_Edge_Test_Transport;

/**
 * @covers \WC_Edge_API_Client
 */
class ApiClientTest extends TestCase {

	private const SECRET = 'ept_sandbox_sAbCdEfGhIjKlMnOpQrStUvWxYz0123456789';

	protected function setUp(): void {
		parent::setUp();
		WC_Edge_Test_Transport::reset();
	}

	protected function tearDown(): void {
		WC_Edge_Test_Transport::reset();
		parent::tearDown();
	}

	private function client( array $args = array() ): WC_Edge_API_Client {
		return new WC_Edge_API_Client(
			self::SECRET,
			array_merge(
				array(
					'user_agent' => 'EdgeWooCommerce/2.1.0',
					'verify_tls' => true,
				),
				$args
			)
		);
	}

	/**
	 * The end-to-end check that matters: a configured client must emit a
	 * correctly shaped Edge v2 request. Asserts on what reaches WordPress.
	 */
	public function test_produces_a_correctly_shaped_v2_request(): void {
		WC_Edge_Test_Transport::respond( 201, '{"data":{"id":"abc","type":"payment_demands"}}' );

		$this->client()->create(
			'payment_demands',
			array(
				'data' => array(
					'type'       => 'payment_demands',
					'attributes' => array( 'amount_cents' => 2500 ),
				),
			)
		);

		$request = WC_Edge_Test_Transport::last();

		$this->assertSame( 'https://api.tryedge.io/v2/payment_demands', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 'Bearer ' . self::SECRET, $request['args']['headers']['Authorization'] );
		$this->assertSame( 'application/vnd.api+json', $request['args']['headers']['Accept'] );
		$this->assertSame( 'application/vnd.api+json', $request['args']['headers']['Content-Type'] );
		$this->assertSame( 'EdgeWooCommerce/2.1.0', $request['args']['user-agent'] );
		$this->assertSame( 'body', $request['args']['data_format'] );

		$body = json_decode( $request['args']['body'], true );
		$this->assertSame( 'payment_demands', $body['data']['type'] );
		$this->assertSame( 2500, $body['data']['attributes']['amount_cents'] );
	}

	/**
	 * A key pasted into a settings screen routinely arrives with surrounding
	 * whitespace, and a trailing newline is not a valid header value.
	 */
	public function test_trims_the_api_key(): void {
		WC_Edge_Test_Transport::respond( 200, '{"data":[]}' );

		( new WC_Edge_API_Client( "  " . self::SECRET . "\n" ) )->get( 'customers' );

		$this->assertSame(
			'Bearer ' . self::SECRET,
			WC_Edge_Test_Transport::last()['args']['headers']['Authorization']
		);
	}

	/**
	 * A GET carries no body, so it must not claim a content type.
	 */
	public function test_get_requests_carry_no_content_type(): void {
		WC_Edge_Test_Transport::respond( 200, '{"data":[]}' );

		$this->client()->get( 'customers' );

		$request = WC_Edge_Test_Transport::last();

		$this->assertArrayNotHasKey( 'Content-Type', $request['args']['headers'] );
		$this->assertArrayNotHasKey( 'body', $request['args'] );
	}

	/**
	 * JSON:API nests its parameters, and PHP's default encoding is RFC 1738
	 * where Guzzle used RFC 3986 - a space must be %20, not +.
	 */
	public function test_encodes_nested_query_parameters(): void {
		WC_Edge_Test_Transport::respond( 200, '{"data":[]}' );

		$this->client()->get(
			'webhook_subscriptions',
			array(
				'page'   => array( 'size' => 100 ),
				'filter' => array( 'description' => 'a b' ),
			)
		);

		$this->assertSame(
			'https://api.tryedge.io/v2/webhook_subscriptions?page%5Bsize%5D=100&filter%5Bdescription%5D=a%20b',
			WC_Edge_Test_Transport::last()['url']
		);
	}

	public function test_omits_the_query_string_when_there_is_nothing_to_send(): void {
		WC_Edge_Test_Transport::respond( 200, '{"data":[]}' );

		$this->client()->get( 'customers' );

		$this->assertSame( 'https://api.tryedge.io/v2/customers', WC_Edge_Test_Transport::last()['url'] );
	}

	/**
	 * Confirm targets PATCH .../confirm with a body carrying a matching id, and
	 * an object rather than an array for empty attributes.
	 */
	public function test_confirm_targets_the_confirm_route(): void {
		WC_Edge_Test_Transport::respond( 200, '{"data":{"id":"abc","type":"payment_demands"}}' );

		$this->client()->confirm( 'payment_demands', 'abc' );

		$request = WC_Edge_Test_Transport::last();

		$this->assertSame( 'PATCH', $request['args']['method'] );
		$this->assertSame( 'https://api.tryedge.io/v2/payment_demands/abc/confirm', $request['url'] );
		$this->assertSame(
			'{"data":{"id":"abc","type":"payment_demands","attributes":{}}}',
			$request['args']['body'],
			'empty attributes must encode as an object'
		);
	}

	/**
	 * Every request carries the merchant's secret key, so a redirect would hand
	 * it to wherever the redirect points.
	 */
	public function test_never_follows_redirects(): void {
		WC_Edge_Test_Transport::respond( 200, '{"data":[]}' );

		$this->client()->get( 'customers' );

		$this->assertSame( 0, WC_Edge_Test_Transport::last()['args']['redirection'] );
	}

	/**
	 * Guards against an unbounded request holding a checkout open until PHP's
	 * own limit.
	 */
	public function test_bounds_the_request_with_a_timeout(): void {
		WC_Edge_Test_Transport::respond( 200, '{"data":[]}' );

		$this->client()->get( 'customers' );

		$request = WC_Edge_Test_Transport::last();

		$this->assertSame( WC_Edge_API_Client::TIMEOUT, $request['args']['timeout'] );
		$this->assertTrue( $request['args']['sslverify'], 'TLS verification must reach the transport' );
	}

	public function test_tls_verification_reaches_the_transport_when_waived(): void {
		WC_Edge_Test_Transport::respond( 200, '{"data":[]}' );

		$this->client( array( 'verify_tls' => false ) )->get( 'customers' );

		$this->assertFalse( WC_Edge_Test_Transport::last()['args']['sslverify'] );
	}

	/**
	 * A bare host gains the version segment; an explicit root is left alone.
	 *
	 * @dataProvider provide_base_uris
	 */
	public function test_normalises_the_base_uri( string $configured, string $expected ): void {
		$this->assertSame( $expected, WC_Edge_API_Client::normalize_base_uri( $configured ) );
	}

	public function provide_base_uris(): array {
		return array(
			'bare host'      => array( 'https://api.tryedge.test:4001', 'https://api.tryedge.test:4001/v2/' ),
			'explicit root'  => array( 'https://api.tryedge.io/v2/', 'https://api.tryedge.io/v2/' ),
			'no slash'       => array( 'https://api.tryedge.io/v2', 'https://api.tryedge.io/v2/' ),
			'padded'         => array( '  https://api.tryedge.io/v2/  ', 'https://api.tryedge.io/v2/' ),
		);
	}

	/**
	 * @dataProvider provide_bad_base_uris
	 */
	public function test_rejects_unusable_base_uris( string $configured ): void {
		$this->expectException( InvalidArgumentException::class );
		WC_Edge_API_Client::normalize_base_uri( $configured );
	}

	public function provide_bad_base_uris(): array {
		return array(
			'no scheme'    => array( 'api.tryedge.io/v2' ),
			'wrong scheme' => array( 'ftp://api.tryedge.io/v2' ),
			'empty'        => array( '' ),
		);
	}

	/**
	 * The version prefix must survive an endpoint written with a leading slash,
	 * which is what RFC 3986 resolution would silently drop.
	 */
	public function test_a_leading_slash_does_not_escape_the_version_root(): void {
		WC_Edge_Test_Transport::respond( 200, '{"data":[]}' );

		$this->client()->get( '/customers' );

		$this->assertSame( 'https://api.tryedge.io/v2/customers', WC_Edge_Test_Transport::last()['url'] );
	}

	/**
	 * A protocol-relative endpoint must become a path segment, never a host.
	 */
	public function test_a_protocol_relative_endpoint_cannot_escape(): void {
		WC_Edge_Test_Transport::respond( 200, '{"data":[]}' );

		$this->client()->get( '//evil.test/collect' );

		$this->assertSame( 'https://api.tryedge.io/v2/evil.test/collect', WC_Edge_Test_Transport::last()['url'] );
	}

	/**
	 * An absolute endpoint pointing anywhere else would hand the secret key to
	 * whoever supplied it.
	 */
	public function test_refuses_to_send_credentials_cross_origin(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->client()->get( 'https://evil.test/collect' );
	}

	public function test_allows_an_absolute_url_on_the_configured_origin(): void {
		WC_Edge_Test_Transport::respond( 200, '{"data":[]}' );

		$this->client()->get( 'https://api.tryedge.io/v2/customers?page%5Bsize%5D=25' );

		$this->assertSame(
			'https://api.tryedge.io/v2/customers?page%5Bsize%5D=25',
			WC_Edge_Test_Transport::last()['url']
		);
	}

	/**
	 * Status 0 is what tells a request that never landed from one that was
	 * rejected, and the whole ambiguous-confirm resolution depends on it.
	 */
	public function test_a_transport_failure_reports_status_zero(): void {
		WC_Edge_Test_Transport::fail( 'Connection timed out' );

		try {
			$this->client()->get( 'customers' );
			$this->fail( 'Expected WC_Edge_API_Exception.' );
		} catch ( WC_Edge_API_Exception $e ) {
			$this->assertSame( 0, $e->get_status_code() );
			$this->assertSame( 'Connection timed out', $e->getMessage() );
			$this->assertSame( array(), $e->get_errors() );
		}
	}

	/**
	 * A changeset failure comes back as a JSON:API error document, and the
	 * pointer is what makes the message useful to a shopper.
	 */
	public function test_parses_a_jsonapi_error_document(): void {
		WC_Edge_Test_Transport::respond(
			422,
			'{"errors":[{"status":422,"title":"can\'t be blank","source":{"pointer":"/data/attributes/zip"}}]}'
		);

		try {
			$this->client()->create( 'consumer_addresses', array( 'data' => array() ) );
			$this->fail( 'Expected WC_Edge_API_Exception.' );
		} catch ( WC_Edge_API_Exception $e ) {
			$this->assertSame( 422, $e->get_status_code() );
			$this->assertSame( "can't be blank", $e->getMessage() );
			$this->assertSame( '/data/attributes/zip', $e->get_errors()[0]['source']['pointer'] );
		}
	}

	/**
	 * `detail` is preferred over `title` when both are present.
	 */
	public function test_prefers_the_error_detail(): void {
		WC_Edge_Test_Transport::respond(
			422,
			'{"errors":[{"title":"is invalid","detail":"amount_cents must be greater than 0"}]}'
		);

		try {
			$this->client()->create( 'payment_demands', array( 'data' => array() ) );
			$this->fail( 'Expected WC_Edge_API_Exception.' );
		} catch ( WC_Edge_API_Exception $e ) {
			$this->assertSame( 'amount_cents must be greater than 0', $e->getMessage() );
		}
	}

	/**
	 * The API answers 401/403/404/405 with plain text rather than JSON:API, so
	 * a short non-JSON body is a message and must survive as one.
	 */
	public function test_keeps_a_plain_text_error_body_as_the_message(): void {
		WC_Edge_Test_Transport::respond( 401, 'Unauthorized' );

		try {
			$this->client()->get( 'customers' );
			$this->fail( 'Expected WC_Edge_API_Exception.' );
		} catch ( WC_Edge_API_Exception $e ) {
			$this->assertSame( 401, $e->get_status_code() );
			$this->assertSame( 'Unauthorized', $e->getMessage() );
			$this->assertSame( array(), $e->get_errors() );
		}
	}

	/**
	 * 403 arrives with an empty body, so there is nothing to quote.
	 */
	public function test_describes_an_empty_error_body_by_status(): void {
		WC_Edge_Test_Transport::respond( 403, '' );

		try {
			$this->client()->get( 'webhook_subscriptions' );
			$this->fail( 'Expected WC_Edge_API_Exception.' );
		} catch ( WC_Edge_API_Exception $e ) {
			$this->assertSame( 403, $e->get_status_code() );
			$this->assertSame( 'Edge API error (HTTP 403)', $e->getMessage() );
		}
	}

	/**
	 * An HTML error page is a payload, not a sentence to show a shopper.
	 */
	public function test_does_not_quote_an_html_error_page(): void {
		WC_Edge_Test_Transport::respond( 502, '<html><body>Bad Gateway</body></html>' );

		try {
			$this->client()->get( 'customers' );
			$this->fail( 'Expected WC_Edge_API_Exception.' );
		} catch ( WC_Edge_API_Exception $e ) {
			$this->assertSame( 'Edge API error (HTTP 502)', $e->getMessage() );
			$this->assertSame( '<html><body>Bad Gateway</body></html>', $e->get_raw_body() );
		}
	}

	/**
	 * A truncated 2xx would otherwise surface much later as a property access
	 * on null.
	 */
	public function test_rejects_a_success_body_that_is_not_json(): void {
		WC_Edge_Test_Transport::respond( 200, '{"data":{"id":"abc"' );

		$this->expectException( WC_Edge_API_Exception::class );
		$this->client()->get( 'payment_demands/abc' );
	}

	/**
	 * Consumers walk the document with object syntax throughout.
	 */
	public function test_decodes_responses_into_objects(): void {
		WC_Edge_Test_Transport::respond(
			200,
			'{"data":{"id":"abc","type":"payment_demands","attributes":{"processor_state":"succeeded"}}}'
		);

		$demand = $this->client()->get( 'payment_demands/abc' );

		$this->assertIsObject( $demand );
		$this->assertSame( 'succeeded', $demand->data->attributes->processor_state );
	}

	public function test_a_local_base_uri_is_honoured(): void {
		WC_Edge_Test_Transport::respond( 200, '{"data":[]}' );

		$this->client( array( 'base_uri' => 'https://api.tryedge.test:4001' ) )->get( 'customers' );

		$this->assertSame(
			'https://api.tryedge.test:4001/v2/customers',
			WC_Edge_Test_Transport::last()['url']
		);
	}

	/**
	 * The bytes the reported bug is about, asserted at the wire rather than
	 * logged. A $1.00 product bought twice must leave as a 100 cent unit at
	 * quantity 2 - Edge reads amount_cents as the price of one unit, and the
	 * dashboard was showing $2.00 at quantity 1 because the plugin sent no line
	 * items and the backend substituted a single aggregate row.
	 *
	 * AGENTS.md forbids logging full API payloads, so this is how the payload is
	 * inspected.
	 */
	public function test_a_cart_reaches_the_wire_with_per_unit_line_items(): void {
		WC_Edge_Test_Transport::respond( 201, '{"data":{"id":"abc","type":"payment_demands"}}' );

		$collected = \WC_Edge_Cart_Items::normalise(
			array(
				'lines'    => array(
					array(
						'readable'    => true,
						'name'        => 'Edge Test Product',
						'description' => '',
						'sku'         => 'EDGE-1',
						'quantity'    => '2',
						'subtotal'    => '1.00',
						'total'       => '1.00',
					),
				),
				'fees'     => array(),
				'shipping' => '5.99',
				'tax'      => '0.55',
			)
		);

		$this->client()->create(
			'payment_demands',
			\WC_Edge_Order_Mapper::demand_document(
				array(
					'amount_cents'       => 754,
					'currency'           => 'USD',
					'reference'          => 'wc-1',
					'idempotency_key'    => 'attempt-abc',
					'customer_id'        => 'cust-1',
					'billing_address_id' => 'addr-1',
					'cart'               => $collected,
				)
			)
		);

		$raw        = WC_Edge_Test_Transport::last()['args']['body'];
		$body       = json_decode( $raw, true );
		$attributes = $body['data']['attributes'];

		// The whole point: 50 cents a unit at quantity 2, not 100 at quantity 1.
		$this->assertSame(
			array(
				array(
					'amount_cents'    => 50,
					'amount_currency' => 'USD',
					'quantity'        => 2,
					'name'            => 'Edge Test Product',
					'sku'             => 'EDGE-1',
				),
			),
			$attributes['line_items']
		);

		$this->assertSame(
			array(
				'shipping_cents'    => 599,
				'shipping_currency' => 'USD',
			),
			$attributes['shipping_detail']
		);

		$this->assertSame(
			array(
				'tax_cents'    => 55,
				'tax_currency' => 'USD',
			),
			$attributes['tax_detail']
		);

		// The charge is still driven by amount_cents, not by the items.
		$this->assertSame( 754, $attributes['amount_cents'] );

		// A JSON array, not an object keyed by index.
		$this->assertStringContainsString( '"line_items":[{', $raw );
	}
}
