<?php
/**
 * Fingerprints the facts a payment demand was created for.
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
 * Produces a stable hash of everything a payment demand depends on.
 *
 * Edge's idempotency is keyed on the value alone, not on the request body:
 * re-sending a key with different attributes silently returns the original
 * resource. Confirmed against the API - re-posting a demand with the amount
 * changed from 2500 to 9999 returned the first demand, still at 2500, with no
 * error at all.
 *
 * So the key cannot be a per-order constant. It has to change whenever anything
 * about the payment changes, or a shopper who edits their cart pays the amount
 * from before the edit. This hash is what the attempt key is derived from.
 *
 * `cart_hash` does not cover everything on its own. WooCommerce builds it from
 * `WC_Cart_Session::get_cart_for_session()`, which unsets each row's product
 * object, so a renamed product or an edited SKU never reaches it; calculated
 * fees are not in the cart rows either. `itemisation_hash` closes that gap - see
 * WC_Edge_Cart_Items - so an attempt is never reused with stale line items.
 */
final class WC_Edge_Fingerprint {

	/**
	 * Fields that identify the payment, in a fixed order.
	 *
	 * Listed explicitly rather than hashing whatever is passed, so that adding a
	 * field to the caller cannot silently change every existing fingerprint.
	 *
	 * @var string[]
	 */
	const FIELDS = array(
		'cart_hash',
		'itemisation_hash',
		'amount_cents',
		'currency',
		'mode',
		'publishable_key',
		'billing_first_name',
		'billing_last_name',
		'billing_email',
		'billing_phone',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_country',
		'shipping_first_name',
		'shipping_last_name',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'shipping_country',
	);

	/**
	 * Fields that identify one refund, in a fixed order.
	 *
	 * `reason_note` is deliberately absent. normalise() lowercases ordinary
	 * strings, but Edge compares `reason_note` byte-exactly when it decides
	 * whether a replayed key is the same request; two notes differing only in
	 * case would hash to one key and collide as a 422. The WooCommerce refund id
	 * already makes the key unique, and a note cannot change for a given row.
	 *
	 * @var string[]
	 */
	const REFUND_FIELDS = array(
		'demand_id',
		'mode',
		'order_id',
		'refund_id',
		'amount_cents',
	);

	/**
	 * Hash a set of payment facts.
	 *
	 * @param array $facts Raw values keyed by the names in self::FIELDS.
	 * @return string 64 character hex digest.
	 */
	public static function of( array $facts ) {
		$normalised = array();

		foreach ( self::FIELDS as $field ) {
			$normalised[ $field ] = self::normalise(
				$field,
				isset( $facts[ $field ] ) ? $facts[ $field ] : ''
			);
		}

		// JSON of an ordered map, so the digest is stable across PHP versions and
		// insertion order.
		return hash( 'sha256', wp_json_encode( $normalised ) );
	}

	/**
	 * Hash the facts of one refund.
	 *
	 * Refund idempotency is stricter than payment idempotency: replaying a key
	 * with a different payment, amount, reason or note is rejected with a 422
	 * rather than silently returning the original. So this digest does not have
	 * to defend against a changed amount reaching an old key - it only has to be
	 * the same value for the same refund, so a retry after an unclear outcome
	 * replays instead of refunding twice, and a different value for a genuinely
	 * different refund, so two deliberate partial refunds of the same amount are
	 * not collapsed into one.
	 *
	 * @param array $facts Raw values keyed by the names in self::REFUND_FIELDS.
	 * @return string 64 character hex digest.
	 */
	public static function for_refund( array $facts ) {
		$normalised = array();

		foreach ( self::REFUND_FIELDS as $field ) {
			$normalised[ $field ] = self::normalise(
				$field,
				isset( $facts[ $field ] ) ? $facts[ $field ] : ''
			);
		}

		return hash( 'sha256', wp_json_encode( $normalised ) );
	}

	/**
	 * Reduce a value to its significant form.
	 *
	 * Cosmetic differences must not rotate the key - retyping an address with
	 * different capitalisation is not a new payment - but anything that changes
	 * what is charged or who is charged must.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string
	 */
	private static function normalise( $field, $value ) {
		if ( is_scalar( $value ) ) {
			$value = (string) $value;
		} else {
			$value = '';
		}

		// Collapse whitespace runs and trim.
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) );

		if ( 'amount_cents' === $field ) {
			return (string) (int) $value;
		}

		if ( 'billing_email' === $field ) {
			return strtolower( $value );
		}

		if ( 'currency' === $field || self::is_country_field( $field ) ) {
			return strtoupper( $value );
		}

		// Case is not significant for addresses or names.
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * Whether a field holds a country code.
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	private static function is_country_field( $field ) {
		return 'billing_country' === $field || 'shipping_country' === $field;
	}
}
