<?php
/**
 * @package WooCommerce Edge Payments Gateway
 */

namespace EdgePayments\EdgeWoocommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC_Edge_Cart_Items;

/**
 * Covers the pure half of the collector.
 *
 * `read()` is the WooCommerce-facing half and is deliberately thin: it pulls
 * values out and puts money into decimal-string form. Everything that can be
 * wrong - conversion, quantities, fee signs, and the decision not to itemise at
 * all - lives in `normalise()`, which is what these exercise.
 *
 * @covers \WC_Edge_Cart_Items
 */
class CartItemsTest extends TestCase {

	/**
	 * A readable product row as read() would emit it.
	 *
	 * @param array $overrides Values to replace.
	 * @return array
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'readable'    => true,
				'name'        => 'Edge Test Product',
				'description' => '',
				'sku'         => '',
				'quantity'    => '2',
				'subtotal'    => '2.00',
				'total'       => '2.00',
			),
			$overrides
		);
	}

	/**
	 * A whole raw cart as read() would emit it.
	 *
	 * @param array $overrides Values to replace.
	 * @return array
	 */
	private function raw( array $overrides = array() ): array {
		return array_merge(
			array(
				'lines'    => array( $this->row() ),
				'fees'     => array(),
				'shipping' => '0.00',
				'tax'      => '0.00',
			),
			$overrides
		);
	}

	/**
	 * The reported case: $1.00 at quantity 2 must survive as a 200 cent line of
	 * two units, not as a single 200 cent unit.
	 */
	public function test_the_reported_cart_is_collected_whole(): void {
		$collected = WC_Edge_Cart_Items::normalise( $this->raw() );

		$this->assertTrue( $collected['complete'] );
		$this->assertSame( '', $collected['reason'] );
		$this->assertCount( 1, $collected['lines'] );
		$this->assertSame( 200, $collected['lines'][0]['subtotal_cents'] );
		$this->assertSame( 2, $collected['lines'][0]['quantity'] );
		$this->assertSame( 'Edge Test Product', $collected['lines'][0]['name'] );
	}

	public function test_pre_and_post_coupon_totals_stay_distinct(): void {
		$collected = WC_Edge_Cart_Items::normalise(
			$this->raw(
				array(
					'lines' => array(
						$this->row(
							array(
								'subtotal' => '60.00',
								'total'    => '54.00',
							)
						),
					),
				)
			)
		);

		$this->assertSame( 6000, $collected['lines'][0]['subtotal_cents'] );
		$this->assertSame( 5400, $collected['lines'][0]['total_cents'] );
	}

	public function test_shipping_and_tax_are_carried_through(): void {
		$collected = WC_Edge_Cart_Items::normalise(
			$this->raw(
				array(
					'shipping' => '5.99',
					'tax'      => '1.65',
				)
			)
		);

		$this->assertSame( 599, $collected['shipping_cents'] );
		$this->assertSame( 165, $collected['tax_cents'] );
	}

	public function test_a_positive_fee_becomes_a_line(): void {
		$collected = WC_Edge_Cart_Items::normalise(
			$this->raw(
				array(
					'fees' => array(
						array(
							'name'  => 'Gift wrap',
							'total' => '5.00',
						),
					),
				)
			)
		);

		$this->assertTrue( $collected['complete'] );
		$this->assertCount( 2, $collected['lines'] );
		$this->assertSame( 'Gift wrap', $collected['lines'][1]['name'] );
		$this->assertSame( 500, $collected['lines'][1]['subtotal_cents'] );
		$this->assertSame( 1, $collected['lines'][1]['quantity'] );
		$this->assertSame( 0, $collected['discount_cents'] );
	}

	/**
	 * A negative fee cannot be a line item - amount_cents has a minimum of 0 -
	 * so it becomes the demand-level reduction it actually is.
	 */
	public function test_a_negative_fee_becomes_a_demand_level_discount(): void {
		$collected = WC_Edge_Cart_Items::normalise(
			$this->raw(
				array(
					'fees' => array(
						array(
							'name'  => 'Loyalty credit',
							'total' => '-7.50',
						),
					),
				)
			)
		);

		$this->assertCount( 1, $collected['lines'], 'a reduction is never a line item' );
		$this->assertSame( 750, $collected['discount_cents'] );
	}

	public function test_a_zero_fee_is_skipped(): void {
		$collected = WC_Edge_Cart_Items::normalise(
			$this->raw(
				array(
					'fees' => array(
						array(
							'name'  => 'Nothing',
							'total' => '0.00',
						),
					),
				)
			)
		);

		$this->assertCount( 1, $collected['lines'] );
		$this->assertSame( 0, $collected['discount_cents'] );
	}

	/**
	 * Any non-empty line_items array suppresses the aggregate row Edge would
	 * otherwise generate, so a basket that is missing a product reads as
	 * authoritative rather than as partial. Refuse the whole thing instead.
	 */
	public function test_an_unreadable_product_refuses_the_whole_itemisation(): void {
		$collected = WC_Edge_Cart_Items::normalise(
			$this->raw(
				array(
					'lines'    => array( $this->row(), array( 'readable' => false ) ),
					'shipping' => '5.99',
					'tax'      => '1.65',
				)
			)
		);

		$this->assertFalse( $collected['complete'] );
		$this->assertSame( 'unreadable_product', $collected['reason'] );
		$this->assertSame( array(), $collected['lines'], 'never a partial basket' );
		$this->assertSame( 0, $collected['shipping_cents'] );
		$this->assertSame( 0, $collected['tax_cents'] );
		$this->assertSame( '', $collected['hash'] );
	}

	/**
	 * @dataProvider provide_unconvertible_amounts
	 */
	public function test_an_unconvertible_amount_refuses_the_whole_itemisation( array $overrides, string $reason ): void {
		$collected = WC_Edge_Cart_Items::normalise(
			$this->raw( array( 'lines' => array( $this->row( $overrides ) ) ) )
		);

		$this->assertFalse( $collected['complete'] );
		$this->assertSame( $reason, $collected['reason'] );
		$this->assertSame( array(), $collected['lines'] );
	}

	public function provide_unconvertible_amounts(): array {
		return array(
			'malformed subtotal' => array( array( 'subtotal' => 'abc' ), 'unconvertible_amount' ),
			'empty subtotal'     => array( array( 'subtotal' => '' ), 'unconvertible_amount' ),
			'null subtotal'      => array( array( 'subtotal' => null ), 'unconvertible_amount' ),
			'malformed total'    => array( array( 'total' => 'n/a' ), 'unconvertible_amount' ),
		);
	}

	public function test_an_unconvertible_fee_refuses_the_whole_itemisation(): void {
		$collected = WC_Edge_Cart_Items::normalise(
			$this->raw( array( 'fees' => array( array( 'name' => 'Odd', 'total' => 'abc' ) ) ) )
		);

		$this->assertFalse( $collected['complete'] );
		$this->assertSame( 'unconvertible_fee', $collected['reason'] );
	}

	public function test_an_unconvertible_total_refuses_the_whole_itemisation(): void {
		$collected = WC_Edge_Cart_Items::normalise( $this->raw( array( 'tax' => 'abc' ) ) );

		$this->assertFalse( $collected['complete'] );
		$this->assertSame( 'unconvertible_total', $collected['reason'] );
	}

	/**
	 * The distinction that matters: zero converts, so a free item is kept. Only
	 * a value that cannot be read at all is a refusal. A malformed amount must
	 * never be quietly coerced to zero.
	 */
	public function test_a_free_item_is_kept(): void {
		$collected = WC_Edge_Cart_Items::normalise(
			$this->raw(
				array(
					'lines' => array(
						$this->row(
							array(
								'quantity' => '1',
								'subtotal' => '0.00',
								'total'    => '0.00',
							)
						),
					),
				)
			)
		);

		$this->assertTrue( $collected['complete'] );
		$this->assertCount( 1, $collected['lines'] );
		$this->assertSame( 0, $collected['lines'][0]['subtotal_cents'] );
	}

	/**
	 * Edge takes an integer quantity of at least one. Rounding 1.5 up to 2 and
	 * halving the line would falsify both the quantity and the unit price, so
	 * the line becomes one unit worth the whole amount and the real quantity is
	 * recorded as text.
	 */
	public function test_a_fractional_quantity_is_recorded_rather_than_rounded(): void {
		$collected = WC_Edge_Cart_Items::normalise(
			$this->raw(
				array(
					'lines' => array(
						$this->row(
							array(
								'quantity' => '1.5',
								'subtotal' => '15.00',
								'total'    => '15.00',
							)
						),
					),
				)
			)
		);

		$line = $collected['lines'][0];

		$this->assertTrue( $collected['complete'] );
		$this->assertSame( 1, $line['quantity'], 'never a fabricated integer quantity' );
		$this->assertSame( 1500, $line['subtotal_cents'], 'the whole line amount stays on one unit' );
		$this->assertStringContainsString( '1.5', $line['description'] );
	}

	public function test_a_fractional_quantity_keeps_the_existing_description(): void {
		$collected = WC_Edge_Cart_Items::normalise(
			$this->raw(
				array(
					'lines' => array(
						$this->row(
							array(
								'quantity'    => '2.25',
								'description' => 'Colour: Blue',
							)
						),
					),
				)
			)
		);

		$description = $collected['lines'][0]['description'];

		$this->assertStringContainsString( 'Colour: Blue', $description );
		$this->assertStringContainsString( '2.25', $description );
	}

	public function test_a_whole_quantity_gets_no_description_note(): void {
		$collected = WC_Edge_Cart_Items::normalise( $this->raw() );

		$this->assertSame( '', $collected['lines'][0]['description'] );
	}

	/**
	 * @dataProvider provide_invalid_quantities
	 */
	public function test_an_unusable_quantity_refuses_the_whole_itemisation( $quantity ): void {
		$collected = WC_Edge_Cart_Items::normalise(
			$this->raw( array( 'lines' => array( $this->row( array( 'quantity' => $quantity ) ) ) ) )
		);

		$this->assertFalse( $collected['complete'] );
		$this->assertSame( 'invalid_quantity', $collected['reason'] );
	}

	public function provide_invalid_quantities(): array {
		return array(
			'zero'        => array( '0' ),
			'negative'    => array( '-2' ),
			'empty'       => array( '' ),
			'not numeric' => array( 'two' ),
		);
	}

	public function test_the_hash_is_stable_across_identical_carts(): void {
		$this->assertSame(
			WC_Edge_Cart_Items::normalise( $this->raw() )['hash'],
			WC_Edge_Cart_Items::normalise( $this->raw() )['hash']
		);
	}

	/**
	 * A re-ordered cart is the same basket. Hashing it differently would mint a
	 * second demand for no reason.
	 */
	public function test_the_hash_ignores_line_order(): void {
		$first  = $this->row( array( 'name' => 'Alpha' ) );
		$second = $this->row( array( 'name' => 'Beta' ) );

		$this->assertSame(
			WC_Edge_Cart_Items::normalise( $this->raw( array( 'lines' => array( $first, $second ) ) ) )['hash'],
			WC_Edge_Cart_Items::normalise( $this->raw( array( 'lines' => array( $second, $first ) ) ) )['hash']
		);
	}

	/**
	 * These are exactly the changes WC_Cart::get_cart_hash() cannot see: it is
	 * built from a cart session that has each product object unset, and fees are
	 * not in the cart rows at all.
	 *
	 * @dataProvider provide_itemisation_changes
	 */
	public function test_the_hash_changes_when_the_itemisation_does( array $raw ): void {
		$this->assertNotSame(
			WC_Edge_Cart_Items::normalise( $this->raw() )['hash'],
			WC_Edge_Cart_Items::normalise( $raw )['hash']
		);
	}

	public function provide_itemisation_changes(): array {
		return array(
			'renamed product' => array(
				array(
					'lines'    => array( $this->row( array( 'name' => 'Renamed' ) ) ),
					'fees'     => array(),
					'shipping' => '0.00',
					'tax'      => '0.00',
				),
			),
			'edited sku'      => array(
				array(
					'lines'    => array( $this->row( array( 'sku' => 'NEW-1' ) ) ),
					'fees'     => array(),
					'shipping' => '0.00',
					'tax'      => '0.00',
				),
			),
			'different fee at the same total' => array(
				array(
					'lines'    => array( $this->row() ),
					'fees'     => array( array( 'name' => 'Rush', 'total' => '5.00' ) ),
					'shipping' => '0.00',
					'tax'      => '0.00',
				),
			),
			'different shipping' => array(
				array(
					'lines'    => array( $this->row() ),
					'fees'     => array(),
					'shipping' => '5.99',
					'tax'      => '0.00',
				),
			),
		);
	}

	public function test_an_empty_cart_is_complete_and_carries_nothing(): void {
		$collected = WC_Edge_Cart_Items::normalise( $this->raw( array( 'lines' => array() ) ) );

		$this->assertTrue( $collected['complete'] );
		$this->assertSame( array(), $collected['lines'] );
	}
}
