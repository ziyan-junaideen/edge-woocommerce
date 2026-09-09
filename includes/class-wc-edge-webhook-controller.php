<?php
/**
 * Receives Edge webhooks and moves orders to their final state.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves `POST /wp-json/edge/v1/webhook`.
 *
 * Webhooks are what actually complete an order: confirming only tells Edge to
 * start processing. Everything here is built around not trusting the delivery
 * itself.
 */
final class WC_Edge_Webhook_Controller {

	const NAMESPACE_V1 = 'edge/v1';
	const ROUTE        = '/webhook';

	/**
	 * Header carrying Edge's signature.
	 *
	 * The scheme itself lives in WC_Edge_Webhook_Signature.
	 *
	 * @var string
	 */
	const SIGNATURE_HEADER = WC_Edge_Webhook_Signature::HEADER;

	/**
	 * Meta recording the last refund state applied to a refund row.
	 *
	 * Deliveries repeat, so this is what makes applying one twice a no-op.
	 *
	 * @var string
	 */
	const REFUND_STATE_META = '_edge_refund_state';

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Authentication is the signature check inside the handler: this
				// endpoint is called by Edge, which has no WordPress identity.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle a delivery.
	 *
	 * Almost every outcome is a 200. Edge treats 400, 401, 403, 404 and 405 as
	 * terminal and permanently stops retrying, so returning one of those for a
	 * problem on our side would silently discard the event - and with it, the
	 * order's only route out of on-hold. A 5xx is reserved for authenticated
	 * events that genuinely deserve a retry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$signature = (string) $request->get_header( self::SIGNATURE_HEADER );

		// The raw bytes, not the parsed body: the signature covers what was sent,
		// and re-encoding a decoded payload does not reproduce it - JSON key
		// order is not stable.
		$mode = self::trusted_mode( $signature, $request->get_body() );

		if ( null === $mode ) {
			// Never log the supplied signature: it is a bearer credential.
			WC_Edge_Logger::error( 'Rejected a webhook with an unrecognised signature.' );

			return self::ok( 'ignored' );
		}

		$event = self::extract_event( $request->get_json_params() );

		if ( ! $event ) {
			WC_Edge_Logger::error( 'Rejected a webhook with an unreadable payload.' );

			return self::ok( 'ignored' );
		}

		// First insert wins, which both deduplicates retries and serialises
		// concurrent deliveries of the same event.
		if ( ! WC_Edge_Webhook_Store::claim( $event['id'], $event + array( 'mode' => $mode ) ) ) {
			return self::ok( 'duplicate' );
		}

		try {
			$applied = self::apply( $event, $mode );
		} catch ( \Throwable $e ) {
			// Let the claim go so Edge's retry is not a no-op.
			WC_Edge_Webhook_Store::release( $event['id'] );
			WC_Edge_Logger::error( 'Webhook handling failed: ' . $e->getMessage() );

			return new WP_REST_Response( array( 'status' => 'retry' ), 500 );
		}

		return self::ok( $applied );
	}

	/**
	 * Work out which mode a delivery belongs to from its signature.
	 *
	 * The signature is checked against every stored subscription secret, and the
	 * one that verifies decides the mode. The payload also carries a `mode`, but
	 * it is unauthenticated, so using it to choose which API key to trust would
	 * let a caller pick their own credentials.
	 *
	 * @param string $signature Supplied `edge-signature` header.
	 * @param string $body      Raw request body, exactly as received.
	 * @return string|null Trusted mode, or null.
	 */
	private static function trusted_mode( $signature, $body ) {
		$parsed = WC_Edge_Webhook_Signature::parse( $signature );

		if ( ! $parsed ) {
			return null;
		}

		// The timestamp is signed, so a delivery cannot be back-dated - but an
		// intact one could still be captured and replayed, which is what the
		// freshness window closes.
		if ( ! WC_Edge_Webhook_Signature::is_fresh( $parsed['timestamp'], time() ) ) {
			WC_Edge_Logger::error( 'Rejected a webhook whose timestamp is outside the accepted window.' );

			return null;
		}

		foreach ( WC_Edge_Subscription_Reconciler::secrets_by_mode() as $mode => $secret ) {
			if ( WC_Edge_Webhook_Signature::matches( $parsed, $body, $secret ) ) {
				return $mode;
			}
		}

		return null;
	}

	/**
	 * Normalise the two envelope shapes Edge sends.
	 *
	 * Which one arrives is a per-merchant flag on Edge's side, not something a
	 * subscription can select, so both have to be accepted.
	 *
	 * @param mixed $body Decoded JSON body.
	 * @return array|null
	 */
	private static function extract_event( $body ) {
		if ( ! is_array( $body ) ) {
			return null;
		}

		// v2 nests under `data`; v1 is the same object unwrapped.
		$event = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;

		if ( empty( $event['id'] ) || empty( $event['attributes'] ) ) {
			return null;
		}

		$attributes = $event['attributes'];

		return array(
			'id'            => (string) $event['id'],
			'resource_type' => isset( $attributes['resource_type'] ) ? (string) $attributes['resource_type'] : '',
			'resource_id'   => isset( $attributes['resource_id'] ) ? (string) $attributes['resource_id'] : '',
			'slug'          => isset( $attributes['slug'] ) ? (string) $attributes['slug'] : '',
		);
	}

	/**
	 * Re-read the resource and move the order accordingly.
	 *
	 * @param array  $event Normalised event.
	 * @param string $mode  Trusted mode.
	 * @return string Outcome label.
	 * @throws RuntimeException When the gateway is unavailable, so the caller
	 *                          releases the dedup claim and Edge can retry.
	 */
	private static function apply( array $event, $mode ) {
		if ( '' === $event['resource_id'] ) {
			return 'unhandled';
		}

		if ( 'transaction.refund_demands' === $event['resource_type'] ) {
			return self::apply_refund( $event, $mode );
		}

		if ( 'transaction.payment_demands' !== $event['resource_type'] ) {
			return 'unhandled';
		}

		$order = self::find_order( $event['resource_id'], $mode );

		if ( ! $order ) {
			// Not ours - another site can share a subscription, and Edge also
			// emits for payments made outside WooCommerce.
			return 'unknown-order';
		}

		WC_Edge_Webhook_Store::attach_order( $event['id'], $order->get_id() );

		$gateway = self::gateway();

		if ( ! $gateway instanceof WC_Gateway_Edge ) {
			throw new RuntimeException( 'Edge gateway unavailable while handling a webhook.' );
		}

		$api = WC_Edge_Client_Factory::client( $gateway->get_secret_key() );

		// The payload carries a state, but the signature does not authenticate
		// the body, so it is only a hint that something changed. This is the
		// authoritative read.
		$demand = $api->get( 'payment_demands/' . rawurlencode( $event['resource_id'] ) );

		$state = isset( $demand->data->attributes->processor_state )
			? (string) $demand->data->attributes->processor_state
			: '';

		self::record_risk_signals( $order, $demand );

		return self::transition( $order, $state, $event['resource_id'] );
	}

	/**
	 * Move an order in response to a refund demand event.
	 *
	 * A refund names its payment demand, and the payment demand is already the
	 * order's binding, so the existing lookup does all the work - no second
	 * index and no new meta to search on.
	 *
	 * @param array  $event Normalised event.
	 * @param string $mode  Trusted mode.
	 * @return string Outcome label.
	 * @throws RuntimeException When the gateway is unavailable, or when the
	 *                          refund is one this site is still in the middle of
	 *                          creating, so the caller releases the dedup claim
	 *                          and Edge retries.
	 * @throws WC_Edge_API_Exception When the refund cannot be read for a reason
	 *                          that a retry could still fix.
	 */
	private static function apply_refund( array $event, $mode ) {
		$gateway = self::gateway();

		if ( ! $gateway instanceof WC_Gateway_Edge ) {
			throw new RuntimeException( 'Edge gateway unavailable while handling a refund webhook.' );
		}

		$api = WC_Edge_Client_Factory::client( $gateway->get_secret_key() );

		// Authoritative read, for the same reason payment demands get one: the
		// signature covers the delivery, not the body.
		//
		// Unlike the payment branch this happens before the order lookup, since
		// the refund is what names the payment demand the order is bound to. So
		// a refund belonging to another mode or another merchant reaches here,
		// and its 404 has to end the delivery rather than escape as a 500 - a
		// subscription for the mode the gateway is no longer configured for goes
		// on delivering, and every retry would be another round trip.
		try {
			$document = $api->get( 'refund_demands/' . rawurlencode( $event['resource_id'] ) );
		} catch ( WC_Edge_API_Exception $e ) {
			$status = $e->get_status_code();

			if ( 404 === $status || 403 === $status ) {
				WC_Edge_Logger::info( 'Refund ' . $event['resource_id'] . ' is not readable with these keys; not ours.' );

				return 'unknown-refund';
			}

			throw $e;
		}

		$demand_id = WC_Edge_Refund_Outcome::payment_demand_id( $document );

		if ( '' === $demand_id ) {
			return 'unknown-refund';
		}

		$order = self::find_order( $demand_id, $mode );

		if ( ! $order ) {
			return 'unknown-order';
		}

		WC_Edge_Webhook_Store::attach_order( $event['id'], $order->get_id() );

		$refund_demand = isset( $document->data ) ? $document->data : null;
		$state         = WC_Edge_Refund_Outcome::state_of( $refund_demand );
		$refund        = self::find_refund( $order, $event['resource_id'] );

		if ( ! $refund ) {
			$key = isset( $refund_demand->attributes->idempotency_key )
				? (string) $refund_demand->attributes->idempotency_key
				: '';

			// Edge records the created event inside the transaction that creates
			// the refund, so an event can overtake the response that would have
			// told us which refund we just made. The key was written down before
			// the request went out, so it says "this is ours, we are just not
			// finished writing it down". Throwing releases the claim; returning
			// 200 would consume it and lose the event for good.
			$pending = WC_Edge_Refund_Service::pending( $order );

			if ( '' !== $key && isset( $pending[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic text for the log; the caller turns this into a bare 500.
				throw new RuntimeException( 'Refund ' . $event['resource_id'] . ' is still being recorded.' );
			}

			return self::note_foreign_refund( $order, $event['resource_id'], $refund_demand, $state );
		}

		return self::transition_refund( $order, $refund, $event['resource_id'], $state );
	}

	/**
	 * Apply a refund state to the WooCommerce refund it belongs to.
	 *
	 * Nothing here changes the order's status. WooCommerce set that when the
	 * refund row was created, and a refund demand reaching a terminal state is
	 * news about money, not about the order's lifecycle.
	 *
	 * @param WC_Order        $order     Order.
	 * @param WC_Order_Refund $refund    WooCommerce refund row.
	 * @param string          $refund_id Edge refund demand id.
	 * @param string          $state     Authoritative refund state.
	 * @return string Outcome label.
	 */
	private static function transition_refund( WC_Order $order, WC_Order_Refund $refund, $refund_id, $state ) {
		$recorded = (string) $refund->get_meta( self::REFUND_STATE_META );

		if ( $recorded === $state ) {
			// Deliveries repeat and arrive out of order; saying so twice on the
			// order would be noise.
			return 'already-recorded';
		}

		switch ( $state ) {
			case 'succeeded':
				$refund->update_meta_data( self::REFUND_STATE_META, $state );
				$refund->delete_meta_data( WC_Edge_Refund_Service::FAILED_META );
				$refund->save();

				// The order-level marker stands for every refund on the order, so
				// one succeeding does not clear a sibling's failure.
				if ( ! self::has_failed_refund( $order ) ) {
					$order->delete_meta_data( WC_Edge_Refund_Service::FAILED_META );
				}

				$order->add_order_note(
					sprintf(
						/* translators: 1: refund amount, 2: Edge refund demand id. */
						__( 'Edge confirmed the refund of %1$s (%2$s).', 'edge-gateway' ),
						wc_price( $refund->get_amount(), array( 'currency' => $order->get_currency() ) ),
						$refund_id
					)
				);
				$order->save();

				return 'refund-succeeded';

			case 'failed':
				$refund->update_meta_data( self::REFUND_STATE_META, $state );
				$refund->update_meta_data( WC_Edge_Refund_Service::FAILED_META, 'yes' );
				$refund->save();

				$order->update_meta_data( WC_Edge_Refund_Service::FAILED_META, 'yes' );
				$order->add_order_note(
					sprintf(
						/* translators: 1: refund amount, 2: Edge refund demand id. */
						__( 'Edge could not process the refund of %1$s (%2$s). The money was NOT returned to the customer, but WooCommerce has already recorded the refund, restocked the items and emailed the customer. Delete the refund on this order to put the balance and the stock back, then decide whether to try again.', 'edge-gateway' ),
						wc_price( $refund->get_amount(), array( 'currency' => $order->get_currency() ) ),
						$refund_id
					)
				);
				$order->save();

				return 'refund-failed';

			case 'pending':
			case 'processing':
				$refund->update_meta_data( self::REFUND_STATE_META, $state );
				$refund->save();

				return 'no-change';

			default:
				$refund->update_meta_data( self::REFUND_STATE_META, $state );
				$refund->save();

				$order->add_order_note(
					sprintf(
						/* translators: %s: unrecognised refund state. */
						__( 'Edge reported an unrecognised refund state: %s.', 'edge-gateway' ),
						$state
					)
				);
				$order->save();

				return 'unrecognised-state';
		}
	}

	/**
	 * Record a refund this site did not create.
	 *
	 * Refunds can be issued from the Edge dashboard. No WooCommerce refund is
	 * synthesised for one: creating order state from a webhook would restock and
	 * email on Edge's schedule rather than the merchant's. The note is enough to
	 * reconcile from.
	 *
	 * @param WC_Order $order         Order.
	 * @param string   $refund_id     Edge refund demand id.
	 * @param mixed    $refund_demand Decoded refund resource.
	 * @param string   $state         Refund state.
	 * @return string Outcome label.
	 */
	private static function note_foreign_refund( WC_Order $order, $refund_id, $refund_demand, $state ) {
		// A refund passes through processing on its way to a terminal state, and
		// both arrive as `.updated`. Noting only the outcome keeps one dashboard
		// refund to one order note.
		if ( 'succeeded' !== $state && 'failed' !== $state ) {
			return 'no-change';
		}

		$cents = isset( $refund_demand->attributes->amount_cents )
			? (int) $refund_demand->attributes->amount_cents
			: 0;

		$order->add_order_note(
			sprintf(
				/* translators: 1: refund amount, 2: refund state, 3: Edge refund demand id. */
				__( 'Edge reported a refund of %1$s (%2$s) that was not created in WooCommerce: %3$s. Reconcile it here if it should show on this order.', 'edge-gateway' ),
				wc_price( WC_Edge_Money::from_cents( $cents ), array( 'currency' => $order->get_currency() ) ),
				$state,
				$refund_id
			)
		);
		$order->save();

		return 'foreign-refund';
	}

	/**
	 * Whether any refund on the order is still marked failed.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function has_failed_refund( WC_Order $order ) {
		foreach ( $order->get_refunds() as $refund ) {
			if ( $refund instanceof WC_Order_Refund
				&& 'yes' === $refund->get_meta( WC_Edge_Refund_Service::FAILED_META ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The WooCommerce refund row standing for an Edge refund demand.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $refund_id Edge refund demand id.
	 * @return WC_Order_Refund|null
	 */
	private static function find_refund( WC_Order $order, $refund_id ) {
		foreach ( $order->get_refunds() as $refund ) {
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}

			if ( (string) $refund->get_meta( WC_Edge_Refund_Service::DEMAND_META ) === (string) $refund_id ) {
				return $refund;
			}
		}

		return null;
	}

	/**
	 * Apply a state to an order, never downgrading it.
	 *
	 * Deliveries can arrive out of order and more than once, so every transition
	 * has to be safe to repeat and safe to receive late. A paid order is never
	 * moved backwards by an older failure.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $state     Authoritative processor state.
	 * @param string   $demand_id Demand id.
	 * @return string Outcome label.
	 */
	private static function transition( WC_Order $order, $state, $demand_id ) {
		$already_paid = $order->is_paid();

		switch ( $state ) {
			case 'succeeded':
				if ( $already_paid ) {
					return 'already-paid';
				}

				$order->payment_complete( $demand_id );
				$order->add_order_note( __( 'Edge confirmed this payment succeeded.', 'edge-gateway' ) );

				return 'completed';

			case 'failed':
				if ( $already_paid ) {
					// Arriving after a success means it is stale. Record it, but
					// do not un-pay an order.
					$order->add_order_note(
						__( 'Edge reported a failure for a payment already marked paid. Not changing the order.', 'edge-gateway' )
					);

					return 'ignored-stale-failure';
				}

				$order->update_status( 'failed', __( 'Edge declined this payment.', 'edge-gateway' ) );

				return 'failed';

			case 'reversed':
			case 'refunded':
			case 'disputed':
				$order->update_meta_data( '_edge_processor_state', $state );
				$order->add_order_note(
					sprintf(
						/* translators: %s: Edge processor state. */
						__( 'Edge reported this payment as %s. Reconcile it in the Edge dashboard.', 'edge-gateway' ),
						$state
					)
				);
				$order->save();

				return $state;

			case 'pending':
			case 'processing':
			case 'incomplete':
			case 'ready':
			case 'confirmed':
			case 'canceled':
				return 'no-change';

			default:
				$order->add_order_note(
					sprintf(
						/* translators: %s: unrecognised state. */
						__( 'Edge reported an unrecognised payment state: %s.', 'edge-gateway' ),
						$state
					)
				);

				return 'unrecognised-state';
		}
	}

	/**
	 * Keep the fraud and authentication results with the order.
	 *
	 * These are the evidence a merchant needs if a payment is ever disputed, and
	 * they are not retrievable once the order is closed out.
	 *
	 * @param WC_Order $order  Order.
	 * @param object   $demand Demand document.
	 * @return void
	 */
	private static function record_risk_signals( WC_Order $order, $demand ) {
		$attributes = isset( $demand->data->attributes ) ? $demand->data->attributes : null;

		if ( ! $attributes ) {
			return;
		}

		$signals = array(
			'cvc2_check',
			'address_line1_verification',
			'postal_code_verification',
			'threeds_status',
			'threeds_version',
			'eci',
		);

		$changed = false;

		foreach ( $signals as $signal ) {
			if ( isset( $attributes->$signal ) && '' !== (string) $attributes->$signal ) {
				$order->update_meta_data( '_edge_' . $signal, (string) $attributes->$signal );
				$changed = true;
			}
		}

		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Find the order bound to a demand, in the trusted mode.
	 *
	 * @param string $demand_id Demand id.
	 * @param string $mode      Trusted mode.
	 * @return WC_Order|null
	 */
	private static function find_order( $demand_id, $mode ) {
		// wc_get_orders() rather than a meta query, so this works under HPOS as
		// well as post storage.
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'status'     => 'any',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_edge_demand_id',
						'value' => $demand_id,
					),
					array(
						'key'   => '_edge_mode',
						'value' => $mode,
					),
				),
			)
		);

		return ! empty( $orders ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
	}

	/**
	 * The registered gateway instance.
	 *
	 * @return WC_Payment_Gateway|null
	 */
	private static function gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return null;
		}

		$gateways = WC()->payment_gateways->payment_gateways();

		return isset( $gateways['edge'] ) ? $gateways['edge'] : null;
	}

	/**
	 * A 200 carrying what was done.
	 *
	 * @param string $status Outcome label.
	 * @return WP_REST_Response
	 */
	private static function ok( $status ) {
		return new WP_REST_Response( array( 'status' => $status ), 200 );
	}
}
