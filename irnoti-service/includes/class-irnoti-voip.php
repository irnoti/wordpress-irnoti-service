<?php
defined( 'ABSPATH' ) || exit;

/**
 * Server-side proxy for the VoIP screens (softphone, live calls, reports, recordings, block list).
 * The browser only talks to admin-ajax with a nonce; the API key never leaves PHP. Only the operations in OPS are reachable.
 */
class IRNoti_VoIP {
	/** op => [ method, path ('{id}' is filled from the `id` param), allowed body/query params ] */
	const OPS = array(
		'accounts'            => array( 'GET', 'voip/sip-accounts', array() ),
		'account_create'      => array( 'POST', 'voip/sip-accounts', array( 'label' ) ),
		'account_credentials' => array( 'POST', 'voip/sip-accounts/{id}/credentials', array() ),
		'account_rotate'      => array( 'POST', 'voip/sip-accounts/{id}/rotate', array() ),
		'account_delete'      => array( 'DELETE', 'voip/sip-accounts/{id}', array() ),
		'call'                => array( 'POST', 'voip/calls', array( 'from', 'to', 'callerId' ) ),
		'summary'             => array( 'GET', 'voip/reports/summary', array( 'from', 'to' ) ),
		'calls'               => array( 'GET', 'voip/reports/calls', array( 'from', 'to', 'direction', 'status', 'q', 'before', 'limit' ) ),
		'live'                => array( 'GET', 'voip/live', array() ),
		'hangup'              => array( 'POST', 'voip/calls/{id}/hangup', array() ),
		'hold'                => array( 'POST', 'voip/calls/{id}/hold', array( 'on' ) ),
		'transfer'            => array( 'POST', 'voip/calls/{id}/transfer', array( 'to' ) ),
		'recordings'          => array( 'GET', 'voip/recordings', array( 'kind', 'search', 'before' ) ),
		'rec_link'            => array( 'POST', 'voip/recordings/{id}/link', array() ),
		'rec_transcribe'      => array( 'POST', 'voip/recordings/{id}/transcribe', array() ),
		'rec_delete'          => array( 'DELETE', 'voip/recordings/{id}', array() ),
		'blocklist'           => array( 'GET', 'voip/blocklist', array() ),
		'block_add'           => array( 'POST', 'voip/blocklist', array( 'number', 'reason' ) ),
		'block_del'           => array( 'DELETE', 'voip/blocklist/{id}', array() ),
	);

	public static function init(): void {
		add_action( 'wp_ajax_irnoti_voip', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_irnoti_voip_csv', array( __CLASS__, 'csv' ) );
		add_action( 'irnoti_webhook_call.inbound', array( __CLASS__, 'on_inbound' ) );
	}

	private static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
	}

	/** Strictly typed per-key cleaning; nothing from the request reaches the API unfiltered. */
	private static function clean( string $key, $v ) {
		$v = is_string( $v ) ? wp_unslash( $v ) : $v;
		switch ( $key ) {
			case 'from':
			case 'to':
				return preg_match( '/^\d{4}-\d{2}-\d{2}T[\d:.]+Z$/', (string) $v ) ? (string) $v : IRNoti_SMS::normalize( sanitize_text_field( (string) $v ) );
			case 'on':
				return in_array( $v, array( true, 'true', '1', 1 ), true );
			case 'limit':
				return min( 100, max( 1, (int) $v ) );
			case 'before':
				return preg_replace( '/\D/', '', (string) $v );
			default:
				return sanitize_text_field( (string) $v );
		}
	}

	private static function build( string $op ): array {
		list( $method, $path, $allowed ) = self::OPS[ $op ];
		if ( false !== strpos( $path, '{id}' ) ) {
			$id = preg_replace( '/\D/', '', (string) ( $_POST['id'] ?? '' ) );
			if ( '' === $id ) {
				wp_send_json_error( array( 'message' => 'id required' ), 400 );
			}
			$path = str_replace( '{id}', $id, $path );
		}
		$params = array();
		foreach ( $allowed as $k ) {
			if ( isset( $_POST[ $k ] ) && '' !== $_POST[ $k ] ) {
				$params[ $k ] = self::clean( $k, $_POST[ $k ] );
			}
		}
		// Report dates arrive as `YYYY-MM-DD` from <input type=date>; the API wants ISO instants.
		foreach ( array( 'from', 'to' ) as $d ) {
			if ( in_array( $op, array( 'summary', 'calls' ), true ) && isset( $_POST[ $d ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $_POST[ $d ] ) ) {
				$params[ $d ] = $_POST[ $d ] . ( 'from' === $d ? 'T00:00:00.000Z' : 'T23:59:59.999Z' );
			}
		}
		return array( $method, $path, $params );
	}

	public static function ajax(): void {
		check_ajax_referer( 'irnoti_voip', 'nonce' );
		self::guard();
		$op = sanitize_key( $_POST['op'] ?? '' );
		if ( ! isset( self::OPS[ $op ] ) ) {
			wp_send_json_error( array( 'message' => 'unknown op' ), 400 );
		}
		list( $method, $path, $params ) = self::build( $op );
		$res = 'GET' === $method ? IRNoti_API::get( $path, $params ) : IRNoti_API::request( $method, $path, 'DELETE' === $method ? null : $params );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 502 );
		}
		if ( 'rec_link' === $op && isset( $res['url'] ) ) {
			$res['url'] = self::public_url( (string) $res['url'] );
		}
		wp_send_json_success( $res );
	}

	/** The API returns a server-relative path (`/api/v1/public/...`); rebuild it against the configured host. */
	private static function public_url( string $path ): string {
		if ( ! preg_match( '#/public/voip-recordings/[A-Za-z0-9._-]+$#', $path, $m ) ) {
			return '';
		}
		return IRNoti_Settings::clean_base_url( (string) IRNoti_Settings::get( 'base_url' ) ) . $m[0];
	}

	public static function csv(): void {
		check_admin_referer( 'irnoti_voip' );
		self::guard();
		$_POST = $_GET; // reuse cleaning for the same allow-list.
		list( , $path, $params ) = self::build( 'calls' );
		unset( $params['before'], $params['limit'] );
		$body = IRNoti_API::get_raw( 'voip/reports/calls.csv', $params );
		if ( is_wp_error( $body ) ) {
			wp_die( esc_html( $body->get_error_message() ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="calls.csv"' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- CSV download.
		exit;
	}

	/** Keeps the last inbound calls so the dashboard can show a "missed/just arrived" list without polling the API. */
	public static function on_inbound( array $data ): void {
		$list = (array) get_transient( 'irnoti_inbound' );
		array_unshift( $list, array( 'time' => time(), 'from' => sanitize_text_field( (string) ( $data['from'] ?? '' ) ), 'to' => sanitize_text_field( (string) ( $data['to'] ?? '' ) ) ) );
		set_transient( 'irnoti_inbound', array_slice( $list, 0, 20 ), DAY_IN_SECONDS );
	}

	public static function jssip_url(): string {
		$custom = (string) IRNoti_Settings::get( 'jssip_url' );
		if ( '' !== $custom && 0 === strpos( $custom, 'https://' ) ) {
			return $custom;
		}
		return file_exists( IRNOTI_DIR . 'assets/js/jssip.min.js' ) ? IRNOTI_URL . 'assets/js/jssip.min.js' : '';
	}
}
