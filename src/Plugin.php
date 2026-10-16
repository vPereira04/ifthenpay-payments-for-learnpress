<?php
/**
 * Plugin bootstrap.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress;

use Ifthenpay\LearnPress\Admin\OrderPanel;
use Ifthenpay\LearnPress\Admin\SettingsSection;
use Ifthenpay\LearnPress\Ajax\SettingsController;
use Ifthenpay\LearnPress\Api\IfthenpayClient;
use Ifthenpay\LearnPress\Api\ReturnHandler;
use Ifthenpay\LearnPress\Api\Webhook\WebhookController;
use Ifthenpay\LearnPress\Api\Webhook\WebhookRegistrar;
use Ifthenpay\LearnPress\Cron\ExpiredPaymentsCron;
use Ifthenpay\LearnPress\Frontend\CheckoutNotice;
use Ifthenpay\LearnPress\Frontend\OrderReceived;
use Ifthenpay\LearnPress\Gateway\IfthenpayGateway;
use Ifthenpay\LearnPress\Payments\PaymentService;
use Ifthenpay\LearnPress\Privacy\PersonalData;
use Ifthenpay\LearnPress\Repository\SettingsRepository;
use Ifthenpay\LearnPress\Repository\TransactionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every piece together once, on `plugins_loaded`.
 */
class Plugin {

	/**
	 * The gateway id LearnPress keys us by, and the `learn_press_{id}` option it reads.
	 */
	public const GATEWAY_ID = 'ifthenpay';

	/**
	 * The singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Settings storage, shared by everything on this request.
	 *
	 * @var SettingsRepository|null
	 */
	private ?SettingsRepository $settings = null;

	/**
	 * Transactions storage, shared by everything on this request.
	 *
	 * @var TransactionStore|null
	 */
	private ?TransactionStore $transactions = null;

	/**
	 * The payment flow, built on first use.
	 *
	 * @var PaymentService|null
	 */
	private ?PaymentService $payments = null;

	/**
	 * Our part of LearnPress's ifthenpay settings section, built on first use.
	 *
	 * @var SettingsSection|null
	 */
	private ?SettingsSection $settings_section = null;

	/**
	 * The singleton instance, created on first access.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Singleton — use instance() instead.
	 */
	private function __construct() {
	}

	/**
	 * Registers every hook, endpoint and admin screen.
	 */
	public function boot(): void {
		// Activation creates the table too; this catches schema upgrades on update.
		TransactionStore::maybe_create_table();

		$settings     = $this->settings();
		$transactions = $this->transactions();

		// LearnPress builds its gateway list once, on init@-1000, so this has to be in place before that.
		add_filter( 'learn-press/payment-methods', array( $this, 'register_gateway' ) );
		add_filter( 'learn-press/payment-gateways/available', array( $this, 'hide_until_ready' ) );

		// ifthenpay calls back and sends the payer back on the frontend, so these are always on.
		( new WebhookController( $transactions, $settings ) )->register();
		( new ReturnHandler( $transactions ) )->register();
		( new OrderReceived( $transactions ) )->register();
		( new CheckoutNotice() )->register();

		if ( is_admin() ) {
			$this->settings_section()->register();
			( new SettingsController( $settings ) )->register();
			( new OrderPanel( $transactions, $settings ) )->register();
			( new PersonalData( $transactions ) )->register();
		}

		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			( new WebhookRegistrar( $settings ) )->register();
			( new ExpiredPaymentsCron( $transactions ) )->register();
		}
	}

	/**
	 * Adds our gateway to LearnPress's list, as a class name it instantiates itself.
	 *
	 * @param mixed $gateways LearnPress's gateways, id => class name or instance.
	 * @return mixed
	 */
	public function register_gateway( $gateways ) {
		if ( ! is_array( $gateways ) || ! class_exists( 'LP_Gateway_Abstract' ) ) {
			return $gateways;
		}

		$gateways[ self::GATEWAY_ID ] = IfthenpayGateway::class;

		return $gateways;
	}

	/**
	 * Keeps ifthenpay off the checkout until it can create a link, without touching LearnPress's own Enable switch.
	 *
	 * @param mixed $gateways The gateways LearnPress would offer.
	 * @return mixed
	 */
	public function hide_until_ready( $gateways ) {
		if ( is_array( $gateways ) && isset( $gateways[ self::GATEWAY_ID ] ) && ! $this->payments()->is_ready() ) {
			unset( $gateways[ self::GATEWAY_ID ] );
		}

		return $gateways;
	}

	/**
	 * Our part of LearnPress's ifthenpay settings section. LearnPress builds the gateway that prints it, so it's shared here.
	 */
	public function settings_section(): SettingsSection {
		return $this->settings_section ??= new SettingsSection( $this->settings() );
	}

	/**
	 * The request's settings storage.
	 */
	public function settings(): SettingsRepository {
		return $this->settings ??= new SettingsRepository();
	}

	/**
	 * The request's transactions storage.
	 */
	public function transactions(): TransactionStore {
		return $this->transactions ??= new TransactionStore();
	}

	/**
	 * The payment flow. The gateway reaches it through here, since LearnPress builds the gateway with no arguments.
	 */
	public function payments(): PaymentService {
		return $this->payments ??= new PaymentService( $this->settings(), new IfthenpayClient(), $this->transactions() );
	}

	/**
	 * The capability every screen and endpoint here checks. LearnPress has no payments capability of its own.
	 */
	public static function admin_cap(): string {
		return 'manage_options';
	}

	/**
	 * The ?ver= for one of our assets. While debugging I use its mtime, so edits show without a version bump.
	 *
	 * @param string $relative Path from the plugin root, e.g. `assets/js/settings.js`.
	 */
	public static function asset_version( string $relative ): string {
		$debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		$path  = IFTP_LP_PATH . $relative;

		return $debug && file_exists( $path ) ? (string) filemtime( $path ) : IFTP_LP_VERSION;
	}
}
