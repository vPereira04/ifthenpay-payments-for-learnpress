<?php
/**
 * The ifthenpay block on LearnPress's order edit screen.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Admin;

use Ifthenpay\LearnPress\Plugin;
use Ifthenpay\LearnPress\Repository\SettingsRepository;
use Ifthenpay\LearnPress\Repository\TransactionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Under the order key, the order's ifthenpay payment: the latest attempt in full, then any earlier ones.
 * LearnPress's order screen is where payments are looked at, so this is the whole record. Unstyled, like the settings.
 *
 * @phpstan-import-type Transaction from TransactionStore
 */
class OrderPanel {

	/**
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Settings storage, for the method names.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Connected method aliases by entity code.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $method_names = null;

	/**
	 * Sets up the panel.
	 *
	 * @param TransactionStore   $transactions Transactions storage.
	 * @param SettingsRepository $settings     Settings storage.
	 */
	public function __construct( TransactionStore $transactions, SettingsRepository $settings ) {
		$this->transactions = $transactions;
		$this->settings     = $settings;
	}

	/**
	 * Hooks the block into LearnPress's order details.
	 */
	public function register(): void {
		add_action( 'lp/admin/order/detail/after-order-key', array( $this, 'render' ) );
	}

	/**
	 * Prints the block, for orders that have at least one ifthenpay attempt.
	 *
	 * @param mixed $order The LearnPress order being edited.
	 */
	public function render( $order ): void {
		if ( ! $order instanceof \LP_Order || ! current_user_can( Plugin::admin_cap() ) ) {
			return;
		}

		$attempts = $this->transactions->for_order( (int) $order->get_id() );

		if ( array() === $attempts ) {
			return;
		}

		$latest  = $attempts[0];
		$earlier = array_slice( $attempts, 1 );
		$live    = TransactionStore::STATUS_PENDING === $latest['status'] && '' !== $latest['payment_url'] && $latest['expires_at'] > time();
		?>
		<div class="order-data-field iftp-lp-order-panel">
			<h3 class="iftp-lp-order-panel__title"><?php esc_html_e( 'ifthenpay payment', 'ifthenpay-payments-for-learnpress' ); ?></h3>
			<dl class="iftp-lp-order-panel__list">
				<?php $this->print_row( __( 'Reference', 'ifthenpay-payments-for-learnpress' ), esc_html( $latest['ref'] ) ); ?>
				<?php $this->print_row( __( 'Status', 'ifthenpay-payments-for-learnpress' ), $this->status_badge( $latest['status'] ) ); ?>
				<?php $this->print_row( __( 'Amount', 'ifthenpay-payments-for-learnpress' ), esc_html( $this->format_amount( $latest['amount'] ) ) ); ?>
				<?php $this->print_row( __( 'Method', 'ifthenpay-payments-for-learnpress' ), $this->method( $latest['pay_method'], $latest['status'] ) ); ?>
				<?php $this->print_row( __( 'Request ID', 'ifthenpay-payments-for-learnpress' ), $this->value_or_dash( $latest['request_id'], __( 'No request ID', 'ifthenpay-payments-for-learnpress' ) ) ); ?>
				<?php $this->print_row( __( 'Transaction ID', 'ifthenpay-payments-for-learnpress' ), $this->value_or_dash( $latest['transaction_id'], __( 'No transaction ID', 'ifthenpay-payments-for-learnpress' ) ) ); ?>
				<?php if ( $live ) : ?>
					<?php
					$link = '<a class="iftp-lp-order-panel__link" href="' . esc_url( $latest['payment_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $latest['payment_url'] ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'ifthenpay-payments-for-learnpress' ) . '</span></a>';
					$this->print_row( __( 'Payment link', 'ifthenpay-payments-for-learnpress' ), $link );
					?>
				<?php endif; ?>
				<?php if ( $latest['expires_at'] > 0 && TransactionStore::STATUS_PENDING === $latest['status'] ) : ?>
					<?php $this->print_row( __( 'Expires', 'ifthenpay-payments-for-learnpress' ), $this->time( $latest['expires_at'] ) ); ?>
				<?php endif; ?>
				<?php if ( $latest['refunded_amount'] > 0 ) : ?>
					<?php $this->print_row( __( 'Refunded', 'ifthenpay-payments-for-learnpress' ), esc_html( $this->format_amount( $latest['refunded_amount'] ) ) ); ?>
				<?php endif; ?>
				<?php $this->print_row( __( 'Created', 'ifthenpay-payments-for-learnpress' ), $this->time( $latest['created_at'] ) ); ?>
				<?php $this->print_row( __( 'Last update', 'ifthenpay-payments-for-learnpress' ), $this->time( $latest['updated_at'] ) ); ?>
			</dl>

			<?php if ( array() !== $earlier ) : ?>
				<details class="iftp-lp-order-panel__earlier">
					<summary class="iftp-lp-order-panel__earlier-title">
						<?php
						/* translators: %s: number of earlier attempts */
						echo esc_html( sprintf( _n( '%s earlier attempt', '%s earlier attempts', count( $earlier ), 'ifthenpay-payments-for-learnpress' ), number_format_i18n( count( $earlier ) ) ) );
						?>
					</summary>
					<table class="widefat striped iftp-lp-order-panel__attempts">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Reference', 'ifthenpay-payments-for-learnpress' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'ifthenpay-payments-for-learnpress' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-learnpress' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Created', 'ifthenpay-payments-for-learnpress' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $earlier as $attempt ) : ?>
								<tr class="iftp-lp-order-panel__attempt">
									<td><?php echo esc_html( $attempt['ref'] ); ?></td>
									<td><?php echo wp_kses_post( $this->status_badge( $attempt['status'] ) ); ?></td>
									<td><?php echo esc_html( $this->format_amount( $attempt['amount'] ) ); ?></td>
									<td><?php echo wp_kses_post( $this->time( $attempt['created_at'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Prints one label/value pair.
	 *
	 * @param string $label The label, already translated.
	 * @param string $html  The value, already escaped.
	 */
	private function print_row( string $label, string $html ): void {
		?>
		<div class="iftp-lp-order-panel__row">
			<dt class="iftp-lp-order-panel__label"><?php echo esc_html( $label ); ?></dt>
			<dd class="iftp-lp-order-panel__value"><?php echo wp_kses_post( $html ); ?></dd>
		</div>
		<?php
	}

	/**
	 * A status badge. Cancelled shows as Failed; the modifier is only a hook for styling later.
	 *
	 * @param string $status One of TransactionStore::ALL_STATUSES.
	 */
	private function status_badge( string $status ): string {
		$status = TransactionStore::STATUS_CANCELLED === $status ? TransactionStore::STATUS_FAILED : $status;
		$labels = array(
			TransactionStore::STATUS_PENDING  => __( 'Pending', 'ifthenpay-payments-for-learnpress' ),
			TransactionStore::STATUS_PAID     => __( 'Paid', 'ifthenpay-payments-for-learnpress' ),
			TransactionStore::STATUS_FAILED   => __( 'Failed', 'ifthenpay-payments-for-learnpress' ),
			TransactionStore::STATUS_EXPIRED  => __( 'Expired', 'ifthenpay-payments-for-learnpress' ),
			TransactionStore::STATUS_REFUNDED => __( 'Refunded', 'ifthenpay-payments-for-learnpress' ),
		);

		return '<span class="iftp-lp-badge iftp-lp-badge--' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ?? $status ) . '</span>';
	}

	/**
	 * The method. It's only known once a payment is paid, so the rest get a placeholder that says why.
	 *
	 * @param string $pay_method The transaction's pay_method.
	 * @param string $status     The transaction's status.
	 */
	private function method( string $pay_method, string $status ): string {
		if ( '' === $pay_method && TransactionStore::STATUS_PENDING === $status ) {
			return '<span class="iftp-lp-method iftp-lp-method--placeholder">' . esc_html__( 'Awaiting selection', 'ifthenpay-payments-for-learnpress' ) . '</span>';
		}

		if ( '' === $pay_method ) {
			return $this->value_or_dash( '', __( 'No payment method', 'ifthenpay-payments-for-learnpress' ) );
		}

		$entity = strtoupper( $pay_method );

		return '<span class="iftp-lp-method" data-entity="' . esc_attr( $entity ) . '">' . esc_html( $this->method_names()[ $entity ] ?? $pay_method ) . '</span>';
	}

	/**
	 * The value, or the dash core list tables use for an empty one (screen readers get the label).
	 *
	 * @param string $value The value.
	 * @param string $label What's missing, already translated.
	 */
	private function value_or_dash( string $value, string $label ): string {
		if ( '' !== $value ) {
			return esc_html( $value );
		}

		return '<span class="iftp-lp-empty-value" aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html( $label ) . '</span>';
	}

	/**
	 * An amount in the site's number format, e.g. "4.134,69 €".
	 *
	 * @param float $amount The amount in euros.
	 */
	private function format_amount( float $amount ): string {
		return number_format_i18n( $amount, 2 ) . ' €';
	}

	/**
	 * A <time> in the site's date and time format.
	 *
	 * @param int $timestamp The unix timestamp.
	 */
	private function time( int $timestamp ): string {
		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

		return '<time class="iftp-lp-time" datetime="' . esc_attr( (string) wp_date( 'c', $timestamp ) ) . '">' . esc_html( (string) wp_date( $format, $timestamp ) ) . '</time>';
	}

	/**
	 * Connected method aliases by entity code, since the callback reports the code.
	 *
	 * @return array<string, string>
	 */
	private function method_names(): array {
		if ( null === $this->method_names ) {
			$this->method_names = array();

			foreach ( $this->settings->get_methods() as $method ) {
				if ( '' !== $method['entity'] && '' !== $method['alias'] ) {
					$this->method_names[ strtoupper( $method['entity'] ) ] = $method['alias'];
				}
			}
		}

		return $this->method_names;
	}
}
