<?php
/**
 * @package WooCommerce Edge Payments Gateway
 */

namespace EdgePayments\EdgeWoocommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC_Edge_Refund_Outcome;

/**
 * @covers \WC_Edge_Refund_Outcome
 */
class RefundOutcomeTest extends TestCase {

	private function document( array $attributes = array(), string $id = 'refund-1' ): object {
		return (object) array(
			'data' => (object) array(
				'id'         => $id,
				'type'       => 'refund_demands',
				'attributes' => (object) array_merge(
					array(
						'state'           => 'pending',
						'amount_cents'    => 1000,
						'amount_currency' => 'USD',
						'idempotency_key' => 'key-one',
					),
					$attributes
				),
			),
		);
	}

	private function listing( array $resources ): object {
		return (object) array( 'data' => $resources );
	}

	private function resource( string $id, string $key, string $state = 'pending' ): object {
		return (object) array(
			'id'         => $id,
			'type'       => 'refund_demands',
			'attributes' => (object) array(
				'state'           => $state,
				'idempotency_key' => $key,
			),
		);
	}

	public function test_a_transport_failure_is_ambiguous_not_a_failure(): void {
		$this->assertSame(
			WC_Edge_Refund_Outcome::AMBIGUOUS,
			WC_Edge_Refund_Outcome::classify( 0, false )
		);
	}

	public function test_a_server_error_is_ambiguous(): void {
		$this->assertSame(
			WC_Edge_Refund_Outcome::AMBIGUOUS,
			WC_Edge_Refund_Outcome::classify( 500, false )
		);
		$this->assertSame(
			WC_Edge_Refund_Outcome::AMBIGUOUS,
			WC_Edge_Refund_Outcome::classify( 503, false )
		);
	}

	/**
	 * @dataProvider rejections
	 */
	public function test_a_client_error_is_a_rejection( int $status ): void {
		$this->assertSame(
			WC_Edge_Refund_Outcome::REJECTED,
			WC_Edge_Refund_Outcome::classify( $status, false )
		);
	}

	public static function rejections(): array {
		return array(
			'unauthorised'       => array( 401 ),
			'forbidden'          => array( 403 ),
			'not found'          => array( 404 ),
			'unprocessable'      => array( 422 ),
			'idempotency clash'  => array( 422 ),
		);
	}

	public function test_a_success_carrying_an_id_is_created(): void {
		$this->assertSame(
			WC_Edge_Refund_Outcome::CREATED,
			WC_Edge_Refund_Outcome::classify( 201, true )
		);
	}

	public function test_a_success_without_an_id_is_ambiguous(): void {
		// An empty 201 decodes to a bare stdClass. Treating that as success
		// would leave a refund nothing points at.
		$this->assertSame(
			WC_Edge_Refund_Outcome::AMBIGUOUS,
			WC_Edge_Refund_Outcome::classify( 201, false )
		);
	}

	public function test_has_id_recognises_a_member_document(): void {
		$this->assertTrue( WC_Edge_Refund_Outcome::has_id( $this->document() ) );
		$this->assertSame( 'refund-1', WC_Edge_Refund_Outcome::id_of( $this->document() ) );
	}

	public function test_has_id_rejects_an_empty_body(): void {
		$this->assertFalse( WC_Edge_Refund_Outcome::has_id( new \stdClass() ) );
		$this->assertSame( '', WC_Edge_Refund_Outcome::id_of( new \stdClass() ) );
	}

	public function test_has_id_rejects_a_document_without_an_id(): void {
		$document = (object) array( 'data' => (object) array( 'type' => 'refund_demands' ) );

		$this->assertFalse( WC_Edge_Refund_Outcome::has_id( $document ) );
	}

	public function test_has_id_rejects_a_collection(): void {
		// A listing is not an answer to "which refund did I just create".
		$this->assertFalse( WC_Edge_Refund_Outcome::has_id( $this->listing( array() ) ) );
	}

	public function test_find_by_key_locates_our_refund_in_a_listing(): void {
		$listing = $this->listing(
			array(
				$this->resource( 'other-1', 'someone-elses-key' ),
				$this->resource( 'ours-1', 'key-two', 'processing' ),
			)
		);

		$found = WC_Edge_Refund_Outcome::find_by_key( $listing, 'key-two' );

		$this->assertNotNull( $found );
		$this->assertSame( 'ours-1', $found->id );
	}

	public function test_find_by_key_returns_null_when_the_key_is_absent(): void {
		$listing = $this->listing( array( $this->resource( 'other-1', 'someone-elses-key' ) ) );

		$this->assertNull( WC_Edge_Refund_Outcome::find_by_key( $listing, 'key-two' ) );
	}

	public function test_find_by_key_ignores_refunds_with_no_key(): void {
		$listing = $this->listing(
			array(
				(object) array(
					'id'         => 'keyless',
					'attributes' => (object) array( 'state' => 'succeeded' ),
				),
			)
		);

		$this->assertNull( WC_Edge_Refund_Outcome::find_by_key( $listing, '' ) );
		$this->assertNull( WC_Edge_Refund_Outcome::find_by_key( $listing, 'key-two' ) );
	}

	public function test_find_by_key_refuses_an_empty_key(): void {
		// Otherwise a refund created without a key would match every lookup.
		$listing = $this->listing( array( $this->resource( 'ours-1', '' ) ) );

		$this->assertNull( WC_Edge_Refund_Outcome::find_by_key( $listing, '' ) );
	}

	public function test_find_by_key_accepts_a_member_document(): void {
		$found = WC_Edge_Refund_Outcome::find_by_key( $this->document(), 'key-one' );

		$this->assertNotNull( $found );
		$this->assertSame( 'refund-1', $found->id );
	}

	public function test_find_by_key_survives_junk(): void {
		$this->assertNull( WC_Edge_Refund_Outcome::find_by_key( null, 'key-one' ) );
		$this->assertNull( WC_Edge_Refund_Outcome::find_by_key( new \stdClass(), 'key-one' ) );
		$this->assertNull( WC_Edge_Refund_Outcome::find_by_key( (object) array( 'data' => 'nope' ), 'key-one' ) );
	}

	public function test_state_of_reads_the_resource_state(): void {
		$this->assertSame( 'succeeded', WC_Edge_Refund_Outcome::state_of( $this->resource( 'r', 'k', 'succeeded' ) ) );
		$this->assertSame( '', WC_Edge_Refund_Outcome::state_of( new \stdClass() ) );
		$this->assertSame( '', WC_Edge_Refund_Outcome::state_of( null ) );
	}

	/**
	 * @dataProvider live_states
	 */
	public function test_states_that_reserve_their_amount_are_live( string $state ): void {
		$this->assertTrue( WC_Edge_Refund_Outcome::is_live( $state ) );
	}

	public static function live_states(): array {
		// All three hold their amount against the payment's balance, so all
		// three mean "do not create another refund".
		return array(
			'pending'    => array( 'pending' ),
			'processing' => array( 'processing' ),
			'succeeded'  => array( 'succeeded' ),
		);
	}

	public function test_a_failed_refund_is_not_live(): void {
		// A failed refund releases its reservation, so it is not ours to adopt.
		$this->assertFalse( WC_Edge_Refund_Outcome::is_live( 'failed' ) );
		$this->assertFalse( WC_Edge_Refund_Outcome::is_live( '' ) );
		$this->assertFalse( WC_Edge_Refund_Outcome::is_live( 'errored' ) );
	}

	public function test_payment_demand_id_comes_from_the_relationship(): void {
		$document = (object) array(
			'data' => (object) array(
				'id'            => 'refund-1',
				'relationships' => (object) array(
					'payment_demand' => (object) array(
						'data' => (object) array(
							'id'   => 'demand-9',
							'type' => 'payment_demands',
						),
					),
				),
			),
		);

		$this->assertSame( 'demand-9', WC_Edge_Refund_Outcome::payment_demand_id( $document ) );
	}

	public function test_payment_demand_id_is_empty_when_absent(): void {
		$this->assertSame( '', WC_Edge_Refund_Outcome::payment_demand_id( $this->document() ) );
		$this->assertSame( '', WC_Edge_Refund_Outcome::payment_demand_id( new \stdClass() ) );
		$this->assertSame( '', WC_Edge_Refund_Outcome::payment_demand_id( null ) );
	}
}
