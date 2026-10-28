<?php
defined( 'ABSPATH' ) || exit;

/** Thin wrapper over the IRNoti REST API (`Authorization: Bearer irn_…`). Returns decoded JSON or WP_Error. */
class IRNoti_API {
	public static function configured(): bool {
		return IRNoti_Settings::api_key() !== '';
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
