<?php
/**
 * Refunds an Edge payment on behalf of the gateway.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a WooCommerce refund into an Edge refund demand.
 *
 * Refunds have no confirm step - creating a refund demand starts it - so unlike
 * a payment there is no second call to lean on. What there is instead is a real
 * idempotency contract: Edge replays a key whose payment, amount, reason and
 * note all match, and rejects one whose do not. Everything here is arranged so
 * that an unclear outcome is resolved with the *same* key rather than retried
 * with a new one.
 *
 * Note that returning true from here does not mean the money moved. Edge answers
 * with `state: pending` and the real outcome arrives later on the webhook. By
 * then WooCommerce has already restocked, revoked downloads and emailed the
 * customer, so a later failure is reported loudly rather than corrected
 * silently - see WC_Edge_Webhook_Controller.
 */
final class WC_Edge_Refund_Service {

	/**
	 * Order meta holding refunds that have been sent but not yet accounted for.
	 *
	 * Written before the request goes out, so a refund that Edge accepted can
	 * still be found after a response we never saw. Keyed by idempotency key
	 * rather than held in a single slot: two refunds can be in flight at once,
	 * and one must not erase the other's only recovery record.
	 *
	 * @var string
	 */
	const PENDING_META = '_edge_refund_pending';

	/**
	 * Meta naming the Edge refund a WooCommerce refund row became.
	 *
	 * @var string
	 */
	const DEMAND_META = '_edge_refund_demand_id';

	/**
	 * Meta holding the idempotency key a refund row was sent with.
	 *
	 * @var string
	 */
	const KEY_META = '_edge_refund_idempotency_key';

	/**
	 * Meta marking a refund Edge could not process.
	 *
	 * Set on both the refund row and its order: the row is where it happened,
	 * the order is where anyone looking for it will be.
	 *
	 * @var string
	 */
	const FAILED_META = '_edge_refund_failed';

	/**
	 * Refund a payment.
	 *
	 * @param WC_Gateway_Edge $gateway Configured gateway.
	 * @param WC_Order        $order   Order to refund against.
	 * @param mixed           $amount  Refund amount, as WooCommerce supplies it.
	 * @param string          $reason  Merchant's refund reason, free text.
	 * @return true|WP_Error
	 */
	public static function refund( WC_Gateway_Edge $gateway, WC_Order $order, $amount, $reason ) {
		$demand_id = (string) $order->get_meta( '_edge_demand_id' );

		// Guards first, network second: none of these need a request to decide,
		// and one of them is "these credentials are for the wrong environment".
		$ready = self::assert_refundable( $gateway, $order, $demand_id );

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$cents = self::to_cents( $amount );

		if ( is_wp_error( $cents ) ) {
			return $cents;
		}

		if ( $cents <= 0 ) {
			// A restock-only refund. There is nothing to reverse at the gateway,
			// and sending it would be a 422: Edge requires a positive amount.
			$order->add_order_note(
				__( 'No Edge refund was sent: this refund is for zero.', 'edge-gateway' )
			);

			return true;
		}

		$refund = WC_Edge_Payments::claimed_refund( $order->get_id() );

		if ( ! $refund instanceof WC_Order_Refund || ! $refund->get_id() ) {
			// Without WooCommerce's own refund row there is no stable identity to
			// derive an idempotency key from, and a key attached to the wrong
			// refund is worse than no refund at all.
			return new WP_Error(
				'edge_refund_no_row',
				__( 'Edge refunds must be created through the WooCommerce refund form.', 'edge-gateway' )
			);
		}

		try {
			$api = WC_Edge_Client_Factory::client( $gateway->get_secret_key() );
		} catch ( \Throwable $e ) {
			WC_Edge_Logger::error( 'Refund could not build a client: ' . $e->getMessage() );

			return new WP_Error( 'edge_not_configured', self::generic_failure() );
		}

		$note = self::note_from( $reason );
		$key  = WC_Edge_Fingerprint::for_refund(
			array(
				'demand_id'    => $demand_id,
				'mode'         => (string) $order->get_meta( '_edge_mode' ),
				'order_id'     => $order->get_id(),
				'refund_id'    => $refund->get_id(),
				'amount_cents' => $cents,
			)
		);

		// An earlier attempt may have left a refund behind that we never managed
		// to write down. Settle that before creating anything.
		$recovered = self::recover_pending( $api, $order, $refund, $demand_id, $cents );

		if ( is_wp_error( $recovered ) ) {
			return $recovered;
		}

		if ( true === $recovered ) {
			return true;
		}

		$duplicate = self::pending_duplicate( $order, $demand_id, $cents, $key );

		if ( is_wp_error( $duplicate ) ) {
			return $duplicate;
		}

		self::remember_pending( $order, $key, $refund->get_id(), $demand_id, $cents );

		return self::send( $api, $order, $refund, $demand_id, $cents, $note, $key );
	}

	/**
	 * Refuse anything that cannot be refunded before a request is built.
	 *
	 * @param WC_Gateway_Edge $gateway   Gateway.
	 * @param WC_Order        $order     Order.
	 * @param string          $demand_id Payment demand bound to the order.
	 * @return true|WP_Error
	 */
	private static function assert_refundable( WC_Gateway_Edge $gateway, WC_Order $order, $demand_id ) {
		if ( '' === $demand_id ) {
			return new WP_Error(
				'edge_no_binding',
				__( 'This order has no Edge payment recorded against it, so it cannot be refunded here.', 'edge-gateway' )
			);
		}

		$order_mode = (string) $order->get_meta( '_edge_mode' );
		$mode       = (string) $gateway->get_mode();

		if ( '' === $mode || ! $gateway->has_valid_keys() ) {
			return new WP_Error( 'edge_not_configured', self::generic_failure() );
		}

		if ( $order_mode !== $mode ) {
			return new WP_Error(
				'edge_mode_mismatch',
				sprintf(
					/* translators: 1: the mode the order was paid in, 2: the mode the gateway is configured for. */
					__( 'This order was paid in %1$s mode but the gateway is configured for %2$s. Restore the matching API key before refunding it.', 'edge-gateway' ),
					$order_mode,
					$mode
				)
			);
		}

		// Paid at some point, rather than paid now. A fully refunded order sits
		// in `refunded`, which is not a paid status, and that is exactly the
		// order a failed full refund has to be retried against.
		if ( ! $order->get_date_paid() && ! $order->is_paid() ) {
			return new WP_Error(
				'edge_not_captured',
				__( 'Edge has not confirmed this payment succeeded yet, so there is nothing to refund.', 'edge-gateway' )
			);
		}

		if ( ! WC_Edge_Money::is_supported_currency( $order->get_currency() ) ) {
			return new WP_Error(
				'edge_currency_unsupported',
				__( 'Edge can only refund orders in US dollars.', 'edge-gateway' )
			);
		}

		return true;
	}

	/**
	 * Convert WooCommerce's refund amount to integer cents.
	 *
	 * WC_Order_Refund::get_amount() returns a string, so the normal path never
	 * touches a float. A float only arrives when something other than the refund
	 * form called the gateway, and wc_format_decimal() is the least-bad way back
	 * to a string from there - it is not used on the ordinary path, because it
	 * rounds through floatval() and that is the precise thing WC_Edge_Money
	 * refuses to do.
	 *
	 * @param mixed $amount Refund amount.
	 * @return int|WP_Error
	 */
	private static function to_cents( $amount ) {
		if ( null === $amount || '' === $amount ) {
			return new WP_Error(
				'edge_refund_amount_missing',
				__( 'A refund amount is required.', 'edge-gateway' )
			);
		}

		if ( is_float( $amount ) ) {
			$amount = wc_format_decimal( $amount, 2 );
		}

		try {
			return WC_Edge_Money::to_cents( (string) $amount );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'edge_refund_amount_invalid',
				__( 'That refund amount could not be read as an amount of money.', 'edge-gateway' )
			);
		}
	}

	/**
	 * Adopt a refund an earlier attempt created but never recorded.
	 *
	 * This must never fall through into a new request. WooCommerce deletes its
	 * refund row whenever the gateway returns an error, so the merchant's retry
	 * arrives with a fresh row id and therefore a fresh idempotency key. Sending
	 * that while an unaccounted refund is already reserving its amount is how a
	 * partial refund gets paid twice - the backend's balance cap does not catch
	 * it while there is balance left.
	 *
	 * @param WC_Edge_API_Client $api       Configured client.
	 * @param WC_Order           $order     Order.
	 * @param WC_Order_Refund    $refund    Refund row being created now.
	 * @param string             $demand_id Payment demand id.
	 * @param int                $cents     Amount being refunded now.
	 * @return true|false|WP_Error True when adopted, false when there was nothing to adopt.
	 */
	private static function recover_pending( WC_Edge_API_Client $api, WC_Order $order, WC_Order_Refund $refund, $demand_id, $cents ) {
		$pending = self::pending( $order );

		if ( empty( $pending ) ) {
			return false;
		}

		try {
			$listing = $api->get(
				'refund_demands',
				array( 'filter' => array( 'payment_demand' => $demand_id ) )
			);
		} catch ( \Throwable $e ) {
			WC_Edge_Logger::error( 'Could not read refunds back for ' . $demand_id . ': ' . $e->getMessage() );

			return new WP_Error(
				'edge_refund_unresolved',
				__( 'An earlier Edge refund for this order has an unknown outcome, and Edge could not be reached to check. Refunding again could refund twice, so it has been stopped. Try again shortly.', 'edge-gateway' )
			);
		}

		foreach ( $pending as $key => $entry ) {
			$found = WC_Edge_Refund_Outcome::find_by_key( $listing, $key );

			if ( ! $found ) {
				// Never sent, or rejected before it existed. Nothing is reserved
				// against the payment, so this entry is safe to drop.
				self::forget_pending( $order, $key );

				continue;
			}

			$state = WC_Edge_Refund_Outcome::state_of( $found );

			if ( ! WC_Edge_Refund_Outcome::is_live( $state ) ) {
				// Failed, so its amount was released. Not ours to adopt.
				self::forget_pending( $order, $key );

				continue;
			}

			$sent = isset( $entry['amount_cents'] ) ? (int) $entry['amount_cents'] : 0;

			if ( $sent !== (int) $cents ) {
				// A live refund for a different amount is not this one. Adopting
				// it would file a $10 refund against a $15 row; sending anyway
				// would refund on top of money already reserved. Neither is ours
				// to choose, so stop and say what is outstanding.
				return new WP_Error(
					'edge_refund_outstanding',
					sprintf(
						/* translators: 1: outstanding refund amount, 2: Edge refund demand id. */
						__( 'An earlier Edge refund of %1$s (%2$s) is not recorded against this order yet. Refund that same amount to adopt it, or resolve it in the Edge dashboard first - sending a different refund now could refund twice.', 'edge-gateway' ),
						wc_price( WC_Edge_Money::from_cents( $sent ), array( 'currency' => $order->get_currency() ) ),
						$found->id
					)
				);
			}

			self::record( $refund, (string) $found->id, (string) $key );
			self::forget_pending( $order, $key );

			$order->add_order_note(
				sprintf(
					/* translators: 1: refund amount, 2: Edge refund demand id. */
					__( 'Adopted an Edge refund of %1$s that an earlier attempt had already created (%2$s). No second refund was sent.', 'edge-gateway' ),
					wc_price( WC_Edge_Money::from_cents( $sent ), array( 'currency' => $order->get_currency() ) ),
					$found->id
				)
			);

			WC_Edge_Logger::info( 'Adopted orphaned refund ' . $found->id . ' for order ' . $order->get_id() );

			return true;
		}

		return false;
	}

	/**
	 * Refuse a refund that repeats one already in flight.
	 *
	 * Best effort, not a lock: two requests racing each other will both pass.
	 * It catches the common shape, which is a merchant submitting the same
	 * refund twice in sequence.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $demand_id Payment demand id.
	 * @param int      $cents     Amount being refunded.
	 * @param string   $key       Key this refund will use.
	 * @return true|WP_Error
	 */
	private static function pending_duplicate( WC_Order $order, $demand_id, $cents, $key ) {
		foreach ( self::pending( $order ) as $existing => $entry ) {
			if ( (string) $existing === (string) $key ) {
				continue;
			}

			$same_demand = isset( $entry['demand_id'] ) && (string) $entry['demand_id'] === (string) $demand_id;
			$same_amount = isset( $entry['amount_cents'] ) && (int) $entry['amount_cents'] === (int) $cents;

			if ( $same_demand && $same_amount ) {
				return new WP_Error(
					'edge_refund_in_flight',
					__( 'An identical Edge refund for this order is still in flight. Wait for it to settle before sending another.', 'edge-gateway' )
				);
			}
		}

		return true;
	}

	/**
	 * Create the refund demand, and work out what happened if that is unclear.
	 *
	 * @param WC_Edge_API_Client $api       Configured client.
	 * @param WC_Order           $order     Order.
	 * @param WC_Order_Refund    $refund    WooCommerce refund row.
	 * @param string             $demand_id Payment demand id.
	 * @param int                $cents     Amount in cents.
	 * @param string             $note      Refund note.
	 * @param string             $key       Idempotency key.
	 * @return true|WP_Error
	 */
	private static function send( WC_Edge_API_Client $api, WC_Order $order, WC_Order_Refund $refund, $demand_id, $cents, $note, $key ) {
		$document = WC_Edge_Order_Mapper::refund_document(
			array(
				'demand_id'       => $demand_id,
				'amount_cents'    => $cents,
				'reason_note'     => $note,
				'idempotency_key' => $key,
			)
		);

		$attempt = self::post( $api, $document );

		// Whether we have ever been unsure. Once we have, no later reply can put
		// that back: a refusal on the second request says nothing about what the
		// first one did, and the first one may have created a refund.
		$unsure = WC_Edge_Refund_Outcome::AMBIGUOUS === $attempt['outcome'];

		if ( $unsure ) {
			// Replaying the same key is safe by contract: a matching request
			// returns the refund the first one created and enqueues nothing.
			$attempt = self::post( $api, $document );
		}

		if ( WC_Edge_Refund_Outcome::CREATED === $attempt['outcome'] ) {
			return self::accept( $order, $refund, $attempt['id'], $key, $cents );
		}

		if ( WC_Edge_Refund_Outcome::REJECTED === $attempt['outcome'] && ! $unsure ) {
			// The only request we made was refused, so nothing exists to record.
			self::forget_pending( $order, $key );

			return new WP_Error( 'edge_refund_rejected', $attempt['message'] );
		}

		// Either still unclear, or refused only after an earlier attempt whose
		// outcome we never saw. Both have to be settled by looking the key up -
		// it was written down before the first request, so the listing can name
		// our refund even though no response did.
		$resolution = self::look_up( $api, $demand_id, $key );

		if ( $resolution['found'] ) {
			return self::accept( $order, $refund, (string) $resolution['found']->id, $key, $cents );
		}

		if ( $resolution['read'] ) {
			// The listing is authoritative and our key is not in it, so nothing
			// was created however confusing the replies were.
			self::forget_pending( $order, $key );

			return new WP_Error(
				'edge_refund_rejected',
				'' !== $attempt['message'] ? $attempt['message'] : self::generic_failure()
			);
		}

		// The pending record stays. It is the only thing that will stop the next
		// attempt refunding this twice.
		WC_Edge_Logger::error( 'Refund outcome unresolved for order ' . $order->get_id() . ', key ' . $key );

		$order->add_order_note(
			sprintf(
				/* translators: %s: refund amount. */
				__( 'An Edge refund of %s was sent but its outcome is unknown. It has been recorded, and the next refund attempt on this order will check for it before sending anything. Do not refund again in the Edge dashboard until this is resolved.', 'edge-gateway' ),
				wc_price( WC_Edge_Money::from_cents( $cents ), array( 'currency' => $order->get_currency() ) )
			)
		);

		return new WP_Error(
			'edge_refund_unresolved',
			__( 'Edge did not confirm this refund. It has been recorded and will be checked before any further refund is sent. Check the order notes.', 'edge-gateway' )
		);
	}

	/**
	 * POST once and classify the result.
	 *
	 * @param WC_Edge_API_Client $api      Configured client.
	 * @param array              $document Refund document.
	 * @return array outcome, id and message.
	 */
	private static function post( WC_Edge_API_Client $api, array $document ) {
		try {
			$response = $api->create( 'refund_demands', $document );
		} catch ( WC_Edge_API_Exception $e ) {
			WC_Edge_Logger::error(
				sprintf( 'Refund create failed (HTTP %d): %s', $e->get_status_code(), $e->getMessage() )
			);

			return array(
				'outcome' => WC_Edge_Refund_Outcome::classify( $e->get_status_code(), false ),
				'id'      => '',
				'message' => self::describe( $e ),
			);
		} catch ( \Throwable $e ) {
			WC_Edge_Logger::error( 'Refund create errored: ' . $e->getMessage() );

			return array(
				'outcome' => WC_Edge_Refund_Outcome::AMBIGUOUS,
				'id'      => '',
				'message' => self::generic_failure(),
			);
		}

		return array(
			'outcome' => WC_Edge_Refund_Outcome::classify( 200, WC_Edge_Refund_Outcome::has_id( $response ) ),
			'id'      => WC_Edge_Refund_Outcome::id_of( $response ),
			'message' => '',
		);
	}

	/**
	 * Find our refund in the listing for a payment demand.
	 *
	 * "Not found" and "could not look" are different answers and must not
	 * collapse into one: the first means nothing was created, the second means
	 * we still do not know, and only the first is safe to act on.
	 *
	 * @param WC_Edge_API_Client $api       Configured client.
	 * @param string             $demand_id Payment demand id.
	 * @param string             $key       Idempotency key.
	 * @return array `read` - whether the listing was fetched, `found` - the resource or null.
	 */
	private static function look_up( WC_Edge_API_Client $api, $demand_id, $key ) {
		try {
			$listing = $api->get(
				'refund_demands',
				array( 'filter' => array( 'payment_demand' => $demand_id ) )
			);
		} catch ( \Throwable $e ) {
			WC_Edge_Logger::error( 'Refund look-up failed for ' . $demand_id . ': ' . $e->getMessage() );

			return array(
				'read'  => false,
				'found' => null,
			);
		}

		$found = WC_Edge_Refund_Outcome::find_by_key( $listing, $key );

		if ( $found && ! WC_Edge_Refund_Outcome::is_live( WC_Edge_Refund_Outcome::state_of( $found ) ) ) {
			// It exists but failed, so its amount was released. Nothing is held
			// against the payment and nothing is ours to adopt.
			$found = null;
		}

		return array(
			'read'  => true,
			'found' => $found,
		);
	}

	/**
	 * Record an accepted refund and tell the merchant.
	 *
	 * The ids go onto the refund row before the pending record is cleared. The
	 * other order would leave a refund Edge has accepted with nothing at all
	 * pointing at it if the request died in between.
	 *
	 * @param WC_Order        $order     Order.
	 * @param WC_Order_Refund $refund    Refund row.
	 * @param string          $refund_id Edge refund demand id.
	 * @param string          $key       Idempotency key.
	 * @param int             $cents     Amount in cents.
	 * @return true
	 */
	private static function accept( WC_Order $order, WC_Order_Refund $refund, $refund_id, $key, $cents ) {
		self::record( $refund, $refund_id, $key );
		self::forget_pending( $order, $key );

		$order->add_order_note(
			sprintf(
				/* translators: 1: refund amount, 2: Edge refund demand id. */
				__( 'Edge accepted a refund of %1$s (%2$s). It is pending until Edge confirms it.', 'edge-gateway' ),
				wc_price( WC_Edge_Money::from_cents( $cents ), array( 'currency' => $order->get_currency() ) ),
				$refund_id
			)
		);

		WC_Edge_Logger::info( 'Refund ' . $refund_id . ' accepted for order ' . $order->get_id() );

		return true;
	}

	/**
	 * Write the Edge identifiers onto a WooCommerce refund row.
	 *
	 * Through the CRUD API, never update_post_meta(), because HPOS is declared.
	 * transaction_id is deliberately not set: it is a WC_Order property and
	 * WC_Order_Refund does not have it.
	 *
	 * @param WC_Order_Refund $refund    Refund row.
	 * @param string          $refund_id Edge refund demand id.
	 * @param string          $key       Idempotency key.
	 * @return void
	 */
	private static function record( WC_Order_Refund $refund, $refund_id, $key ) {
		$refund->update_meta_data( self::DEMAND_META, (string) $refund_id );
		$refund->update_meta_data( self::KEY_META, (string) $key );
		$refund->save();
	}

	/**
	 * Refunds sent but not yet accounted for, keyed by idempotency key.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function pending( WC_Order $order ) {
		$pending = $order->get_meta( self::PENDING_META );

		return is_array( $pending ) ? $pending : array();
	}

	/**
	 * Note that a refund is about to be sent.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $key       Idempotency key.
	 * @param int      $refund_id WooCommerce refund row id.
	 * @param string   $demand_id Payment demand id.
	 * @param int      $cents     Amount in cents.
	 * @return void
	 */
	private static function remember_pending( WC_Order $order, $key, $refund_id, $demand_id, $cents ) {
		$pending = self::pending( $order );

		$pending[ $key ] = array(
			'refund_id'    => (int) $refund_id,
			'demand_id'    => (string) $demand_id,
			'amount_cents' => (int) $cents,
			'sent_at'      => gmdate( 'c' ),
		);

		$order->update_meta_data( self::PENDING_META, $pending );
		$order->save();
	}

	/**
	 * Drop a pending record once its outcome is known.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Idempotency key.
	 * @return void
	 */
	private static function forget_pending( WC_Order $order, $key ) {
		$pending = self::pending( $order );

		if ( ! isset( $pending[ $key ] ) ) {
			return;
		}

		unset( $pending[ $key ] );

		if ( empty( $pending ) ) {
			$order->delete_meta_data( self::PENDING_META );
		} else {
			$order->update_meta_data( self::PENDING_META, $pending );
		}

		$order->save();
	}

	/**
	 * The note sent to Edge for a WooCommerce refund reason.
	 *
	 * @param mixed $reason Merchant's free text reason.
	 * @return string
	 */
	private static function note_from( $reason ) {
		return is_scalar( $reason ) ? trim( (string) $reason ) : '';
	}

	/**
	 * Turn an Edge rejection into something the merchant can act on.
	 *
	 * Unlike a checkout failure this is read by someone who can do something
	 * about it, so the API's own wording is more useful than a generic line -
	 * "greater than the unrefunded amount" says exactly what went wrong.
	 *
	 * @param WC_Edge_API_Exception $e Exception.
	 * @return string
	 */
	private static function describe( WC_Edge_API_Exception $e ) {
		$details = array();

		foreach ( (array) $e->get_errors() as $error ) {
			$detail = '';

			if ( ! empty( $error['detail'] ) ) {
				$detail = (string) $error['detail'];
			} elseif ( ! empty( $error['title'] ) ) {
				$detail = (string) $error['title'];
			}

			$detail = trim( wp_strip_all_tags( $detail ) );

			if ( '' === $detail ) {
				continue;
			}

			// A changeset failure's title is bare text like "can't be blank",
			// which only means something next to the field the pointer names.
			$field = isset( $error['source']['pointer'] )
				? basename( (string) $error['source']['pointer'] )
				: '';

			if ( '' !== $field && false === strpos( $detail, $field ) ) {
				$detail = $field . ' ' . $detail;
			}

			$details[ $detail ] = true;
		}

		if ( empty( $details ) ) {
			return self::generic_failure();
		}

		return sprintf(
			/* translators: %s: reason Edge gave for refusing the refund. */
			__( 'Edge refused this refund: %s', 'edge-gateway' ),
			implode( '; ', array_keys( $details ) )
		);
	}

	/**
	 * Copy for failures with nothing useful to say.
	 *
	 * @return string
	 */
	private static function generic_failure() {
		return __( 'The refund could not be sent to Edge. Check the Edge payments log and try again.', 'edge-gateway' );
	}
}
