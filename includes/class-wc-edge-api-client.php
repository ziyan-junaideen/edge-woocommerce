<?php
/**
 * The Edge JSON:API client.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WC_EDGE_TESTING' ) ) {
	exit;
}

/**
 * Talks to the Edge v2 API over WordPress's HTTP API.
 *
 * Endpoints are given relative to the version root, so `customers` becomes
 * `https://api.tryedge.io/v2/customers`. The API speaks JSON:API 1.0 over
 * `application/vnd.api+json` and exposes GET, POST and PATCH only - there are no
 * DELETE routes, so this offers no delete().
 *
 * One instance holds one API key. Nothing here is static: a request carries the
 * merchant's secret, and process-global credentials are how the wrong key ends
 * up on the wrong call.
 */
final class WC_Edge_API_Client {

	/**
	 * The API root, used when nothing overrides it.
	 *
	 * @var string
	 */
	const DEFAULT_BASE_URI = 'https://api.tryedge.io/v2/';

	/**
	 * JSON:API media type, sent as both Accept and Content-Type.
	 *
	 * @var string
	 */
	const MEDIA_TYPE = 'application/vnd.api+json';

	/**
	 * API version segment appended to a base URI given as a bare host.
	 *
	 * @var string
	 */
	const VERSION_PATH = '/v2';

	/**
	 * Seconds to wait for a complete response.
	 *
	 * WordPress has no separate connect timeout, so this bounds the whole
	 * exchange. Setting it explicitly overrides the `http_request_timeout`
	 * filter, which is the point: a hung Edge API must not hold a checkout
	 * request open for however long the site has configured for everything else.
	 *
	 * @var int
	 */
	const TIMEOUT = 15;

	/**
	 * The API key every request authenticates with.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Normalised API root, with a trailing slash.
	 *
	 * @var string
	 */
	private $base_uri;

	/**
	 * Value sent as User-Agent.
	 *
	 * @var string
	 */
	private $user_agent;

	/**
	 * Whether TLS certificates are verified.
	 *
	 * @var bool
	 */
	private $verify_tls;

	/**
	 * Seconds to wait for a response.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Build a client for one API key.
	 *
	 * @param string $secret_key An `ept_{live|sandbox}_s...` key.
	 * @param array  $args       Optional `base_uri`, `user_agent`, `verify_tls`, `timeout`.
	 * @throws InvalidArgumentException When the base URI is not a usable http(s) root.
	 */
	public function __construct( $secret_key, array $args = array() ) {
		// Keys pasted into settings screens routinely arrive with surrounding
		// whitespace, and a stray newline is not a valid HTTP header value.
		$this->secret_key = trim( (string) $secret_key );

		$this->base_uri = self::normalize_base_uri(
			isset( $args['base_uri'] ) && '' !== $args['base_uri']
				? $args['base_uri']
				: self::DEFAULT_BASE_URI
		);

		$this->user_agent = isset( $args['user_agent'] ) ? (string) $args['user_agent'] : '';
		$this->verify_tls = isset( $args['verify_tls'] ) ? (bool) $args['verify_tls'] : true;
		$this->timeout    = isset( $args['timeout'] ) ? (int) $args['timeout'] : self::TIMEOUT;
	}

	/**
	 * The API root this client is pointed at.
	 *
	 * @return string
	 */
	public function base_uri() {
		return $this->base_uri;
	}

	/**
	 * GET a resource or collection.
	 *
	 * The query supports the JSON:API parameters: `filter[…]`, `include`,
	 * `fields[…]`, `sort` and `page[…]`.
	 *
	 * @param string $endpoint Endpoint relative to the API root.
	 * @param array  $query    Query parameters.
	 * @return object Decoded response document.
	 */
	public function get( $endpoint, array $query = array() ) {
		return $this->request( 'GET', $endpoint, null, $query );
	}

	/**
	 * POST a JSON:API document to create a resource.
	 *
	 * @param string $endpoint Endpoint relative to the API root.
	 * @param array  $document JSON:API document.
	 * @return object Decoded response document.
	 */
	public function create( $endpoint, array $document ) {
		return $this->request( 'POST', $endpoint, $document );
	}

	/**
	 * PATCH a JSON:API document to update a resource.
	 *
	 * The API has no PUT - updates are always a partial merge.
	 *
	 * @param string $endpoint Endpoint relative to the API root.
	 * @param array  $document JSON:API document.
	 * @return object Decoded response document.
	 */
	public function patch( $endpoint, array $document ) {
		return $this->request( 'PATCH', $endpoint, $document );
	}

	/**
	 * Confirm a resource created in the unconfirmed state.
	 *
	 * Creating a payment demand defaults it to `confirmed: false`; this is the
	 * second half of that flow, run once the hosted form has attached a verified
	 * payment method.
	 *
	 * @param string $type       Resource type, e.g. `payment_demands`.
	 * @param string $id         Resource id.
	 * @param array  $attributes Optional attributes to send with the confirm.
	 * @return object Decoded response document.
	 */
	public function confirm( $type, $id, array $attributes = array() ) {
		$type = trim( (string) $type, '/' );

		return $this->patch(
			$type . '/' . rawurlencode( (string) $id ) . '/confirm',
			array(
				'data' => array(
					'id'         => (string) $id,
					'type'       => $type,
					// An empty PHP array encodes to a JSON list; the server wants
					// an object. This is load-bearing - `"attributes":[]` is
					// rejected.
					'attributes' => empty( $attributes ) ? new stdClass() : $attributes,
				),
			)
		);
	}

	/**
	 * Send one request and decode the reply.
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $endpoint Endpoint relative to the API root.
	 * @param array|null $document JSON:API document for writes, null for reads.
	 * @param array      $query    Query parameters.
	 * @return object Decoded response document.
	 * @throws WC_Edge_API_Exception On transport failure, a non-2xx status, or an undecodable body.
	 */
	private function request( $method, $endpoint, $document = null, array $query = array() ) {
		$url = $this->url( $endpoint );

		if ( ! empty( $query ) ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' )
				// RFC 3986, not PHP's RFC 1738 default: a space must encode as
				// %20, and nested arrays as page%5Bsize%5D=100.
				. http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => $this->timeout,
			'httpversion' => '1.1',
			// Never follow a redirect: every request carries the merchant's
			// secret key, and a redirect would hand it to wherever it points.
			'redirection' => 0,
			'sslverify'   => $this->verify_tls,
			'user-agent'  => $this->user_agent,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->secret_key,
				'Accept'        => self::MEDIA_TYPE,
			),
		);

		if ( null !== $document ) {
			$encoded = wp_json_encode( $document );

			if ( false === $encoded ) {
				throw WC_Edge_API_Exception::from_transport_failure(
					'Could not encode the request document as JSON.'
				);
			}

			// Content-Type belongs on writes only - a GET carries no body, and
			// the API enforces the media type on requests that do.
			$args['headers']['Content-Type'] = self::MEDIA_TYPE;
			$args['body']                    = $encoded;
			$args['data_format']             = 'body';
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			// Status 0 means "the outcome is unknown". Callers depend on that to
			// tell a request that never landed from one that was rejected.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic text for the log; callers build their own shopper-facing copy.
			throw WC_Edge_API_Exception::from_transport_failure( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( $status >= 400 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic text for the log; callers build their own shopper-facing copy.
			throw WC_Edge_API_Exception::from_response( $status, $body );
		}

		return self::decode( $status, $body );
	}

	/**
	 * Decode a successful response body.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body   Response body.
	 * @return object
	 * @throws WC_Edge_API_Exception When the body is not a JSON:API document.
	 */
	private static function decode( $status, $body ) {
		if ( '' === trim( $body ) ) {
			return new stdClass();
		}

		$decoded = json_decode( $body );

		// A truncated or non-JSON 2xx would otherwise surface much later as a
		// property access on null.
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $decoded ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic text for the log; callers build their own shopper-facing copy.
			throw WC_Edge_API_Exception::from_response( $status, $body );
		}

		return $decoded;
	}

	/**
	 * Resolve an endpoint against the API root.
	 *
	 * Done by hand rather than by URL resolution, because RFC 3986 silently
	 * drops the version prefix when the endpoint has a leading slash.
	 *
	 * @param string $endpoint Endpoint.
	 * @return string Absolute URL.
	 * @throws InvalidArgumentException When an absolute endpoint points elsewhere.
	 */
	private function url( $endpoint ) {
		// Stripping leading slashes before the scheme test is what makes a
		// protocol-relative `//evil.test/collect` unable to escape the root: it
		// becomes a path segment rather than a host.
		$endpoint = ltrim( trim( (string) $endpoint ), '/' );

		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $endpoint ) ) {
			return $this->assert_same_origin( $endpoint );
		}

		return $this->base_uri . $endpoint;
	}

	/**
	 * Refuse to send credentials anywhere but the configured API.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 * @throws InvalidArgumentException When the origin differs from the API root.
	 */
	private function assert_same_origin( $url ) {
		$target = wp_parse_url( $url );

		if ( ! is_array( $target ) ) {
			throw new InvalidArgumentException( 'Could not parse endpoint URL.' );
		}

		$base = wp_parse_url( $this->base_uri );

		if ( ! is_array( $base ) || self::origin( $target ) !== self::origin( $base ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Our own base URI, for a developer reading the log.
				'Refusing to send Edge credentials outside ' . self::origin( is_array( $base ) ? $base : array() ) . '.'
			);
		}

		return $url;
	}

	/**
	 * Reduce parsed URL parts to a comparable origin.
	 *
	 * @param array $parts Output of wp_parse_url().
	 * @return string
	 */
	private static function origin( array $parts ) {
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'http' === $scheme ? 80 : 443 );

		return $scheme . '://' . $host . ':' . $port;
	}

	/**
	 * Normalise an API root.
	 *
	 * Accepts a full root (`https://api.tryedge.io/v2/`) or a bare host
	 * (`https://api.tryedge.test:4001`), which gains the version segment.
	 *
	 * @param string $base_uri Configured root.
	 * @return string Root with a trailing slash.
	 * @throws InvalidArgumentException When the value is not a usable http(s) root.
	 */
	public static function normalize_base_uri( $base_uri ) {
		$base_uri = rtrim( trim( (string) $base_uri ), '/' );
		$parts    = wp_parse_url( $base_uri );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			throw new InvalidArgumentException( 'Invalid Edge API base URI.' );
		}

		$scheme = strtolower( $parts['scheme'] );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			throw new InvalidArgumentException( 'Edge API base URI must be http or https.' );
		}

		if ( ! isset( $parts['path'] ) || '' === $parts['path'] ) {
			$base_uri .= self::VERSION_PATH;
		}

		return $base_uri . '/';
	}
}
