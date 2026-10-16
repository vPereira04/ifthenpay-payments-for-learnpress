<?php
/**
 * Custom transactions table storage.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row per Pay by Link attempt, linked to its LearnPress order. LearnPress keeps the order; this keeps the
 * attempts, links, methods and request IDs it has no place for, unpaid ones included.
 *
 * @phpstan-type Transaction array{ref: string, order_id: int, user_id: int, course_ids: string, gateway_key: string, amount: float, refunded_amount: float, pay_method: string, request_id: string, transaction_id: string, payment_url: string, customer_name: string, customer_email: string, status: string, source: string, expires_at: int, created_at: int, updated_at: int}
 * @phpstan-type NewTransaction array{order_id: int, user_id: int, course_ids: array<int, int>, amount: float, gateway_key: string, customer_name: string, customer_email: string, source: string}
 */
class TransactionStore {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_PAID      = 'paid';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_EXPIRED   = 'expired';
	public const STATUS_REFUNDED  = 'refunded';

	public const ALL_STATUSES = array(
		self::STATUS_PENDING,
		self::STATUS_PAID,
		self::STATUS_FAILED,
		self::STATUS_CANCELLED,
		self::STATUS_EXPIRED,
		self::STATUS_REFUNDED,
	);

	// Payment history: callbacks never move these, and nothing deletes them.
	public const PROTECTED_STATUSES = array( self::STATUS_PAID, self::STATUS_REFUNDED );

	public const SOURCE_CHECKOUT = 'checkout';
	public const SOURCE_ACCOUNT  = 'account';

	// The table's own schema revision, separate from the plugin version.
	private const SCHEMA_REVISION = 1;

	private const CUSTOMER_MAX_LENGTH = 191;

	// Every column, spelled out so a later column doesn't slip into reads unnoticed.
	private const LIST_COLUMNS = 't.ref, t.order_id, t.user_id, t.course_ids, t.gateway_key, t.amount, t.refunded_amount, t.pay_method, t.request_id, t.transaction_id, t.payment_url, t.customer_name, t.customer_email, t.status, t.source, t.expires_at, t.created_at, t.updated_at';

	private const CACHE_GROUP     = 'iftp_lp';
	private const ENTRY_CACHE_TTL = 600;
	private const LIST_CACHE_TTL  = 30;

	/**
	 * The custom table name, including the site's table prefix.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'iftp_lp_transactions';
	}

	/**
	 * Creates or upgrades the table; a no-op once the stored schema revision is current.
	 */
	public static function maybe_create_table(): void {
		if ( version_compare( (string) get_option( 'iftp_lp_db_version', '0' ), (string) self::SCHEMA_REVISION, '>=' ) ) {
			return;
		}

		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
            ref VARCHAR(191) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            course_ids VARCHAR(191) NOT NULL DEFAULT '',
            gateway_key VARCHAR(100) NOT NULL DEFAULT '',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            refunded_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            pay_method VARCHAR(50) NOT NULL DEFAULT '',
            request_id VARCHAR(100) NOT NULL DEFAULT '',
            transaction_id VARCHAR(100) NOT NULL DEFAULT '',
            payment_url VARCHAR(500) NOT NULL DEFAULT '',
            customer_name VARCHAR(191) NOT NULL DEFAULT '',
            customer_email VARCHAR(191) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            source VARCHAR(20) NOT NULL DEFAULT 'checkout',
            expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (ref),
            KEY status (status),
            KEY created_at (created_at),
            KEY status_created_at (status,created_at),
            KEY order_id (order_id),
            KEY user_id (user_id),
            KEY pay_method (pay_method),
            KEY customer_name (customer_name)
        ) {$charset_collate};"
		);

		// Autoloaded, since boot() reads it on every request.
		update_option( 'iftp_lp_db_version', self::SCHEMA_REVISION, true );
	}

	/**
	 * Inserts a pending attempt for a LearnPress order. The link comes after, with record_payment_link().
	 *
	 * @param array<string, mixed> $row The attempt.
	 * @phpstan-param NewTransaction $row
	 * @return string|null The generated ref, or null if the insert failed.
	 */
	public function create_pending( array $row ): ?string {
		global $wpdb;

		// The order id in the ref lets anyone reading ifthenpay's backoffice find the order.
		$ref      = $this->unique_ref( 'LP_' . $row['order_id'] . '_', 6 );
		$now      = time();
		$customer = $this->storable_customer( $row['customer_name'], $row['customer_email'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API available.
		$result = $wpdb->insert(
			self::table_name(),
			array(
				'ref'            => $ref,
				'order_id'       => $row['order_id'],
				'user_id'        => $row['user_id'],
				'course_ids'     => self::course_ids_column( $row['course_ids'] ),
				'gateway_key'    => $row['gateway_key'],
				'amount'         => $row['amount'],
				'customer_name'  => $customer['name'],
				'customer_email' => $customer['email'],
				'status'         => self::STATUS_PENDING,
				'source'         => $row['source'],
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		// unique_ref() cached a miss for this ref; a persistent cache would hide the row from the webhook.
		$this->forget( array( $ref ) );

		return false !== $result ? $ref : null;
	}

	/**
	 * A reference no row uses yet.
	 *
	 * @param string $prefix Readable prefix, e.g. `LP_42_`.
	 * @param int    $length Number of random characters after it.
	 */
	public function unique_ref( string $prefix, int $length ): string {
		do {
			$ref = $prefix . strtoupper( wp_generate_password( $length, false, false ) );
		} while ( null !== $this->get( $ref ) );

		return $ref;
	}

	/**
	 * Reads a transaction row by reference.
	 *
	 * @param string $ref The transaction reference.
	 * @return Transaction|null
	 */
	public function get( string $ref ): ?array {
		global $wpdb;

		$cached = wp_cache_get( self::row_cache_key( $ref ), self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return is_array( $cached ) ? self::hydrate( $cached ) : null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table, cached right below.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ref = %s', self::table_name(), $ref ), ARRAY_A );

		$record = is_array( $row ) ? self::hydrate( $row ) : null;

		wp_cache_set( self::row_cache_key( $ref ), $record, self::CACHE_GROUP, self::ENTRY_CACHE_TTL );

		return $record;
	}

	/**
	 * Marks a transaction paid. One conditional UPDATE, so when the webhook fires twice at once only one call gets true.
	 *
	 * @param string $ref        The transaction reference.
	 * @param string $pay_method The payment method used.
	 * @param string $request_id The ifthenpay request id.
	 */
	public function mark_paid( string $ref, string $pay_method, string $request_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; cache is invalidated right below.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, pay_method = %s, request_id = %s, updated_at = %d WHERE ref = %s AND status NOT IN (%s, %s)',
				self::table_name(),
				self::STATUS_PAID,
				$pay_method,
				$request_id,
				time(),
				$ref,
				self::STATUS_PAID,
				self::STATUS_REFUNDED
			)
		);

		$this->forget( array( $ref ) );

		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * Marks a transaction failed, unless it's already payment history.
	 *
	 * @param string $ref The transaction reference.
	 */
	public function mark_failed( string $ref ): bool {
		return $this->set_status_unless_protected( $ref, self::STATUS_FAILED );
	}

	/**
	 * Marks a transaction cancelled, unless it's already payment history.
	 *
	 * @param string $ref The transaction reference.
	 */
	public function mark_cancelled( string $ref ): bool {
		return $this->set_status_unless_protected( $ref, self::STATUS_CANCELLED );
	}

	/**
	 * Expires pending rows whose link ran out. Rows that never got a link expire once they're older than the window.
	 * A late webhook still wins: mark_paid() only skips paid and refunded rows.
	 *
	 * @param int $now           Unix timestamp.
	 * @param int $no_link_after Rows without a link, created before this, expire too.
	 * @return int How many rows changed.
	 */
	public function expire_due( int $now, int $no_link_after ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; a cron sweep, caching it would be pointless.
		$refs = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT ref FROM %i WHERE status = %s AND ( ( expires_at > 0 AND expires_at < %d ) OR ( expires_at = 0 AND created_at < %d ) )',
				self::table_name(),
				self::STATUS_PENDING,
				$now,
				$no_link_after
			)
		);

		return $this->set_status_many( array_map( 'strval', $refs ), self::STATUS_EXPIRED, self::STATUS_PENDING );
	}

	/**
	 * Records a payment link, the Gateway Key the webhook validates against, and when the link stops being payable.
	 *
	 * @param string $ref         The transaction reference.
	 * @param string $payment_url The ifthenpay hosted payment page URL.
	 * @param string $gateway_key The Gateway Key the link belongs to.
	 * @param int    $expires_at  Unix timestamp the link expires at.
	 */
	public function record_payment_link( string $ref, string $payment_url, string $gateway_key, int $expires_at ): bool {
		return $this->update(
			$ref,
			array(
				'payment_url' => $payment_url,
				'gateway_key' => $gateway_key,
				'expires_at'  => $expires_at,
			)
		);
	}

	/**
	 * Records the ifthenpay transaction id.
	 *
	 * @param string $ref            The transaction reference.
	 * @param string $transaction_id The ifthenpay transaction id.
	 */
	public function record_transaction_id( string $ref, string $transaction_id ): bool {
		return $this->update( $ref, array( 'transaction_id' => $transaction_id ) );
	}

	/**
	 * Adds a refund to the row's running total and marks it refunded. LearnPress overwrites its own refunded amount, so I keep the sum here.
	 *
	 * @param string $ref    The transaction reference.
	 * @param float  $amount The amount just refunded.
	 */
	public function record_refund( string $ref, float $amount ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; cache is invalidated right below.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET refunded_amount = refunded_amount + %f, status = %s, updated_at = %d WHERE ref = %s',
				self::table_name(),
				$amount,
				self::STATUS_REFUNDED,
				time(),
				$ref
			)
		);

		$this->forget( array( $ref ) );

		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * The order's link that can still be paid (pending, has a link, not expired), newest first, or null.
	 *
	 * @param int $order_id The LearnPress order id.
	 * @param int $now      Unix timestamp.
	 * @return Transaction|null
	 */
	public function live_for_order( int $order_id, int $now ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; read under the link lock, so it must be live.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE order_id = %d AND status = %s AND payment_url <> '' AND expires_at > %d ORDER BY created_at DESC, ref DESC LIMIT 1", self::table_name(), $order_id, self::STATUS_PENDING, $now ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Live links on other orders for the same customer and any of the same courses, newest first.
	 *
	 * @param int             $user_id     The customer's user id, or 0 for a guest.
	 * @param string          $email       The guest's checkout email.
	 * @param array<int, int> $course_ids  The courses being bought.
	 * @param int             $exclude     The order being paid, left out.
	 * @param int             $now         Unix timestamp.
	 * @return array<int, Transaction>
	 */
	public function live_for_customer( int $user_id, string $email, array $course_ids, int $exclude, int $now ): array {
		global $wpdb;

		$course_ids = array_values( array_filter( array_map( 'absint', $course_ids ) ) );

		if ( array() === $course_ids || ( $user_id <= 0 && '' === $email ) ) {
			return array();
		}

		$likes = array();

		foreach ( $course_ids as $course_id ) {
			$likes[] = $wpdb->prepare( 'course_ids LIKE %s', '%,' . $course_id . ',%' );
		}

		$customer = $user_id > 0 ? $wpdb->prepare( 'user_id = %d', $user_id ) : $wpdb->prepare( 'user_id = 0 AND customer_email = %s', $email );
		$courses  = '(' . implode( ' OR ', $likes ) . ')';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, read under the link lock; $customer and $courses are built from prepare() calls.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = %s AND payment_url <> '' AND expires_at > %d AND order_id <> %d AND " . $customer . ' AND ' . $courses . ' ORDER BY created_at DESC, ref DESC LIMIT 10', self::table_name(), self::STATUS_PENDING, $now, $exclude ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Every attempt for an order, newest first.
	 *
	 * @param int $order_id The LearnPress order id.
	 * @return array<int, Transaction>
	 */
	public function for_order( int $order_id ): array {
		global $wpdb;

		$cache_key = 'order_' . $order_id . '_' . $this->last_changed();
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cached right below; LIST_COLUMNS is a constant.
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::LIST_COLUMNS . ' FROM %i AS t WHERE t.order_id = %d ORDER BY t.created_at DESC, t.ref DESC LIMIT 50', self::table_name(), $order_id ), ARRAY_A );
		$records = array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );

		wp_cache_set( $cache_key, $records, self::CACHE_GROUP, self::LIST_CACHE_TTL );

		return $records;
	}

	/**
	 * The course ids a row's `course_ids` column holds.
	 *
	 * @param string $column The stored `,12,34,` list.
	 * @return array<int, int>
	 */
	public static function course_ids( string $column ): array {
		return array_values( array_filter( array_map( 'absint', explode( ',', $column ) ) ) );
	}

	/**
	 * One page of a customer's rows by email, for the personal data exporter.
	 *
	 * @param string $email  The customer's email.
	 * @param int    $limit  Rows per page.
	 * @param int    $offset Rows to skip.
	 * @return array<int, Transaction>
	 */
	public function for_email( string $email, int $limit, int $offset ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; a privacy export reads live data once.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_email = %s ORDER BY created_at ASC, ref ASC LIMIT %d OFFSET %d', self::table_name(), $email, $limit, $offset ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Wipes the customer's personal data from up to $limit of their rows. The payment itself (ref, amount, status) stays.
	 *
	 * @param string $email The customer's email.
	 * @param int    $limit Rows per batch.
	 * @return int How many rows changed.
	 */
	public function anonymize_email( string $email, int $limit ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; a privacy erase reads live data.
		$refs = array_map( 'strval', $wpdb->get_col( $wpdb->prepare( 'SELECT ref FROM %i WHERE customer_email = %s LIMIT %d', self::table_name(), $email, $limit ) ) );

		if ( array() === $refs ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cache invalidated below; prepare_for_refs() prepares it.
		$affected = $wpdb->query( self::prepare_for_refs( "UPDATE %i SET customer_name = '', customer_email = '', updated_at = %d WHERE ref IN ({refs})", $refs, array( self::table_name(), time() ) ) );

		$this->forget( $refs );

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Writes a status unless the row is payment history — in the WHERE clause, so a webhook landing at the same moment can't be overwritten.
	 *
	 * @param string $ref    The transaction reference.
	 * @param string $status One of ALL_STATUSES.
	 */
	private function set_status_unless_protected( string $ref, string $status ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; cache is invalidated right below.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %d WHERE ref = %s AND status NOT IN (%s, %s)',
				self::table_name(),
				$status,
				time(),
				$ref,
				self::STATUS_PAID,
				self::STATUS_REFUNDED
			)
		);

		$this->forget( array( $ref ) );

		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * Sets one status on many rows in one UPDATE.
	 *
	 * @param array<int, string> $refs        The transaction references.
	 * @param string             $status      The status to write.
	 * @param string             $only_status Only touch rows currently in this status, or '' for any.
	 * @return int How many rows changed.
	 */
	private function set_status_many( array $refs, string $status, string $only_status = '' ): int {
		global $wpdb;

		$refs = self::clean_refs( $refs );

		if ( array() === $refs ) {
			return 0;
		}

		$sql     = '' !== $only_status
			? 'UPDATE %i SET status = %s, updated_at = %d WHERE ref IN ({refs}) AND status = %s'
			: 'UPDATE %i SET status = %s, updated_at = %d WHERE ref IN ({refs}) AND status <> %s';
		$guarded = '' !== $only_status ? $only_status : $status;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cache invalidated below; prepare_for_refs() prepares it.
		$affected = $wpdb->query( self::prepare_for_refs( $sql, $refs, array( self::table_name(), $status, time() ), array( $guarded ) ) );

		$this->forget( $refs );

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Updates a transaction row and invalidates its cache entry.
	 *
	 * @param string               $ref    The transaction reference.
	 * @param array<string, mixed> $fields The columns to update.
	 */
	private function update( string $ref, array $fields ): bool {
		global $wpdb;

		$fields['updated_at'] = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; cache is invalidated right below.
		$result = $wpdb->update( self::table_name(), $fields, array( 'ref' => $ref ) );

		$this->forget( array( $ref ) );

		return false !== $result;
	}

	/**
	 * Casts a raw ARRAY_A row to its typed shape once, so callers don't have to.
	 *
	 * @param array<string, mixed> $row A raw table row.
	 * @return Transaction
	 */
	private static function hydrate( array $row ): array {
		$text  = static fn( string $key ): string => is_scalar( $row[ $key ] ?? null ) ? (string) $row[ $key ] : '';
		$int   = static fn( string $key ): int => is_numeric( $row[ $key ] ?? null ) ? (int) $row[ $key ] : 0;
		$money = static fn( string $key ): float => is_numeric( $row[ $key ] ?? null ) ? (float) $row[ $key ] : 0.0;

		return array(
			'ref'             => $text( 'ref' ),
			'order_id'        => $int( 'order_id' ),
			'user_id'         => $int( 'user_id' ),
			'course_ids'      => $text( 'course_ids' ),
			'gateway_key'     => $text( 'gateway_key' ),
			'amount'          => $money( 'amount' ),
			'refunded_amount' => $money( 'refunded_amount' ),
			'pay_method'      => $text( 'pay_method' ),
			'request_id'      => $text( 'request_id' ),
			'transaction_id'  => $text( 'transaction_id' ),
			'payment_url'     => $text( 'payment_url' ),
			'customer_name'   => $text( 'customer_name' ),
			'customer_email'  => $text( 'customer_email' ),
			'status'          => $text( 'status' ),
			'source'          => $text( 'source' ),
			'expires_at'      => $int( 'expires_at' ),
			'created_at'      => $int( 'created_at' ),
			'updated_at'      => $int( 'updated_at' ),
		);
	}

	/**
	 * Course ids as stored: `,12,34,`, so `LIKE '%,12,%'` matches whole ids only.
	 *
	 * @param array<int, int> $course_ids The course ids.
	 */
	private static function course_ids_column( array $course_ids ): string {
		$course_ids = array_values( array_unique( array_filter( array_map( 'absint', $course_ids ) ) ) );

		return array() === $course_ids ? '' : ',' . implode( ',', $course_ids ) . ',';
	}

	/**
	 * The customer cut down to what its columns can store.
	 * wpdb fails the whole insert on a character the charset can't hold (emoji on utf8mb3), so I strip those.
	 *
	 * @param string $name  The customer's name.
	 * @param string $email The customer's email.
	 * @return array{name: string, email: string}
	 */
	private function storable_customer( string $name, string $email ): array {
		global $wpdb;

		$table = self::table_name();
		$name  = $wpdb->strip_invalid_text_for_column( $table, 'customer_name', mb_substr( $name, 0, self::CUSTOMER_MAX_LENGTH ) );
		$email = false !== is_email( $email ) ? $wpdb->strip_invalid_text_for_column( $table, 'customer_email', mb_substr( $email, 0, self::CUSTOMER_MAX_LENGTH ) ) : '';

		return array(
			'name'  => is_string( $name ) ? $name : '',
			'email' => is_string( $email ) ? $email : '',
		);
	}

	/**
	 * Prepares $sql with `{refs}` swapped for one %s per ref. The refs sit between $before and $after in the args.
	 *
	 * @param string             $sql    The query, with a `{refs}` token inside its `IN ( … )`.
	 * @param array<int, string> $refs   The refs, already cleaned.
	 * @param array<int, mixed>  $before Args for the placeholders before `{refs}`.
	 * @param array<int, mixed>  $after  Args for the placeholders after it.
	 */
	private static function prepare_for_refs( string $sql, array $refs, array $before = array(), array $after = array() ): string {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $refs ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- callers pass fixed SQL; {refs} becomes one %s per ref.
		return (string) $wpdb->prepare( str_replace( '{refs}', $placeholders, $sql ), array_merge( $before, $refs, $after ) );
	}

	/**
	 * Scalar, non-empty, de-duplicated refs, re-indexed.
	 *
	 * @param array<mixed> $refs Raw refs.
	 * @return array<int, string>
	 */
	private static function clean_refs( array $refs ): array {
		$refs = array_map( 'strval', array_filter( $refs, 'is_scalar' ) );

		return array_values( array_unique( array_filter( $refs, static fn( string $ref ): bool => '' !== $ref ) ) );
	}

	/**
	 * Drops the given rows' cached copies and every cached list.
	 *
	 * @param array<int, string> $refs The transaction references.
	 */
	private function forget( array $refs ): void {
		wp_cache_delete_multiple( array_map( array( self::class, 'row_cache_key' ), $refs ), self::CACHE_GROUP );
		wp_cache_set( 'last_changed', microtime(), self::CACHE_GROUP );
	}

	/**
	 * The cache key for one row.
	 *
	 * @param string $ref The transaction reference.
	 */
	private static function row_cache_key( string $ref ): string {
		return 'txn_' . $ref;
	}

	/**
	 * A token bumped on every write, so list caches never need per-row invalidation.
	 */
	private function last_changed(): string {
		$last_changed = wp_cache_get( 'last_changed', self::CACHE_GROUP );

		if ( ! is_string( $last_changed ) ) {
			$last_changed = microtime();
			wp_cache_set( 'last_changed', $last_changed, self::CACHE_GROUP );
		}

		return $last_changed;
	}
}
