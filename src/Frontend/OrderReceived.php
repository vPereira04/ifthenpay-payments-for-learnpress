<?php
/**
 * The student's side after checkout: order-received status, "Pay now" and "Try again".
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Frontend;

use Ifthenpay\LearnPress\Host;
use Ifthenpay\LearnPress\Payments\PaymentService;
use Ifthenpay\LearnPress\Plugin;
use Ifthenpay\LearnPress\Repository\TransactionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LearnPress owns the page after checkout, and it empties the cart before the student has paid. So its
 * order-received page gets a small status block for ifthenpay orders, which watches for the payment and reloads
 * once it's in. A failed, cancelled or expired attempt gets "Try again", and My Account › Orders gets "Pay now".
 * Both go through the same one-live-link rule as checkout.
 *
 * @phpstan-import-type Transaction from TransactionStore
 */
class OrderReceived {

	public const PAY_VAR    = 'iftp_lp_pay_order';
	public const KEY_VAR    = 'iftp_lp_key';
	public const NOTICE_VAR = 'iftp_lp_notice';

	private const PAY_NONCE    = 'iftp_lp_pay_order';
	private const STATUS_NONCE = 'iftp_lp_order_status';
	private const NOTICE_TTL   = 5 * MINUTE_IN_SECONDS;

	private const SCRIPT_HANDLE = 'iftp-lp-order-status';

	/**
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Sets up the student-facing hooks.
	 *
	 * @param TransactionStore $transactions Transactions storage.
	 */
	public function __construct( TransactionStore $transactions ) {
		$this->transactions = $transactions;
	}

	/**
	 * Hooks the order-received block, the account action, the pay handler and the status check.
	 */
	public function register(): void {
		add_action( 'learn-press/order/after-received-order-message', array( $this, 'render_status' ) );
		add_filter( 'learn-press/profile-order-actions', array( $this, 'add_pay_action' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'handle_pay_request' ) );
		add_action( 'wp_ajax_iftp_lp_order_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_nopriv_iftp_lp_order_status', array( $this, 'ajax_status' ) );
	}

	/**
	 * The URL that sends a student on to pay a pending order: the open link when there is one, otherwise a new one.
	 *
	 * @param \LP_Order $order The pending order.
	 */
	public static function pay_url( \LP_Order $order ): string {
		return add_query_arg(
			array(
				self::PAY_VAR => (int) $order->get_id(),
				self::KEY_VAR => rawurlencode( (string) $order->get_order_key() ),
				'_wpnonce'    => wp_create_nonce( self::PAY_NONCE ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Prints the ifthenpay block under LearnPress's "Thank you" message.
	 *
	 * @param mixed $order_id The order LearnPress is showing.
	 */
	public function render_status( $order_id ): void {
		$order = Host::order( absint( $order_id ) );

		if ( null === $order || ! self::is_ours( $order ) || $order->has_status( array( 'completed', 'refunded' ) ) ) {
			return;
		}

		$attempts = $this->transactions->for_order( (int) $order->get_id() );
		$latest   = $attempts[0] ?? null;
		$status   = null !== $latest ? $latest['status'] : '';
		$is_open  = $order->has_status( array( 'pending' ) );
		$live     = $is_open && null !== $latest && TransactionStore::STATUS_PENDING === $status && '' !== $latest['payment_url'] && $latest['expires_at'] > time();
		$notice   = $this->take_notice();

		// Every status but paid can still change (a late Multibanco payment), so the block keeps watching while the order is open.
		if ( $is_open ) {
			$this->enqueue_watcher( $order, $status );
		}
		?>
		<section class="iftp-lp-order-status iftp-lp-order-status--<?php echo esc_attr( '' !== $status ? $status : 'none' ); ?>" aria-labelledby="iftp-lp-order-status-title" data-iftp-order-status>
			<h2 id="iftp-lp-order-status-title" class="iftp-lp-order-status__title"><?php esc_html_e( 'ifthenpay payment', 'ifthenpay-payments-for-learnpress' ); ?></h2>

			<?php if ( '' !== $notice ) : ?>
				<p class="iftp-lp-order-status__notice" role="alert"><?php echo wp_kses_post( $notice ); ?></p>
			<?php endif; ?>

			<p class="iftp-lp-order-status__message" role="status" data-iftp-order-status-message><?php echo esc_html( self::status_message( $status, $live, $is_open ) ); ?></p>

			<?php if ( $live && null !== $latest ) : ?>
				<p class="iftp-lp-order-status__expiry">
					<?php
					/* translators: %s: date and time the payment link expires */
					echo esc_html( sprintf( __( 'Your payment link stays open until %s.', 'ifthenpay-payments-for-learnpress' ), wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $latest['expires_at'] ) ) );
					?>
				</p>
				<p class="iftp-lp-order-status__actions">
					<a class="iftp-lp-order-status__pay" href="<?php echo esc_url( self::pay_url( $order ) ); ?>"><?php esc_html_e( 'Continue payment', 'ifthenpay-payments-for-learnpress' ); ?></a>
				</p>
			<?php elseif ( $is_open && TransactionStore::STATUS_PAID !== $status ) : ?>
				<p class="iftp-lp-order-status__actions">
					<a class="iftp-lp-order-status__retry" href="<?php echo esc_url( self::pay_url( $order ) ); ?>"><?php esc_html_e( 'Try again', 'ifthenpay-payments-for-learnpress' ); ?></a>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Adds "Pay now" to My Account › Orders for the student's own pending ifthenpay order.
	 *
	 * @param mixed $actions  LearnPress's actions for the order row.
	 * @param mixed $order_id The order.
	 * @return mixed
	 */
	public function add_pay_action( $actions, $order_id ) {
		$order = is_array( $actions ) ? Host::order( absint( $order_id ) ) : null;

		if ( null === $order || ! self::is_ours( $order ) || ! $order->has_status( array( 'pending' ) ) ) {
			return $actions;
		}

		$actions['iftp-lp-pay'] = array(
			'url'  => self::pay_url( $order ),
			'text' => __( 'Pay now', 'ifthenpay-payments-for-learnpress' ),
		);

		return $actions;
	}

	/**
	 * "Pay now" / "Try again" / "Continue payment": checks the order key, then goes to the live link or a new one.
	 * Anything that stops it comes back to the order-received page with the reason.
	 */
	public function handle_pay_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is verified right below; these only pick the order.
		if ( ! isset( $_GET[ self::PAY_VAR ] ) ) {
			return;
		}

		$order_id = absint( wp_unslash( $_GET[ self::PAY_VAR ] ) );
		$key      = sanitize_text_field( wp_unslash( $_GET[ self::KEY_VAR ] ?? '' ) );
		$nonce    = sanitize_key( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = Host::order( $order_id );

		// The order key is what LearnPress itself trusts on its order-received page.
		if ( null === $order || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) || false === wp_verify_nonce( $nonce, self::PAY_NONCE ) || ! self::is_ours( $order ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$payments = Plugin::instance()->payments();
		$result   = $payments->link_for_order( $order, TransactionStore::SOURCE_ACCOUNT );

		if ( in_array( $result['status'], array( PaymentService::RESULT_CREATED, PaymentService::RESULT_REUSED ), true ) && '' !== $result['url'] ) {
			add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_payment_hosts' ) );
			wp_safe_redirect( $result['url'] );
			exit;
		}

		$token = strtolower( wp_generate_password( 16, false, false ) );

		set_transient( self::NOTICE_VAR . '_' . $token, $payments->refusal_message( $result ), self::NOTICE_TTL );

		wp_safe_redirect( add_query_arg( self::NOTICE_VAR, $token, (string) $order->get_checkout_order_received_url() ) );
		exit;
	}

	/**
	 * Lets wp_safe_redirect() go to ifthenpay's hosted page. The links are short URLs on pinpay.pt.
	 *
	 * @param mixed $hosts The allowed hosts.
	 * @return array<int, string>
	 */
	public static function allow_payment_hosts( $hosts ): array {
		$hosts = is_array( $hosts ) ? array_values( array_filter( $hosts, 'is_string' ) ) : array();

		return array_merge( $hosts, array( 'pinpay.pt', 'www.pinpay.pt', 'ifthenpay.com', 'www.ifthenpay.com', 'gateway.ifthenpay.com', 'api.ifthenpay.com' ) );
	}

	/**
	 * The order-received page's status check: the latest attempt's status and whether the order completed.
	 * It only reports; the callback is what changes anything.
	 */
	public function ajax_status(): void {
		check_ajax_referer( self::STATUS_NONCE, 'nonce' );

		$order_id = absint( wp_unslash( $_POST['order_id'] ?? 0 ) );
		$key      = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
		$order    = Host::order( $order_id );

		if ( null === $order || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			wp_send_json_error( array(), 404 );
		}

		$attempts = $this->transactions->for_order( $order_id );

		wp_send_json_success(
			array(
				'status'    => isset( $attempts[0] ) ? $attempts[0]['status'] : '',
				'completed' => $order->has_status( array( 'completed' ) ),
			)
		);
	}

	/**
	 * Whether this is an ifthenpay order: LearnPress's payment method says so.
	 *
	 * @param \LP_Order $order The order.
	 */
	private static function is_ours( \LP_Order $order ): bool {
		return Plugin::GATEWAY_ID === $order->get_data( 'payment_method', '' );
	}

	/**
	 * Loads the status watcher, which reloads the page once the payment is in or the attempt changes.
	 *
	 * @param \LP_Order $order  The open order.
	 * @param string    $status The latest attempt's status, or ''.
	 */
	private function enqueue_watcher( \LP_Order $order, string $status ): void {
		$script = 'assets/js/order-status.js';

		wp_enqueue_script( self::SCRIPT_HANDLE, IFTP_LP_URL . $script, array(), Plugin::asset_version( $script ), true );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'iftpLpOrderStatus',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::STATUS_NONCE ),
				'orderId' => (int) $order->get_id(),
				'key'     => (string) $order->get_order_key(),
				'status'  => $status,
			)
		);
	}

	/**
	 * Reads and deletes the one-time notice a refused "Pay now" left for this page.
	 */
	private function take_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a random token that only picks a short-lived message; nothing changes but its deletion.
		$token = isset( $_GET[ self::NOTICE_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_VAR ] ) ) : '';

		if ( '' === $token ) {
			return '';
		}

		$notice = get_transient( self::NOTICE_VAR . '_' . $token );

		delete_transient( self::NOTICE_VAR . '_' . $token );

		return is_string( $notice ) ? $notice : '';
	}

	/**
	 * What the block says for the latest attempt.
	 *
	 * @param string $status  The latest attempt's status, or '' for none.
	 * @param bool   $live    Whether its link can still be paid.
	 * @param bool   $is_open Whether the order is still pending.
	 */
	private static function status_message( string $status, bool $live, bool $is_open ): string {
		if ( ! $is_open ) {
			return __( 'This order is no longer waiting for a payment.', 'ifthenpay-payments-for-learnpress' );
		}

		switch ( $status ) {
			case TransactionStore::STATUS_PAID:
				return __( 'Payment received. Your order is being completed.', 'ifthenpay-payments-for-learnpress' );
			case TransactionStore::STATUS_CANCELLED:
				return __( 'The payment was cancelled, so your order is still waiting for it.', 'ifthenpay-payments-for-learnpress' );
			case TransactionStore::STATUS_FAILED:
				return __( 'The payment didn\'t go through, so your order is still waiting for it.', 'ifthenpay-payments-for-learnpress' );
			case TransactionStore::STATUS_EXPIRED:
				return __( 'The payment link expired before it was paid.', 'ifthenpay-payments-for-learnpress' );
			case TransactionStore::STATUS_PENDING:
				return $live
					? __( 'We\'re waiting for ifthenpay to confirm your payment. Multibanco and Payshop payments are confirmed a little after you pay. This page updates on its own.', 'ifthenpay-payments-for-learnpress' )
					: __( 'Your order is waiting for its payment.', 'ifthenpay-payments-for-learnpress' );
			default:
				return __( 'Your order is waiting for its payment.', 'ifthenpay-payments-for-learnpress' );
		}
	}
}
