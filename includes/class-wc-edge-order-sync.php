<?php
/**
 * Applies an Edge payment demand's authoritative state to its order.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.4.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place an order learns what happened to its payment.
 *
 * Edge settles asynchronously, so the outcome arrives twice over: the webhook is
 * authoritative, and the checkout poll is the shopper's copy of the same news.
 * They routinely arrive together, because Edge delivers the event at about the
 * moment the browser next asks. Sharing this code is what stops the two of them
 * completing an order twice, and the demand lock is what serialises them.
 *
 * The decision itself is not here - it is in WC_Edge_Payment_Outcome, which has
 * no WordPress in it and can be tested. This is the part that writes.
 */
final class WC_Edge_Order_Sync {

	/**
	 * Read the demand and apply what it says to the order.
	 *
	 * The order handed in is only used for its id and its binding: the state is
	 * re-read from the database inside the lock, because the caller may have been
	 * holding its copy since before another request moved the order.
	 *
	 * @param WC_Order        $order   Order to sync.
	 * @param WC_Gateway_Edge $gateway Configured gateway.
	 * @return array|WP_Error `array{state:string, outcome:string, attributes:object|null}`.
	 * @throws WC_Edge_API_Exception When the demand cannot be read. The caller
	 *                               decides whether that is worth a retry.
	 */
	public static function sync( WC_Order $order, WC_Gateway_Edge $gateway ) {
		$demand_id = (string) $order->get_meta( '_edge_demand_id' );

		if ( '' === $demand_id ) {
			return new WP_Error( 'edge_no_binding', __( 'This order is not bound to an Edge payment.', 'edge-gateway' ) );
		}

		$owner = WC_Edge_Demand_Lock::acquire( $demand_id );

		if ( false === $owner ) {
			// Somebody else is applying this demand right now - the other sync
			// caller, or process_payment() still writing the order it has just
			// confirmed. Callers differ on what to do about it: the webhook asks
			// Edge to redeliver, the poll tells the shopper to keep waiting.
			return new WP_Error( 'edge_sync_locked', __( 'This payment is already being updated.', 'edge-gateway' ) );
		}

		try {
			$fresh = self::reload_order( $order->get_id() );

			if ( ! $fresh instanceof WC_Order ) {
				return new WP_Error( 'edge_no_binding', __( 'That order could not be found.', 'edge-gateway' ) );
			}

			$bound = (string) $fresh->get_meta( '_edge_demand_id' );

			if ( ! hash_equals( $demand_id, $bound ) ) {
				// A declined shopper who edits their details gets a new demand, and
				// adoption rebinds the order to it. News about the demand we were
				// asked about is no longer news about this order.
				return new WP_Error( 'edge_binding_moved', __( 'This order has moved on to another payment.', 'edge-gateway' ) );
			}

			$api = WC_Edge_Client_Factory::client( $gateway->get_secret_key() );

			// The webhook payload carries a state too, but the signature covers the
			// delivery rather than the body, so this read is the authoritative one.
			$demand     = $api->get( 'payment_demands/' . rawurlencode( $demand_id ) );
			$attributes = isset( $demand->data->attributes ) ? $demand->data->attributes : null;

			$state = isset( $attributes->processor_state ) ? (string) $attributes->processor_state : '';

			self::record_risk_signals( $fresh, $demand );

			$outcome = WC_Edge_Payment_Outcome::decide( $state, $fresh->get_status(), $fresh->is_paid() );

			self::apply( $fresh, $outcome, $state, $demand_id );

			return array(
				'state'      => $state,
				'outcome'    => $outcome,
				'attributes' => $attributes,
			);
		} finally {
			WC_Edge_Demand_Lock::release( $demand_id, $owner );
		}
	}

	/**
	 * Read an order back from the database, past every cache in front of it.
	 *
	 * `wc_get_order()` on its own is not a fresh read. Under HPOS the container's
	 * OrderCache hands back the very object this request built earlier - the
	 * `order_objects` group is non-persistent, so it is a per-request store of
	 * exactly the copy we are trying to get away from - and under post storage the
	 * post and post-meta caches do the same. Either way a webhook that completed
	 * the order milliseconds ago is invisible: the outcome rules see `on-hold`,
	 * and payment_complete() runs a second time.
	 *
	 * So evict first, then read. Every eviction is optional and guarded: this runs
	 * on WooCommerce versions that have none of these caches, and a failure to
	 * clear one must not take the sync down with it.
	 *
	 * @param int $order_id Order to read.
	 * @return WC_Order|WC_Order_Refund|false Whatever wc_get_order() makes of it.
	 */
	public static function reload_order( $order_id ) {
		$order_id = (int) $order_id;

		if ( $order_id <= 0 ) {
			return false;
		}

		// The HPOS order-object cache. Keyed by id in the `order_objects` (or
		// `orders`) group, and the thing that actually serves the stale copy.
		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			try {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
			} catch ( \Throwable $e ) {
				// A WooCommerce whose container does not know the class. There is
				// no such cache to evict there, so nothing is stale because of it.
				unset( $e );
			}
		}

		// The HPOS data store keeps a second one of its own - the `orders_data`
		// group and the raw meta behind it - populated only when datastore caching
		// is switched on. clear_cached_data() is how WooCommerce itself drops it;
		// WC_Data_Store::__call() ignores the call on a store that has no such
		// method, which is what makes this safe on post storage.
		if ( class_exists( 'WC_Data_Store' ) ) {
			try {
				WC_Data_Store::load( 'order' )->clear_cached_data( array( $order_id ) );
			} catch ( \Throwable $e ) {
				// load() throws when no order store is registered, which only
				// happens if WooCommerce is not running - in which case there is
				// no cache either.
				unset( $e );
			}
		}

		// WC_Data caches an order's raw meta rows under its own group on both
		// storages, and read_meta_data() prefers that cache over the database.
		if ( method_exists( 'WC_Order', 'generate_meta_cache_key' ) ) {
			wp_cache_delete( WC_Order::generate_meta_cache_key( $order_id, 'orders' ), 'orders' );
		}

		// Post storage: the order is a post, and its meta is in the post-meta
		// cache. Harmless under HPOS, where no such entries exist.
		clean_post_cache( $order_id );
		wp_cache_delete( $order_id, 'post_meta' );

		return wc_get_order( $order_id );
	}

	/**
	 * Write an outcome to the order.
	 *
	 * Every branch has to be safe to repeat and safe to arrive late, because
	 * deliveries do both. The decision about which branch applies was made by
	 * WC_Edge_Payment_Outcome::decide(); this only carries it out.
	 *
	 * @param WC_Order $order     Order, freshly read.
	 * @param string   $outcome   One of the WC_Edge_Payment_Outcome constants.
	 * @param string   $state     Edge processor state, for the notes that quote it.
	 * @param string   $demand_id Demand id, which becomes the transaction id.
	 * @return void
	 */
	private static function apply( WC_Order $order, $outcome, $state, $demand_id ) {
		switch ( $outcome ) {
			case WC_Edge_Payment_Outcome::COMPLETE:
				$order->payment_complete( $demand_id );
				$order->add_order_note( __( 'Edge confirmed this payment succeeded.', 'edge-gateway' ) );

				return;

			case WC_Edge_Payment_Outcome::IGNORED_STALE_FAILURE:
				$order->add_order_note(
					__( 'Edge reported a failure for a payment already marked paid. Not changing the order.', 'edge-gateway' )
				);

				return;

			case WC_Edge_Payment_Outcome::FAIL:
				$order->update_status( 'failed', __( 'Edge declined this payment.', 'edge-gateway' ) );

				return;

			case WC_Edge_Payment_Outcome::RECONCILE:
				$order->update_meta_data( '_edge_processor_state', $state );
				$order->add_order_note(
					sprintf(
						/* translators: %s: Edge processor state. */
						__( 'Edge reported this payment as %s. Reconcile it in the Edge dashboard.', 'edge-gateway' ),
						$state
					)
				);
				$order->save();

				return;

			case WC_Edge_Payment_Outcome::UNRECOGNISED:
				$order->add_order_note(
					sprintf(
						/* translators: %s: unrecognised state. */
						__( 'Edge reported an unrecognised payment state: %s.', 'edge-gateway' ),
						$state
					)
				);

				return;

			default:
				// ALREADY_PAID, ALREADY_FAILED and NO_CHANGE. The order already
				// records this, or the state says nothing about it. Saying so on
				// the order would only add noise, and a poll says it every second.
				return;
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
}
