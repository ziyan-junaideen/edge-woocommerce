<?php
/**
 * Decides what an Edge payment state means for an order and for the shopper.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.4.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WC_EDGE_TESTING' ) ) {
	exit;
}

/**
 * The judgement calls a payment outcome makes, with no WordPress in them.
 *
 * Edge settles a card payment asynchronously: `confirm` leaves the demand
 * `pending`, and `succeeded` or `failed` arrives seconds later. Two places learn
 * that outcome - the webhook, which is authoritative, and the checkout poll,
 * which the shopper is waiting on - and both have to reach the same conclusion
 * about the order. That decision lives here, pure, so it can be tested without a
 * WordPress install; the callers are the thin part that writes notes and moves
 * the order.
 */
final class WC_Edge_Payment_Outcome {

	/**
	 * Take the money: the payment succeeded and the order is not paid yet.
	 *
	 * @var string
	 */
	const COMPLETE = 'complete';

	/**
	 * The payment succeeded and the order already records it. Nothing to do.
	 *
	 * @var string
	 */
	const ALREADY_PAID = 'already-paid';

	/**
	 * The payment failed and the order should be moved to failed.
	 *
	 * @var string
	 */
	const FAIL = 'fail';

	/**
	 * The payment failed and the order already records it. Nothing to do.
	 *
	 * @var string
	 */
	const ALREADY_FAILED = 'already-failed';

	/**
	 * A failure arrived for an order that is already paid. The caller records it
	 * as a note and leaves the order alone - an order is never un-paid.
	 *
	 * @var string
	 */
	const IGNORED_STALE_FAILURE = 'ignored-stale-failure';

	/**
	 * The money moved after the fact (reversed, refunded, disputed). WooCommerce
	 * cannot derive the right end state from that, so the merchant is told to
	 * reconcile it in the Edge dashboard.
	 *
	 * @var string
	 */
	const RECONCILE = 'reconcile';

	/**
	 * A legitimate state that says nothing new about the order.
	 *
	 * @var string
	 */
	const NO_CHANGE = 'no-change';

	/**
	 * A state this plugin does not know. The caller records it rather than
	 * guessing at what it meant.
	 *
	 * @var string
	 */
	const UNRECOGNISED = 'unrecognised';

	/**
	 * Checkout answer: done, whatever happens next is the merchant's business.
	 *
	 * @var string
	 */
	const CHECKOUT_SUCCEEDED = 'succeeded';

	/**
	 * Checkout answer: declined. The shopper can try another card on this order.
	 *
	 * @var string
	 */
	const CHECKOUT_FAILED = 'failed';

	/**
	 * Checkout answer: not settled yet. Keep waiting.
	 *
	 * @var string
	 */
	const CHECKOUT_PROCESSING = 'processing';

	/**
	 * Processor states that mean the payment was taken and then moved again.
	 *
	 * @var string[]
	 */
	const RECONCILE_STATES = array( 'reversed', 'refunded', 'disputed' );

	/**
	 * Processor states that are part of the normal run-up to an outcome, or a
	 * cancellation that never took money. None of them changes an order.
	 *
	 * @var string[]
	 */
	const QUIET_STATES = array( 'pending', 'processing', 'incomplete', 'ready', 'confirmed', 'canceled' );

	/**
	 * The order status a `failed` state may be applied from.
	 *
	 * @var string
	 */
	const CONFIRMED_STATUS = 'on-hold';

	/**
	 * The `cvc2_check` values that mean the security code was not accepted.
	 *
	 * The full enum is `match`, `mismatch`, `unprocessed`, `missing`,
	 * `unavailable`, `unresponsive` (verified in ept
	 * `lib/core/transactions/payment.ex`, `avs_module_attributes/0`). Only
	 * `mismatch` and `missing` are answers about the code the shopper typed.
	 * `match` is a pass, and `unavailable` and `unresponsive` mean the issuer
	 * never answered.
	 *
	 * `unprocessed` is excluded deliberately: it is the column default and it
	 * means the check did not run, not that the code was wrong, so pointing the
	 * shopper at their security code on that value would be a guess. The
	 * sandbox's incorrect-CVC test card happens to write it
	 * (`bad_cvv_avs_verification/1` in
	 * `lib/core/job/edge_authorize_payment_job.ex`), so that card shows the
	 * generic copy. That is accepted - the generic message is not wrong.
	 *
	 * @var string[]
	 */
	const NEGATIVE_CVC2_CHECKS = array( 'mismatch', 'missing' );

	/**
	 * Address verification values that mean the billing address did not match.
	 *
	 * The full enum is `match`, `mismatch`, `retry`, `unavailable`, `unverified`
	 * (verified in ept `lib/core/transactions/payment.ex`,
	 * `avs_module_attributes/0`). Only `mismatch` is an answer; `retry`,
	 * `unavailable` and `unverified` are all "no result", and `unverified` is the
	 * column default.
	 *
	 * @var string[]
	 */
	const NEGATIVE_ADDRESS_CHECKS = array( 'mismatch' );

	/**
	 * What a processor state means for an order in a given status.
	 *
	 * The asymmetry between success and failure is deliberate.
	 *
	 * A `succeeded` state is applied from any unpaid status: money that has been
	 * taken is never stale, and however the order got where it is, the payment
	 * has to be recorded.
	 *
	 * A `failed` state is applied only from `on-hold`, which is where
	 * `process_payment()` leaves an order it has just confirmed. A declined
	 * shopper can confirm the same demand again, and that retry moves the order
	 * through `pending` on its way back to `on-hold`; the late `failed` delivery
	 * for the previous attempt must not land on top of it. Any other status means
	 * either a retry is in flight or somebody moved the order by hand, and in
	 * both cases the order is not ours to change.
	 *
	 * @param string $processor_state Edge processor state, as delivered.
	 * @param string $order_status    WooCommerce order status, with or without the `wc-` prefix.
	 * @param bool   $is_paid         Whether WooCommerce already treats the order as paid.
	 * @return string One of the outcome constants.
	 */
	public static function decide( $processor_state, $order_status, $is_paid ) {
		$state   = is_string( $processor_state ) ? $processor_state : '';
		$status  = self::normalise_status( $order_status );
		$is_paid = (bool) $is_paid;

		if ( 'succeeded' === $state ) {
			return $is_paid ? self::ALREADY_PAID : self::COMPLETE;
		}

		if ( 'failed' === $state ) {
			if ( $is_paid ) {
				return self::IGNORED_STALE_FAILURE;
			}

			if ( 'failed' === $status ) {
				return self::ALREADY_FAILED;
			}

			return self::CONFIRMED_STATUS === $status ? self::FAIL : self::NO_CHANGE;
		}

		if ( in_array( $state, self::RECONCILE_STATES, true ) ) {
			return self::RECONCILE;
		}

		if ( in_array( $state, self::QUIET_STATES, true ) ) {
			return self::NO_CHANGE;
		}

		return self::UNRECOGNISED;
	}

	/**
	 * What a processor state means to the shopper waiting at the checkout.
	 *
	 * Only three answers matter to a browser that is polling: keep waiting, try
	 * another card, or leave the page. `reversed`, `refunded` and `disputed` all
	 * report as succeeded because the payment did go through - whatever happened
	 * afterwards is between the merchant and Edge, and there is nothing the
	 * shopper can do about it at the checkout.
	 *
	 * @param string $processor_state Edge processor state, as delivered.
	 * @return string One of the CHECKOUT_* constants.
	 */
	public static function checkout_status( $processor_state ) {
		$state = is_string( $processor_state ) ? $processor_state : '';

		if ( 'succeeded' === $state || in_array( $state, self::RECONCILE_STATES, true ) ) {
			return self::CHECKOUT_SUCCEEDED;
		}

		if ( 'failed' === $state ) {
			return self::CHECKOUT_FAILED;
		}

		return self::CHECKOUT_PROCESSING;
	}

	/**
	 * What to tell the shopper about a decline.
	 *
	 * Edge exposes no decline reason - issuers do not give one in a form anybody
	 * may repeat - so the only material here is the three verification results
	 * that come back alongside the failure. They are proxies, not causes: a
	 * mismatch usually accompanies a decline rather than explaining it. The
	 * messages are therefore phrased as something to check, never as the reason,
	 * and no API text is ever passed through to the shopper.
	 *
	 * The security code is checked first: when both it and the address failed,
	 * the code is the cheaper thing to get right on a second attempt.
	 *
	 * @param object|array|null $attributes The demand's `data.attributes`.
	 * @return string A translated message.
	 */
	public static function shopper_message( $attributes ) {
		$cvc2 = self::attribute( $attributes, 'cvc2_check' );

		if ( in_array( $cvc2, self::NEGATIVE_CVC2_CHECKS, true ) ) {
			return __( "Your bank declined this payment. Please check the card's security code, or try another card.", 'edge-gateway' );
		}

		$line1  = self::attribute( $attributes, 'address_line1_verification' );
		$postal = self::attribute( $attributes, 'postal_code_verification' );

		if ( in_array( $line1, self::NEGATIVE_ADDRESS_CHECKS, true )
			|| in_array( $postal, self::NEGATIVE_ADDRESS_CHECKS, true ) ) {
			return __( 'Your bank declined this payment. Please check that the billing address matches your card statement, or try another card.', 'edge-gateway' );
		}

		return __( 'Your bank declined this payment. Please check the card details or try another card.', 'edge-gateway' );
	}

	/**
	 * One verification attribute, as a comparable lowercase string.
	 *
	 * @param object|array|null $attributes The demand's `data.attributes`.
	 * @param string            $name       Attribute name.
	 * @return string The value, or an empty string when it is absent or not a scalar.
	 */
	private static function attribute( $attributes, $name ) {
		$value = null;

		if ( is_object( $attributes ) && isset( $attributes->$name ) ) {
			$value = $attributes->$name;
		} elseif ( is_array( $attributes ) && isset( $attributes[ $name ] ) ) {
			$value = $attributes[ $name ];
		}

		if ( ! is_string( $value ) ) {
			return '';
		}

		return strtolower( trim( $value ) );
	}

	/**
	 * An order status without WooCommerce's storage prefix.
	 *
	 * `WC_Order::get_status()` drops the `wc-` prefix, but statuses read from
	 * anywhere else keep it, and a prefix that slipped through would silently
	 * turn every comparison here into "some status we do not recognise".
	 *
	 * @param string $order_status Order status.
	 * @return string
	 */
	private static function normalise_status( $order_status ) {
		$status = is_string( $order_status ) ? strtolower( trim( $order_status ) ) : '';

		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}
}
