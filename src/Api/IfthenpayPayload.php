<?php
/**
 * Pay by Link request payload builder.
 *
 * @package Ifthenpay\LearnPress
 */

declare(strict_types=1);

namespace Ifthenpay\LearnPress\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the Pay by Link request payload and the accounts string. Pure transformations, no WordPress state.
 */
class IfthenpayPayload {

	/**
	 * Joins the enabled methods' accounts. Each `account` is already a full `ENTITY|ACCOUNT` segment from ifthenpay.
	 *
	 * @param array<int, array{entity: string, account: string}> $enabled_methods The site's enabled payment methods.
	 */
	public static function build_accounts_string( array $enabled_methods ): string {
		return implode( ';', array_filter( array_column( $enabled_methods, 'account' ), static fn( string $account ): bool => '' !== $account ) );
	}

	/**
	 * Formats an amount the way the ifthenpay API expects it.
	 *
	 * @param float $amount The amount to format.
	 */
	public static function format_amount( float $amount ): string {
		return number_format( $amount, 2, '.', '' );
	}

	/**
	 * Builds the full Pay by Link request payload.
	 *
	 * @param string                                                $id                       The payment reference.
	 * @param float                                                 $amount                   The payment amount.
	 * @param string                                                $description              The payer-facing description.
	 * @param array<int, array{entity: string, account: string}>    $enabled_methods          The site's enabled payment methods.
	 * @param array{success: string, error: string, cancel: string} $return_urls              Where ifthenpay sends the payer back to, per outcome.
	 * @param string                                                $lang                     The ifthenpay `lang` code.
	 * @param string                                                $selected_method_position The catalog Position of the pre-selected method, or '' for none.
	 * @param string                                                $expire_date              The last payable day as `Ymd`, or '' for ifthenpay's default.
	 * @return array<string, string>
	 */
	public static function build_payment_payload(
		string $id,
		float $amount,
		string $description,
		array $enabled_methods,
		array $return_urls,
		string $lang = 'en',
		string $selected_method_position = '',
		string $expire_date = ''
	): array {
		$payload = array(
			'id'          => $id,
			'amount'      => self::format_amount( $amount ),
			'description' => $description,
			'accounts'    => self::build_accounts_string( $enabled_methods ),
			'success_url' => $return_urls['success'],
			'error_url'   => $return_urls['error'],
			'cancel_url'  => $return_urls['cancel'],
			'otp'         => 'true',
			'lang'        => $lang,
		);

		if ( '' !== $selected_method_position ) {
			$payload['selected_method'] = $selected_method_position;
		}

		if ( '' !== $expire_date ) {
			$payload['expiredate'] = $expire_date;
		}

		return $payload;
	}

	/**
	 * Maps a WordPress locale (e.g. `pt_PT`, `es_ES`) to an ifthenpay `lang` code.
	 *
	 * @param string $locale The WordPress locale, e.g. determine_locale().
	 */
	public static function locale_to_lang( string $locale ): string {
		$prefix = strtolower( substr( $locale, 0, 2 ) );

		return in_array( $prefix, array( 'pt', 'es', 'fr' ), true ) ? $prefix : 'en';
	}
}
