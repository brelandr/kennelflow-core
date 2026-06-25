<?php
/**
 * Twilio SMS API client (credentials from KennelFlow Settings).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class TwilioService
 */
class TwilioService {

	const OPTION_ACCOUNT_SID = 'ltkf_twilio_account_sid';

	const OPTION_AUTH_TOKEN = 'ltkf_twilio_auth_token';

	const OPTION_FROM_NUMBER = 'ltkf_twilio_from_number';

	/**
	 * Send an SMS via Twilio REST API (WordPress HTTP API).
	 *
	 * Uses {@see wp_remote_post()} against the fixed Twilio HTTPS host (not user-supplied URL).
	 * Checks {@see is_wp_error()} and requires HTTP 2xx before treating the send as successful.
	 *
	 * @param string $to_number Destination phone (normalized to E.164).
	 * @param string $message   Message body (Twilio limits apply).
	 * @return bool True when Twilio returns HTTP 2xx; false on missing config, bad number, transport error, or non-2xx.
	 */
	public static function send_sms( $to_number, $message ) {
		$sid   = get_option( self::OPTION_ACCOUNT_SID, '' );
		$token = get_option( self::OPTION_AUTH_TOKEN, '' );
		$from  = get_option( self::OPTION_FROM_NUMBER, '' );

		$sid   = is_string( $sid ) ? trim( $sid ) : '';
		$token = is_string( $token ) ? trim( $token ) : '';
		$from  = is_string( $from ) ? trim( $from ) : '';

		if ( '' === $sid || '' === $token || '' === $from ) {
			return false;
		}

		$message = is_string( $message ) ? trim( $message ) : '';
		if ( '' === $message ) {
			return false;
		}

		$to_e164 = self::normalize_to_e164( $to_number );
		if ( '' === $to_e164 ) {
			return false;
		}

		$from_e164 = self::normalize_to_e164( $from );
		if ( '' === $from_e164 ) {
			return false;
		}

		$url = sprintf(
			'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
			rawurlencode( $sid )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Twilio REST API Basic authentication.
		$auth = base64_encode( $sid . ':' . $token );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Basic ' . $auth,
				),
				'body'    => array(
					'To'   => $to_e164,
					'From' => $from_e164,
					'Body' => $message,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		return is_numeric( $code ) && (int) $code >= 200 && (int) $code < 300;
	}

	/**
	 * Format a phone string as E.164: strip non-digits, prepend + and default country code when missing.
	 *
	 * @param string $number Raw phone input.
	 * @return string E.164 or empty if invalid.
	 */
	public static function normalize_to_e164( $number ) {
		$number = is_string( $number ) ? trim( $number ) : '';
		if ( '' === $number ) {
			return '';
		}

		$digits = preg_replace( '/\D/', '', $number );
		if ( null === $digits || '' === $digits ) {
			return '';
		}

		/**
		 * Default numeric country code when the number has no country prefix (e.g. US/Canada = 1 for 10-digit NANP).
		 *
		 * @since 0.2.0
		 *
		 * @param string $country_digits Digits only, no +.
		 */
		$country = apply_filters( 'ltkf_twilio_default_country_code', '1' );
		$country = preg_replace( '/\D/', '', (string) $country );
		if ( '' === $country ) {
			$country = '1';
		}

		$len = strlen( $digits );

		// 10 digits: assume local number under default country (e.g. NANP).
		if ( 10 === $len ) {
			return '+' . $country . $digits;
		}

		// 11 digits starting with 1: typical NANP with country code.
		if ( 11 === $len && '1' === $digits[0] && '1' === $country ) {
			return '+' . $digits;
		}

		// Otherwise treat as already including country code (international).
		return '+' . $digits;
	}
}
