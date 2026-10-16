<?php
/**
 * Settings AJAX router for ifthenpay.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Ajax;

use Ifthenpay\LearnPress\Admin\MethodsTable;
use Ifthenpay\LearnPress\Api\IfthenpayClient;
use Ifthenpay\LearnPress\Api\Webhook\WebhookRegistrar;
use Ifthenpay\LearnPress\Host;
use Ifthenpay\LearnPress\Mail\IfthenpayEmailHelper;
use Ifthenpay\LearnPress\Plugin;
use Ifthenpay\LearnPress\Repository\SettingsRepository;
use Ifthenpay\LearnPress\Sync\GatewaySync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin AJAX for our LearnPress settings section: connect, disconnect, Gateway Key switch and activation requests.
 * The rest of the section saves with LearnPress's own Save (see SettingsSection::save()).
 */
class SettingsController {

	public const NONCE_ACTION = 'iftp_lp_settings';

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Gateway catalog sync.
	 *
	 * @var GatewaySync
	 */
	private GatewaySync $sync;

	/**
	 * Methods table renderer.
	 *
	 * @var MethodsTable
	 */
	private MethodsTable $methods_table;

	/**
	 * Webhook callback registration.
	 *
	 * @var WebhookRegistrar
	 */
	private WebhookRegistrar $webhooks;

	/**
	 * Sets up the router.
	 *
	 * @param SettingsRepository   $settings Settings storage.
	 * @param IfthenpayClient|null $client   Ifthenpay API client.
	 */
	public function __construct( SettingsRepository $settings, ?IfthenpayClient $client = null ) {
		$client              = $client ?? new IfthenpayClient();
		$this->settings      = $settings;
		$this->sync          = new GatewaySync( $settings, $client );
		$this->methods_table = new MethodsTable( $settings );
		$this->webhooks      = new WebhookRegistrar( $settings, $client );
	}

	/**
	 * Registers every AJAX action this router handles.
	 */
	public function register(): void {
		add_action( 'wp_ajax_iftp_lp_connect', array( $this, 'connect' ) );
		add_action( 'wp_ajax_iftp_lp_disconnect', array( $this, 'disconnect' ) );
		add_action( 'wp_ajax_iftp_lp_select_gateway_key', array( $this, 'select_gateway_key' ) );
		add_action( 'wp_ajax_iftp_lp_request_activation', array( $this, 'request_activation' ) );
	}

	/**
	 * The 24h cooldown transient for one method's activation request. Per method, never per table.
	 *
	 * @param string $gateway_key The Gateway Key.
	 * @param string $entity      The method entity code.
	 */
	public static function activation_cooldown_key( string $gateway_key, string $entity ): string {
		return 'iftp_lp_activation_' . md5( $gateway_key . '|' . strtoupper( $entity ) );
	}

	/**
	 * Connects a Backoffice Key: checks its format, then that it has a gateway of our type.
	 */
	public function connect(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_cap();

		$backoffice_key = $this->post_text( 'backoffice_key' );

		if ( 1 !== preg_match( '/^\d{4}-\d{4}-\d{4}-\d{4}$/', $backoffice_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter the Backoffice Key as 0000-0000-0000-0000.', 'ifthenpay-payments-for-learnpress' ) ), 400 );
		}

		if ( ! $this->sync->connect( $backoffice_key ) ) {
			wp_send_json_error(
				array(
					/* translators: %s: the ifthenpay gateway type, e.g. "LearnPress" */
					'message' => sprintf( __( 'No %s Gateway Key was found for this Backoffice Key. Ask ifthenpay to create one for you.', 'ifthenpay-payments-for-learnpress' ), IfthenpayClient::gateway_type() ),
				)
			);
		}

		$this->webhooks->register_webhook( $this->settings->get_gateway_key() );

		wp_send_json_success();
	}

	/**
	 * Disconnects the Backoffice Key, wiping every stored setting but the anti-phishing keys.
	 */
	public function disconnect(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_cap();

		$this->settings->delete_all();
		delete_transient( IfthenpayClient::GATEWAY_ROWS_TRANSIENT );

		wp_send_json_success();
	}

	/**
	 * Switches the active Gateway Key and re-renders the methods table for it.
	 */
	public function select_gateway_key(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_connected();

		$gateway_key = $this->post_text( 'gateway_key' );

		if ( ! $this->sync->switch_gateway_key( $gateway_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown Gateway Key.', 'ifthenpay-payments-for-learnpress' ) ), 400 );
		}

		$this->webhooks->register_webhook( $gateway_key );

		wp_send_json_success( array( 'tableHtml' => $this->methods_table->render() ) );
	}

	/**
	 * Emails ifthenpay support to activate a not-yet-provisioned method. The Backoffice Key is read here, never from the browser.
	 */
	public function request_activation(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_connected();

		$entity      = strtoupper( $this->post_text( 'entity' ) );
		$gateway_key = $this->settings->get_gateway_key();

		if ( '' === $entity || '' === $gateway_key || ! in_array( $entity, array_column( $this->settings->get_methods(), 'entity' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing method or Gateway Key.', 'ifthenpay-payments-for-learnpress' ) ), 400 );
		}

		$cooldown_key = self::activation_cooldown_key( $gateway_key, $entity );

		if ( false !== get_transient( $cooldown_key ) ) {
			wp_send_json_error( array( 'message' => __( 'An activation request for this method was already sent in the last 24 hours.', 'ifthenpay-payments-for-learnpress' ) ), 429 );
		}

		$sent = IfthenpayEmailHelper::send_activation_email(
			array(
				'gateway_key'        => $gateway_key,
				'entity'             => $entity,
				'backoffice_key'     => $this->settings->get_backoffice_key(),
				'customer_email'     => wp_get_current_user()->user_email,
				'site_url'           => home_url( '/' ),
				'site_name'          => (string) get_bloginfo( 'name' ),
				'wp_version'         => (string) get_bloginfo( 'version' ),
				'plugin_version'     => IFTP_LP_VERSION,
				'learnpress_version' => Host::version(),
			)
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'Could not send the activation request. Check that this site can send email, then try again.', 'ifthenpay-payments-for-learnpress' ) ), 500 );
		}

		set_transient( $cooldown_key, 1, DAY_IN_SECONDS );

		wp_send_json_success();
	}

	/**
	 * A single-line POST value.
	 *
	 * @param string $key      The POST key.
	 * @param string $fallback Returned when the key is missing or not a string.
	 */
	private function post_text( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler checks the nonce first.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
	}

	/**
	 * Refuses anyone without the admin capability.
	 */
	private function require_cap(): void {
		if ( ! current_user_can( Plugin::admin_cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-learnpress' ) ), 403 );
		}
	}

	/**
	 * Same as require_cap(), plus a connected Backoffice Key.
	 */
	private function require_connected(): void {
		$this->require_cap();

		if ( ! $this->settings->is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Connect your ifthenpay account first.', 'ifthenpay-payments-for-learnpress' ) ), 409 );
		}
	}
}
