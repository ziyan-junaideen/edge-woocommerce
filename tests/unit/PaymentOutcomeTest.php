<?php
/**
 * @package WooCommerce Edge Payments Gateway
 */

namespace EdgePayments\EdgeWoocommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC_Edge_Payment_Outcome;

/**
 * @covers \WC_Edge_Payment_Outcome
 */
class PaymentOutcomeTest extends TestCase {

	/**
	 * Every order status the decision has to survive.
	 */
	private const STATUSES = array( 'on-hold', 'pending', 'failed', 'processing', 'completed', 'cancelled' );

	private function attributes( array $values ): object {
		return (object) $values;
	}

	/**
	 * Money that has been taken is never stale, so success applies from
	 * wherever the order happens to be.
	 *
	 * @dataProvider order_statuses
	 */
	public function test_success_completes_an_unpaid_order_from_any_status( string $status ): void {
		$this->assertSame(
			WC_Edge_Payment_Outcome::COMPLETE,
			WC_Edge_Payment_Outcome::decide( 'succeeded', $status, false )
		);
	}

	/**
	 * @dataProvider order_statuses
	 */
	public function test_success_on_a_paid_order_is_already_paid( string $status ): void {
		$this->assertSame(
			WC_Edge_Payment_Outcome::ALREADY_PAID,
			WC_Edge_Payment_Outcome::decide( 'succeeded', $status, true )
		);
	}

	public function test_a_failure_is_applied_from_on_hold(): void {
		// on-hold is where process_payment() leaves a confirmed order.
		$this->assertSame(
			WC_Edge_Payment_Outcome::FAIL,
			WC_Edge_Payment_Outcome::decide( 'failed', 'on-hold', false )
		);
	}

	public function test_a_failure_on_an_already_failed_order_changes_nothing(): void {
		$this->assertSame(
			WC_Edge_Payment_Outcome::ALREADY_FAILED,
			WC_Edge_Payment_Outcome::decide( 'failed', 'failed', false )
		);
	}

	/**
	 * A stale failure for attempt one must never clobber a retry that has
	 * already moved the order, nor an order an admin has moved by hand.
	 *
	 * @dataProvider statuses_a_failure_leaves_alone
	 */
	public function test_a_failure_from_any_other_status_is_left_alone( string $status ): void {
		$this->assertSame(
			WC_Edge_Payment_Outcome::NO_CHANGE,
			WC_Edge_Payment_Outcome::decide( 'failed', $status, false )
		);
	}

	public static function statuses_a_failure_leaves_alone(): array {
		return array(
			// A retry confirm is in flight.
			'pending'    => array( 'pending' ),
			'processing' => array( 'processing' ),
			'completed'  => array( 'completed' ),
			'cancelled'  => array( 'cancelled' ),
			'unknown'    => array( 'wc-custom-status' ),
			'empty'      => array( '' ),
		);
	}

	/**
	 * @dataProvider order_statuses
	 */
	public function test_a_failure_never_un_pays_an_order( string $status ): void {
		$this->assertSame(
			WC_Edge_Payment_Outcome::IGNORED_STALE_FAILURE,
			WC_Edge_Payment_Outcome::decide( 'failed', $status, true )
		);
	}

	/**
	 * @dataProvider reconcile_states
	 */
	public function test_money_that_moved_afterwards_is_for_the_merchant_to_reconcile( string $state ): void {
		foreach ( self::STATUSES as $status ) {
			foreach ( array( true, false ) as $is_paid ) {
				$this->assertSame(
					WC_Edge_Payment_Outcome::RECONCILE,
					WC_Edge_Payment_Outcome::decide( $state, $status, $is_paid ),
					$state . ' from ' . $status
				);
			}
		}
	}

	public static function reconcile_states(): array {
		return array(
			'reversed' => array( 'reversed' ),
			'refunded' => array( 'refunded' ),
			'disputed' => array( 'disputed' ),
		);
	}

	/**
	 * @dataProvider quiet_states
	 */
	public function test_a_state_on_the_way_to_an_outcome_changes_nothing( string $state ): void {
		foreach ( self::STATUSES as $status ) {
			foreach ( array( true, false ) as $is_paid ) {
				$this->assertSame(
					WC_Edge_Payment_Outcome::NO_CHANGE,
					WC_Edge_Payment_Outcome::decide( $state, $status, $is_paid ),
					$state . ' from ' . $status
				);
			}
		}
	}

	public static function quiet_states(): array {
		return array(
			'pending'    => array( 'pending' ),
			'processing' => array( 'processing' ),
			'incomplete' => array( 'incomplete' ),
			'ready'      => array( 'ready' ),
			'confirmed'  => array( 'confirmed' ),
			'canceled'   => array( 'canceled' ),
		);
	}

	/**
	 * @dataProvider unrecognised_states
	 */
	public function test_a_state_we_do_not_know_is_never_guessed_at( $state ): void {
		foreach ( self::STATUSES as $status ) {
			foreach ( array( true, false ) as $is_paid ) {
				$this->assertSame(
					WC_Edge_Payment_Outcome::UNRECOGNISED,
					WC_Edge_Payment_Outcome::decide( $state, $status, $is_paid )
				);
			}
		}
	}

	public static function unrecognised_states(): array {
		return array(
			'empty'     => array( '' ),
			'bogus'     => array( 'bogus' ),
			'cancelled' => array( 'cancelled' ), // Edge spells it with one l.
			'null'      => array( null ),
			'numeric'   => array( 0 ),
		);
	}

	public function test_the_storage_prefix_on_an_order_status_is_tolerated(): void {
		$this->assertSame(
			WC_Edge_Payment_Outcome::FAIL,
			WC_Edge_Payment_Outcome::decide( 'failed', 'wc-on-hold', false )
		);
		$this->assertSame(
			WC_Edge_Payment_Outcome::ALREADY_FAILED,
			WC_Edge_Payment_Outcome::decide( 'failed', 'WC-Failed', false )
		);
	}

	public function test_a_non_string_order_status_is_not_a_fatal(): void {
		$this->assertSame(
			WC_Edge_Payment_Outcome::NO_CHANGE,
			WC_Edge_Payment_Outcome::decide( 'failed', null, false )
		);
	}

	/**
	 * @dataProvider checkout_statuses
	 */
	public function test_checkout_status( $state, string $expected ): void {
		$this->assertSame( $expected, WC_Edge_Payment_Outcome::checkout_status( $state ) );
	}

	public static function checkout_statuses(): array {
		return array(
			// Nothing more the shopper can do at the checkout: the payment went
			// through, and anything that happened to it since is the merchant's.
			'succeeded'  => array( 'succeeded', WC_Edge_Payment_Outcome::CHECKOUT_SUCCEEDED ),
			'reversed'   => array( 'reversed', WC_Edge_Payment_Outcome::CHECKOUT_SUCCEEDED ),
			'refunded'   => array( 'refunded', WC_Edge_Payment_Outcome::CHECKOUT_SUCCEEDED ),
			'disputed'   => array( 'disputed', WC_Edge_Payment_Outcome::CHECKOUT_SUCCEEDED ),
			// Declined: the shopper can try another card.
			'failed'     => array( 'failed', WC_Edge_Payment_Outcome::CHECKOUT_FAILED ),
			// Still settling: keep waiting.
			'pending'    => array( 'pending', WC_Edge_Payment_Outcome::CHECKOUT_PROCESSING ),
			'processing' => array( 'processing', WC_Edge_Payment_Outcome::CHECKOUT_PROCESSING ),
			'incomplete' => array( 'incomplete', WC_Edge_Payment_Outcome::CHECKOUT_PROCESSING ),
			'ready'      => array( 'ready', WC_Edge_Payment_Outcome::CHECKOUT_PROCESSING ),
			'confirmed'  => array( 'confirmed', WC_Edge_Payment_Outcome::CHECKOUT_PROCESSING ),
			'canceled'   => array( 'canceled', WC_Edge_Payment_Outcome::CHECKOUT_PROCESSING ),
			'empty'      => array( '', WC_Edge_Payment_Outcome::CHECKOUT_PROCESSING ),
			'bogus'      => array( 'bogus', WC_Edge_Payment_Outcome::CHECKOUT_PROCESSING ),
			'null'       => array( null, WC_Edge_Payment_Outcome::CHECKOUT_PROCESSING ),
		);
	}

	/**
	 * @dataProvider negative_cvc2_checks
	 */
	public function test_a_rejected_security_code_is_named( string $value ): void {
		$message = WC_Edge_Payment_Outcome::shopper_message(
			$this->attributes(
				array(
					'cvc2_check'                 => $value,
					'address_line1_verification' => 'match',
					'postal_code_verification'   => 'match',
				)
			)
		);

		$this->assertStringContainsString( 'security code', $message );
	}

	public static function negative_cvc2_checks(): array {
		return array(
			'mismatch' => array( 'mismatch' ),
			'missing'  => array( 'missing' ),
		);
	}

	/**
	 * @dataProvider negative_address_checks
	 */
	public function test_a_rejected_billing_address_is_named( string $field ): void {
		$message = WC_Edge_Payment_Outcome::shopper_message(
			$this->attributes(
				array(
					'cvc2_check'                 => 'match',
					'address_line1_verification' => 'match',
					'postal_code_verification'   => 'match',
					$field                       => 'mismatch',
				)
			)
		);

		$this->assertStringContainsString( 'billing address', $message );
	}

	public static function negative_address_checks(): array {
		return array(
			'address line 1' => array( 'address_line1_verification' ),
			'postal code'    => array( 'postal_code_verification' ),
		);
	}

	public function test_the_security_code_wins_when_both_failed(): void {
		// It is the cheaper of the two to get right on a second attempt.
		$message = WC_Edge_Payment_Outcome::shopper_message(
			$this->attributes(
				array(
					'cvc2_check'                 => 'mismatch',
					'address_line1_verification' => 'mismatch',
					'postal_code_verification'   => 'mismatch',
				)
			)
		);

		$this->assertStringContainsString( 'security code', $message );
		$this->assertStringNotContainsString( 'billing address', $message );
	}

	public function test_everything_matching_gets_the_generic_message(): void {
		$message = WC_Edge_Payment_Outcome::shopper_message(
			$this->attributes(
				array(
					'cvc2_check'                 => 'match',
					'address_line1_verification' => 'match',
					'postal_code_verification'   => 'match',
				)
			)
		);

		$this->assertStringContainsString( 'card details', $message );
	}

	/**
	 * A check that did not run says nothing about what the shopper typed.
	 *
	 * @dataProvider inconclusive_results
	 */
	public function test_an_inconclusive_result_is_not_treated_as_a_rejection( string $cvc2, string $avs ): void {
		$message = WC_Edge_Payment_Outcome::shopper_message(
			$this->attributes(
				array(
					'cvc2_check'                 => $cvc2,
					'address_line1_verification' => $avs,
					'postal_code_verification'   => $avs,
				)
			)
		);

		$this->assertStringContainsString( 'card details', $message );
	}

	public static function inconclusive_results(): array {
		return array(
			// `unprocessed` is the cvc2_check default and means the check did not
			// run. The sandbox's incorrect-CVC card writes it, so that card gets
			// the generic copy rather than a guess about the security code.
			'unprocessed'  => array( 'unprocessed', 'match' ),
			'unavailable'  => array( 'unavailable', 'unavailable' ),
			'unresponsive' => array( 'unresponsive', 'retry' ),
			'defaults'     => array( 'unprocessed', 'unverified' ),
		);
	}

	public function test_no_attributes_at_all_gets_the_generic_message(): void {
		$this->assertStringContainsString(
			'card details',
			WC_Edge_Payment_Outcome::shopper_message( new \stdClass() )
		);
		$this->assertStringContainsString(
			'card details',
			WC_Edge_Payment_Outcome::shopper_message( null )
		);
		$this->assertStringContainsString(
			'card details',
			WC_Edge_Payment_Outcome::shopper_message( array() )
		);
	}

	public function test_attributes_may_arrive_as_an_array(): void {
		$this->assertStringContainsString(
			'security code',
			WC_Edge_Payment_Outcome::shopper_message( array( 'cvc2_check' => 'mismatch' ) )
		);
		$this->assertStringContainsString(
			'billing address',
			WC_Edge_Payment_Outcome::shopper_message( array( 'postal_code_verification' => 'mismatch' ) )
		);
	}

	public function test_a_value_that_is_not_a_string_is_ignored(): void {
		$this->assertStringContainsString(
			'card details',
			WC_Edge_Payment_Outcome::shopper_message(
				$this->attributes(
					array(
						'cvc2_check'                 => null,
						'address_line1_verification' => 0,
						'postal_code_verification'   => array( 'mismatch' ),
					)
				)
			)
		);
	}

	public function test_a_value_is_compared_case_insensitively(): void {
		// Ecto renders the enum lowercase, but nothing here depends on that.
		$this->assertStringContainsString(
			'security code',
			WC_Edge_Payment_Outcome::shopper_message( array( 'cvc2_check' => ' Mismatch ' ) )
		);
	}

	public static function order_statuses(): array {
		$cases = array();

		foreach ( self::STATUSES as $status ) {
			$cases[ $status ] = array( $status );
		}

		return $cases;
	}
}
