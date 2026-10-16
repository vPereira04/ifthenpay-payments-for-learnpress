<?php
/**
 * Drops LearnPress's login nag from the checkout page.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Frontend;

use Ifthenpay\LearnPress\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LearnPress prints a "Please login in to enroll in the course!" error box on top of checkout for guests.
 * It's hard-coded in its template, so I hide it with a checkout-only stylesheet.
 */
class CheckoutNotice {

	private const STYLE_HANDLE = 'iftp-lp-checkout';

	/**
	 * Hooks the stylesheet.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the stylesheet on LearnPress's checkout page only.
	 */
	public function enqueue(): void {
		if ( is_user_logged_in() || ! function_exists( 'learn_press_is_checkout' ) || ! learn_press_is_checkout() ) {
			return;
		}

		$css = 'assets/css/checkout.css';
		wp_enqueue_style( self::STYLE_HANDLE, IFTP_LP_URL . $css, array(), Plugin::asset_version( $css ) );
	}
}
