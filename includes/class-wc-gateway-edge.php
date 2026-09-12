<?php
/**
 * WC_Gateway_Edge class
 *
 * @package  Deens_Edge_Payments_For_WooCommerce
 * @since    1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Edge Gateway.
 *
 * @class    WC_Gateway_Edge
 * @version  2.4.0
 */
class WC_Gateway_Edge extends WC_Payment_Gateway {

	/**
	 * How many times process_payment() reaches for the demand lock.
	 *
	 * @var int
	 */
	const LOCK_ATTEMPTS = 3;

	/**
	 * Microseconds between those tries.
	 *
	 * @var int
	 */
	const LOCK_RETRY_DELAY_US = 300000;

	/**
	 * Lease length for the lock process_payment() holds.
	 *
	 * The sync's 30s default is sized for one round trip to Edge, which is all
	 * that method makes. The critical section here is four: the read before the
	 * PATCH, the PATCH, and - when the PATCH comes back unanswered - the read and
	 * the single retry that resolve it. Each is capped at
	 * WC_Edge_API_Client::TIMEOUT (15s), so 4 x 15 is the worst case, plus 30s of
	 * margin for the order writes either side of it. Anything shorter lets the
	 * lease expire mid-confirm, and a poll or a webhook then takes it over and
	 * writes the order underneath a confirm that is still running.
	 *
	 * @var int
	 */
	const CONFIRM_LOCK_TTL = 90;

	/**
	 * Secret (server-only) API key.
	 *
	 * @var string
	 */
	protected $secret_key = '';

	/**
	 * Publishable (browser-safe) API key.
	 *
	 * @var string
	 */
	protected $publishable_key = '';

	/**
	 * Derived from the key prefix: "live" or "sandbox".
	 *
	 * @var string|null
	 */
	protected $mode = null;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {

		$this->id = 'edge';

		// The hosted iframe collects the card, so there are no gateway-rendered
		// fields on any surface.
		$this->has_fields = false;

		$this->icon = apply_filters( 'woocommerce_edge_gateway_icon', '' );

		// Subscriptions are deliberately absent: recurring billing has no
		// counterpart in the flow this gateway implements. Refunds are supported
		// against Edge's `refund_demands` resource - full and partial, and
		// idempotent so a retry cannot pay twice.
		$this->supports = array( 'products', 'refunds' );

		$this->method_title       = _x( 'Edge Payments', 'Edge payments method', 'deens-edge-payments-for-woocommerce' );
		$this->method_description = __( 'Accept card payments through Edge. Requires the block-based checkout.', 'deens-edge-payments-for-woocommerce' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->enabled         = $this->get_option( 'enabled' );
		$this->secret_key      = trim( (string) $this->get_option( 'secret_key' ) );
		$this->publishable_key = trim( (string) $this->get_option( 'publishable_key' ) );
		$this->mode            = WC_Edge_Mode::mode_of( $this->publishable_key );

		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 *
	 * There is one key pair, not two. The mode is part of the key itself, so a
	 * separate "test mode" toggle could only ever disagree with the credentials
	 * sitting next to it.
	 */
	public function init_form_fields() {

		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'deens-edge-payments-for-woocommerce' ),
				'label'   => __( 'Enable Edge Payments', 'deens-edge-payments-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'deens-edge-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'deens-edge-payments-for-woocommerce' ),
				'default'     => __( 'Credit Card', 'deens-edge-payments-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description', 'deens-edge-payments-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'deens-edge-payments-for-woocommerce' ),
				'default'     => __( 'Pay securely with your card.', 'deens-edge-payments-for-woocommerce' ),
			),
			'publishable_key' => array(
				'title'       => __( 'Publishable key', 'deens-edge-payments-for-woocommerce' ),
				'type'        => 'text',
				/* translators: %s: example key prefix. */
				'description' => sprintf( __( 'Browser-safe key, starting %s. Sandbox or live is determined by this prefix.', 'deens-edge-payments-for-woocommerce' ), '<code>ept_live_b</code>' ),
			),
			'secret_key'      => array(
				'title'       => __( 'Secret key', 'deens-edge-payments-for-woocommerce' ),
				'type'        => 'password',
				/* translators: %s: example key prefix. */
				'description' => sprintf( __( 'Server-only key, starting %s. Never shared with the browser.', 'deens-edge-payments-for-woocommerce' ), '<code>ept_live_s</code>' ),
			),
			'webhook_secret'  => array(
				'title'       => __( 'Webhook signing secret', 'deens-edge-payments-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Leave blank if your API key can manage webhook subscriptions - the gateway registers its own and stores the secret automatically. Fill this in only if you created the subscription in the Edge dashboard yourself, and paste the secret Edge generated for it. Orders stay on hold until a webhook can be verified.', 'deens-edge-payments-for-woocommerce' ),
			),
		);
	}

	/**
	 * Save settings, refusing credential combinations that cannot work.
	 *
	 * Catching these here means a misconfiguration surfaces on the settings
	 * screen rather than as a failed payment after a shopper has entered a card.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		$secret      = trim( (string) $this->get_option( 'secret_key' ) );
		$publishable = trim( (string) $this->get_option( 'publishable_key' ) );

		// An entirely blank pair is the initial state, not an error.
		if ( '' === $secret && '' === $publishable ) {
			return $saved;
		}

		$error = WC_Edge_Mode::validate_pair( $secret, $publishable );

		if ( null !== $error ) {
			WC_Admin_Settings::add_error( self::describe_key_error( $error ) );

			return $saved;
		}

		$this->sync_webhook_subscription();

		return $saved;
	}

	/**
	 * Register or refresh the webhook subscription for the saved credentials.
	 *
	 * Without a subscription no order can ever leave on-hold, so a failure here
	 * is surfaced on the settings screen rather than discovered later by a
	 * merchant wondering why nothing completes.
	 *
	 * @return void
	 */
	private function sync_webhook_subscription() {
		// Re-read the gateway so it reflects what was just saved.
		$gateway = new self();

		$result = WC_Edge_Subscription_Reconciler::reconcile( $gateway );

		if ( ! is_wp_error( $result ) ) {
			WC_Admin_Settings::add_message( __( 'Edge Payments: webhooks are registered.', 'deens-edge-payments-for-woocommerce' ) );

			return;
		}

		// A 403 here means the API key has no webhook_subscriptions permission,
		// which is a token scope decision rather than a mistake in the settings.
		// Point at the manual secret instead of implying the keys are wrong.
		//
		// The API answers 403 with an empty body, so the status is the only
		// reliable signal; the message match is a fallback for anything that
		// arrives without one.
		$data   = $result->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		if ( 403 === $status || false !== stripos( $result->get_error_message(), 'forbidden' ) ) {
			if ( '' !== trim( (string) $this->get_option( 'webhook_secret' ) ) ) {
				WC_Admin_Settings::add_message(
					__( 'Edge Payments: settings saved. Using the webhook signing secret you supplied, since this API key cannot manage webhook subscriptions.', 'deens-edge-payments-for-woocommerce' )
				);

				return;
			}

			WC_Admin_Settings::add_error(
				__( 'Edge Payments: this API key is not permitted to manage webhook subscriptions, so the gateway could not register its own. Create a webhook in the Edge dashboard pointing at this site and paste its signing secret into "Webhook signing secret", or ask Edge to grant the key the developer.webhook_subscriptions permission. Until then orders will stay on hold.', 'deens-edge-payments-for-woocommerce' )
			);

			return;
		}

		if ( 'edge_callback_unreachable' === $result->get_error_code() ) {
			// Expected on a local site; not a misconfiguration to shout about.
			WC_Admin_Settings::add_message(
				__( 'Edge Payments: settings saved. Webhooks were not registered because this site is not reachable from the internet, so orders will stay on hold until it is.', 'deens-edge-payments-for-woocommerce' )
			);

			return;
		}

		WC_Admin_Settings::add_error(
			sprintf(
				/* translators: %s: error detail. */
				__( 'Edge Payments: webhooks could not be registered, so orders will not complete automatically. %s', 'deens-edge-payments-for-woocommerce' ),
				$result->get_error_message()
			)
		);
	}

	/**
	 * Translate a WC_Edge_Mode error code for display.
	 *
	 * @param string $code Error code from WC_Edge_Mode::validate_pair().
	 * @return string
	 */
	private static function describe_key_error( $code ) {
		switch ( $code ) {
			case 'missing_secret_key':
				return __( 'Edge Payments: a secret key is required.', 'deens-edge-payments-for-woocommerce' );

			case 'missing_publishable_key':
				return __( 'Edge Payments: a publishable key is required.', 'deens-edge-payments-for-woocommerce' );

			case 'malformed_secret_key':
				return __( 'Edge Payments: that secret key is not in the expected format.', 'deens-edge-payments-for-woocommerce' );

			case 'malformed_publishable_key':
				return __( 'Edge Payments: that publishable key is not in the expected format.', 'deens-edge-payments-for-woocommerce' );

			case 'secret_field_holds_publishable_key':
				return __( 'Edge Payments: the secret key field contains a publishable key. Check the two fields are not swapped.', 'deens-edge-payments-for-woocommerce' );

			case 'publishable_field_holds_secret_key':
				return __( 'Edge Payments: the publishable key field contains a secret key. This key would be exposed to the browser, so it has not been accepted.', 'deens-edge-payments-for-woocommerce' );

			case 'mode_mismatch':
				return __( 'Edge Payments: one key is live and the other is sandbox. Both keys must be from the same mode.', 'deens-edge-payments-for-woocommerce' );

			default:
				return __( 'Edge Payments: the API keys could not be validated.', 'deens-edge-payments-for-woocommerce' );
		}
	}

	/**
	 * Whether the configured keys form a usable pair.
	 *
	 * @return bool
	 */
	public function has_valid_keys() {
		return null === WC_Edge_Mode::validate_pair( $this->secret_key, $this->publishable_key );
	}

	/**
	 * The mode the gateway is operating in.
	 *
	 * @return string|null "live", "sandbox", or null when unconfigured.
	 */
	public function get_mode() {
		return $this->mode;
	}

	/**
	 * The secret key, for server-side calls only.
	 *
	 * @return string
	 */
	public function get_secret_key() {
		return $this->secret_key;
	}

	/**
	 * The publishable key, safe to hand to the browser.
	 *
	 * Re-checked rather than returned blindly: settings can be written by WP-CLI,
	 * a migration or direct SQL, and a secret key reaching the browser is not a
	 * mistake worth risking on the save-time check alone.
	 *
	 * @return string
	 */
	public function get_publishable_key() {
		return WC_Edge_Mode::is_publishable( $this->publishable_key ) ? $this->publishable_key : '';
	}

	/**
	 * Whether the gateway can be offered for the current request.
	 *
	 * Every reason to decline is checked here rather than at payment time, so a
	 * shopper is never offered Edge only to have it fail after entering a card.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( ! $this->has_valid_keys() ) {
			return false;
		}

		// Leave the admin free to configure the gateway before it is usable.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return true;
		}

		if ( ! $this->is_supported_checkout_surface() ) {
			return false;
		}

		if ( ! WC_Edge_Money::is_supported_currency( get_woocommerce_currency() ) ) {
			return false;
		}

		return $this->is_amount_chargeable();
	}

	/**
	 * Whether the current cart total is one Edge will accept.
	 *
	 * @return bool
	 */
	private function is_amount_chargeable() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			// No cart to judge - e.g. the gateway list on a settings screen.
			return true;
		}

		try {
			$cents = WC_Edge_Money::to_cents( WC()->cart->get_total( 'edit' ) );
		} catch ( InvalidArgumentException $e ) {
			return false;
		}

		return WC_Edge_Money::is_chargeable( $cents );
	}

	/**
	 * Whether this request is a checkout surface the gateway actually supports.
	 *
	 * The gateway is block-checkout only. `has_fields = false` alone would not
	 * achieve that: it removes the classic card fields but still lets the gateway
	 * be selected on the classic checkout, which would then submit with no
	 * hosted-form verification having happened at all.
	 *
	 * @return bool
	 */
	private function is_supported_checkout_surface() {
		// Paying for an existing order and saving a card both run outside the
		// block checkout, so neither can complete the hosted-form flow.
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			return false;
		}

		if ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() ) {
			return false;
		}

		// The Store API drives the block checkout.
		if ( self::is_store_api_request() ) {
			return true;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return self::checkout_uses_blocks();
		}

		return true;
	}

	/**
	 * Whether the checkout page is the block-based one.
	 *
	 * @return bool
	 */
	private static function checkout_uses_blocks() {
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils' ) ) {
			return \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default();
		}

		$checkout_page_id = wc_get_page_id( 'checkout' );

		return $checkout_page_id > 0 && has_block( 'woocommerce/checkout', $checkout_page_id );
	}

	/**
	 * Whether the current request is a WooCommerce Store API request.
	 *
	 * @return bool
	 */
	private static function is_store_api_request() {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		return '' !== $uri && false !== strpos( $uri, '/wc/store/' );
	}

	/**
	 * Confirm the bound payment demand and hand the order over to the webhook.
	 *
	 * No card data reaches this method: the hosted iframe collected and verified
	 * the card, and all that crosses the boundary is an opaque demand id, which
	 * is only ever used to cross-check the binding the server already holds.
	 *
	 * The result is `pending` rather than `success`, which the Store API answers
	 * as a 202: the payment has been accepted for processing and nobody yet knows
	 * whether it worked. The block script waits on the checkout for that outcome,
	 * so `edge_demand_id` and `edge_order_id` are handed back alongside -
	 * WooCommerce merges the whole return array into the response's payment
	 * details, where the script reads them (verified in WooCommerce 11.0.0,
	 * `src/StoreApi/Legacy.php`). `redirect` stays the fallback for any client
	 * that does not wait.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return $this->fail( __( 'That order could not be found.', 'deens-edge-payments-for-woocommerce' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The Store API authenticates this request; this value is cross-checked against server-held order meta and never trusted on its own.
		$submitted = isset( $_POST['edge_demand_id'] ) ? sanitize_text_field( wp_unslash( $_POST['edge_demand_id'] ) ) : '';

		if ( '' !== $submitted && ! self::is_uuid( $submitted ) ) {
			return $this->fail( __( 'That payment reference is not valid.', 'deens-edge-payments-for-woocommerce' ) );
		}

		// Confirm and the status write that follows it are one step as far as
		// everything else is concerned. Blocks leaves the order `pending` on the
		// way in here, and Edge can settle before this method has written
		// `on-hold`: a webhook arriving in that window either reads a status the
		// outcome rules call stale and does nothing, or completes the order only
		// for the copy held below to put `on-hold` back over it. Holding the lock
		// across both makes the webhook fail and be redelivered, and the poll
		// answer `processing`, which are both the right answers for that second.
		$bound_demand_id = (string) $order->get_meta( '_edge_demand_id' );
		$lock_owner      = false;

		if ( '' !== $bound_demand_id ) {
			$lock_owner = $this->take_demand_lock( $bound_demand_id );

			if ( false === $lock_owner ) {
				return $this->fail( __( 'Your payment is still being processed. Please wait a moment and try again.', 'deens-edge-payments-for-woocommerce' ) );
			}
		}

		// An order with no binding at all is left to the payment service, which
		// already has the right refusal for it.
		try {
			$confirmed = WC_Edge_Payment_Service::confirm( $this, $order, $submitted );

			if ( is_wp_error( $confirmed ) ) {
				if ( 'edge_payment_failed' === $confirmed->get_error_code() ) {
					// The ambiguous path read the demand back and found the bank had
					// declined it. Nothing else will ever say so: `failed` only
					// applies from `on-hold`, so the webhook that follows is a
					// no-change against the `pending` order Blocks left behind, and
					// the merchant is left with an order that looks abandoned. Same
					// wording as WC_Edge_Order_Sync, because it is the same news.
					$order->update_status( 'failed', __( 'Edge declined this payment.', 'deens-edge-payments-for-woocommerce' ) );
				}

				return $this->fail( $confirmed->get_error_message() );
			}

			$demand_id = $confirmed['demand_id'];

			// Edge can settle while the confirm response is still in flight, and
			// the webhook completes the order from another request. Re-read so this
			// one does not write a stale status back over it - through the helper,
			// because a plain wc_get_order() would be answered from the copy this
			// request already loaded and show nothing of that webhook's write.
			$fresh = WC_Edge_Order_Sync::reload_order( $order_id );

			if ( $fresh instanceof WC_Order ) {
				$order = $fresh;
			}

			if ( 'failed' === $confirmed['prior_state'] ) {
				// The same demand, confirmed again after the bank said no. Worth
				// saying on the order, because the attempts are otherwise
				// indistinguishable.
				$order->add_order_note( __( 'Retrying the payment with Edge after a decline.', 'deens-edge-payments-for-woocommerce' ) );
			}

			$order->set_transaction_id( $demand_id );

			// Confirming means Edge accepted the payment for processing, not that
			// it succeeded. Completing the order here would mark it paid before the
			// processor has said anything, so the order waits for the webhook, which
			// is the authoritative source - unless that has already arrived, in which
			// case on-hold would be a step backwards.
			if ( ! $order->is_paid() ) {
				$order->update_status(
					'on-hold',
					__( 'Awaiting confirmation from Edge.', 'deens-edge-payments-for-woocommerce' )
				);
			}

			$order->save();
		} finally {
			if ( false !== $lock_owner ) {
				WC_Edge_Demand_Lock::release( $bound_demand_id, $lock_owner );
			}
		}

		return array(
			'result'         => 'pending',
			'redirect'       => $this->get_return_url( $order ),
			'edge_demand_id' => $demand_id,
			// The order id goes back the same way for the same reason. Blocks
			// seeds its checkout store's `orderId` from the draft order in the
			// opening GET /wc/store/v1/checkout and never refreshes it from the
			// POST response, so on a fresh session the `onCheckoutSuccess`
			// observer is handed 0 and has nothing to poll for (WooCommerce
			// 11.0.0). This value is the order that was actually paid.
			'edge_order_id'  => (string) $order->get_id(),
		);
	}

	/**
	 * Wait a short while for the demand lock, rather than refusing on the first try.
	 *
	 * The holder is a sync that is one round trip to Edge from finishing, so a
	 * shopper who double-submits is better served by waiting a moment than by
	 * being told to try again. Three tries is under a second, which is short
	 * enough that the request does not sit on a PHP worker.
	 *
	 * @param string $demand_id Demand the order is bound to.
	 * @return string|false Owner token, or false if somebody kept hold of it.
	 */
	private function take_demand_lock( $demand_id ) {
		for ( $attempt = 0; $attempt < self::LOCK_ATTEMPTS; $attempt++ ) {
			if ( $attempt > 0 ) {
				usleep( self::LOCK_RETRY_DELAY_US );
			}

			$owner = WC_Edge_Demand_Lock::acquire( $demand_id, self::CONFIRM_LOCK_TTL );

			if ( false !== $owner ) {
				return $owner;
			}
		}

		WC_Edge_Logger::info( 'Gave up waiting for the sync lock on demand ' . $demand_id );

		return false;
	}

	/**
	 * Refund a payment through Edge.
	 *
	 * Thin on purpose - the decisions live in WC_Edge_Refund_Service, the same
	 * way process_payment() leaves them to WC_Edge_Payment_Service.
	 *
	 * Returning true does not mean the money has moved. Edge accepts a refund as
	 * `pending` and confirms it later on the webhook; WooCommerce, meanwhile,
	 * treats a true return as final and will already have restocked, revoked
	 * downloads and emailed the customer. A refund that later fails is therefore
	 * reported on the order rather than unwound.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount   Amount to refund.
	 * @param string $reason   Merchant's reason for the refund.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error(
				'edge_order_missing',
				__( 'That order could not be found.', 'deens-edge-payments-for-woocommerce' )
			);
		}

		return WC_Edge_Refund_Service::refund( $this, $order, $amount, $reason );
	}

	/**
	 * Build a checkout failure result.
	 *
	 * @param string $message Shopper-facing message.
	 * @return array
	 */
	private function fail( $message ) {
		wc_add_notice( $message, 'error' );

		return array(
			'result'  => 'failure',
			'message' => $message,
		);
	}

	/**
	 * Whether a value is UUID-shaped.
	 *
	 * Input validation only. It says nothing about whether the resource belongs
	 * to this order, which is what the binding check in the payment service is
	 * for.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_uuid( $value ) {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			(string) $value
		);
	}
}
