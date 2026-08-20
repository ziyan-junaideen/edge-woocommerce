<?php
/**
 * @package WooCommerce Edge Payments Gateway
 */

namespace EdgePayments\EdgeWoocommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC_Edge_Order_Mapper;
use WP_Error;

/**
 * @covers \WC_Edge_Order_Mapper
 */
class OrderMapperTest extends TestCase {

	private function address(): array {
		return array(
			'address_1' => '1 Test Street',
			'address_2' => '',
			'city'      => 'Portland',
			'state'     => 'OR',
			'postcode'  => '97205',
			'country'   => 'US',
		);
	}

	public function test_customer_document_shape(): void {
		$doc = WC_Edge_Order_Mapper::customer_document( 'Ada Lovelace', 'ada@example.test' );

		$this->assertSame( 'customers', $doc['data']['type'] );
		$this->assertSame( 'Ada Lovelace', $doc['data']['attributes']['name'] );
		$this->assertSame( 'ada@example.test', $doc['data']['attributes']['email'] );
		$this->assertArrayNotHasKey( 'phone_number', $doc['data']['attributes'] );
	}

	public function test_customer_document_includes_phone_when_given(): void {
		$doc = WC_Edge_Order_Mapper::customer_document( 'Ada', 'ada@example.test', '+15035550100' );
		$this->assertSame( '+15035550100', $doc['data']['attributes']['phone_number'] );
	}

	public function test_address_document_shape(): void {
		$doc = WC_Edge_Order_Mapper::address_document( $this->address(), 'cust-1' );

		$this->assertSame( 'consumer_addresses', $doc['data']['type'] );
		$this->assertSame( '1 Test Street', $doc['data']['attributes']['line_1'] );
		$this->assertSame( '97205', $doc['data']['attributes']['zip'] );

		// Edge wants alpha-3; WooCommerce stores alpha-2.
		$this->assertSame( 'USA', $doc['data']['attributes']['country'] );

		// line_2 is the one optional field; omit rather than send empty.
		$this->assertArrayNotHasKey( 'line_2', $doc['data']['attributes'] );

		$this->assertSame(
			array( 'data' => array( 'type' => 'customers', 'id' => 'cust-1' ) ),
			$doc['data']['relationships']['customer']
		);
	}

	public function test_address_document_includes_line_2_when_present(): void {
		$address              = $this->address();
		$address['address_2'] = 'Apt 4';

		$doc = WC_Edge_Order_Mapper::address_document( $address, 'cust-1' );
		$this->assertSame( 'Apt 4', $doc['data']['attributes']['line_2'] );
	}

	/**
	 * @dataProvider provide_missing_required_fields
	 */
	public function test_address_document_rejects_incomplete_addresses( string $field ): void {
		$address           = $this->address();
		$address[ $field ] = '';

		$result = WC_Edge_Order_Mapper::address_document( $address, 'cust-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'edge_address_incomplete', $result->get_error_code() );
	}

	public function provide_missing_required_fields(): array {
		return array(
			array( 'address_1' ),
			array( 'city' ),
			array( 'state' ),
			array( 'postcode' ),
		);
	}

	/**
	 * An unknown country must become a checkout validation message, not an
	 * uncaught exception out of the SDK helper.
	 */
	public function test_unknown_country_becomes_an_error(): void {
		$address            = $this->address();
		$address['country'] = 'ZZ';

		$result = WC_Edge_Order_Mapper::address_document( $address, 'cust-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'edge_country_unsupported', $result->get_error_code() );
	}

	public function test_missing_country_becomes_an_error(): void {
		$address            = $this->address();
		$address['country'] = '';

		$result = WC_Edge_Order_Mapper::address_document( $address, 'cust-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'edge_country_missing', $result->get_error_code() );
	}

	/**
	 * @dataProvider provide_country_conversions
	 */
	public function test_country_conversion( string $input, string $expected ): void {
		$this->assertSame( $expected, WC_Edge_Order_Mapper::to_alpha3( $input ) );
	}

	public function provide_country_conversions(): array {
		return array(
			'US'              => array( 'US', 'USA' ),
			'GB'              => array( 'GB', 'GBR' ),
			'CA'              => array( 'CA', 'CAN' ),
			'lowercase'       => array( 'us', 'USA' ),
			'already alpha-3' => array( 'USA', 'USA' ),
		);
	}

	public function test_demand_document_shape(): void {
		$doc = WC_Edge_Order_Mapper::demand_document(
			array(
				'amount_cents'        => 2500,
				'currency'            => 'usd',
				'description'         => 'Order 123',
				'reference'           => 'wc-123',
				'idempotency_key'     => 'attempt-abc',
				'customer_id'         => 'cust-1',
				'billing_address_id'  => 'addr-1',
			)
		);

		$attributes = $doc['data']['attributes'];

		$this->assertSame( 'payment_demands', $doc['data']['type'] );
		$this->assertFalse( $attributes['confirmed'], 'must create an intent, never a confirmed demand' );
		$this->assertSame( 2500, $attributes['amount_cents'] );
		$this->assertSame( 'USD', $attributes['amount_currency'] );
		$this->assertSame( 'order', $attributes['purchase_kind'] );
		$this->assertSame( 'wc-123', $attributes['purchase_reference'] );
		$this->assertSame( 'attempt-abc', $attributes['idempotency_key'] );

		// The iframe supplies this from the shopper's browser during
		// verification; sending the site timezone would overwrite a real value.
		$this->assertArrayNotHasKey( 'payer_timezone', $attributes );

		// The API back-fills these from payer and billing_address.
		$this->assertSame( array( 'payer', 'billing_address' ), array_keys( $doc['data']['relationships'] ) );

		// No cart was passed, so nothing itemised may appear.
		$this->assertArrayNotHasKey( 'line_items', $attributes );
	}

	/**
	 * The identifier type was literally "string" in the previous version, which
	 * was never valid JSON:API.
	 */
	public function test_relationships_carry_real_resource_types(): void {
		$doc = WC_Edge_Order_Mapper::demand_document(
			array(
				'amount_cents'       => 2500,
				'currency'           => 'USD',
				'reference'          => 'wc-1',
				'idempotency_key'    => 'k',
				'customer_id'        => 'cust-1',
				'billing_address_id' => 'addr-1',
			)
		);

		$this->assertSame( 'customers', $doc['data']['relationships']['payer']['data']['type'] );
		$this->assertSame( 'consumer_addresses', $doc['data']['relationships']['billing_address']['data']['type'] );
	}

	public function test_distinct_shipping_address_is_sent(): void {
		$doc = WC_Edge_Order_Mapper::demand_document(
			array(
				'amount_cents'        => 2500,
				'currency'            => 'USD',
				'reference'           => 'wc-1',
				'idempotency_key'     => 'k',
				'customer_id'         => 'cust-1',
				'billing_address_id'  => 'addr-1',
				'shipping_address_id' => 'addr-2',
			)
		);

		$this->assertSame( 'addr-2', $doc['data']['relationships']['shipping_address']['data']['id'] );
	}

	/**
	 * When shipping equals billing, let the API default it rather than sending
	 * the same id twice.
	 */
	public function test_identical_shipping_address_is_omitted(): void {
		$doc = WC_Edge_Order_Mapper::demand_document(
			array(
				'amount_cents'        => 2500,
				'currency'            => 'USD',
				'reference'           => 'wc-1',
				'idempotency_key'     => 'k',
				'customer_id'         => 'cust-1',
				'billing_address_id'  => 'addr-1',
				'shipping_address_id' => 'addr-1',
			)
		);

		$this->assertArrayNotHasKey( 'shipping_address', $doc['data']['relationships'] );
	}

	/**
	 * A complete cart, as WC_Edge_Cart_Items::collect() would return it.
	 *
	 * @param array $overrides Values to replace.
	 * @return array
	 */
	private function cart( array $overrides = array() ): array {
		return array_merge(
			array(
				'complete'       => true,
				'reason'         => '',
				'lines'          => array( $this->line() ),
				'shipping_cents' => 0,
				'tax_cents'      => 0,
				'discount_cents' => 0,
				'hash'           => 'abc123',
			),
			$overrides
		);
	}

	/**
	 * One normalised cart line.
	 *
	 * @param array $overrides Values to replace.
	 * @return array
	 */
	private function line( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'           => 'Edge Test Product',
				'description'    => '',
				'sku'            => '',
				'quantity'       => 2,
				'subtotal_cents' => 200,
				'total_cents'    => 200,
			),
			$overrides
		);
	}

	/**
	 * Build a demand document around a cart.
	 *
	 * @param array  $cart     Collected cart.
	 * @param string $currency Currency code.
	 * @return array The demand attributes.
	 */
	private function attributes( array $cart, string $currency = 'USD' ): array {
		$doc = WC_Edge_Order_Mapper::demand_document(
			array(
				'amount_cents'       => 200,
				'currency'           => $currency,
				'reference'          => 'wc-1',
				'idempotency_key'    => 'k',
				'customer_id'        => 'cust-1',
				'billing_address_id' => 'addr-1',
				'cart'               => $cart,
			)
		);

		return $doc['data']['attributes'];
	}

	/**
	 * The reported bug: a $1.00 product bought twice arrived as $2.00 at
	 * quantity 1, because Edge reads amount_cents as the price of one unit and
	 * the plugin was sending nothing at all.
	 */
	public function test_amount_cents_is_per_unit_not_the_line_total(): void {
		$attributes = $this->attributes( $this->cart() );

		$this->assertCount( 1, $attributes['line_items'] );
		$this->assertSame( 100, $attributes['line_items'][0]['amount_cents'] );
		$this->assertSame( 2, $attributes['line_items'][0]['quantity'] );
		$this->assertSame( 'USD', $attributes['line_items'][0]['amount_currency'] );
		$this->assertSame( 'Edge Test Product', $attributes['line_items'][0]['name'] );
	}

	/**
	 * @dataProvider provide_per_unit_amounts
	 */
	public function test_per_unit_rounding_is_half_up( int $subtotal, int $quantity, int $expected ): void {
		$cart = $this->cart(
			array(
				'lines' => array(
					$this->line(
						array(
							'quantity'       => $quantity,
							'subtotal_cents' => $subtotal,
							'total_cents'    => $subtotal,
						)
					),
				),
			)
		);

		$this->assertSame( $expected, $this->attributes( $cart )['line_items'][0]['amount_cents'] );
	}

	public function provide_per_unit_amounts(): array {
		return array(
			'exact'            => array( 200, 2, 100 ),
			'rounds down'      => array( 1000, 3, 333 ),
			'rounds up'        => array( 1001, 3, 334 ),
			'half rounds up'   => array( 5, 2, 3 ),
			'small'            => array( 100, 8, 13 ),
			'below half a cent' => array( 49, 100, 0 ),
			'free'             => array( 0, 4, 0 ),
		);
	}

	/**
	 * Sending a detail object without line items is worse than sending nothing:
	 * Edge's wallet sheet only falls back to an aggregate row when the combined
	 * list is empty, so tax alone would render as the whole basket.
	 */
	public function test_an_incomplete_cart_sends_no_addendum_at_all(): void {
		$attributes = $this->attributes(
			$this->cart(
				array(
					'complete'       => false,
					'reason'         => 'unreadable_product',
					'lines'          => array(),
					'shipping_cents' => 599,
					'tax_cents'      => 165,
					'discount_cents' => 500,
				)
			)
		);

		foreach ( array( 'line_items', 'shipping_detail', 'tax_detail', 'discount_cents' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $attributes, $key . ' must not survive an incomplete cart' );
		}
	}

	/**
	 * Same rule when the cart is complete but has nothing in it.
	 */
	public function test_an_empty_cart_sends_no_addendum_at_all(): void {
		$attributes = $this->attributes(
			$this->cart(
				array(
					'lines'      => array(),
					'tax_cents'  => 165,
				)
			)
		);

		$this->assertArrayNotHasKey( 'line_items', $attributes );
		$this->assertArrayNotHasKey( 'tax_detail', $attributes );
	}

	public function test_line_items_are_omitted_when_no_cart_is_given(): void {
		$doc = WC_Edge_Order_Mapper::demand_document(
			array(
				'amount_cents'       => 200,
				'currency'           => 'USD',
				'reference'          => 'wc-1',
				'idempotency_key'    => 'k',
				'customer_id'        => 'cust-1',
				'billing_address_id' => 'addr-1',
			)
		);

		$this->assertArrayNotHasKey( 'line_items', $doc['data']['attributes'] );
	}

	/**
	 * Line items are an attribute, not a relationship. Adding them must not
	 * disturb the relationships the API back-fills from.
	 */
	public function test_line_items_do_not_become_a_relationship(): void {
		$doc = WC_Edge_Order_Mapper::demand_document(
			array(
				'amount_cents'       => 200,
				'currency'           => 'USD',
				'reference'          => 'wc-1',
				'idempotency_key'    => 'k',
				'customer_id'        => 'cust-1',
				'billing_address_id' => 'addr-1',
				'cart'               => $this->cart(),
			)
		);

		$this->assertArrayHasKey( 'line_items', $doc['data']['attributes'] );
		$this->assertSame( array( 'payer', 'billing_address' ), array_keys( $doc['data']['relationships'] ) );
	}

	public function test_discount_is_the_per_unit_gap_between_subtotal_and_total(): void {
		$cart = $this->cart(
			array(
				'lines' => array(
					$this->line(
						array(
							'quantity'       => 2,
							'subtotal_cents' => 6000,
							'total_cents'    => 5400,
						)
					),
				),
			)
		);

		$item = $this->attributes( $cart )['line_items'][0];

		$this->assertSame( 3000, $item['amount_cents'], 'amount stays the pre-coupon list price' );
		$this->assertSame( 300, $item['discount_cents'] );
		$this->assertSame( 'USD', $item['discount_currency'] );
	}

	public function test_discount_keys_are_absent_without_a_coupon(): void {
		$item = $this->attributes( $this->cart() )['line_items'][0];

		$this->assertArrayNotHasKey( 'discount_cents', $item );
		$this->assertArrayNotHasKey( 'discount_currency', $item );
	}

	/**
	 * A total above the subtotal should never produce a negative discount;
	 * amount_cents has a minimum of 0 and so does discount_cents.
	 */
	public function test_discount_is_never_negative(): void {
		$cart = $this->cart(
			array(
				'lines' => array(
					$this->line(
						array(
							'subtotal_cents' => 200,
							'total_cents'    => 500,
						)
					),
				),
			)
		);

		$item = $this->attributes( $cart )['line_items'][0];

		$this->assertArrayNotHasKey( 'discount_cents', $item );
		$this->assertSame( 100, $item['amount_cents'] );
	}

	/**
	 * All tax lives on tax_detail, so describing it again per line would be the
	 * same money counted twice.
	 */
	public function test_line_items_never_carry_tax(): void {
		$item = $this->attributes( $this->cart( array( 'tax_cents' => 165 ) ) )['line_items'][0];

		$this->assertArrayNotHasKey( 'tax_cents', $item );
		$this->assertArrayNotHasKey( 'tax_currency', $item );
	}

	public function test_shipping_and_tax_details_are_sent_when_non_zero(): void {
		$attributes = $this->attributes(
			$this->cart(
				array(
					'shipping_cents' => 599,
					'tax_cents'      => 165,
					'discount_cents' => 250,
				)
			)
		);

		$this->assertSame(
			array(
				'shipping_cents'    => 599,
				'shipping_currency' => 'USD',
			),
			$attributes['shipping_detail']
		);

		$this->assertSame(
			array(
				'tax_cents'    => 165,
				'tax_currency' => 'USD',
			),
			$attributes['tax_detail']
		);

		$this->assertSame( 250, $attributes['discount_cents'] );
	}

	public function test_shipping_and_tax_details_are_omitted_when_zero(): void {
		$attributes = $this->attributes( $this->cart() );

		$this->assertArrayHasKey( 'line_items', $attributes );
		$this->assertArrayNotHasKey( 'shipping_detail', $attributes );
		$this->assertArrayNotHasKey( 'tax_detail', $attributes );
		$this->assertArrayNotHasKey( 'discount_cents', $attributes );
	}

	public function test_negative_demand_discount_is_not_sent(): void {
		$attributes = $this->attributes( $this->cart( array( 'discount_cents' => -500 ) ) );

		$this->assertArrayNotHasKey( 'discount_cents', $attributes );
	}

	public function test_sku_and_description_are_sent_when_present(): void {
		$cart = $this->cart(
			array(
				'lines' => array(
					$this->line(
						array(
							'sku'         => 'EDGE-1',
							'description' => 'Colour: Blue',
						)
					),
				),
			)
		);

		$item = $this->attributes( $cart )['line_items'][0];

		$this->assertSame( 'EDGE-1', $item['sku'] );
		$this->assertSame( 'Colour: Blue', $item['description'] );
	}

	public function test_sku_and_description_are_omitted_when_empty(): void {
		$item = $this->attributes( $this->cart() )['line_items'][0];

		$this->assertArrayNotHasKey( 'sku', $item );
		$this->assertArrayNotHasKey( 'description', $item );
	}

	public function test_long_names_are_truncated(): void {
		$cart = $this->cart(
			array( 'lines' => array( $this->line( array( 'name' => str_repeat( 'a', 500 ) ) ) ) )
		);

		$this->assertSame(
			WC_Edge_Order_Mapper::MAX_TEXT_LENGTH,
			mb_strlen( $this->attributes( $cart )['line_items'][0]['name'], 'UTF-8' )
		);
	}

	public function test_multibyte_names_are_not_split_mid_character(): void {
		$cart = $this->cart(
			array( 'lines' => array( $this->line( array( 'name' => str_repeat( 'é', 200 ) ) ) ) )
		);

		$name = $this->attributes( $cart )['line_items'][0]['name'];

		$this->assertTrue( mb_check_encoding( $name, 'UTF-8' ) );
		$this->assertSame( WC_Edge_Order_Mapper::MAX_TEXT_LENGTH, mb_strlen( $name, 'UTF-8' ) );
	}

	public function test_whitespace_in_names_is_collapsed(): void {
		$cart = $this->cart(
			array( 'lines' => array( $this->line( array( 'name' => "  Big   \n\t Widget  " ) ) ) )
		);

		$this->assertSame( 'Big Widget', $this->attributes( $cart )['line_items'][0]['name'] );
	}

	public function test_currency_is_uppercased_on_every_money_field(): void {
		$cart = $this->cart(
			array(
				'lines'          => array(
					$this->line(
						array(
							'subtotal_cents' => 6000,
							'total_cents'    => 5400,
						)
					),
				),
				'shipping_cents' => 599,
				'tax_cents'      => 165,
			)
		);

		$attributes = $this->attributes( $cart, 'usd' );
		$item       = $attributes['line_items'][0];

		$this->assertSame( 'USD', $item['amount_currency'] );
		$this->assertSame( 'USD', $item['discount_currency'] );
		$this->assertSame( 'USD', $attributes['shipping_detail']['shipping_currency'] );
		$this->assertSame( 'USD', $attributes['tax_detail']['tax_currency'] );
	}

	public function test_zero_priced_items_are_still_sent(): void {
		$cart = $this->cart(
			array(
				'lines' => array(
					$this->line(
						array(
							'quantity'       => 1,
							'subtotal_cents' => 0,
							'total_cents'    => 0,
						)
					),
				),
			)
		);

		$item = $this->attributes( $cart )['line_items'][0];

		$this->assertSame( 0, $item['amount_cents'] );
		$this->assertSame( 1, $item['quantity'] );
	}

	public function test_quantity_below_one_becomes_one(): void {
		$cart = $this->cart(
			array( 'lines' => array( $this->line( array( 'quantity' => 0 ) ) ) )
		);

		$this->assertSame( 1, $this->attributes( $cart )['line_items'][0]['quantity'] );
	}

	/**
	 * A PHP array with a gap in its keys encodes as a JSON object, and the
	 * schema declares an array.
	 */
	public function test_line_items_encode_as_a_json_array(): void {
		$cart = $this->cart(
			array(
				'lines' => array(
					3 => $this->line(),
					7 => $this->line( array( 'name' => 'Second' ) ),
				),
			)
		);

		$encoded = json_encode( $this->attributes( $cart )['line_items'] );

		$this->assertSame( '[', $encoded[0] );
	}
}
