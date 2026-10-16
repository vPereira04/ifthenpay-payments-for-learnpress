<?php
/**
 * Parsed inbound webhook payload.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Api\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ifthenpay callback's query params, parsed into one typed shape.
 */
final class WebhookPayload {

	/**
	 * The transaction reference.
	 *
	 * @var string
	 */
	private string $ref;

	/**
	 * The anti-phishing key.
	 *
	 * @var string
	 */
	private string $apk;

	/**
	 * The reported paid amount.
	 *
	 * @var float
	 */
	private float $amount;

	/**
	 * The payment method used.
	 *
	 * @var string
	 */
	private string $method;

	/**
	 * The ifthenpay request id.
	 *
	 * @var string
	 */
	private string $request_id;

	/**
	 * A failure notice's status (`cancelled`, `error`, …), or '' for a paid callback.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Sets up the payload.
	 *
	 * @param string $ref        The transaction reference.
	 * @param string $apk        The anti-phishing key.
	 * @param float  $amount     The reported paid amount.
	 * @param string $method     The payment method used.
	 * @param string $request_id The ifthenpay request id.
	 * @param string $status     A failure notice's status, or ''.
	 */
	private function __construct( string $ref, string $apk, float $amount, string $method, string $request_id, string $status ) {
		$this->ref        = $ref;
		$this->apk        = $apk;
		$this->amount     = $amount;
		$this->method     = $method;
		$this->request_id = $request_id;
		$this->status     = $status;
	}

	/**
	 * Builds a payload from the REST request. The REST server already unslashed the params.
	 *
	 * @param \WP_REST_Request $request The callback request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function from_request( \WP_REST_Request $request ): self {
		$text = static function ( string $key ) use ( $request ): string {
			$value = $request->get_param( $key );

			return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		};

		// Base64 alphabet only. A '+' that a non-encoded query turned into a space comes back here.
		$raw_apk = $request->get_param( WebhookController::ARG_APK );
		$apk     = is_string( $raw_apk ) ? filter_var( $raw_apk, FILTER_VALIDATE_REGEXP, array( 'options' => array( 'regexp' => '#^[A-Za-z0-9+/= ]+$#' ) ) ) : false;
		$apk     = false === $apk ? '' : str_replace( ' ', '+', (string) $apk );
		$amount  = str_replace( ',', '.', $text( WebhookController::ARG_VAL ) );

		return new self(
			$text( WebhookController::ARG_REF ),
			$apk,
			is_numeric( $amount ) ? (float) $amount : -1.0,
			strtoupper( $text( WebhookController::ARG_MTD ) ),
			$text( WebhookController::ARG_REQ ),
			sanitize_key( $text( 'status' ) )
		);
	}

	/**
	 * The transaction reference.
	 */
	public function ref(): string {
		return $this->ref;
	}

	/**
	 * The anti-phishing key.
	 */
	public function apk(): string {
		return $this->apk;
	}

	/**
	 * The reported paid amount, or -1 when it wasn't a number.
	 */
	public function amount(): float {
		return $this->amount;
	}

	/**
	 * The payment method used.
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * The ifthenpay request id.
	 */
	public function request_id(): string {
		return $this->request_id;
	}

	/**
	 * A failure notice's status, or '' for a paid callback.
	 */
	public function status(): string {
		return $this->status;
	}
}
