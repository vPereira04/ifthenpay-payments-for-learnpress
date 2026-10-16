<?php
/**
 * Inbound webhook endpoint.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Api\Webhook;

use Ifthenpay\LearnPress\Plugin;
use Ifthenpay\LearnPress\Repository\SettingsRepository;
use Ifthenpay\LearnPress\Repository\TransactionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The REST route ifthenpay calls back into, and the only path that marks a payment paid.
 * LearnPress's own gateway route is for subscriptions only, so I bring my own.
 */
class WebhookController {

	public const REST_NAMESPACE = 'iftp-lp/v1';
	public const REST_ROUTE     = '/callback';

	// Our own args, prefixed; `status` is ifthenpay's, appended on failure notices.
	public const ARG_REF = 'iftp_lp_ref';
	public const ARG_APK = 'iftp_lp_apk';
	public const ARG_VAL = 'iftp_lp_val';
	public const ARG_MTD = 'iftp_lp_mtd';
	public const ARG_REQ = 'iftp_lp_req';

	/**
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Anti-phishing + amount validator.
	 *
	 * @var WebhookValidator
	 */
	private WebhookValidator $validator;

	/**
	 * Sets up the controller.
	 *
	 * @param TransactionStore   $transactions Transactions storage.
	 * @param SettingsRepository $settings     Settings storage.
	 */
	public function __construct( TransactionStore $transactions, SettingsRepository $settings ) {
		$this->transactions = $transactions;
		$this->validator    = new WebhookValidator( $settings );
	}

	/**
	 * Hooks the route.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Registers `GET /wp-json/iftp-lp/v1/callback`. It's public on purpose: the anti-phishing key authenticates it.
	 */
	public function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The callback URL template registered with ifthenpay. rest_url() also covers plain permalinks (`?rest_route=`).
	 */
	public static function url(): string {
		$base = rest_url( self::REST_NAMESPACE . self::REST_ROUTE );

		return $base . ( false === strpos( $base, '?' ) ? '?' : '&' )
			. self::ARG_REF . '=[ORDER_ID]&'
			. self::ARG_APK . '=[ANTI_PHISHING_KEY]&'
			. self::ARG_VAL . '=[AMOUNT]&'
			. self::ARG_MTD . '=[PAYMENT_METHOD]&'
			. self::ARG_REQ . '=[REQUEST_ID]';
	}

	/**
	 * Validates and processes a callback. The anti-phishing key is checked before anything branches or changes.
	 *
	 * @param \WP_REST_Request $request The callback request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$payload = WebhookPayload::from_request( $request );

		if ( '' === $payload->ref() ) {
			return self::respond( 400, 'Missing reference' );
		}

		$record = $this->transactions->get( $payload->ref() );

		if ( null === $record ) {
			return self::respond( 404, 'Unknown reference' );
		}

		// Failure notices carry the key too; without this a guessed ref could cancel someone's payment.
		if ( ! $this->validator->key_matches( $payload, $record ) ) {
			return self::respond( 403, 'Invalid anti-phishing key' );
		}

		$payments = Plugin::instance()->payments();

		if ( '' !== $payload->status() ) {
			$payments->record_failure( $record, in_array( $payload->status(), array( 'cancel', 'cancelled', 'canceled' ), true ) );

			return self::respond( 200, 'OK' );
		}

		if ( ! $this->validator->amount_matches( $payload, $record ) ) {
			return self::respond( 403, 'Amount mismatch' );
		}

		// A repeat call finds the row already paid and does nothing; still a 200, so ifthenpay stops retrying.
		$payments->complete_paid( $record, $payload->method(), $payload->request_id() );

		return self::respond( 200, 'OK' );
	}

	/**
	 * A small JSON answer; ifthenpay only reads the status code.
	 *
	 * @param int    $status  The HTTP status code.
	 * @param string $message A short machine-read message.
	 */
	private static function respond( int $status, string $message ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'message' => $message ), $status );
	}
}
