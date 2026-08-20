<?php
/**
 * Reads the cart into the plain values a payment line item needs.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WC_EDGE_TESTING' ) ) {
	exit;
}

/**
 * Flattens the cart into whole-line integer cents.
 *
 * Split in two on purpose. `read()` is the only method that touches
 * WooCommerce, and it does nothing but pull values out and put money into
 * decimal-string form. `normalise()` holds every decision worth being wrong
 * about - conversion, quantities, fee signs, completeness - and is pure, so the
 * suite can exercise it without WordPress.
 *
 * Nothing here does per-unit arithmetic or knows what Edge's document looks
 * like; WC_Edge_Order_Mapper does both.
 */
final class WC_Edge_Cart_Items {

	/**
	 * Read and normalise the current cart.
	 *
	 * @param WC_Cart $cart Cart with its totals already calculated.
	 * @return array See normalise() for the shape.
	 */
	public static function collect( $cart ) {
		return self::normalise( self::read( $cart ) );
	}

	/**
	 * Pull the cart apart into plain values.
	 *
	 * Every monetary value leaves here as a decimal string.
	 * `WC_Edge_Money::to_cents()` rejects floats on purpose, and WooCommerce is
	 * not consistent about which it hands back: `line_subtotal` and `line_tax`
	 * on a cart item are floats from `wc_remove_number_precision()`,
	 * `get_shipping_total()` is a decimal string from `wc_format_decimal()`,
	 * and `get_total_tax()` is a float again. One conversion, applied to
	 * everything, rather than a per-accessor assumption.
	 *
	 * @param WC_Cart $cart Cart with its totals already calculated.
	 * @return array
	 */
	private static function read( $cart ) {
		$raw = array(
			'lines'    => array(),
			'fees'     => array(),
			'shipping' => self::decimal( $cart->get_shipping_total() ),

			// Contents tax plus shipping tax plus fee tax, which is why no
			// per-line or per-fee tax is read below.
			'tax'      => self::decimal( $cart->get_total_tax() ),
		);

		foreach ( $cart->get_cart() as $item ) {
			$item = (array) $item;

			// A product deleted mid-session leaves a row whose `data` is false.
			// That is a hole in the basket, not an empty line - normalise()
			// turns it into a refusal to itemise at all.
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				$raw['lines'][] = array( 'readable' => false );

				continue;
			}

			$product = $item['data'];

			$raw['lines'][] = array(
				'readable'    => true,
				'name'        => self::plain( $product->get_name() ),
				'description' => self::variation( $product ),
				'sku'         => self::plain( $product->get_sku() ),
				'quantity'    => isset( $item['quantity'] ) ? (string) $item['quantity'] : '',

				// Both are tax-exclusive whatever the store's
				// prices-include-tax setting says: WC_Cart_Totals subtracts the
				// tax back out when price_includes_tax is on and parks it in
				// line_subtotal_tax / line_tax. So there is no branch for it.
				'subtotal'    => isset( $item['line_subtotal'] ) ? self::decimal( $item['line_subtotal'] ) : null,
				'total'       => isset( $item['line_total'] ) ? self::decimal( $item['line_total'] ) : null,
			);
		}

		foreach ( (array) $cart->get_fees() as $fee ) {
			$raw['fees'][] = array(
				'name'  => self::plain( isset( $fee->name ) ? $fee->name : '' ),
				'total' => self::decimal( isset( $fee->total ) ? $fee->total : 0 ),
			);
		}

		return $raw;
	}

	/**
	 * Turn raw cart values into whole-line cents, or refuse to.
	 *
	 * Returns `complete => false` rather than a best effort. Any non-empty
	 * `line_items` array suppresses the aggregate row Edge would otherwise
	 * generate, so a basket missing one product does not read as incomplete at
	 * the far end - it reads as authoritative and wrong. Better to send no
	 * itemisation and let the aggregate stand.
	 *
	 * @param array $raw Output of read().
	 * @return array{complete:bool,reason:string,lines:array,shipping_cents:int,
	 *               tax_cents:int,discount_cents:int,hash:string}
	 */
	public static function normalise( array $raw ) {
		$lines    = array();
		$discount = 0;

		foreach ( (array) ( isset( $raw['lines'] ) ? $raw['lines'] : array() ) as $row ) {
			$row = (array) $row;

			if ( empty( $row['readable'] ) ) {
				return self::incomplete( 'unreadable_product' );
			}

			$line = self::line( $row );

			if ( is_string( $line ) ) {
				return self::incomplete( $line );
			}

			$lines[] = $line;
		}

		foreach ( (array) ( isset( $raw['fees'] ) ? $raw['fees'] : array() ) as $fee ) {
			$fee   = (array) $fee;
			$cents = self::cents( isset( $fee['total'] ) ? $fee['total'] : null );

			if ( null === $cents ) {
				return self::incomplete( 'unconvertible_fee' );
			}

			if ( 0 === $cents ) {
				continue;
			}

			// A negative fee is a surcharge being used as a reduction. It
			// cannot be a line item - amount_cents has a minimum of 0 - and the
			// demand-level discount_cents is what it actually means.
			if ( 0 > $cents ) {
				$discount += -$cents;

				continue;
			}

			$lines[] = array(
				'name'           => isset( $fee['name'] ) ? (string) $fee['name'] : '',
				'description'    => '',
				'sku'            => '',
				'quantity'       => 1,
				'subtotal_cents' => $cents,
				'total_cents'    => $cents,
			);
		}

		$shipping = self::cents( isset( $raw['shipping'] ) ? $raw['shipping'] : null );
		$tax      = self::cents( isset( $raw['tax'] ) ? $raw['tax'] : null );

		if ( null === $shipping || null === $tax ) {
			return self::incomplete( 'unconvertible_total' );
		}

		$collected = array(
			'complete'       => true,
			'reason'         => '',
			'lines'          => $lines,
			'shipping_cents' => max( 0, $shipping ),
			'tax_cents'      => max( 0, $tax ),
			'discount_cents' => $discount,
		);

		$collected['hash'] = self::hash( $collected );

		return $collected;
	}

	/**
	 * One product line, or the reason it cannot be represented.
	 *
	 * @param array $row One entry from read()['lines'].
	 * @return array|string The line, or a reason string on failure.
	 */
	private static function line( array $row ) {
		$subtotal = self::cents( isset( $row['subtotal'] ) ? $row['subtotal'] : null );
		$total    = self::cents( isset( $row['total'] ) ? $row['total'] : null );

		// Zero is a real price - a free item is representable, and amount_cents
		// has a minimum of 0, not 1. Only an unconvertible value is a failure,
		// which is why this is a null check and not a falsy one.
		if ( null === $subtotal || null === $total ) {
			return 'unconvertible_amount';
		}

		$description = isset( $row['description'] ) ? (string) $row['description'] : '';
		$quantity    = self::quantity( isset( $row['quantity'] ) ? $row['quantity'] : '' );

		if ( null === $quantity ) {
			return 'invalid_quantity';
		}

		// A fractional quantity cannot be encoded: Edge takes an integer of at
		// least one. Rounding it would falsify both the quantity and the unit
		// price, so the line becomes a single unit worth the whole amount and
		// the real quantity is written into the description instead. Nothing is
		// invented and no money moves.
		if ( $quantity['fractional'] ) {
			$description = self::append(
				$description,
				sprintf(
					/* translators: %s: the quantity as WooCommerce recorded it, which may be fractional. */
					__( 'Quantity: %s', 'edge-gateway' ),
					$quantity['label']
				)
			);
		}

		return array(
			'name'           => isset( $row['name'] ) ? (string) $row['name'] : '',
			'description'    => $description,
			'sku'            => isset( $row['sku'] ) ? (string) $row['sku'] : '',
			'quantity'       => $quantity['units'],
			'subtotal_cents' => max( 0, $subtotal ),
			'total_cents'    => max( 0, $total ),
		);
	}

	/**
	 * Interpret a raw quantity.
	 *
	 * WooCommerce's quantity is whatever `woocommerce_stock_amount` returns, and
	 * extensions that sell by weight or length filter it to `floatval`.
	 *
	 * @param mixed $value Raw quantity.
	 * @return array{units:int,fractional:bool,label:string}|null Null when unusable.
	 */
	private static function quantity( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$label = trim( (string) $value );

		if ( '' === $label || ! is_numeric( $label ) ) {
			return null;
		}

		$number = (float) $label;

		if ( 0.0 >= $number ) {
			return null;
		}

		$whole = (int) $number;

		if ( (float) $whole === $number ) {
			return array(
				'units'      => $whole,
				'fractional' => false,
				'label'      => (string) $whole,
			);
		}

		return array(
			'units'      => 1,
			'fractional' => true,
			'label'      => $label,
		);
	}

	/**
	 * Convert a decimal string to integer cents.
	 *
	 * @param mixed $value Decimal string from read().
	 * @return int|null Null when the value cannot be converted.
	 */
	private static function cents( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		try {
			return WC_Edge_Money::to_cents( $value );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * A stable digest of the itemisation.
	 *
	 * The attempt fingerprint cannot rely on `WC_Cart::get_cart_hash()` for
	 * this: `WC_Cart_Session::get_cart_for_session()` unsets each row's product
	 * object, so a rename or an edited SKU never reaches that hash, and
	 * calculated fees are not in the cart rows at all. Without this, two carts
	 * that differ only in itemisation share an attempt and the second reuses the
	 * first one's demand.
	 *
	 * Lines are sorted before hashing, and only for hashing, so that a
	 * re-ordered cart does not mint a second demand for the same basket.
	 *
	 * @param array $collected Normalised values, before the hash is added.
	 * @return string 64 character hex digest.
	 */
	private static function hash( array $collected ) {
		$lines = $collected['lines'];

		usort(
			$lines,
			static function ( $a, $b ) {
				return strcmp( self::hash_key( $a ), self::hash_key( $b ) );
			}
		);

		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'lines'          => $lines,
					'shipping_cents' => $collected['shipping_cents'],
					'tax_cents'      => $collected['tax_cents'],
					'discount_cents' => $collected['discount_cents'],
				)
			)
		);
	}

	/**
	 * Sort key for hashing, stable across requests.
	 *
	 * @param array $line One normalised line.
	 * @return string
	 */
	private static function hash_key( array $line ) {
		return implode(
			'|',
			array(
				$line['sku'],
				$line['name'],
				$line['description'],
				(string) $line['subtotal_cents'],
				(string) $line['total_cents'],
				(string) $line['quantity'],
			)
		);
	}

	/**
	 * A refusal to itemise.
	 *
	 * @param string $reason Machine-readable cause, for the log.
	 * @return array
	 */
	private static function incomplete( $reason ) {
		return array(
			'complete'       => false,
			'reason'         => $reason,
			'lines'          => array(),
			'shipping_cents' => 0,
			'tax_cents'      => 0,
			'discount_cents' => 0,
			'hash'           => '',
		);
	}

	/**
	 * Join two description fragments.
	 *
	 * @param string $existing Current description, possibly empty.
	 * @param string $addition Fragment to add.
	 * @return string
	 */
	private static function append( $existing, $addition ) {
		$existing = trim( (string) $existing );

		return '' === $existing ? $addition : $existing . ' - ' . $addition;
	}

	/**
	 * Put a WooCommerce money value into decimal-string form.
	 *
	 * @param mixed $value Money value of any of WooCommerce's several types.
	 * @return string
	 */
	private static function decimal( $value ) {
		// Never fewer than two: a store set to display whole dollars still has
		// to charge in cents, and wc_format_decimal( 25.50, 0 ) is "26".
		$decimals = max( 2, (int) wc_get_price_decimals() );

		return (string) wc_format_decimal( $value, $decimals );
	}

	/**
	 * The variation attributes as a flat string, or an empty one.
	 *
	 * `wc_get_formatted_cart_item_data()` would also fold in third-party cart
	 * item meta, but it renders through an output buffer and fires
	 * `woocommerce_get_item_data`, which puts arbitrary plugin markup and echo
	 * on the payment path. This is the plain-string half of the same job.
	 *
	 * @param WC_Product $product Cart item product.
	 * @return string
	 */
	private static function variation( $product ) {
		if ( ! $product instanceof WC_Product_Variation ) {
			return '';
		}

		return self::plain( wc_get_formatted_variation( $product, true, true, true ) );
	}

	/**
	 * Reduce a stored label to plain text.
	 *
	 * Titles are stored entity-encoded and may carry markup from a page
	 * builder. Both have to go: this value ends up in a JSON payload and on a
	 * merchant's dashboard, not in HTML.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function plain( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return wp_specialchars_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES );
	}
}
