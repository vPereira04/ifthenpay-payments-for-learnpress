<?php
/**
 * Daily expiry sweep for unpaid payment links.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Cron;

use Ifthenpay\LearnPress\Plugin;
use Ifthenpay\LearnPress\Repository\TransactionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks pending attempts Expired once their link's last day is over. The LearnPress order stays pending, so the
 * student can still pay through a new link, and a late callback on the old one still wins.
 */
class ExpiredPaymentsCron {

	public const HOOK = 'iftp_lp_expire_payments';

	/**
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Sets up the sweep.
	 *
	 * @param TransactionStore $transactions Transactions storage.
	 */
	public function __construct( TransactionStore $transactions ) {
		$this->transactions = $transactions;
	}

	/**
	 * Hooks the sweep and makes sure it's scheduled.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );

		// Activation schedules it too; this covers a site that was already active. Only in wp-admin, so the frontend doesn't pay for it.
		add_action( 'admin_init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedules the daily sweep, unless it already is.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Removes the scheduled sweep.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Expires every pending attempt whose link ran out, and the ones that never got a link within the window.
	 */
	public function run(): void {
		$now = time();

		$this->transactions->expire_due( $now, $now - Plugin::instance()->settings()->get_expiry_days() * DAY_IN_SECONDS );
	}
}
