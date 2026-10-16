<?php
/**
 * Short-lived atomic locks.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A row in wp_options per lock, claimed with INSERT IGNORE, so two requests at the same moment can't both win.
 * add_option() isn't atomic (it checks, then writes), which is why I go to the table myself.
 */
final class Locks {

	private const PREFIX = 'iftp_lp_lock_';

	// A crashed request never releases, so its lock is reclaimed after this.
	private const STALE_AFTER = 300;

	/**
	 * Static-only helper.
	 */
	private function __construct() {
	}

	/**
	 * Claims a lock, reclaiming it once if it's stale.
	 *
	 * @param string $key What the lock protects, e.g. `link|12|34`.
	 */
	public static function claim( string $key ): bool {
		global $wpdb;

		$name = self::name( $key );

		if ( self::insert( $name ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must read the live value, not the options cache.
		$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		if ( $since > time() - self::STALE_AFTER ) {
			return false;
		}

		// Only the request that deletes the stale value gets to claim it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- conditional delete, same reason as above.
		if ( 1 !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $since ) ) ) {
			return false;
		}

		return self::insert( $name );
	}

	/**
	 * Releases a lock.
	 *
	 * @param string $key What the lock protects.
	 */
	public static function release( string $key ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the lock row never goes through the options cache.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::name( $key ) ) );
	}

	/**
	 * Inserts the lock row unless it exists. INSERT IGNORE affects one row only for the request that wins.
	 *
	 * @param string $name The lock's option name.
	 */
	private static function insert( string $name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic claim has to hit the table; no WP API does INSERT IGNORE.
		return 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, (string) time() ) );
	}

	/**
	 * The option name for a lock key.
	 *
	 * @param string $key What the lock protects.
	 */
	private static function name( string $key ): string {
		return self::PREFIX . md5( $key );
	}
}
