<?php
/**
 * Gateway payment methods table renderer.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Admin;

use Ifthenpay\LearnPress\Ajax\SettingsController;
use Ifthenpay\LearnPress\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the payment methods table: Enabled, the Default star, the method, its account and its status / activation request.
 *
 * @phpstan-import-type Method from SettingsRepository
 */
class MethodsTable {

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Sets up the table.
	 *
	 * @param SettingsRepository $settings Settings storage.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The table markup, for the AJAX responses that swap it in.
	 */
	public function render(): string {
		ob_start();
		$this->output();

		return (string) ob_get_clean();
	}

	/**
	 * Prints the full methods table.
	 */
	public function output(): void {
		// I keep IsVisible:false methods in storage for their alias, but ifthenpay isn't offering them.
		$methods        = array_filter( $this->settings->get_methods(), static fn ( array $method ): bool => $method['visible'] ?? true );
		$default_method = $this->settings->get_default_method();
		$gateway_key    = $this->settings->get_gateway_key();

		if ( array() === $methods ) {
			?>
			<p class="iftp-lp-methods__empty"><?php esc_html_e( 'No payment methods loaded yet. Reload this page to fetch them from ifthenpay.', 'ifthenpay-payments-for-learnpress' ); ?></p>
			<?php
			return;
		}
		?>
		<table class="widefat iftp-lp-methods">
			<caption class="screen-reader-text"><?php esc_html_e( 'Payment methods on this Gateway Key', 'ifthenpay-payments-for-learnpress' ); ?></caption>
			<thead>
				<tr>
					<th scope="col" class="iftp-lp-methods__col iftp-lp-methods__col--enabled"><?php esc_html_e( 'Enabled', 'ifthenpay-payments-for-learnpress' ); ?></th>
					<th scope="col" class="iftp-lp-methods__col iftp-lp-methods__col--default"><?php esc_html_e( 'Default Method', 'ifthenpay-payments-for-learnpress' ); ?></th>
					<th scope="col" class="iftp-lp-methods__col iftp-lp-methods__col--method"><?php esc_html_e( 'Method', 'ifthenpay-payments-for-learnpress' ); ?></th>
					<th scope="col" class="iftp-lp-methods__col iftp-lp-methods__col--account"><?php esc_html_e( 'Account', 'ifthenpay-payments-for-learnpress' ); ?></th>
					<th scope="col" class="iftp-lp-methods__col iftp-lp-methods__col--status"><?php esc_html_e( 'Status', 'ifthenpay-payments-for-learnpress' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $methods as $method ) : ?>
					<?php $this->render_row( $method, $default_method, $gateway_key ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders a single method row.
	 *
	 * @param array<string, mixed> $method         The catalog method row.
	 * @param string               $default_method The currently configured default method entity.
	 * @param string               $gateway_key    The selected Gateway Key, for the activation cooldown.
	 * @phpstan-param Method $method
	 */
	private function render_row( array $method, string $default_method, string $gateway_key ): void {
		$entity      = $method['entity'];
		$alias       = $method['alias'];
		$logo        = $method['logo'] ?? '';
		$enabled     = (bool) $method['enabled'];
		$provisioned = '' !== $method['account'];
		$is_default  = $enabled && $entity === $default_method;
		$star_id     = 'iftp-lp-default-' . sanitize_html_class( $entity );
		$row_class   = 'iftp-lp-methods__row' . ( $provisioned ? '' : ' iftp-lp-methods__row--unprovisioned' );
		?>
		<tr class="<?php echo esc_attr( $row_class ); ?>" data-iftp-method-row data-entity="<?php echo esc_attr( $entity ); ?>">
			<td class="iftp-lp-methods__cell iftp-lp-methods__cell--enabled">
				<input
					type="checkbox"
					class="iftp-lp-methods__enabled"
					name="iftp_lp_enabled_methods[]"
					value="<?php echo esc_attr( $entity ); ?>"
					data-iftp-method-enabled
					aria-label="<?php /* translators: %s: payment method name, e.g. "MB WAY" */ echo esc_attr( sprintf( __( 'Enable %s', 'ifthenpay-payments-for-learnpress' ), $alias ) ); ?>"
					<?php checked( $enabled ); ?>
					<?php disabled( ! $provisioned ); ?>
				/>
			</td>
			<td class="iftp-lp-methods__cell iftp-lp-methods__cell--default">
				<?php // A radio group gives "exactly one default, never none" for free; the label is the visible star. ?>
				<input
					type="radio"
					class="screen-reader-text iftp-lp-methods__default"
					id="<?php echo esc_attr( $star_id ); ?>"
					name="iftp_lp_default_method"
					value="<?php echo esc_attr( $entity ); ?>"
					data-iftp-method-default
					<?php checked( $is_default ); ?>
					<?php disabled( ! $enabled ); ?>
				/>
				<label
					for="<?php echo esc_attr( $star_id ); ?>"
					class="iftp-lp-star<?php echo $is_default ? ' iftp-lp-star--checked' : ''; ?><?php echo $enabled ? '' : ' iftp-lp-star--hidden'; ?>"
					title="<?php esc_attr_e( 'Default payment method', 'ifthenpay-payments-for-learnpress' ); ?>"
					data-iftp-star
					<?php echo $enabled ? '' : ' hidden'; ?>
				><span class="iftp-lp-star__glyph" aria-hidden="true" data-iftp-star-glyph><?php echo $is_default ? '&#9733;' : '&#9734;'; ?></span><span class="screen-reader-text">
					<?php
					/* translators: %s: payment method name, e.g. "MB WAY" */
					echo esc_html( sprintf( __( 'Make %s the default payment method', 'ifthenpay-payments-for-learnpress' ), $alias ) );
					?>
				</span></label>
			</td>
			<td class="iftp-lp-methods__cell iftp-lp-methods__cell--method">
				<?php if ( '' !== $logo ) : ?>
					<img class="iftp-lp-methods__logo" src="<?php echo esc_url( $logo ); ?>" alt="" height="24" loading="lazy" />
				<?php endif; ?>
				<span class="iftp-lp-methods__label<?php echo $provisioned ? '' : ' iftp-lp-methods__label--dimmed'; ?>"><?php echo esc_html( $alias ); ?></span>
			</td>
			<td class="iftp-lp-methods__cell iftp-lp-methods__cell--account">
				<?php if ( $provisioned ) : ?>
					<code class="iftp-lp-methods__account"><?php echo esc_html( self::account_display( $entity, $method['account'] ) ); ?></code>
				<?php else : ?>
					<span aria-hidden="true">&#8212;</span><span class="screen-reader-text"><?php esc_html_e( 'No account', 'ifthenpay-payments-for-learnpress' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="iftp-lp-methods__cell iftp-lp-methods__cell--status">
				<?php if ( $provisioned ) : ?>
					<?php // Both labels ship in the markup so settings.js can swap them live as "Enabled" is toggled. ?>
					<span
						class="iftp-lp-method-status iftp-lp-method-status--<?php echo $enabled ? 'enabled' : 'activated'; ?>"
						data-iftp-method-status
						data-label-enabled="<?php esc_attr_e( 'Enabled', 'ifthenpay-payments-for-learnpress' ); ?>"
						data-label-activated="<?php esc_attr_e( 'Activated', 'ifthenpay-payments-for-learnpress' ); ?>"
					><?php echo $enabled ? esc_html__( 'Enabled', 'ifthenpay-payments-for-learnpress' ) : esc_html__( 'Activated', 'ifthenpay-payments-for-learnpress' ); ?></span>
				<?php else : ?>
					<?php $requested = false !== get_transient( SettingsController::activation_cooldown_key( $gateway_key, $entity ) ); ?>
					<span class="iftp-lp-method-status iftp-lp-method-status--unavailable iftp-lp-methods__label--dimmed"><?php esc_html_e( 'Not activated', 'ifthenpay-payments-for-learnpress' ); ?></span>
					<button
						type="button"
						class="button iftp-lp-methods__activate"
						data-iftp-request-activation="<?php echo esc_attr( $entity ); ?>"
						<?php disabled( $requested ); ?>
					><?php echo $requested ? esc_html__( 'Requested', 'ifthenpay-payments-for-learnpress' ) : esc_html__( 'Request Activation', 'ifthenpay-payments-for-learnpress' ); ?></button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Classic Multibanco (numeric Entidade|Subentidade) shows both parts; everything else just its account code.
	 *
	 * @param string $entity  The method entity code.
	 * @param string $account The stored `ENTITY|ACCOUNT` segment.
	 */
	private static function account_display( string $entity, string $account ): string {
		$parts = array_map( 'trim', explode( '|', $account ) );

		if ( 'MB' === $entity && ctype_digit( $parts[0] ) ) {
			return implode( ' | ', $parts );
		}

		return (string) end( $parts );
	}
}
