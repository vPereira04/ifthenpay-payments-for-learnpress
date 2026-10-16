<?php
/**
 * The ifthenpay gateway LearnPress offers at checkout.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Gateway;

use Ifthenpay\LearnPress\Host;
use Ifthenpay\LearnPress\Payments\PaymentService;
use Ifthenpay\LearnPress\Plugin;
use Ifthenpay\LearnPress\Repository\TransactionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pay by Link for LearnPress orders. LearnPress builds this itself (no arguments), so the work lives in
 * PaymentService and this class only speaks LearnPress's gateway API.
 *
 * Its Payments › ifthenpay section holds the whole configuration: our account panel on top, LearnPress's own Enable,
 * Title and Description, then the Gateway Key, methods and hosted page (see Admin\SettingsSection).
 */
class IfthenpayGateway extends \LP_Gateway_Abstract {

	/**
	 * The gateway id; LearnPress reads its options from `learn_press_ifthenpay`.
	 *
	 * @var string
	 */
	public $id = Plugin::GATEWAY_ID;

	/**
	 * Sets up the admin titles and icon, then lets LearnPress load the saved settings.
	 */
	public function __construct() {
		$this->method_title       = __( 'ifthenpay', 'ifthenpay-payments-for-learnpress' );
		$this->method_description = __( 'Students pay on ifthenpay\'s secure page with Multibanco, MB WAY, cards, Apple Pay, Google Pay and more.', 'ifthenpay-payments-for-learnpress' );
		$this->icon               = IFTP_LP_URL . 'assets/img/logo-color.svg';

		parent::__construct();
	}

	/**
	 * The checkout title, from LearnPress's own field for this gateway. Same filter as LearnPress's own getter.
	 *
	 * @return string
	 */
	public function get_title() {
		return apply_filters( 'learn_press_gateway_title', (string) $this->settings->get( 'title', $this->default_title() ), $this->id );
	}

	/**
	 * The checkout description, from LearnPress's own field for this gateway. Same filter as LearnPress's own getter.
	 *
	 * @return string
	 */
	public function get_description() {
		return apply_filters( 'learn_press_gateway_description', (string) $this->settings->get( 'description', $this->default_description() ), $this->id );
	}

	/**
	 * LearnPress's own fields for this section, saved by LearnPress itself.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			array(
				'type' => 'title',
			),
			array(
				'title'   => __( 'Enable', 'ifthenpay-payments-for-learnpress' ),
				'id'      => '[enable]',
				'default' => 'no',
				'type'    => 'checkbox',
				'desc'    => __( 'Offer ifthenpay at checkout once it\'s connected and has payment methods enabled.', 'ifthenpay-payments-for-learnpress' ),
			),
			array(
				'title'   => __( 'Checkout title', 'ifthenpay-payments-for-learnpress' ),
				'id'      => '[title]',
				'default' => $this->default_title(),
				'type'    => 'text',
				'desc'    => __( 'What students see next to the payment option at checkout.', 'ifthenpay-payments-for-learnpress' ),
			),
			array(
				'title'   => __( 'Checkout description', 'ifthenpay-payments-for-learnpress' ),
				'id'      => '[description]',
				'default' => $this->default_description(),
				'type'    => 'textarea',
				'desc'    => __( 'Shown under the payment option at checkout.', 'ifthenpay-payments-for-learnpress' ),
			),
			array(
				'type' => 'sectionend',
			),
		);
	}

	/**
	 * Prints the section: our account panel, LearnPress's own fields, then the gateway configuration once connected.
	 */
	public function admin_option_settings(): void {
		$section = Plugin::instance()->settings_section();

		$section->render_connection();
		parent::admin_option_settings();
		$section->render_configuration();
	}

	/**
	 * The checkout text under the payment option.
	 */
	public function get_payment_form(): string {
		return wp_kses_post( wpautop( (string) $this->get_description() ) );
	}

	/**
	 * Runs before LearnPress creates the order. A last readiness check for paths that skip the available-gateways
	 * filter (LearnPress's REST checkout), and the "payment in progress" refusal, so a refused checkout leaves no order.
	 *
	 * @throws \Exception When ifthenpay can't take this payment.
	 */
	public function validate_fields(): bool {
		$payments = Plugin::instance()->payments();

		if ( ! $payments->is_ready() ) {
			throw new \Exception( esc_html__( 'ifthenpay payments are not available right now. Please choose another payment method.', 'ifthenpay-payments-for-learnpress' ) );
		}

		$email       = \LP_Request::get_param( 'checkout-email', '', 'email' );
		$in_progress = $payments->in_progress_for_checkout( is_string( $email ) ? $email : '' );

		if ( null !== $in_progress ) {
			throw new \Exception( wp_kses_post( $payments->refusal_message( $in_progress ) ) );
		}

		return true;
	}

	/**
	 * Sends the student to ifthenpay's page. LearnPress empties the cart as soon as this returns success, so the order
	 * stays pending until the callback; the order-received page shows where it stands.
	 *
	 * @param int|string $order_id The pending order LearnPress just created (or reused).
	 * @return array{result: string, redirect: string}
	 * @throws \Exception With the reason, which LearnPress shows at checkout.
	 */
	public function process_payment( $order_id ): array {
		$order = Host::order( absint( $order_id ) );

		if ( null === $order ) {
			throw new \Exception( esc_html__( 'We could not find your order. Please try again.', 'ifthenpay-payments-for-learnpress' ) );
		}

		// After a failed checkout LearnPress reuses the session's order without rebuilding it, so it can still carry another gateway.
		if ( Plugin::GATEWAY_ID !== $order->get_data( 'payment_method', '' ) ) {
			$order->set_data( 'payment_method', Plugin::GATEWAY_ID );
			$order->set_data( 'payment_method_title', $this->get_title() );
			$order->save();
		}

		$payments = Plugin::instance()->payments();
		$result   = $payments->link_for_order( $order, TransactionStore::SOURCE_CHECKOUT );

		if ( in_array( $result['status'], array( PaymentService::RESULT_CREATED, PaymentService::RESULT_REUSED ), true ) && '' !== $result['url'] ) {
			return array(
				'result'   => 'success',
				'redirect' => $result['url'],
			);
		}

		throw new \Exception( wp_kses_post( $payments->refusal_message( $result ) ) );
	}

	/**
	 * Refunds through ifthenpay. It has to be declared here: LearnPress only offers refunds for gateways whose own class defines refund().
	 *
	 * @param \LP_Order|mixed $lp_order The order being refunded.
	 * @param float           $amount   The amount, or 0 for the rest.
	 * @param string          $note     The admin's note (LearnPress keeps it on the order).
	 * @throws \Exception When ifthenpay can't refund it.
	 */
	public function refund( $lp_order, float $amount = 0, string $note = '' ): void {
		if ( ! $lp_order instanceof \LP_Order ) {
			throw new \Exception( esc_html__( 'Invalid order.', 'ifthenpay-payments-for-learnpress' ) );
		}

		Plugin::instance()->payments()->refund( $lp_order, $amount );
	}

	/**
	 * The checkout title until the admin sets one.
	 */
	private function default_title(): string {
		return __( 'ifthenpay', 'ifthenpay-payments-for-learnpress' );
	}

	/**
	 * The checkout description until the admin sets one.
	 */
	private function default_description(): string {
		return __( 'Pay securely on ifthenpay\'s page with Multibanco, MB WAY, card, Apple Pay, Google Pay and more.', 'ifthenpay-payments-for-learnpress' );
	}
}
