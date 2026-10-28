<?php
defined( 'ABSPATH' ) || exit;

/** Single option array; the API key is encrypted with the WP salts so a DB dump alone does not leak it. */
class IRNoti_Settings {
	const OPTION = 'irnoti_settings';

	public static function defaults(): array {
		return array(
			'base_url'       => 'https://api.irnoti.com/v1',
			'api_key_enc'    => '',
			'sms_line_id'    => '',
			'sms_events'     => array(), // woo status => template
			'sms_admin_to'   => '',
			'email_enabled'  => 0,
			'email_from'     => '',
			'bot_id'         => '',
			'bot_auto_sync'  => 1,
			'voip_from'      => '',
			'voip_sip_id'    => '',
			'jssip_url'      => '',
		);
	}

	public static function all(): array {
		return array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function update( array $values ): void {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	public static function api_key(): string {
		$enc = (string) self::get( 'api_key_enc' );
		return $enc === '' ? '' : self::decrypt( $enc );
	}

	public static function set_api_key( string $key ): void {
		self::update( array( 'api_key_enc' => $key === '' ? '' : self::encrypt( $key ) ) );
	}

	/** Base URL must be https (outside local dev) so the Bearer key never travels in clear text. */
	public static function clean_base_url( string $url ): string {
		$url = untrailingslashit( esc_url_raw( trim( $url ) ) );
		if ( $url === '' || ( 0 !== strpos( $url, 'https://' ) && ! self::is_local( $url ) ) ) {
			return self::defaults()['base_url'];
		}
		return $url;
	}

	private static function is_local( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return in_array( $host, array( 'localhost', '127.0.0.1' ), true ) && defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	private static function crypto_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
	}

	private static function encrypt( string $plain ): string {
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return base64_encode( $iv . $tag . $ct );
	}

	private static function decrypt( string $blob ): string {
		$raw = base64_decode( $blob, true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? '' : $plain;
	}
}
