<?php
/**
 * Guarded access to LearnPress helpers.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every LearnPress function or class I call goes through here, so a renamed helper can't fatal and there's one place to fix it.
 */
final class Host {

	/**
	 * Static-only helper.
	 */
	private function __construct() {
	}

	/**
	 * The store currency code, e.g. `EUR`.
	 */
	public static function currency(): string {
		return function_exists( 'learn_press_get_currency' ) ? strtoupper( (string) learn_press_get_currency() ) : '';
	}

	/**
	 * Whether the store sells in euros, the only currency ifthenpay takes.
	 */
	public static function is_eur(): bool {
		return 'EUR' === self::currency();
	}

	/**
	 * The LearnPress version, for the activation email.
	 */
	public static function version(): string {
		return defined( 'LEARNPRESS_VERSION' ) ? (string) LEARNPRESS_VERSION : '';
	}

	/**
	 * The course post type.
	 */
	public static function course_post_type(): string {
		return defined( 'LP_COURSE_CPT' ) ? (string) LP_COURSE_CPT : 'lp_course';
	}

	/**
	 * A LearnPress order, or null when it doesn't exist.
	 *
	 * @param int $order_id The order id.
	 */
	public static function order( int $order_id ): ?\LP_Order {
		if ( $order_id <= 0 || ! class_exists( 'LP_Order' ) || ! function_exists( 'learn_press_get_order' ) ) {
			return null;
		}

		$order = learn_press_get_order( $order_id );

		return $order instanceof \LP_Order && $order->get_id() ? $order : null;
	}

	/**
	 * The order's items as course id => title. Items that aren't courses (an add-on's plans) keep their own id.
	 *
	 * @param \LP_Order $order The order.
	 * @return array<int, string>
	 */
	public static function order_courses( \LP_Order $order ): array {
		$courses = array();
		$items   = $order->get_items();

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$id = absint( $item['course_id'] ?? ( $item['item_id'] ?? 0 ) );

			if ( $id > 0 ) {
				$name           = is_scalar( $item['name'] ?? null ) ? (string) $item['name'] : '';
				$courses[ $id ] = '' !== $name ? $name : (string) get_the_title( $id );
			}
		}

		return $courses;
	}

	/**
	 * A course's current price, sale included, or 0 when it isn't a course.
	 *
	 * @param int $course_id The course id.
	 */
	public static function course_price( int $course_id ): float {
		if ( $course_id <= 0 || ! function_exists( 'learn_press_get_course' ) || self::course_post_type() !== get_post_type( $course_id ) ) {
			return 0.0;
		}

		$course = learn_press_get_course( $course_id );

		return is_object( $course ) && method_exists( $course, 'get_price' ) ? round( (float) $course->get_price(), 2 ) : 0.0;
	}

	/**
	 * The order number LearnPress shows, e.g. `#000123`.
	 *
	 * @param \LP_Order $order The order.
	 */
	public static function order_number( \LP_Order $order ): string {
		return (string) $order->get_order_number();
	}

	/**
	 * LearnPress's label for an order status, e.g. "Pending".
	 *
	 * @param string $status The status without the `lp-` prefix.
	 */
	public static function order_status_label( string $status ): string {
		$label = class_exists( 'LP_Order' ) ? \LP_Order::get_status_label( $status ) : '';

		return '' !== $label ? $label : ucfirst( $status );
	}

	/**
	 * The order's customer: the account's name and email, or the guest checkout email.
	 *
	 * @param \LP_Order $order The order.
	 * @return array{user_id: int, name: string, email: string}
	 */
	public static function order_customer( \LP_Order $order ): array {
		$user_id = (int) ( $order->get_users()[0] ?? 0 );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( $user instanceof \WP_User ) {
			return array(
				'user_id' => $user_id,
				'name'    => (string) $user->display_name,
				'email'   => (string) $user->user_email,
			);
		}

		return array(
			'user_id' => 0,
			'name'    => '',
			'email'   => (string) $order->get_checkout_email(),
		);
	}

	/**
	 * The course ids in LearnPress's cart, before checkout turns them into an order.
	 *
	 * @return array<int, int>
	 */
	public static function cart_course_ids(): array {
		if ( ! class_exists( 'LearnPress' ) ) {
			return array();
		}

		$ids = array();

		foreach ( \LearnPress::instance()->get_cart()->get_items() as $item ) {
			$id = is_array( $item ) ? absint( $item['item_id'] ?? 0 ) : 0;

			if ( $id > 0 && self::course_post_type() === get_post_type( $id ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * The order LearnPress will reuse for this checkout: a failed attempt leaves it in the session.
	 */
	public static function awaiting_order_id(): int {
		if ( ! class_exists( 'LearnPress' ) || null === \LearnPress::instance()->session ) {
			return 0;
		}

		return absint( \LearnPress::instance()->session->get( 'order_awaiting_payment', 0 ) );
	}

	/**
	 * LearnPress › Settings › Payments › ifthenpay, where Enable, Title and Description live.
	 */
	public static function payments_settings_url(): string {
		return add_query_arg(
			array(
				'page'    => 'learn-press-settings',
				'tab'     => 'payments',
				'section' => Plugin::GATEWAY_ID,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Whether LearnPress's own Enable switch is on for ifthenpay.
	 */
	public static function gateway_enabled(): bool {
		$options = get_option( 'learn_press_' . Plugin::GATEWAY_ID, array() );

		return is_array( $options ) && 'yes' === ( $options['enable'] ?? 'no' );
	}
}
