<?php
/**
 * Payer return redirect handler.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Api;

use Ifthenpay\LearnPress\Host;
use Ifthenpay\LearnPress\Payments\PaymentService;
use Ifthenpay\LearnPress\Plugin;
use Ifthenpay\LearnPress\Repository\TransactionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the payer landing back from ifthenpay's page. It never marks anything paid: Multibanco and Payshop send
 * the payer back before they've paid, and the URL isn't proof anyway. Only declines and cancels are recorded here,
 * then the payer goes on to LearnPress's order-received page, which shows where the payment stands.
 *
 * @phpstan-import-type Transaction from TransactionStore
 */
class ReturnHandler {

	/**
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Sets up the handler.
	 *
	 * @param TransactionStore $transactions Transactions storage.
	 */
	public function __construct( TransactionStore $transactions ) {
		$this->transactions = $transactions;
	}

	/**
	 * Hooks the return check.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ) );
	}

	/**
	 * Records a signed return, then redirects away from the URL that carries it.
	 */
	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- ifthenpay's redirect can't carry a nonce; the signature below authenticates it instead.
		if ( ! isset( $_GET[ PaymentService::RETURN_VAR ] ) ) {
			return;
		}

		$outcome   = sanitize_key( wp_unslash( $_GET[ PaymentService::RETURN_VAR ] ) );
		$ref       = sanitize_text_field( wp_unslash( $_GET[ PaymentService::RETURN_REF ] ?? '' ) );
		$signature = sanitize_text_field( wp_unslash( $_GET[ PaymentService::RETURN_SIG ] ?? '' ) );
		$txn_id    = sanitize_text_field( wp_unslash( $_GET[ PaymentService::RETURN_TXN ] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$record = '' !== $ref && hash_equals( PaymentService::return_signature( $ref, $outcome ), $signature ) ? $this->transactions->get( $ref ) : null;

		if ( null === $record ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$this->record_outcome( $record, $outcome, $txn_id );

		$order = Host::order( $record['order_id'] );

		wp_safe_redirect( null !== $order ? (string) $order->get_checkout_order_received_url() : home_url( '/' ) );
		exit;
	}

	/**
	 * A later webhook still wins: the failure marks never touch a paid row.
	 *
	 * @param array<string, mixed> $record  The attempt.
	 * @param string               $outcome 'success', 'error' or 'cancel'.
	 * @param string               $txn_id  The transaction id ifthenpay put on a success return, or ''.
	 * @phpstan-param Transaction $record
	 */
	private function record_outcome( array $record, string $outcome, string $txn_id ): void {
		// The placeholder comes back unreplaced when ifthenpay has no id to give.
		if ( 'success' === $outcome && '' !== $txn_id && '[TRANSACTIONID]' !== $txn_id && '' === $record['transaction_id'] ) {
			$this->transactions->record_transaction_id( $record['ref'], $txn_id );
		}

		if ( TransactionStore::STATUS_PENDING !== $record['status'] || ! in_array( $outcome, array( 'error', 'cancel' ), true ) ) {
			return;
		}

		Plugin::instance()->payments()->record_failure( $record, 'cancel' === $outcome );
	}
}
