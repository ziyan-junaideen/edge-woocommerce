<?php
/**
 * Plugin Name: Deen's Edge Payments for WooCommerce
 * Plugin URI: https://github.com/ziyan-junaideen/edge-woocommerce
 * Description: Accept payments through Edge Payment Technologies on your WooCommerce store. An independent plugin, not affiliated with or endorsed by Edge. Provided as is, without warranty of any kind.
 * Version: 2.4.0
 *
 * Author: Ziyan Junaideen
 * Author URI: https://www.jdeen.com
 *
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 11.0
 *
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Text Domain: deens-edge-payments-for-woocommerce
 *
 * @package Deens_Edge_Payments_For_WooCommerce
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_EDGE_VERSION', '2.4.0' );
define( 'WC_EDGE_PLUGIN_FILE', __FILE__ );

/**
 * WC Edge Payment gateway plugin class.
 *
 * @class WC_Edge_Payments
 */
class WC_Edge_Payments {

	/**
	 * The refund row WooCommerce is in the middle of creating.
	 *
	 * @var WC_Order_Refund|null
	 */
	private static $claimed_refund = null;

	/**
	 * Plugin bootstrapping.
	 */
	public static function init() {
		// Edge Payments gateway class.
		add_action( 'plugins_loaded', array( __CLASS__, 'includes' ), 0 );

		// Make the Edge Payments gateway available to WC.
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );

		// Registers WooCommerce Blocks integration.
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'woocommerce_gateway_edge_woocommerce_block_support' ) );

		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_compatibility' ) );

		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
	}

	/**
	 * Declare High-Performance Order Storage compatibility.
	 *
	 * Claimed only because it has been exercised: the gateway reads and writes
	 * order data exclusively through the CRUD API, and complete payments -
	 * binding, meta, status transitions and the webhook handler's order lookup by
	 * meta - have all run against a store with HPOS enabled.
	 *
	 * @return void
	 */
	public static function declare_compatibility() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			true
		);
	}

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		require_once self::plugin_abspath() . 'includes/class-wc-edge-attempt-store.php';
		require_once self::plugin_abspath() . 'includes/class-wc-edge-demand-lock.php';

		WC_Edge_Attempt_Store::install();
		update_option( WC_Edge_Attempt_Store::SCHEMA_OPTION, WC_Edge_Attempt_Store::SCHEMA_VERSION );

		WC_Edge_Demand_Lock::install();
		update_option( WC_Edge_Demand_Lock::SCHEMA_OPTION, WC_Edge_Demand_Lock::SCHEMA_VERSION );
	}

	/**
	 * Add the Edge Payment gateway to the list of available gateways.
	 *
	 * @param array $gateways Registered gateways.
	 * @return array
	 */
	public static function add_gateway( $gateways ) {

		$gateways[] = 'WC_Gateway_Edge';

		return $gateways;
	}

	/**
	 * Plugin includes.
	 */
	public static function includes() {

		$path = self::plugin_abspath() . 'includes/';

		require_once $path . 'class-wc-edge-money.php';
		require_once $path . 'class-wc-edge-mode.php';
		require_once $path . 'class-wc-edge-countries.php';
		require_once $path . 'class-wc-edge-api-exception.php';
		require_once $path . 'class-wc-edge-api-client.php';
		require_once $path . 'class-wc-edge-client-factory.php';
		require_once $path . 'class-wc-edge-attempt-store.php';
		require_once $path . 'class-wc-edge-demand-lock.php';
		require_once $path . 'class-wc-edge-fingerprint.php';
		require_once $path . 'class-wc-edge-order-mapper.php';
		require_once $path . 'class-wc-edge-logger.php';
		require_once $path . 'class-wc-edge-cart-items.php';
		require_once $path . 'class-wc-edge-payment-outcome.php';
		require_once $path . 'class-wc-edge-order-sync.php';
		require_once $path . 'class-wc-edge-payment-service.php';
		require_once $path . 'class-wc-edge-refund-outcome.php';
		require_once $path . 'class-wc-edge-refund-service.php';
		require_once $path . 'class-wc-edge-rest-controller.php';
		require_once $path . 'class-wc-edge-webhook-signature.php';
		require_once $path . 'class-wc-edge-webhook-store.php';
		require_once $path . 'class-wc-edge-webhook-controller.php';
		require_once $path . 'class-wc-edge-subscription-reconciler.php';

		add_action( 'rest_api_init', array( 'WC_Edge_REST_Controller', 'register' ) );
		add_action( 'rest_api_init', array( 'WC_Edge_Webhook_Controller', 'register' ) );

		// Housekeeping for the three bounded tables.
		add_action( 'wc_edge_daily_cleanup', array( __CLASS__, 'run_cleanup' ) );

		if ( ! wp_next_scheduled( 'wc_edge_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wc_edge_daily_cleanup' );
		}

		// Bind the pre-order attempt to the order the moment one exists.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'adopt_attempt' ), 10, 1 );

		// Follow the session when a guest signs in or creates an account, so the
		// attempt claimed under the guest key is still findable. See
		// rekey_attempts().
		add_action( 'woocommerce_guest_session_to_user_id', array( __CLASS__, 'rekey_attempts' ), 10, 2 );

		// The shopper watches the whole on-hold -> settled cycle on the checkout,
		// so these two would only ever be noise. See suppress_customer_email().
		add_filter( 'woocommerce_email_enabled_customer_on_hold_order', array( __CLASS__, 'suppress_customer_email' ), 10, 3 );
		add_filter( 'woocommerce_email_enabled_customer_failed_order', array( __CLASS__, 'suppress_customer_email' ), 10, 3 );

		// Hold on to the refund row WooCommerce is building, so process_refund()
		// can identify it. See claim_refund().
		add_action( 'woocommerce_create_refund', array( __CLASS__, 'claim_refund' ), 10, 2 );

		self::maybe_upgrade_settings();

		// The plugin is symlinked in development, where activation hooks do not
		// always fire, so the schema is checked here as well. The check is a
		// single option read when the version already matches.
		WC_Edge_Attempt_Store::maybe_install();
		WC_Edge_Webhook_Store::maybe_install();
		WC_Edge_Demand_Lock::maybe_install();

		// Make the WC_Gateway_Edge class available.
		if ( class_exists( 'WC_Payment_Gateway' ) ) {
			require_once $path . 'class-wc-gateway-edge.php';
		}
	}

	/**
	 * Collapse the old four-key settings into a single key pair.
	 *
	 * The previous layout stored separate sandbox and live pairs alongside a
	 * `testmode` flag. The new layout keeps one pair and derives the mode from
	 * the key prefix.
	 *
	 * The field name `publishable_key` existed before and meant "the live
	 * publishable key". Adopting it verbatim would silently promote a store that
	 * was running in test mode to live credentials, so the old flag decides which
	 * pair is carried over. Anything that does not migrate to a valid, matching
	 * pair is cleared and the gateway disabled: refusing to run is the safe
	 * direction when the intended mode is ambiguous.
	 *
	 * @return void
	 */
	private static function maybe_upgrade_settings() {
		$settings = get_option( 'woocommerce_edge_settings', array() );

		// `testmode` is the marker of the old layout.
		if ( ! is_array( $settings ) || ! array_key_exists( 'testmode', $settings ) ) {
			return;
		}

		// The old blocks class treated anything other than "no" as test mode.
		$was_sandbox = 'no' !== ( isset( $settings['testmode'] ) ? $settings['testmode'] : 'yes' );

		$publishable = $was_sandbox
			? ( isset( $settings['test_publishable_key'] ) ? $settings['test_publishable_key'] : '' )
			: ( isset( $settings['publishable_key'] ) ? $settings['publishable_key'] : '' );

		$secret = $was_sandbox
			? ( isset( $settings['test_private_key'] ) ? $settings['test_private_key'] : '' )
			: ( isset( $settings['private_key'] ) ? $settings['private_key'] : '' );

		unset(
			$settings['testmode'],
			$settings['test_publishable_key'],
			$settings['test_private_key'],
			$settings['private_key']
		);

		$settings['publishable_key'] = trim( (string) $publishable );
		$settings['secret_key']      = trim( (string) $secret );

		if ( null !== WC_Edge_Mode::validate_pair( $settings['secret_key'], $settings['publishable_key'] ) ) {
			$settings['publishable_key'] = '';
			$settings['secret_key']      = '';
			$settings['enabled']         = 'no';
		}

		update_option( 'woocommerce_edge_settings', $settings );
	}

	/**
	 * Trim the bounded tables.
	 *
	 * @return void
	 */
	public static function run_cleanup() {
		WC_Edge_Attempt_Store::purge();
		WC_Edge_Webhook_Store::purge();
		WC_Edge_Demand_Lock::purge();
	}

	/**
	 * Carry the checkout attempt onto the order that was just created.
	 *
	 * Until this point the payment demand belongs to a session, because the
	 * iframe had to mount before an order existed. From here on the order is the
	 * binding, and it is what process_payment() and the webhook handler trust -
	 * never a value supplied by the browser.
	 *
	 * @param WC_Order $order Newly created order.
	 * @return void
	 */
	public static function adopt_attempt( $order ) {
		if ( ! $order instanceof WC_Order || 'edge' !== $order->get_payment_method() ) {
			return;
		}

		if ( ! WC()->session || ! WC()->session->get_customer_id() ) {
			return;
		}

		global $wpdb;

		$table       = WC_Edge_Attempt_Store::table_name();
		$session_key = (string) WC()->session->get_customer_id();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$attempt = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE session_key = %s AND status = %s AND demand_id IS NOT NULL
				 ORDER BY updated_at DESC LIMIT 1",
				$session_key,
				WC_Edge_Attempt_Store::STATUS_PREPARED
			)
		);
		// phpcs:enable

		if ( ! $attempt ) {
			return;
		}

		// CRUD rather than update_post_meta, so this works under HPOS.
		$order->update_meta_data( '_edge_demand_id', $attempt->demand_id );
		$order->update_meta_data( '_edge_attempt_key', $attempt->attempt_key );
		$order->update_meta_data( '_edge_mode', $attempt->mode );
		$order->update_meta_data( '_edge_amount_cents', $attempt->amount_cents );
		$order->update_meta_data( '_edge_currency', $attempt->currency );
		$order->save();

		WC_Edge_Attempt_Store::adopt( $attempt->attempt_key, $order->get_id() );
	}

	/**
	 * Carry this session's checkout attempts over to the shopper's user id.
	 *
	 * WooCommerce migrates a guest session to the user id as soon as the shopper
	 * signs in or creates an account, and on the block checkout that happens
	 * before `woocommerce_store_api_checkout_order_processed` fires. Everything
	 * an attempt is found by is the session key, so without this adopt_attempt()
	 * looks under the new key, finds nothing, and the order is confirmed with no
	 * binding at all. The status route authorises on the same row and would miss
	 * it too.
	 *
	 * @param string $guest_session_id Session key the attempts were claimed under.
	 * @param string $user_id          Session key WooCommerce moved to.
	 * @return void
	 */
	public static function rekey_attempts( $guest_session_id, $user_id ) {
		WC_Edge_Attempt_Store::rekey_session( (string) $guest_session_id, (string) $user_id );
	}

	/**
	 * Keep the on-hold and payment-failed emails away from Edge shoppers.
	 *
	 * An Edge order is on-hold for the seconds between confirm and the outcome,
	 * and the shopper is watching that happen on the checkout page. A decline and
	 * a retry would send them two "your order is on hold" emails and a "payment
	 * failed" one whose pay link points at `order-pay` - a surface this gateway
	 * refuses, so following it leads nowhere.
	 *
	 * Only the customer-facing pair. The merchant still gets the admin failed
	 * order email, which is the one somebody acts on.
	 *
	 * @param bool     $enabled Whether WooCommerce would send it.
	 * @param mixed    $order   The object the email is about, which is not always an order.
	 * @param WC_Email $email   The email being considered.
	 * @return bool
	 */
	public static function suppress_customer_email( $enabled, $order, $email ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Declared to match the filter, which passes three arguments; which email it is has already been decided by which of the two hooks called us.
		if ( $order instanceof WC_Order && 'edge' === $order->get_payment_method() ) {
			return false;
		}

		return $enabled;
	}

	/**
	 * Hold on to the refund row WooCommerce is about to save.
	 *
	 * WC_Payment_Gateway::process_refund() is handed an order id, an amount and
	 * a reason - never the WC_Order_Refund itself - but the Edge idempotency key
	 * has to be tied to something that identifies *this* refund and no other, or
	 * two deliberate refunds of the same amount collapse into one.
	 *
	 * wc_create_refund() fires this before it saves the row and calls the
	 * gateway afterwards, so the id is populated by the time claimed_refund()
	 * reads it back - PHP hands the same object around, not a copy.
	 *
	 * @param WC_Order_Refund $refund Refund being created.
	 * @param array           $args   Arguments wc_create_refund() was called with.
	 * @return void
	 */
	public static function claim_refund( $refund, $args ) {
		self::$claimed_refund = null;

		if ( ! $refund instanceof WC_Order_Refund ) {
			return;
		}

		// A manual refund never reaches a gateway, so claiming it would only
		// leave a stale object behind for whatever runs next.
		if ( empty( $args['refund_payment'] ) ) {
			return;
		}

		self::$claimed_refund = $refund;
	}

	/**
	 * Take the claimed refund row, if it belongs to this order.
	 *
	 * Single use: the claim is dropped as it is read, so a stale object can
	 * never be handed to a later refund on a different order.
	 *
	 * @param int $order_id Order the refund must belong to.
	 * @return WC_Order_Refund|null
	 */
	public static function claimed_refund( $order_id ) {
		$refund = self::$claimed_refund;

		self::$claimed_refund = null;

		if ( ! $refund instanceof WC_Order_Refund ) {
			return null;
		}

		return (int) $refund->get_parent_id() === (int) $order_id ? $refund : null;
	}

	/**
	 * Plugin url.
	 *
	 * @return string
	 */
	public static function plugin_url() {
		return untrailingslashit( plugins_url( '/', __FILE__ ) );
	}

	/**
	 * Plugin path.
	 *
	 * @return string
	 */
	public static function plugin_abspath() {
		return trailingslashit( plugin_dir_path( __FILE__ ) );
	}

	/**
	 * Registers WooCommerce Blocks integration.
	 */
	public static function woocommerce_gateway_edge_woocommerce_block_support() {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			require_once self::plugin_abspath() . 'includes/blocks/class-wc-edge-payments-blocks.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new WC_Gateway_Edge_Blocks_Support() );
				}
			);
		}
	}
}

WC_Edge_Payments::init();
