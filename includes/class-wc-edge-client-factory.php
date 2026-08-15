<?php
/**
 * Builds configured Edge API clients.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WC_EDGE_TESTING' ) ) {
	exit;
}

/**
 * Resolves where the plugin talks to Edge, and hands out clients for it.
 *
 * Every environment decision - the API root, the hosted form origin, the browser
 * SDK URL, TLS policy and the user agent - is made here, so a caller only has to
 * supply an API key.
 */
final class WC_Edge_Client_Factory {

	/**
	 * Hosted payment form origin, used by the browser SDK for the iframe.
	 *
	 * @var string
	 */
	const DEFAULT_DASHBOARD_HOST = 'https://dashboard.tryedge.io';

	/**
	 * Canonical URL of Edge's hosted browser SDK.
	 *
	 * Edge's developer page hands out a content-hashed path
	 * (`.../edge-<digest>.js?vsn=d`), which is cache-optimal but changes on every
	 * deploy. The previous version of this plugin hard-coded one such digest and
	 * broke when it moved. This undigested path serves a byte-identical file -
	 * its ETag is the digest - and survives redeploys, which matters more here
	 * than the cache headers on an 8 KB script.
	 *
	 * @var string
	 */
	const DEFAULT_BROWSER_SDK_URL = 'https://assets.tryedge.io/assets/js/edge.js';

	/**
	 * Suffix of Edge's production hostnames. TLS verification is never
	 * negotiable for these.
	 *
	 * @var string
	 */
	const PRODUCTION_HOST_SUFFIX = 'tryedge.io';

	/**
	 * Build a client authenticated with one secret key.
	 *
	 * @param string $secret_key An `ept_{live|sandbox}_s...` key.
	 * @return WC_Edge_API_Client
	 * @throws InvalidArgumentException When handed a key that is not a secret key.
	 */
	public static function client( $secret_key ) {
		if ( ! WC_Edge_Mode::is_secret( $secret_key ) ) {
			// Never let a publishable key authenticate server-side calls: the API
			// accepts it as a bearer token but with different permissions, which
			// would fail confusingly and much later.
			throw new InvalidArgumentException( 'Edge API calls require a secret key.' );
		}

		return new WC_Edge_API_Client(
			$secret_key,
			array(
				'base_uri'   => self::api_base_uri(),
				'user_agent' => self::user_agent(),
				'verify_tls' => self::should_verify_tls(),
			)
		);
	}

	/**
	 * The Edge API root.
	 *
	 * Overridable only through a constant, never through a stored setting. An
	 * admin-editable API host would send the merchant's secret key to an
	 * arbitrary origin and turn the gateway into an SSRF primitive.
	 *
	 * @return string
	 */
	public static function api_base_uri() {
		if ( defined( 'EDGE_API_BASE_URI' ) && is_string( EDGE_API_BASE_URI ) && '' !== EDGE_API_BASE_URI ) {
			return EDGE_API_BASE_URI;
		}

		return WC_Edge_API_Client::DEFAULT_BASE_URI;
	}

	/**
	 * The hosted payment form origin handed to the browser SDK.
	 *
	 * @return string
	 */
	public static function dashboard_host() {
		if ( defined( 'EDGE_DASHBOARD_HOST' ) && is_string( EDGE_DASHBOARD_HOST ) && '' !== EDGE_DASHBOARD_HOST ) {
			return untrailingslashit( EDGE_DASHBOARD_HOST );
		}

		return self::DEFAULT_DASHBOARD_HOST;
	}

	/**
	 * URL of the hosted browser SDK.
	 *
	 * Overridable for local development, where the SDK is served unminified from
	 * the dashboard host rather than the assets CDN.
	 *
	 * @return string
	 */
	public static function browser_sdk_url() {
		if ( defined( 'EDGE_BROWSER_SDK_URL' ) && is_string( EDGE_BROWSER_SDK_URL ) && '' !== EDGE_BROWSER_SDK_URL ) {
			return EDGE_BROWSER_SDK_URL;
		}

		return self::DEFAULT_BROWSER_SDK_URL;
	}

	/**
	 * Whether TLS certificates must be verified.
	 *
	 * Verification can only be waived for a non-production host, and only when a
	 * developer has explicitly opted in. A misconfigured constant can therefore
	 * never expose live credentials to an unverified connection.
	 *
	 * @return bool
	 */
	public static function should_verify_tls() {
		if ( ! defined( 'EDGE_DISABLE_TLS_VERIFY' ) || ! EDGE_DISABLE_TLS_VERIFY ) {
			return true;
		}

		return self::is_production_host( self::api_base_uri() );
	}

	/**
	 * Whether a URI points at Edge production infrastructure.
	 *
	 * @param string $uri Absolute URI.
	 * @return bool
	 */
	public static function is_production_host( $uri ) {
		$host = wp_parse_url( (string) $uri, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			// Unparseable means "assume production" - the safe direction.
			return true;
		}

		$host   = strtolower( $host );
		$suffix = '.' . self::PRODUCTION_HOST_SUFFIX;

		return self::PRODUCTION_HOST_SUFFIX === $host
			|| substr( $host, -strlen( $suffix ) ) === $suffix;
	}

	/**
	 * Identify this integration to Edge.
	 *
	 * The API marks User-Agent required on every operation, and WordPress sends
	 * its own generic one unless told otherwise, so this is the whole header
	 * value rather than a suffix appended to something else.
	 *
	 * @return string
	 */
	public static function user_agent() {
		$parts = array( 'EdgeWooCommerce/' . ( defined( 'WC_EDGE_VERSION' ) ? WC_EDGE_VERSION : 'dev' ) );

		if ( defined( 'WC_VERSION' ) ) {
			$parts[] = 'WooCommerce/' . WC_VERSION;
		}

		if ( function_exists( 'get_bloginfo' ) ) {
			$parts[] = 'WordPress/' . get_bloginfo( 'version' );
		}

		$parts[] = 'PHP/' . PHP_VERSION;

		return implode( ' ', $parts );
	}
}
