<?php
/**
 * Personal data exporter and eraser.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Privacy;

use Ifthenpay\LearnPress\Repository\TransactionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks our payment attempts into Tools › Export / Erase Personal Data. LearnPress's own orders are LearnPress's to handle.
 */
class PersonalData {

	private const PER_PAGE = 100;

	/**
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Sets up the hooks' handler.
	 *
	 * @param TransactionStore $transactions Transactions storage.
	 */
	public function __construct( TransactionStore $transactions ) {
		$this->transactions = $transactions;
	}

	/**
	 * Registers the exporter and the eraser.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return mixed
	 */
	public function add_exporter( $exporters ) {
		if ( is_array( $exporters ) ) {
			$exporters['ifthenpay-payments-for-learnpress'] = array(
				'exporter_friendly_name' => __( 'ifthenpay payments for LearnPress', 'ifthenpay-payments-for-learnpress' ),
				'callback'               => array( $this, 'export' ),
			);
		}

		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return mixed
	 */
	public function add_eraser( $erasers ) {
		if ( is_array( $erasers ) ) {
			$erasers['ifthenpay-payments-for-learnpress'] = array(
				'eraser_friendly_name' => __( 'ifthenpay payments for LearnPress', 'ifthenpay-payments-for-learnpress' ),
				'callback'             => array( $this, 'erase' ),
			);
		}

		return $erasers;
	}

	/**
	 * One page of a customer's payment attempts.
	 *
	 * @param string $email The customer's email.
	 * @param int    $page  The page, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$email = sanitize_email( (string) $email );
		$page  = max( 1, (int) $page );
		$rows  = '' !== $email ? $this->transactions->for_email( $email, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) : array();
		$data  = array();

		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'iftp-lp-payments',
				'group_label' => __( 'ifthenpay payments', 'ifthenpay-payments-for-learnpress' ),
				'item_id'     => 'iftp-lp-' . $row['ref'],
				'data'        => array(
					array(
						'name'  => __( 'Reference', 'ifthenpay-payments-for-learnpress' ),
						'value' => $row['ref'],
					),
					array(
						'name'  => __( 'LearnPress order', 'ifthenpay-payments-for-learnpress' ),
						'value' => (string) $row['order_id'],
					),
					array(
						'name'  => __( 'Customer name', 'ifthenpay-payments-for-learnpress' ),
						'value' => $row['customer_name'],
					),
					array(
						'name'  => __( 'Customer email', 'ifthenpay-payments-for-learnpress' ),
						'value' => $row['customer_email'],
					),
					array(
						'name'  => __( 'Amount', 'ifthenpay-payments-for-learnpress' ),
						'value' => number_format_i18n( $row['amount'], 2 ) . ' €',
					),
					array(
						'name'  => __( 'Status', 'ifthenpay-payments-for-learnpress' ),
						'value' => $row['status'],
					),
					array(
						'name'  => __( 'Created', 'ifthenpay-payments-for-learnpress' ),
						'value' => (string) wp_date( 'Y-m-d H:i:s', $row['created_at'] ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Wipes the customer's name and email from a batch of their attempts. The payment rows stay as records.
	 *
	 * @param string $email The customer's email.
	 * @param int    $page  Ignored: erased rows stop matching, so I always read from the start.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );

		$email   = sanitize_email( (string) $email );
		$changed = '' !== $email ? $this->transactions->anonymize_email( $email, self::PER_PAGE ) : 0;

		return array(
			'items_removed'  => $changed > 0,
			'items_retained' => false,
			'messages'       => array(),
			// A short batch, or nothing changed (a failed UPDATE), ends it, so it can't loop forever.
			'done'           => $changed < self::PER_PAGE,
		);
	}
}
