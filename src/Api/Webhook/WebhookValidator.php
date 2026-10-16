<?php
/**
 * Webhook anti-phishing + amount validator.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Api\Webhook;

use Ifthenpay\LearnPress\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anti-phishing and amount checks for an inbound callback, against the row it claims to resolve.
 */
class WebhookValidator {

	private const AMOUNT_TOLERANCE = 0.01;

	// Rows that never went through ifthenpay; nothing may resolve them through a callback.
	private const PLACEHOLDER_KEYS = array( 'manual' );

	/**
	 * Settings storage, holding the anti-phishing key per Gateway Key.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Sets up the validator.
	 *
	 * @param SettingsRepository $settings Settings storage.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether the callback's anti-phishing key is the one registered for the row's Gateway Key. Runs before any branch.
	 *
	 * @param WebhookPayload       $payload The parsed callback.
	 * @param array<string, mixed> $record  As stored by TransactionStore.
	 */
	public function key_matches( WebhookPayload $payload, array $record ): bool {
		$gateway_key = is_string( $record['gateway_key'] ?? null ) ? $record['gateway_key'] : '';

		if ( '' === $gateway_key || in_array( $gateway_key, self::PLACEHOLDER_KEYS, true ) ) {
			return false;
		}

		$expected = $this->settings->get_anti_phishing_key( $gateway_key );

		// No stored key means nothing to verify against, so I refuse rather than guess. WebhookRegistrar sets a fresh one on the next admin visit.
		return '' !== $expected && '' !== $payload->apk() && hash_equals( $expected, $payload->apk() );
	}

	/**
	 * Whether the paid amount is the row's amount, to the cent.
	 *
	 * @param WebhookPayload       $payload The parsed callback.
	 * @param array<string, mixed> $record  As stored by TransactionStore.
	 */
	public function amount_matches( WebhookPayload $payload, array $record ): bool {
		$expected = is_numeric( $record['amount'] ?? null ) ? (float) $record['amount'] : 0.0;

		return $payload->amount() >= 0 && abs( $payload->amount() - $expected ) <= self::AMOUNT_TOLERANCE;
	}
}
