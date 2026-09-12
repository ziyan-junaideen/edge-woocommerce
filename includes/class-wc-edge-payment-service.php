<?php
/**
 * Creates and binds the Edge resources a checkout needs.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prepares a payment demand for the hosted form to mount against.
 *
 * Orchestration only: documents come from WC_Edge_Order_Mapper, the fingerprint
 * from WC_Edge_Fingerprint, persistence from WC_Edge_Attempt_Store.
 */
final class WC_Edge_Payment_Service {

	/**
	 * Processor states in which Edge has already taken the money.
	 *
	 * Confirming again is neither possible nor wanted: the API answers 405, and
	 * the order is one the poll or the webhook is about to complete.
	 *
	 * @var string[]
	 */
	const SETTLED_STATES = array( 'succeeded', 'reversed', 'disputed', 'refunded' );

	/**
	 * Processor states a confirm can still be applied to.
	 *
	 * `incomplete` and `ready` are the run-up to a first confirm. `failed` is
	 * there because a declined demand is the one non-terminal outcome Edge allows
	 * back in: confirming it again moves it to `pending`, which is what lets a
	 * shopper retry with the same order rather than starting over.
	 *
	 * @var string[]
	 */
	const RETRY_STATES = array( 'incomplete', 'ready', 'failed' );

	/**
	 * How far back an on-hold Edge order still counts as this session's problem.
	 *
	 * An Edge payment settles in seconds, so an hour is generous by any ordinary
	 * measure and the cut-off only bites when something upstream has gone wrong.
	 * Past it the order is treated as abandoned and the shopper may start again:
	 * a stuck job at Edge is not a reason to refuse somebody the ability to buy,
	 * and an order left on-hold is one a merchant can see and act on, where a
	 * shopper who cannot check out is one who leaves. The trade-off is that the
	 * stuck payment could in principle still settle and leave two orders to
	 * reconcile, which is the lesser of the two.
	 *
	 * @var int
	 */
	const IN_FLIGHT_WINDOW = 3600;

	/**
	 * Prepare a demand for the current cart.
	 *
	 * @param WC_Gateway_Edge $gateway Configured gateway.
	 * @return array|WP_Error `array{demand_id:string, attempt_key:string}`.
	 */
	public static function prepare( WC_Gateway_Edge $gateway ) {
		$facts = self::collect_facts( $gateway );

		if ( is_wp_error( $facts ) ) {
			return $facts;
		}

		$claim = WC_Edge_Attempt_Store::claim(
			$facts['session_key'],
			WC_Edge_Fingerprint::of( $facts ),
			array(
				'mode'         => $facts['mode'],
				'amount_cents' => $facts['amount_cents'],
				'currency'     => $facts['currency'],
			)
		);

		if ( is_wp_error( $claim ) ) {
			return $claim;
		}

		$attempt = $claim['attempt'];

		// An attempt already carried onto an order is spent - its demand has been
		// confirmed and cannot pay for a second order. This happens when the same
		// customer buys an identical cart again, producing the same fingerprint.
		// Free the slot and claim afresh rather than handing back a used demand.
		if ( WC_Edge_Attempt_Store::STATUS_ADOPTED === $attempt->status ) {
			WC_Edge_Attempt_Store::release_facts_slot( $attempt->attempt_key );

			$claim = WC_Edge_Attempt_Store::claim(
				$facts['session_key'],
				WC_Edge_Fingerprint::of( $facts ),
				array(
					'mode'         => $facts['mode'],
					'amount_cents' => $facts['amount_cents'],
					'currency'     => $facts['currency'],
				)
			);

			if ( is_wp_error( $claim ) ) {
				return $claim;
			}

			$attempt = $claim['attempt'];
		}

		// Already complete: the same facts always map to the same demand, so
		// refreshes and remounts reuse it rather than creating another.
		if ( ! empty( $attempt->demand_id ) ) {
			return array(
				'demand_id'   => $attempt->demand_id,
				'attempt_key' => $attempt->attempt_key,
			);
		}

		try {
			$api = WC_Edge_Client_Factory::client( $gateway->get_secret_key() );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'edge_not_configured', self::generic_failure() );
		}

		$built = self::build_resources( $api, $attempt, $facts );

		if ( is_wp_error( $built ) ) {
			return $built;
		}

		WC_Edge_Attempt_Store::update(
			$attempt->attempt_key,
			array( 'status' => WC_Edge_Attempt_Store::STATUS_PREPARED )
		);

		// A demand left over from superseded facts must not be mountable.
		WC_Edge_Attempt_Store::supersede_others( $facts['session_key'], $attempt->attempt_key );

		return array(
			'demand_id'   => $built['demand_id'],
			'attempt_key' => $attempt->attempt_key,
		);
	}

	/**
	 * The order this session is already paying for, if there is one.
	 *
	 * Preparing a second demand while the first order is settling gives a shopper
	 * two orders for the same goods - the reload case, where the checkout comes
	 * back with a full cart while the order placed from it is still in flight.
	 *
	 * Each candidate is synced before it is judged, so an order that settled
	 * while nothing was watching is resolved here rather than blocking the
	 * checkout until a poll happens to arrive.
	 *
	 * @param WC_Gateway_Edge $gateway     Configured gateway.
	 * @param string          $session_key Session identifier.
	 * @return WC_Order|null The order still being paid for, or null.
	 */
	public static function in_flight_order( WC_Gateway_Edge $gateway, $session_key ) {
		$since = gmdate( 'Y-m-d H:i:s', time() - self::IN_FLIGHT_WINDOW );

		foreach ( WC_Edge_Attempt_Store::find_recent_adopted( (string) $session_key, $since ) as $row ) {
			$order = wc_get_order( (int) $row->order_id );

			if ( ! $order instanceof WC_Order
				|| 'edge' !== $order->get_payment_method()
				|| 'on-hold' !== $order->get_status() ) {
				continue;
			}

			// The order may have been rebound to a newer demand after a decline,
			// in which case this row is history and says nothing about it.
			if ( ! hash_equals( (string) $order->get_meta( '_edge_demand_id' ), (string) $row->demand_id ) ) {
				continue;
			}

			if ( self::still_settling( $order, $gateway ) ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * Whether an on-hold order is still waiting on Edge.
	 *
	 * Anything that stops the sync from producing an answer counts as still in
	 * flight. Not knowing is not the same as knowing it is over, and the safe
	 * direction is to make the shopper wait rather than to let them buy twice.
	 *
	 * @param WC_Order        $order   Candidate order.
	 * @param WC_Gateway_Edge $gateway Configured gateway.
	 * @return bool
	 */
	private static function still_settling( WC_Order $order, WC_Gateway_Edge $gateway ) {
		try {
			$result = WC_Edge_Order_Sync::sync( $order, $gateway );
		} catch ( \Throwable $e ) {
			WC_Edge_Logger::info(
				'Could not read the payment state for order ' . $order->get_id() . ' while checking for one in flight.'
			);

			return true;
		}

		if ( is_wp_error( $result ) ) {
			return true;
		}

		return WC_Edge_Payment_Outcome::CHECKOUT_PROCESSING
			=== WC_Edge_Payment_Outcome::checkout_status( $result['state'] );
	}

	/**
	 * Revalidate the bound demand and confirm it.
	 *
	 * The same order can reach here twice. A shopper declined by their bank stays
	 * on the checkout, and the block reuses the order for the next Place Order,
	 * so what the demand is already doing decides whether confirming is the right
	 * move at all: money that has moved is never asked for again, a payment still
	 * in flight is reported as-is rather than raced, and only a demand that is
	 * idle or declined is confirmed. Every one of those is a success as far as the
	 * caller is concerned, because in each case the order has a payment to wait
	 * on - what differs is only whether this call is what started it.
	 *
	 * @param WC_Gateway_Edge $gateway   Gateway.
	 * @param WC_Order        $order     Order being paid.
	 * @param string          $submitted Demand id the browser sent, for cross-checking only.
	 * @return array|WP_Error `array{demand_id:string, prior_state:string}` - the state
	 *                        being the one the demand was in before this call touched it.
	 */
	public static function confirm( WC_Gateway_Edge $gateway, WC_Order $order, $submitted ) {
		$bound = (string) $order->get_meta( '_edge_demand_id' );

		if ( '' === $bound ) {
			WC_Edge_Logger::error( 'Order ' . $order->get_id() . ' has no bound Edge demand.' );

			return new WP_Error( 'edge_no_binding', self::generic_failure() );
		}

		// The browser's value never selects the resource; it only has to agree
		// with the binding the server already made. A mismatch means the page and
		// the order have diverged.
		if ( '' !== (string) $submitted && ! hash_equals( $bound, (string) $submitted ) ) {
			WC_Edge_Logger::error( 'Submitted demand does not match the order binding on order ' . $order->get_id() );

			return new WP_Error(
				'edge_binding_mismatch',
				__( 'Your payment session no longer matches this order. Please reload and try again.', 'edge-gateway' )
			);
		}

		try {
			$api = WC_Edge_Client_Factory::client( $gateway->get_secret_key() );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'edge_not_configured', self::generic_failure() );
		}

		$demand = self::fetch_demand( $api, $bound );

		if ( is_wp_error( $demand ) ) {
			return $demand;
		}

		$mismatch = self::revalidate( $demand, $order );

		if ( is_wp_error( $mismatch ) ) {
			return $mismatch;
		}

		$state = isset( $demand->data->attributes->processor_state )
			? (string) $demand->data->attributes->processor_state
			: '';

		// Taken alongside the state because the pair is what makes an ambiguous
		// confirm resolvable: a demand can come back in the state it started in
		// having been all the way round the cycle, and only the timestamp says so.
		$updated_at = isset( $demand->data->attributes->updated_at )
			? (string) $demand->data->attributes->updated_at
			: '';

		if ( in_array( $state, self::SETTLED_STATES, true ) ) {
			// Edge took the money for this order while the shopper was retrying -
			// a success that landed after they had given up on it. Confirming
			// again would be refused anyway; report it as paid and let the poll or
			// the webhook complete the order.
			WC_Edge_Logger::info( 'Demand ' . $bound . ' is already ' . $state . '; not confirming it again.' );

			return array(
				'demand_id'   => $bound,
				'prior_state' => $state,
			);
		}

		if ( in_array( $state, array( 'pending', 'processing' ), true ) ) {
			// An attempt is already in flight for this order. Confirming again
			// would race money that may be about to move, but refusing would be
			// wrong too: this is a duplicate submit for the payment the shopper is
			// already waiting on, and the honest answer to that is the one they
			// would have got the first time. The order stays on-hold and the
			// browser's observer waits for the same outcome.
			WC_Edge_Logger::info( 'Demand ' . $bound . ' is already ' . $state . '; waiting on it rather than confirming again.' );

			return array(
				'demand_id'   => $bound,
				'prior_state' => $state,
			);
		}

		if ( 'failed' === $state ) {
			WC_Edge_Logger::info( 'Retrying confirm for ' . $bound . ' after a decline' );
		}

		$confirmed = self::do_confirm( $api, $bound, $state, $updated_at );

		if ( is_wp_error( $confirmed ) ) {
			return $confirmed;
		}

		return array(
			'demand_id'   => $confirmed,
			'prior_state' => $state,
		);
	}

	/**
	 * Fetch a demand with its payment method included.
	 *
	 * @param WC_Edge_API_Client $api       Configured client.
	 * @param string             $demand_id Demand id.
	 * @return object|WP_Error
	 */
	private static function fetch_demand( WC_Edge_API_Client $api, $demand_id ) {
		try {
			return $api->get(
				'payment_demands/' . rawurlencode( $demand_id ),
				array( 'include' => 'payment_method' )
			);
		} catch ( \Throwable $e ) {
			WC_Edge_Logger::error( 'Could not read demand ' . $demand_id . ': ' . $e->getMessage() );

			return new WP_Error( 'edge_demand_unreadable', self::generic_failure() );
		}
	}

	/**
	 * Check the remote resource still matches the order it is bound to.
	 *
	 * A UUID being well formed says nothing about ownership, so every fact that
	 * defines the payment is compared against the order before any money moves.
	 *
	 * @param object   $demand Demand document.
	 * @param WC_Order $order  Order.
	 * @return true|WP_Error
	 */
	private static function revalidate( $demand, WC_Order $order ) {
		$attributes = isset( $demand->data->attributes ) ? $demand->data->attributes : null;

		if ( ! $attributes ) {
			return new WP_Error( 'edge_demand_malformed', self::generic_failure() );
		}

		try {
			$expected_cents = WC_Edge_Money::to_cents( $order->get_total() );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'edge_amount_invalid', self::generic_failure() );
		}

		$actual_cents = isset( $attributes->amount_cents ) ? (int) $attributes->amount_cents : -1;

		if ( $actual_cents !== $expected_cents ) {
			WC_Edge_Logger::error(
				sprintf(
					'Demand amount %d does not match order %d total %d.',
					$actual_cents,
					$order->get_id(),
					$expected_cents
				)
			);

			return new WP_Error(
				'edge_amount_mismatch',
				__( 'Your order total changed. Please reload the checkout and try again.', 'edge-gateway' )
			);
		}

		$currency = isset( $attributes->amount_currency ) ? strtoupper( (string) $attributes->amount_currency ) : '';

		if ( strtoupper( $order->get_currency() ) !== $currency ) {
			return new WP_Error( 'edge_currency_mismatch', self::generic_failure() );
		}

		$attempt_key = (string) $order->get_meta( '_edge_attempt_key' );
		$remote_key  = isset( $attributes->idempotency_key ) ? (string) $attributes->idempotency_key : '';

		if ( '' !== $attempt_key && ! hash_equals( $attempt_key, $remote_key ) ) {
			return new WP_Error( 'edge_attempt_mismatch', self::generic_failure() );
		}

		// The hosted form is what attaches a payment method, and only a confirmed
		// one is chargeable. Without this the confirm would fail at Edge with a
		// 422 listing the 3DS fields the iframe was supposed to supply.
		if ( ! self::has_confirmed_payment_method( $demand ) ) {
			return new WP_Error(
				'edge_card_not_verified',
				__( 'Your card has not been verified yet. Please complete the card form and try again.', 'edge-gateway' )
			);
		}

		return true;
	}

	/**
	 * Whether a chargeable payment method is attached.
	 *
	 * @param object $demand Demand document with `payment_method` included.
	 * @return bool
	 */
	private static function has_confirmed_payment_method( $demand ) {
		$related = isset( $demand->data->relationships->payment_method->data->id )
			? (string) $demand->data->relationships->payment_method->data->id
			: '';

		if ( '' === $related ) {
			return false;
		}

		foreach ( (array) ( isset( $demand->included ) ? $demand->included : array() ) as $resource ) {
			if ( ! isset( $resource->type, $resource->id ) || 'payment_methods' !== $resource->type ) {
				continue;
			}

			if ( (string) $resource->id !== $related ) {
				continue;
			}

			return isset( $resource->attributes->external_state )
				&& 'confirmed' === $resource->attributes->external_state;
		}

		// Related but not returned: treat as unverified rather than assuming.
		return false;
	}

	/**
	 * Confirm, resolving an ambiguous response rather than retrying blindly.
	 *
	 * A transport failure or a 5xx does not mean the confirm did not happen, so
	 * the authoritative state is read back before deciding anything. Retrying a
	 * confirm that already succeeded would be a second charge.
	 *
	 * @param WC_Edge_API_Client $api              Configured client.
	 * @param string             $demand_id        Demand id.
	 * @param string             $prior_state      State the demand was in before the PATCH.
	 * @param string             $prior_updated_at `updated_at` as it read before the PATCH.
	 * @return string|WP_Error
	 */
	private static function do_confirm( WC_Edge_API_Client $api, $demand_id, $prior_state, $prior_updated_at ) {
		try {
			$api->confirm( 'payment_demands', $demand_id );

			return $demand_id;
		} catch ( WC_Edge_API_Exception $e ) {
			$status = $e->get_status_code();

			if ( 422 === $status ) {
				WC_Edge_Logger::error( 'Confirm rejected for ' . $demand_id . ': ' . $e->getMessage() );

				return new WP_Error( 'edge_confirm_rejected', self::describe( $e ) );
			}

			// 0 is a transport failure; 405 means the state moved under us; 5xx
			// may have applied. All three are ambiguous until we look.
			if ( 0 === $status || 405 === $status || $status >= 500 ) {
				return self::resolve_ambiguous_confirm( $api, $demand_id, $prior_state, $prior_updated_at );
			}

			WC_Edge_Logger::error( 'Confirm failed for ' . $demand_id . ' (HTTP ' . $status . ')' );

			return new WP_Error( 'edge_confirm_failed', self::generic_failure() );
		} catch ( \Throwable $e ) {
			return self::resolve_ambiguous_confirm( $api, $demand_id, $prior_state, $prior_updated_at );
		}
	}

	/**
	 * Read the demand back and decide whether the confirm took effect.
	 *
	 * What the state means depends on where it started, and the state alone does
	 * not say. A retry of a declined payment runs `failed -> pending ->
	 * processing -> failed`, and in the sandbox that whole cycle can finish
	 * inside the window this is resolving, so reading `failed` again is no proof
	 * the PATCH never landed. Re-sending it on that reading would be a second
	 * authorisation the shopper never asked for.
	 *
	 * `updated_at` is what settles it. Unchanged, alongside an unchanged state,
	 * means the demand has not been touched since the read before the PATCH and a
	 * single retry is safe. Changed means the PATCH landed and the state on the
	 * other side of it is the answer.
	 *
	 * @param WC_Edge_API_Client $api              Configured client.
	 * @param string             $demand_id        Demand id.
	 * @param string             $prior_state      State the demand was in before the PATCH.
	 * @param string             $prior_updated_at `updated_at` as it read before the PATCH.
	 * @return string|WP_Error
	 */
	private static function resolve_ambiguous_confirm( WC_Edge_API_Client $api, $demand_id, $prior_state, $prior_updated_at ) {
		$demand = self::fetch_demand( $api, $demand_id );

		if ( is_wp_error( $demand ) ) {
			return new WP_Error( 'edge_confirm_unresolved', self::generic_failure() );
		}

		$state = isset( $demand->data->attributes->processor_state )
			? (string) $demand->data->attributes->processor_state
			: '';

		$updated_at = isset( $demand->data->attributes->updated_at )
			? (string) $demand->data->attributes->updated_at
			: '';

		WC_Edge_Logger::info( 'Resolving ambiguous confirm for ' . $demand_id . '; state is ' . $state );

		// Already moving through the processor: the confirm landed.
		if ( in_array( $state, array( 'pending', 'processing', 'succeeded' ), true ) ) {
			return $demand_id;
		}

		// Untouched since the read before the PATCH, and still confirmable, so
		// nothing was applied. One retry only.
		//
		// An empty prior state means the attribute was missing rather than that
		// the demand was idle, and an empty timestamp is not evidence of anything,
		// so neither is allowed to authorise a second PATCH.
		if ( '' !== $prior_state
			&& '' !== $prior_updated_at
			&& $state === $prior_state
			&& $updated_at === $prior_updated_at
			&& in_array( $state, self::RETRY_STATES, true ) ) {
			try {
				$api->confirm( 'payment_demands', $demand_id );

				return $demand_id;
			} catch ( \Throwable $e ) {
				WC_Edge_Logger::error( 'Confirm retry failed for ' . $demand_id . ': ' . $e->getMessage() );

				return new WP_Error( 'edge_confirm_failed', self::generic_failure() );
			}
		}

		// The demand moved, so the confirm did land, and `failed` on the far side
		// of it is the bank's answer. An untouched `failed` demand never reaches
		// here - the retry above takes it.
		if ( 'failed' === $state ) {
			return new WP_Error(
				'edge_payment_failed',
				__( 'Your payment was declined. Please try another card.', 'edge-gateway' )
			);
		}

		return new WP_Error( 'edge_confirm_unresolved', self::generic_failure() );
	}

	/**
	 * Create whatever the attempt is still missing.
	 *
	 * Each id is persisted the moment it is known, so a retry after a lost
	 * response resumes from the first gap instead of creating a second customer
	 * or address. Only payment_demands carries an idempotency key; customers and
	 * consumer_addresses do not, so this is the only protection they get.
	 *
	 * @param WC_Edge_API_Client $api     Configured client.
	 * @param object             $attempt Attempt row.
	 * @param array              $facts   Collected facts.
	 * @return array|WP_Error
	 */
	private static function build_resources( WC_Edge_API_Client $api, $attempt, array $facts ) {
		$customer_id = $attempt->customer_id;

		if ( empty( $customer_id ) ) {
			$created = self::create(
				$api,
				'customers',
				WC_Edge_Order_Mapper::customer_document(
					trim( $facts['billing_first_name'] . ' ' . $facts['billing_last_name'] ),
					$facts['billing_email'],
					$facts['billing_phone']
				)
			);

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			$customer_id = $created;
			WC_Edge_Attempt_Store::update( $attempt->attempt_key, array( 'customer_id' => $customer_id ) );
		}

		$billing_id = $attempt->billing_address_id;

		if ( empty( $billing_id ) ) {
			$document = WC_Edge_Order_Mapper::address_document( $facts['billing'], $customer_id );

			if ( is_wp_error( $document ) ) {
				return $document;
			}

			$created = self::create( $api, 'consumer_addresses', $document );

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			$billing_id = $created;
			WC_Edge_Attempt_Store::update( $attempt->attempt_key, array( 'billing_address_id' => $billing_id ) );
		}

		$shipping_id = $attempt->shipping_address_id;

		if ( empty( $shipping_id ) && $facts['has_distinct_shipping'] ) {
			$document = WC_Edge_Order_Mapper::address_document( $facts['shipping'], $customer_id );

			// A shipping address we cannot map is not worth failing checkout
			// over: the API falls back to billing, which is what a
			// non-shippable order would use anyway.
			if ( ! is_wp_error( $document ) ) {
				$created = self::create( $api, 'consumer_addresses', $document );

				if ( ! is_wp_error( $created ) ) {
					$shipping_id = $created;
					WC_Edge_Attempt_Store::update(
						$attempt->attempt_key,
						array( 'shipping_address_id' => $shipping_id )
					);
				}
			}
		}

		$demand_id = self::create(
			$api,
			'payment_demands',
			WC_Edge_Order_Mapper::demand_document(
				array(
					'amount_cents'        => $facts['amount_cents'],
					'currency'            => $facts['currency'],
					'description'         => $facts['description'],
					'reference'           => $attempt->attempt_key,
					'idempotency_key'     => $attempt->attempt_key,
					'customer_id'         => $customer_id,
					'billing_address_id'  => $billing_id,
					'shipping_address_id' => $shipping_id,
					'cart'                => isset( $facts['cart'] ) ? $facts['cart'] : array(),
				)
			)
		);

		if ( is_wp_error( $demand_id ) ) {
			return $demand_id;
		}

		// The attempt key *is* the idempotency key - see demand_document() above -
		// so there is nothing else to store. Keeping a second copy would only
		// create a way for the two to disagree.
		WC_Edge_Attempt_Store::update( $attempt->attempt_key, array( 'demand_id' => $demand_id ) );

		return array( 'demand_id' => $demand_id );
	}

	/**
	 * POST a document and return the new resource id.
	 *
	 * @param WC_Edge_API_Client $api      Configured client.
	 * @param string             $endpoint Resource endpoint.
	 * @param array              $document JSON:API document.
	 * @return string|WP_Error
	 */
	private static function create( WC_Edge_API_Client $api, $endpoint, array $document ) {
		try {
			$response = $api->create( $endpoint, $document );
		} catch ( WC_Edge_API_Exception $e ) {
			WC_Edge_Logger::error(
				sprintf( 'Creating %s failed (HTTP %d): %s', $endpoint, $e->get_status_code(), $e->getMessage() )
			);

			return new WP_Error( 'edge_request_failed', self::describe( $e ) );
		} catch ( \Throwable $e ) {
			WC_Edge_Logger::error( sprintf( 'Creating %s errored: %s', $endpoint, $e->getMessage() ) );

			return new WP_Error( 'edge_request_failed', self::generic_failure() );
		}

		if ( empty( $response->data->id ) ) {
			return new WP_Error( 'edge_response_malformed', self::generic_failure() );
		}

		return (string) $response->data->id;
	}

	/**
	 * Turn an Edge validation failure into something a shopper can act on.
	 *
	 * The exception message is the first error's `title`, which for a changeset
	 * failure is bare text like "can't be blank" - true but useless without
	 * knowing which field. The pointer carries that, so the message is built
	 * from it instead.
	 *
	 * @param WC_Edge_API_Exception $e Exception.
	 * @return string
	 */
	private static function describe( WC_Edge_API_Exception $e ) {
		if ( 422 !== $e->get_status_code() ) {
			return self::generic_failure();
		}

		$fields = array();

		foreach ( (array) $e->get_errors() as $error ) {
			$pointer = isset( $error['source']['pointer'] ) ? $error['source']['pointer'] : '';
			$field   = self::field_from_pointer( $pointer );

			if ( '' !== $field ) {
				$fields[ $field ] = true;
			}
		}

		if ( empty( $fields ) ) {
			return self::generic_failure();
		}

		return sprintf(
			/* translators: %s: comma separated list of checkout field names. */
			__( 'Please check these details and try again: %s.', 'edge-gateway' ),
			implode( ', ', array_keys( $fields ) )
		);
	}

	/**
	 * Map a JSON:API pointer to a shopper-facing field name.
	 *
	 * @param string $pointer e.g. /data/attributes/amount_cents.
	 * @return string
	 */
	private static function field_from_pointer( $pointer ) {
		$known = array(
			'line_1'          => __( 'address', 'edge-gateway' ),
			'line_2'          => __( 'address', 'edge-gateway' ),
			'city'            => __( 'town or city', 'edge-gateway' ),
			'state'           => __( 'state or county', 'edge-gateway' ),
			'zip'             => __( 'postcode', 'edge-gateway' ),
			'country'         => __( 'country', 'edge-gateway' ),
			'email'           => __( 'email address', 'edge-gateway' ),
			'name'            => __( 'name', 'edge-gateway' ),
			'phone_number'    => __( 'phone number', 'edge-gateway' ),
			'amount_cents'    => __( 'order total', 'edge-gateway' ),
			'amount_currency' => __( 'currency', 'edge-gateway' ),
		);

		$leaf = basename( (string) $pointer );

		return isset( $known[ $leaf ] ) ? $known[ $leaf ] : '';
	}

	/**
	 * Copy for failures a shopper cannot diagnose.
	 *
	 * @return string
	 */
	private static function generic_failure() {
		return __( 'We could not start your card payment. Please try again in a moment.', 'edge-gateway' );
	}

	/**
	 * Read every fact the payment depends on, server-side.
	 *
	 * Nothing here comes from the request body: the browser is not trusted with
	 * the amount, the currency, the mode, or the cart.
	 *
	 * @param WC_Gateway_Edge $gateway Gateway.
	 * @return array|WP_Error
	 */
	private static function collect_facts( WC_Gateway_Edge $gateway ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return new WP_Error( 'edge_cart_empty', __( 'Your cart is empty.', 'edge-gateway' ) );
		}

		if ( ! WC()->session || ! WC()->session->get_customer_id() ) {
			return new WP_Error( 'edge_no_session', self::generic_failure() );
		}

		$currency = get_woocommerce_currency();

		if ( ! WC_Edge_Money::is_supported_currency( $currency ) ) {
			return new WP_Error(
				'edge_currency_unsupported',
				__( 'Card payments are only available for orders in US dollars.', 'edge-gateway' )
			);
		}

		try {
			$amount_cents = WC_Edge_Money::to_cents( WC()->cart->get_total( 'edit' ) );
		} catch ( InvalidArgumentException $e ) {
			WC_Edge_Logger::error( 'Cart total could not be converted: ' . $e->getMessage() );

			return new WP_Error( 'edge_amount_invalid', self::generic_failure() );
		}

		if ( ! WC_Edge_Money::is_chargeable( $amount_cents ) ) {
			return new WP_Error(
				'edge_amount_too_small',
				__( 'This order is below the minimum for card payments.', 'edge-gateway' )
			);
		}

		$customer = WC()->customer;

		$billing = array(
			'address_1' => $customer->get_billing_address_1(),
			'address_2' => $customer->get_billing_address_2(),
			'city'      => $customer->get_billing_city(),
			'state'     => $customer->get_billing_state(),
			'postcode'  => $customer->get_billing_postcode(),
			'country'   => $customer->get_billing_country(),
		);

		$shipping = array(
			'address_1' => $customer->get_shipping_address_1(),
			'address_2' => $customer->get_shipping_address_2(),
			'city'      => $customer->get_shipping_city(),
			'state'     => $customer->get_shipping_state(),
			'postcode'  => $customer->get_shipping_postcode(),
			'country'   => $customer->get_shipping_country(),
		);

		$email = $customer->get_billing_email();

		if ( '' === trim( (string) $email ) ) {
			return new WP_Error(
				'edge_email_required',
				__( 'Please enter your email address before paying.', 'edge-gateway' )
			);
		}

		$cart = WC_Edge_Cart_Items::collect( WC()->cart );

		if ( empty( $cart['complete'] ) ) {
			// Not fatal: the payment goes through either way, and Edge falls
			// back to a single aggregate line. Worth knowing about, though, so
			// log the cause - the reason string only, never the cart itself.
			WC_Edge_Logger::info(
				'Cart could not be itemised for Edge: ' . (string) $cart['reason']
			);
		}

		return array(
			// Fingerprint inputs.
			'cart_hash'             => WC()->cart->get_cart_hash(),
			'itemisation_hash'      => (string) $cart['hash'],
			'amount_cents'          => $amount_cents,
			'currency'              => $currency,
			'mode'                  => (string) $gateway->get_mode(),
			'publishable_key'       => $gateway->get_publishable_key(),
			'billing_first_name'    => $customer->get_billing_first_name(),
			'billing_last_name'     => $customer->get_billing_last_name(),
			'billing_email'         => $email,
			'billing_phone'         => $customer->get_billing_phone(),
			'billing_address_1'     => $billing['address_1'],
			'billing_address_2'     => $billing['address_2'],
			'billing_city'          => $billing['city'],
			'billing_state'         => $billing['state'],
			'billing_postcode'      => $billing['postcode'],
			'billing_country'       => $billing['country'],
			'shipping_first_name'   => $customer->get_shipping_first_name(),
			'shipping_last_name'    => $customer->get_shipping_last_name(),
			'shipping_address_1'    => $shipping['address_1'],
			'shipping_address_2'    => $shipping['address_2'],
			'shipping_city'         => $shipping['city'],
			'shipping_state'        => $shipping['state'],
			'shipping_postcode'     => $shipping['postcode'],
			'shipping_country'      => $shipping['country'],

			// Working values.
			'cart'                  => $cart,
			'session_key'           => (string) WC()->session->get_customer_id(),
			'billing'               => $billing,
			'shipping'              => $shipping,
			'has_distinct_shipping' => WC()->cart->needs_shipping()
				&& '' !== trim( (string) $shipping['address_1'] )
				&& $shipping !== $billing,
			'description'           => sprintf(
				/* translators: %s: site name. */
				__( '%s order', 'edge-gateway' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
		);
	}
}
