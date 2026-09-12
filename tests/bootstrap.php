<?php
/**
 * PHPUnit bootstrap.
 *
 * These are plain unit tests: no WordPress is loaded. Classes under test are
 * therefore written to avoid WordPress functions, or to have those seams
 * injected. `WC_EDGE_TESTING` stands in for the `ABSPATH` direct-access guard.
 *
 * A few WordPress helpers are shimmed below. They are deliberately the real
 * implementations rather than mocks, so a test failure means our code is wrong
 * rather than the stub being wrong.
 *
 * @package WooCommerce Edge Payments Gateway
 */

define( 'WC_EDGE_TESTING', true );
define( 'WC_EDGE_VERSION', '2.4.0' );

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url       URL to parse.
	 * @param int    $component Component to retrieve.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * @param string $value Value to trim.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data to encode.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions
		return $text;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_Error, sufficient for these tests.
	 */
	class WP_Error { // phpcs:ignore WordPress.NamingConventions

		/** @var string */
		private $code;

		/** @var string */
		private $message;

		/** @var mixed */
		private $data;

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/** @return string */
		public function get_error_code() {
			return $this->code;
		}

		/** @return string */
		public function get_error_message() {
			return $this->message;
		}

		/** @return mixed */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

/**
 * Stands in for WordPress's HTTP API.
 *
 * `WC_Edge_API_Client` calls `wp_remote_request()` directly, so the seam is the
 * function itself rather than an injected transport: the tests then assert on
 * the exact arguments the plugin hands WordPress, which is the thing that
 * actually has to be right.
 */
class WC_Edge_Test_Transport { // phpcs:ignore WordPress.NamingConventions

	/** @var callable|null Receives ( $url, $args ) and returns a response or WP_Error. */
	public static $handler = null;

	/** @var array<int,array{url:string,args:array}> Every request made. */
	public static $requests = array();

	/**
	 * Answer every request with one canned response.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $body    Response body.
	 * @param array  $headers Response headers.
	 * @return void
	 */
	public static function respond( $status, $body = '', array $headers = array() ) {
		self::$handler = static function () use ( $status, $body, $headers ) {
			return array(
				'response' => array( 'code' => $status ),
				'body'     => $body,
				'headers'  => $headers,
			);
		};
	}

	/**
	 * Fail every request the way a transport error does.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	public static function fail( $message = 'Connection timed out' ) {
		self::$handler = static function () use ( $message ) {
			return new WP_Error( 'http_request_failed', $message );
		};
	}

	/**
	 * Forget recorded requests and any installed handler.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$handler  = null;
		self::$requests = array();
	}

	/**
	 * The most recent request.
	 *
	 * @return array{url:string,args:array}
	 */
	public static function last() {
		if ( empty( self::$requests ) ) {
			throw new RuntimeException( 'No request was made.' );
		}

		return self::$requests[ count( self::$requests ) - 1 ];
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	/**
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments.
	 * @return array|WP_Error
	 */
	function wp_remote_request( $url, $args = array() ) {
		WC_Edge_Test_Transport::$requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( ! WC_Edge_Test_Transport::$handler ) {
			return new WP_Error( 'http_request_failed', 'No canned response was installed.' );
		}

		return call_user_func( WC_Edge_Test_Transport::$handler, $url, $args );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * @param array $response Response.
	 * @return int|string
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? $response['response']['code'] : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * @param array $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? $response['body'] : '';
	}
}

require_once __DIR__ . '/../includes/class-wc-edge-money.php';
require_once __DIR__ . '/../includes/class-wc-edge-mode.php';
require_once __DIR__ . '/../includes/class-wc-edge-countries.php';
require_once __DIR__ . '/../includes/class-wc-edge-api-exception.php';
require_once __DIR__ . '/../includes/class-wc-edge-api-client.php';
require_once __DIR__ . '/../includes/class-wc-edge-client-factory.php';
require_once __DIR__ . '/../includes/class-wc-edge-fingerprint.php';
require_once __DIR__ . '/../includes/class-wc-edge-order-mapper.php';
require_once __DIR__ . '/../includes/class-wc-edge-cart-items.php';
require_once __DIR__ . '/../includes/class-wc-edge-payment-outcome.php';
require_once __DIR__ . '/../includes/class-wc-edge-refund-outcome.php';
require_once __DIR__ . '/../includes/class-wc-edge-webhook-signature.php';
