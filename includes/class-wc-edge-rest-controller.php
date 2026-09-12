<?php
/**
 * REST endpoint the block checkout calls before mounting the hosted form.
 *
 * @package Deens_Edge_Payments_For_WooCommerce
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves `POST /wp-json/edge/v1/checkout-intent` and `.../checkout-status`.
 *
 * The hosted iframe needs a payment demand to mount against, and it needs one
 * before WooCommerce has created an order. This is where that demand comes from.
 *
 * Edge then settles asynchronously, so the shopper stays on the checkout after
 * Place Order and asks the status route how it went.
 */
final class WC_Edge_REST_Controller {

	const NAMESPACE_V1 = 'edge/v1';
	const ROUTE        = '/checkout-intent';
	const STATUS_ROUTE = '/checkout-status';

	/**
	 * Register the routes.
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
				'permission_callback' => array( __CLASS__, 'permitted' ),
				'args'                => array(
					'cart_hash' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			self::STATUS_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_status' ),
				'permission_callback' => array( __CLASS__, 'status_permitted' ),
				'args'                => array(
					'order_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Decide whether the caller may prepare a payment.
	 *
	 * This is a state-changing call that spends real resources at Edge, so it is
	 * gated on the shopper actually being mid-checkout, and on there being a cart
	 * to pay for.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permitted( WP_REST_Request $request ) {
		$permitted = self::session_permitted( $request );

		if ( is_wp_error( $permitted ) ) {
			return $permitted;
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return new WP_Error(
				'edge_cart_empty',
				__( 'Your cart is empty.', 'deens-edge-payments-for-woocommerce' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * Decide whether the caller may ask about an order's payment.
	 *
	 * The cart is empty by the time this is polled - placing the order emptied it
	 * - so the session check is all that carries over, and on its own it would
	 * let any shopper ask about any order id. The adopted attempt is what ties
	 * this session to this order, and it is the only such link that does not
	 * require handing the browser an order key or an email address.
	 *
	 * The refusal is deliberately the same as a missing session: an order id that
	 * is not yours and an order id that does not exist must not be told apart.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function status_permitted( WP_REST_Request $request ) {
		$permitted = self::session_permitted( $request );

		if ( is_wp_error( $permitted ) ) {
			return $permitted;
		}

		$order_id = absint( $request->get_param( 'order_id' ) );
		$attempt  = $order_id
			? WC_Edge_Attempt_Store::find_adopted( $order_id, (string) WC()->session->get_customer_id() )
			: null;

		if ( ! $attempt ) {
			return new WP_Error(
				'edge_not_your_order',
				__( 'Your checkout session could not be found. Please reload the page.', 'deens-edge-payments-for-woocommerce' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * The checks both routes share: a real shopper, mid-checkout.
	 *
	 * A valid REST nonce and an established WooCommerce session. The nonce alone
	 * is not enough, because it says nothing about which session is asking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private static function session_permitted( WP_REST_Request $request ) {
		self::ensure_cart_loaded();

		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			// The code is WordPress's own on purpose. apiFetch's nonce middleware
			// refreshes the nonce and replays the request only on this exact code,
			// and a shopper who has just created an account is holding a nonce
			// minted for the guest they no longer are.
			return new WP_Error(
				'rest_cookie_invalid_nonce',
				__( 'Your checkout session has expired. Please reload the page.', 'deens-edge-payments-for-woocommerce' ),
				array( 'status' => 403 )
			);
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->session->get_customer_id() ) {
			return new WP_Error(
				'edge_no_session',
				__( 'Your checkout session could not be found. Please reload the page.', 'deens-edge-payments-for-woocommerce' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Prepare a demand and return what the browser needs to mount the form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( WP_REST_Request $request ) {
		nocache_headers();

		$gateway = self::gateway();

		if ( ! $gateway instanceof WC_Gateway_Edge || ! $gateway->is_available() ) {
			return new WP_Error(
				'edge_unavailable',
				__( 'Card payments are not available for this order.', 'deens-edge-payments-for-woocommerce' ),
				array( 'status' => 409 )
			);
		}

		// A payment this session started is still settling. Preparing another
		// demand now would hand the shopper a second order for the same goods, so
		// point the browser at the one already in flight and let it wait.
		$in_flight = WC_Edge_Payment_Service::in_flight_order( $gateway, (string) WC()->session->get_customer_id() );

		if ( $in_flight instanceof WC_Order ) {
			return new WP_Error(
				'edge_payment_in_flight',
				__( 'Your previous payment is still being processed. Please wait a moment.', 'deens-edge-payments-for-woocommerce' ),
				array(
					'status'  => 409,
					'orderId' => $in_flight->get_id(),
				)
			);
		}

		// If the browser tells us which cart it is showing, refuse to prepare for
		// a different one. The cart is still read server-side; this only catches
		// a stale page, it is not a source of truth.
		$claimed_hash = (string) $request->get_param( 'cart_hash' );

		if ( '' !== $claimed_hash && ! hash_equals( (string) WC()->cart->get_cart_hash(), $claimed_hash ) ) {
			return new WP_Error(
				'edge_cart_changed',
				__( 'Your cart has changed. Please reload the page.', 'deens-edge-payments-for-woocommerce' ),
				array( 'status' => 409 )
			);
		}

		$prepared = WC_Edge_Payment_Service::prepare( $gateway );

		if ( is_wp_error( $prepared ) ) {
			$prepared->add_data( array( 'status' => 400 ) );

			return $prepared;
		}

		return rest_ensure_response(
			array(
				'demandId'       => $prepared['demand_id'],
				'attemptKey'     => $prepared['attempt_key'],
				'publishableKey' => $gateway->get_publishable_key(),
				'iframeHost'     => WC_Edge_Client_Factory::dashboard_host(),
				'mode'           => $gateway->get_mode(),
			)
		);
	}

	/**
	 * Tell the waiting checkout what Edge has done with the payment.
	 *
	 * Polled by the block checkout between Place Order and the thank-you page.
	 * Every answer is one of three words - keep waiting, go here, try another
	 * card - and nothing an API response said ever reaches the shopper verbatim.
	 *
	 * Anything that goes wrong reads as "still processing": the webhook is the
	 * authoritative route to a final state, and a poll that cannot reach Edge has
	 * learnt nothing about the payment. Failing the checkout on it would strand a
	 * shopper whose money has already moved.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_status( WP_REST_Request $request ) {
		nocache_headers();

		$gateway = self::gateway();

		// Deliberately not is_available(): that asks whether a new payment could
		// be started - cart total, currency, checkout surface - none of which has
		// any bearing on an order that has already been placed.
		if ( ! $gateway instanceof WC_Gateway_Edge ) {
			return new WP_Error(
				'edge_unavailable',
				__( 'Card payments are not available for this order.', 'deens-edge-payments-for-woocommerce' ),
				array( 'status' => 409 )
			);
		}

		$order = wc_get_order( absint( $request->get_param( 'order_id' ) ) );

		if ( ! $order instanceof WC_Order
			|| 'edge' !== $order->get_payment_method()
			|| '' === (string) $order->get_meta( '_edge_demand_id' ) ) {
			return new WP_Error(
				'edge_not_pending',
				__( 'This order is not waiting on an Edge payment.', 'deens-edge-payments-for-woocommerce' ),
				array( 'status' => 409 )
			);
		}

		if ( $order->is_paid() ) {
			// The webhook got here first, which is the common case on a fast
			// settlement. Nothing left to ask Edge.
			return rest_ensure_response(
				array(
					'status'      => WC_Edge_Payment_Outcome::CHECKOUT_SUCCEEDED,
					'redirectUrl' => $gateway->get_return_url( $order ),
				)
			);
		}

		try {
			$result = WC_Edge_Order_Sync::sync( $order, $gateway );
		} catch ( \Throwable $e ) {
			WC_Edge_Logger::error(
				'Could not read the payment state for order ' . $order->get_id() . ': ' . $e->getMessage()
			);

			return self::still_processing();
		}

		if ( is_wp_error( $result ) ) {
			// Usually the webhook holding the lock for this same demand, which is
			// exactly the case the next poll resolves. Logged at info because it is
			// routine, and by code - the message is shopper copy, not a diagnosis.
			WC_Edge_Logger::info(
				'Order ' . $order->get_id() . ' could not be synced: ' . $result->get_error_code()
			);

			return self::still_processing();
		}

		$status   = WC_Edge_Payment_Outcome::checkout_status( $result['state'] );
		$response = array( 'status' => $status );

		if ( WC_Edge_Payment_Outcome::CHECKOUT_SUCCEEDED === $status ) {
			$response['redirectUrl'] = $gateway->get_return_url( $order );
		} elseif ( WC_Edge_Payment_Outcome::CHECKOUT_FAILED === $status ) {
			// The order is `failed` by now, which is what lets the block checkout
			// reuse it for the next attempt rather than orphaning it.
			$response['message'] = WC_Edge_Payment_Outcome::shopper_message( $result['attributes'] );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * The answer that asks the browser to come back.
	 *
	 * @return WP_REST_Response
	 */
	private static function still_processing() {
		return rest_ensure_response( array( 'status' => WC_Edge_Payment_Outcome::CHECKOUT_PROCESSING ) );
	}

	/**
	 * Make sure the shopper's cart and session exist for this request.
	 *
	 * WooCommerce loads the session and cart automatically for the front end and
	 * for its own Store API namespace, but not for a custom REST route: there,
	 * `WC()->session` and `WC()->cart` are null and the shopper looks like they
	 * have no checkout in progress. `wc_load_cart()` is WooCommerce's own entry
	 * point for exactly this case, and it adopts the existing session from the
	 * request cookies rather than starting a new one.
	 *
	 * @return void
	 */
	private static function ensure_cart_loaded() {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_load_cart' ) ) {
			return;
		}

		if ( WC()->cart instanceof WC_Cart && WC()->session ) {
			return;
		}

		wc_load_cart();
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
}
