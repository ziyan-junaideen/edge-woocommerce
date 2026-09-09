<?php
/**
 * @package WooCommerce Edge Payments Gateway
 */

namespace EdgePayments\EdgeWoocommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC_Edge_Fingerprint;

/**
 * @covers \WC_Edge_Fingerprint
 */
class FingerprintTest extends TestCase {

	private function facts( array $overrides = array() ): array {
		return array_merge(
			array(
				'cart_hash'          => 'abc123',
				'amount_cents'       => 2500,
				'currency'           => 'USD',
				'mode'               => 'live',
				'publishable_key'    => 'ept_live_bXXXX',
				'billing_first_name' => 'Ada',
				'billing_last_name'  => 'Lovelace',
				'billing_email'      => 'ada@example.test',
				'billing_address_1'  => '1 Test Street',
				'billing_city'       => 'Portland',
				'billing_state'      => 'OR',
				'billing_postcode'   => '97205',
				'billing_country'    => 'US',
			),
			$overrides
		);
	}

	public function test_is_stable_for_identical_facts(): void {
		$this->assertSame(
			WC_Edge_Fingerprint::of( $this->facts() ),
			WC_Edge_Fingerprint::of( $this->facts() )
		);
	}

	public function test_is_a_sha256_digest(): void {
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', WC_Edge_Fingerprint::of( $this->facts() ) );
	}

	public function test_key_order_does_not_matter(): void {
		$facts   = $this->facts();
		$shuffled = array_reverse( $facts, true );

		$this->assertSame(
			WC_Edge_Fingerprint::of( $facts ),
			WC_Edge_Fingerprint::of( $shuffled )
		);
	}

	/**
	 * The case this exists for. Each of these must rotate the key, because Edge
	 * would otherwise silently return the demand created for the old facts.
	 *
	 * @dataProvider provide_significant_changes
	 */
	public function test_significant_changes_rotate_the_key( string $field, $value ): void {
		$this->assertNotSame(
			WC_Edge_Fingerprint::of( $this->facts() ),
			WC_Edge_Fingerprint::of( $this->facts( array( $field => $value ) ) ),
			$field . ' must change the fingerprint'
		);
	}

	public function provide_significant_changes(): array {
		return array(
			'amount'          => array( 'amount_cents', 9999 ),
			'cart contents'   => array( 'cart_hash', 'different' ),
			'currency'        => array( 'currency', 'EUR' ),
			'mode'            => array( 'mode', 'sandbox' ),
			'credentials'     => array( 'publishable_key', 'ept_live_bYYYY' ),
			'payer email'     => array( 'billing_email', 'someone.else@example.test' ),
			'payer name'      => array( 'billing_last_name', 'Byron' ),
			'billing street'  => array( 'billing_address_1', '2 Test Street' ),
			'billing city'    => array( 'billing_city', 'Seattle' ),
			'billing postal'  => array( 'billing_postcode', '98101' ),
			'billing country' => array( 'billing_country', 'CA' ),
			'shipping street' => array( 'shipping_address_1', '9 Other Road' ),
			'shipping country'=> array( 'shipping_country', 'CA' ),
		);
	}

	/**
	 * Cosmetic differences must not rotate the key: retyping an address with
	 * different capitalisation is not a new payment, and a spurious rotation
	 * means a fresh demand and a remounted iframe for no reason.
	 *
	 * @dataProvider provide_cosmetic_changes
	 */
	public function test_cosmetic_changes_do_not_rotate_the_key( array $overrides ): void {
		$this->assertSame(
			WC_Edge_Fingerprint::of( $this->facts() ),
			WC_Edge_Fingerprint::of( $this->facts( $overrides ) )
		);
	}

	public function provide_cosmetic_changes(): array {
		return array(
			'address casing'   => array( array( 'billing_address_1' => '1 TEST STREET' ) ),
			'email casing'     => array( array( 'billing_email' => 'Ada@Example.TEST' ) ),
			'country casing'   => array( array( 'billing_country' => 'us' ) ),
			'currency casing'  => array( array( 'currency' => 'usd' ) ),
			'padded whitespace'=> array( array( 'billing_city' => '  Portland  ' ) ),
			'internal spacing' => array( array( 'billing_address_1' => '1  Test   Street' ) ),
			'amount as string' => array( array( 'amount_cents' => '2500' ) ),
		);
	}

	/**
	 * A missing field and an empty one must agree, or an absent optional value
	 * would rotate the key depending on how the caller happened to build it.
	 */
	public function test_absent_and_empty_are_equivalent(): void {
		$with_empty = $this->facts( array( 'billing_address_2' => '' ) );
		$without    = $this->facts();
		unset( $without['billing_address_2'] );

		$this->assertSame(
			WC_Edge_Fingerprint::of( $without ),
			WC_Edge_Fingerprint::of( $with_empty )
		);
	}

	/**
	 * Only declared fields count, so a caller adding an unrelated key cannot
	 * invalidate every attempt in flight.
	 */
	public function test_unknown_fields_are_ignored(): void {
		$this->assertSame(
			WC_Edge_Fingerprint::of( $this->facts() ),
			WC_Edge_Fingerprint::of( $this->facts( array( 'something_new' => 'value' ) ) )
		);
	}

	public function test_non_scalar_values_do_not_explode(): void {
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			WC_Edge_Fingerprint::of( $this->facts( array( 'billing_city' => array( 'x' ) ) ) )
		);
	}

	/**
	 * cart_hash cannot see a renamed product or a changed fee composition:
	 * WooCommerce builds it from a cart session with each product object unset,
	 * and fees are not in the cart rows. Without this field an attempt would be
	 * reused and its demand would keep the old line items.
	 */
	public function test_itemisation_hash_changes_the_digest(): void {
		$facts = $this->facts();

		$before = \WC_Edge_Fingerprint::of( $facts );

		$facts['itemisation_hash'] = 'a-different-basket';

		$this->assertNotSame( $before, \WC_Edge_Fingerprint::of( $facts ) );
	}

	public function test_an_unchanged_itemisation_hash_keeps_the_digest(): void {
		$facts = $this->facts();

		$this->assertSame(
			\WC_Edge_Fingerprint::of( $facts ),
			\WC_Edge_Fingerprint::of( $facts )
		);
	}

	private function refund_facts( array $overrides = array() ): array {
		return array_merge(
			array(
				'demand_id'    => 'demand-9',
				'mode'         => 'live',
				'order_id'     => 17,
				'refund_id'    => 42,
				'amount_cents' => 1000,
			),
			$overrides
		);
	}

	public function test_a_refund_key_is_stable_for_the_same_refund(): void {
		$this->assertSame(
			\WC_Edge_Fingerprint::for_refund( $this->refund_facts() ),
			\WC_Edge_Fingerprint::for_refund( $this->refund_facts() )
		);
	}

	public function test_a_refund_key_is_a_sha256_digest(): void {
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{64}$/',
			\WC_Edge_Fingerprint::for_refund( $this->refund_facts() )
		);
	}

	public function test_a_refund_key_does_not_depend_on_array_order(): void {
		$facts = $this->refund_facts();

		$this->assertSame(
			\WC_Edge_Fingerprint::for_refund( $facts ),
			\WC_Edge_Fingerprint::for_refund( array_reverse( $facts, true ) )
		);
	}

	/**
	 * Two deliberate partial refunds of the same amount are different refunds,
	 * and the WooCommerce refund row is what tells them apart.
	 */
	public function test_a_different_refund_row_rotates_the_key(): void {
		$this->assertNotSame(
			\WC_Edge_Fingerprint::for_refund( $this->refund_facts() ),
			\WC_Edge_Fingerprint::for_refund( $this->refund_facts( array( 'refund_id' => 43 ) ) )
		);
	}

	/**
	 * @dataProvider refund_facts_that_matter
	 */
	public function test_changing_a_refund_fact_rotates_the_key( string $field, $value ): void {
		$this->assertNotSame(
			\WC_Edge_Fingerprint::for_refund( $this->refund_facts() ),
			\WC_Edge_Fingerprint::for_refund( $this->refund_facts( array( $field => $value ) ) )
		);
	}

	public static function refund_facts_that_matter(): array {
		return array(
			'payment demand' => array( 'demand_id', 'demand-8' ),
			'mode'           => array( 'mode', 'sandbox' ),
			'order'          => array( 'order_id', 18 ),
			'amount'         => array( 'amount_cents', 1500 ),
		);
	}

	/**
	 * The note is deliberately not in the key: normalise() lowercases ordinary
	 * strings while Edge compares reason_note byte-exactly, so including it
	 * would let two notes differing only in case collide as a 422.
	 */
	public function test_a_refund_note_is_not_part_of_the_key(): void {
		$this->assertSame(
			\WC_Edge_Fingerprint::for_refund( $this->refund_facts() ),
			\WC_Edge_Fingerprint::for_refund( $this->refund_facts( array( 'reason_note' => 'anything at all' ) ) )
		);
	}

	public function test_a_refund_key_is_not_a_payment_key(): void {
		$this->assertNotSame(
			\WC_Edge_Fingerprint::of( $this->refund_facts() ),
			\WC_Edge_Fingerprint::for_refund( $this->refund_facts() )
		);
	}

	public function test_a_missing_refund_fact_is_the_same_as_an_empty_one(): void {
		$facts = $this->refund_facts();
		unset( $facts['mode'] );

		$this->assertSame(
			\WC_Edge_Fingerprint::for_refund( $facts ),
			\WC_Edge_Fingerprint::for_refund( $this->refund_facts( array( 'mode' => '' ) ) )
		);
	}
}
