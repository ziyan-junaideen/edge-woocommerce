<?php
/**
 * Decides what an Edge refund response actually means.
 *
 * @package Deens_Edge_Payments_For_WooCommerce
 * @since   2.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WC_EDGE_TESTING' ) ) {
	exit;
}

/**
 * The judgement calls a refund makes, with no WordPress in them.
 *
 * Refunding is the one operation in this plugin where being wrong about what a
 * response meant costs the merchant real money twice. That reasoning lives here,
 * pure, so it can be tested without a WordPress install - the orchestration in
 * WC_Edge_Refund_Service is the thin part.
 */
final class WC_Edge_Refund_Outcome {

	/**
	 * Edge created (or replayed) the refund and told us which one it is.
	 *
	 * @var string
	 */
	const CREATED = 'created';

	/**
	 * Edge refused. Nothing was refunded and retrying the same request will not
	 * change that.
	 *
	 * @var string
	 */
	const REJECTED = 'rejected';

	/**
	 * We do not know. A refund may or may not exist, so it has to be looked up
	 * before anything else is decided.
	 *
	 * @var string
	 */
	const AMBIGUOUS = 'ambiguous';

	/**
	 * States that mean the refund is real as far as Edge is concerned.
	 *
	 * `pending` and `processing` reserve their amount against the payment's
	 * balance exactly as `succeeded` does, so all three mean "this refund
	 * exists, do not create another one".
	 *
	 * @var string[]
	 */
	const LIVE_STATES = array( 'pending', 'processing', 'succeeded' );

	/**
	 * Classify a completed POST.
	 *
	 * A 2xx is not automatically a success. WC_Edge_API_Client::decode() answers
	 * an empty body with a bare stdClass and throws on a truncated one carrying
	 * the 2xx status, so "the request returned without an exception" and "a
	 * refund was created" are different questions. Anything that leaves us
	 * unable to name the refund is ambiguous, never a failure: a failure would
	 * send the merchant round again with a fresh key.
	 *
	 * @param int  $status         HTTP status, or 0 for a transport failure.
	 * @param bool $has_usable_id  Whether a refund demand id came back.
	 * @return string One of the class constants.
	 */
	public static function classify( $status, $has_usable_id ) {
		$status = (int) $status;

		// A response we never saw. The outcome is unknown, not failed.
		if ( 0 === $status ) {
			return self::AMBIGUOUS;
		}

		if ( $status >= 500 ) {
			return self::AMBIGUOUS;
		}

		if ( $status >= 400 ) {
			return self::REJECTED;
		}

		return $has_usable_id ? self::CREATED : self::AMBIGUOUS;
	}

	/**
	 * Whether a decoded document names a refund demand.
	 *
	 * @param mixed $document Decoded JSON:API document.
	 * @return bool
	 */
	public static function has_id( $document ) {
		return is_object( $document )
			&& isset( $document->data )
			&& is_object( $document->data )
			&& ! empty( $document->data->id );
	}

	/**
	 * The id a document names, or an empty string.
	 *
	 * @param mixed $document Decoded JSON:API document.
	 * @return string
	 */
	public static function id_of( $document ) {
		return self::has_id( $document ) ? (string) $document->data->id : '';
	}

	/**
	 * Find the refund demand carrying an idempotency key in a listing.
	 *
	 * This is how an unclear outcome is resolved: the key was written down
	 * before the request went out, so it identifies our own refund even when the
	 * response that would have named it never arrived.
	 *
	 * @param mixed  $document Decoded `GET /refund_demands` document.
	 * @param string $key      Idempotency key to look for.
	 * @return object|null The matching resource object, or null.
	 */
	public static function find_by_key( $document, $key ) {
		$key = (string) $key;

		if ( '' === $key || ! is_object( $document ) || empty( $document->data ) ) {
			return null;
		}

		$candidates = is_array( $document->data ) ? $document->data : array( $document->data );

		foreach ( $candidates as $candidate ) {
			if ( ! is_object( $candidate ) || empty( $candidate->id ) ) {
				continue;
			}

			$found = isset( $candidate->attributes->idempotency_key )
				? (string) $candidate->attributes->idempotency_key
				: '';

			// hash_equals rather than ===: these are secrets in every sense that
			// matters, and the comparison is cheap.
			if ( '' !== $found && hash_equals( $key, $found ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * The state a refund resource is in.
	 *
	 * @param mixed $refund_demand Decoded resource object.
	 * @return string
	 */
	public static function state_of( $refund_demand ) {
		return is_object( $refund_demand ) && isset( $refund_demand->attributes->state )
			? (string) $refund_demand->attributes->state
			: '';
	}

	/**
	 * Whether a state means the refund exists and holds its amount.
	 *
	 * @param string $state Refund state.
	 * @return bool
	 */
	public static function is_live( $state ) {
		return in_array( (string) $state, self::LIVE_STATES, true );
	}

	/**
	 * The payment demand a refund resource belongs to.
	 *
	 * @param mixed $document Decoded refund demand document.
	 * @return string
	 */
	public static function payment_demand_id( $document ) {
		if ( ! is_object( $document ) || ! isset( $document->data ) ) {
			return '';
		}

		$data = $document->data;

		return isset( $data->relationships->payment_demand->data->id )
			? (string) $data->relationships->payment_demand->data->id
			: '';
	}
}
