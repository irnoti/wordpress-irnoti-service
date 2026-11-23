<?php
defined( 'ABSPATH' ) || exit;

/** Thin wrapper over the IRNoti REST API (`Authorization: Bearer irn_…`). Returns decoded JSON or WP_Error. */
class IRNoti_API {
	/** Result of the last key check: state ok|invalid|error, message, time, and a fingerprint of the key it was made for. */
	const STATUS = 'irnoti_key_status';
	/** How long a successful check is trusted before the admin re-checks. */
	const STATUS_TTL = 6 * HOUR_IN_SECONDS;

	public static function configured(): bool {
		return IRNoti_Settings::api_key() !== '';
	}

	private static function fingerprint(): string {
		return substr( hash( 'sha256', IRNoti_Settings::api_key() . '|' . (string) IRNoti_Settings::get( 'base_url' ) ), 0, 16 );
	}

	private static function remember( string $state, string $msg = '' ): array {
		$st = array( 'state' => $state, 'msg' => $msg, 'time' => time(), 'fp' => self::fingerprint() );
		update_option( self::STATUS, $st, false );
		return $st;
	}

	/**
	 * Asks the server whether the key works. 401 = wrong key; 403/429 still prove the key is real (missing scope, KYC, quota);
	 * 404 or a network/5xx error means the server or the API address is the problem.
	 */
	public static function verify(): array {
		if ( ! self::configured() ) {
			delete_option( self::STATUS );
			return array( 'state' => 'none', 'msg' => '' );
		}
		$res = self::request( 'GET', 'sms/lines' );
		if ( ! is_wp_error( $res ) ) {
			return self::remember( 'ok' );
		}
		$code = (int) ( $res->get_error_data()['status'] ?? 0 );
		if ( 401 === $code ) {
			return self::remember( 'invalid', __( 'کلید API نامعتبر است یا غیرفعال شده.', 'irnoti-service' ) );
		}
		if ( in_array( $code, array( 403, 429 ), true ) ) {
			return self::remember( 'ok' );
		}
		return self::remember( 'error', 404 === $code ? __( 'آدرس API اشتباه است.', 'irnoti-service' ) : $res->get_error_message() );
	}

	/** Cached key status; re-checked when missing, for another key, or stale. */
	public static function status(): array {
		if ( ! self::configured() ) {
			return array( 'state' => 'none', 'msg' => '' );
		}
		$st = get_option( self::STATUS );
		if ( ! is_array( $st ) || ( $st['fp'] ?? '' ) !== self::fingerprint() || ( 'ok' !== $st['state'] && time() - (int) $st['time'] > 5 * MINUTE_IN_SECONDS ) || time() - (int) $st['time'] > self::STATUS_TTL ) {
			return self::verify();
		}
		return $st;
	}

	public static function connected(): bool {
		return 'ok' === self::status()['state'];
	}

	public static function request( string $method, string $path, ?array $body = null, array $query = array(), array $headers = array(), bool $raw = false ) {
		$key = IRNoti_Settings::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'irnoti_no_key', __( 'کلید API تنظیم نشده است.', 'irnoti-service' ) );
		}
		$url = IRNoti_Settings::clean_base_url( (string) IRNoti_Settings::get( 'base_url' ) ) . '/' . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $key,
					'Accept'        => 'application/json',
					'User-Agent'    => 'irnoti-wordpress/' . IRNOTI_VERSION,
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body ?: new stdClass() ); // `[]` would fail object schemas.
		}
		$res = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $raw && $code < 400 ) {
			return wp_remote_retrieve_body( $res );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 401 === $code ) {
			self::remember( 'invalid', __( 'کلید API نامعتبر است یا غیرفعال شده.', 'irnoti-service' ) ); // any call can reveal a revoked key
		}
		if ( $code >= 400 ) {
			$err = is_array( $data ) ? ( $data['error'] ?? $data ) : array();
			$msg = is_array( $err ) ? ( $err['message'] ?? $err['code'] ?? '' ) : (string) $err;
			return new WP_Error( 'irnoti_http_' . $code, $msg ?: sprintf( 'HTTP %d', $code ), array( 'status' => $code ) );
		}
		return null === $data ? array() : $data;
	}

	public static function get( string $path, array $query = array() ) {
		return self::request( 'GET', $path, null, $query );
	}

	public static function get_raw( string $path, array $query = array() ) {
		return self::request( 'GET', $path, null, $query, array(), true );
	}

	public static function post( string $path, array $body = array(), string $idempotency_key = '' ) {
		return self::request( 'POST', $path, $body, array(), $idempotency_key ? array( 'Idempotency-Key' => substr( $idempotency_key, 0, 100 ) ) : array() );
	}

	public static function put( string $path, array $body = array() ) {
		return self::request( 'PUT', $path, $body );
	}

	public static function delete( string $path ) {
		return self::request( 'DELETE', $path );
	}
}
