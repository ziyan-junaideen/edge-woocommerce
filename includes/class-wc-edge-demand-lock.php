<?php
/**
 * Short-lived exclusive lease over one Edge payment demand.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.4.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serialises the requests that apply a payment demand's outcome to its order.
 *
 * Three of them race for the same order. The webhook and the checkout poll both
 * read the demand and write what it says, and they routinely arrive together
 * because Edge delivers the event at about the moment the shopper's browser next
 * asks. `process_payment()` is the third: between Blocks setting the order
 * `pending` and that method writing `on-hold`, a fast webhook would otherwise see
 * a status the outcome rules read as stale, or complete the order only to have
 * the in-memory copy in `process_payment()` write `on-hold` back over it.
 *
 * A table of its own rather than a column on the attempt row. An order can carry
 * more than one adopted attempt, an order can be bound to a demand by hand with
 * no attempt behind it at all, and the lock has to be takeable in both cases;
 * keying the lease on the demand and nothing else is what makes that true. It
 * also keeps a lock write - which happens on every poll - off the row that
 * records what was created at Edge.
 *
 * The lease expires by itself, because a request that dies mid-sync must not
 * wedge an order until somebody notices. It is handed back as an owner token:
 * once a lease has expired and been taken over, the original holder releasing it
 * would otherwise free somebody else's lock.
 */
final class WC_Edge_Demand_Lock {

	/**
	 * Bump when the schema changes.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 *
	 * @var string
	 */
	const SCHEMA_OPTION = 'wc_edge_demand_locks_schema_version';

	/**
	 * Default lease length.
	 *
	 * Only has to outlast one round trip to Edge plus the order write.
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 30;

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'edge_demand_locks';
	}

	/**
	 * Create the table when the schema version changes.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::install();

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Create the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		// The primary key on demand_id is the mutual exclusion: two requests both
		// insert, and only one of them can succeed.
		$sql = "CREATE TABLE {$table} (
			demand_id char(36) NOT NULL,
			owner char(36) NOT NULL,
			lock_until bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (demand_id),
			KEY lock_until (lock_until)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Take the lease on a demand.
	 *
	 * @param string $demand_id   Edge payment demand id.
	 * @param int    $ttl_seconds How long the lease is honoured for.
	 * @return string|false The owner token to release with, or false when somebody else holds it.
	 */
	public static function acquire( $demand_id, $ttl_seconds = self::DEFAULT_TTL ) {
		global $wpdb;

		$demand_id = (string) $demand_id;

		if ( '' === $demand_id ) {
			return false;
		}

		$owner      = wp_generate_uuid4();
		$now        = time();
		$lock_until = $now + max( 1, (int) $ttl_seconds );

		// A row already there is the expected miss, not a fault, so keep the
		// duplicate-key violation out of the error log.
		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'demand_id'  => $demand_id,
				'owner'      => $owner,
				'lock_until' => $lock_until,
			)
		);

		$wpdb->suppress_errors( $suppressed );

		if ( false !== $inserted ) {
			return $owner;
		}

		$table = self::table_name();

		// Somebody holds the row. One statement, so the database decides the
		// winner of an expired lease: the owner token changes on every take, so
		// this never updates zero rows for want of a changed value - which both
		// MySQL and SQLite would report as nothing having happened.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET owner = %s, lock_until = %d
				 WHERE demand_id = %s AND lock_until < %d",
				$owner,
				$lock_until,
				$demand_id,
				$now
			)
		);
		// phpcs:enable

		return 1 === $affected ? $owner : false;
	}

	/**
	 * Give up a lease.
	 *
	 * Matching on the owner is what stops a request whose lease expired - and was
	 * taken over while it was still working - from releasing the new holder's.
	 *
	 * @param string $demand_id Edge payment demand id.
	 * @param string $owner     Token acquire() returned.
	 * @return bool Whether this caller's lease was the one removed.
	 */
	public static function release( $demand_id, $owner ) {
		global $wpdb;

		$demand_id = (string) $demand_id;
		$owner     = (string) $owner;

		if ( '' === $demand_id || '' === $owner ) {
			return false;
		}

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE demand_id = %s AND owner = %s",
				$demand_id,
				$owner
			)
		);
		// phpcs:enable

		return 1 === $deleted;
	}

	/**
	 * Drop leases nobody is holding any more.
	 *
	 * A released lease deletes its own row, so this only clears the ones left
	 * behind by a request that died mid-sync.
	 *
	 * @return int Rows removed.
	 */
	public static function purge() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE lock_until < %d", time() )
		);
		// phpcs:enable
	}
}
