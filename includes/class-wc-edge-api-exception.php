<?php
/**
 * Failures from the Edge API.
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
 * Raised for every failed Edge API call.
 *
 * One class rather than a hierarchy: callers branch on the status code, which
 * carries all the distinction they need.
 *
 *     try {
 *         $api->create( 'payment_demands', $document );
 *     } catch ( WC_Edge_API_Exception $e ) {
 *         $e->getMessage();       // "amount_cents must be greater than 0"
 *         $e->get_status_code();  // 422
 *         $e->get_errors();       // the parsed JSON:API errors array
 *         $e->get_raw_body();     // the response body, exactly as it arrived
 *     }
 *
 * Not every failure is a JSON:API error document. The gateway answers
 * authentication failures with plain text and returns an empty body for 403,
 * so `get_errors()` is empty in those cases while `getMessage()` still reads
 * sensibly.
 */
class WC_Edge_API_Exception extends Exception {

	/**
	 * Longest response body still treated as a human-readable message rather
	 * than a payload.
	 *
	 * @var int
	 */
	const MAX_PLAIN_TEXT_MESSAGE = 200;

	/**
	 * The JSON:API `errors` array, or empty when the body carried none.
	 *
	 * @var array
	 */
	protected $errors = array();

	/**
	 * The response body exactly as it came off the wire.
	 *
	 * @var string
	 */
	protected $raw_body = '';

	/**
	 * Build an exception from a response that arrived.
	 *
	 * @param int    $status   HTTP status code.
	 * @param string $raw_body Response body.
	 * @return self
	 */
	public static function from_response( $status, $raw_body ) {
		$status   = (int) $status;
		$raw_body = (string) $raw_body;
		$errors   = self::parse_errors( $raw_body );

		$exception = new self( self::describe( $status, $errors, $raw_body ), $status );

		$exception->raw_body = $raw_body;
		$exception->errors   = $errors;

		return $exception;
	}

	/**
	 * Build an exception for a request that never got a response.
	 *
	 * Status 0 is the signal callers use to mean "the outcome is unknown", which
	 * is what makes ambiguous-confirm resolution possible. Never report a
	 * transport failure with any other code.
	 *
	 * @param string $message Failure description.
	 * @return self
	 */
	public static function from_transport_failure( $message ) {
		return new self( (string) $message, 0 );
	}

	/**
	 * The JSON:API errors array. Empty for plain text and empty bodies.
	 *
	 * @return array
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * The HTTP status code, or 0 when the request never got a response.
	 *
	 * @return int
	 */
	public function get_status_code() {
		return (int) $this->getCode();
	}

	/**
	 * The response body exactly as it came off the wire.
	 *
	 * @return string
	 */
	public function get_raw_body() {
		return $this->raw_body;
	}

	/**
	 * Reduce a failure to one displayable line.
	 *
	 * @param int    $status   HTTP status code.
	 * @param array  $errors   Parsed JSON:API errors.
	 * @param string $raw_body Response body.
	 * @return string
	 */
	private static function describe( $status, array $errors, $raw_body ) {
		if ( ! empty( $errors ) ) {
			$first = reset( $errors );

			foreach ( array( 'detail', 'title', 'code' ) as $key ) {
				if ( is_array( $first ) && isset( $first[ $key ] ) && '' !== $first[ $key ] ) {
					return (string) $first[ $key ];
				}
			}
		}

		$body = trim( $raw_body );

		// Auth failures come back as a bare phrase such as "Unauthorized".
		// Anything longer, or that opens like JSON or HTML, is a payload rather
		// than a message.
		if ( '' !== $body
			&& strlen( $body ) <= self::MAX_PLAIN_TEXT_MESSAGE
			&& '{' !== $body[0]
			&& '<' !== $body[0] ) {
			return $body;
		}

		return 'Edge API error (HTTP ' . $status . ')';
	}

	/**
	 * Pull the `errors` array out of a JSON:API error document.
	 *
	 * @param string $body Response body.
	 * @return array
	 */
	private static function parse_errors( $body ) {
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['errors'] ) || ! is_array( $decoded['errors'] ) ) {
			return array();
		}

		return $decoded['errors'];
	}
}
