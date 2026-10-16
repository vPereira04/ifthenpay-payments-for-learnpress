<?php
/**
 * Uninstall routine: removes our options, transients, locks, cron hook and the attempts table.
 * LearnPress's orders and their notes stay; they're LearnPress's records.
 *
 * @package Ifthenpay\LearnPress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$iftp_lp_autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $iftp_lp_autoload ) ) {
	require_once $iftp_lp_autoload;
}

// Everything here lives per site (options, transients, cron, the prefixed table), so on a network I run it on each one.
$iftp_lp_uninstall_site = static function (): void {
	global $wpdb;

	if ( class_exists( \Ifthenpay\LearnPress\Repository\SettingsRepository::class ) ) {
		$settings = new \Ifthenpay\LearnPress\Repository\SettingsRepository();
		$settings->delete_all();
		$settings->delete_anti_phishing_keys();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time DDL cleanup on uninstall; custom plugin table with no WP Core API.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'iftp_lp_transactions' ) );

	// Per-method activation cooldowns, one-time notices and link locks have generated names, so they go by prefix.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_iftp_lp_' ) . '%', $wpdb->esc_like( '_transient_timeout_iftp_lp_' ) . '%', $wpdb->esc_like( 'iftp_lp_lock_' ) . '%' ) );

	delete_option( 'iftp_lp_db_version' );
	// LearnPress's own Enable/Title/Description for our gateway.
	delete_option( 'learn_press_ifthenpay' );
	delete_transient( 'iftp_lp_callback_resync_lock' );
	delete_transient( 'iftp_lp_backfill_lock' );
	delete_transient( 'iftp_lp_methods_catalog' );
	delete_transient( 'iftp_lp_gateway_rows' );
	wp_clear_scheduled_hook( 'iftp_lp_expire_payments' );
};

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $iftp_lp_blog_id ) {
		switch_to_blog( (int) $iftp_lp_blog_id );
		$iftp_lp_uninstall_site();
		restore_current_blog();
	}
} else {
	$iftp_lp_uninstall_site();
}
