<?php
/**
 * Verifies the signature on an inbound Edge webhook.
 *
 * @package Deens_Edge_Payments_For_WooCommerce
 * @since   2.3.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'WC_EDGE_TESTING' ) ) {
		exit;
	}
}

/**
 * Edge webhook delivery version v3, and only v3.
 *
 * The header is `edge-signature: t=<unix seconds>,v3=<lowercase hex>`, where the
 * signed payload is `"<timestamp>.<raw body>"` and the key is the subscription's
 * secret. Signing the body binds the signature to the payload it was issued for,
 * and signing the timestamp with it is what lets a stale delivery be rejected.
 *
 * The legacy `x-hub-signature` - a constant `base64(sha1(secret))`, with the body
 * not an input - is not accepted. It was a bearer token, not a signature.
 *
 * This is the boundary that decides whether a delivery is believed at all, so it
 * lives here as pure functions rather than inside the controller, where it could
 * not be tested without a WordPress install.
 */
final class WC_Edge_Webhook_Signature {

	/**
	 * Header carrying the signature.
	 *
	 * @var string
	 */
	const HEADER = 'edge-signature';

	/**
	 * How far out of date a delivery's timestamp may be, in seconds.
	 *
	 * Generous enough to survive clock skew and Edge's retry backoff, short
	 * enough that a captured delivery cannot be replayed indefinitely.
	 *
	 * @var int
	 */
	const TOLERANCE = 300;

	/**
	 * Pull the timestamp and digest out of a signature header.
	 *
	 * Unknown tokens are skipped rather than treated as a malformed header: Edge
	 * documents that a future scheme would be rolled out by emitting `v4`
	 * alongside `v3` for a migration window, and such a delivery must still
	 * verify against `v3` while both are sent.
	 *
	 * @param string $header Header value.
	 * @return array|null `timestamp` and `signature`, or null when unusable.
	 */
	public static function parse( $header ) {
		if ( ! is_string( $header ) || '' === trim( $header ) ) {
			return null;
		}

		$timestamp = null;
		$signature = '';

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			$name  = trim( $pair[0] );
			$value = trim( $pair[1] );

			if ( 't' === $name && ctype_digit( $value ) ) {
				$timestamp = (int) $value;
			} elseif ( 'v3' === $name ) {
				$signature = strtolower( $value );
			}
		}

		if ( null === $timestamp || ! preg_match( '/^[0-9a-f]{64}$/', $signature ) ) {
			return null;
		}

		return array(
			'timestamp' => $timestamp,
			'signature' => $signature,
		);
	}

	/**
	 * Whether a delivery is fresh enough to act on.
	 *
	 * @param int $timestamp Signed timestamp.
	 * @param int $now       Current unix time.
	 * @return bool
	 */
	public static function is_fresh( $timestamp, $now ) {
		return abs( (int) $now - (int) $timestamp ) <= self::TOLERANCE;
	}

	/**
	 * Whether a signature verifies against a secret.
	 *
	 * `$body` must be the bytes as received. Re-encoding a decoded payload does
	 * not reproduce them - JSON key order is not stable - and the receiver only
	 * ever has the bytes.
	 *
	 * @param array  $parsed Output of self::parse().
	 * @param string $body   Raw request body.
	 * @param string $secret Subscription signing secret.
	 * @return bool
	 */
	public static function matches( array $parsed, $body, $secret ) {
		if ( ! isset( $parsed['timestamp'], $parsed['signature'] ) || ! is_string( $secret ) || '' === $secret ) {
			return false;
		}

		$expected = hash_hmac(
			'sha256',
			$parsed['timestamp'] . '.' . (string) $body,
			$secret
		);

		return hash_equals( $expected, (string) $parsed['signature'] );
	}
}
