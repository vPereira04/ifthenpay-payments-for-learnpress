<?php
/**
 * The ifthenpay payment flow for LearnPress orders.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Payments;

use Ifthenpay\LearnPress\Api\IfthenpayClient;
use Ifthenpay\LearnPress\Api\IfthenpayPayload;
use Ifthenpay\LearnPress\Host;
use Ifthenpay\LearnPress\Plugin;
use Ifthenpay\LearnPress\Repository\SettingsRepository;
use Ifthenpay\LearnPress\Repository\TransactionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates links for LearnPress orders (one live link per customer and course), completes orders when ifthenpay
 * confirms, and refunds. Checkout, "Pay now", "Try again", the payment email and "New payment" all go through here.
 *
 * @phpstan-import-type Transaction from TransactionStore
 * @phpstan-type LinkResult array{status: string, url: string, record: Transaction|null}
 */
class PaymentService {

	public const RESULT_CREATED     = 'created';
	public const RESULT_REUSED      = 'reused';
	public const RESULT_IN_PROGRESS = 'in_progress';
	public const RESULT_BUSY        = 'busy';
	public const RESULT_FAILED      = 'failed';
	public const RESULT_NOT_READY   = 'not_ready';
	public const RESULT_NOT_PAYABLE = 'not_payable';

	// Prefixed, since a bare `ref` is what affiliate plugins read.
	public const RETURN_VAR = 'iftp_lp_pay';
	public const RETURN_REF = 'iftp_lp_txn_ref';
	public const RETURN_SIG = 'iftp_lp_sig';
	public const RETURN_TXN = 'iftp_lp_txn_id';

	// What ifthenpay's refund API takes back; Multibanco and Payshop references can't be refunded through it.
	private const REFUNDABLE_METHODS = array( 'MBWAY', 'CCARD', 'GOOGLE', 'APPLE' );

	private const AMOUNT_TOLERANCE = 0.005;

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Ifthenpay API client.
	 *
	 * @var IfthenpayClient
	 */
	private IfthenpayClient $client;

	/**
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Sets up the service.
	 *
	 * @param SettingsRepository $settings     Settings storage.
	 * @param IfthenpayClient    $client       Ifthenpay API client.
	 * @param TransactionStore   $transactions Transactions storage.
	 */
	public function __construct( SettingsRepository $settings, IfthenpayClient $client, TransactionStore $transactions ) {
		$this->settings     = $settings;
		$this->client       = $client;
		$this->transactions = $transactions;
	}

	/**
	 * Whether a link can be created at all: connected, a Gateway Key, an enabled method, and a euro store.
	 */
	public function is_ready(): bool {
		return $this->settings->is_connected()
			&& '' !== $this->settings->get_gateway_key()
			&& array() !== $this->settings->get_enabled_methods()
			&& Host::is_eur();
	}

	/**
	 * The link a pending order should be paid through. ifthenpay can't revoke a link, so a customer must never hold
	 * two payable ones for the same course: I reuse the live one, refuse while another is open, and only then create one.
	 *
	 * @param \LP_Order $order         The pending LearnPress order.
	 * @param string    $source        One of TransactionStore::SOURCE_*.
	 * @param string    $customer_name A name to store when the order has none (a guest).
	 * @return array{status: string, url: string, record: array<string, mixed>|null}
	 * @phpstan-return LinkResult
	 */
	public function link_for_order( \LP_Order $order, string $source, string $customer_name = '' ): array {
		if ( ! $this->is_ready() ) {
			return self::result( self::RESULT_NOT_READY );
		}

		$amount = round( (float) $order->get_total(), 2 );

		if ( $amount <= 0 || ! $order->has_status( array( 'pending' ) ) ) {
			return self::result( self::RESULT_NOT_PAYABLE );
		}

		$courses  = Host::order_courses( $order );
		$customer = Host::order_customer( $order );
		$lock     = self::lock_key( (int) $order->get_id(), $customer['user_id'], $customer['email'], array_keys( $courses ) );

		// Two submits at the same moment would both find nothing live and both create a link.
		if ( ! Locks::claim( $lock ) ) {
			return self::result( self::RESULT_BUSY );
		}

		try {
			$now = time();
			$own = $this->transactions->live_for_order( (int) $order->get_id(), $now );

			if ( null !== $own ) {
				// A changed total (a coupon, a price edit) means the open link charges the wrong amount.
				return abs( $own['amount'] - $amount ) <= self::AMOUNT_TOLERANCE
					? self::result( self::RESULT_REUSED, $own['payment_url'], $own )
					: self::result( self::RESULT_IN_PROGRESS, $own['payment_url'], $own );
			}

			foreach ( $this->transactions->live_for_customer( $customer['user_id'], $customer['email'], array_keys( $courses ), (int) $order->get_id(), $now ) as $other ) {
				$other_order = Host::order( $other['order_id'] );

				// A link on an order that got completed or cancelled some other way doesn't block this one.
				if ( null !== $other_order && $other_order->has_status( array( 'pending' ) ) ) {
					return self::result( self::RESULT_IN_PROGRESS, $other['payment_url'], $other );
				}
			}

			if ( '' === $customer['name'] ) {
				$customer['name'] = $customer_name;
			}

			return $this->create_attempt( $order, $amount, $courses, $customer, $source );
		} finally {
			Locks::release( $lock );
		}
	}

	/**
	 * At checkout, before LearnPress creates the order: an open link the student already holds for a course in the cart.
	 * Refusing here means a second checkout doesn't leave a pending order behind that can never get a link.
	 *
	 * @param string $checkout_email The email typed at checkout, for a guest.
	 * @return array{status: string, url: string, record: array<string, mixed>|null}|null The refusal, or null to go ahead.
	 * @phpstan-return LinkResult|null
	 */
	public function in_progress_for_checkout( string $checkout_email ): ?array {
		$course_ids = Host::cart_course_ids();
		$user_id    = get_current_user_id();
		$email      = '';

		// Same customer LearnPress will put on the order: the account, or the guest's email (an account with that email wins).
		if ( $user_id <= 0 && '' !== $checkout_email ) {
			$user    = get_user_by( 'email', $checkout_email );
			$user_id = $user instanceof \WP_User ? $user->ID : 0;
			$email   = $user instanceof \WP_User ? '' : $checkout_email;
		}

		// The session's own order is excluded: its live link gets reused, not refused.
		foreach ( $this->transactions->live_for_customer( $user_id, $email, $course_ids, Host::awaiting_order_id(), time() ) as $other ) {
			$other_order = Host::order( $other['order_id'] );

			if ( null !== $other_order && $other_order->has_status( array( 'pending' ) ) ) {
				return self::result( self::RESULT_IN_PROGRESS, $other['payment_url'], $other );
			}
		}

		return null;
	}

	/**
	 * The customer-facing reason a link wasn't created, with the open payment's link when there is one.
	 *
	 * @param array<string, mixed> $result From link_for_order().
	 * @phpstan-param LinkResult $result
	 * @return string Safe HTML.
	 */
	public function refusal_message( array $result ): string {
		switch ( $result['status'] ) {
			case self::RESULT_IN_PROGRESS:
				$record = $result['record'];
				$text   = null !== $record && $record['expires_at'] > 0
					/* translators: %s: date and time the open payment expires */
					? sprintf( __( 'You already have a payment in progress for this course. It stays open until %s.', 'ifthenpay-payments-for-learnpress' ), wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $record['expires_at'] ) )
					: __( 'You already have a payment in progress for this course.', 'ifthenpay-payments-for-learnpress' );

				if ( '' === $result['url'] ) {
					return esc_html( $text );
				}

				return esc_html( $text ) . ' <a class="iftp-lp-continue-payment" href="' . esc_url( $result['url'] ) . '">' . esc_html__( 'Continue payment', 'ifthenpay-payments-for-learnpress' ) . '</a>';
			case self::RESULT_BUSY:
				return esc_html__( 'Your payment is being prepared. Please wait a few seconds and try again.', 'ifthenpay-payments-for-learnpress' );
			case self::RESULT_NOT_READY:
				return esc_html__( 'ifthenpay payments are not available right now. Please choose another payment method.', 'ifthenpay-payments-for-learnpress' );
			case self::RESULT_NOT_PAYABLE:
				return esc_html__( 'This order can no longer be paid.', 'ifthenpay-payments-for-learnpress' );
			default:
				return esc_html__( 'We could not reach ifthenpay to create your payment. Please try again in a moment.', 'ifthenpay-payments-for-learnpress' );
		}
	}

	/**
	 * Resolves a paid callback. The atomic mark_paid() means only one request ever completes the order.
	 *
	 * @param array<string, mixed> $record     The row the callback resolved.
	 * @param string               $method     The method ifthenpay reported.
	 * @param string               $request_id The ifthenpay request id.
	 * @phpstan-param Transaction $record
	 */
	public function complete_paid( array $record, string $method, string $request_id ): void {
		if ( ! $this->transactions->mark_paid( $record['ref'], $method, $request_id ) ) {
			return;
		}

		$order = Host::order( $record['order_id'] );

		if ( null === $order ) {
			return;
		}

		$order->add_note(
			sprintf(
				/* translators: 1: payment reference, 2: payment method, 3: ifthenpay request ID */
				__( 'ifthenpay payment received. Reference: %1$s. Method: %2$s. Request ID: %3$s.', 'ifthenpay-payments-for-learnpress' ),
				$record['ref'],
				'' !== $method ? $method : '—',
				'' !== $request_id ? $request_id : '—'
			)
		);

		$this->complete_order( $order, '' !== $request_id ? $request_id : $record['ref'] );
	}

	/**
	 * Records a failed or cancelled attempt, from a callback or the payer's return. Paid and refunded rows never move.
	 *
	 * @param array<string, mixed> $record    The attempt.
	 * @param bool                 $cancelled Whether the payer cancelled, rather than the payment failing.
	 * @phpstan-param Transaction $record
	 */
	public function record_failure( array $record, bool $cancelled ): void {
		$changed = $cancelled ? $this->transactions->mark_cancelled( $record['ref'] ) : $this->transactions->mark_failed( $record['ref'] );
		$order   = $changed ? Host::order( $record['order_id'] ) : null;

		if ( null === $order ) {
			return;
		}

		$order->add_note(
			$cancelled
				/* translators: %s: payment reference */
				? sprintf( __( 'ifthenpay payment %s was cancelled. The order stays pending, so the student can try again.', 'ifthenpay-payments-for-learnpress' ), $record['ref'] )
				/* translators: %s: payment reference */
				: sprintf( __( 'ifthenpay payment %s failed. The order stays pending, so the student can try again.', 'ifthenpay-payments-for-learnpress' ), $record['ref'] )
		);
	}

	/**
	 * Refunds an order's ifthenpay payment through ifthenpay. Throws, so LearnPress stops before marking it refunded.
	 *
	 * @param \LP_Order $order  The order LearnPress is refunding.
	 * @param float     $amount The amount; 0 means whatever is left.
	 * @throws \Exception When there's nothing to refund or ifthenpay refuses.
	 */
	public function refund( \LP_Order $order, float $amount ): void {
		$record = null;

		foreach ( $this->transactions->for_order( (int) $order->get_id() ) as $candidate ) {
			if ( in_array( $candidate['status'], TransactionStore::PROTECTED_STATUSES, true ) && '' !== $candidate['request_id'] ) {
				$record = $candidate;
				break;
			}
		}

		if ( null === $record ) {
			throw new \Exception( esc_html__( 'There is no ifthenpay payment with a request ID on this order to refund.', 'ifthenpay-payments-for-learnpress' ) );
		}

		if ( ! in_array( strtoupper( $record['pay_method'] ), self::refundable_methods(), true ) ) {
			throw new \Exception(
				esc_html(
					sprintf(
						/* translators: %s: payment method, e.g. "MB" */
						__( 'ifthenpay cannot refund %s payments automatically. Refund the student another way, then mark the order as refunded.', 'ifthenpay-payments-for-learnpress' ),
						'' !== $record['pay_method'] ? $record['pay_method'] : __( 'unknown', 'ifthenpay-payments-for-learnpress' )
					)
				)
			);
		}

		$left   = round( $record['amount'] - $record['refunded_amount'], 2 );
		$amount = $amount > 0 ? round( $amount, 2 ) : $left;

		if ( $amount <= 0 || $amount > $left + self::AMOUNT_TOLERANCE ) {
			throw new \Exception( esc_html__( 'The refund is more than what is left of the ifthenpay payment.', 'ifthenpay-payments-for-learnpress' ) );
		}

		if ( ! $this->client->refund( $this->settings->get_backoffice_key(), $record['request_id'], $amount ) ) {
			$error = $this->client->get_last_error();

			throw new \Exception(
				'insufficient_funds' === $error
					? esc_html__( 'ifthenpay refused the refund: there are not enough funds on the account yet.', 'ifthenpay-payments-for-learnpress' )
					/* translators: %s: the error ifthenpay returned */
					: esc_html( sprintf( __( 'ifthenpay refused the refund: %s', 'ifthenpay-payments-for-learnpress' ), $error ) )
			);
		}

		$this->transactions->record_refund( $record['ref'], $amount );

		$order->add_note(
			sprintf(
				/* translators: 1: refunded amount, 2: payment reference */
				__( 'ifthenpay refunded %1$s € of payment %2$s.', 'ifthenpay-payments-for-learnpress' ),
				number_format_i18n( $amount, 2 ),
				$record['ref']
			)
		);
	}

	/**
	 * The title LearnPress shows for our gateway, from its own settings.
	 */
	public static function payment_method_title(): string {
		$options = get_option( 'learn_press_' . Plugin::GATEWAY_ID, array() );
		$title   = is_array( $options ) && is_string( $options['title'] ?? null ) ? $options['title'] : '';

		return '' !== $title ? $title : __( 'ifthenpay', 'ifthenpay-payments-for-learnpress' );
	}

	/**
	 * Signs a return URL's ref + outcome. The payer comes back through a plain GET, so there's no nonce; this proves
	 * I issued the URL, and that nobody flipped a success URL into a cancel one.
	 *
	 * @param string $ref    The transaction reference.
	 * @param string $status One of 'success', 'error', 'cancel'.
	 */
	public static function return_signature( string $ref, string $status ): string {
		return wp_hash( 'iftp_lp_return|' . $ref . '|' . $status );
	}

	/**
	 * The methods the refund API accepts.
	 *
	 * @return array<int, string>
	 */
	private static function refundable_methods(): array {
		$methods = apply_filters( 'iftp_lp_refundable_methods', self::REFUNDABLE_METHODS );

		return is_array( $methods ) ? array_values( array_map( 'strtoupper', array_filter( $methods, 'is_string' ) ) ) : self::REFUNDABLE_METHODS;
	}

	/**
	 * Inserts the attempt, asks ifthenpay for the link and stores it.
	 *
	 * @param \LP_Order                                        $order    The order.
	 * @param float                                            $amount   The amount to charge.
	 * @param array<int, string>                               $courses  Course id => title.
	 * @param array{user_id: int, name: string, email: string} $customer The customer.
	 * @param string                                           $source   One of TransactionStore::SOURCE_*.
	 * @phpstan-return LinkResult
	 */
	private function create_attempt( \LP_Order $order, float $amount, array $courses, array $customer, string $source ): array {
		$gateway_key = $this->settings->get_gateway_key();
		$ref         = $this->transactions->create_pending(
			array(
				'order_id'       => (int) $order->get_id(),
				'user_id'        => $customer['user_id'],
				'course_ids'     => array_keys( $courses ),
				'amount'         => $amount,
				'gateway_key'    => $gateway_key,
				'customer_name'  => $customer['name'],
				'customer_email' => $customer['email'],
				'source'         => $source,
			)
		);
		$record      = null !== $ref ? $this->transactions->get( $ref ) : null;

		if ( null === $ref || null === $record ) {
			return self::result( self::RESULT_FAILED );
		}

		// The last payable day, in the site's timezone; the link stops at its end.
		$last_day = current_datetime()->add( new \DateInterval( 'P' . $this->settings->get_expiry_days() . 'D' ) );
		$url      = $this->client->create_payment_link(
			$gateway_key,
			IfthenpayPayload::build_payment_payload(
				$ref,
				$amount,
				$this->settings->get_description(),
				$this->settings->get_enabled_methods(),
				array(
					'success' => self::return_url( 'success', $ref ),
					'error'   => self::return_url( 'error', $ref ),
					'cancel'  => self::return_url( 'cancel', $ref ),
				),
				IfthenpayPayload::locale_to_lang( determine_locale() ),
				$this->settings->get_default_method_position(),
				$last_day->format( 'Ymd' )
			)
		);

		if ( '' === $url ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- I log failed API calls on purpose, for admin troubleshooting.
			error_log( 'ifthenpay for LearnPress: creating the payment link failed - ' . $this->client->get_last_error() );

			return self::result( self::RESULT_FAILED, '', $record );
		}

		$this->transactions->record_payment_link( $ref, $url, $gateway_key, $last_day->setTime( 23, 59, 59 )->getTimestamp() );

		$order->add_note(
			/* translators: %s: payment reference */
			sprintf( __( 'ifthenpay payment link created. Reference: %s.', 'ifthenpay-payments-for-learnpress' ), $ref )
		);

		return self::result( self::RESULT_CREATED, $url, $this->transactions->get( $ref ) );
	}

	/**
	 * Completes a LearnPress order, which enrols its students. Only a pending or processing order can complete;
	 * anything else gets a note, since the money arrived after the order moved on.
	 *
	 * @param \LP_Order $order          The order.
	 * @param string    $transaction_id What LearnPress stores as `_transaction_id`.
	 */
	private function complete_order( \LP_Order $order, string $transaction_id ): void {
		if ( ! $order->has_status( array( 'pending', 'processing' ) ) ) {
			if ( ! $order->has_status( array( 'completed' ) ) ) {
				$order->add_note(
					sprintf(
						/* translators: %s: order status, e.g. "Cancelled" */
						__( 'ifthenpay received this payment after the order was set to %s. Check it, and complete the order by hand if the student should be enrolled.', 'ifthenpay-payments-for-learnpress' ),
						Host::order_status_label( (string) $order->get_status() )
					)
				);
			}

			return;
		}

		// A retry after a failed checkout may have run on an order made for another gateway.
		$order->set_data( 'payment_method', Plugin::GATEWAY_ID );
		$order->set_data( 'payment_method_title', self::payment_method_title() );
		$order->payment_complete( $transaction_id );
	}

	/**
	 * A return URL for one outcome. Only success gets the `[TRANSACTIONID]` placeholder, since error/cancel have nothing to look up.
	 *
	 * @param string $status One of 'success', 'error', 'cancel'.
	 * @param string $ref    The transaction reference.
	 */
	private static function return_url( string $status, string $ref ): string {
		$args = array(
			self::RETURN_VAR => $status,
			self::RETURN_REF => rawurlencode( $ref ),
			self::RETURN_SIG => self::return_signature( $ref, $status ),
		);

		if ( 'success' === $status ) {
			$args[ self::RETURN_TXN ] = '[TRANSACTIONID]';
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * The lock that keeps one customer from getting two links for the same courses at once.
	 *
	 * @param int             $order_id   The order, for a customer with no courses on it.
	 * @param int             $user_id    The customer's user id, or 0.
	 * @param string          $email      The guest's email.
	 * @param array<int, int> $course_ids The courses.
	 */
	private static function lock_key( int $order_id, int $user_id, string $email, array $course_ids ): string {
		sort( $course_ids );

		$who  = $user_id > 0 ? 'u' . $user_id : 'e' . strtolower( $email );
		$what = array() !== $course_ids ? implode( ',', $course_ids ) : 'o' . $order_id;

		return 'link|' . $who . '|' . $what;
	}

	/**
	 * A link result.
	 *
	 * @param string                    $status One of the RESULT_* constants.
	 * @param string                    $url    The payable link, or ''.
	 * @param array<string, mixed>|null $record The attempt, when there is one.
	 * @phpstan-param Transaction|null $record
	 * @phpstan-return LinkResult
	 */
	private static function result( string $status, string $url = '', ?array $record = null ): array {
		return array(
			'status' => $status,
			'url'    => $url,
			'record' => $record,
		);
	}
}
