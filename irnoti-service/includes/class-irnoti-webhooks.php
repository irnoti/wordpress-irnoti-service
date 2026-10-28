<?php
defined( 'ABSPATH' ) || exit;

/**
 * Receives IRNoti webhooks at /wp-json/irnoti/v1/webhook.
 * Signature: `X-IRNoti-Signature: t=<unix>,v1=<hmac_sha256(secret, "<t>.<raw body>")>`; rejects stale (>5 min) timestamps.
 * Webhooks are created in the IRNoti panel (JWT-only API); paste the signing secret in the plugin's connection tab.
 */
class IRNoti_Webhooks {
	const LOG = 'irnoti_events';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function endpoint(): string {
		return rest_url( 'irnoti/v1/webhook' );
	}

	public static function routes(): void {
		register_rest_route( 'irnoti/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // authenticated by HMAC signature below.
		) );
	}

	public static function verify( string $secret, string $body, string $header, int $tolerance = 300, ?int $now = null ): bool {
		$now = $now ?? time();
		if ( '' === $secret || ! preg_match( '/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $m ) || abs( $now - (int) $m[1] ) > $tolerance ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $m[1] . '.' . $body, $secret ), $m[2] );
	}

	public static function handle( WP_REST_Request $req ) {
		$body = $req->get_body();
		if ( ! self::verify( IRNoti_Settings::webhook_secret(), $body, (string) $req->get_header( 'x-irnoti-signature' ) ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}
		$payload = json_decode( $body, true );
		$event   = is_array( $payload ) ? sanitize_text_field( (string) ( $payload['event'] ?? '' ) ) : '';
		if ( '' === $event ) {
			return new WP_REST_Response( array( 'error' => 'bad_payload' ), 400 );
		}
		$data = (array) ( $payload['data'] ?? array() );
		self::log( $event, $data );
		/**
		 * Fires for every verified IRNoti event, e.g. `irnoti_webhook_message.delivered`, `irnoti_webhook_call.inbound`.
		 */
		do_action( 'irnoti_webhook_' . $event, $data, $payload );
		do_action( 'irnoti_webhook', $event, $data, $payload );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	private static function log( string $event, array $data ): void {
		$log = (array) get_option( self::LOG, array() );
		array_unshift( $log, array(
			'time'  => time(),
			'event' => $event,
			'brief' => mb_substr( wp_json_encode( $data, JSON_UNESCAPED_UNICODE ), 0, 300 ),
		) );
		update_option( self::LOG, array_slice( $log, 0, 50 ), false );
	}
}
