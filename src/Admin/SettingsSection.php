<?php
/**
 * The ifthenpay configuration inside LearnPress › Settings › Payments › ifthenpay.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Admin;

use Ifthenpay\LearnPress\Ajax\SettingsController;
use Ifthenpay\LearnPress\Api\IfthenpayClient;
use Ifthenpay\LearnPress\Api\Webhook\WebhookRegistrar;
use Ifthenpay\LearnPress\Host;
use Ifthenpay\LearnPress\Plugin;
use Ifthenpay\LearnPress\Repository\SettingsRepository;
use Ifthenpay\LearnPress\Sync\GatewaySync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The standard settings contract, inside LearnPress's own section: Connect (Backoffice Key popup) → Disconnect, then
 * the Gateway Key, the methods table with the default star, and the hosted page's description and expiry.
 *
 * LearnPress wraps the section in one <form> with one Save, so our fields ride along and get saved on its
 * "settings updated" action. The popups have forms of their own, so they're printed in the footer, outside it.
 *
 * Unstyled on purpose (Victor designs it later): plain markup, native <dialog>s and the `hidden` attribute.
 */
class SettingsSection {

	// Marks that our fields were on the submitted form, so a save from another section never touches them.
	private const FIELD_MARKER = 'iftp_lp_settings';

	private const BACKFILL_LOCK = 'iftp_lp_backfill_lock';
	private const ERROR_PREFIX  = 'iftp_lp_settings_error_';

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Methods table renderer.
	 *
	 * @var MethodsTable
	 */
	private MethodsTable $methods_table;

	/**
	 * Sets up the section.
	 *
	 * @param SettingsRepository $settings Settings storage.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings      = $settings;
		$this->methods_table = new MethodsTable( $settings );
	}

	/**
	 * Hooks the assets, the footer popups and the save.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( $this, 'render_dialogs' ) );
		add_action( 'learn-press/update-settings/updated', array( $this, 'save' ) );
	}

	/**
	 * Whether this request is our LearnPress section.
	 */
	public static function is_screen(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only reads which screen this is.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return is_admin() && 'learn-press-settings' === $page && 'payments' === $tab && Plugin::GATEWAY_ID === $section;
	}

	/**
	 * Enqueues the section's script, only on our section. No stylesheet.
	 */
	public function enqueue_assets(): void {
		if ( ! self::is_screen() || ! current_user_can( Plugin::admin_cap() ) ) {
			return;
		}

		$settings_js = 'assets/js/settings.js';

		wp_enqueue_script( 'iftp-lp-settings', IFTP_LP_URL . $settings_js, array(), Plugin::asset_version( $settings_js ), true );

		wp_localize_script(
			'iftp-lp-settings',
			'iftpLpSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( SettingsController::NONCE_ACTION ),
				'i18n'    => array(
					'connect'     => __( 'Connect', 'ifthenpay-payments-for-learnpress' ),
					'connecting'  => __( 'Connecting…', 'ifthenpay-payments-for-learnpress' ),
					'invalidKey'  => __( 'Enter the Backoffice Key as 0000-0000-0000-0000.', 'ifthenpay-payments-for-learnpress' ),
					'requesting'  => __( 'Requesting…', 'ifthenpay-payments-for-learnpress' ),
					'requested'   => __( 'Requested', 'ifthenpay-payments-for-learnpress' ),
					'requestSent' => __( 'Activation request sent. ifthenpay will get back to you by email.', 'ifthenpay-payments-for-learnpress' ),
					'loading'     => __( 'Loading the payment methods…', 'ifthenpay-payments-for-learnpress' ),
					'error'       => __( 'Something went wrong. Please try again.', 'ifthenpay-payments-for-learnpress' ),
					'starOn'      => __( 'Default payment method', 'ifthenpay-payments-for-learnpress' ),
					'starOff'     => __( 'Not the default', 'ifthenpay-payments-for-learnpress' ),
				),
			)
		);
	}

	/**
	 * The top of the section: the euro check and the ifthenpay account (Connect / Disconnect).
	 */
	public function render_connection(): void {
		if ( ! current_user_can( Plugin::admin_cap() ) ) {
			return;
		}

		$connected = $this->settings->is_connected();

		// The lock keeps a failing API from being called on every load until the backfill lands.
		if ( $connected && false === get_transient( self::BACKFILL_LOCK ) ) {
			$sync = new GatewaySync( $this->settings, new IfthenpayClient() );

			if ( $sync->needs_backfill() ) {
				set_transient( self::BACKFILL_LOCK, 1, 15 * MINUTE_IN_SECONDS );
				$sync->sync();
			}
		}

		$this->render_notices();
		?>
		<div id="iftp-lp-settings" class="iftp-lp-settings">
			<section class="iftp-lp-panel iftp-lp-connection" aria-labelledby="iftp-lp-connection-title">
				<h3 id="iftp-lp-connection-title" class="iftp-lp-panel__title"><?php esc_html_e( 'ifthenpay account', 'ifthenpay-payments-for-learnpress' ); ?></h3>
				<p class="iftp-lp-panel__description"><?php esc_html_e( 'Your Backoffice Key unlocks the Gateway Keys and payment methods on your ifthenpay contract.', 'ifthenpay-payments-for-learnpress' ); ?></p>
				<p class="iftp-lp-connection__status iftp-lp-connection__status--<?php echo $connected ? 'connected' : 'disconnected'; ?>">
					<?php echo $connected ? esc_html__( 'Connected', 'ifthenpay-payments-for-learnpress' ) : esc_html__( 'Not connected', 'ifthenpay-payments-for-learnpress' ); ?>
				</p>
				<?php if ( $connected ) : ?>
					<button type="button" class="button iftp-lp-connection__action iftp-lp-connection__action--disconnect" data-iftp-disconnect-open aria-haspopup="dialog"><?php esc_html_e( 'Disconnect', 'ifthenpay-payments-for-learnpress' ); ?></button>
				<?php else : ?>
					<button type="button" class="button iftp-lp-connection__action iftp-lp-connection__action--connect" data-iftp-connect-open aria-haspopup="dialog"><?php esc_html_e( 'Connect', 'ifthenpay-payments-for-learnpress' ); ?></button>
				<?php endif; ?>
				<p class="iftp-lp-connection__message" data-iftp-connection-message role="alert"></p>
			</section>
		</div>
		<?php
	}

	/**
	 * The gateway configuration under LearnPress's own fields, once connected. Saved by LearnPress's Save button.
	 */
	public function render_configuration(): void {
		if ( ! current_user_can( Plugin::admin_cap() ) || ! $this->settings->is_connected() ) {
			return;
		}

		$gateway_keys = $this->settings->get_gateway_keys();
		$current_key  = $this->settings->get_gateway_key();
		?>
		<div class="iftp-lp-settings__configuration" data-iftp-configuration>
			<input type="hidden" name="<?php echo esc_attr( self::FIELD_MARKER ); ?>" value="1" />

			<section class="iftp-lp-panel iftp-lp-gateway" aria-labelledby="iftp-lp-gateway-title">
				<h3 id="iftp-lp-gateway-title" class="iftp-lp-panel__title"><?php esc_html_e( 'Gateway Key', 'ifthenpay-payments-for-learnpress' ); ?></h3>
				<p class="iftp-lp-panel__description" id="iftp-lp-gateway-key-help"><?php esc_html_e( 'The Gateway Key payments are created under. Switching it reloads its payment methods.', 'ifthenpay-payments-for-learnpress' ); ?></p>
				<p class="iftp-lp-field">
					<label class="iftp-lp-field__label" for="iftp-lp-gateway-key"><?php esc_html_e( 'Gateway Key', 'ifthenpay-payments-for-learnpress' ); ?></label>
					<select class="iftp-lp-field__control" id="iftp-lp-gateway-key" name="iftp_lp_gateway_key" aria-describedby="iftp-lp-gateway-key-help" data-iftp-gateway-key>
						<?php foreach ( $gateway_keys as $key ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $current_key ); ?>><?php echo esc_html( $key ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			</section>

			<section class="iftp-lp-panel iftp-lp-methods-panel" aria-labelledby="iftp-lp-methods-title">
				<h3 id="iftp-lp-methods-title" class="iftp-lp-panel__title"><?php esc_html_e( 'Payment methods', 'ifthenpay-payments-for-learnpress' ); ?></h3>
				<p class="iftp-lp-panel__description"><?php esc_html_e( 'Choose which methods to offer, and star the one the hosted payment page opens on.', 'ifthenpay-payments-for-learnpress' ); ?></p>
				<div class="iftp-lp-methods-panel__body" data-iftp-methods>
					<?php $this->methods_table->output(); ?>
				</div>
				<p class="iftp-lp-methods-panel__status" data-iftp-status role="status"></p>
			</section>

			<section class="iftp-lp-panel iftp-lp-hosted" aria-labelledby="iftp-lp-hosted-title">
				<h3 id="iftp-lp-hosted-title" class="iftp-lp-panel__title"><?php esc_html_e( 'Hosted payment page', 'ifthenpay-payments-for-learnpress' ); ?></h3>
				<p class="iftp-lp-panel__description"><?php esc_html_e( 'What the student reads on the ifthenpay page, and how long an unpaid link stays open.', 'ifthenpay-payments-for-learnpress' ); ?></p>
				<p class="iftp-lp-field">
					<label class="iftp-lp-field__label" for="iftp-lp-description"><?php esc_html_e( 'Payment page description', 'ifthenpay-payments-for-learnpress' ); ?></label>
					<input type="text" class="iftp-lp-field__control" id="iftp-lp-description" name="iftp_lp_description" maxlength="200" value="<?php echo esc_attr( $this->settings->get_description_setting() ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>" aria-describedby="iftp-lp-description-help" />
					<span class="iftp-lp-field__help" id="iftp-lp-description-help"><?php esc_html_e( 'Leave it empty to use the site name.', 'ifthenpay-payments-for-learnpress' ); ?></span>
				</p>
				<p class="iftp-lp-field">
					<label class="iftp-lp-field__label" for="iftp-lp-expiry-days"><?php esc_html_e( 'Expiry days', 'ifthenpay-payments-for-learnpress' ); ?></label>
					<input type="number" class="iftp-lp-field__control" id="iftp-lp-expiry-days" name="iftp_lp_expiry_days" min="1" max="<?php echo esc_attr( (string) SettingsRepository::MAX_EXPIRY_DAYS ); ?>" step="1" inputmode="numeric" required value="<?php echo esc_attr( (string) $this->settings->get_expiry_days() ); ?>" aria-describedby="iftp-lp-expiry-days-help" />
					<span class="iftp-lp-field__help" id="iftp-lp-expiry-days-help"><?php esc_html_e( 'After this many days an unpaid payment link stops working and the payment is marked Expired.', 'ifthenpay-payments-for-learnpress' ); ?></span>
				</p>
			</section>
		</div>
		<?php
	}

	/**
	 * The Connect or Disconnect popup, in the footer so its form isn't nested inside LearnPress's.
	 */
	public function render_dialogs(): void {
		if ( ! self::is_screen() || ! current_user_can( Plugin::admin_cap() ) ) {
			return;
		}

		if ( $this->settings->is_connected() ) {
			$this->render_disconnect_dialog();
		} else {
			$this->render_connect_dialog();
		}
	}

	/**
	 * Saves our fields after LearnPress saved its own (it has checked its nonce and the admin role by then).
	 */
	public function save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the marker only tells whether our fields were posted; the nonce is checked right below.
		if ( ! self::is_screen() || ! isset( $_POST[ self::FIELD_MARKER ] ) ) {
			return;
		}

		$nonce = isset( $_POST['lp-settings-nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lp-settings-nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'lp-settings' ) || ! current_user_can( Plugin::admin_cap() ) || ! $this->settings->is_connected() ) {
			return;
		}

		$sync        = new GatewaySync( $this->settings, new IfthenpayClient() );
		$gateway_key = isset( $_POST['iftp_lp_gateway_key'] ) ? sanitize_text_field( wp_unslash( $_POST['iftp_lp_gateway_key'] ) ) : '';

		if ( '' !== $gateway_key && $gateway_key !== $this->settings->get_gateway_key() && ! $sync->switch_gateway_key( $gateway_key ) ) {
			$this->flash_error( __( 'Unknown Gateway Key. The ifthenpay settings were not saved.', 'ifthenpay-payments-for-learnpress' ) );

			return;
		}

		$expiry_days = isset( $_POST['iftp_lp_expiry_days'] ) ? absint( wp_unslash( $_POST['iftp_lp_expiry_days'] ) ) : SettingsRepository::DEFAULT_EXPIRY_DAYS;

		if ( $expiry_days < 1 || $expiry_days > SettingsRepository::MAX_EXPIRY_DAYS ) {
			/* translators: %s: the most days allowed */
			$this->flash_error( sprintf( __( 'Expiry days must be between 1 and %s. The ifthenpay settings were not saved.', 'ifthenpay-payments-for-learnpress' ), number_format_i18n( SettingsRepository::MAX_EXPIRY_DAYS ) ) );

			return;
		}

		$enabled_entities = isset( $_POST['iftp_lp_enabled_methods'] ) && is_array( $_POST['iftp_lp_enabled_methods'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['iftp_lp_enabled_methods'] ) )
			: array();
		$default_method   = isset( $_POST['iftp_lp_default_method'] ) ? sanitize_text_field( wp_unslash( $_POST['iftp_lp_default_method'] ) ) : '';
		$description      = isset( $_POST['iftp_lp_description'] ) ? sanitize_text_field( wp_unslash( $_POST['iftp_lp_description'] ) ) : '';
		$methods          = $this->settings->get_methods();
		$enabled_now      = array();

		foreach ( $methods as &$method ) {
			$method['enabled'] = in_array( $method['entity'], $enabled_entities, true ) && '' !== $method['account'];

			if ( $method['enabled'] ) {
				$enabled_now[] = $method['entity'];
			}
		}
		unset( $method );

		// The default can only be a method that's enabled.
		$this->settings->set_methods( $methods );
		$this->settings->set_default_method( in_array( $default_method, $enabled_now, true ) ? $default_method : '' );
		$this->settings->set_description( mb_substr( $description, 0, 200 ) );
		$this->settings->set_expiry_days( $expiry_days );

		( new WebhookRegistrar( $this->settings ) )->register_webhook( $this->settings->get_gateway_key() );
	}

	/**
	 * The euro requirement, and a save error from the last submit.
	 */
	private function render_notices(): void {
		if ( ! Host::is_eur() ) {
			$message = sprintf(
				/* translators: %s: the store currency code, e.g. "USD" */
				__( 'ifthenpay only takes euros, and LearnPress is set to %s. Students won\'t see ifthenpay at checkout until the currency is EUR.', 'ifthenpay-payments-for-learnpress' ),
				'' !== Host::currency() ? Host::currency() : '—'
			);

			wp_admin_notice(
				esc_html( $message ),
				array(
					'type'               => 'warning',
					'additional_classes' => array( 'inline', 'iftp-lp-settings__notice' ),
				)
			);
		}

		$error_key = self::ERROR_PREFIX . get_current_user_id();
		$error     = get_transient( $error_key );

		if ( is_string( $error ) && '' !== $error ) {
			delete_transient( $error_key );

			wp_admin_notice(
				esc_html( $error ),
				array(
					'type'               => 'error',
					'additional_classes' => array( 'inline', 'iftp-lp-settings__notice' ),
				)
			);
		}
	}

	/**
	 * Keeps a save error for the page LearnPress redirects to after saving.
	 *
	 * @param string $message The error, already translated.
	 */
	private function flash_error( string $message ): void {
		set_transient( self::ERROR_PREFIX . get_current_user_id(), $message, MINUTE_IN_SECONDS );
	}

	/**
	 * The Backoffice Key popup. A native <dialog>, so it's a real modal (focus, Escape, top layer) with no styles at all.
	 */
	private function render_connect_dialog(): void {
		?>
		<dialog class="iftp-lp-dialog iftp-lp-dialog--connect" aria-labelledby="iftp-lp-connect-title" aria-describedby="iftp-lp-connect-desc" data-iftp-connect-dialog>
			<form class="iftp-lp-dialog__form" data-iftp-connect-form novalidate>
				<h2 id="iftp-lp-connect-title" class="iftp-lp-dialog__title"><?php esc_html_e( 'Connect to ifthenpay', 'ifthenpay-payments-for-learnpress' ); ?></h2>
				<p id="iftp-lp-connect-desc" class="iftp-lp-dialog__text"><?php esc_html_e( 'Paste the Backoffice Key from your ifthenpay account. It stays on this site and is never shown again.', 'ifthenpay-payments-for-learnpress' ); ?></p>

				<p class="iftp-lp-field">
					<label class="iftp-lp-field__label" for="iftp-lp-backoffice-key"><?php esc_html_e( 'Backoffice Key', 'ifthenpay-payments-for-learnpress' ); ?></label>
					<input
						type="text"
						class="iftp-lp-field__control"
						id="iftp-lp-backoffice-key"
						name="backoffice_key"
						placeholder="0000-0000-0000-0000"
						autocomplete="off"
						spellcheck="false"
						inputmode="numeric"
						maxlength="19"
						required
						aria-describedby="iftp-lp-backoffice-key-help iftp-lp-connect-error"
						data-iftp-backoffice-key
					/>
					<span id="iftp-lp-backoffice-key-help" class="iftp-lp-field__help"><?php esc_html_e( 'The 16-digit key, as 0000-0000-0000-0000.', 'ifthenpay-payments-for-learnpress' ); ?></span>
				</p>
				<p id="iftp-lp-connect-error" class="iftp-lp-field__error" role="alert" data-iftp-connect-error></p>

				<p class="iftp-lp-dialog__actions">
					<button type="button" class="button iftp-lp-dialog__cancel" data-iftp-dialog-close><?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-learnpress' ); ?></button>
					<button type="submit" class="button iftp-lp-dialog__confirm" data-iftp-connect-submit><?php esc_html_e( 'Connect', 'ifthenpay-payments-for-learnpress' ); ?></button>
				</p>
			</form>
		</dialog>
		<?php
	}

	/**
	 * The disconnect confirmation, in place of window.confirm().
	 */
	private function render_disconnect_dialog(): void {
		?>
		<dialog class="iftp-lp-dialog iftp-lp-dialog--disconnect" role="alertdialog" aria-labelledby="iftp-lp-disconnect-title" aria-describedby="iftp-lp-disconnect-desc" data-iftp-disconnect-dialog>
			<h2 id="iftp-lp-disconnect-title" class="iftp-lp-dialog__title"><?php esc_html_e( 'Disconnect ifthenpay?', 'ifthenpay-payments-for-learnpress' ); ?></h2>
			<p id="iftp-lp-disconnect-desc" class="iftp-lp-dialog__text"><?php esc_html_e( 'This clears the Backoffice Key, Gateway Key and payment methods, and takes ifthenpay off the checkout. Past payments stay on their LearnPress orders, and you can connect again at any time.', 'ifthenpay-payments-for-learnpress' ); ?></p>
			<p class="iftp-lp-dialog__actions">
				<?php // Cancel comes first and takes focus, so Enter doesn't disconnect by accident. ?>
				<button type="button" class="button iftp-lp-dialog__cancel" data-iftp-dialog-close autofocus><?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-learnpress' ); ?></button>
				<button type="button" class="button iftp-lp-dialog__confirm iftp-lp-dialog__confirm--danger" data-iftp-disconnect-confirm><?php esc_html_e( 'Disconnect', 'ifthenpay-payments-for-learnpress' ); ?></button>
			</p>
		</dialog>
		<?php
	}
}
