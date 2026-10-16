<?php
/**
 * Ifthenpay HTTP API client.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the ifthenpay HTTP API.
 */
class IfthenpayClient {

	private const API_BASE = 'https://api.ifthenpay.com';

	// Not confirmed with ifthenpay yet: the other integrations' naming pattern. Both are filterable.
	public const GATEWAY_TYPE = 'Forminator';
	public const CALLBACK_CMS = 'Forminator';

	// The methods catalog is the same for every ifthenpay account and rarely changes, so I keep it for a while.
	public const CATALOG_TRANSIENT = 'iftp_lp_methods_catalog';
	private const CATALOG_TTL      = 12 * HOUR_IN_SECONDS;

	// Switching Gateway Keys back and forth re-reads the same rows; a short window keeps that to one call.
	public const GATEWAY_ROWS_TRANSIENT = 'iftp_lp_gateway_rows';
	private const GATEWAY_ROWS_TTL      = 5 * MINUTE_IN_SECONDS;

	/**
	 * The last API/HTTP error message, if any.
	 *
	 * @var string
	 */
	private string $last_error = '';

	/**
	 * The last API/HTTP error message, if any.
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * The ifthenpay gateway type we ask for, e.g. "LearnPress".
	 */
	public static function gateway_type(): string {
		$type = apply_filters( 'iftp_lp_gateway_type', self::GATEWAY_TYPE );

		return is_string( $type ) && '' !== $type ? $type : self::GATEWAY_TYPE;
	}

	/**
	 * The `cms` tag our callback registers under.
	 */
	public static function callback_cms(): string {
		$cms = apply_filters( 'iftp_lp_callback_cms', self::CALLBACK_CMS );

		return is_string( $cms ) && '' !== $cms ? $cms : self::CALLBACK_CMS;
	}

	/**
	 * The Gateway Key rows for a Backoffice Key and our gateway type, cached for GATEWAY_ROWS_TTL. A failed (empty) fetch isn't cached.
	 *
	 * @param string $backoffice_key The Backoffice Key to look up gateway rows for.
	 * @param bool   $fresh          Skip the cache, e.g. when connecting a key for the first time.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_gateway_keys( string $backoffice_key, bool $fresh = false ): array {
		$key_hash = md5( $backoffice_key );
		$cached   = $fresh ? false : get_transient( self::GATEWAY_ROWS_TRANSIENT );

		if ( is_array( $cached ) && ( $cached['key_hash'] ?? '' ) === $key_hash && ! empty( $cached['rows'] ) && is_array( $cached['rows'] ) ) {
			return $cached['rows'];
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'boKey' => rawurlencode( $backoffice_key ),
					'Type'  => rawurlencode( self::gateway_type() ),
				),
				self::API_BASE . '/gateway/get'
			),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = $this->decode_response( $response );

		if ( ! is_array( $body ) || array() === $body ) {
			return array();
		}

		$rows = array_values( array_filter( isset( $body[0] ) ? $body : array( $body ), 'is_array' ) );

		if ( array() === $rows ) {
			return array();
		}

		set_transient(
			self::GATEWAY_ROWS_TRANSIENT,
			array(
				'key_hash' => $key_hash,
				'rows'     => $rows,
			),
			self::GATEWAY_ROWS_TTL
		);

		return $rows;
	}

	/**
	 * The public methods catalog, cached for CATALOG_TTL. A failed (empty) fetch isn't cached.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_available_methods(): array {
		$cached = get_transient( self::CATALOG_TRANSIENT );

		if ( is_array( $cached ) && array() !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( self::API_BASE . '/gateway/methods/available', array( 'timeout' => 15 ) );

		// An error page can still be JSON, and I don't want to cache that as the catalog for 12 hours.
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body    = $this->decode_response( $response );
		$catalog = is_array( $body ) ? array_values( array_filter( $body, 'is_array' ) ) : array();

		if ( array() !== $catalog ) {
			set_transient( self::CATALOG_TRANSIENT, $catalog, self::CATALOG_TTL );
		}

		return $catalog;
	}

	/**
	 * Creates a Pay by Link (POST /gateway/pinpay/{gateway_key}).
	 *
	 * @param string               $gateway_key The Gateway Key to create the link under.
	 * @param array<string, mixed> $payload     The Pay by Link request payload.
	 * @return string The hosted payment page URL, or '' on failure (see get_last_error()).
	 */
	public function create_payment_link( string $gateway_key, array $payload ): string {
		$this->last_error = '';

		$response = wp_remote_post(
			self::API_BASE . '/gateway/pinpay/' . rawurlencode( $gateway_key ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();

			return '';
		}

		$body = $this->decode_response( $response );
		$url  = is_array( $body ) ? ( $body['RedirectUrl'] ?? ( $body['redirect_url'] ?? '' ) ) : '';

		if ( ! is_string( $url ) || '' === $url ) {
			$this->last_error = sprintf( 'HTTP %d: %s', wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ) );

			return '';
		}

		return $url;
	}

	/**
	 * Refunds part or all of a paid payment (POST /v2/payments/refund). ifthenpay answers Code 1 when it's done.
	 *
	 * @param string $backoffice_key The Backoffice Key.
	 * @param string $request_id     The `req` the paid callback carried.
	 * @param float  $amount         The amount to refund.
	 */
	public function refund( string $backoffice_key, string $request_id, float $amount ): bool {
		$this->last_error = '';

		$response = wp_remote_post(
			self::API_BASE . '/v2/payments/refund',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'backofficekey' => $backoffice_key,
						'requestId'     => $request_id,
						'amount'        => IfthenpayPayload::format_amount( $amount ),
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();

			return false;
		}

		$body = $this->decode_response( $response );
		$code = is_array( $body ) && is_scalar( $body['Code'] ?? null ) ? (string) $body['Code'] : '';

		if ( '1' === $code ) {
			return true;
		}

		$message          = is_array( $body ) && is_scalar( $body['Message'] ?? null ) ? (string) $body['Message'] : '';
		$this->last_error = '-1' === $code ? 'insufficient_funds' : ( '' !== $message ? $message : sprintf( 'HTTP %d', wp_remote_retrieve_response_code( $response ) ) );

		return false;
	}

	/**
	 * (Re-)registers the callback URL for a Gateway Key (POST /endpoint/callback/activation/).
	 *
	 * @param string $gateway_key       The Gateway Key to register the webhook for.
	 * @param string $anti_phishing_key The key ifthenpay echoes back as `[ANTI_PHISHING_KEY]`.
	 * @param string $webhook_url       The webhook URL ifthenpay should call back.
	 */
	public function activate_callback( string $gateway_key, string $anti_phishing_key, string $webhook_url ): bool {
		$response = wp_remote_post(
			self::API_BASE . '/endpoint/callback/activation/?cms=' . rawurlencode( self::callback_cms() ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'apKey' => $anti_phishing_key,
						'chave' => $gateway_key,
						'urlCb' => $webhook_url,
					)
				),
				'timeout' => 15,
			)
		);

		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 300;
	}

	/**
	 * Decodes a response body as JSON. Callers check is_wp_error() first.
	 *
	 * @param array<string, mixed> $response The HTTP response.
	 * @return mixed
	 */
	private function decode_response( array $response ) {
		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}
