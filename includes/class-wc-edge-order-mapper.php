<?php
/**
 * Builds the JSON:API documents Edge expects.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WC_EDGE_TESTING' ) ) {
	exit;
}

/**
 * Turns WooCommerce values into Edge v2 request documents.
 *
 * Pure: every method takes plain values and returns an array. Nothing here
 * touches WooCommerce or the network, so the exact bytes sent to Edge can be
 * asserted in a unit test.
 */
final class WC_Edge_Order_Mapper {

	/**
	 * Longest name or description sent for a line item.
	 *
	 * Plugin policy, not an Edge constraint: `line_items` is a jsonb column with
	 * no length validation. A product title is free text and can run to a
	 * paragraph, and there is no reason to put one in a payment request.
	 *
	 * @var int
	 */
	const MAX_TEXT_LENGTH = 120;

	/**
	 * Longest SKU sent for a line item. Also plugin policy.
	 *
	 * @var int
	 */
	const MAX_SKU_LENGTH = 64;

	/**
	 * Longest refund note Edge accepts.
	 *
	 * An Edge constraint this time, not plugin policy:
	 * `validate_length(:reason_note, max: 500)` on the backend changeset.
	 *
	 * @var int
	 */
	const MAX_REFUND_NOTE_LENGTH = 500;

	/**
	 * Build a `customers` creation document.
	 *
	 * @param string $name  Customer name.
	 * @param string $email Customer email.
	 * @param string $phone Optional phone number.
	 * @return array
	 */
	public static function customer_document( $name, $email, $phone = '' ) {
		$attributes = array(
			'name'  => self::clean( $name ),
			'email' => self::clean( $email ),
		);

		$phone = self::clean( $phone );

		if ( '' !== $phone ) {
			$attributes['phone_number'] = $phone;
		}

		return array(
			'data' => array(
				'type'       => 'customers',
				'attributes' => $attributes,
			),
		);
	}

	/**
	 * Build a `consumer_addresses` creation document.
	 *
	 * @param array  $address     WooCommerce address parts.
	 * @param string $customer_id Edge customer id to attach to.
	 * @return array|WP_Error
	 */
	public static function address_document( array $address, $customer_id ) {
		$country = self::to_alpha3( isset( $address['country'] ) ? $address['country'] : '' );

		if ( is_wp_error( $country ) ) {
			return $country;
		}

		$attributes = array(
			'line_1'  => self::clean( isset( $address['address_1'] ) ? $address['address_1'] : '' ),
			'city'    => self::clean( isset( $address['city'] ) ? $address['city'] : '' ),
			'state'   => self::clean( isset( $address['state'] ) ? $address['state'] : '' ),
			'zip'     => self::clean( isset( $address['postcode'] ) ? $address['postcode'] : '' ),
			'country' => $country,
		);

		$line_2 = self::clean( isset( $address['address_2'] ) ? $address['address_2'] : '' );

		// The backend requires everything except line_2, so send it only when set
		// rather than as an empty string.
		if ( '' !== $line_2 ) {
			$attributes['line_2'] = $line_2;
		}

		foreach ( array( 'line_1', 'city', 'state', 'zip' ) as $required ) {
			if ( '' === $attributes[ $required ] ) {
				return new WP_Error(
					'edge_address_incomplete',
					__( 'Please complete your billing address before paying.', 'edge-gateway' ),
					array( 'field' => $required )
				);
			}
		}

		return array(
			'data' => array(
				'type'          => 'consumer_addresses',
				'attributes'    => $attributes,
				'relationships' => array(
					'customer' => self::identifier( 'customers', $customer_id ),
				),
			),
		);
	}

	/**
	 * Build an unconfirmed `payment_demands` creation document.
	 *
	 * Only `payer` and `billing_address` are sent. The API fills in `receiver`,
	 * `buyer` and `shipping_address` from those - confirmed against the running
	 * backend - so sending them again would only add ways to disagree.
	 *
	 * `payer_timezone` is deliberately absent: it is not required at create, and
	 * the hosted iframe sets it from the shopper's own browser during
	 * verification. Sending the site's timezone would overwrite a real value
	 * with a meaningless one.
	 *
	 * @param array $args amount_cents, currency, description, reference,
	 *                    idempotency_key, customer_id, billing_address_id, and
	 *                    optionally shipping_address_id and cart.
	 * @return array
	 */
	public static function demand_document( array $args ) {
		$attributes = array(
			'confirmed'          => false,
			'amount_cents'       => (int) $args['amount_cents'],
			'amount_currency'    => strtoupper( (string) $args['currency'] ),
			'purchase_kind'      => 'order',
			'purchase_reference' => (string) $args['reference'],
			'idempotency_key'    => (string) $args['idempotency_key'],
		);

		if ( ! empty( $args['description'] ) ) {
			$attributes['description'] = self::clean( $args['description'] );
		}

		self::add_addendum(
			$attributes,
			isset( $args['cart'] ) ? (array) $args['cart'] : array()
		);

		$relationships = array(
			'payer'           => self::identifier( 'customers', $args['customer_id'] ),
			'billing_address' => self::identifier( 'consumer_addresses', $args['billing_address_id'] ),
		);

		if ( ! empty( $args['shipping_address_id'] )
			&& $args['shipping_address_id'] !== $args['billing_address_id'] ) {
			$relationships['shipping_address'] = self::identifier(
				'consumer_addresses',
				$args['shipping_address_id']
			);
		}

		return array(
			'data' => array(
				'type'          => 'payment_demands',
				'attributes'    => $attributes,
				'relationships' => $relationships,
			),
		);
	}

	/**
	 * Build a `refund_demands` creation document.
	 *
	 * There is no confirm step for a refund: creating one starts it. `reason` is
	 * required by the backend and is always `custom` here, because WooCommerce's
	 * refund reason is free text and cannot be mapped onto Edge's enum without
	 * guessing; the text itself travels in `reason_note`.
	 *
	 * `amount_currency` is deliberately absent. The backend inherits it from the
	 * payment demand and rejects a value that disagrees, so sending it can only
	 * ever hurt.
	 *
	 * `amount_cents` is always sent. Omitting it means "refund the whole
	 * remaining balance", which is the worst possible default for a call that
	 * reached here with a malformed amount.
	 *
	 * @param array $args demand_id, amount_cents, idempotency_key and optionally
	 *                    reason_note.
	 * @return array
	 */
	public static function refund_document( array $args ) {
		$attributes = array(
			'reason'          => 'custom',
			'amount_cents'    => (int) $args['amount_cents'],
			'idempotency_key' => (string) $args['idempotency_key'],
		);

		$note = isset( $args['reason_note'] )
			? self::text( $args['reason_note'], self::MAX_REFUND_NOTE_LENGTH )
			: '';

		// Sent as an absent key rather than an empty string: the backend's
		// idempotent replay compares `reason_note` exactly, and "" and null are
		// not the same value there.
		if ( '' !== $note ) {
			$attributes['reason_note'] = $note;
		}

		return array(
			'data' => array(
				'type'          => 'refund_demands',
				'attributes'    => $attributes,
				'relationships' => array(
					'payment_demand' => self::identifier( 'payment_demands', $args['demand_id'] ),
				),
			),
		);
	}

	/**
	 * Attach the itemisation to a demand's attributes, or attach none of it.
	 *
	 * All four keys move together. Edge's wallet sheet builds its rows from
	 * `line_items`, appends Tax and Shipping from the detail objects, and only
	 * falls back to a single aggregate row when the combined list is empty
	 * (`Core.RemoteClient.Evervault.line_items/1`). So a `tax_detail` sent
	 * without line items does not degrade to the aggregate - it produces a sheet
	 * showing tax and nothing else. Likewise, any non-empty `line_items`
	 * suppresses the aggregate row the backend would otherwise generate, so a
	 * basket missing a product reads as authoritative rather than as partial.
	 *
	 * When the cart could not be represented in full, sending nothing is the
	 * honest outcome: the shopper sees one aggregate line, which is true.
	 *
	 * @param array $attributes Demand attributes, modified in place.
	 * @param array $cart       Output of WC_Edge_Cart_Items::collect().
	 * @return void
	 */
	private static function add_addendum( array &$attributes, array $cart ) {
		if ( empty( $cart['complete'] ) || empty( $cart['lines'] ) ) {
			return;
		}

		$currency = $attributes['amount_currency'];

		// array_values() so wp_json_encode() renders a JSON array. A PHP array
		// with a gap in its keys encodes as an object, which is not what the
		// schema declares.
		$attributes['line_items'] = array_values( self::line_items( (array) $cart['lines'], $currency ) );

		if ( ! empty( $cart['shipping_cents'] ) && 0 < (int) $cart['shipping_cents'] ) {
			$attributes['shipping_detail'] = array(
				'shipping_cents'    => (int) $cart['shipping_cents'],
				'shipping_currency' => $currency,
			);
		}

		if ( ! empty( $cart['tax_cents'] ) && 0 < (int) $cart['tax_cents'] ) {
			$attributes['tax_detail'] = array(
				'tax_cents'    => (int) $cart['tax_cents'],
				'tax_currency' => $currency,
			);
		}

		// Where a negative WooCommerce fee lands. Coupon discounts are not here:
		// they are already on the lines they apply to, and counting them in both
		// places would misstate the order either way.
		if ( ! empty( $cart['discount_cents'] ) && 0 < (int) $cart['discount_cents'] ) {
			$attributes['discount_cents'] = (int) $cart['discount_cents'];
		}
	}

	/**
	 * Map normalised cart lines to Edge line items.
	 *
	 * @param array  $lines    Lines from WC_Edge_Cart_Items::collect().
	 * @param string $currency Uppercase ISO 4217 code.
	 * @return array
	 */
	public static function line_items( array $lines, $currency ) {
		$currency = strtoupper( (string) $currency );
		$items    = array();

		foreach ( $lines as $line ) {
			$items[] = self::line_item( (array) $line, $currency );
		}

		return $items;
	}

	/**
	 * One line item, with per-unit money.
	 *
	 * Edge's `amount_cents` is the price of a *single unit*, not the line total:
	 * the backend derives the line as `amount_cents * quantity`. WooCommerce
	 * only stores whole-line figures, so each is divided back down. Getting this
	 * wrong is the bug this whole change exists to fix - a $1 product bought
	 * twice was arriving as $2 at quantity 1.
	 *
	 * `amount_cents` is the price *before* any coupon, with the reduction
	 * carried separately in `discount_cents`. That is the Level 3 convention and
	 * what `Core.Invoiced` sends.
	 *
	 * Tax is deliberately absent. All of it is on the demand's `tax_detail`, so
	 * the same money is described once.
	 *
	 * @param array  $line     One normalised line.
	 * @param string $currency Uppercase ISO 4217 code.
	 * @return array
	 */
	private static function line_item( array $line, $currency ) {
		$quantity = isset( $line['quantity'] ) ? max( 1, (int) $line['quantity'] ) : 1;
		$subtotal = isset( $line['subtotal_cents'] ) ? max( 0, (int) $line['subtotal_cents'] ) : 0;
		$total    = isset( $line['total_cents'] ) ? max( 0, (int) $line['total_cents'] ) : 0;

		// A free item is a real line: amount_cents may be 0, quantity may not.
		$item = array(
			'amount_cents'    => self::per_unit( $subtotal, $quantity ),
			'amount_currency' => $currency,
			'quantity'        => $quantity,
		);

		$name = self::text( isset( $line['name'] ) ? $line['name'] : '', self::MAX_TEXT_LENGTH );

		if ( '' !== $name ) {
			$item['name'] = $name;
		}

		$description = self::text(
			isset( $line['description'] ) ? $line['description'] : '',
			self::MAX_TEXT_LENGTH
		);

		if ( '' !== $description ) {
			$item['description'] = $description;
		}

		// Not every product has a SKU, and an empty string is not one.
		$sku = self::text( isset( $line['sku'] ) ? $line['sku'] : '', self::MAX_SKU_LENGTH );

		if ( '' !== $sku ) {
			$item['sku'] = $sku;
		}

		$discount = self::per_unit( max( 0, $subtotal - $total ), $quantity );

		if ( 0 < $discount ) {
			$item['discount_cents']    = $discount;
			$item['discount_currency'] = $currency;
		}

		return $item;
	}

	/**
	 * Divide a whole-line amount into a per-unit one, rounding half up.
	 *
	 * `intdiv( 2n + q, 2q )` is `floor( n/q + 1/2 )` written without division:
	 * doubling makes the half exact, and the `+ q` before the divide is the
	 * `+ 1/2`. Doing it in floats would be the mistake WC_Edge_Money exists to
	 * avoid. It is only correct for a non-negative numerator - intdiv truncates
	 * toward zero rather than flooring - which is also the minimum Edge allows,
	 * so the clamp does both jobs.
	 *
	 * Per-unit rounding cannot always reproduce the line exactly: 1000 cents
	 * over 3 units is 333 each, and 333 * 3 is 999. That is fine here. Edge
	 * treats line items as informational and never sums them; `amount_cents` on
	 * the demand is sent separately and is what is charged.
	 *
	 * @param int $cents    Whole-line amount.
	 * @param int $quantity Units.
	 * @return int
	 */
	private static function per_unit( $cents, $quantity ) {
		$cents    = max( 0, (int) $cents );
		$quantity = max( 1, (int) $quantity );

		return intdiv( ( 2 * $cents ) + $quantity, 2 * $quantity );
	}

	/**
	 * Clean a value and cap its length.
	 *
	 * Cuts on characters rather than bytes where it can, so a multibyte title is
	 * never split mid-character. The plugin declares `Requires PHP: 7.4` without
	 * requiring ext-mbstring, so the byte fallback has to exist.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Maximum length.
	 * @return string
	 */
	private static function text( $value, $limit ) {
		$value = self::clean( $value );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $limit, 'UTF-8' );
		}

		return substr( $value, 0, $limit );
	}

	/**
	 * Build a JSON:API resource identifier object.
	 *
	 * The previous implementation sent `"type": "string"` here, which was never
	 * valid JSON:API.
	 *
	 * @param string $type Resource type.
	 * @param string $id   Resource id.
	 * @return array
	 */
	public static function identifier( $type, $id ) {
		return array(
			'data' => array(
				'type' => $type,
				'id'   => (string) $id,
			),
		);
	}

	/**
	 * Convert an ISO 3166-1 alpha-2 country to the alpha-3 Edge requires.
	 *
	 * @param string $alpha2 Two letter country code.
	 * @return string|WP_Error
	 */
	public static function to_alpha3( $alpha2 ) {
		$alpha2 = strtoupper( self::clean( $alpha2 ) );

		if ( '' === $alpha2 ) {
			return new WP_Error(
				'edge_country_missing',
				__( 'Please choose a billing country before paying.', 'edge-gateway' )
			);
		}

		// Already alpha-3.
		if ( 3 === strlen( $alpha2 ) ) {
			return $alpha2;
		}

		$alpha3 = WC_Edge_Countries::to_alpha3( $alpha2 );

		if ( '' === $alpha3 ) {
			// An unknown country must surface as a checkout validation message.
			return new WP_Error(
				'edge_country_unsupported',
				__( 'That billing country is not supported for card payments.', 'edge-gateway' ),
				array( 'country' => $alpha2 )
			);
		}

		return $alpha3;
	}

	/**
	 * Trim and collapse whitespace.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
	}
}
