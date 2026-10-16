<?php
/**
 * Plugin Name: ifthenpay | Payments for LearnPress
 * Plugin URI: https://ifthenpay.com
 * Description: ifthenpay Pay by Link for LearnPress: students pay for courses on ifthenpay's hosted page with Multibanco, MB WAY, cards, Apple Pay, Google Pay and more.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: learnpress
 * Author: ifthenpay
 * Author URI: https://ifthenpay.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ifthenpay-payments-for-learnpress
 * Domain Path: /languages
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFTP_LP_VERSION', '1.0.0' );
define( 'IFTP_LP_FILE', __FILE__ );
define( 'IFTP_LP_PATH', plugin_dir_path( __FILE__ ) );
define( 'IFTP_LP_URL', plugin_dir_url( __FILE__ ) );

$iftp_lp_autoload = IFTP_LP_PATH . 'vendor/autoload.php';

if ( file_exists( $iftp_lp_autoload ) ) {
	require_once $iftp_lp_autoload;
}

// A closure, so I don't add a global function.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( \Ifthenpay\LearnPress\Plugin::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					wp_admin_notice( esc_html__( 'ifthenpay | Payments for LearnPress could not load its classes. Run "composer install" in the plugin folder.', 'ifthenpay-payments-for-learnpress' ), array( 'type' => 'error' ) );
				},
				10,
				0
			);

			return;
		}

		// Requires Plugins covers WP 6.5+; this covers LearnPress removed some other way.
		if ( ! class_exists( 'LearnPress' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					wp_admin_notice( esc_html__( 'ifthenpay | Payments for LearnPress needs LearnPress to be installed and active.', 'ifthenpay-payments-for-learnpress' ), array( 'type' => 'error' ) );
				},
				10,
				0
			);

			return;
		}

		\Ifthenpay\LearnPress\Plugin::instance()->boot();
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		// Below WP 6.5 the Requires Plugins header is ignored, so I stop the activation myself.
		if ( ! class_exists( 'LearnPress' ) ) {
			wp_die(
				esc_html__( 'ifthenpay | Payments for LearnPress needs LearnPress. Install and activate LearnPress first.', 'ifthenpay-payments-for-learnpress' ),
				esc_html__( 'Plugin dependency missing', 'ifthenpay-payments-for-learnpress' ),
				array( 'back_link' => true )
			);
		}

		if ( ! class_exists( \Ifthenpay\LearnPress\Plugin::class ) ) {
			return;
		}

		\Ifthenpay\LearnPress\Repository\TransactionStore::maybe_create_table();
		\Ifthenpay\LearnPress\Cron\ExpiredPaymentsCron::schedule();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( \Ifthenpay\LearnPress\Plugin::class ) ) {
			\Ifthenpay\LearnPress\Cron\ExpiredPaymentsCron::unschedule();
		}
	}
);
